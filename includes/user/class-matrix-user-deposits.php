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

        return ['status' => 'pending', 'message' => __('Payment is being processed. Your wallet will be credited once confirmed.', 'matrix-mlm')];
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

        return ['status' => 'pending', 'message' => __('Payment is being processed. Your wallet will be credited once confirmed.', 'matrix-mlm')];
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
                <button type="submit" class="matrix-btn matrix-btn-primary matrix-btn-block"><?php _e('Proceed to Payment', 'matrix-mlm'); ?></button>
            </form>
        </div>
        <?php endif; ?>
        <?php
    }

    public function render_history($user_id) {
        global $wpdb;
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $deposits = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_deposits WHERE user_id = %d ORDER BY created_at DESC LIMIT 50",
            $user_id
        ));
        ?>
        <h2><?php _e('Deposit History', 'matrix-mlm'); ?></h2>
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
    }
}
