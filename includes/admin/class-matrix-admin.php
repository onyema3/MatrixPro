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
        global $wpdb;
        $epin = new Matrix_MLM_Epin();
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $generated_pins = null;

        // Handle export
        if (isset($_GET['export_epins']) && wp_verify_nonce($_GET['_wpnonce'], 'matrix_export_epins')) {
            $this->export_epins(sanitize_text_field($_GET['export_epins']));
            return;
        }

        // Handle generation
        if (isset($_POST['generate_epins']) && wp_verify_nonce($_POST['_wpnonce'], 'matrix_generate_epins')) {
            $plan_id = intval($_POST['plan_id']);
            $quantity = intval($_POST['quantity']);
            $custom_amount = floatval($_POST['custom_amount'] ?? 0);
            $expires_at = !empty($_POST['expires_at']) ? sanitize_text_field($_POST['expires_at']) : null;

            $result = $epin->generate($plan_id, $quantity, get_current_user_id(), $expires_at, $custom_amount);

            if ($result['success']) {
                $generated_pins = $result['pins'];
                echo '<div class="notice notice-success"><p>' . sprintf(__('%d E-Pins generated successfully! Amount: %s per pin.', 'matrix-mlm'), $result['count'], $currency . number_format($result['amount'], 2)) . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html($result['message']) . '</p></div>';
            }
        }

        // Filters
        $status_filter = isset($_GET['pin_status']) ? sanitize_text_field($_GET['pin_status']) : '';
        $pins = $epin->get_all_pins($status_filter ?: null, 100, 0);
        $plan_engine = new Matrix_MLM_Plan_Engine();
        $plans = $plan_engine->get_plans('active');

        // Stats
        $total_pins = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_epins");
        $unused_pins = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_epins WHERE status = 'unused'");
        $used_pins = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_epins WHERE status = 'used'");
        $expired_pins = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_epins WHERE status = 'expired'");
        $total_value = $wpdb->get_var("SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_epins WHERE status = 'unused'");
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php _e('E-Pin Management', 'matrix-mlm'); ?></h1>

            <!-- Stats -->
            <div class="matrix-admin-stats">
                <div class="stat-card stat-primary"><h3><?php echo number_format($total_pins); ?></h3><p><?php _e('Total Pins', 'matrix-mlm'); ?></p></div>
                <div class="stat-card stat-success"><h3><?php echo number_format($unused_pins); ?></h3><p><?php _e('Unused', 'matrix-mlm'); ?></p></div>
                <div class="stat-card stat-warning"><h3><?php echo number_format($used_pins); ?></h3><p><?php _e('Used', 'matrix-mlm'); ?></p></div>
                <div class="stat-card stat-danger"><h3><?php echo number_format($expired_pins); ?></h3><p><?php _e('Expired', 'matrix-mlm'); ?></p></div>
                <div class="stat-card stat-info"><h3><?php echo $currency . number_format($total_value, 2); ?></h3><p><?php _e('Unused Value', 'matrix-mlm'); ?></p></div>
            </div>

            <!-- Generate Form -->
            <div class="matrix-admin-card">
                <h2><?php _e('Generate E-Pins', 'matrix-mlm'); ?></h2>
                <form method="post">
                    <?php wp_nonce_field('matrix_generate_epins'); ?>
                    <table class="form-table">
                        <tr>
                            <th><?php _e('Amount Source', 'matrix-mlm'); ?></th>
                            <td>
                                <label style="display:block;margin-bottom:8px;"><input type="radio" name="amount_source" value="plan" checked onchange="document.getElementById('plan-select').style.display='block';document.getElementById('custom-amount').style.display='none';"> <?php _e('From Plan Price', 'matrix-mlm'); ?></label>
                                <label><input type="radio" name="amount_source" value="custom" onchange="document.getElementById('plan-select').style.display='none';document.getElementById('custom-amount').style.display='block';"> <?php _e('Custom Amount', 'matrix-mlm'); ?></label>
                            </td>
                        </tr>
                        <tr id="plan-select">
                            <th><?php _e('Select Plan', 'matrix-mlm'); ?></th>
                            <td>
                                <select name="plan_id">
                                    <?php foreach ($plans as $plan): ?>
                                    <option value="<?php echo $plan->id; ?>"><?php echo esc_html($plan->name . ' - ' . $currency . number_format($plan->price, 2)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr id="custom-amount" style="display:none;">
                            <th><?php _e('Pin Amount', 'matrix-mlm'); ?> (<?php echo $currency; ?>)</th>
                            <td><input type="number" name="custom_amount" step="0.01" min="1" value="1000" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><?php _e('Quantity', 'matrix-mlm'); ?></th>
                            <td>
                                <input type="number" name="quantity" min="1" max="500" value="10" required>
                                <p class="description"><?php _e('Maximum 500 pins per batch.', 'matrix-mlm'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Expiry Date (Optional)', 'matrix-mlm'); ?></th>
                            <td>
                                <input type="date" name="expires_at" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                                <p class="description"><?php _e('Leave empty for no expiration.', 'matrix-mlm'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p><input type="submit" name="generate_epins" class="button button-primary" value="<?php _e('Generate Pins', 'matrix-mlm'); ?>"></p>
                </form>
                <script>
                document.querySelector('[name="amount_source"][value="custom"]').addEventListener('change', function(){ document.querySelector('[name="plan_id"]').removeAttribute('name'); });
                document.querySelector('[name="amount_source"][value="plan"]').addEventListener('change', function(){ document.getElementById('plan-select').querySelector('select').setAttribute('name','plan_id'); });
                </script>
            </div>

            <?php if ($generated_pins): ?>
            <!-- Just Generated Pins -->
            <div class="matrix-admin-card" style="border-left:4px solid #059669;">
                <h2><?php _e('Newly Generated Pins', 'matrix-mlm'); ?></h2>
                <p><?php printf(__('%d pins generated at %s each.', 'matrix-mlm'), count($generated_pins), $currency . number_format($generated_pins[0]['amount'], 2)); ?></p>
                <div style="margin-bottom:12px;display:flex;gap:8px;">
                    <button class="button" onclick="matrixCopyPins()"><?php _e('Copy All to Clipboard', 'matrix-mlm'); ?></button>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=matrix-mlm-epins&export_epins=csv&batch=latest'), 'matrix_export_epins'); ?>" class="button">CSV</a>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=matrix-mlm-epins&export_epins=excel&batch=latest'), 'matrix_export_epins'); ?>" class="button">Excel</a>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=matrix-mlm-epins&export_epins=pdf&batch=latest'), 'matrix_export_epins'); ?>" class="button">PDF</a>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=matrix-mlm-epins&export_epins=json&batch=latest'), 'matrix_export_epins'); ?>" class="button">JSON</a>
                </div>
                <textarea id="generated-pins-text" rows="6" class="large-text code" readonly><?php foreach ($generated_pins as $p) { echo $p['pin_code'] . '  (' . $currency . number_format($p['amount'], 2) . ")\n"; } ?></textarea>
                <script>function matrixCopyPins(){var t=document.getElementById('generated-pins-text');t.select();document.execCommand('copy');alert('<?php _e('Copied!', 'matrix-mlm'); ?>');}</script>
            </div>
            <?php endif; ?>

            <!-- Export & Filter -->
            <div class="matrix-admin-card">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:15px;">
                    <h2 style="margin:0;"><?php _e('All E-Pins', 'matrix-mlm'); ?></h2>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <a href="<?php echo admin_url('admin.php?page=matrix-mlm-epins'); ?>" class="button <?php echo !$status_filter ? 'button-primary' : ''; ?>"><?php _e('All', 'matrix-mlm'); ?></a>
                        <a href="<?php echo admin_url('admin.php?page=matrix-mlm-epins&pin_status=unused'); ?>" class="button <?php echo $status_filter === 'unused' ? 'button-primary' : ''; ?>"><?php _e('Unused', 'matrix-mlm'); ?></a>
                        <a href="<?php echo admin_url('admin.php?page=matrix-mlm-epins&pin_status=used'); ?>" class="button <?php echo $status_filter === 'used' ? 'button-primary' : ''; ?>"><?php _e('Used', 'matrix-mlm'); ?></a>
                        <a href="<?php echo admin_url('admin.php?page=matrix-mlm-epins&pin_status=expired'); ?>" class="button <?php echo $status_filter === 'expired' ? 'button-primary' : ''; ?>"><?php _e('Expired', 'matrix-mlm'); ?></a>
                        <span>|</span>
                        <strong><?php _e('Export:', 'matrix-mlm'); ?></strong>
                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=matrix-mlm-epins&export_epins=csv&pin_status=' . $status_filter), 'matrix_export_epins'); ?>" class="button">CSV</a>
                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=matrix-mlm-epins&export_epins=excel&pin_status=' . $status_filter), 'matrix_export_epins'); ?>" class="button">Excel</a>
                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=matrix-mlm-epins&export_epins=pdf&pin_status=' . $status_filter), 'matrix_export_epins'); ?>" class="button">PDF</a>
                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=matrix-mlm-epins&export_epins=json&pin_status=' . $status_filter), 'matrix_export_epins'); ?>" class="button">JSON</a>
                    </div>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Pin Code', 'matrix-mlm'); ?></th>
                            <th><?php _e('Plan', 'matrix-mlm'); ?></th>
                            <th><?php _e('Amount', 'matrix-mlm'); ?></th>
                            <th><?php _e('Created By', 'matrix-mlm'); ?></th>
                            <th><?php _e('Used By', 'matrix-mlm'); ?></th>
                            <th><?php _e('Status', 'matrix-mlm'); ?></th>
                            <th><?php _e('Expires', 'matrix-mlm'); ?></th>
                            <th><?php _e('Created', 'matrix-mlm'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pins as $pin): ?>
                        <tr>
                            <td><code><?php echo esc_html($pin->pin_code); ?></code></td>
                            <td><?php echo esc_html($pin->plan_name ?? __('Custom', 'matrix-mlm')); ?></td>
                            <td><?php echo $currency . number_format($pin->amount, 2); ?></td>
                            <td><?php echo esc_html($pin->created_by_username ?? 'Admin'); ?></td>
                            <td><?php echo esc_html($pin->used_by_username ?? '-'); ?></td>
                            <td><span class="matrix-badge matrix-badge-<?php echo $pin->status; ?>"><?php echo ucfirst($pin->status); ?></span></td>
                            <td><?php echo $pin->expires_at ? date('M d, Y', strtotime($pin->expires_at)) : '-'; ?></td>
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
     * Export E-Pins
     */
    private function export_epins($format) {
        global $wpdb;
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $status_filter = sanitize_text_field($_GET['pin_status'] ?? '');
        $batch = sanitize_text_field($_GET['batch'] ?? '');

        $where = "WHERE 1=1";
        $params = [];

        if ($status_filter) {
            $where .= " AND e.status = %s";
            $params[] = $status_filter;
        }

        if ($batch === 'latest') {
            // Get the most recent batch (pins created in the last 60 seconds by current admin)
            $where .= " AND e.created_by = %d AND e.created_at >= DATE_SUB(NOW(), INTERVAL 60 SECOND)";
            $params[] = get_current_user_id();
        }

        $query = "SELECT e.pin_code, e.amount, COALESCE(p.name, 'Custom') as plan, e.status, COALESCE(u1.user_login, 'Admin') as created_by, COALESCE(u2.user_login, '-') as used_by, e.expires_at, e.created_at FROM {$wpdb->prefix}matrix_epins e LEFT JOIN {$wpdb->prefix}matrix_plans p ON e.plan_id = p.id LEFT JOIN {$wpdb->users} u1 ON e.created_by = u1.ID LEFT JOIN {$wpdb->users} u2 ON e.used_by = u2.ID $where ORDER BY e.created_at DESC";

        $data = !empty($params) ? $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A) : $wpdb->get_results($query, ARRAY_A);
        $filename = 'matrix-epins-' . ($status_filter ?: 'all') . '-' . date('Y-m-d');

        switch ($format) {
            case 'csv':
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
                $output = fopen('php://output', 'w');
                fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
                if (!empty($data)) { fputcsv($output, array_keys($data[0])); foreach ($data as $row) { fputcsv($output, $row); } }
                fclose($output);
                break;

            case 'excel':
                header('Content-Type: application/vnd.ms-excel');
                header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
                echo '<html><head><meta charset="UTF-8"></head><body><table border="1">';
                if (!empty($data)) {
                    echo '<tr>'; foreach (array_keys($data[0]) as $h) { echo '<th style="background:#4f46e5;color:#fff;padding:8px;">' . esc_html(ucwords(str_replace('_', ' ', $h))) . '</th>'; } echo '</tr>';
                    foreach ($data as $row) { echo '<tr>'; foreach ($row as $cell) { echo '<td style="padding:6px;">' . esc_html($cell) . '</td>'; } echo '</tr>'; }
                }
                echo '</table></body></html>';
                break;

            case 'pdf':
                header('Content-Type: text/html; charset=utf-8');
                $title = get_option('matrix_mlm_site_title', 'Matrix MLM Pro') . ' - E-Pins';
                echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . esc_html($title) . '</title><style>body{font-family:Arial,sans-serif;margin:20px;font-size:12px;}h1{font-size:18px;color:#4f46e5;}table{width:100%;border-collapse:collapse;margin-top:15px;}th{background:#4f46e5;color:#fff;padding:6px 8px;text-align:left;font-size:11px;}td{padding:5px 8px;border-bottom:1px solid #e5e7eb;font-size:11px;}tr:nth-child(even){background:#f9fafb;}.no-print{margin-bottom:15px;}@media print{.no-print{display:none;}}</style></head><body>';
                echo '<div class="no-print"><button onclick="window.print()" style="padding:8px 16px;background:#4f46e5;color:#fff;border:none;border-radius:4px;cursor:pointer;">Print / Save as PDF</button></div>';
                echo '<h1>' . esc_html($title) . '</h1><p style="color:#666;font-size:11px;">Generated: ' . date('F d, Y H:i:s') . ' | Pins: ' . count($data) . '</p>';
                if (!empty($data)) {
                    echo '<table><thead><tr>'; foreach (array_keys($data[0]) as $h) { echo '<th>' . esc_html(ucwords(str_replace('_', ' ', $h))) . '</th>'; } echo '</tr></thead><tbody>';
                    foreach ($data as $row) { echo '<tr>'; foreach ($row as $cell) { echo '<td>' . esc_html($cell) . '</td>'; } echo '</tr>'; }
                    echo '</tbody></table>';
                }
                echo '</body></html>';
                break;

            case 'json':
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '.json"');
                echo json_encode(['report' => 'epins', 'generated_at' => current_time('mysql'), 'total_pins' => count($data), 'filter' => $status_filter ?: 'all', 'data' => $data], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                break;
        }
        exit;
    }

    /**    /**
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

        // Refund only the charge to Matrix wallet (the payout amount was never debited from Matrix wallet)
        if ($withdrawal->charge > 0) {
            $wallet = new Matrix_MLM_Wallet();
            $wallet->credit($withdrawal->user_id, $withdrawal->charge, 'withdrawal_charge_refund', __('Withdrawal rejected - charge refund', 'matrix-mlm'));
        }

        Matrix_MLM_Notifications::send_withdrawal_notification($withdrawal->user_id, $withdrawal->amount, 'rejected');
        wp_send_json_success(['message' => __('Withdrawal rejected and charge refunded', 'matrix-mlm')]);
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
