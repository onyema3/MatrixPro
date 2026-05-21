<?php
/**
 * Admin Reports
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Reports {

    public function render() {
        global $wpdb;
        $currency = get_option('matrix_mlm_currency_symbol', '₦');

        $period = isset($_GET['period']) ? sanitize_text_field($_GET['period']) : 'month';
        $date_condition = $this->get_date_condition($period);

        $stats = [
            'deposits' => $wpdb->get_var("SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_deposits WHERE status = 'completed' $date_condition"),
            'withdrawals' => $wpdb->get_var("SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_withdrawals WHERE status = 'approved' $date_condition"),
            'commissions' => $wpdb->get_var("SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_commissions WHERE status = 'paid' $date_condition"),
            'new_users' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_user_meta WHERE 1=1 $date_condition"),
            'new_positions' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_positions WHERE 1=1 $date_condition"),
            'referral_commissions' => $wpdb->get_var("SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_commissions WHERE type = 'referral' AND status = 'paid' $date_condition"),
            'level_commissions' => $wpdb->get_var("SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_commissions WHERE type = 'level' AND status = 'paid' $date_condition"),
            'completion_bonuses' => $wpdb->get_var("SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_commissions WHERE type = 'matrix_completion' AND status = 'paid' $date_condition"),
        ];

        // Top earners
        $top_earners = $wpdb->get_results(
            "SELECT c.user_id, u.user_login, SUM(c.amount) as total_earned 
             FROM {$wpdb->prefix}matrix_commissions c 
             LEFT JOIN {$wpdb->users} u ON c.user_id = u.ID 
             WHERE c.status = 'paid' $date_condition 
             GROUP BY c.user_id ORDER BY total_earned DESC LIMIT 10"
        );

        // Top referrers
        $top_referrers = $wpdb->get_results(
            "SELECT um.user_id, u.user_login, COUNT(*) as referral_count 
             FROM {$wpdb->prefix}matrix_user_meta um2 
             LEFT JOIN {$wpdb->prefix}matrix_user_meta um ON um2.referred_by = um.user_id 
             LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID 
             WHERE um.user_id IS NOT NULL 
             GROUP BY um.user_id ORDER BY referral_count DESC LIMIT 10"
        );

        // Plan performance
        $plan_stats = $wpdb->get_results(
            "SELECT p.name, p.width, p.depth, 
             COUNT(DISTINCT pos.user_id) as members,
             COALESCE(SUM(c.amount), 0) as total_commissions
             FROM {$wpdb->prefix}matrix_plans p
             LEFT JOIN {$wpdb->prefix}matrix_positions pos ON p.id = pos.plan_id AND pos.status = 'active'
             LEFT JOIN {$wpdb->prefix}matrix_commissions c ON p.id = c.plan_id AND c.status = 'paid'
             WHERE p.status = 'active'
             GROUP BY p.id ORDER BY members DESC"
        );
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php _e('Reports & Analytics', 'matrix-mlm'); ?></h1>

            <div class="matrix-admin-filters">
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-reports&period=today'); ?>" class="button <?php echo $period === 'today' ? 'button-primary' : ''; ?>"><?php _e('Today', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-reports&period=week'); ?>" class="button <?php echo $period === 'week' ? 'button-primary' : ''; ?>"><?php _e('This Week', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-reports&period=month'); ?>" class="button <?php echo $period === 'month' ? 'button-primary' : ''; ?>"><?php _e('This Month', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-reports&period=year'); ?>" class="button <?php echo $period === 'year' ? 'button-primary' : ''; ?>"><?php _e('This Year', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-reports&period=all'); ?>" class="button <?php echo $period === 'all' ? 'button-primary' : ''; ?>"><?php _e('All Time', 'matrix-mlm'); ?></a>
            </div>

            <div class="matrix-admin-stats">
                <div class="stat-card stat-success"><h3><?php echo $currency . number_format($stats['deposits'], 2); ?></h3><p><?php _e('Deposits', 'matrix-mlm'); ?></p></div>
                <div class="stat-card stat-warning"><h3><?php echo $currency . number_format($stats['withdrawals'], 2); ?></h3><p><?php _e('Withdrawals', 'matrix-mlm'); ?></p></div>
                <div class="stat-card stat-info"><h3><?php echo $currency . number_format($stats['commissions'], 2); ?></h3><p><?php _e('Commissions Paid', 'matrix-mlm'); ?></p></div>
                <div class="stat-card stat-primary"><h3><?php echo number_format($stats['new_users']); ?></h3><p><?php _e('New Users', 'matrix-mlm'); ?></p></div>
                <div class="stat-card stat-purple"><h3><?php echo $currency . number_format($stats['referral_commissions'], 2); ?></h3><p><?php _e('Referral Commissions', 'matrix-mlm'); ?></p></div>
                <div class="stat-card stat-danger"><h3><?php echo $currency . number_format($stats['level_commissions'], 2); ?></h3><p><?php _e('Level Commissions', 'matrix-mlm'); ?></p></div>
            </div>

            <div class="matrix-admin-tables">
                <div class="matrix-table-section">
                    <h2><?php _e('Top Earners', 'matrix-mlm'); ?></h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead><tr><th>#</th><th><?php _e('User', 'matrix-mlm'); ?></th><th><?php _e('Total Earned', 'matrix-mlm'); ?></th></tr></thead>
                        <tbody>
                            <?php foreach ($top_earners as $i => $earner): ?>
                            <tr><td><?php echo $i + 1; ?></td><td><?php echo esc_html($earner->user_login); ?></td><td><?php echo $currency . number_format($earner->total_earned, 2); ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="matrix-table-section">
                    <h2><?php _e('Plan Performance', 'matrix-mlm'); ?></h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead><tr><th><?php _e('Plan', 'matrix-mlm'); ?></th><th><?php _e('Matrix', 'matrix-mlm'); ?></th><th><?php _e('Members', 'matrix-mlm'); ?></th><th><?php _e('Commissions', 'matrix-mlm'); ?></th></tr></thead>
                        <tbody>
                            <?php foreach ($plan_stats as $plan): ?>
                            <tr><td><?php echo esc_html($plan->name); ?></td><td><?php echo $plan->width . 'x' . $plan->depth; ?></td><td><?php echo number_format($plan->members); ?></td><td><?php echo $currency . number_format($plan->total_commissions, 2); ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    private function get_date_condition($period) {
        switch ($period) {
            case 'today':
                return "AND DATE(created_at) = CURDATE()";
            case 'week':
                return "AND YEARWEEK(created_at) = YEARWEEK(NOW())";
            case 'month':
                return "AND MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())";
            case 'year':
                return "AND YEAR(created_at) = YEAR(NOW())";
            default:
                return "";
        }
    }
}
