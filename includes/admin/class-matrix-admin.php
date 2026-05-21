<?php
/**
 * Admin Main Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menus']);
        add_action('wp_ajax_matrix_admin_action', [$this, 'handle_admin_ajax']);
    }

    /**
     * Register admin menus
     */
    public function add_admin_menus() {
        // Main menu
        add_menu_page(
            __('Matrix MLM', 'matrix-mlm'),
            __('Matrix MLM', 'matrix-mlm'),
            'manage_matrix_mlm',
            'matrix-mlm',
            [$this, 'render_dashboard'],
            'dashicons-networking',
            30
        );

        // Submenus
        add_submenu_page('matrix-mlm', __('Dashboard', 'matrix-mlm'), __('Dashboard', 'matrix-mlm'), 'manage_matrix_mlm', 'matrix-mlm', [$this, 'render_dashboard']);
        add_submenu_page('matrix-mlm', __('Manage Users', 'matrix-mlm'), __('Users', 'matrix-mlm'), 'manage_matrix_users', 'matrix-mlm-users', [new Matrix_MLM_Admin_Users(), 'render']);
        add_submenu_page('matrix-mlm', __('Manage Plans', 'matrix-mlm'), __('Plans', 'matrix-mlm'), 'manage_matrix_plans', 'matrix-mlm-plans', [new Matrix_MLM_Admin_Plans(), 'render']);
        add_submenu_page('matrix-mlm', __('E-Pins', 'matrix-mlm'), __('E-Pins', 'matrix-mlm'), 'manage_matrix_mlm', 'matrix-mlm-epins', [$this, 'render_epins']);
        add_submenu_page('matrix-mlm', __('Payment Gateways', 'matrix-mlm'), __('Gateways', 'matrix-mlm'), 'manage_matrix_mlm', 'matrix-mlm-gateways', [new Matrix_MLM_Admin_Gateways(), 'render']);
        add_submenu_page('matrix-mlm', __('Deposits', 'matrix-mlm'), __('Deposits', 'matrix-mlm'), 'manage_matrix_deposits', 'matrix-mlm-deposits', [new Matrix_MLM_Admin_Deposits(), 'render']);
        add_submenu_page('matrix-mlm', __('Withdrawals', 'matrix-mlm'), __('Withdrawals', 'matrix-mlm'), 'manage_matrix_withdrawals', 'matrix-mlm-withdrawals', [new Matrix_MLM_Admin_Withdrawals(), 'render']);
        add_submenu_page('matrix-mlm', __('Support Tickets', 'matrix-mlm'), __('Tickets', 'matrix-mlm'), 'manage_matrix_tickets', 'matrix-mlm-tickets', [new Matrix_MLM_Admin_Tickets(), 'render']);
        add_submenu_page('matrix-mlm', __('Reports', 'matrix-mlm'), __('Reports', 'matrix-mlm'), 'manage_matrix_mlm', 'matrix-mlm-reports', [new Matrix_MLM_Admin_Reports(), 'render']);
        add_submenu_page('matrix-mlm', __('Frontend Manager', 'matrix-mlm'), __('Frontend', 'matrix-mlm'), 'manage_matrix_mlm', 'matrix-mlm-frontend', [new Matrix_MLM_Admin_Frontend(), 'render']);
        add_submenu_page('matrix-mlm', __('Settings', 'matrix-mlm'), __('Settings', 'matrix-mlm'), 'manage_matrix_settings', 'matrix-mlm-settings', [new Matrix_MLM_Admin_Settings(), 'render']);
    }

    /**
     * Render admin dashboard
     */
    public function render_dashboard() {
        global $wpdb;

        $stats = [
            'total_users' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_user_meta"),
            'active_users' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_user_meta WHERE status = 'active'"),
            'total_deposits' => $wpdb->get_var("SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_deposits WHERE status = 'completed'"),
            'total_withdrawals' => $wpdb->get_var("SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_withdrawals WHERE status = 'approved'"),
            'total_commissions' => $wpdb->get_var("SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_commissions WHERE status = 'paid'"),
            'pending_deposits' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_deposits WHERE status = 'pending'"),
            'pending_withdrawals' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_withdrawals WHERE status = 'pending'"),
            'open_tickets' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_tickets WHERE status IN ('open', 'customer_reply')"),
            'total_plans' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_plans WHERE status = 'active'"),
            'subscribers' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_subscribers"),
        ];

        $recent_deposits = $wpdb->get_results(
            "SELECT d.*, u.user_login FROM {$wpdb->prefix}matrix_deposits d 
             LEFT JOIN {$wpdb->users} u ON d.user_id = u.ID 
             ORDER BY d.created_at DESC LIMIT 5"
        );

        $recent_withdrawals = $wpdb->get_results(
            "SELECT w.*, u.user_login FROM {$wpdb->prefix}matrix_withdrawals w 
             LEFT JOIN {$wpdb->users} u ON w.user_id = u.ID 
             ORDER BY w.created_at DESC LIMIT 5"
        );

        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1 class="matrix-admin-title"><?php _e('Matrix MLM Dashboard', 'matrix-mlm'); ?></h1>
            
            <div class="matrix-admin-stats">
                <div class="stat-card stat-primary">
                    <div class="stat-icon"><span class="dashicons dashicons-groups"></span></div>
                    <div class="stat-content">
                        <h3><?php echo number_format($stats['total_users']); ?></h3>
                        <p><?php _e('Total Users', 'matrix-mlm'); ?></p>
                    </div>
                </div>
                <div class="stat-card stat-success">
                    <div class="stat-icon"><span class="dashicons dashicons-download"></span></div>
                    <div class="stat-content">
                        <h3><?php echo $currency . number_format($stats['total_deposits'], 2); ?></h3>
                        <p><?php _e('Total Deposits', 'matrix-mlm'); ?></p>
                    </div>
                </div>
                <div class="stat-card stat-warning">
                    <div class="stat-icon"><span class="dashicons dashicons-upload"></span></div>
                    <div class="stat-content">
                        <h3><?php echo $currency . number_format($stats['total_withdrawals'], 2); ?></h3>
                        <p><?php _e('Total Withdrawals', 'matrix-mlm'); ?></p>
                    </div>
                </div>
                <div class="stat-card stat-info">
                    <div class="stat-icon"><span class="dashicons dashicons-money-alt"></span></div>
                    <div class="stat-content">
                        <h3><?php echo $currency . number_format($stats['total_commissions'], 2); ?></h3>
                        <p><?php _e('Total Commissions', 'matrix-mlm'); ?></p>
                    </div>
                </div>
                <div class="stat-card stat-danger">
                    <div class="stat-icon"><span class="dashicons dashicons-clock"></span></div>
                    <div class="stat-content">
                        <h3><?php echo $stats['pending_withdrawals']; ?></h3>
                        <p><?php _e('Pending Withdrawals', 'matrix-mlm'); ?></p>
                    </div>
                </div>
                <div class="stat-card stat-purple">
                    <div class="stat-icon"><span class="dashicons dashicons-sos"></span></div>
                    <div class="stat-content">
                        <h3><?php echo $stats['open_tickets']; ?></h3>
                        <p><?php _e('Open Tickets', 'matrix-mlm'); ?></p>
                    </div>
                </div>
            </div>

            <div class="matrix-admin-tables">
                <div class="matrix-table-section">
                    <h2><?php _e('Recent Deposits', 'matrix-mlm'); ?></h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('User', 'matrix-mlm'); ?></th>
                                <th><?php _e('Gateway', 'matrix-mlm'); ?></th>
                                <th><?php _e('Amount', 'matrix-mlm'); ?></th>
                                <th><?php _e('Status', 'matrix-mlm'); ?></th>
                                <th><?php _e('Date', 'matrix-mlm'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_deposits as $deposit): ?>
                            <tr>
                                <td><?php echo esc_html($deposit->user_login); ?></td>
                                <td><?php echo esc_html(ucfirst($deposit->gateway)); ?></td>
                                <td><?php echo $currency . number_format($deposit->amount, 2); ?></td>
                                <td><span class="matrix-badge matrix-badge-<?php echo esc_attr($deposit->status); ?>"><?php echo esc_html(ucfirst($deposit->status)); ?></span></td>
                                <td><?php echo date('M d, Y H:i', strtotime($deposit->created_at)); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="matrix-table-section">
                    <h2><?php _e('Recent Withdrawals', 'matrix-mlm'); ?></h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('User', 'matrix-mlm'); ?></th>
                                <th><?php _e('Method', 'matrix-mlm'); ?></th>
                                <th><?php _e('Amount', 'matrix-mlm'); ?></th>
                                <th><?php _e('Status', 'matrix-mlm'); ?></th>
                                <th><?php _e('Date', 'matrix-mlm'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_withdrawals as $withdrawal): ?>
                            <tr>
                                <td><?php echo esc_html($withdrawal->user_login); ?></td>
                                <td><?php echo esc_html(ucfirst($withdrawal->method)); ?></td>
                                <td><?php echo $currency . number_format($withdrawal->amount, 2); ?></td>
                                <td><span class="matrix-badge matrix-badge-<?php echo esc_attr($withdrawal->status); ?>"><?php echo esc_html(ucfirst($withdrawal->status)); ?></span></td>
                                <td><?php echo date('M d, Y H:i', strtotime($withdrawal->created_at)); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render E-Pins page
     */
    public function render_epins() {
        $epin = new Matrix_MLM_Epin();

        if (isset($_POST['generate_epins']) && wp_verify_nonce($_POST['_wpnonce'], 'matrix_generate_epins')) {
            $plan_id = intval($_POST['plan_id']);
            $quantity = intval($_POST['quantity']);
            $result = $epin->generate($plan_id, $quantity, get_current_user_id());

            if ($result['success']) {
                echo '<div class="notice notice-success"><p>' . sprintf(__('%d E-Pins generated successfully!', 'matrix-mlm'), $result['count']) . '</p></div>';
            }
        }

        $pins = $epin->get_all_pins(null, 50, 0);
        $plan_engine = new Matrix_MLM_Plan_Engine();
        $plans = $plan_engine->get_plans('active');
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php _e('E-Pin Management', 'matrix-mlm'); ?></h1>

            <div class="matrix-admin-card">
                <h2><?php _e('Generate E-Pins', 'matrix-mlm'); ?></h2>
                <form method="post">
                    <?php wp_nonce_field('matrix_generate_epins'); ?>
                    <table class="form-table">
                        <tr>
                            <th><?php _e('Plan', 'matrix-mlm'); ?></th>
                            <td>
                                <select name="plan_id" required>
                                    <?php foreach ($plans as $plan): ?>
                                    <option value="<?php echo $plan->id; ?>"><?php echo esc_html($plan->name . ' - ' . get_option('matrix_mlm_currency_symbol', '₦') . number_format($plan->price, 2)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Quantity', 'matrix-mlm'); ?></th>
                            <td><input type="number" name="quantity" min="1" max="100" value="10" required></td>
                        </tr>
                    </table>
                    <p><input type="submit" name="generate_epins" class="button button-primary" value="<?php _e('Generate Pins', 'matrix-mlm'); ?>"></p>
                </form>
            </div>

            <div class="matrix-admin-card">
                <h2><?php _e('All E-Pins', 'matrix-mlm'); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Pin Code', 'matrix-mlm'); ?></th>
                            <th><?php _e('Plan', 'matrix-mlm'); ?></th>
                            <th><?php _e('Amount', 'matrix-mlm'); ?></th>
                            <th><?php _e('Created By', 'matrix-mlm'); ?></th>
                            <th><?php _e('Used By', 'matrix-mlm'); ?></th>
                            <th><?php _e('Status', 'matrix-mlm'); ?></th>
                            <th><?php _e('Created', 'matrix-mlm'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pins as $pin): ?>
                        <tr>
                            <td><code><?php echo esc_html($pin->pin_code); ?></code></td>
                            <td><?php echo esc_html($pin->plan_name); ?></td>
                            <td><?php echo get_option('matrix_mlm_currency_symbol', '₦') . number_format($pin->amount, 2); ?></td>
                            <td><?php echo esc_html($pin->created_by_username ?? 'Admin'); ?></td>
                            <td><?php echo esc_html($pin->used_by_username ?? '-'); ?></td>
                            <td><span class="matrix-badge matrix-badge-<?php echo $pin->status; ?>"><?php echo ucfirst($pin->status); ?></span></td>
                            <td><?php echo date('M d, Y', strtotime($pin->created_at)); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Handle admin AJAX actions
     */
    public function handle_admin_ajax() {
        check_ajax_referer('matrix_mlm_admin_nonce', 'nonce');

        if (!current_user_can('manage_matrix_mlm')) {
            wp_send_json_error(['message' => __('Unauthorized', 'matrix-mlm')]);
        }

        $action = sanitize_text_field($_POST['matrix_action'] ?? '');

        switch ($action) {
            case 'approve_withdrawal':
                $this->approve_withdrawal();
                break;
            case 'reject_withdrawal':
                $this->reject_withdrawal();
                break;
            case 'approve_deposit':
                $this->approve_deposit();
                break;
            case 'ban_user':
                $this->ban_user();
                break;
            case 'unban_user':
                $this->unban_user();
                break;
            case 'add_balance':
                $this->add_user_balance();
                break;
            case 'subtract_balance':
                $this->subtract_user_balance();
                break;
            default:
                wp_send_json_error(['message' => __('Invalid action', 'matrix-mlm')]);
        }
    }

    private function approve_withdrawal() {
        global $wpdb;
        $id = intval($_POST['id']);
        $wpdb->update($wpdb->prefix . 'matrix_withdrawals', ['status' => 'approved'], ['id' => $id]);
        
        $withdrawal = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}matrix_withdrawals WHERE id = %d", $id));
        if ($withdrawal) {
            Matrix_MLM_Notifications::send_withdrawal_notification($withdrawal->user_id, $withdrawal->amount, 'approved');
        }
        
        wp_send_json_success(['message' => __('Withdrawal approved', 'matrix-mlm')]);
    }

    private function reject_withdrawal() {
        global $wpdb;
        $id = intval($_POST['id']);
        $note = sanitize_textarea_field($_POST['note'] ?? '');

        $withdrawal = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}matrix_withdrawals WHERE id = %d", $id));
        if (!$withdrawal) {
            wp_send_json_error(['message' => __('Not found', 'matrix-mlm')]);
        }

        $wpdb->update($wpdb->prefix . 'matrix_withdrawals', ['status' => 'rejected', 'admin_note' => $note], ['id' => $id]);

        // Refund full amount + charge back to Matrix wallet
        $wallet = new Matrix_MLM_Wallet();
        $wallet->credit($withdrawal->user_id, $withdrawal->amount + $withdrawal->charge, 'withdrawal_refund', __('Withdrawal rejected - refund', 'matrix-mlm'));

        Matrix_MLM_Notifications::send_withdrawal_notification($withdrawal->user_id, $withdrawal->amount, 'rejected');
        wp_send_json_success(['message' => __('Withdrawal rejected and refunded to Matrix wallet', 'matrix-mlm')]);
    }

    private function approve_deposit() {
        global $wpdb;
        $id = intval($_POST['id']);
        
        $deposit = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}matrix_deposits WHERE id = %d", $id));
        if (!$deposit) {
            wp_send_json_error(['message' => __('Not found', 'matrix-mlm')]);
        }

        $wpdb->update($wpdb->prefix . 'matrix_deposits', ['status' => 'completed'], ['id' => $id]);

        $wallet = new Matrix_MLM_Wallet();
        $wallet->credit($deposit->user_id, $deposit->net_amount, 'deposit', __('Manual deposit approval', 'matrix-mlm'));

        Matrix_MLM_Notifications::send_deposit_notification($deposit->user_id, $deposit->amount, 'completed');
        wp_send_json_success(['message' => __('Deposit approved', 'matrix-mlm')]);
    }

    private function ban_user() {
        $user_id = intval($_POST['user_id']);
        Matrix_MLM_User::ban($user_id);
        wp_send_json_success(['message' => __('User banned', 'matrix-mlm')]);
    }

    private function unban_user() {
        $user_id = intval($_POST['user_id']);
        Matrix_MLM_User::unban($user_id);
        wp_send_json_success(['message' => __('User unbanned', 'matrix-mlm')]);
    }

    private function add_user_balance() {
        $user_id = intval($_POST['user_id']);
        $amount = floatval($_POST['amount']);
        $wallet = new Matrix_MLM_Wallet();
        $wallet->credit($user_id, $amount, 'admin_credit', __('Admin credit', 'matrix-mlm'));
        wp_send_json_success(['message' => __('Balance added', 'matrix-mlm')]);
    }

    private function subtract_user_balance() {
        $user_id = intval($_POST['user_id']);
        $amount = floatval($_POST['amount']);
        $wallet = new Matrix_MLM_Wallet();
        $result = $wallet->debit($user_id, $amount, 'admin_debit', __('Admin debit', 'matrix-mlm'));
        if ($result === false) {
            wp_send_json_error(['message' => __('Insufficient balance', 'matrix-mlm')]);
        }
        wp_send_json_success(['message' => __('Balance subtracted', 'matrix-mlm')]);
    }
}
