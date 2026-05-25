<?php
/**
 * Admin — Zebra Wallet /Notify dispute / fraud / chargeback feed.
 *
 * Operator-facing review surface for inbound Bibimoney /Notify
 * events. Each row is one notification persisted by
 * Matrix_MLM_Zebra::record_notify_event() at IPN-receive time;
 * this page renders them with state filters and per-row action
 * buttons for the three operator responses:
 *
 *   - Acknowledge       (state='acknowledged') — clears the
 *                        unread badge; no money moves.
 *   - Freeze withdrawal (state='actioned')     — only available
 *                        when the related row is a pending /
 *                        processing matrix_withdrawals row.
 *                        Forces the withdrawal to status
 *                        ='cancelled' and refunds amount+charge
 *                        to the user's Matrix wallet via
 *                        WD-FREEZE-{id}. Distinct semantically
 *                        from a reject_withdrawal — this is a
 *                        chargeback-driven freeze, not an
 *                        operator-initiated reject — and the
 *                        WD-FREEZE-* reference makes the auditor
 *                        ledger trace the difference.
 *   - Dismiss           (state='dismissed')    — false positive
 *                        / duplicate notification.
 *
 * Why a separate admin page rather than a tab on the existing
 * Withdrawals page: /Notify events can attach to either a
 * deposit OR a withdrawal, plus the operator workflow is
 * fundamentally different (review-and-respond, not approve-or-
 * reject). Putting it on Withdrawals would either misclassify
 * deposit-side notifications or force a third tab on a page
 * that's already filter-heavy.
 *
 * Capability: manage_matrix_withdrawals — the same cap that
 * gates the Withdrawals page. An operator who can approve /
 * reject withdrawals can review notifications about them; a
 * support-tier operator who can read deposits but not act on
 * withdrawals can't freeze a withdrawal here either.
 *
 * The action handlers themselves live in class-matrix-admin.php
 * (the existing matrix_action AJAX dispatcher), keyed on
 * matrix_action='zebra_acknowledge_notification' /
 * 'zebra_dismiss_notification' / 'zebra_freeze_withdrawal'.
 *
 * @package MatrixMLM
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Zebra_Notifications {

    /**
     * Top-level entry point. Renders the listing with state
     * filter tabs + table.
     */
    public function render() {
        if (!current_user_can('manage_matrix_withdrawals')) {
            wp_die(__('You do not have permission to view Zebra notifications.', 'matrix-mlm'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'matrix_zebra_notifications';

        // Count per state for the subsubsub nav badges. One
        // grouped query — cheaper than five separate COUNTs and
        // simpler than a CASE-based pivot. Returns rows like
        // (state='received', total=12).
        $rows = $wpdb->get_results(
            "SELECT state, COUNT(*) AS total
               FROM $table
              GROUP BY state"
        );
        $totals = [
            'received'     => 0,
            'acknowledged' => 0,
            'actioned'     => 0,
            'dismissed'    => 0,
        ];
        foreach ((array) $rows as $r) {
            if (isset($totals[$r->state])) {
                $totals[$r->state] = (int) $r->total;
            }
        }
        $totals['all'] = array_sum($totals);

        // Active filter tab. Whitelist against the keys above so
        // an arbitrary ?status= value can't reach the WHERE clause.
        $allowed_states = ['all', 'received', 'acknowledged', 'actioned', 'dismissed'];
        $current = isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : 'received';
        if (!in_array($current, $allowed_states, true)) {
            $current = 'received';
        }

        // Optional ?highlight=N from the admin email link — used
        // to scroll the matching row into view client-side.
        $highlight_id = isset($_GET['highlight']) ? (int) $_GET['highlight'] : 0;

        // Listing query. Page size is fixed at 50 — high enough
        // that an operator rarely needs to paginate during a
        // single triage session, low enough that the page renders
        // quickly even on installs with thousands of historical
        // events.
        $where = '';
        $params = [];
        if ($current !== 'all') {
            $where    = 'WHERE state = %s';
            $params[] = $current;
        }
        $sql = "SELECT * FROM $table $where ORDER BY created_at DESC LIMIT 50";
        $notifications = empty($params)
            ? $wpdb->get_results($sql)
            : $wpdb->get_results($wpdb->prepare($sql, ...$params));

        $currency_symbol = (string) get_option('matrix_mlm_currency_symbol', '₦');
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php esc_html_e('Zebra Wallet Notifications', 'matrix-mlm'); ?></h1>
            <p class="description" style="max-width:760px;">
                <?php esc_html_e('Inbound Bibimoney /Notify events (dispute / fraud / chargeback). Each row is reviewed independently — Acknowledge if no action is needed, Freeze withdrawal to cancel a related pending payout and refund the user\'s Matrix wallet, or Dismiss for false positives. None of these actions move money out of the platform; Freeze refunds the Matrix wallet via WD-FREEZE-{id}.', 'matrix-mlm'); ?>
            </p>

            <ul class="subsubsub">
                <?php
                $tabs = [
                    'received'     => __('Received', 'matrix-mlm'),
                    'acknowledged' => __('Acknowledged', 'matrix-mlm'),
                    'actioned'     => __('Actioned', 'matrix-mlm'),
                    'dismissed'    => __('Dismissed', 'matrix-mlm'),
                    'all'          => __('All', 'matrix-mlm'),
                ];
                $i = 0;
                $count = count($tabs);
                foreach ($tabs as $key => $label):
                    $i++;
                    $sep = $i < $count ? ' |' : '';
                    $is_active = ($current === $key);
                    $url = add_query_arg([
                        'page'   => 'matrix-mlm-zebra-notifications',
                        'status' => $key,
                    ], admin_url('admin.php'));
                    ?>
                    <li>
                        <a href="<?php echo esc_url($url); ?>" class="<?php echo $is_active ? 'current' : ''; ?>">
                            <?php echo esc_html($label); ?>
                            <span class="count">(<?php echo (int) $totals[$key]; ?>)</span>
                        </a><?php echo esc_html($sep); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <br class="clear">

            <?php if (empty($notifications)): ?>
                <div class="notice notice-info inline" style="margin:12px 0;">
                    <p><?php esc_html_e('No notifications match this filter.', 'matrix-mlm'); ?></p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped" style="margin-top:12px;">
                    <thead>
                        <tr>
                            <th style="width:140px;"><?php esc_html_e('Received', 'matrix-mlm'); ?></th>
                            <th style="width:100px;"><?php esc_html_e('Severity', 'matrix-mlm'); ?></th>
                            <th style="width:130px;"><?php esc_html_e('Event', 'matrix-mlm'); ?></th>
                            <th><?php esc_html_e('Message', 'matrix-mlm'); ?></th>
                            <th style="width:160px;"><?php esc_html_e('Related', 'matrix-mlm'); ?></th>
                            <th style="width:120px;"><?php esc_html_e('Amount', 'matrix-mlm'); ?></th>
                            <th style="width:110px;"><?php esc_html_e('State', 'matrix-mlm'); ?></th>
                            <th style="width:280px;"><?php esc_html_e('Actions', 'matrix-mlm'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($notifications as $n):
                            $is_highlight = ((int) $n->id === $highlight_id);
                            $related_label = self::format_related_label($n);
                            $related_link  = self::related_admin_link($n);
                            ?>
                            <tr id="zebra-notif-<?php echo (int) $n->id; ?>"
                                class="<?php echo $is_highlight ? 'matrix-zebra-notif-highlight' : ''; ?>">
                                <td>
                                    <?php echo esc_html(date('M d, Y H:i', strtotime((string) $n->created_at))); ?><br>
                                    <small style="color:#6b7280;">#<?php echo (int) $n->id; ?></small>
                                </td>
                                <td>
                                    <span class="matrix-badge matrix-badge-severity-<?php echo esc_attr($n->severity); ?>">
                                        <?php echo esc_html(ucfirst((string) $n->severity)); ?>
                                    </span>
                                </td>
                                <td><code><?php echo esc_html((string) $n->event_type); ?></code></td>
                                <td>
                                    <?php echo esc_html((string) $n->message); ?>
                                    <details style="margin-top:6px;">
                                        <summary style="cursor:pointer;color:#4f46e5;font-size:12px;">
                                            <?php esc_html_e('Raw payload', 'matrix-mlm'); ?>
                                        </summary>
                                        <pre style="background:#f3f4f6;border:1px solid #e5e7eb;padding:8px;border-radius:4px;font-size:11px;line-height:1.45;max-height:240px;overflow:auto;margin-top:6px;"><?php
                                            echo esc_html(self::pretty_print_payload((string) $n->raw_payload));
                                        ?></pre>
                                    </details>
                                    <?php if (!empty($n->handler_note)): ?>
                                        <div class="matrix-zebra-notif-handler-note" style="margin-top:6px;font-size:12px;color:#374151;border-left:3px solid #4f46e5;padding-left:8px;">
                                            <?php echo esc_html((string) $n->handler_note); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($related_link): ?>
                                        <a href="<?php echo esc_url($related_link); ?>"><?php echo esc_html($related_label); ?></a>
                                    <?php else: ?>
                                        <?php echo esc_html($related_label); ?>
                                    <?php endif; ?>
                                    <?php if (!empty($n->vendor_reference)): ?>
                                        <br><small style="color:#6b7280;"><code><?php echo esc_html((string) $n->vendor_reference); ?></code></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    if ($n->amount !== null && $n->amount !== '') {
                                        $sym = ($n->currency === 'NGN' || $n->currency === '')
                                            ? $currency_symbol
                                            : ($n->currency . ' ');
                                        echo esc_html($sym . number_format((float) $n->amount, 2));
                                    } else {
                                        echo '<small style="color:#6b7280;">&mdash;</small>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <span class="matrix-badge matrix-badge-zebra-notif-<?php echo esc_attr($n->state); ?>">
                                        <?php echo esc_html(ucfirst((string) $n->state)); ?>
                                    </span>
                                    <?php if (!empty($n->handled_at)): ?>
                                        <br><small style="color:#6b7280;" title="<?php echo esc_attr(self::handler_login_for($n)); ?>">
                                            <?php echo esc_html(date('M d, H:i', strtotime((string) $n->handled_at))); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php self::render_action_buttons($n); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <style>
            .matrix-badge {
                display:inline-block;
                padding:3px 8px;
                border-radius:10px;
                font-size:11px;
                font-weight:600;
                line-height:1.2;
                text-transform:uppercase;
                letter-spacing:0.3px;
            }
            .matrix-badge-severity-info     { background:#eef2ff; color:#3730a3; }
            .matrix-badge-severity-warning  { background:#fef3c7; color:#92400e; }
            .matrix-badge-severity-critical { background:#fee2e2; color:#991b1b; }
            .matrix-badge-zebra-notif-received     { background:#fef3c7; color:#92400e; }
            .matrix-badge-zebra-notif-acknowledged { background:#dbeafe; color:#1e40af; }
            .matrix-badge-zebra-notif-actioned     { background:#d1fae5; color:#065f46; }
            .matrix-badge-zebra-notif-dismissed    { background:#e5e7eb; color:#374151; }
            .matrix-zebra-notif-highlight { box-shadow: inset 4px 0 0 #4f46e5; }
        </style>

        <script>
        // Auto-scroll to a row referenced by ?highlight=N from
        // the admin email link. Doing this client-side rather
        // than via PHP fragment so the URL stays clean and the
        // page renders correctly even when the highlighted row
        // is on the wrong filter tab.
        (function() {
            var hash = '<?php echo esc_js((string) $highlight_id); ?>';
            if (!hash || hash === '0') { return; }
            var row = document.getElementById('zebra-notif-' + hash);
            if (row && row.scrollIntoView) {
                row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        })();

        // matrixAdminAction lives in admin/js/matrix-admin.js;
        // these wrappers add the confirm() prompts + optional
        // operator note before forwarding. Same idiom the
        // Withdrawals admin page uses for Approve/Reject.
        function matrixZebraAcknowledgeNotification(id) {
            if (!confirm('<?php echo esc_js(__('Mark this notification as acknowledged? No money moves; this just clears the unread badge.', 'matrix-mlm')); ?>')) {
                return;
            }
            var note = prompt('<?php echo esc_js(__('Optional: add an internal note for the audit trail.', 'matrix-mlm')); ?>') || '';
            matrixAdminAction('zebra_acknowledge_notification', { id: id, note: note });
        }
        function matrixZebraDismissNotification(id) {
            var note = prompt('<?php echo esc_js(__('Reason for dismissing? (Required — false positives or duplicates should still leave a paper trail.)', 'matrix-mlm')); ?>');
            if (note === null) { return; }
            note = (note || '').trim();
            if (note === '') {
                alert('<?php echo esc_js(__('Reason is required.', 'matrix-mlm')); ?>');
                return;
            }
            matrixAdminAction('zebra_dismiss_notification', { id: id, note: note });
        }
        function matrixZebraFreezeWithdrawal(id, withdrawalId) {
            if (!confirm('<?php echo esc_js(__('Freeze the related withdrawal? This will set the row to status=cancelled and refund amount+charge to the user\'s Matrix wallet. The user is notified. This is irreversible.', 'matrix-mlm')); ?>')) {
                return;
            }
            var note = prompt('<?php echo esc_js(__('Reason for freeze? (Required — captured for the audit trail and shown to the user.)', 'matrix-mlm')); ?>');
            if (note === null) { return; }
            note = (note || '').trim();
            if (note === '') {
                alert('<?php echo esc_js(__('Reason is required.', 'matrix-mlm')); ?>');
                return;
            }
            matrixAdminAction('zebra_freeze_withdrawal', {
                id: id,
                withdrawal_id: withdrawalId,
                note: note,
            });
        }
        </script>
        <?php
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------*/

    /**
     * Render the per-row action buttons.
     *
     * Visibility rules:
     *
     *   - Acknowledge:       always available on rows in
     *                        state='received'.
     *   - Freeze withdrawal: available on rows in any
     *                        non-finalised state (received OR
     *                        acknowledged) AND when the related
     *                        row is a pending/processing
     *                        matrix_withdrawals (we re-check
     *                        the current status here so an
     *                        already-approved withdrawal can't
     *                        be frozen via a stale row).
     *   - Dismiss:           available on any non-finalised
     *                        state.
     *
     * Finalised rows (state in actioned/dismissed) show a
     * read-only summary of who handled them.
     */
    private static function render_action_buttons($n) {
        $finalised = in_array((string) $n->state, ['actioned', 'dismissed'], true);
        if ($finalised) {
            $login = self::handler_login_for($n);
            if ($login !== '') {
                printf(
                    '<small style="color:#6b7280;">%s</small>',
                    esc_html(sprintf(
                        /* translators: %s = handler login */
                        __('handled by %s', 'matrix-mlm'),
                        $login
                    ))
                );
            } else {
                echo '<small style="color:#6b7280;">' . esc_html__('handled', 'matrix-mlm') . '</small>';
            }
            return;
        }

        if ((string) $n->state === 'received') {
            ?>
            <button type="button" class="button button-small button-secondary"
                    onclick="matrixZebraAcknowledgeNotification(<?php echo (int) $n->id; ?>)">
                <?php esc_html_e('Acknowledge', 'matrix-mlm'); ?>
            </button>
            <?php
        }

        // Freeze button — only renders when the related row is
        // a still-pending/processing withdrawal that we can
        // actually freeze. Re-check the live status to defeat
        // a stale-row race where the withdrawal was approved
        // between IPN-receive and the operator's click.
        if ((string) $n->related_type === 'withdrawal' && !empty($n->related_id)) {
            global $wpdb;
            $live_status = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}matrix_withdrawals WHERE id = %d",
                (int) $n->related_id
            ));
            if (in_array($live_status, ['pending', 'processing'], true)) {
                ?>
                <button type="button" class="button button-small"
                        style="background:#dc2626;border-color:#dc2626;color:#fff;"
                        onclick="matrixZebraFreezeWithdrawal(<?php echo (int) $n->id; ?>, <?php echo (int) $n->related_id; ?>)">
                    <?php esc_html_e('Freeze withdrawal', 'matrix-mlm'); ?>
                </button>
                <?php
            }
        }

        ?>
        <button type="button" class="button button-small"
                onclick="matrixZebraDismissNotification(<?php echo (int) $n->id; ?>)">
            <?php esc_html_e('Dismiss', 'matrix-mlm'); ?>
        </button>
        <?php
    }

    /**
     * Human-readable label for the related deposit / withdrawal
     * column.
     */
    private static function format_related_label($n) {
        $type = (string) $n->related_type;
        $id   = (int) ($n->related_id ?? 0);
        if ($type === 'unknown' || $id <= 0) {
            return __('Unknown', 'matrix-mlm');
        }
        if ($type === 'deposit') {
            return sprintf(__('Deposit #%d', 'matrix-mlm'), $id);
        }
        if ($type === 'withdrawal') {
            return sprintf(__('Withdrawal #%d', 'matrix-mlm'), $id);
        }
        return __('Unknown', 'matrix-mlm');
    }

    /**
     * Build a deep link into the matching admin listing for the
     * related row. Returns '' for unknown rows.
     *
     * The listings (Deposits / Withdrawals) don't currently
     * support per-row anchor URLs, so the link drops the
     * operator on the page filtered to the relevant status.
     * Good enough for triage; a future per-row deep-link is a
     * one-line change here.
     */
    private static function related_admin_link($n) {
        $type = (string) $n->related_type;
        $id   = (int) ($n->related_id ?? 0);
        if ($id <= 0) {
            return '';
        }
        if ($type === 'deposit') {
            return admin_url('admin.php?page=matrix-mlm-deposits');
        }
        if ($type === 'withdrawal') {
            return admin_url('admin.php?page=matrix-mlm-withdrawals');
        }
        return '';
    }

    /**
     * Resolve the handler's WP login for display. Returns ''
     * when no handler is recorded yet.
     */
    private static function handler_login_for($n) {
        $uid = (int) ($n->handled_by_user_id ?? 0);
        if ($uid <= 0) {
            return '';
        }
        $user = get_userdata($uid);
        return $user && !empty($user->user_login) ? (string) $user->user_login : '';
    }

    /**
     * Pretty-print the raw_payload column for the inline
     * <details> reveal. Falls back to the raw value when the
     * column happens to hold a non-JSON string (legacy import,
     * filter override that wrote a different shape, etc.).
     */
    private static function pretty_print_payload($raw) {
        if (!is_string($raw) || $raw === '') {
            return '';
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $raw;
    }
}
