<?php
/**
 * User Referrals
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_User_Referrals {

    public function render($user_id) {
        $referrals = Matrix_MLM_User::get_referrals($user_id, 50);
        $referral_count = Matrix_MLM_User::get_referral_count($user_id);
        $referral_earnings = Matrix_MLM_User::get_referral_earnings($user_id);
        $referral_link = Matrix_MLM_User::get_referral_link($user_id);
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        ?>
        <h2><?php _e('My Referrals', 'matrix-mlm'); ?></h2>

        <div class="matrix-stats-grid">
            <div class="matrix-stat-card primary">
                <div class="stat-value"><?php echo $referral_count; ?></div>
                <div class="stat-label"><?php _e('Total Referrals', 'matrix-mlm'); ?></div>
            </div>
            <div class="matrix-stat-card success">
                <div class="stat-value"><?php echo $currency . number_format($referral_earnings, 2); ?></div>
                <div class="stat-label"><?php _e('Referral Earnings', 'matrix-mlm'); ?></div>
            </div>
        </div>

        <div class="matrix-referral-box">
            <h3><?php _e('Your Referral Link', 'matrix-mlm'); ?></h3>
            <div class="matrix-referral-link">
                <input type="text" id="ref-link" value="<?php echo esc_url($referral_link); ?>" readonly>
                <button onclick="navigator.clipboard.writeText(document.getElementById('ref-link').value); this.textContent='Copied!';" class="matrix-btn matrix-btn-primary"><?php _e('Copy', 'matrix-mlm'); ?></button>
            </div>
        </div>

        <h3><?php _e('Referred Users', 'matrix-mlm'); ?></h3>
        <table class="matrix-table">
            <thead><tr><th><?php _e('Username', 'matrix-mlm'); ?></th><th><?php _e('Email', 'matrix-mlm'); ?></th><th><?php _e('Status', 'matrix-mlm'); ?></th><th><?php _e('Joined', 'matrix-mlm'); ?></th></tr></thead>
            <tbody>
                <?php foreach ($referrals as $ref): ?>
                <tr>
                    <td><?php echo esc_html($ref->user_login); ?></td>
                    <td><?php echo esc_html($ref->user_email); ?></td>
                    <td><span class="matrix-badge matrix-badge-<?php echo $ref->status; ?>"><?php echo ucfirst($ref->status); ?></span></td>
                    <td><?php echo date('M d, Y', strtotime($ref->user_registered)); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
