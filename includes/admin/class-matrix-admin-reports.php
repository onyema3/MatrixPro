<?php
/**
 * Admin Reports with Export Functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Reports {

    public function __construct() {
        add_action('admin_init', [$this, 'handle_export']);
    }

    public function render() {
        global $wpdb;
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $report_type = isset($_GET['report']) ? sanitize_text_field($_GET['report']) : 'overview';
        $period = isset($_GET['period']) ? sanitize_text_field($_GET['period']) : 'month';
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php _e('Reports & Analytics', 'matrix-mlm'); ?></h1>

            <nav class="nav-tab-wrapper" style="margin-bottom: 20px;">
                <?php
                $tabs = [
                    'overview' => __('Overview', 'matrix-mlm'),
                    'financial' => __('Financial', 'matrix-mlm'),
                    'users' => __('Users', 'matrix-mlm'),
                    'commissions' => __('Commissions', 'matrix-mlm'),
                    'plans' => __('Plans', 'matrix-mlm'),
                    'deposits' => __('Deposits', 'matrix-mlm'),
                    'withdrawals' => __('Withdrawals', 'matrix-mlm'),
                    'referrals' => __('Referrals', 'matrix-mlm'),
                ];
                foreach ($tabs as $key => $label):
                ?>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-reports&report=' . $key . '&period=' . $period); ?>" class="nav-tab <?php echo $report_type === $key ? 'nav-tab-active' : ''; ?>"><?php echo $label; ?></a>
                <?php endforeach; ?>
            </nav>


            <!-- Period Filter & Custom Date Range -->
            <div class="matrix-admin-filters" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:20px;">
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-reports&report=' . $report_type . '&period=today'); ?>" class="button <?php echo $period === 'today' ? 'button-primary' : ''; ?>"><?php _e('Today', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-reports&report=' . $report_type . '&period=week'); ?>" class="button <?php echo $period === 'week' ? 'button-primary' : ''; ?>"><?php _e('This Week', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-reports&report=' . $report_type . '&period=month'); ?>" class="button <?php echo $period === 'month' ? 'button-primary' : ''; ?>"><?php _e('This Month', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-reports&report=' . $report_type . '&period=year'); ?>" class="button <?php echo $period === 'year' ? 'button-primary' : ''; ?>"><?php _e('This Year', 'matrix-mlm'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-reports&report=' . $report_type . '&period=all'); ?>" class="button <?php echo $period === 'all' ? 'button-primary' : ''; ?>"><?php _e('All Time', 'matrix-mlm'); ?></a>
                <span style="margin-left:10px;">|</span>
                <form method="get" style="display:flex;gap:6px;align-items:center;">
                    <input type="hidden" name="page" value="matrix-mlm-reports">
                    <input type="hidden" name="report" value="<?php echo esc_attr($report_type); ?>">
                    <input type="hidden" name="period" value="custom">
                    <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" class="regular-text" style="width:140px;">
                    <span>—</span>
                    <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>" class="regular-text" style="width:140px;">
                    <button type="submit" class="button"><?php _e('Filter', 'matrix-mlm'); ?></button>
                </form>
            </div>

            <!-- Export Buttons -->
            <div class="matrix-export-buttons" style="margin-bottom:20px;display:flex;gap:8px;">
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=matrix-mlm-reports&export=csv&report=' . $report_type . '&period=' . $period . '&date_from=' . $date_from . '&date_to=' . $date_to), 'matrix_export_report'); ?>" class="button"><span class="dashicons dashicons-media-spreadsheet" style="margin-top:4px;"></span> <?php _e('Export CSV', 'matrix-mlm'); ?></a>
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=matrix-mlm-reports&export=excel&report=' . $report_type . '&period=' . $period . '&date_from=' . $date_from . '&date_to=' . $date_to), 'matrix_export_report'); ?>" class="button"><span class="dashicons dashicons-media-document" style="margin-top:4px;"></span> <?php _e('Export Excel', 'matrix-mlm'); ?></a>
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=matrix-mlm-reports&export=pdf&report=' . $report_type . '&period=' . $period . '&date_from=' . $date_from . '&date_to=' . $date_to), 'matrix_export_report'); ?>" class="button"><span class="dashicons dashicons-pdf" style="margin-top:4px;"></span> <?php _e('Export PDF', 'matrix-mlm'); ?></a>
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=matrix-mlm-reports&export=json&report=' . $report_type . '&period=' . $period . '&date_from=' . $date_from . '&date_to=' . $date_to), 'matrix_export_report'); ?>" class="button"><span class="dashicons dashicons-editor-code" style="margin-top:4px;"></span> <?php _e('Export JSON', 'matrix-mlm'); ?></a>
            </div>

            <?php
            switch ($report_type) {
                case 'financial': $this->render_financial_report($period, $date_from, $date_to); break;
                case 'users': $this->render_users_report($period, $date_from, $date_to); break;
                case 'commissions': $this->render_commissions_report($period, $date_from, $date_to); break;
                case 'plans': $this->render_plans_report($period, $date_from, $date_to); break;
                case 'deposits': $this->render_deposits_report($period, $date_from, $date_to); break;
                case 'withdrawals': $this->render_withdrawals_report($period, $date_from, $date_to); break;
                case 'referrals': $this->render_referrals_report($period, $date_from, $date_to); break;
                default: $this->render_overview_report($period, $date_from, $date_to); break;
            }
            ?>
        </div>
        <?php
    }


    private function render_overview_report($period, $date_from, $date_to) {
        global $wpdb;
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $dc = $this->get_date_condition($period, $date_from, $date_to);

        $stats = [
            'deposits' => $wpdb->get_var("SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_deposits WHERE status = 'completed' $dc"),
            'withdrawals' => $wpdb->get_var("SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_withdrawals WHERE status = 'approved' $dc"),
            'commissions' => $wpdb->get_var("SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_commissions WHERE status = 'paid' $dc"),
            'new_users' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_user_meta WHERE 1=1 $dc"),
            'new_positions' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_positions WHERE 1=1 $dc"),
            'referral_commissions' => $wpdb->get_var("SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_commissions WHERE type = 'referral' AND status = 'paid' $dc"),
            'level_commissions' => $wpdb->get_var("SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_commissions WHERE type = 'level' AND status = 'paid' $dc"),
            'completion_bonuses' => $wpdb->get_var("SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_commissions WHERE type = 'matrix_completion' AND status = 'paid' $dc"),
            'transfers' => $wpdb->get_var("SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_transfers WHERE status = 'completed' $dc"),
            'pending_withdrawals' => $wpdb->get_var("SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_withdrawals WHERE status = 'pending' $dc"),
        ];

        $top_earners = $wpdb->get_results("SELECT c.user_id, u.user_login, SUM(c.amount) as total_earned FROM {$wpdb->prefix}matrix_commissions c LEFT JOIN {$wpdb->users} u ON c.user_id = u.ID WHERE c.status = 'paid' $dc GROUP BY c.user_id ORDER BY total_earned DESC LIMIT 10");
        ?>
        <div class="matrix-admin-stats">
            <div class="stat-card stat-success"><h3><?php echo $currency . number_format($stats['deposits'], 2); ?></h3><p><?php _e('Total Deposits', 'matrix-mlm'); ?></p></div>
            <div class="stat-card stat-warning"><h3><?php echo $currency . number_format($stats['withdrawals'], 2); ?></h3><p><?php _e('Total Withdrawals', 'matrix-mlm'); ?></p></div>
            <div class="stat-card stat-info"><h3><?php echo $currency . number_format($stats['commissions'], 2); ?></h3><p><?php _e('Commissions Paid', 'matrix-mlm'); ?></p></div>
            <div class="stat-card stat-primary"><h3><?php echo number_format($stats['new_users']); ?></h3><p><?php _e('New Users', 'matrix-mlm'); ?></p></div>
            <div class="stat-card stat-purple"><h3><?php echo $currency . number_format($stats['referral_commissions'], 2); ?></h3><p><?php _e('Referral Commissions', 'matrix-mlm'); ?></p></div>
            <div class="stat-card stat-danger"><h3><?php echo $currency . number_format($stats['level_commissions'], 2); ?></h3><p><?php _e('Level Commissions', 'matrix-mlm'); ?></p></div>
        </div>
        <div class="matrix-admin-tables"><div class="matrix-table-section">
            <h2><?php _e('Top 10 Earners', 'matrix-mlm'); ?></h2>
            <table class="wp-list-table widefat fixed striped"><thead><tr><th>#</th><th><?php _e('User', 'matrix-mlm'); ?></th><th><?php _e('Total Earned', 'matrix-mlm'); ?></th></tr></thead><tbody>
            <?php foreach ($top_earners as $i => $e): ?>
            <tr><td><?php echo $i+1; ?></td><td><?php echo esc_html($e->user_login); ?></td><td><?php echo $currency . number_format($e->total_earned, 2); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div></div>
        <?php
    }

    private function render_financial_report($period, $date_from, $date_to) {
        global $wpdb;
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $dc = $this->get_date_condition($period, $date_from, $date_to);

        $daily_revenue = $wpdb->get_results("SELECT DATE(created_at) as date, SUM(amount) as total FROM {$wpdb->prefix}matrix_deposits WHERE status = 'completed' $dc GROUP BY DATE(created_at) ORDER BY date DESC LIMIT 30");
        $daily_payouts = $wpdb->get_results("SELECT DATE(created_at) as date, SUM(amount) as total FROM {$wpdb->prefix}matrix_withdrawals WHERE status = 'approved' $dc GROUP BY DATE(created_at) ORDER BY date DESC LIMIT 30");

        $net_revenue = $wpdb->get_var("SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_deposits WHERE status = 'completed' $dc");
        $net_payouts = $wpdb->get_var("SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_withdrawals WHERE status = 'approved' $dc");
        $charges_earned = $wpdb->get_var("SELECT COALESCE(SUM(charge), 0) FROM {$wpdb->prefix}matrix_deposits WHERE status = 'completed' $dc") + $wpdb->get_var("SELECT COALESCE(SUM(charge), 0) FROM {$wpdb->prefix}matrix_withdrawals WHERE status = 'approved' $dc");
        ?>
        <div class="matrix-admin-stats">
            <div class="stat-card stat-success"><h3><?php echo esc_html($currency . number_format($net_revenue, 2)); ?></h3><p><?php _e('Total Revenue (Deposits)', 'matrix-mlm'); ?></p></div>
            <div class="stat-card stat-danger"><h3><?php echo esc_html($currency . number_format($net_payouts, 2)); ?></h3><p><?php _e('Total Payouts', 'matrix-mlm'); ?></p></div>
            <div class="stat-card stat-info"><h3><?php echo esc_html($currency . number_format($net_revenue - $net_payouts, 2)); ?></h3><p><?php _e('Net Balance', 'matrix-mlm'); ?></p></div>
            <div class="stat-card stat-warning"><h3><?php echo esc_html($currency . number_format($charges_earned, 2)); ?></h3><p><?php _e('Charges/Fees Earned', 'matrix-mlm'); ?></p></div>
        </div>
        <h2><?php _e('Daily Revenue', 'matrix-mlm'); ?></h2>
        <table class="wp-list-table widefat fixed striped"><thead><tr><th><?php _e('Date', 'matrix-mlm'); ?></th><th><?php _e('Amount', 'matrix-mlm'); ?></th></tr></thead><tbody>
        <?php foreach ($daily_revenue as $row): ?>
        <tr><td><?php echo esc_html(date('M d, Y', strtotime($row->date))); ?></td><td><?php echo esc_html($currency . number_format($row->total, 2)); ?></td></tr>
        <?php endforeach; ?></tbody></table>
        <?php
    }


    private function render_users_report($period, $date_from, $date_to) {
        global $wpdb;
        $dc = $this->get_date_condition($period, $date_from, $date_to);
        $users = $wpdb->get_results("SELECT um.*, u.user_login, u.user_email, u.user_registered FROM {$wpdb->prefix}matrix_user_meta um LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID WHERE 1=1 $dc ORDER BY um.created_at DESC LIMIT 100");
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_user_meta WHERE 1=1 $dc");
        $active = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_user_meta WHERE status = 'active' $dc");
        $banned = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_user_meta WHERE status = 'banned' $dc");
        ?>
        <div class="matrix-admin-stats">
            <div class="stat-card stat-primary"><h3><?php echo number_format($total); ?></h3><p><?php _e('Total Users', 'matrix-mlm'); ?></p></div>
            <div class="stat-card stat-success"><h3><?php echo number_format($active); ?></h3><p><?php _e('Active', 'matrix-mlm'); ?></p></div>
            <div class="stat-card stat-danger"><h3><?php echo number_format($banned); ?></h3><p><?php _e('Banned', 'matrix-mlm'); ?></p></div>
        </div>
        <table class="wp-list-table widefat fixed striped"><thead><tr><th><?php _e('Username', 'matrix-mlm'); ?></th><th><?php _e('Email', 'matrix-mlm'); ?></th><th><?php _e('Phone', 'matrix-mlm'); ?></th><th><?php _e('Balance', 'matrix-mlm'); ?></th><th><?php _e('Referral Code', 'matrix-mlm'); ?></th><th><?php _e('Status', 'matrix-mlm'); ?></th><th><?php _e('Joined', 'matrix-mlm'); ?></th></tr></thead><tbody>
        <?php $currency = get_option('matrix_mlm_currency_symbol', '₦'); foreach ($users as $u): ?>
        <tr><td><?php echo esc_html($u->user_login); ?></td><td><?php echo esc_html($u->user_email); ?></td><td><?php echo esc_html($u->phone ?? '-'); ?></td><td><?php echo esc_html($currency . number_format($u->balance, 2)); ?></td><td><code><?php echo esc_html($u->referral_code); ?></code></td><td><span class="matrix-badge matrix-badge-<?php echo esc_attr($u->status); ?>"><?php echo esc_html(ucfirst($u->status)); ?></span></td><td><?php echo esc_html(date('M d, Y', strtotime($u->user_registered))); ?></td></tr>
        <?php endforeach; ?></tbody></table>
        <?php
    }

    private function render_commissions_report($period, $date_from, $date_to) {
        global $wpdb;
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $dc = $this->get_date_condition($period, $date_from, $date_to);
        $commissions = $wpdb->get_results("SELECT c.*, u.user_login, u2.user_login as from_username, p.name as plan_name FROM {$wpdb->prefix}matrix_commissions c LEFT JOIN {$wpdb->users} u ON c.user_id = u.ID LEFT JOIN {$wpdb->users} u2 ON c.from_user_id = u2.ID LEFT JOIN {$wpdb->prefix}matrix_plans p ON c.plan_id = p.id WHERE c.status = 'paid' $dc ORDER BY c.created_at DESC LIMIT 200");
        $by_type = $wpdb->get_results("SELECT type, COUNT(*) as count, SUM(amount) as total FROM {$wpdb->prefix}matrix_commissions WHERE status = 'paid' $dc GROUP BY type");
        ?>
        <div class="matrix-admin-stats">
        <?php foreach ($by_type as $t): ?>
            <div class="stat-card stat-info"><h3><?php echo esc_html($currency . number_format($t->total, 2)); ?></h3><p><?php echo esc_html(ucfirst(str_replace('_', ' ', $t->type))); ?> (<?php echo (int) $t->count; ?>)</p></div>
        <?php endforeach; ?>
        </div>
        <table class="wp-list-table widefat fixed striped"><thead><tr><th><?php _e('Date', 'matrix-mlm'); ?></th><th><?php _e('User', 'matrix-mlm'); ?></th><th><?php _e('Type', 'matrix-mlm'); ?></th><th><?php _e('From', 'matrix-mlm'); ?></th><th><?php _e('Plan', 'matrix-mlm'); ?></th><th><?php _e('Level', 'matrix-mlm'); ?></th><th><?php _e('Amount', 'matrix-mlm'); ?></th></tr></thead><tbody>
        <?php foreach ($commissions as $c): ?>
        <tr><td><?php echo esc_html(date('M d, Y', strtotime($c->created_at))); ?></td><td><?php echo esc_html($c->user_login); ?></td><td><?php echo esc_html(ucfirst(str_replace('_', ' ', $c->type))); ?></td><td><?php echo esc_html($c->from_username ?? '-'); ?></td><td><?php echo esc_html($c->plan_name ?? '-'); ?></td><td><?php echo esc_html((string) ($c->level ?: '-')); ?></td><td><?php echo esc_html($currency . number_format($c->amount, 2)); ?></td></tr>
        <?php endforeach; ?></tbody></table>
        <?php
    }

    private function render_plans_report($period, $date_from, $date_to) {
        global $wpdb;
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $dc = $this->get_date_condition($period, $date_from, $date_to);
        $plans = $wpdb->get_results("SELECT p.*, COUNT(DISTINCT pos.user_id) as members, COUNT(CASE WHEN pos.status = 'completed' THEN 1 END) as completed, COALESCE(SUM(c.amount), 0) as total_commissions FROM {$wpdb->prefix}matrix_plans p LEFT JOIN {$wpdb->prefix}matrix_positions pos ON p.id = pos.plan_id LEFT JOIN {$wpdb->prefix}matrix_commissions c ON p.id = c.plan_id AND c.status = 'paid' GROUP BY p.id ORDER BY members DESC");
        ?>
        <table class="wp-list-table widefat fixed striped"><thead><tr><th><?php _e('Plan', 'matrix-mlm'); ?></th><th><?php _e('Matrix', 'matrix-mlm'); ?></th><th><?php _e('Price', 'matrix-mlm'); ?></th><th><?php _e('Members', 'matrix-mlm'); ?></th><th><?php _e('Completed', 'matrix-mlm'); ?></th><th><?php _e('Total Commissions', 'matrix-mlm'); ?></th><th><?php _e('Status', 'matrix-mlm'); ?></th></tr></thead><tbody>
        <?php foreach ($plans as $p): ?>
        <tr><td><?php echo esc_html($p->name); ?></td><td><?php echo esc_html($p->width . 'x' . $p->depth); ?></td><td><?php echo esc_html($currency . number_format($p->price, 2)); ?></td><td><?php echo esc_html(number_format($p->members)); ?></td><td><?php echo esc_html(number_format($p->completed)); ?></td><td><?php echo esc_html($currency . number_format($p->total_commissions, 2)); ?></td><td><span class="matrix-badge matrix-badge-<?php echo esc_attr($p->status); ?>"><?php echo esc_html(ucfirst($p->status)); ?></span></td></tr>
        <?php endforeach; ?></tbody></table>
        <?php
    }


    private function render_deposits_report($period, $date_from, $date_to) {
        global $wpdb;
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $dc = $this->get_date_condition($period, $date_from, $date_to);
        $deposits = $wpdb->get_results("SELECT d.*, u.user_login FROM {$wpdb->prefix}matrix_deposits d LEFT JOIN {$wpdb->users} u ON d.user_id = u.ID WHERE 1=1 $dc ORDER BY d.created_at DESC LIMIT 200");
        $by_status = $wpdb->get_results("SELECT status, COUNT(*) as count, SUM(amount) as total FROM {$wpdb->prefix}matrix_deposits WHERE 1=1 $dc GROUP BY status");
        $by_gateway = $wpdb->get_results("SELECT gateway, COUNT(*) as count, SUM(amount) as total FROM {$wpdb->prefix}matrix_deposits WHERE status = 'completed' $dc GROUP BY gateway");
        ?>
        <div class="matrix-admin-stats">
        <?php foreach ($by_status as $s): ?>
            <div class="stat-card stat-<?php echo esc_attr($s->status === 'completed' ? 'success' : ($s->status === 'pending' ? 'warning' : 'danger')); ?>"><h3><?php echo esc_html($currency . number_format($s->total, 2)); ?></h3><p><?php echo esc_html(ucfirst($s->status)); ?> (<?php echo (int) $s->count; ?>)</p></div>
        <?php endforeach; ?>
        </div>
        <h3><?php _e('By Gateway', 'matrix-mlm'); ?></h3>
        <div class="matrix-admin-stats">
        <?php foreach ($by_gateway as $g): ?>
            <div class="stat-card stat-info"><h3><?php echo esc_html($currency . number_format($g->total, 2)); ?></h3><p><?php echo esc_html(ucfirst($g->gateway)); ?> (<?php echo (int) $g->count; ?>)</p></div>
        <?php endforeach; ?>
        </div>
        <table class="wp-list-table widefat fixed striped"><thead><tr><th><?php _e('Date', 'matrix-mlm'); ?></th><th><?php _e('User', 'matrix-mlm'); ?></th><th><?php _e('Gateway', 'matrix-mlm'); ?></th><th><?php _e('Amount', 'matrix-mlm'); ?></th><th><?php _e('Charge', 'matrix-mlm'); ?></th><th><?php _e('Net', 'matrix-mlm'); ?></th><th><?php _e('Status', 'matrix-mlm'); ?></th></tr></thead><tbody>
        <?php foreach ($deposits as $d): ?>
        <tr><td><?php echo esc_html(date('M d, Y H:i', strtotime($d->created_at))); ?></td><td><?php echo esc_html($d->user_login); ?></td><td><?php echo esc_html(ucfirst($d->gateway)); ?></td><td><?php echo esc_html($currency . number_format($d->amount, 2)); ?></td><td><?php echo esc_html($currency . number_format($d->charge, 2)); ?></td><td><?php echo esc_html($currency . number_format($d->net_amount, 2)); ?></td><td><span class="matrix-badge matrix-badge-<?php echo esc_attr($d->status); ?>"><?php echo esc_html(ucfirst($d->status)); ?></span></td></tr>
        <?php endforeach; ?></tbody></table>
        <?php
    }

    private function render_withdrawals_report($period, $date_from, $date_to) {
        global $wpdb;
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $dc = $this->get_date_condition($period, $date_from, $date_to);
        $withdrawals = $wpdb->get_results("SELECT w.*, u.user_login FROM {$wpdb->prefix}matrix_withdrawals w LEFT JOIN {$wpdb->users} u ON w.user_id = u.ID WHERE 1=1 $dc ORDER BY w.created_at DESC LIMIT 200");
        $by_status = $wpdb->get_results("SELECT status, COUNT(*) as count, SUM(amount) as total FROM {$wpdb->prefix}matrix_withdrawals WHERE 1=1 $dc GROUP BY status");
        ?>
        <div class="matrix-admin-stats">
        <?php foreach ($by_status as $s): ?>
            <div class="stat-card stat-<?php echo esc_attr($s->status === 'approved' ? 'success' : ($s->status === 'pending' ? 'warning' : 'danger')); ?>"><h3><?php echo esc_html($currency . number_format($s->total, 2)); ?></h3><p><?php echo esc_html(ucfirst($s->status)); ?> (<?php echo (int) $s->count; ?>)</p></div>
        <?php endforeach; ?>
        </div>
        <table class="wp-list-table widefat fixed striped"><thead><tr><th><?php _e('Date', 'matrix-mlm'); ?></th><th><?php _e('User', 'matrix-mlm'); ?></th><th><?php _e('Method', 'matrix-mlm'); ?></th><th><?php _e('Amount', 'matrix-mlm'); ?></th><th><?php _e('Charge', 'matrix-mlm'); ?></th><th><?php _e('Net', 'matrix-mlm'); ?></th><th><?php _e('Status', 'matrix-mlm'); ?></th></tr></thead><tbody>
        <?php foreach ($withdrawals as $w): ?>
        <tr><td><?php echo esc_html(date('M d, Y H:i', strtotime($w->created_at))); ?></td><td><?php echo esc_html($w->user_login); ?></td><td><?php echo esc_html(ucfirst($w->method)); ?></td><td><?php echo esc_html($currency . number_format($w->amount, 2)); ?></td><td><?php echo esc_html($currency . number_format($w->charge, 2)); ?></td><td><?php echo esc_html($currency . number_format($w->net_amount, 2)); ?></td><td><span class="matrix-badge matrix-badge-<?php echo esc_attr($w->status); ?>"><?php echo esc_html(ucfirst($w->status)); ?></span></td></tr>
        <?php endforeach; ?></tbody></table>
        <?php
    }

    private function render_referrals_report($period, $date_from, $date_to) {
        global $wpdb;
        $dc = $this->get_date_condition($period, $date_from, $date_to);
        $top_referrers = $wpdb->get_results("SELECT um.user_id, u.user_login, u.user_email, COUNT(um2.user_id) as referral_count, COALESCE(SUM(c.amount), 0) as earnings FROM {$wpdb->prefix}matrix_user_meta um LEFT JOIN {$wpdb->prefix}matrix_user_meta um2 ON um2.referred_by = um.user_id LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID LEFT JOIN {$wpdb->prefix}matrix_commissions c ON c.user_id = um.user_id AND c.type = 'referral' AND c.status = 'paid' WHERE um2.user_id IS NOT NULL GROUP BY um.user_id ORDER BY referral_count DESC LIMIT 50");
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        ?>
        <h2><?php _e('Top Referrers', 'matrix-mlm'); ?></h2>
        <table class="wp-list-table widefat fixed striped"><thead><tr><th>#</th><th><?php _e('User', 'matrix-mlm'); ?></th><th><?php _e('Email', 'matrix-mlm'); ?></th><th><?php _e('Referrals', 'matrix-mlm'); ?></th><th><?php _e('Earnings', 'matrix-mlm'); ?></th></tr></thead><tbody>
        <?php foreach ($top_referrers as $i => $r): ?>
        <tr><td><?php echo $i+1; ?></td><td><?php echo esc_html($r->user_login); ?></td><td><?php echo esc_html($r->user_email); ?></td><td><?php echo number_format($r->referral_count); ?></td><td><?php echo $currency . number_format($r->earnings, 2); ?></td></tr>
        <?php endforeach; ?></tbody></table>
        <?php
    }


    /**
     * Handle export requests
     */
    public function handle_export() {
        if (!isset($_GET['export']) || !isset($_GET['page']) || $_GET['page'] !== 'matrix-mlm-reports') {
            return;
        }
        if (!wp_verify_nonce($_GET['_wpnonce'], 'matrix_export_report')) {
            return;
        }
        if (!current_user_can('manage_matrix_mlm')) {
            return;
        }

        $format = sanitize_text_field($_GET['export']);
        $report_type = sanitize_text_field($_GET['report'] ?? 'overview');
        $period = sanitize_text_field($_GET['period'] ?? 'month');
        $date_from = sanitize_text_field($_GET['date_from'] ?? '');
        $date_to = sanitize_text_field($_GET['date_to'] ?? '');

        $data = $this->get_export_data($report_type, $period, $date_from, $date_to);
        $filename = 'matrix-' . $report_type . '-report-' . date('Y-m-d');

        switch ($format) {
            case 'csv': $this->export_csv($data, $filename); break;
            case 'excel': $this->export_excel($data, $filename); break;
            case 'pdf': $this->export_pdf($data, $filename, $report_type); break;
            case 'json': $this->export_json($data, $filename); break;
        }
        exit;
    }

    /**
     * Get structured data for export based on report type
     */
    private function get_export_data($report_type, $period, $date_from, $date_to) {
        global $wpdb;
        $dc = $this->get_date_condition($period, $date_from, $date_to);

        switch ($report_type) {
            case 'financial':
                return $wpdb->get_results("SELECT DATE(created_at) as date, gateway, amount, charge, net_amount, status FROM {$wpdb->prefix}matrix_deposits WHERE 1=1 $dc ORDER BY created_at DESC", ARRAY_A);
            case 'users':
                return $wpdb->get_results("SELECT u.user_login as username, u.user_email as email, um.phone, um.balance, um.referral_code, um.status, u.user_registered as joined FROM {$wpdb->prefix}matrix_user_meta um LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID WHERE 1=1 $dc ORDER BY um.created_at DESC", ARRAY_A);
            case 'commissions':
                return $wpdb->get_results("SELECT c.created_at as date, u.user_login as user, c.type, u2.user_login as from_user, p.name as plan, c.level, c.amount FROM {$wpdb->prefix}matrix_commissions c LEFT JOIN {$wpdb->users} u ON c.user_id = u.ID LEFT JOIN {$wpdb->users} u2 ON c.from_user_id = u2.ID LEFT JOIN {$wpdb->prefix}matrix_plans p ON c.plan_id = p.id WHERE c.status = 'paid' $dc ORDER BY c.created_at DESC", ARRAY_A);
            case 'plans':
                return $wpdb->get_results("SELECT p.name, CONCAT(p.width, 'x', p.depth) as matrix, p.price, COUNT(DISTINCT pos.user_id) as members, p.status FROM {$wpdb->prefix}matrix_plans p LEFT JOIN {$wpdb->prefix}matrix_positions pos ON p.id = pos.plan_id GROUP BY p.id", ARRAY_A);
            case 'deposits':
                return $wpdb->get_results("SELECT d.created_at as date, u.user_login as user, d.gateway, d.amount, d.charge, d.net_amount, d.currency, d.status FROM {$wpdb->prefix}matrix_deposits d LEFT JOIN {$wpdb->users} u ON d.user_id = u.ID WHERE 1=1 $dc ORDER BY d.created_at DESC", ARRAY_A);
            case 'withdrawals':
                return $wpdb->get_results("SELECT w.created_at as date, u.user_login as user, w.method, w.amount, w.charge, w.net_amount, w.currency, w.status FROM {$wpdb->prefix}matrix_withdrawals w LEFT JOIN {$wpdb->users} u ON w.user_id = u.ID WHERE 1=1 $dc ORDER BY w.created_at DESC", ARRAY_A);
            case 'referrals':
                return $wpdb->get_results("SELECT u.user_login as referrer, u.user_email as email, COUNT(um2.user_id) as referral_count, COALESCE(SUM(c.amount), 0) as earnings FROM {$wpdb->prefix}matrix_user_meta um LEFT JOIN {$wpdb->prefix}matrix_user_meta um2 ON um2.referred_by = um.user_id LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID LEFT JOIN {$wpdb->prefix}matrix_commissions c ON c.user_id = um.user_id AND c.type = 'referral' AND c.status = 'paid' WHERE um2.user_id IS NOT NULL GROUP BY um.user_id ORDER BY referral_count DESC", ARRAY_A);
            default:
                return $wpdb->get_results("SELECT DATE(created_at) as date, 'deposit' as type, gateway as detail, amount, status FROM {$wpdb->prefix}matrix_deposits WHERE status = 'completed' $dc UNION ALL SELECT DATE(created_at) as date, 'withdrawal' as type, method as detail, amount, status FROM {$wpdb->prefix}matrix_withdrawals WHERE status = 'approved' $dc ORDER BY date DESC", ARRAY_A);
        }
    }


    /**
     * Export as CSV
     */
    private function export_csv($data, $filename) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        // UTF-8 BOM for Excel compatibility
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        if (!empty($data)) {
            // Header row
            fputcsv($output, array_keys($data[0]));
            // Data rows
            foreach ($data as $row) {
                fputcsv($output, $row);
            }
        }
        fclose($output);
    }

    /**
     * Export as Excel (XML Spreadsheet)
     */
    private function export_excel($data, $filename) {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">';
        echo '<head><meta charset="UTF-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Report</x:Name></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--></head>';
        echo '<body><table border="1">';

        if (!empty($data)) {
            // Header
            echo '<tr>';
            foreach (array_keys($data[0]) as $header) {
                echo '<th style="background:#4f46e5;color:#fff;font-weight:bold;padding:8px;">' . esc_html(ucwords(str_replace('_', ' ', $header))) . '</th>';
            }
            echo '</tr>';
            // Data
            foreach ($data as $row) {
                echo '<tr>';
                foreach ($row as $cell) {
                    echo '<td style="padding:6px;">' . esc_html($cell) . '</td>';
                }
                echo '</tr>';
            }
        }
        echo '</table></body></html>';
    }

    /**
     * Export as PDF (HTML-based printable)
     */
    private function export_pdf($data, $filename, $report_type) {
        header('Content-Type: text/html; charset=utf-8');
        $title = get_option('matrix_mlm_site_title', 'Matrix MLM Pro') . ' - ' . ucfirst($report_type) . ' Report';
        ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title><?php echo esc_html($title); ?></title>
<style>
body{font-family:Arial,sans-serif;margin:20px;font-size:12px;color:#333;}
h1{font-size:20px;color:#4f46e5;margin-bottom:5px;}
.meta{color:#666;margin-bottom:20px;font-size:11px;}
table{width:100%;border-collapse:collapse;margin-top:15px;}
th{background:#4f46e5;color:#fff;padding:8px 10px;text-align:left;font-size:11px;}
td{padding:6px 10px;border-bottom:1px solid #e5e7eb;font-size:11px;}
tr:nth-child(even){background:#f9fafb;}
.footer{margin-top:30px;text-align:center;color:#999;font-size:10px;border-top:1px solid #e5e7eb;padding-top:10px;}
@media print{body{margin:0;}.no-print{display:none;}}
</style>
</head>
<body>
<div class="no-print" style="margin-bottom:15px;"><button onclick="window.print()" style="padding:8px 16px;background:#4f46e5;color:#fff;border:none;border-radius:4px;cursor:pointer;">Print / Save as PDF</button></div>
<h1><?php echo esc_html($title); ?></h1>
<p class="meta"><?php echo __('Generated:', 'matrix-mlm') . ' ' . date('F d, Y H:i:s'); ?> | <?php echo __('Records:', 'matrix-mlm') . ' ' . count($data); ?></p>
<?php if (!empty($data)): ?>
<table>
<thead><tr>
<?php foreach (array_keys($data[0]) as $h): ?>
<th><?php echo esc_html(ucwords(str_replace('_', ' ', $h))); ?></th>
<?php endforeach; ?>
</tr></thead>
<tbody>
<?php foreach ($data as $row): ?>
<tr><?php foreach ($row as $cell): ?><td><?php echo esc_html($cell); ?></td><?php endforeach; ?></tr>
<?php endforeach; ?>
</tbody>
</table>
<?php else: ?>
<p><?php _e('No data available for this period.', 'matrix-mlm'); ?></p>
<?php endif; ?>
<div class="footer"><?php echo esc_html(get_option('matrix_mlm_site_title', 'Matrix MLM Pro')); ?> &copy; <?php echo date('Y'); ?></div>
</body></html>
        <?php
    }

    /**
     * Export as JSON
     */
    private function export_json($data, $filename) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.json"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo json_encode([
            'report' => $filename,
            'generated_at' => current_time('mysql'),
            'total_records' => count($data),
            'data' => $data,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }


    /**
     * Get SQL date condition based on period or custom range
     */
    private function get_date_condition($period, $date_from = '', $date_to = '') {
        if ($period === 'custom' && !empty($date_from) && !empty($date_to)) {
            global $wpdb;
            return $wpdb->prepare(" AND DATE(created_at) BETWEEN %s AND %s", $date_from, $date_to);
        }

        switch ($period) {
            case 'today':
                return " AND DATE(created_at) = CURDATE()";
            case 'week':
                return " AND YEARWEEK(created_at, 1) = YEARWEEK(NOW(), 1)";
            case 'month':
                return " AND MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())";
            case 'year':
                return " AND YEAR(created_at) = YEAR(NOW())";
            case 'all':
            default:
                return "";
        }
    }
}
