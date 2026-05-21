<?php
/**
 * User Balance Transfer
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_User_Transfer {

    public function render($user_id) {
        global $wpdb;
        $wallet = new Matrix_MLM_Wallet();
        $balance = $wallet->get_balance($user_id);
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $min = get_option('matrix_mlm_min_transfer', 500);
        $charge_type = get_option('matrix_mlm_transfer_charge_type', 'fixed');
        $charge_value = get_option('matrix_mlm_transfer_charge', 100);

        $transfers = $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, 
                    u1.user_login as from_username, 
                    u2.user_login as to_username 
             FROM {$wpdb->prefix}matrix_transfers t 
             LEFT JOIN {$wpdb->users} u1 ON t.from_user_id = u1.ID 
             LEFT JOIN {$wpdb->users} u2 ON t.to_user_id = u2.ID 
             WHERE t.from_user_id = %d OR t.to_user_id = %d 
             ORDER BY t.created_at DESC LIMIT 20",
            $user_id, $user_id
        ));
        ?>
        <h2><?php _e('Balance Transfer', 'matrix-mlm'); ?></h2>
        <div class="matrix-info-box">
            <p><?php echo sprintf(__('Available Balance: %s%s', 'matrix-mlm'), $currency, number_format($balance, 2)); ?></p>
            <p><?php echo sprintf(__('Minimum Transfer: %s%s', 'matrix-mlm'), $currency, number_format($min)); ?></p>
            <p><?php echo sprintf(__('Transfer Charge: %s', 'matrix-mlm'), $charge_type === 'percent' ? $charge_value . '%' : $currency . number_format($charge_value, 2)); ?></p>
        </div>
        <div class="matrix-form-card">
            <form id="matrix-transfer-form" class="matrix-form">
                <div class="matrix-form-group">
                    <label><?php _e('Recipient Username', 'matrix-mlm'); ?></label>
                    <input type="text" name="recipient" required placeholder="<?php _e('Enter username', 'matrix-mlm'); ?>">
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Amount', 'matrix-mlm'); ?> (<?php echo $currency; ?>)</label>
                    <input type="number" name="amount" min="<?php echo $min; ?>" step="0.01" required>
                </div>
                <button type="submit" class="matrix-btn matrix-btn-primary"><?php _e('Transfer', 'matrix-mlm'); ?></button>
            </form>
        </div>

        <h3><?php _e('Transfer History', 'matrix-mlm'); ?></h3>
        <table class="matrix-table">
            <thead><tr><th><?php _e('Date', 'matrix-mlm'); ?></th><th><?php _e('Type', 'matrix-mlm'); ?></th><th><?php _e('User', 'matrix-mlm'); ?></th><th><?php _e('Amount', 'matrix-mlm'); ?></th><th><?php _e('Charge', 'matrix-mlm'); ?></th></tr></thead>
            <tbody>
                <?php foreach ($transfers as $t): 
                    $is_sender = ($t->from_user_id == $user_id);
                ?>
                <tr>
                    <td><?php echo date('M d, Y H:i', strtotime($t->created_at)); ?></td>
                    <td><?php echo $is_sender ? '<span class="text-danger">Sent</span>' : '<span class="text-success">Received</span>'; ?></td>
                    <td><?php echo esc_html($is_sender ? $t->to_username : $t->from_username); ?></td>
                    <td><?php echo $currency . number_format($t->amount, 2); ?></td>
                    <td><?php echo $is_sender ? $currency . number_format($t->charge, 2) : '-'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
