<?php
/**
 * User E-Pin Recharge
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_User_Epin {

    public function render($user_id) {
        $epin = new Matrix_MLM_Epin();
        $recharge_logs = $epin->get_recharge_logs($user_id, 20);
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        ?>
        <h2><?php _e('E-Pin Recharge', 'matrix-mlm'); ?></h2>
        <div class="matrix-form-card">
            <form id="matrix-epin-form" class="matrix-form">
                <div class="matrix-form-group">
                    <label><?php _e('Enter E-Pin Code', 'matrix-mlm'); ?></label>
                    <input type="text" name="pin_code" placeholder="EP-XXXXXXXXXXXX" required style="text-transform: uppercase;">
                </div>
                <button type="submit" class="matrix-btn matrix-btn-primary"><?php _e('Redeem Pin', 'matrix-mlm'); ?></button>
            </form>
        </div>

        <h3><?php _e('Recharge History', 'matrix-mlm'); ?></h3>
        <table class="matrix-table">
            <thead><tr><th><?php _e('Date', 'matrix-mlm'); ?></th><th><?php _e('Pin', 'matrix-mlm'); ?></th><th><?php _e('Plan', 'matrix-mlm'); ?></th><th><?php _e('Amount', 'matrix-mlm'); ?></th></tr></thead>
            <tbody>
                <?php foreach ($recharge_logs as $log): ?>
                <tr>
                    <td><?php echo date('M d, Y H:i', strtotime($log->used_at)); ?></td>
                    <td><code><?php echo esc_html($log->pin_code); ?></code></td>
                    <td><?php echo esc_html($log->plan_name); ?></td>
                    <td class="text-success"><?php echo $currency . number_format($log->amount, 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
