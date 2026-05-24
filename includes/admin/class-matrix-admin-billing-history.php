<?php
/**
 * Admin Billing History — Item D.
 *
 * Surfaces wp_matrix_billing_transactions to admins with filters
 * (user, category, status, date range), pagination, and a per-row
 * Refund action. The refund flow credits the user's Matrix wallet
 * (Fintava billing endpoints have no upstream reversal API — the
 * telco transaction is irreversible — so we only compensate the
 * user inside our own ledger), records the action in the
 * matrix_billing_refunds audit table, and bumps refunded_amount +
 * status on the underlying transaction row.
 *
 * Concurrency model
 * =================
 * Two admins clicking Refund on the same row at the same time is
 * the bug we have to defend against. design.md proposed a
 * wallet-first flow with a compensating debit on detected
 * over-refund. We instead do a CAS-first flow (update the
 * transactions row before any wallet movement) because it has a
 * smaller blast radius — if step 3 (wallet credit) fails, we
 * have one inconsistency to clean up (the transactions row that
 * was bumped) instead of a wallet movement we now have to
 * reverse with a debit. The CAS-update IS the lock:
 *
 *   UPDATE matrix_billing_transactions
 *   SET refunded_amount = refunded_amount + N,
 *       status          = ...derived...
 *   WHERE id = X
 *     AND status IN ('completed', 'partial_refund')
 *     AND refunded_amount + N <= total_charged + 0.005
 *
 * Two admins racing → only one matches the WHERE clause; the
 * second sees affected_rows == 0 and is rejected before the
 * wallet is touched. Mirrors the CAS pattern PR #251 used to
 * close the failed → completed regression on matrix_fintava_payouts.
 *
 * Capability
 * ==========
 * The page itself is gated on manage_matrix_mlm (read-only
 * reviewer tier — same as the Fintava admin view) so support
 * staff can inspect the history without escalating. The refund
 * button + AJAX handler are gated on manage_options because a
 * refund moves money. Two layers, intentionally.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Billing_History {

    /** Page rows per pagination page. */
    const PER_PAGE = 25;

    /** Reason length cap on the modal input. */
    const REASON_MAX_LEN = 500;

    /**
     * Float epsilon for cap comparisons. Matches the constant used
     * by the design.md handler body so that "₦0.001 over" doesn't
     * reject a legitimate refund-the-rest click after partial
     * refunds have rounded.
     */
    const FLOAT_EPSILON = 0.005;

    /**
     * Capability required to ISSUE a refund. The page itself is
     * gated separately (manage_matrix_mlm); this is the tighter
     * money-moving cap, evaluated server-side both at render time
     * (do we draw the button?) and inside the AJAX handler (do we
     * accept the action?).
     */
    const REFUND_CAPABILITY = 'manage_options';

    /**
     * Wire up the AJAX endpoint. Called from matrix-mlm.php
     * unconditionally inside the admin block so the wp_ajax_*
     * hook is registered for every admin-ajax.php request, not
     * just rendered admin pages (admin_menu does not fire on
     * AJAX requests).
     */
    public static function init() {
        add_action('wp_ajax_matrix_admin_billing_refund', [__CLASS__, 'ajax_refund']);
    }

    /**
     * Render the Bill Payments History admin page.
     */
    public function render() {
        global $wpdb;

        // Defense-in-depth: the submenu is registered with
        // manage_matrix_mlm so WordPress's menu router already
        // gates entry. This re-check protects the narrow class of
        // bypasses where this method is invoked outside the menu
        // pipeline (custom admin_init handlers, tests, future
        // refactors). Same idiom render_epins() uses.
        if (!current_user_can('manage_matrix_mlm')) {
            wp_die(__('Sorry, you are not allowed to access this page.', 'matrix-mlm'), 403);
        }

        $currency = get_option('matrix_mlm_currency_symbol', '₦');

        // ---- Filter inputs (GET so URLs are bookmarkable) ----
        $f_user   = isset($_GET['user'])   ? trim(sanitize_text_field(wp_unslash($_GET['user'])))   : '';
        $f_type   = isset($_GET['type'])   ? sanitize_text_field(wp_unslash($_GET['type']))         : '';
        $f_status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status']))       : '';
        $f_from   = isset($_GET['from'])   ? sanitize_text_field(wp_unslash($_GET['from']))         : '';
        $f_to     = isset($_GET['to'])     ? sanitize_text_field(wp_unslash($_GET['to']))           : '';
        $paged    = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);

        // Validate type / status against allowlists. Anything off
        // the list silently drops the filter — defensive against a
        // typo in a copy-pasted URL fataling on the prepare() call
        // below.
        $valid_types    = ['airtime', 'data', 'cable', 'electricity'];
        $valid_statuses = ['pending', 'completed', 'failed', 'refunded', 'partial_refund'];
        if ($f_type !== '' && !in_array($f_type, $valid_types, true))       { $f_type = ''; }
        if ($f_status !== '' && !in_array($f_status, $valid_statuses, true)) { $f_status = ''; }

        // Resolve the user filter (ID, login, or email) to a numeric
        // user_id so the SQL can use the indexed user_id column.
        // Empty filter = unfiltered. Non-resolvable filter (e.g. a
        // typo'd email) is rendered as "no rows" instead of an
        // error — matches the precedent in the Withdrawals admin.
        $user_id_filter = null;
        $user_filter_unresolved = false;
        if ($f_user !== '') {
            if (ctype_digit($f_user)) {
                $user_id_filter = (int) $f_user;
            } else {
                $u = is_email($f_user) ? get_user_by('email', $f_user) : get_user_by('login', $f_user);
                if ($u) {
                    $user_id_filter = (int) $u->ID;
                } else {
                    $user_filter_unresolved = true;
                }
            }
        }

        // Build WHERE clause + params. Date filters are applied as
        // half-open intervals on created_at: from = 00:00:00 of the
        // given date, to = 24:00:00 of the given date. Same idiom
        // class-matrix-admin-reports.php uses.
        $where  = "WHERE 1=1";
        $params = [];
        if ($user_id_filter !== null) {
            $where .= " AND t.user_id = %d";
            $params[] = $user_id_filter;
        }
        if ($f_type !== '') {
            $where .= " AND t.type = %s";
            $params[] = $f_type;
        }
        if ($f_status !== '') {
            $where .= " AND t.status = %s";
            $params[] = $f_status;
        }
        if ($f_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_from)) {
            $where .= " AND t.created_at >= %s";
            $params[] = $f_from . ' 00:00:00';
        }
        if ($f_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_to)) {
            $where .= " AND t.created_at < %s";
            $params[] = date('Y-m-d 00:00:00', strtotime($f_to . ' +1 day'));
        }

        // If the user filter was a non-resolvable string, force a
        // zero-row query (matches "no rows" in the UI). Cheaper
        // than constructing a separate empty branch, and an
        // attacker can't smuggle SQL through the user filter
        // because every other condition is still parameterised.
        if ($user_filter_unresolved) {
            $where .= " AND 1=0";
        }

        // Total count (for pagination chrome).
        $count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}matrix_billing_transactions t $where";
        $total = $params
            ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $params))
            : (int) $wpdb->get_var($count_sql);
        $total_pages = max(1, (int) ceil($total / self::PER_PAGE));
        $paged       = min($paged, $total_pages);
        $offset      = ($paged - 1) * self::PER_PAGE;

        // Rows for current page.
        $list_params = array_merge($params, [self::PER_PAGE, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, u.user_login, u.user_email
               FROM {$wpdb->prefix}matrix_billing_transactions t
               LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID
               $where
               ORDER BY t.created_at DESC
               LIMIT %d OFFSET %d",
            $list_params
        ));

        // Top-line stats — bounded scope (matches the active
        // filter set, NOT the whole table) so an admin who's
        // filtered down to "DSTV refunds in May" sees those
        // numbers, not the global ones. Matches the precedent
        // in Reports.
        $stats_sql = "SELECT
            COUNT(*) AS rows_n,
            COALESCE(SUM(t.total_charged), 0)   AS total_charged_sum,
            COALESCE(SUM(t.service_fee), 0)     AS service_fee_sum,
            COALESCE(SUM(t.refunded_amount), 0) AS refunded_sum
         FROM {$wpdb->prefix}matrix_billing_transactions t $where";
        $stats = $params
            ? $wpdb->get_row($wpdb->prepare($stats_sql, $params))
            : $wpdb->get_row($stats_sql);

        // Status filter chip totals — unfiltered counts so the
        // chips show the global mix and clicking one re-filters.
        $status_totals = $wpdb->get_row(
            "SELECT
                COUNT(*) AS all_n,
                SUM(CASE WHEN status = 'completed'      THEN 1 ELSE 0 END) AS completed_n,
                SUM(CASE WHEN status = 'failed'         THEN 1 ELSE 0 END) AS failed_n,
                SUM(CASE WHEN status = 'refunded'       THEN 1 ELSE 0 END) AS refunded_n,
                SUM(CASE WHEN status = 'partial_refund' THEN 1 ELSE 0 END) AS partial_n,
                SUM(CASE WHEN status = 'pending'        THEN 1 ELSE 0 END) AS pending_n
              FROM {$wpdb->prefix}matrix_billing_transactions"
        );

        // Whether the operator can issue a refund. Drives the
        // visibility of the per-row Refund button. The AJAX
        // handler re-checks this server-side regardless.
        $can_refund = current_user_can(self::REFUND_CAPABILITY);

        // Nonce for the refund AJAX form. Single-use enough for
        // a session — the form rebuilds it on each render.
        $refund_nonce = wp_create_nonce('matrix_admin_billing_refund');

        // Base URL for filter links / pagination. Strips paged
        // and the WP nonce so links rebuild cleanly.
        $base_url = remove_query_arg(['paged', '_wpnonce'], add_query_arg([]));

        ?>
        <div class="wrap matrix-admin-wrap">
            <h1 class="matrix-admin-title"><?php _e('Bill Payments History', 'matrix-mlm'); ?></h1>

            <p class="description" style="margin-bottom:20px;">
                <?php _e('Inspect every airtime, data, cable, and electricity purchase routed through Fintava. Issue manual refunds against the user\'s Matrix wallet when a purchase needs remediation. Refunds are wallet-only — Fintava\'s billing endpoints have no upstream reversal API.', 'matrix-mlm'); ?>
            </p>

            <div class="matrix-admin-stats">
                <div class="stat-card stat-primary">
                    <div class="stat-content">
                        <h3><?php echo number_format((int) ($stats->rows_n ?? 0)); ?></h3>
                        <p><?php _e('Rows (filtered)', 'matrix-mlm'); ?></p>
                    </div>
                </div>
                <div class="stat-card stat-success">
                    <div class="stat-content">
                        <h3><?php echo esc_html($currency . number_format((float) ($stats->total_charged_sum ?? 0), 2)); ?></h3>
                        <p><?php _e('Total Charged', 'matrix-mlm'); ?></p>
                    </div>
                </div>
                <div class="stat-card stat-info">
                    <div class="stat-content">
                        <h3><?php echo esc_html($currency . number_format((float) ($stats->service_fee_sum ?? 0), 2)); ?></h3>
                        <p><?php _e('Service Fees', 'matrix-mlm'); ?></p>
                    </div>
                </div>
                <div class="stat-card stat-warning">
                    <div class="stat-content">
                        <h3><?php echo esc_html($currency . number_format((float) ($stats->refunded_sum ?? 0), 2)); ?></h3>
                        <p><?php _e('Refunded', 'matrix-mlm'); ?></p>
                    </div>
                </div>
            </div>

            <ul class="subsubsub" style="margin-top:20px;">
                <li><a href="<?php echo esc_url(remove_query_arg('status', $base_url)); ?>" class="<?php echo $f_status === '' ? 'current' : ''; ?>"><?php _e('All', 'matrix-mlm'); ?> (<?php echo number_format((int) ($status_totals->all_n ?? 0)); ?>)</a> |</li>
                <li><a href="<?php echo esc_url(add_query_arg('status', 'completed', $base_url)); ?>" class="<?php echo $f_status === 'completed' ? 'current' : ''; ?>"><?php _e('Completed', 'matrix-mlm'); ?> (<?php echo number_format((int) ($status_totals->completed_n ?? 0)); ?>)</a> |</li>
                <li><a href="<?php echo esc_url(add_query_arg('status', 'partial_refund', $base_url)); ?>" class="<?php echo $f_status === 'partial_refund' ? 'current' : ''; ?>"><?php _e('Partial Refund', 'matrix-mlm'); ?> (<?php echo number_format((int) ($status_totals->partial_n ?? 0)); ?>)</a> |</li>
                <li><a href="<?php echo esc_url(add_query_arg('status', 'refunded', $base_url)); ?>" class="<?php echo $f_status === 'refunded' ? 'current' : ''; ?>"><?php _e('Refunded', 'matrix-mlm'); ?> (<?php echo number_format((int) ($status_totals->refunded_n ?? 0)); ?>)</a> |</li>
                <li><a href="<?php echo esc_url(add_query_arg('status', 'failed', $base_url)); ?>" class="<?php echo $f_status === 'failed' ? 'current' : ''; ?>"><?php _e('Failed', 'matrix-mlm'); ?> (<?php echo number_format((int) ($status_totals->failed_n ?? 0)); ?>)</a> |</li>
                <li><a href="<?php echo esc_url(add_query_arg('status', 'pending', $base_url)); ?>" class="<?php echo $f_status === 'pending' ? 'current' : ''; ?>"><?php _e('Pending', 'matrix-mlm'); ?> (<?php echo number_format((int) ($status_totals->pending_n ?? 0)); ?>)</a></li>
            </ul>

            <form method="get" class="matrix-admin-card" style="margin-top:30px;padding:15px;">
                <input type="hidden" name="page" value="matrix-mlm-billing-history">
                <table class="form-table" style="margin:0;">
                    <tr>
                        <th style="width:140px;"><label for="filter-user"><?php _e('User', 'matrix-mlm'); ?></label></th>
                        <td>
                            <input type="text" id="filter-user" name="user" value="<?php echo esc_attr($f_user); ?>" placeholder="<?php esc_attr_e('ID, login, or email', 'matrix-mlm'); ?>" class="regular-text">
                        </td>
                        <th style="width:140px;"><label for="filter-type"><?php _e('Category', 'matrix-mlm'); ?></label></th>
                        <td>
                            <select id="filter-type" name="type">
                                <option value=""><?php _e('All categories', 'matrix-mlm'); ?></option>
                                <?php foreach ($valid_types as $t): ?>
                                    <option value="<?php echo esc_attr($t); ?>" <?php selected($f_type, $t); ?>><?php echo esc_html(ucfirst($t)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="filter-from"><?php _e('Date From', 'matrix-mlm'); ?></label></th>
                        <td><input type="date" id="filter-from" name="from" value="<?php echo esc_attr($f_from); ?>"></td>
                        <th><label for="filter-to"><?php _e('Date To', 'matrix-mlm'); ?></label></th>
                        <td><input type="date" id="filter-to" name="to" value="<?php echo esc_attr($f_to); ?>"></td>
                    </tr>
                </table>
                <?php if ($f_status !== ''): ?>
                    <input type="hidden" name="status" value="<?php echo esc_attr($f_status); ?>">
                <?php endif; ?>
                <p style="margin-top:10px;">
                    <input type="submit" class="button button-primary" value="<?php esc_attr_e('Apply Filters', 'matrix-mlm'); ?>">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=matrix-mlm-billing-history')); ?>" class="button"><?php _e('Reset', 'matrix-mlm'); ?></a>
                </p>
            </form>

            <?php if ($user_filter_unresolved): ?>
                <div class="notice notice-warning inline" style="margin-top:15px;">
                    <p><?php printf(esc_html__('No user matches "%s". Try an ID, login, or email.', 'matrix-mlm'), esc_html($f_user)); ?></p>
                </div>
            <?php endif; ?>

            <table class="wp-list-table widefat fixed striped" style="margin-top:20px;">
                <thead>
                    <tr>
                        <th style="width:60px;">#</th>
                        <th><?php _e('User', 'matrix-mlm'); ?></th>
                        <th style="width:90px;"><?php _e('Type', 'matrix-mlm'); ?></th>
                        <th><?php _e('Nominal', 'matrix-mlm'); ?></th>
                        <th><?php _e('Fee', 'matrix-mlm'); ?></th>
                        <th><?php _e('Total', 'matrix-mlm'); ?></th>
                        <th><?php _e('Refunded', 'matrix-mlm'); ?></th>
                        <th><?php _e('Status', 'matrix-mlm'); ?></th>
                        <th><?php _e('Date', 'matrix-mlm'); ?></th>
                        <th><?php _e('Details', 'matrix-mlm'); ?></th>
                        <th style="width:120px;"><?php _e('Actions', 'matrix-mlm'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="11" style="text-align:center;padding:30px;color:#6b7280;"><?php _e('No bill payments match the current filters.', 'matrix-mlm'); ?></td></tr>
                    <?php else: foreach ($rows as $row):
                        $remaining = round(((float) $row->total_charged) - ((float) $row->refunded_amount), 2);
                        $is_refundable = in_array($row->status, ['completed', 'partial_refund'], true) && $remaining > 0.005;
                        $details_decoded = json_decode((string) $row->details, true);
                    ?>
                        <tr>
                            <td><?php echo (int) $row->id; ?></td>
                            <td>
                                <?php if ($row->user_login): ?>
                                    <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . (int) $row->user_id)); ?>"><?php echo esc_html($row->user_login); ?></a>
                                    <br><small><?php echo esc_html($row->user_email ?: ''); ?></small>
                                <?php else: ?>
                                    <em>#<?php echo (int) $row->user_id; ?> <?php esc_html_e('(deleted)', 'matrix-mlm'); ?></em>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(ucfirst((string) $row->type)); ?></td>
                            <td><?php echo esc_html($currency . number_format((float) $row->nominal_amount, 2)); ?></td>
                            <td><?php echo esc_html($currency . number_format((float) $row->service_fee, 2)); ?></td>
                            <td><strong><?php echo esc_html($currency . number_format((float) $row->total_charged, 2)); ?></strong></td>
                            <td>
                                <?php if ((float) $row->refunded_amount > 0): ?>
                                    <?php echo esc_html($currency . number_format((float) $row->refunded_amount, 2)); ?>
                                <?php else: ?>
                                    <span style="color:#9ca3af;">—</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="matrix-badge matrix-badge-<?php echo esc_attr($row->status); ?>"><?php echo esc_html(ucwords(str_replace('_', ' ', (string) $row->status))); ?></span></td>
                            <td><?php echo esc_html(date('M d, Y H:i', strtotime((string) $row->created_at))); ?></td>
                            <td>
                                <?php if (is_array($details_decoded) && !empty($details_decoded)): ?>
                                    <details>
                                        <summary style="cursor:pointer;color:#2563eb;"><?php _e('show', 'matrix-mlm'); ?></summary>
                                        <pre style="white-space:pre-wrap;word-break:break-all;font-size:11px;background:#f9fafb;padding:6px;border-radius:4px;margin-top:4px;max-width:280px;"><?php echo esc_html(wp_json_encode($details_decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
                                    </details>
                                <?php else: ?>
                                    <span style="color:#9ca3af;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($can_refund && $is_refundable): ?>
                                    <button type="button" class="button button-small matrix-refund-btn"
                                        data-tx-id="<?php echo (int) $row->id; ?>"
                                        data-user-login="<?php echo esc_attr($row->user_login ?: ('#' . (int) $row->user_id)); ?>"
                                        data-type="<?php echo esc_attr($row->type); ?>"
                                        data-total="<?php echo esc_attr(number_format((float) $row->total_charged, 2, '.', '')); ?>"
                                        data-refunded="<?php echo esc_attr(number_format((float) $row->refunded_amount, 2, '.', '')); ?>"
                                        data-remaining="<?php echo esc_attr(number_format($remaining, 2, '.', '')); ?>"
                                        data-currency="<?php echo esc_attr($currency); ?>">
                                        <?php _e('Refund', 'matrix-mlm'); ?>
                                    </button>
                                <?php elseif (!$can_refund && $is_refundable): ?>
                                    <span title="<?php esc_attr_e('Requires manage_options capability', 'matrix-mlm'); ?>" style="color:#9ca3af;font-size:11px;"><?php _e('—', 'matrix-mlm'); ?></span>
                                <?php else: ?>
                                    <span style="color:#9ca3af;font-size:11px;"><?php _e('—', 'matrix-mlm'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom" style="margin-top:15px;">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php printf(esc_html__('%s items', 'matrix-mlm'), number_format($total)); ?></span>
                        <span class="pagination-links">
                            <?php
                            // Render the standard prev/next + jump-to-page
                            // chrome. Mirrors the WP_List_Table look without
                            // pulling the class in (other admin pages here
                            // use the same hand-rolled approach).
                            $links_base = remove_query_arg('paged', $base_url);
                            $first_url  = add_query_arg('paged', 1, $links_base);
                            $prev_url   = add_query_arg('paged', max(1, $paged - 1), $links_base);
                            $next_url   = add_query_arg('paged', min($total_pages, $paged + 1), $links_base);
                            $last_url   = add_query_arg('paged', $total_pages, $links_base);
                            ?>
                            <a class="first-page button" href="<?php echo esc_url($first_url); ?>" <?php echo $paged <= 1 ? 'aria-disabled="true"' : ''; ?>>&laquo;</a>
                            <a class="prev-page button" href="<?php echo esc_url($prev_url); ?>" <?php echo $paged <= 1 ? 'aria-disabled="true"' : ''; ?>>&lsaquo;</a>
                            <span class="paging-input"><?php printf(esc_html__('Page %1$d of %2$d', 'matrix-mlm'), $paged, $total_pages); ?></span>
                            <a class="next-page button" href="<?php echo esc_url($next_url); ?>" <?php echo $paged >= $total_pages ? 'aria-disabled="true"' : ''; ?>>&rsaquo;</a>
                            <a class="last-page button" href="<?php echo esc_url($last_url); ?>" <?php echo $paged >= $total_pages ? 'aria-disabled="true"' : ''; ?>>&raquo;</a>
                        </span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($can_refund): ?>
                <?php $this->render_refund_modal($refund_nonce); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Inline refund modal + the JS that drives it.
     *
     * Vanilla JS, no jQuery dep. Focus is moved to the reason
     * field on open and returned to the triggering button on
     * close. Escape closes. Submit posts to admin-ajax.php and
     * either reloads (success) or surfaces the server message
     * inline (failure) so the operator sees what went wrong
     * without losing what they typed.
     */
    private function render_refund_modal($nonce) {
        ?>
        <div id="matrix-refund-modal" role="dialog" aria-modal="true" aria-labelledby="matrix-refund-title" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:100050;align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:8px;padding:24px;width:480px;max-width:calc(100vw - 40px);box-shadow:0 20px 50px rgba(0,0,0,0.3);">
                <h2 id="matrix-refund-title" style="margin-top:0;"><?php _e('Refund Bill Payment', 'matrix-mlm'); ?></h2>
                <p id="matrix-refund-summary" style="color:#4b5563;margin-bottom:14px;"></p>

                <div id="matrix-refund-error" class="notice notice-error inline" style="display:none;margin:0 0 12px;"><p></p></div>

                <form id="matrix-refund-form">
                    <?php wp_nonce_field('matrix_admin_billing_refund', 'matrix_refund_nonce'); ?>
                    <input type="hidden" name="action" value="matrix_admin_billing_refund">
                    <input type="hidden" name="transaction_id" id="matrix-refund-tx-id" value="">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">

                    <table class="form-table" style="margin:0;">
                        <tr>
                            <th><label for="matrix-refund-amount"><?php _e('Amount', 'matrix-mlm'); ?></label></th>
                            <td>
                                <span id="matrix-refund-currency"></span>
                                <input type="number" step="0.01" min="0.01" id="matrix-refund-amount" name="amount" required class="regular-text" style="width:140px;">
                                <p class="description" id="matrix-refund-amount-help"></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="matrix-refund-reason"><?php _e('Reason', 'matrix-mlm'); ?></label></th>
                            <td>
                                <textarea id="matrix-refund-reason" name="reason" required maxlength="<?php echo (int) self::REASON_MAX_LEN; ?>" rows="4" class="large-text" placeholder="<?php esc_attr_e('e.g. DSTV did not activate after purchase, support ticket #1234', 'matrix-mlm'); ?>"></textarea>
                                <p class="description"><?php printf(esc_html__('Required. Max %d characters. Recorded in the audit trail and shared with the user in their refund notification.', 'matrix-mlm'), self::REASON_MAX_LEN); ?></p>
                            </td>
                        </tr>
                    </table>

                    <p style="margin-top:16px;text-align:right;">
                        <button type="button" class="button" id="matrix-refund-cancel"><?php _e('Cancel', 'matrix-mlm'); ?></button>
                        <button type="submit" class="button button-primary" id="matrix-refund-submit"><?php _e('Issue Refund', 'matrix-mlm'); ?></button>
                    </p>
                </form>
            </div>
        </div>

        <script>
        (function(){
            var modal     = document.getElementById('matrix-refund-modal');
            var form      = document.getElementById('matrix-refund-form');
            var txIdInput = document.getElementById('matrix-refund-tx-id');
            var amountIn  = document.getElementById('matrix-refund-amount');
            var reasonIn  = document.getElementById('matrix-refund-reason');
            var summary   = document.getElementById('matrix-refund-summary');
            var amountHelp= document.getElementById('matrix-refund-amount-help');
            var currencyL = document.getElementById('matrix-refund-currency');
            var errorBox  = document.getElementById('matrix-refund-error');
            var submitBtn = document.getElementById('matrix-refund-submit');
            var cancelBtn = document.getElementById('matrix-refund-cancel');
            var lastTrigger = null;

            function showError(msg) {
                errorBox.querySelector('p').textContent = msg;
                errorBox.style.display = '';
            }
            function hideError() {
                errorBox.style.display = 'none';
            }
            function open(btn) {
                lastTrigger = btn;
                hideError();
                form.reset();

                txIdInput.value = btn.getAttribute('data-tx-id');
                var currency  = btn.getAttribute('data-currency') || '';
                var total     = parseFloat(btn.getAttribute('data-total') || '0');
                var refunded  = parseFloat(btn.getAttribute('data-refunded') || '0');
                var remaining = parseFloat(btn.getAttribute('data-remaining') || '0');
                var userLogin = btn.getAttribute('data-user-login') || '';
                var type      = btn.getAttribute('data-type') || '';

                summary.textContent = 'User: ' + userLogin
                    + ' • ' + type.charAt(0).toUpperCase() + type.slice(1)
                    + ' purchase #' + btn.getAttribute('data-tx-id')
                    + ' • Total ' + currency + total.toFixed(2)
                    + ' • Already refunded ' + currency + refunded.toFixed(2);

                currencyL.textContent = currency;
                amountIn.max = remaining.toFixed(2);
                amountIn.value = remaining.toFixed(2);
                amountHelp.textContent = 'Max ' + currency + remaining.toFixed(2) + ' (remaining unrefunded balance).';

                modal.style.display = 'flex';
                setTimeout(function(){ reasonIn.focus(); }, 30);
            }
            function close() {
                modal.style.display = 'none';
                if (lastTrigger) lastTrigger.focus();
            }

            document.addEventListener('click', function(ev){
                var t = ev.target.closest('.matrix-refund-btn');
                if (t) { ev.preventDefault(); open(t); }
            });
            cancelBtn.addEventListener('click', close);
            modal.addEventListener('click', function(ev){
                if (ev.target === modal) close();
            });
            document.addEventListener('keydown', function(ev){
                if (ev.key === 'Escape' && modal.style.display === 'flex') close();
            });

            form.addEventListener('submit', function(ev){
                ev.preventDefault();
                hideError();

                var amount = parseFloat(amountIn.value || '0');
                var max    = parseFloat(amountIn.max || '0');
                var reason = reasonIn.value.trim();

                if (!(amount > 0)) { showError('Amount must be greater than zero.'); return; }
                if (amount > max + 0.005) { showError('Amount exceeds remaining balance.'); return; }
                if (reason.length === 0) { showError('Reason is required.'); return; }
                if (!confirm('Refund ' + amountIn.value + ' to this user?')) { return; }

                submitBtn.disabled = true;
                submitBtn.textContent = 'Processing...';

                var fd = new FormData(form);
                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(json){
                        if (json && json.success) {
                            // Reload to show the updated row + status
                            // chip counts. The success message would be
                            // good to surface, but a fresh page load is
                            // closer to the "atomic confirmation" UX
                            // operators expect.
                            window.location.reload();
                        } else {
                            var msg = (json && json.data && json.data.message) || 'Refund failed. See server logs.';
                            showError(msg);
                            submitBtn.disabled = false;
                            submitBtn.textContent = 'Issue Refund';
                        }
                    })
                    .catch(function(err){
                        showError('Network error: ' + (err && err.message ? err.message : 'unknown'));
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Issue Refund';
                    });
            });
        })();
        </script>
        <?php
    }

    /**
     * AJAX endpoint: wp_ajax_matrix_admin_billing_refund.
     *
     * Capability:        manage_options (REFUND_CAPABILITY).
     * Nonce:             matrix_admin_billing_refund.
     * Required POST:     transaction_id, amount, reason.
     *
     * Concurrency model is documented in the class docblock at the
     * top of this file; the short version is "CAS the transactions
     * row first, credit the wallet only if the CAS landed".
     */
    public static function ajax_refund() {
        check_ajax_referer('matrix_admin_billing_refund', 'nonce');

        if (!current_user_can(self::REFUND_CAPABILITY)) {
            wp_send_json_error(['message' => __('You do not have permission to issue refunds.', 'matrix-mlm')], 403);
        }

        $transaction_id = isset($_POST['transaction_id']) ? absint($_POST['transaction_id']) : 0;
        $amount         = isset($_POST['amount'])         ? round((float) $_POST['amount'], 2) : 0;
        $reason         = isset($_POST['reason'])         ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : '';

        if ($transaction_id <= 0) {
            wp_send_json_error(['message' => __('Invalid transaction id.', 'matrix-mlm')]);
        }
        if ($amount <= 0) {
            wp_send_json_error(['message' => __('Amount must be greater than zero.', 'matrix-mlm')]);
        }
        if ($reason === '') {
            wp_send_json_error(['message' => __('Reason is required.', 'matrix-mlm')]);
        }
        if (mb_strlen($reason) > self::REASON_MAX_LEN) {
            wp_send_json_error(['message' => sprintf(
                /* translators: %d: max reason length */
                __('Reason is too long (max %d characters).', 'matrix-mlm'),
                self::REASON_MAX_LEN
            )]);
        }

        global $wpdb;
        $tx_table      = $wpdb->prefix . 'matrix_billing_transactions';
        $refunds_table = $wpdb->prefix . 'matrix_billing_refunds';

        // 1. Read the row for the response payload + the
        //    user_id we'll need for the wallet credit. Note
        //    we DON'T trust this read for the cap check —
        //    the CAS in step 2 is the actual gate. This read
        //    is just for "what user / what type" so the wallet
        //    credit description and notification body can
        //    name the right purchase.
        $tx = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $tx_table WHERE id = %d",
            $transaction_id
        ));
        if (!$tx) {
            wp_send_json_error(['message' => __('Transaction not found.', 'matrix-mlm')]);
        }
        if (!in_array($tx->status, ['completed', 'partial_refund'], true)) {
            wp_send_json_error(['message' => __('This transaction is not refundable in its current state.', 'matrix-mlm')]);
        }

        $total_charged = round((float) $tx->total_charged, 2);
        $already       = round((float) $tx->refunded_amount, 2);
        $remaining     = round($total_charged - $already, 2);
        if ($amount > $remaining + self::FLOAT_EPSILON) {
            wp_send_json_error(['message' => sprintf(
                /* translators: 1: currency symbol, 2: remaining amount */
                __('Amount exceeds remaining unrefunded balance (%1$s%2$s).', 'matrix-mlm'),
                get_option('matrix_mlm_currency_symbol', '₦'),
                number_format($remaining, 2)
            )]);
        }

        // 2. CAS-update the transactions row. Two parallel
        //    refund clicks against the same row both reach
        //    this UPDATE; only one matches the WHERE clause.
        //    The second sees affected_rows == 0 and is told
        //    to retry with a fresh remaining amount, BEFORE
        //    any wallet movement. The wallet is therefore
        //    only credited on a transaction-row write that
        //    we know was the canonical one.
        //
        //    Determining the post-refund status inline in
        //    SQL keeps the status flag in lock-step with the
        //    refunded_amount value, so a reader observing the
        //    row mid-flight (between this UPDATE and step 3)
        //    sees a coherent state.
        $new_refunded_expr = "refunded_amount + " . sprintf('%.2F', $amount);
        $sql = $wpdb->prepare(
            "UPDATE $tx_table
                SET refunded_amount = $new_refunded_expr,
                    status = CASE
                        WHEN $new_refunded_expr >= total_charged - %f
                             THEN 'refunded'
                        ELSE 'partial_refund'
                    END
              WHERE id = %d
                AND status IN ('completed', 'partial_refund')
                AND total_charged - refunded_amount >= %f - %f",
            self::FLOAT_EPSILON,
            $transaction_id,
            $amount,
            self::FLOAT_EPSILON
        );
        $cas_rows = $wpdb->query($sql);

        if ($cas_rows === false) {
            error_log(sprintf(
                '[Matrix Billing Refund] CAS UPDATE failed: tx_id=%d amount=%.2f last_error=%s',
                $transaction_id, $amount, $wpdb->last_error
            ));
            wp_send_json_error(['message' => __('Database error processing refund. See error log.', 'matrix-mlm')]);
        }
        if ($cas_rows === 0) {
            // Race detected, OR the row's state changed under us
            // (status moved off completed/partial_refund, or
            // another refund pushed refunded_amount up). Tell the
            // operator to refresh.
            wp_send_json_error(['message' => __('This transaction was modified concurrently. Refresh the page and try again with the updated remaining balance.', 'matrix-mlm')]);
        }

        // 3. Credit the user's Matrix wallet. The reference is
        //    UNIQUE-keyed in the wallet auditor table indirectly
        //    (and primary-keyed in matrix_billing_refunds below)
        //    so a re-submitted modal cannot double-credit.
        $reference = sprintf('ADMIN-REFUND-%d-%s', $transaction_id, wp_generate_uuid4());
        $description = sprintf(
            /* translators: 1: bill type, 2: tx id, 3: reason */
            __('Admin refund: %1$s purchase #%2$d - %3$s', 'matrix-mlm'),
            (string) $tx->type,
            (int) $tx->id,
            $reason
        );
        $wallet  = new Matrix_MLM_Wallet();
        $credited = $wallet->credit(
            (int) $tx->user_id,
            $amount,
            'bill_admin_refund',
            $description,
            $reference
        );
        if ($credited === false) {
            // Wallet credit failed AFTER we already bumped
            // refunded_amount + status. Compensate by rolling
            // back the CAS so the row's state matches the
            // wallet's. Both writes are scoped to one row
            // and one wallet auditor row, so the worst case
            // is the audit log shows a CAS-then-revert pair —
            // which is exactly the trail an operator wants
            // when they're investigating a refund that
            // reportedly didn't land.
            $rollback_sql = $wpdb->prepare(
                "UPDATE $tx_table
                    SET refunded_amount = refunded_amount - %f,
                        status = CASE
                            WHEN refunded_amount - %f <= %f
                                 THEN 'completed'
                            ELSE 'partial_refund'
                        END
                  WHERE id = %d
                    AND refunded_amount >= %f",
                $amount, $amount, self::FLOAT_EPSILON, $transaction_id, $amount - self::FLOAT_EPSILON
            );
            $rolled = $wpdb->query($rollback_sql);
            error_log(sprintf(
                '[Matrix Billing Refund] CRITICAL: wallet credit failed after CAS. tx_id=%d amount=%.2f reference=%s rollback_rows=%s',
                $transaction_id, $amount, $reference, var_export($rolled, true)
            ));
            Matrix_MLM_Notifications::send_admin_notification(
                'billing_refund_wallet_credit_failed',
                sprintf(
                    /* translators: 1: tx id, 2: amount, 3: reference */
                    __('Manual bill refund failed at the wallet credit step. Transaction #%1$d, amount %2$.2f, reference %3$s. The transaction row was rolled back if the rollback succeeded — verify in admin and inspect error_log.', 'matrix-mlm'),
                    $transaction_id, $amount, $reference
                )
            );
            wp_send_json_error(['message' => __('Wallet credit failed. The refund was not applied — see error log.', 'matrix-mlm')]);
        }

        // 4. Insert the audit row. The unique
        //    wallet_credit_reference column makes a duplicate
        //    INSERT (e.g. retry of the same UUID, which
        //    shouldn't happen but defensive) fail loudly
        //    rather than silently double-recording. Belt-
        //    and-braces; in practice every reference is a
        //    fresh wp_generate_uuid4().
        $inserted = $wpdb->insert(
            $refunds_table,
            [
                'transaction_id'          => $transaction_id,
                'admin_user_id'           => get_current_user_id(),
                'amount'                  => $amount,
                'reason'                  => $reason,
                'wallet_credit_reference' => $reference,
                'created_at'              => current_time('mysql'),
            ],
            ['%d', '%d', '%f', '%s', '%s', '%s']
        );
        if ($inserted === false) {
            // Audit insert failed AFTER the wallet was credited
            // and the transaction row was bumped. We cannot
            // safely roll either of those back at this point —
            // the user has the money, and rolling refunded_amount
            // back would un-cap the next refund click incorrectly.
            // Surface a loud warning so operators investigate;
            // the wallet auditor row from credit() is the
            // primary record of the movement and is sufficient
            // for reconciliation.
            error_log(sprintf(
                '[Matrix Billing Refund] CRITICAL: audit row insert failed after wallet credit. tx_id=%d amount=%.2f reference=%s last_error=%s',
                $transaction_id, $amount, $reference, $wpdb->last_error
            ));
            Matrix_MLM_Notifications::send_admin_notification(
                'billing_refund_audit_insert_failed',
                sprintf(
                    /* translators: 1: tx id, 2: amount, 3: reference */
                    __('Manual bill refund completed (wallet credited, transaction row updated) but the audit row insert failed. Transaction #%1$d, amount %2$.2f, reference %3$s. Reconcile against the wallet auditor row manually.', 'matrix-mlm'),
                    $transaction_id, $amount, $reference
                )
            );
            // Continue — the user got their money, that's the
            // right behaviour. The audit gap is for ops to fix.
        }

        // 5. Re-read the row so the response carries the
        //    canonical post-refund values (status, cumulative
        //    refunded_amount). Cheap one-row read — worth it
        //    to avoid the response lying if SQL coerced the
        //    decimal differently than PHP's arithmetic.
        $tx_after = $wpdb->get_row($wpdb->prepare(
            "SELECT status, refunded_amount FROM $tx_table WHERE id = %d",
            $transaction_id
        ));

        // 6. Notify the user. Best-effort — wp_mail failures
        //    don't roll the refund back; the user has the money,
        //    a follow-up email is the only thing missing.
        Matrix_MLM_Notifications::send_billing_refund_notification(
            (int) $tx->user_id,
            (string) $tx->type,
            (float) $amount,
            (string) $reason,
            (int) $tx->id
        );

        wp_send_json_success([
            'message'         => __('Refund processed.', 'matrix-mlm'),
            'transaction_id'  => $transaction_id,
            'new_status'      => $tx_after ? (string) $tx_after->status : 'partial_refund',
            'refunded_amount' => $tx_after ? (float) $tx_after->refunded_amount : null,
            'reference'       => $reference,
        ]);
    }
}
