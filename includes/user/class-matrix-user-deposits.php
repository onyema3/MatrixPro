<?php
/**
 * User Deposits
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_User_Deposits {

    /**
     * Handle payment verification when user returns from gateway
     * Called on page load when ?status=verify is present
     */
    public function maybe_verify_payment() {
        if (!isset($_GET['status']) || $_GET['status'] !== 'verify') {
            return null;
        }

        $gateway = sanitize_text_field($_GET['gateway'] ?? '');
        $result = null;

        switch ($gateway) {
            case 'paystack':
                $reference = sanitize_text_field($_GET['reference'] ?? '');
                if (!empty($reference)) {
                    $result = $this->verify_paystack_payment($reference);
                }
                break;

            case 'flutterwave':
                $tx_ref = sanitize_text_field($_GET['tx_ref'] ?? '');
                $status = sanitize_text_field($_GET['status'] ?? '');
                $transaction_id = sanitize_text_field($_GET['transaction_id'] ?? '');
                if (!empty($tx_ref)) {
                    $result = $this->verify_flutterwave_payment($tx_ref, $transaction_id);
                }
                break;

            case 'zebra':
                // Zebra Wallet has no redirect-and-return flow -
                // step 2 of the deposit happens via AJAX while the
                // user is still on the dashboard. This branch
                // exists for the case where the user closes the
                // OTP dialog before the payment is confirmed and
                // later revisits the dashboard with a manually-
                // crafted ?status=verify URL, OR for an operator
                // who wants to wire a deep link of that shape from
                // a notification email. The reference is the
                // VendorReference we stashed in transaction_id at
                // step 1.
                $reference = sanitize_text_field($_GET['reference'] ?? '');
                if (!empty($reference)) {
                    $result = $this->verify_zebra_payment($reference);
                }
                break;
        }

        return $result;
    }

    /**
     * Verify Paystack payment on user return
     */
    private function verify_paystack_payment($reference) {
        global $wpdb;

        // Check if already completed
        $deposit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_deposits WHERE transaction_id = %s",
            $reference
        ));

        if (!$deposit) {
            return ['status' => 'error', 'message' => __('Deposit not found', 'matrix-mlm')];
        }

        if ($deposit->status === 'completed') {
            return ['status' => 'success', 'message' => __('Payment already confirmed! Your wallet has been credited.', 'matrix-mlm')];
        }

        // Verify with Paystack API
        $paystack = new Matrix_MLM_Paystack();
        $verify_result = $paystack->verify_payment($reference);

        if ($verify_result instanceof WP_REST_Response) {
            $response_data = $verify_result->get_data();
            if (isset($response_data['status']) && $response_data['status'] === 'success') {
                return ['status' => 'success', 'message' => __('Payment verified successfully! Your wallet has been credited.', 'matrix-mlm')];
            }
        }

        return ['status' => 'pending', 'message' => __('Payment confirmation is finalising. Your wallet will be credited in a few seconds — refresh this page to see the update.', 'matrix-mlm')];
    }

    /**
     * Verify Flutterwave payment on user return
     */
    private function verify_flutterwave_payment($tx_ref, $transaction_id = '') {
        global $wpdb;

        // Check if already completed
        $deposit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_deposits WHERE transaction_id = %s",
            $tx_ref
        ));

        if (!$deposit) {
            return ['status' => 'error', 'message' => __('Deposit not found', 'matrix-mlm')];
        }

        if ($deposit->status === 'completed') {
            return ['status' => 'success', 'message' => __('Payment already confirmed! Your wallet has been credited.', 'matrix-mlm')];
        }

        // Verify with Flutterwave API
        $flutterwave = new Matrix_MLM_Flutterwave();
        $verify_result = $flutterwave->verify_payment($tx_ref);

        if ($verify_result instanceof WP_REST_Response) {
            $response_data = $verify_result->get_data();
            if (isset($response_data['status']) && $response_data['status'] === 'success') {
                return ['status' => 'success', 'message' => __('Payment verified successfully! Your wallet has been credited.', 'matrix-mlm')];
            }
        }

        return ['status' => 'pending', 'message' => __('Payment confirmation is finalising. Your wallet will be credited in a few seconds — refresh this page to see the update.', 'matrix-mlm')];
    }

    /**
     * Verify a Zebra Wallet payment on user return.
     *
     * Unlike Paystack and Flutterwave, Zebra has no redirect-and-
     * return checkout: step 2 of the deposit completes inline via
     * AJAX while the user is still on the dashboard, and the IPN
     * lands a few seconds later for asynchronous confirmation.
     * This helper is a polling/recovery path for the corner case
     * where the user dismisses the OTP modal mid-flow OR the
     * operator wires a deep link of the shape
     * `?tab=deposits&status=verify&gateway=zebra&reference=<vref>`
     * into a notification email. It only reads local state - the
     * Zebra gateway class doesn't call out to the platform for
     * verify because Bibimoney does not expose a transaction-
     * lookup endpoint in the spec. The IPN is the authoritative
     * completion signal; this method just surfaces whichever state
     * the deposit row currently sits in to the dashboard banner.
     *
     * Reference here is the VendorReference (e.g. MTX-ZBR-42-1700000000)
     * that Matrix_MLM_Zebra::initialize_payment() stashed into the
     * deposit's transaction_id column at step 1. The gateway
     * class's verify_payment() returns a WP_REST_Response so we
     * can reuse it without reimplementing the deposit-row lookup.
     */
    private function verify_zebra_payment($reference) {
        global $wpdb;

        $deposit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_deposits WHERE transaction_id = %s",
            $reference
        ));

        if (!$deposit) {
            return ['status' => 'error', 'message' => __('Deposit not found', 'matrix-mlm')];
        }

        if ($deposit->status === 'completed') {
            return ['status' => 'success', 'message' => __('Payment already confirmed! Your wallet has been credited.', 'matrix-mlm')];
        }

        $zebra = new Matrix_MLM_Zebra();
        $verify_result = $zebra->verify_payment($reference);

        if ($verify_result instanceof WP_REST_Response) {
            $response_data = $verify_result->get_data();
            $status = isset($response_data['status']) ? (string) $response_data['status'] : '';
            if ($status === 'success') {
                return ['status' => 'success', 'message' => __('Payment confirmed! Your wallet has been credited.', 'matrix-mlm')];
            }
            if ($status === 'failed') {
                return ['status' => 'error', 'message' => (string) ($response_data['message'] ?? __('Payment was not successful.', 'matrix-mlm'))];
            }
        }

        return ['status' => 'pending', 'message' => __('Payment is still being confirmed. Refresh this page in a few seconds to see the update.', 'matrix-mlm')];
    }

    public function render_deposit_form($user_id) {
        global $wpdb;
        $gateways = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}matrix_gateways WHERE status = 1");
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $min = get_option('matrix_mlm_min_deposit', 1000);
        $max = get_option('matrix_mlm_max_deposit', 5000000);
        ?>
        <h2><?php _e('Make a Deposit', 'matrix-mlm'); ?></h2>
        <?php if (empty($gateways)): ?>
        <div class="matrix-alert matrix-alert-info">
            <?php _e('No payment gateways are currently available. Please contact the administrator.', 'matrix-mlm'); ?>
        </div>
        <?php else: ?>
        <div class="matrix-form-card">
            <form id="matrix-deposit-form" class="matrix-form">
                <div class="matrix-form-group">
                    <label><?php _e('Amount', 'matrix-mlm'); ?> (<?php echo $currency; ?>)</label>
                    <input type="number" name="amount" min="<?php echo $min; ?>" max="<?php echo $max; ?>" step="0.01" required placeholder="<?php echo sprintf(__('Min: %s, Max: %s', 'matrix-mlm'), number_format($min), number_format($max)); ?>">
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Payment Gateway', 'matrix-mlm'); ?></label>
                    <div class="matrix-gateway-options">
                        <?php foreach ($gateways as $gw): ?>
                        <label class="matrix-gateway-option">
                            <input type="radio" name="gateway" value="<?php echo esc_attr($gw->slug); ?>" required>
                            <span class="gateway-name"><?php echo esc_html($gw->name); ?></span>
                            <?php if ($gw->fixed_charge > 0 || $gw->percent_charge > 0): ?>
                            <small class="gateway-charge"><?php echo sprintf(__('Charge: %s + %s%%', 'matrix-mlm'), $currency . number_format($gw->fixed_charge, 2), $gw->percent_charge); ?></small>
                            <?php endif; ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php
                // Zebra Wallet (Bibimoney) - direct-debit-with-OTP
                // flow needs the customer's wallet identifier
                // (IWAN, local reference, or MSISDN) before the
                // first /PaymentAuth call. The fieldset is hidden
                // by default; the JS in matrix-public.js shows it
                // when the user picks the Zebra gateway radio.
                // It's only rendered when the slug is actually
                // installed so existing deposit pages don't get an
                // empty Zebra fieldset.
                $has_zebra = false;
                foreach ($gateways as $gw_check) {
                    if ($gw_check->slug === 'zebra') {
                        $has_zebra = true;
                        break;
                    }
                }
                if ($has_zebra):
                ?>
                <div class="matrix-form-group matrix-zebra-fields" style="display:none;">
                    <label for="matrix-zebra-wallet-account"><?php _e('Zebra Wallet Number / IWAN / Mobile', 'matrix-mlm'); ?></label>
                    <input type="text" id="matrix-zebra-wallet-account" name="wallet_account" autocomplete="off" placeholder="<?php esc_attr_e('e.g. BIBI50000005413 or +234XXXXXXXXXX', 'matrix-mlm'); ?>">
                    <small class="matrix-form-hint"><?php _e('An OTP will be sent to the phone number registered on this Zebra Wallet to authorise the payment.', 'matrix-mlm'); ?></small>
                </div>
                <?php endif; ?>
                <button type="submit" class="matrix-btn matrix-btn-primary matrix-btn-block"><?php _e('Proceed to Payment', 'matrix-mlm'); ?></button>
            </form>
        </div>

        <?php if ($has_zebra): ?>
        <!--
            Zebra Wallet OTP step-2 dialog. Hidden by default;
            matrix-public.js shows it after a successful step 1
            response with requires_otp=true. It carries the
            deposit_id and psp_reference as data attributes so the
            submit posts step 2 with the same identifiers the
            server stashed during step 1.
        -->
        <div class="matrix-zebra-otp-dialog" style="display:none;">
            <div class="matrix-form-card">
                <h3><?php _e('Enter Zebra Wallet OTP', 'matrix-mlm'); ?></h3>
                <p class="matrix-zebra-otp-message"><?php _e('An OTP has been sent to the phone number registered on the wallet. Enter it below to complete the payment.', 'matrix-mlm'); ?></p>
                <form id="matrix-zebra-otp-form" class="matrix-form" data-deposit-id="" data-psp-reference="">
                    <div class="matrix-form-group">
                        <label for="matrix-zebra-otp-input"><?php _e('OTP Code', 'matrix-mlm'); ?></label>
                        <input type="text" id="matrix-zebra-otp-input" name="otp" inputmode="numeric" pattern="[0-9A-Za-z]+" autocomplete="one-time-code" required>
                    </div>
                    <button type="submit" class="matrix-btn matrix-btn-primary matrix-btn-block"><?php _e('Confirm Payment', 'matrix-mlm'); ?></button>
                    <button type="button" class="matrix-btn matrix-btn-link matrix-zebra-otp-cancel" style="display:block;margin:8px auto 0;background:none;border:0;color:#888;cursor:pointer;"><?php _e('Cancel', 'matrix-mlm'); ?></button>
                </form>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <?php
    }

    public function render_history($user_id) {
        global $wpdb;
        $currency = get_option('matrix_mlm_currency_symbol', '₦');

        // Pagination chrome — 10 rows per page across all paginated
        // transaction tables on the dashboard for consistency. The
        // page param is namespaced (`dep_page` rather than `page` /
        // `p`) so it doesn't collide with WordPress's own `page`
        // query var on the dashboard tab router or with any other
        // paginated table elsewhere on the site.
        $per_page = 10;
        $total_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}matrix_deposits WHERE user_id = %d",
            $user_id
        ));
        $total_pages = $total_count > 0 ? (int) ceil($total_count / $per_page) : 1;

        $current_page = isset($_GET['dep_page']) ? (int) $_GET['dep_page'] : 1;
        if ($current_page < 1)            { $current_page = 1; }
        if ($current_page > $total_pages) { $current_page = $total_pages; }
        $offset = ($current_page - 1) * $per_page;

        $deposits = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_deposits WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $user_id, $per_page, $offset
        ));
        ?>
        <h2 id="matrix-deposit-history"><?php _e('Deposit History', 'matrix-mlm'); ?></h2>
        <?php if (empty($deposits)): ?>
            <div class="matrix-info-box">
                <p><?php esc_html_e('No deposits yet.', 'matrix-mlm'); ?></p>
            </div>
        <?php else: ?>
        <table class="matrix-table">
            <thead><tr><th><?php _e('Date', 'matrix-mlm'); ?></th><th><?php _e('Gateway', 'matrix-mlm'); ?></th><th><?php _e('Amount', 'matrix-mlm'); ?></th><th><?php _e('Charge', 'matrix-mlm'); ?></th><th><?php _e('Net', 'matrix-mlm'); ?></th><th><?php _e('Status', 'matrix-mlm'); ?></th></tr></thead>
            <tbody>
                <?php foreach ($deposits as $d): ?>
                <tr>
                    <td><?php echo date('M d, Y H:i', strtotime($d->created_at)); ?></td>
                    <td><?php echo esc_html(ucfirst($d->gateway)); ?></td>
                    <td><?php echo $currency . number_format($d->amount, 2); ?></td>
                    <td><?php echo $currency . number_format($d->charge, 2); ?></td>
                    <td><?php echo $currency . number_format($d->net_amount, 2); ?></td>
                    <td><span class="matrix-badge matrix-badge-<?php echo $d->status; ?>"><?php echo ucfirst($d->status); ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        // Pagination chrome — only when there's more than one
        // page, so a small ledger doesn't carry visual noise.
        // Anchored to #matrix-deposit-history so prev/next jumps
        // back to the heading rather than the page top.
        $this->render_pagination_nav($current_page, $total_pages, 'dep_page', 'matrix-deposit-history');
        ?>
        <?php endif; ?>

        <style>
        /* Pagination chrome — same visual contract as the Wallet
           page's Transaction History. Defined inline rather than
           in a shared stylesheet so each transaction page stays
           self-contained and editable without a CSS rebuild. */
        .matrix-pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-top: 16px;
            padding: 12px 0 4px;
            font-size: 14px;
            color: #4b5563;
        }
        .matrix-page-btn {
            display: inline-flex;
            align-items: center;
            padding: 8px 14px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            background: #fff;
            color: #1f2937;
            text-decoration: none;
            font-weight: 500;
            transition: background .15s ease, border-color .15s ease;
        }
        .matrix-page-btn:hover {
            background: #eef2ff;
            border-color: #4f46e5;
            color: #4f46e5;
        }
        .matrix-page-btn-disabled,
        .matrix-page-btn-disabled:hover {
            color: #9ca3af;
            background: #f9fafb;
            border-color: #e5e7eb;
            cursor: not-allowed;
        }
        .matrix-page-info {
            font-variant-numeric: tabular-nums;
            color: #6b7280;
        }
        </style>
        <?php
    }

    /**
     * Render Previous / Next pagination links plus a "Page X of Y"
     * indicator. Same contract as the helper on Matrix_MLM_User_Wallet
     * — see that class's render_pagination_nav() for the full
     * rationale. The implementation is duplicated here (rather than
     * extracted to a shared trait) because each user-facing page
     * class is self-contained, and a 25-line helper isn't worth
     * threading through a separate file.
     *
     * @param int    $current_page  1-indexed.
     * @param int    $total_pages   >= 1.
     * @param string $param_name    URL query var to read/write.
     * @param string $section_id    HTML id (no leading #) to anchor
     *                              the prev/next links to.
     */
    private function render_pagination_nav($current_page, $total_pages, $param_name, $section_id = '') {
        if ($total_pages <= 1) {
            return;
        }

        $base_url = remove_query_arg($param_name);
        $fragment = $section_id !== '' ? '#' . $section_id : '';

        $prev_url = $current_page > 1
            ? esc_url(add_query_arg($param_name, $current_page - 1, $base_url) . $fragment)
            : '';
        $next_url = $current_page < $total_pages
            ? esc_url(add_query_arg($param_name, $current_page + 1, $base_url) . $fragment)
            : '';
        ?>
        <nav class="matrix-pagination" aria-label="<?php esc_attr_e('Deposit history pagination', 'matrix-mlm'); ?>">
            <?php if ($prev_url !== ''): ?>
                <a class="matrix-page-btn" href="<?php echo $prev_url; ?>" rel="prev">&laquo; <?php esc_html_e('Previous', 'matrix-mlm'); ?></a>
            <?php else: ?>
                <span class="matrix-page-btn matrix-page-btn-disabled" aria-disabled="true">&laquo; <?php esc_html_e('Previous', 'matrix-mlm'); ?></span>
            <?php endif; ?>

            <span class="matrix-page-info">
                <?php
                printf(
                    /* translators: 1: current page number, 2: total page count */
                    esc_html__('Page %1$d of %2$d', 'matrix-mlm'),
                    (int) $current_page,
                    (int) $total_pages
                );
                ?>
            </span>

            <?php if ($next_url !== ''): ?>
                <a class="matrix-page-btn" href="<?php echo $next_url; ?>" rel="next"><?php esc_html_e('Next', 'matrix-mlm'); ?> &raquo;</a>
            <?php else: ?>
                <span class="matrix-page-btn matrix-page-btn-disabled" aria-disabled="true"><?php esc_html_e('Next', 'matrix-mlm'); ?> &raquo;</span>
            <?php endif; ?>
        </nav>
        <?php
    }
}
