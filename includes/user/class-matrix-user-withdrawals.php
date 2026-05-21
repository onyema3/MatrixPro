<?php
/**
 * User Withdrawals
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_User_Withdrawals {

    public function render_form($user_id) {
        $wallet = new Matrix_MLM_Wallet();
        $balance = $wallet->get_balance($user_id);
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $min = get_option('matrix_mlm_min_withdraw', 1000);
        $max = get_option('matrix_mlm_max_withdraw', 1000000);
        $charge_type = get_option('matrix_mlm_withdraw_charge_type', 'percent');
        $charge_value = get_option('matrix_mlm_withdraw_charge', 5);
        ?>
        <h2><?php _e('Request Withdrawal', 'matrix-mlm'); ?></h2>
        <div class="matrix-info-box">
            <p><?php echo sprintf(__('Available Balance: %s%s', 'matrix-mlm'), $currency, number_format($balance, 2)); ?></p>
            <p><?php echo sprintf(__('Charge: %s', 'matrix-mlm'), $charge_type === 'percent' ? $charge_value . '%' : $currency . number_format($charge_value, 2)); ?></p>
            <p><?php echo sprintf(__('Limits: %s - %s', 'matrix-mlm'), $currency . number_format($min), $currency . number_format($max)); ?></p>
        </div>
        <div class="matrix-form-card">
            <form id="matrix-withdraw-form" class="matrix-form">
                <div class="matrix-form-group">
                    <label><?php _e('Amount', 'matrix-mlm'); ?> (<?php echo $currency; ?>)</label>
                    <input type="number" name="amount" min="<?php echo $min; ?>" max="<?php echo min($max, $balance); ?>" step="0.01" required>
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Withdrawal Method', 'matrix-mlm'); ?></label>
                    <select name="method" required>
                        <option value="bank_transfer"><?php _e('Bank Transfer', 'matrix-mlm'); ?></option>
                        <option value="mobile_money"><?php _e('Mobile Money', 'matrix-mlm'); ?></option>
                        <option value="crypto"><?php _e('Cryptocurrency', 'matrix-mlm'); ?></option>
                    </select>
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Account Details', 'matrix-mlm'); ?></label>
                    <textarea name="account_details" rows="4" required placeholder="<?php _e('Bank Name, Account Number, Account Name', 'matrix-mlm'); ?>"></textarea>
                </div>
                <button type="submit" class="matrix-btn matrix-btn-primary matrix-btn-block"><?php _e('Submit Withdrawal', 'matrix-mlm'); ?></button>
            </form>
        </div>
        <?php
    }

    public function render_history($user_id) {
        global $wpdb;
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $withdrawals = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_withdrawals WHERE user_id = %d ORDER BY created_at DESC LIMIT 50",
            $user_id
        ));
        ?>
        <h2><?php _e('Withdrawal History', 'matrix-mlm'); ?></h2>
        <table class="matrix-table">
            <thead><tr><th><?php _e('Date', 'matrix-mlm'); ?></th><th><?php _e('Method', 'matrix-mlm'); ?></th><th><?php _e('Amount', 'matrix-mlm'); ?></th><th><?php _e('Charge', 'matrix-mlm'); ?></th><th><?php _e('Net', 'matrix-mlm'); ?></th><th><?php _e('Status', 'matrix-mlm'); ?></th></tr></thead>
            <tbody>
                <?php foreach ($withdrawals as $w): ?>
                <tr>
                    <td><?php echo date('M d, Y H:i', strtotime($w->created_at)); ?></td>
                    <td><?php echo esc_html(ucfirst(str_replace('_', ' ', $w->method))); ?></td>
                    <td><?php echo $currency . number_format($w->amount, 2); ?></td>
                    <td><?php echo $currency . number_format($w->charge, 2); ?></td>
                    <td><?php echo $currency . number_format($w->net_amount, 2); ?></td>
                    <td><span class="matrix-badge matrix-badge-<?php echo $w->status; ?>"><?php echo ucfirst($w->status); ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
