<?php
/**
 * Admin — CUG (Closed User Group) request triage
 *
 * Lists and manages the rows in wp_matrix_cug_requests submitted by
 * members through the Benefits-tab CUG card form. Operators can
 * filter by status, view full details, change status (approve /
 * reject / cancel), and attach internal notes.
 *
 * Routing mirrors Matrix_MLM_Admin_Benefits (?action=view / update),
 * so navigation/back-button behaviour matches the rest of the
 * Matrix MLM admin. All form submissions land back on the same admin
 * URL with an inline notice rather than redirecting — same pattern
 * used elsewhere in this codebase.
 *
 * Capability: manage_matrix_mlm. Same gate as the Benefits CRUD page,
 * which is the upstream system that decided this user got to apply
 * in the first place — keeping both pages on the same capability
 * means an operator's permissions never get out of sync between the
 * "what benefits exist" surface and the "who has applied" surface.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_CUG {

    /** Whitelist of statuses an operator can transition a request to.
     *  Mirrors the ENUM column in matrix_cug_requests. */
    const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    /**
     * Top-level entry point. Routes to the detail view or the list,
     * processing any submitted form first so the resulting page can
     * render its own success/error notice inline.
     */
    public function render() {
        if (!current_user_can('manage_matrix_mlm')) {
            wp_die(__('You do not have permission to manage CUG requests.', 'matrix-mlm'));
        }

        // Process status update before render so the notice appears
        // above the page that follows. The nonce protects against
        // CSRF; the capability check above protects against access
        // by non-admins; both must pass.
        if (isset($_POST['matrix_cug_update']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'matrix_cug_update')) {
            $this->handle_update();
        }

        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';

        if ($action === 'view' && isset($_GET['id'])) {
            $this->render_detail(intval($_GET['id']));
            return;
        }

        $this->render_list();
    }

    /**
     * Render the paginated list, optionally filtered by status.
     */
    private function render_list() {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_cug_requests';

        // Schema probe — same reason as the Benefits panel: a
        // fresh-install pageload may run this before maybe_upgrade
        // creates the table on its first pass.
        if (!self::table_exists()) {
            ?>
            <div class="wrap matrix-admin-wrap">
                <h1><?php _e('CUG Requests', 'matrix-mlm'); ?></h1>
                <div class="notice notice-info">
                    <p><?php _e('CUG request storage is being initialised. Please reload this page in a moment.', 'matrix-mlm'); ?></p>
                </div>
            </div>
            <?php
            return;
        }

        // Status filter. 'all' (or any unrecognised value) shows
        // everything; otherwise the value must be in STATUSES so
        // a malformed query string can't inject SQL via the WHERE
        // clause's parameter.
        $filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
        if ($filter !== 'all' && !in_array($filter, self::STATUSES, true)) {
            $filter = 'all';
        }

        // Counts per status for the filter chips. One round-trip
        // per page render — cheap on a table that's small by design
        // (one row per user).
        $counts = $this->get_status_counts();

        // Pagination. 25 per page is the WP-list-table convention.
        $per_page = 25;
        $paged    = max(1, isset($_GET['paged']) ? intval($_GET['paged']) : 1);
        $offset   = ($paged - 1) * $per_page;

        if ($filter === 'all') {
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            $rows  = $wpdb->get_results($wpdb->prepare(
                "SELECT r.*, u.user_login, u.user_email
                   FROM {$table} r
                   LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
                  ORDER BY r.created_at DESC
                  LIMIT %d OFFSET %d",
                $per_page, $offset
            ));
        } else {
            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE status = %s",
                $filter
            ));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT r.*, u.user_login, u.user_email
                   FROM {$table} r
                   LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
                  WHERE r.status = %s
                  ORDER BY r.created_at DESC
                  LIMIT %d OFFSET %d",
                $filter, $per_page, $offset
            ));
        }
        $total_pages = max(1, (int) ceil($total / $per_page));

        $base_url = admin_url('admin.php?page=matrix-mlm-cug');
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php _e('CUG Requests', 'matrix-mlm'); ?></h1>
            <p class="description">
                <?php _e('Member applications for the Closed User Group benefit, submitted from the dashboard\'s Benefits tab.', 'matrix-mlm'); ?>
            </p>

            <ul class="subsubsub">
                <?php
                $links = [
                    'all'       => __('All', 'matrix-mlm'),
                    'pending'   => __('Pending', 'matrix-mlm'),
                    'approved'  => __('Approved', 'matrix-mlm'),
                    'rejected'  => __('Rejected', 'matrix-mlm'),
                    'cancelled' => __('Cancelled', 'matrix-mlm'),
                ];
                $i = 0;
                foreach ($links as $key => $label):
                    $url    = $key === 'all' ? $base_url : add_query_arg('status', $key, $base_url);
                    $count  = $key === 'all' ? array_sum($counts) : ($counts[$key] ?? 0);
                    $active = $filter === $key ? ' class="current"' : '';
                    $sep    = (++$i < count($links)) ? ' | ' : '';
                ?>
                    <li>
                        <a href="<?php echo esc_url($url); ?>"<?php echo $active; ?>>
                            <?php echo esc_html($label); ?>
                            <span class="count">(<?php echo intval($count); ?>)</span>
                        </a><?php echo $sep; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <table class="wp-list-table widefat fixed striped" style="margin-top:16px;">
                <thead>
                    <tr>
                        <th style="width:60px;"><?php _e('ID', 'matrix-mlm'); ?></th>
                        <th><?php _e('User', 'matrix-mlm'); ?></th>
                        <th><?php _e('Name', 'matrix-mlm'); ?></th>
                        <th style="width:140px;"><?php _e('NIN', 'matrix-mlm'); ?></th>
                        <th style="width:140px;"><?php _e('Airtel', 'matrix-mlm'); ?></th>
                        <th style="width:100px;"><?php _e('Status', 'matrix-mlm'); ?></th>
                        <th style="width:160px;"><?php _e('Submitted', 'matrix-mlm'); ?></th>
                        <th style="width:90px;"><?php _e('Actions', 'matrix-mlm'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="8" style="padding:24px;text-align:center;color:#6b7280;">
                                <?php
                                if ($filter === 'all') {
                                    _e('No CUG applications have been submitted yet.', 'matrix-mlm');
                                } else {
                                    /* translators: %s: status label */
                                    printf(esc_html__('No %s applications.', 'matrix-mlm'), esc_html($links[$filter] ?? $filter));
                                }
                                ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row):
                            $view_url = add_query_arg(['action' => 'view', 'id' => $row->id], $base_url);
                            $name     = trim((string) $row->first_name . ' ' . (string) $row->last_name);
                            $airtel   = trim((string) $row->airtel_number);
                        ?>
                        <tr>
                            <td><?php echo intval($row->id); ?></td>
                            <td>
                                <?php if ($row->user_login): ?>
                                    <strong><?php echo esc_html($row->user_login); ?></strong><br>
                                    <span style="font-size:11px;color:#6b7280;"><?php echo esc_html($row->user_email); ?></span>
                                <?php else: ?>
                                    <em style="color:#9ca3af;"><?php
                                        /* translators: %d: user id */
                                        printf(esc_html__('user #%d (deleted)', 'matrix-mlm'), intval($row->user_id));
                                    ?></em>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($name); ?></td>
                            <td><code style="font-size:11px;"><?php echo esc_html($row->nin); ?></code></td>
                            <td>
                                <?php if ($airtel !== ''): ?>
                                    <code style="font-size:11px;"><?php echo esc_html($airtel); ?></code>
                                <?php else: ?>
                                    <span style="color:#9ca3af;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="matrix-badge matrix-badge-<?php echo esc_attr($row->status); ?>">
                                    <?php echo esc_html(ucfirst($row->status)); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html(self::format_datetime($row->created_at)); ?></td>
                            <td>
                                <a href="<?php echo esc_url($view_url); ?>" class="button button-small">
                                    <?php _e('View', 'matrix-mlm'); ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1): ?>
            <div class="tablenav bottom" style="margin-top:12px;">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php
                        /* translators: %s: number of items */
                        printf(_n('%s item', '%s items', $total, 'matrix-mlm'), number_format_i18n($total));
                        ?>
                    </span>
                    <span class="pagination-links">
                        <?php
                        $pagination_args = ['paged' => '%#%'];
                        if ($filter !== 'all') {
                            $pagination_args['status'] = $filter;
                        }
                        echo paginate_links([
                            'base'      => add_query_arg($pagination_args, $base_url),
                            'format'    => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total'     => $total_pages,
                            'current'   => $paged,
                        ]);
                        ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the detail page for a single request, including the
     * status-change form. Linked from the list table's "View" action.
     */
    private function render_detail($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_cug_requests';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, u.user_login, u.user_email, u.display_name
               FROM {$table} r
               LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
              WHERE r.id = %d",
            $id
        ));

        $back_url = admin_url('admin.php?page=matrix-mlm-cug');

        if (!$row) {
            ?>
            <div class="wrap matrix-admin-wrap">
                <h1><?php _e('CUG Request', 'matrix-mlm'); ?></h1>
                <div class="notice notice-error">
                    <p><?php _e('CUG request not found. It may have been deleted.', 'matrix-mlm'); ?></p>
                </div>
                <p><a href="<?php echo esc_url($back_url); ?>" class="button">&larr; <?php _e('Back to list', 'matrix-mlm'); ?></a></p>
            </div>
            <?php
            return;
        }

        // Pull the current set of plans the applicant holds, so the
        // operator can sanity-check eligibility at a glance without
        // popping over to the Users page. Defensive against the
        // helper class not being loaded.
        $plans = [];
        if (class_exists('Matrix_MLM_User')) {
            $plans = Matrix_MLM_User::get_active_plans(intval($row->user_id));
        }

        ?>
        <div class="wrap matrix-admin-wrap">
            <h1>
                <?php
                /* translators: %d: request id */
                printf(esc_html__('CUG Request #%d', 'matrix-mlm'), intval($row->id));
                ?>
                <a href="<?php echo esc_url($back_url); ?>" class="page-title-action">
                    <?php _e('Back to list', 'matrix-mlm'); ?>
                </a>
            </h1>

            <div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;margin-top:16px;align-items:start;">
                <div class="matrix-admin-card">
                    <h2 style="margin-top:0;"><?php _e('Application Details', 'matrix-mlm'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th><?php _e('Member', 'matrix-mlm'); ?></th>
                            <td>
                                <?php if ($row->user_login): ?>
                                    <strong><?php echo esc_html($row->display_name ?: $row->user_login); ?></strong>
                                    <br><code><?php echo esc_html($row->user_login); ?></code>
                                    &mdash; <?php echo esc_html($row->user_email); ?>
                                <?php else: ?>
                                    <em style="color:#9ca3af;"><?php
                                        /* translators: %d: user id */
                                        printf(esc_html__('user #%d (deleted)', 'matrix-mlm'), intval($row->user_id));
                                    ?></em>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Active plans', 'matrix-mlm'); ?></th>
                            <td>
                                <?php if (!empty($plans)): ?>
                                    <?php foreach ($plans as $plan): ?>
                                        <span class="matrix-badge matrix-badge-active"
                                              style="margin-right:6px;"><?php
                                            echo esc_html($plan->name ?? sprintf('plan #%d', intval($plan->id ?? 0)));
                                        ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <em style="color:#b91c1c;"><?php _e('No active plan — eligibility may have lapsed.', 'matrix-mlm'); ?></em>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('First Name', 'matrix-mlm'); ?></th>
                            <td><?php echo esc_html($row->first_name); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Last Name', 'matrix-mlm'); ?></th>
                            <td><?php echo esc_html($row->last_name); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('NIN', 'matrix-mlm'); ?></th>
                            <td><code><?php echo esc_html($row->nin); ?></code></td>
                        </tr>
                        <tr>
                            <th><?php _e('Existing Airtel Number', 'matrix-mlm'); ?></th>
                            <td>
                                <?php if (!empty($row->airtel_number)): ?>
                                    <code><?php echo esc_html($row->airtel_number); ?></code>
                                <?php else: ?>
                                    <em style="color:#9ca3af;"><?php _e('not provided', 'matrix-mlm'); ?></em>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Submitted', 'matrix-mlm'); ?></th>
                            <td><?php echo esc_html(self::format_datetime($row->created_at)); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Last updated', 'matrix-mlm'); ?></th>
                            <td><?php echo esc_html(self::format_datetime($row->updated_at)); ?></td>
                        </tr>
                    </table>
                </div>

                <div class="matrix-admin-card">
                    <h2 style="margin-top:0;"><?php _e('Triage', 'matrix-mlm'); ?></h2>
                    <p>
                        <?php _e('Current status:', 'matrix-mlm'); ?>
                        <span class="matrix-badge matrix-badge-<?php echo esc_attr($row->status); ?>">
                            <?php echo esc_html(ucfirst($row->status)); ?>
                        </span>
                    </p>

                    <form method="post" action="<?php echo esc_url($back_url); ?>">
                        <?php wp_nonce_field('matrix_cug_update'); ?>
                        <input type="hidden" name="action" value="view">
                        <input type="hidden" name="id" value="<?php echo intval($row->id); ?>">
                        <input type="hidden" name="cug_request_id" value="<?php echo intval($row->id); ?>">

                        <p>
                            <label for="cug_status" style="display:block;font-weight:600;margin-bottom:4px;">
                                <?php _e('Change status to', 'matrix-mlm'); ?>
                            </label>
                            <select id="cug_status" name="status" style="width:100%;">
                                <?php foreach (self::STATUSES as $s): ?>
                                    <option value="<?php echo esc_attr($s); ?>" <?php selected($row->status, $s); ?>>
                                        <?php echo esc_html(ucfirst($s)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </p>

                        <p>
                            <label for="cug_admin_notes" style="display:block;font-weight:600;margin-bottom:4px;">
                                <?php _e('Internal notes', 'matrix-mlm'); ?>
                            </label>
                            <textarea id="cug_admin_notes" name="admin_notes" rows="5"
                                      style="width:100%;"
                                      placeholder="<?php esc_attr_e('Visible to admins only.', 'matrix-mlm'); ?>"><?php
                                echo esc_textarea($row->admin_notes ?? '');
                            ?></textarea>
                        </p>

                        <p>
                            <button type="submit" name="matrix_cug_update" class="button button-primary">
                                <?php _e('Save changes', 'matrix-mlm'); ?>
                            </button>
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Apply a status / notes update from the detail-page form.
     * Inputs are validated; a status outside the whitelist is
     * rejected outright rather than silently coerced, so an admin
     * who misuses the form sees an explicit error instead of an
     * apparently-successful save with the wrong value.
     */
    private function handle_update() {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_cug_requests';

        $id     = isset($_POST['cug_request_id']) ? intval($_POST['cug_request_id']) : 0;
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
        $notes  = isset($_POST['admin_notes']) ? sanitize_textarea_field(wp_unslash($_POST['admin_notes'])) : '';

        if ($id <= 0) {
            self::admin_notice('error', __('Invalid request ID.', 'matrix-mlm'));
            return;
        }
        if (!in_array($status, self::STATUSES, true)) {
            self::admin_notice('error', __('Invalid status value.', 'matrix-mlm'));
            return;
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM {$table} WHERE id = %d",
            $id
        ));
        if (!$existing) {
            self::admin_notice('error', __('CUG request not found.', 'matrix-mlm'));
            return;
        }

        $result = $wpdb->update(
            $table,
            [
                'status'      => $status,
                'admin_notes' => $notes,
                'updated_at'  => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        if ($result === false) {
            self::admin_notice('error', __('Could not update CUG request.', 'matrix-mlm') . ' ' . esc_html($wpdb->last_error));
            return;
        }

        // $result === 0 is normal when the operator submits the form
        // without changing anything (status and notes both unchanged).
        // Treat as success so the operator's intent is reflected in
        // the UI; the timestamp didn't move because nothing moved.
        if ($status !== $existing->status) {
            self::admin_notice(
                'success',
                sprintf(
                    /* translators: 1: old status, 2: new status */
                    __('Status changed from <strong>%1$s</strong> to <strong>%2$s</strong>.', 'matrix-mlm'),
                    esc_html(ucfirst($existing->status)),
                    esc_html(ucfirst($status))
                )
            );
        } else {
            self::admin_notice('success', __('CUG request saved.', 'matrix-mlm'));
        }
    }

    /**
     * Per-status row counts for the filter chips. Returns a
     * status => count map; missing statuses default to 0 at
     * read time.
     */
    private function get_status_counts() {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_cug_requests';

        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status"
        );
        $out = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'cancelled' => 0];
        foreach ((array) $rows as $r) {
            $out[$r->status] = (int) $r->n;
        }
        return $out;
    }

    /**
     * INFORMATION_SCHEMA probe so a missing-table install (older
     * schema, repair pending) doesn't bomb on first read. Same
     * cheap-and-safe pattern used by Matrix_MLM_User_Benefits.
     */
    private static function table_exists() {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_cug_requests';
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $table
        ));
        return $exists > 0;
    }

    /**
     * Format a MySQL datetime string for the admin tables. Uses the
     * site's date_format + time_format options so operators in
     * different locales see dates the way they expect, and falls
     * back to a sensible ISO-ish string when the input is empty or
     * not parseable rather than rendering "Jan 1, 1970".
     */
    private static function format_datetime($mysql_dt) {
        if (empty($mysql_dt) || $mysql_dt === '0000-00-00 00:00:00') {
            return '—';
        }
        $ts = strtotime($mysql_dt);
        if ($ts === false) {
            return (string) $mysql_dt;
        }
        $fmt = trim(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'));
        return date_i18n($fmt, $ts);
    }

    /**
     * Render an inline admin notice (echoes immediately because
     * the page is rendered in the same request as the form post,
     * not after a redirect — same pattern used by the Benefits
     * admin).
     */
    private static function admin_notice($type, $message) {
        $cls = $type === 'error' ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . esc_attr($cls) . ' is-dismissible"><p>'
            . wp_kses_post($message)
            . '</p></div>';
    }
}
