<?php
/**
 * Admin Withdrawals Management
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Withdrawals {

    public function render() {
        global $wpdb;

        // Handle the "Create Test Remit" form post first so the
        // resulting row is included in the listing render below.
        // The form is gated on manage_matrix_withdrawals (the same
        // capability that gates the page registration); we re-
        // verify here as defense in depth.
        $this->maybe_handle_create_test_remit();

        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

        $where = "WHERE 1=1";
        $params = [];
        if ($status_filter) {
            $where .= " AND w.status = %s";
            $params[] = $status_filter;
        }
        $params[] = 50;
        $params[] = 0;

        $withdrawals = $wpdb->get_results($wpdb->prepare(
            "SELECT w.*, u.user_login, u.user_email FROM {$wpdb->prefix}matrix_withdrawals w 
             LEFT JOIN {$wpdb->users} u ON w.user_id = u.ID 
             $where ORDER BY w.created_at DESC LIMIT %d OFFSET %d",
            $params
        ));

        $totals = [
            'all'        => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_withdrawals"),
            'pending'    => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_withdrawals WHERE status = 'pending'"),
            // 'processing' is the in-flight lock state introduced
            // in 1.0.17 for the Zebra /Remit dispatch (see
            // class-matrix-zebra.php). Surfaced as its own filter
            // tab so an operator can spot rows that got stuck
            // mid-flight (e.g. a transient platform 5xx or a
            // crashed PHP worker between processing -> approved).
            'processing' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_withdrawals WHERE status = 'processing'"),
            'approved'   => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_withdrawals WHERE status = 'approved'"),
            'rejected'   => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_withdrawals WHERE status = 'rejected'"),
        ];

        $zebra_configured = false;
        if (class_exists('Matrix_MLM_Zebra')) {
            $zebra_configured = (new Matrix_MLM_Zebra())->is_configured();
        }
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php _e('Manage Withdrawals', 'matrix-mlm'); ?></h1>

            <ul class="subsubsub">
                <li><a href="<?php echo admin_url('admin.php?page=matrix-mlm-withdrawals'); ?>" class="<?php echo empty($status_filter) ? 'current' : ''; ?>">All (<?php echo $totals['all']; ?>)</a> |</li>
                <li><a href="<?php echo admin_url('admin.php?page=matrix-mlm-withdrawals&status=pending'); ?>" class="<?php echo $status_filter === 'pending' ? 'current' : ''; ?>">Pending (<?php echo $totals['pending']; ?>)</a> |</li>
                <li><a href="<?php echo admin_url('admin.php?page=matrix-mlm-withdrawals&status=processing'); ?>" class="<?php echo $status_filter === 'processing' ? 'current' : ''; ?>">Processing (<?php echo $totals['processing']; ?>)</a> |</li>
                <li><a href="<?php echo admin_url('admin.php?page=matrix-mlm-withdrawals&status=approved'); ?>" class="<?php echo $status_filter === 'approved' ? 'current' : ''; ?>">Approved (<?php echo $totals['approved']; ?>)</a> |</li>
                <li><a href="<?php echo admin_url('admin.php?page=matrix-mlm-withdrawals&status=rejected'); ?>" class="<?php echo $status_filter === 'rejected' ? 'current' : ''; ?>">Rejected (<?php echo $totals['rejected']; ?>)</a></li>
            </ul>

            <?php $this->render_create_test_remit_panel($zebra_configured); ?>

            <table class="wp-list-table widefat fixed striped" style="margin-top: 30px;">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><?php _e('User', 'matrix-mlm'); ?></th>
                        <th><?php _e('Method', 'matrix-mlm'); ?></th>
                        <th><?php _e('Gateway', 'matrix-mlm'); ?></th>
                        <th><?php _e('Amount', 'matrix-mlm'); ?></th>
                        <th><?php _e('Charge', 'matrix-mlm'); ?></th>
                        <th><?php _e('Net', 'matrix-mlm'); ?></th>
                        <th><?php _e('Account Details', 'matrix-mlm'); ?></th>
                        <th><?php _e('Reference', 'matrix-mlm'); ?></th>
                        <th><?php _e('Status', 'matrix-mlm'); ?></th>
                        <th><?php _e('Date', 'matrix-mlm'); ?></th>
                        <th><?php _e('Actions', 'matrix-mlm'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($withdrawals as $w): ?>
                    <?php
                        // Gateway badge: NULL gateway on legacy
                        // rows renders an em-dash so the operator
                        // can tell at a glance which rows route
                        // through the Zebra /Remit dispatch and
                        // which fall through to the legacy
                        // Fintava-credit branch.
                        $gw_label = $w->gateway ? ucfirst($w->gateway) : '—';
                        // Status label humanised so 'processing'
                        // renders cleanly. Mirrors the deposits
                        // page's pending_capture treatment.
                        $status_label = ucwords(str_replace('_', ' ', (string) $w->status));
                    ?>
                    <tr>
                        <td><?php echo $w->id; ?></td>
                        <td><?php echo esc_html($w->user_login); ?><br><small><?php echo esc_html($w->user_email); ?></small></td>
                        <td><?php echo esc_html(ucfirst($w->method)); ?></td>
                        <td><span class="matrix-badge matrix-badge-gateway-<?php echo esc_attr($w->gateway ?: 'legacy'); ?>"><?php echo esc_html($gw_label); ?></span></td>
                        <td><?php echo $currency . number_format($w->amount, 2); ?></td>
                        <td><?php echo $currency . number_format($w->charge, 2); ?></td>
                        <td><?php echo $currency . number_format($w->net_amount, 2); ?></td>
                        <td><small><?php echo esc_html($w->account_details); ?></small></td>
                        <td>
                            <?php if (!empty($w->transaction_id)): ?>
                                <code style="font-size:11px;"><?php echo esc_html($w->transaction_id); ?></code>
                            <?php else: ?>
                                <span style="color:#999;">—</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="matrix-badge matrix-badge-<?php echo esc_attr($w->status); ?>"><?php echo esc_html($status_label); ?></span></td>
                        <td><?php echo date('M d, Y H:i', strtotime($w->created_at)); ?></td>
                        <td>
                            <?php if ($w->status === 'pending'): ?>
                                <button class="button button-small button-primary" onclick="matrixAdminAction('approve_withdrawal', {id: <?php echo $w->id; ?>})"><?php _e('Approve', 'matrix-mlm'); ?></button>
                                <button class="button button-small" onclick="matrixRejectWithdrawal(<?php echo $w->id; ?>)"><?php _e('Reject', 'matrix-mlm'); ?></button>
                            <?php elseif ($w->status === 'processing'): ?>
                                <?php /* Processing rows are mid-flight Zebra /Remit calls
                                       that haven't finalised yet. The reject button is
                                       the operator's escape hatch when the platform
                                       returned a transient failure that bumped the row
                                       back to pending was missed (or, rarely, a worker
                                       crashed between the lock acquire and the post()).
                                       Reject from processing -> rejected refunds the
                                       Matrix wallet just like rejecting from pending. */ ?>
                                <button class="button button-small" onclick="matrixRejectWithdrawal(<?php echo $w->id; ?>)" title="<?php esc_attr_e('Force-reject a stuck Remit; refunds the Matrix wallet.', 'matrix-mlm'); ?>"><?php _e('Force Reject', 'matrix-mlm'); ?></button>
                            <?php endif; ?>
                            <?php if (!empty($w->gateway_response)): ?>
                                <button class="button button-small" type="button" onclick="this.nextElementSibling.style.display = (this.nextElementSibling.style.display === 'block') ? 'none' : 'block';"><?php _e('Audit', 'matrix-mlm'); ?></button>
                                <pre style="display:none;white-space:pre-wrap;background:#f6f7f7;border:1px solid #c3c4c7;padding:6px;margin-top:4px;font-size:11px;max-width:340px;overflow:auto;"><?php echo esc_html(self::format_audit_stash($w->gateway_response)); ?></pre>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render the "Create Test Remit" admin tool.
     *
     * This is a thin operator-facing form for inserting a pending
     * matrix_withdrawals row with gateway='zebra' so /Remit can be
     * exercised end-to-end without the user-facing payout form
     * (which is reserved for a separate follow-up that lands
     * /Dispense + /Remit through a unified user UI).
     *
     * The form does NOT debit any wallet. It's purely an admin
     * convenience for staging a test row; the operator approves
     * it on the very next page render to dispatch /Remit. A
     * production install would not normally use this — the user-
     * facing form follow-up will create rows from the member's
     * Matrix wallet balance with the proper debit + PIN gate.
     */
    private function render_create_test_remit_panel($zebra_configured) {
        if (!current_user_can('manage_matrix_withdrawals')) {
            return;
        }
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        ?>
        <div class="matrix-admin-card" style="margin-top:20px;padding:14px;border:1px solid #c3c4c7;background:#fff;">
            <h2 style="margin-top:0;"><?php _e('Test Zebra Wallet /Remit', 'matrix-mlm'); ?></h2>
            <p>
                <?php _e('Create a pending matrix_withdrawals row tagged for the Zebra Wallet (Bibimoney) /Remit endpoint. Approving the row dispatches the vendor->customer payout. This tool does not debit any wallet — production rows will be created from the user-facing payout form in a follow-up release.', 'matrix-mlm'); ?>
            </p>
            <?php if (!$zebra_configured): ?>
                <p style="color:#92400e;">
                    <?php
                    printf(
                        /* translators: %s = HTML link to admin Gateways page */
                        wp_kses(
                            __('Zebra Wallet credentials are not configured. Set them on the %s page first.', 'matrix-mlm'),
                            ['a' => ['href' => true]]
                        ),
                        '<a href="' . esc_url(admin_url('admin.php?page=matrix-mlm-gateways')) . '">' . esc_html__('Gateways', 'matrix-mlm') . '</a>'
                    );
                    ?>
                </p>
            <?php endif; ?>
            <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                <?php wp_nonce_field('matrix_create_test_remit', 'matrix_create_test_remit_nonce'); ?>
                <input type="hidden" name="matrix_action" value="create_test_remit">
                <div>
                    <label style="display:block;font-size:12px;"><?php _e('User ID', 'matrix-mlm'); ?></label>
                    <input type="number" name="user_id" min="1" step="1" required style="width:90px;">
                </div>
                <div>
                    <label style="display:block;font-size:12px;"><?php _e('Net amount', 'matrix-mlm'); ?> (<?php echo esc_html($currency); ?>)</label>
                    <input type="number" name="amount" min="0.01" step="0.01" required style="width:120px;">
                </div>
                <div>
                    <label style="display:block;font-size:12px;"><?php _e('Customer Zebra Wallet (IWAN / MSISDN)', 'matrix-mlm'); ?></label>
                    <input type="text" name="account_details" placeholder="BIBI500000054** or +234XXXXXXXXXX" required style="width:260px;">
                </div>
                <div>
                    <button type="submit" class="button button-primary" <?php echo $zebra_configured ? '' : 'disabled'; ?>><?php _e('Create Pending Row', 'matrix-mlm'); ?></button>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Handle the "Create Test Remit" form post. Inserts a single
     * pending row with gateway='zebra' and method='zebra'. No
     * wallet debit — see render_create_test_remit_panel() for
     * rationale.
     */
    private function maybe_handle_create_test_remit() {
        if (empty($_POST['matrix_action']) || $_POST['matrix_action'] !== 'create_test_remit') {
            return;
        }
        if (!current_user_can('manage_matrix_withdrawals')) {
            wp_die(__('Insufficient permissions.', 'matrix-mlm'));
        }
        check_admin_referer('matrix_create_test_remit', 'matrix_create_test_remit_nonce');

        $user_id         = (int) ($_POST['user_id'] ?? 0);
        $amount          = (float) ($_POST['amount'] ?? 0);
        $account_details = isset($_POST['account_details']) ? sanitize_text_field((string) $_POST['account_details']) : '';

        if ($user_id <= 0 || $amount <= 0 || $account_details === '') {
            add_settings_error(
                'matrix_mlm_withdrawals',
                'invalid_test_remit',
                __('User ID, amount, and Zebra Wallet identifier are all required.', 'matrix-mlm'),
                'error'
            );
            return;
        }

        global $wpdb;
        $currency = strtoupper((string) get_option('matrix_mlm_currency', 'NGN'));

        $wpdb->insert(
            $wpdb->prefix . 'matrix_withdrawals',
            [
                'user_id'         => $user_id,
                'method'          => 'zebra',
                'gateway'         => 'zebra',
                'amount'          => $amount,
                'charge'          => 0,
                'net_amount'      => $amount,
                'currency'        => $currency,
                'account_details' => $account_details,
                'status'          => 'pending',
            ],
            ['%d', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s']
        );

        if ($wpdb->insert_id) {
            add_settings_error(
                'matrix_mlm_withdrawals',
                'test_remit_created',
                sprintf(
                    /* translators: %d = withdrawal id */
                    __('Test Remit row created (id #%d). Click Approve below to dispatch /Remit.', 'matrix-mlm'),
                    (int) $wpdb->insert_id
                ),
                'success'
            );
        } else {
            add_settings_error(
                'matrix_mlm_withdrawals',
                'test_remit_failed',
                __('Could not create the test row. Check error_log for the underlying DB message.', 'matrix-mlm'),
                'error'
            );
        }
        settings_errors('matrix_mlm_withdrawals');
    }

    /**
     * Pretty-print a gateway_response JSON envelope for the audit
     * <pre> block. Falls back to the raw value if the column
     * happens to hold a non-JSON string (legacy import data,
     * etc.).
     */
    private static function format_audit_stash($raw) {
        if (!is_string($raw) || $raw === '') {
            return '';
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return (string) $raw;
    }
}
