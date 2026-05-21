<?php
/**
 * Admin Users Management
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Users {

    public function render() {
        global $wpdb;
        $currency = get_option('matrix_mlm_currency_symbol', '₦');

        if (isset($_GET['action']) && $_GET['action'] === 'view' && isset($_GET['id'])) {
            $this->render_user_detail(intval($_GET['id']));
            return;
        }

        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $page_num = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($page_num - 1) * $per_page;

        $where = "WHERE 1=1";
        $params = [];

        if ($search) {
            $where .= " AND (u.user_login LIKE %s OR u.user_email LIKE %s)";
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        if ($status_filter) {
            $where .= " AND um.status = %s";
            $params[] = $status_filter;
        }

        $params[] = $per_page;
        $params[] = $offset;

        $users = $wpdb->get_results($wpdb->prepare(
            "SELECT um.*, u.user_login, u.user_email, u.user_registered 
             FROM {$wpdb->prefix}matrix_user_meta um 
             LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID 
             $where ORDER BY um.created_at DESC LIMIT %d OFFSET %d",
            $params
        ));

        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_user_meta");
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php _e('Manage Users', 'matrix-mlm'); ?></h1>

            <div class="matrix-admin-filters">
                <form method="get">
                    <input type="hidden" name="page" value="matrix-mlm-users">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e('Search users...', 'matrix-mlm'); ?>">
                    <select name="status">
                        <option value=""><?php _e('All Status', 'matrix-mlm'); ?></option>
                        <option value="active" <?php selected($status_filter, 'active'); ?>><?php _e('Active', 'matrix-mlm'); ?></option>
                        <option value="banned" <?php selected($status_filter, 'banned'); ?>><?php _e('Banned', 'matrix-mlm'); ?></option>
                        <option value="pending" <?php selected($status_filter, 'pending'); ?>><?php _e('Pending', 'matrix-mlm'); ?></option>
                    </select>
                    <input type="submit" class="button" value="<?php _e('Filter', 'matrix-mlm'); ?>">
                </form>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Username', 'matrix-mlm'); ?></th>
                        <th><?php _e('Email', 'matrix-mlm'); ?></th>
                        <th><?php _e('Balance', 'matrix-mlm'); ?></th>
                        <th><?php _e('Referral Code', 'matrix-mlm'); ?></th>
                        <th><?php _e('Referrals', 'matrix-mlm'); ?></th>
                        <th><?php _e('Status', 'matrix-mlm'); ?></th>
                        <th><?php _e('Joined', 'matrix-mlm'); ?></th>
                        <th><?php _e('Actions', 'matrix-mlm'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): 
                        $referral_count = Matrix_MLM_User::get_referral_count($user->user_id);
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($user->user_login); ?></strong></td>
                        <td><?php echo esc_html($user->user_email); ?></td>
                        <td><?php echo $currency . number_format($user->balance, 2); ?></td>
                        <td><code><?php echo esc_html($user->referral_code); ?></code></td>
                        <td><?php echo $referral_count; ?></td>
                        <td><span class="matrix-badge matrix-badge-<?php echo $user->status; ?>"><?php echo ucfirst($user->status); ?></span></td>
                        <td><?php echo date('M d, Y', strtotime($user->user_registered)); ?></td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=matrix-mlm-users&action=view&id=' . $user->user_id); ?>" class="button button-small"><?php _e('View', 'matrix-mlm'); ?></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            $total_pages = ceil($total / $per_page);
            if ($total_pages > 1):
            ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="<?php echo admin_url('admin.php?page=matrix-mlm-users&paged=' . $i); ?>" class="<?php echo $i === $page_num ? 'current' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_user_detail($user_id) {
        global $wpdb;
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $user = get_userdata($user_id);
        $meta = Matrix_MLM_User::get_meta($user_id);
        $wallet = new Matrix_MLM_Wallet();
        $commissions = Matrix_MLM_Commission::get_summary($user_id);
        $active_plans = Matrix_MLM_User::get_active_plans($user_id);
        $referrals = Matrix_MLM_User::get_referrals($user_id, 10);

        if (!$user || !$meta) {
            echo '<div class="notice notice-error"><p>' . __('User not found', 'matrix-mlm') . '</p></div>';
            return;
        }
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1>
                <?php printf(__('User: %s', 'matrix-mlm'), $user->user_login); ?>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-users'); ?>" class="page-title-action"><?php _e('Back to Users', 'matrix-mlm'); ?></a>
            </h1>

            <div class="matrix-admin-stats">
                <div class="stat-card stat-primary">
                    <h3><?php echo $currency . number_format($meta->balance, 2); ?></h3>
                    <p><?php _e('Current Balance', 'matrix-mlm'); ?></p>
                </div>
                <div class="stat-card stat-success">
                    <h3><?php echo $currency . number_format($commissions['total'], 2); ?></h3>
                    <p><?php _e('Total Commissions', 'matrix-mlm'); ?></p>
                </div>
                <div class="stat-card stat-info">
                    <h3><?php echo $currency . number_format($commissions['referral'], 2); ?></h3>
                    <p><?php _e('Referral Earnings', 'matrix-mlm'); ?></p>
                </div>
                <div class="stat-card stat-warning">
                    <h3><?php echo Matrix_MLM_User::get_referral_count($user_id); ?></h3>
                    <p><?php _e('Total Referrals', 'matrix-mlm'); ?></p>
                </div>
            </div>

            <div class="matrix-admin-card">
                <h2><?php _e('User Details', 'matrix-mlm'); ?></h2>
                <table class="form-table">
                    <tr><th><?php _e('Email', 'matrix-mlm'); ?></th><td><?php echo esc_html($user->user_email); ?></td></tr>
                    <tr><th><?php _e('Phone', 'matrix-mlm'); ?></th><td><?php echo esc_html($meta->phone ?? 'N/A'); ?></td></tr>
                    <tr><th><?php _e('Referral Code', 'matrix-mlm'); ?></th><td><code><?php echo esc_html($meta->referral_code); ?></code></td></tr>
                    <tr><th><?php _e('Status', 'matrix-mlm'); ?></th><td><span class="matrix-badge matrix-badge-<?php echo $meta->status; ?>"><?php echo ucfirst($meta->status); ?></span></td></tr>
                    <tr><th><?php _e('2FA', 'matrix-mlm'); ?></th><td><?php echo $meta->two_factor_enabled ? __('Enabled', 'matrix-mlm') : __('Disabled', 'matrix-mlm'); ?></td></tr>
                    <tr><th><?php _e('Email Verified', 'matrix-mlm'); ?></th><td><?php echo $meta->email_verified ? __('Yes', 'matrix-mlm') : __('No', 'matrix-mlm'); ?></td></tr>
                    <tr><th><?php _e('Registered', 'matrix-mlm'); ?></th><td><?php echo date('M d, Y H:i', strtotime($user->user_registered)); ?></td></tr>
                </table>

                <h3><?php _e('Quick Actions', 'matrix-mlm'); ?></h3>
                <div class="matrix-admin-actions">
                    <?php if ($meta->status === 'active'): ?>
                    <button class="button button-secondary" onclick="matrixAdminAction('ban_user', {user_id: <?php echo $user_id; ?>})"><?php _e('Ban User', 'matrix-mlm'); ?></button>
                    <?php else: ?>
                    <button class="button button-primary" onclick="matrixAdminAction('unban_user', {user_id: <?php echo $user_id; ?>})"><?php _e('Unban User', 'matrix-mlm'); ?></button>
                    <?php endif; ?>
                    <button class="button" onclick="matrixAddBalance(<?php echo $user_id; ?>)"><?php _e('Add Balance', 'matrix-mlm'); ?></button>
                    <button class="button" onclick="matrixSubtractBalance(<?php echo $user_id; ?>)"><?php _e('Subtract Balance', 'matrix-mlm'); ?></button>
                </div>
            </div>

            <div class="matrix-admin-card">
                <h2><?php _e('Active Plans', 'matrix-mlm'); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th><?php _e('Plan', 'matrix-mlm'); ?></th><th><?php _e('Downline', 'matrix-mlm'); ?></th><th><?php _e('Joined', 'matrix-mlm'); ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($active_plans as $plan): ?>
                        <tr>
                            <td><?php echo esc_html($plan->name); ?> (<?php echo $plan->width . 'x' . $plan->depth; ?>)</td>
                            <td><?php echo $plan->total_downline; ?></td>
                            <td><?php echo date('M d, Y', strtotime($plan->joined_at)); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
}
