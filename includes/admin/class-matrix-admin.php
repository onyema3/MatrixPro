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

        // Surface a one-click admin notice on the Matrix MLM Dashboard
        // when any Fintava virtual wallets still have NULL bank_code, so
        // operators get prompted to run the bulk backfill from the
        // migration page without having to navigate there first to
        // discover the count. Implemented as a static method on the
        // migration class — see its docblock for the why and the
        // gating rules (capability, page-slug scope, transient cache).
        add_action('admin_notices', ['Matrix_MLM_Admin_Migration', 'render_bank_code_admin_notice']);
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
        // Visual genealogy editor — sits right next to "Users"
        // because tree surgery is a per-user concern operationally,
        // and the typed-username "Move in Genealogy" form on each
        // user's edit page now links here as the recommended path.
        add_submenu_page('matrix-mlm', __('Genealogy', 'matrix-mlm'), __('Genealogy', 'matrix-mlm'), 'manage_matrix_users', 'matrix-mlm-genealogy', [new Matrix_MLM_Admin_Genealogy(), 'render']);
        // Genealogy Analytics — company-wide aggregate views
        // (orphan branches, stuck levels, top sponsors, dormant
        // subtrees) that the per-user Genealogy editor does not
        // surface. Sits directly next to its sibling so an
        // operator pivoting between "fix one user" and "scan the
        // whole tree" doesn't have to hunt for the other tab.
        // Capability is manage_matrix_mlm (the Dashboard cap)
        // because the page is read-only — operators who can
        // already see the dashboard stat tiles can see these
        // slices of the same data.
        add_submenu_page('matrix-mlm', __('Genealogy Analytics', 'matrix-mlm'), __('Tree Analytics', 'matrix-mlm'), 'manage_matrix_mlm', 'matrix-mlm-genealogy-analytics', [new Matrix_MLM_Admin_Genealogy_Analytics(), 'render']);
        add_submenu_page('matrix-mlm', __('Manage Plans', 'matrix-mlm'), __('Plans', 'matrix-mlm'), 'manage_matrix_plans', 'matrix-mlm-plans', [new Matrix_MLM_Admin_Plans(), 'render']);
        add_submenu_page('matrix-mlm', __('E-Pins', 'matrix-mlm'), __('E-Pins', 'matrix-mlm'), 'manage_matrix_mlm', 'matrix-mlm-epins', [$this, 'render_epins']);
        add_submenu_page('matrix-mlm', __('Payment Gateways', 'matrix-mlm'), __('Gateways', 'matrix-mlm'), 'manage_matrix_mlm', 'matrix-mlm-gateways', [new Matrix_MLM_Admin_Gateways(), 'render']);
        add_submenu_page('matrix-mlm', __('Deposits', 'matrix-mlm'), __('Deposits', 'matrix-mlm'), 'manage_matrix_deposits', 'matrix-mlm-deposits', [new Matrix_MLM_Admin_Deposits(), 'render']);
        add_submenu_page('matrix-mlm', __('Withdrawals', 'matrix-mlm'), __('Withdrawals', 'matrix-mlm'), 'manage_matrix_withdrawals', 'matrix-mlm-withdrawals', [new Matrix_MLM_Admin_Withdrawals(), 'render']);
        // Bill Payments History (Item D). Sits between Withdrawals
        // and Tickets so the three financial-ledger surfaces
        // (Deposits / Withdrawals / Bill Payments) are
        // visually adjacent. Page-level capability is
        // manage_matrix_mlm — the same reviewer tier as Reports —
        // so support staff can inspect the ledger; the per-row
        // Refund button inside the page is gated separately on
        // manage_options because issuing a refund moves money.
        add_submenu_page('matrix-mlm', __('Bill Payments History', 'matrix-mlm'), __('Bill Payments', 'matrix-mlm'), 'manage_matrix_mlm', 'matrix-mlm-billing-history', [new Matrix_MLM_Admin_Billing_History(), 'render']);
        add_submenu_page('matrix-mlm', __('Support Tickets', 'matrix-mlm'), __('Tickets', 'matrix-mlm'), 'manage_matrix_tickets', 'matrix-mlm-tickets', [new Matrix_MLM_Admin_Tickets(), 'render']);
        // Announcements — broadcast composer for in-app notifications
        // pushed to every member. Sits next to Tickets because the
        // two are the platform's outbound + inbound member-comms
        // surfaces (announcements are 1:N from staff, tickets are
        // 1:1 to staff). Capability is manage_matrix_settings —
        // sending a platform-wide blast is settings-grade, distinct
        // from the lower manage_matrix_tickets that staff need to
        // triage support, so a triage-only operator can't broadcast.
        add_submenu_page('matrix-mlm', __('Announcements', 'matrix-mlm'), __('Announcements', 'matrix-mlm'), 'manage_matrix_settings', 'matrix-mlm-announcements', [new Matrix_MLM_Admin_Announcements(), 'render']);
        add_submenu_page('matrix-mlm', __('Reports', 'matrix-mlm'), __('Reports', 'matrix-mlm'), 'manage_matrix_mlm', 'matrix-mlm-reports', [new Matrix_MLM_Admin_Reports(), 'render']);
        add_submenu_page('matrix-mlm', __('Frontend Manager', 'matrix-mlm'), __('Frontend', 'matrix-mlm'), 'manage_matrix_mlm', 'matrix-mlm-frontend', [new Matrix_MLM_Admin_Frontend(), 'render']);
        add_submenu_page('matrix-mlm', __('Migration', 'matrix-mlm'), __('Migration', 'matrix-mlm'), 'manage_matrix_mlm', 'matrix-mlm-migration', [new Matrix_MLM_Admin_Migration(), 'render']);
        add_submenu_page('matrix-mlm', __('Benefits', 'matrix-mlm'), __('Benefits', 'matrix-mlm'), 'manage_matrix_mlm', 'matrix-mlm-benefits', [new Matrix_MLM_Admin_Benefits(), 'render']);
        // CUG Requests sits next to Benefits because it's the
        // member-facing companion view: Benefits defines what
        // exists, CUG Requests triages who has applied.
        add_submenu_page('matrix-mlm', __('CUG Requests', 'matrix-mlm'), __('CUG Requests', 'matrix-mlm'), 'manage_matrix_mlm', 'matrix-mlm-cug', [new Matrix_MLM_Admin_CUG(), 'render']);
        // Loan Applications — sister page to CUG Requests for the
        // business-loan benefit. Same capability so an operator's
        // permissions never get out of sync between the two
        // member-application surfaces.
        add_submenu_page('matrix-mlm', __('Loan Applications', 'matrix-mlm'), __('Loans', 'matrix-mlm'), 'manage_matrix_mlm', 'matrix-mlm-loans', [new Matrix_MLM_Admin_Loans(), 'render']);
        // Healthcare Applications — sister page to CUG/Loans for the
        // HMO enrolment benefit. Same capability so reviewer
        // permissions stay in lockstep across all three application
        // surfaces.
        add_submenu_page('matrix-mlm', __('Healthcare Applications', 'matrix-mlm'), __('Healthcare', 'matrix-mlm'), 'manage_matrix_mlm', 'matrix-mlm-healthcare', [new Matrix_MLM_Admin_Healthcare(), 'render']);
        // Hospitals — admin-managed list that backs the "Choice of
        // Hospital" dropdown on the member-facing Healthcare form.
        // Sits next to Healthcare Applications because the two are
        // operationally paired: one defines the choices, the other
        // triages the resulting applications.
        add_submenu_page('matrix-mlm', __('Hospitals', 'matrix-mlm'), __('Hospitals', 'matrix-mlm'), 'manage_matrix_mlm', 'matrix-mlm-hospitals', [new Matrix_MLM_Admin_Hospitals(), 'render']);
        // Backup & Restore — gated on manage_matrix_settings (same
        // capability as Settings) because granting access to this
        // page is effectively granting full read/write of the
        // plugin's data and the ability to overwrite live tables
        // from an arbitrary uploaded SQL file. Sits next to Settings
        // intentionally — both are infrastructure surfaces operators
        // touch infrequently and shouldn't expose to lower-privileged
        // staff who only manage day-to-day deposits/withdrawals.
        add_submenu_page('matrix-mlm', __('Backup & Restore', 'matrix-mlm'), __('Backup', 'matrix-mlm'), 'manage_matrix_settings', 'matrix-mlm-backup', ['Matrix_MLM_Admin_Backup', 'render']);
        // Import from Laravel — one-time migration tool that reads a
        // ViserMLM-family SQL dump and rewrites it into MatrixPro's
        // schema (users + matrix_user_meta + matrix_positions, plus
        // plans and per-level commission JSON in PR 1). Same capability
        // as Backup because committing an import overwrites live user
        // and tree data on the destination.
        add_submenu_page('matrix-mlm', __('Import from Laravel', 'matrix-mlm'), __('Import', 'matrix-mlm'), 'manage_matrix_settings', 'matrix-mlm-import', ['Matrix_MLM_Admin_Import', 'render']);
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

        // Defense-in-depth (H8). The submenu is registered with the
        // 'manage_matrix_mlm' capability, so WordPress's menu router
        // already gates entry. Re-checking here protects against the
        // narrow class of bypasses where this method is invoked outside
        // the menu pipeline (custom admin_init handlers, tests, future
        // refactors that wire the page to a different surface).
        if (!current_user_can('manage_matrix_mlm')) {
            wp_die(__('Sorry, you are not allowed to access this page.', 'matrix-mlm'), 403);
        }

        // Handle export
        if (isset($_GET['export_epins']) && wp_verify_nonce($_GET['_wpnonce'], 'matrix_export_epins')) {
            // E-Pins are bearer tokens for wallet credit (H8). The
            // export carries unredeemed pin codes in cleartext, so it
            // is treated as a privileged financial action — same cap
            // tier as Backup/Settings/balance adjustments per PR #226.
            // The menu cap (manage_matrix_mlm, broad reviewer) is the
            // wrong gate here on its own; reviewer-tier admins should
            // be able to view/triage but not exfiltrate. The nonce
            // catches the CSRF case; this catches the
            // authenticated-but-undertier case.
            if (!current_user_can('manage_matrix_settings')) {
                wp_die(__('You do not have permission to export E-Pins.', 'matrix-mlm'), 403);
            }
            $this->export_epins(sanitize_text_field($_GET['export_epins']));
            return;
        }

        // Handle generation
        if (isset($_POST['generate_epins']) && wp_verify_nonce($_POST['_wpnonce'], 'matrix_generate_epins')) {
            // Generation mints new bearer tokens (H8). Same cap tier as
            // export for the same reason — every generated pin is
            // wallet credit waiting to happen, so a reviewer-tier admin
            // who can see deposits/withdrawals should not also be able
            // to issue free wallet credit.
            if (!current_user_can('manage_matrix_settings')) {
                wp_die(__('You do not have permission to generate E-Pins.', 'matrix-mlm'), 403);
            }
            $plan_id = intval($_POST['plan_id']);
            $quantity = intval($_POST['quantity']);
            $custom_amount = floatval($_POST['custom_amount'] ?? 0);
            $expires_at = !empty($_POST['expires_at']) ? sanitize_text_field($_POST['expires_at']) : null;

            $result = $epin->generate($plan_id, $quantity, get_current_user_id(), $expires_at, $custom_amount);

            if ($result['success']) {
                $generated_pins = $result['pins'];
                if (!empty($result['failed'])) {
                    echo '<div class="notice notice-warning"><p>' . sprintf(
                        /* translators: 1: count generated, 2: total attempted, 3: count failed, 4: opaque support reference, possibly empty */
                        __('%1$d of %2$d E-Pins generated. %3$d failed to save. %4$s', 'matrix-mlm'),
                        $result['count'],
                        $result['count'] + $result['failed'],
                        $result['failed'],
                        !empty($result['error_ref'])
                            ? sprintf(__('(Reference: %s)', 'matrix-mlm'), esc_html($result['error_ref']))
                            : ''
                    ) . '</p></div>';
                } else {
                    echo '<div class="notice notice-success"><p>' . sprintf(__('%d E-Pins generated successfully! Amount: %s per pin.', 'matrix-mlm'), $result['count'], $currency . number_format($result['amount'], 2)) . '</p></div>';
                }
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
        // Belt-and-braces (H8). The caller in render_epins() already
        // gates on manage_matrix_settings, but anchoring the check
        // here too means any future caller (REST endpoint, CLI hook,
        // direct invocation from an admin_init handler) cannot reach
        // the SELECT without proving authorisation. E-Pins are bearer
        // tokens for wallet credit and the export emits unredeemed
        // codes in cleartext.
        if (!current_user_can('manage_matrix_settings')) {
            wp_die(__('You do not have permission to export E-Pins.', 'matrix-mlm'), 403);
        }

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
            case 'zebra_capture_deposit':
                $this->zebra_capture_deposit();
                break;
            case 'zebra_cancel_deposit':
                $this->zebra_cancel_deposit();
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
            case 'update_user_profile':
                $this->update_user_profile();
                break;
            case 'change_user_password':
                $this->change_user_password();
                break;
            case 'backfill_referral_codes':
                $this->backfill_referral_codes();
                break;
            case 'move_user_position':
                $this->move_user_position();
                break;
            case 'preview_move_position':
                $this->preview_move_position();
                break;
            case 'link_fintava_account':
                $this->link_fintava_account();
                break;
            case 'fintava_lookup_wallet':
                $this->fintava_lookup_wallet();
                break;
            case 'fintava_backfill_wallet_ids':
                $this->fintava_backfill_wallet_ids();
                break;
            case 'fintava_backfill_bank_codes':
                $this->fintava_backfill_bank_codes();
                break;
            case 'update_fintava_wallet_bank':
                $this->update_fintava_wallet_bank();
                break;
            case 'repair_database_schema':
                $this->repair_database_schema();
                break;
            case 'reset_card':
                $this->reset_card();
                break;
            default:
                wp_send_json_error(['message' => __('Invalid action', 'matrix-mlm')]);
        }
    }

    private function approve_withdrawal() {
        global $wpdb;
        $id = intval($_POST['id']);
        if ($id <= 0) {
            wp_send_json_error(['message' => __('Invalid withdrawal id', 'matrix-mlm')]);
        }

        // Read once up front so we can dispatch by gateway. The
        // gateway column was added in 1.0.17 alongside the Zebra
        // /Remit wiring; a NULL value means "legacy row" and falls
        // through to the today's-behaviour Fintava-credit branch.
        $withdrawal = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_withdrawals WHERE id = %d",
            $id
        ));
        if (!$withdrawal) {
            wp_send_json_error(['message' => __('Withdrawal not found.', 'matrix-mlm')]);
        }

        $gateway = strtolower((string) ($withdrawal->gateway ?? ''));
        $note    = isset($_POST['note']) ? sanitize_textarea_field((string) $_POST['note']) : '';

        if ($gateway === 'zebra') {
            // Vendor -> customer payout via Bibimoney. The
            // Zebra gateway exposes two destination rails:
            //
            //   /Remit    — destination is the customer's Zebra
            //               Wallet (IWAN / MSISDN). method='zebra'.
            //   /Dispense — destination is the customer's bank
            //               account (account number + sortCode).
            //               method='zebra_bank' AND
            //               account_details is a JSON envelope
            //               with the bank routing fields.
            //
            // Both flows share the same pending -> processing ->
            // approved (or pending) lock state machine. The
            // gateway class owns the conditional-UPDATE locks
            // that prevent two simultaneous Approve clicks from
            // double-firing the platform call. We just forward
            // the result envelope.
            if (!class_exists('Matrix_MLM_Zebra')) {
                wp_send_json_error(['message' => __('Zebra gateway not available.', 'matrix-mlm')]);
            }
            $zebra  = new Matrix_MLM_Zebra();
            $method = strtolower((string) ($withdrawal->method ?? ''));
            if ($method === 'zebra_bank') {
                $result = $zebra->dispense_to_account($id, $note);
                $rail_label = __('Dispense', 'matrix-mlm');
            } else {
                $result = $zebra->remit_to_account($id, $note);
                $rail_label = __('Remit', 'matrix-mlm');
            }
            if (!empty($result['success'])) {
                wp_send_json_success([
                    'message'        => $result['message'] ?? sprintf(
                        /* translators: %s = "Remit" or "Dispense" depending on the rail */
                        __('%s completed.', 'matrix-mlm'),
                        $rail_label
                    ),
                    'transaction_id' => $result['transaction_id'] ?? '',
                    'psp_reference'  => $result['psp_reference'] ?? '',
                ]);
            }
            wp_send_json_error([
                'message' => $result['message'] ?? sprintf(
                    /* translators: %s = "Remit" or "Dispense" depending on the rail */
                    __('%s failed.', 'matrix-mlm'),
                    $rail_label
                ),
            ]);
        }

        // Legacy Fintava-credit branch (gateway IS NULL or any
        // non-zebra value). Behaviour is unchanged from before
        // 1.0.17: conditional UPDATE pending -> approved, then
        // credit the user's Fintava virtual wallet.
        //
        // Conditional UPDATE: only the first request that flips
        // pending -> approved wins. A second click (same admin or
        // two admins on the page) sees affected_rows == 0 and
        // bails out. This replaces the previous pattern of
        // unconditional UPDATE + SELECT, which could double-
        // credit when raced.
        $rows = $wpdb->update(
            $wpdb->prefix . 'matrix_withdrawals',
            ['status' => 'approved'],
            ['id' => $id, 'status' => 'pending']
        );
        if ($rows !== 1) {
            wp_send_json_error(['message' => __('This withdrawal has already been processed.', 'matrix-mlm')]);
        }

        // Now we own the transition. Re-read for the side-effects
        // (the row may have been mutated by the UPDATE above).
        $withdrawal = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}matrix_withdrawals WHERE id = %d", $id));
        if ($withdrawal) {
            // Credit the withdrawal amount to user's Fintava wallet
            $fintava = new Matrix_MLM_Fintava();
            $fintava->credit_wallet($withdrawal->user_id, $withdrawal->amount, 'withdrawal_approved', sprintf(__('Withdrawal approved: %s%s credited to Fintava wallet', 'matrix-mlm'), get_option('matrix_mlm_currency_symbol', '₦'), number_format($withdrawal->amount, 2)));

            Matrix_MLM_Notifications::send_withdrawal_notification($withdrawal->user_id, $withdrawal->amount, 'approved');
        }

        wp_send_json_success(['message' => __('Withdrawal approved and credited to Fintava wallet', 'matrix-mlm')]);
    }

    private function reject_withdrawal() {
        global $wpdb;
        $id = intval($_POST['id']);
        $note = sanitize_textarea_field($_POST['note'] ?? '');
        if ($id <= 0) {
            wp_send_json_error(['message' => __('Invalid withdrawal id', 'matrix-mlm')]);
        }

        // Read first to capture the user_id / amount / charge for the
        // refund; the conditional UPDATE below confirms we won the race
        // before the wallet credit lands.
        //
        // Source status accepts BOTH 'pending' (the normal reject
        // path) and 'processing' (the escape hatch for a Zebra
        // /Remit row that's stuck mid-flight — e.g. the platform
        // returned a transient 5xx and the operator wants to
        // reject + refund rather than retry). The legacy fast-
        // path-pending UPDATE wouldn't have matched a processing
        // row before 1.0.17, but processing is new in 1.0.17 so
        // there's no behaviour drift for legacy installs.
        $withdrawal = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}matrix_withdrawals WHERE id = %d", $id));
        if (!$withdrawal) {
            wp_send_json_error(['message' => __('Not found', 'matrix-mlm')]);
        }
        if ($withdrawal->status !== 'pending' && $withdrawal->status !== 'processing') {
            wp_send_json_error(['message' => __('This withdrawal cannot be rejected (already finalised).', 'matrix-mlm')]);
        }

        $rows = $wpdb->update(
            $wpdb->prefix . 'matrix_withdrawals',
            ['status' => 'rejected', 'admin_note' => $note],
            ['id' => $id, 'status' => $withdrawal->status]
        );
        if ($rows !== 1) {
            wp_send_json_error(['message' => __('This withdrawal has already been processed.', 'matrix-mlm')]);
        }

        // We own the transition. Refund full amount + charge back to
        // Matrix wallet. A reference tied to the withdrawal id makes
        // the credit row unique so a hypothetical retry can't credit
        // twice.
        $wallet = new Matrix_MLM_Wallet();
        $wallet->credit(
            $withdrawal->user_id,
            $withdrawal->amount + $withdrawal->charge,
            'withdrawal_refund',
            __('Withdrawal rejected - refund', 'matrix-mlm'),
            'WD-REFUND-' . $withdrawal->id
        );

        Matrix_MLM_Notifications::send_withdrawal_notification($withdrawal->user_id, $withdrawal->amount, 'rejected');
        wp_send_json_success(['message' => __('Withdrawal rejected and refunded to Matrix wallet', 'matrix-mlm')]);
    }

    private function approve_deposit() {
        global $wpdb;
        $id = intval($_POST['id']);
        if ($id <= 0) {
            wp_send_json_error(['message' => __('Invalid deposit id', 'matrix-mlm')]);
        }

        $deposit = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}matrix_deposits WHERE id = %d", $id));
        if (!$deposit) {
            wp_send_json_error(['message' => __('Not found', 'matrix-mlm')]);
        }

        // Conditional UPDATE: pending -> completed only once. A second
        // click (or a webhook completing the same row concurrently)
        // gets affected_rows == 0 and is rejected before the credit
        // lands.
        $rows = $wpdb->update(
            $wpdb->prefix . 'matrix_deposits',
            ['status' => 'completed'],
            ['id' => $id, 'status' => 'pending']
        );
        if ($rows !== 1) {
            wp_send_json_error(['message' => __('This deposit has already been processed.', 'matrix-mlm')]);
        }

        $wallet = new Matrix_MLM_Wallet();
        $wallet->credit(
            $deposit->user_id,
            $deposit->net_amount,
            'deposit',
            __('Manual deposit approval', 'matrix-mlm'),
            'DEP-MANUAL-' . $deposit->id
        );

        Matrix_MLM_Notifications::send_deposit_notification($deposit->user_id, $deposit->amount, 'completed');
        wp_send_json_success(['message' => __('Deposit approved', 'matrix-mlm')]);
    }

    /**
     * Capture a held Zebra Wallet (Bibimoney) pre-auth deposit.
     *
     * Flow: admin clicks Capture on a row with status='pending_capture'
     * and gateway='zebra'. We forward to Matrix_MLM_Zebra::capture_deposit()
     * which posts /CaptureOrCancel with EventType=CAPTURE, then on
     * success transitions pending_capture -> completed (idempotent
     * conditional UPDATE) and credits the Matrix wallet via the
     * shared finaliser. The matching CAPTURE IPN will arrive after,
     * and the same conditional UPDATE means it's a no-op.
     *
     * The capability is gated by manage_matrix_deposits at the
     * page-registration level (Matrix_MLM_Admin::add_admin_menus
     * uses that cap on the Deposits submenu) and the
     * matrix_admin_action nonce is verified at the dispatch
     * boundary in handle_admin_ajax(). We also re-check
     * manage_matrix_deposits here as defense in depth so a stray
     * AJAX caller from a lower-cap operator can't slip through.
     */
    private function zebra_capture_deposit() {
        if (!current_user_can('manage_matrix_deposits')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'matrix-mlm')]);
        }
        $id   = (int) ($_POST['id'] ?? 0);
        $note = isset($_POST['note']) ? sanitize_textarea_field((string) $_POST['note']) : '';
        if ($id <= 0) {
            wp_send_json_error(['message' => __('Invalid deposit id', 'matrix-mlm')]);
        }
        if (!class_exists('Matrix_MLM_Zebra')) {
            wp_send_json_error(['message' => __('Zebra gateway not available.', 'matrix-mlm')]);
        }
        $zebra  = new Matrix_MLM_Zebra();
        $result = $zebra->capture_deposit($id, $note);
        if (!empty($result['success'])) {
            wp_send_json_success(['message' => $result['message'] ?? __('Capture completed.', 'matrix-mlm')]);
        }
        wp_send_json_error(['message' => $result['message'] ?? __('Capture failed.', 'matrix-mlm')]);
    }

    /**
     * Cancel a held Zebra Wallet (Bibimoney) pre-auth deposit.
     *
     * Mirror of zebra_capture_deposit(). On success, transitions
     * pending_capture -> cancelled. No Matrix wallet credit, no
     * refund — the customer was never credited Matrix-side, and
     * the platform releases the hold back to the customer's
     * Zebra wallet on /CaptureOrCancel success.
     */
    private function zebra_cancel_deposit() {
        if (!current_user_can('manage_matrix_deposits')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'matrix-mlm')]);
        }
        $id   = (int) ($_POST['id'] ?? 0);
        $note = isset($_POST['note']) ? sanitize_textarea_field((string) $_POST['note']) : '';
        if ($id <= 0) {
            wp_send_json_error(['message' => __('Invalid deposit id', 'matrix-mlm')]);
        }
        if (!class_exists('Matrix_MLM_Zebra')) {
            wp_send_json_error(['message' => __('Zebra gateway not available.', 'matrix-mlm')]);
        }
        $zebra  = new Matrix_MLM_Zebra();
        $result = $zebra->cancel_deposit($id, $note);
        if (!empty($result['success'])) {
            wp_send_json_success(['message' => $result['message'] ?? __('Cancel completed.', 'matrix-mlm')]);
        }
        wp_send_json_error(['message' => $result['message'] ?? __('Cancel failed.', 'matrix-mlm')]);
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

    /**
     * Admin: directly credit a user's wallet.
     *
     * Hardened in audit fix C9:
     *   - Re-checks the manage_matrix_settings capability (not just the
     *     broader manage_matrix_mlm gate on the dispatcher), since
     *     direct balance manipulation belongs alongside Settings/Backup
     *     and not alongside day-to-day deposit/withdrawal review.
     *   - Refuses non-positive amounts (a negative or zero "credit"
     *     was previously a silent debit-via-credit path).
     *   - Caps the per-call amount at a configurable ceiling so a
     *     compromised admin session can't drain unlimited float in one
     *     click.
     *   - Records the acting admin's user id in the wallet description
     *     and includes a stable reference (ADMIN-CREDIT-<actor>-<target>-<ts>)
     *     so the matrix_wallet auditor row carries the actor identity
     *     and is straightforward to reconcile.
     */
    private function add_user_balance() {
        if (!current_user_can('manage_matrix_settings')) {
            wp_send_json_error(['message' => __('You do not have permission to adjust user balances.', 'matrix-mlm')]);
        }

        $user_id = intval($_POST['user_id'] ?? 0);
        $amount = floatval($_POST['amount'] ?? 0);
        if ($user_id <= 0) {
            wp_send_json_error(['message' => __('Invalid user', 'matrix-mlm')]);
        }
        if ($amount <= 0) {
            wp_send_json_error(['message' => __('Amount must be greater than zero.', 'matrix-mlm')]);
        }

        $cap = floatval(get_option('matrix_mlm_admin_balance_adjust_max', 1000000));
        if ($cap > 0 && $amount > $cap) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s: max allowed amount */
                    __('Amount exceeds the per-action limit (%s).', 'matrix-mlm'),
                    number_format($cap, 2)
                ),
            ]);
        }

        $actor_id = get_current_user_id();
        $actor = get_userdata($actor_id);
        $actor_login = $actor ? $actor->user_login : 'admin';
        $reference = sprintf('ADMIN-CREDIT-%d-%d-%d', $actor_id, $user_id, time());
        $description = sprintf(
            /* translators: 1: admin login, 2: admin user id */
            __('Admin credit by %1$s (#%2$d)', 'matrix-mlm'),
            $actor_login,
            $actor_id
        );

        $wallet = new Matrix_MLM_Wallet();
        $result = $wallet->credit($user_id, $amount, 'admin_credit', $description, $reference);
        if ($result === false) {
            wp_send_json_error(['message' => __('Could not credit balance. Please try again.', 'matrix-mlm')]);
        }

        wp_send_json_success(['message' => __('Balance added', 'matrix-mlm')]);
    }

    /**
     * Admin: directly debit a user's wallet. Same hardening as
     * add_user_balance — see that method's docblock.
     */
    private function subtract_user_balance() {
        if (!current_user_can('manage_matrix_settings')) {
            wp_send_json_error(['message' => __('You do not have permission to adjust user balances.', 'matrix-mlm')]);
        }

        $user_id = intval($_POST['user_id'] ?? 0);
        $amount = floatval($_POST['amount'] ?? 0);
        if ($user_id <= 0) {
            wp_send_json_error(['message' => __('Invalid user', 'matrix-mlm')]);
        }
        if ($amount <= 0) {
            wp_send_json_error(['message' => __('Amount must be greater than zero.', 'matrix-mlm')]);
        }

        $cap = floatval(get_option('matrix_mlm_admin_balance_adjust_max', 1000000));
        if ($cap > 0 && $amount > $cap) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s: max allowed amount */
                    __('Amount exceeds the per-action limit (%s).', 'matrix-mlm'),
                    number_format($cap, 2)
                ),
            ]);
        }

        $actor_id = get_current_user_id();
        $actor = get_userdata($actor_id);
        $actor_login = $actor ? $actor->user_login : 'admin';
        $reference = sprintf('ADMIN-DEBIT-%d-%d-%d', $actor_id, $user_id, time());
        $description = sprintf(
            /* translators: 1: admin login, 2: admin user id */
            __('Admin debit by %1$s (#%2$d)', 'matrix-mlm'),
            $actor_login,
            $actor_id
        );

        $wallet = new Matrix_MLM_Wallet();
        $result = $wallet->debit($user_id, $amount, 'admin_debit', $description, $reference);
        if ($result === false) {
            wp_send_json_error(['message' => __('Insufficient balance', 'matrix-mlm')]);
        }
        wp_send_json_success(['message' => __('Balance subtracted', 'matrix-mlm')]);
    }

    private function update_user_profile() {
        $user_id = intval($_POST['user_id'] ?? 0);
        if (!$user_id) {
            wp_send_json_error(['message' => __('Invalid user', 'matrix-mlm')]);
        }

        $user = get_userdata($user_id);
        if (!$user) {
            wp_send_json_error(['message' => __('User not found', 'matrix-mlm')]);
        }

        // Authorization: mirror the same gates change_user_password uses.
        // The dispatcher's manage_matrix_mlm cap is too broad — it lets a
        // deposit/withdrawal-reviewer admin rewrite any user's email,
        // including the super admin's, which combined with WordPress
        // password reset is a full account-takeover path (audit C8).
        //
        //   1. edit_user — same gate WordPress core uses on the user-edit
        //      screen. Refuses to let a low-privileged admin edit a user
        //      whose role they don't have permission for.
        //   2. Self-edit — admins should change their own profile from
        //      the standard WP profile screen so the standard re-auth
        //      flow runs.
        //   3. On multisite, refuse to let a non-super admin edit a
        //      super admin.
        if ($user_id === get_current_user_id()) {
            wp_send_json_error(['message' => __('Use your WordPress profile page to edit your own account.', 'matrix-mlm')]);
        }
        if (is_multisite() && is_super_admin($user_id) && !is_super_admin()) {
            wp_send_json_error(['message' => __('You do not have permission to edit a super admin\'s profile.', 'matrix-mlm')]);
        }
        if (!current_user_can('edit_user', $user_id)) {
            wp_send_json_error(['message' => __('You do not have permission to edit this user.', 'matrix-mlm')]);
        }

        // Update WordPress user fields
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');

        if ($first_name) update_user_meta($user_id, 'first_name', $first_name);
        if ($last_name) update_user_meta($user_id, 'last_name', $last_name);

        // Update email if changed
        if ($email && $email !== $user->user_email) {
            if (email_exists($email) && email_exists($email) !== $user_id) {
                wp_send_json_error(['message' => __('Email already in use by another user', 'matrix-mlm')]);
            }
            wp_update_user(['ID' => $user_id, 'user_email' => $email]);
        }

        // Update matrix_user_meta fields
        global $wpdb;
        $meta_data = [
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'address' => sanitize_textarea_field($_POST['address'] ?? ''),
            'city' => sanitize_text_field($_POST['city'] ?? ''),
            'state' => sanitize_text_field($_POST['state'] ?? ''),
            'country' => sanitize_text_field($_POST['country'] ?? ''),
            'zip_code' => sanitize_text_field($_POST['zip_code'] ?? ''),
        ];

        // Update status if provided
        $status = sanitize_text_field($_POST['status'] ?? '');
        if ($status && in_array($status, ['active', 'inactive', 'banned'])) {
            $meta_data['status'] = $status;
        }

        $wpdb->update($wpdb->prefix . 'matrix_user_meta', $meta_data, ['user_id' => $user_id]);

        wp_send_json_success(['message' => __('User profile updated successfully', 'matrix-mlm')]);
    }

    /**
     * Admin: change a user's password
     *
     * Sets a new password on the WordPress account, ends all of the
     * target user's existing login sessions so the old password can no
     * longer be used, and (optionally) sends them the standard
     * "password changed" confirmation email.
     *
     * Guard rails:
     *   - Caller must already have manage_matrix_mlm (checked at the
     *     dispatcher in handle_admin_ajax).
     *   - Refuses to operate on accounts that out-rank the current
     *     admin, so a non-super admin can't quietly reset a super
     *     admin's password.
     *   - Minimum length 8, must match the confirm field.
     */
    private function change_user_password() {
        $user_id = intval($_POST['user_id'] ?? 0);
        if (!$user_id) {
            wp_send_json_error(['message' => __('Invalid user', 'matrix-mlm')]);
        }

        $user = get_userdata($user_id);
        if (!$user) {
            wp_send_json_error(['message' => __('User not found', 'matrix-mlm')]);
        }

        // Don't allow editing yourself through this admin tool —
        // admins should change their own password through the normal
        // WP profile screen so the standard re-auth flow runs.
        if ($user_id === get_current_user_id()) {
            wp_send_json_error(['message' => __('Use your WordPress profile page to change your own password.', 'matrix-mlm')]);
        }

        // Block lowering a super admin's password from a non-super
        // admin account on multisite.
        if (is_multisite() && is_super_admin($user_id) && !is_super_admin()) {
            wp_send_json_error(['message' => __('You do not have permission to change a super admin\'s password.', 'matrix-mlm')]);
        }

        // On single site, block changing the password of any user
        // whose role we don't have edit_user permission for. This is
        // the same gate WordPress core uses on the user-edit screen.
        if (!current_user_can('edit_user', $user_id)) {
            wp_send_json_error(['message' => __('You do not have permission to edit this user.', 'matrix-mlm')]);
        }

        // Read raw — passwords MUST NOT be sanitized through
        // sanitize_text_field, which strips characters and silently
        // mutates what the admin typed.
        $new_password     = isset($_POST['new_password']) ? (string) wp_unslash($_POST['new_password']) : '';
        $confirm_password = isset($_POST['confirm_password']) ? (string) wp_unslash($_POST['confirm_password']) : '';
        $notify           = !empty($_POST['notify']);

        if (strlen($new_password) < 8) {
            wp_send_json_error(['message' => __('Password must be at least 8 characters long.', 'matrix-mlm')]);
        }
        if ($new_password !== $confirm_password) {
            wp_send_json_error(['message' => __('Passwords do not match.', 'matrix-mlm')]);
        }

        // wp_set_password() hashes the password, writes it, AND clears
        // the user's auth cookies / session tokens — which is exactly
        // what we want when an admin force-resets a password.
        wp_set_password($new_password, $user_id);

        // wp_set_password destroys sessions for the target user, but
        // be explicit so this still holds if WP changes that behavior.
        $sessions = WP_Session_Tokens::get_instance($user_id);
        $sessions->destroy_all();

        if ($notify && class_exists('Matrix_MLM_Notifications')) {
            Matrix_MLM_Notifications::send_password_changed_email($user_id);
        }

        wp_send_json_success([
            'message' => $notify
                ? __('Password changed and the user has been notified by email.', 'matrix-mlm')
                : __('Password changed.', 'matrix-mlm'),
        ]);
    }

    /**
     * Backfill missing referral codes across matrix_user_meta.
     *
     * The Laravel importer historically wrote `referral_code = NULL`
     * with a "generated lazily by plan engine" comment, but no lazy
     * generator was ever wired up — so every imported member showed
     * an empty Referral Code in the admin Manage Users list and
     * couldn't share a working referral link until they re-registered.
     *
     * This method finds every row where referral_code is NULL or
     * empty and assigns a freshly generated unique code via the
     * shared helper Matrix_MLM_User::generate_unique_referral_code().
     * The helper retries on collision against the UNIQUE index, so
     * the loop is safe to run in bulk.
     *
     * Returns a structured report (processed / updated / failed +
     * a per-row table) so the UI can show what happened, mirroring
     * the existing wallet-id and bank-code backfill tools on the
     * same Migration page.
     */
    private function backfill_referral_codes() {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_user_meta';

        // A long bulk loop on a 10k+ user dataset can run past the
        // default 30s timeout on shared hosting. 180s gives roughly
        // a 100x margin over what this UPDATE-only loop actually
        // needs, but matches the budgets used by the sister backfill
        // tools on the same admin page.
        @set_time_limit(180);

        $rows = $wpdb->get_results(
            "SELECT user_id FROM {$table}
              WHERE referral_code IS NULL OR referral_code = ''
              ORDER BY user_id ASC"
        );

        $total     = count($rows);
        $updated   = 0;
        $failed    = 0;
        $details   = [];

        foreach ($rows as $row) {
            $user_id = (int) $row->user_id;
            $code    = Matrix_MLM_User::generate_unique_referral_code($user_id);

            $result = $wpdb->update(
                $table,
                ['referral_code' => $code],
                ['user_id'       => $user_id],
                ['%s'],
                ['%d']
            );

            if ($result === false) {
                $failed++;
                $details[] = [
                    'user_id' => $user_id,
                    'code'    => '',
                    'status'  => 'failed',
                    'reason'  => $wpdb->last_error ?: __('Unknown DB error', 'matrix-mlm'),
                ];
                continue;
            }

            $updated++;
            $details[] = [
                'user_id' => $user_id,
                'code'    => $code,
                'status'  => 'ok',
                'reason'  => '',
            ];
        }

        wp_send_json_success([
            'message' => sprintf(
                /* translators: 1: rows updated, 2: rows that failed, 3: total rows processed */
                __('Referral-code backfill complete: %1$d updated, %2$d failed (out of %3$d rows missing a code).', 'matrix-mlm'),
                $updated,
                $failed,
                $total
            ),
            'report'  => [
                'total_missing_before' => $total,
                'updated'              => $updated,
                'failed'               => $failed,
                'details'              => $details,
            ],
        ]);
    }

    /**
     * Drag-and-drop preview endpoint for the visual genealogy
     * editor.
     *
     * Used by the admin Genealogy page (see
     * Matrix_MLM_Admin_Genealogy::render_inline_script): when the
     * operator drops a node onto another node, the page calls this
     * action to compute the impact summary the confirmation modal
     * displays before they commit. Validation here mirrors
     * move_user_position() exactly so the modal can never
     * green-light a move that the commit would then reject.
     *
     * Read-only — does not mutate any matrix_* tables. The actual
     * commit still flows through move_user_position() once the
     * operator confirms.
     */
    private function preview_move_position() {
        // Class file is only required inside is_admin(); we're in
        // an AJAX request here so it's loaded. Guard defensively
        // anyway — cheaper than debugging a fatal six months from
        // now if someone reorganises the loader.
        if (!class_exists('Matrix_MLM_Admin_Genealogy')) {
            require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-genealogy.php';
        }

        $position_id            = isset($_POST['position_id']) ? (int) $_POST['position_id'] : 0;
        $new_parent_position_id = isset($_POST['new_parent_position_id']) ? (int) $_POST['new_parent_position_id'] : 0;

        $payload = Matrix_MLM_Admin_Genealogy::build_move_preview(
            $position_id,
            $new_parent_position_id
        );
        wp_send_json_success($payload);
    }

    /**
     * Move a user to a new position in the genealogy tree
     */
    private function move_user_position() {
        global $wpdb;

        $position_id = intval($_POST['position_id'] ?? 0);
        $new_parent_username = sanitize_text_field($_POST['new_parent_username'] ?? '');
        $new_sponsor_username = sanitize_text_field($_POST['new_sponsor_username'] ?? '');

        if (!$position_id) {
            wp_send_json_error(['message' => __('Position ID is required', 'matrix-mlm')]);
        }

        // Get the position being moved
        $position = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_positions WHERE id = %d",
            $position_id
        ));

        if (!$position) {
            wp_send_json_error(['message' => __('Position not found', 'matrix-mlm')]);
        }

        $plan_id = $position->plan_id;
        $plan = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}matrix_plans WHERE id = %d", $plan_id));

        // Handle parent change (tree position)
        if (!empty($new_parent_username)) {
            $new_parent_user = get_user_by('login', $new_parent_username);
            if (!$new_parent_user) {
                wp_send_json_error(['message' => __('New parent user not found', 'matrix-mlm')]);
            }

            // Cannot move under self or own descendant
            if ($new_parent_user->ID == $position->user_id) {
                wp_send_json_error(['message' => __('Cannot place a user under themselves', 'matrix-mlm')]);
            }

            // Get new parent's position in this plan
            $new_parent_position = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}matrix_positions WHERE user_id = %d AND plan_id = %d AND status = 'active'",
                $new_parent_user->ID, $plan_id
            ));

            if (!$new_parent_position) {
                wp_send_json_error(['message' => __('New parent does not have an active position in this plan', 'matrix-mlm')]);
            }

            // Check if new parent already has max children (width limit)
            $child_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}matrix_positions WHERE parent_id = %d AND plan_id = %d AND id != %d",
                $new_parent_position->id, $plan_id, $position_id
            ));

            if ($child_count >= $plan->width) {
                wp_send_json_error(['message' => sprintf(__('New parent already has %d children (max width: %d). Remove a child first or choose a different parent.', 'matrix-mlm'), $child_count, $plan->width)]);
            }

            // Check this won't create a circular reference (moving under own descendant)
            if ($this->is_descendant_of($new_parent_position->id, $position_id, $plan_id)) {
                wp_send_json_error(['message' => __('Cannot move a user under their own descendant. This would create a circular reference.', 'matrix-mlm')]);
            }

            // Store old parent for count update
            $old_parent_id = $position->parent_id;

            // Calculate new level
            $new_level = $new_parent_position->level + 1;

            // Perform the move
            $wpdb->update(
                $wpdb->prefix . 'matrix_positions',
                [
                    'parent_id' => $new_parent_position->id,
                    'position' => $child_count, // Place at next available slot
                    'level' => $new_level,
                ],
                ['id' => $position_id]
            );

            // Update levels of all descendants recursively
            $this->recalculate_descendant_levels($position_id, $new_level, $plan_id);

            // Recalculate downline counts for old parent (subtract)
            if ($old_parent_id) {
                $this->recalculate_upline_counts($old_parent_id, $plan_id);
            }

            // Recalculate downline counts for new parent (add)
            $this->recalculate_upline_counts($new_parent_position->id, $plan_id);
        }

        // Handle sponsor change
        if (!empty($new_sponsor_username)) {
            $new_sponsor_user = get_user_by('login', $new_sponsor_username);
            if (!$new_sponsor_user) {
                wp_send_json_error(['message' => __('New sponsor user not found', 'matrix-mlm')]);
            }

            if ($new_sponsor_user->ID == $position->user_id) {
                wp_send_json_error(['message' => __('Cannot set user as their own sponsor', 'matrix-mlm')]);
            }

            $wpdb->update(
                $wpdb->prefix . 'matrix_positions',
                ['sponsor_id' => $new_sponsor_user->ID],
                ['id' => $position_id]
            );

            // Also update the referred_by in matrix_user_meta
            $wpdb->update(
                $wpdb->prefix . 'matrix_user_meta',
                ['referred_by' => $new_sponsor_user->ID],
                ['user_id' => $position->user_id]
            );
        }

        wp_send_json_success(['message' => __('User position updated successfully in the genealogy tree', 'matrix-mlm')]);
    }

    /**
     * Check if a position is a descendant of another position
     */
    private function is_descendant_of($check_id, $ancestor_id, $plan_id) {
        global $wpdb;
        $current_id = $check_id;
        $max_depth = 50; // Safety limit

        while ($current_id && $max_depth-- > 0) {
            $parent = $wpdb->get_row($wpdb->prepare(
                "SELECT id, parent_id FROM {$wpdb->prefix}matrix_positions WHERE id = %d AND plan_id = %d",
                $current_id, $plan_id
            ));
            if (!$parent) break;
            if ($parent->parent_id == $ancestor_id) return true;
            $current_id = $parent->parent_id;
        }
        return false;
    }

    /**
     * Recursively update levels for all descendants after a move
     */
    private function recalculate_descendant_levels($parent_id, $parent_level, $plan_id) {
        global $wpdb;
        $children = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}matrix_positions WHERE parent_id = %d AND plan_id = %d",
            $parent_id, $plan_id
        ));

        foreach ($children as $child) {
            $child_level = $parent_level + 1;
            $wpdb->update(
                $wpdb->prefix . 'matrix_positions',
                ['level' => $child_level],
                ['id' => $child->id]
            );
            $this->recalculate_descendant_levels($child->id, $child_level, $plan_id);
        }
    }

    /**
     * Recalculate total_downline for a position and all its ancestors
     */
    private function recalculate_upline_counts($position_id, $plan_id) {
        global $wpdb;
        $current_id = $position_id;
        $max_depth = 50;

        while ($current_id && $max_depth-- > 0) {
            // Count all descendants of this position
            $count = $this->count_all_descendants($current_id, $plan_id);
            $wpdb->update(
                $wpdb->prefix . 'matrix_positions',
                ['total_downline' => $count],
                ['id' => $current_id]
            );

            // Move up to parent
            $parent_id = $wpdb->get_var($wpdb->prepare(
                "SELECT parent_id FROM {$wpdb->prefix}matrix_positions WHERE id = %d",
                $current_id
            ));
            $current_id = $parent_id;
        }
    }

    /**
     * Count all descendants of a position recursively
     */
    private function count_all_descendants($position_id, $plan_id) {
        global $wpdb;
        $count = 0;
        $children = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}matrix_positions WHERE parent_id = %d AND plan_id = %d",
            $position_id, $plan_id
        ));

        foreach ($children as $child) {
            $count++;
            $count += $this->count_all_descendants($child->id, $plan_id);
        }
        return $count;
    }

    /**
     * Look up a virtual wallet on Fintava by wallet_id and return the
     * authoritative account details so the admin Link form can auto-fill
     * itself instead of relying on hand-typed values.
     */
    private function fintava_lookup_wallet() {
        $wallet_id      = sanitize_text_field($_POST['wallet_id'] ?? '');
        $account_number = sanitize_text_field($_POST['account_number'] ?? '');
        if (empty($wallet_id)) {
            wp_send_json_error(['message' => __('Enter a Wallet ID first, then click Verify.', 'matrix-mlm')]);
        }

        $fintava = new Matrix_MLM_Fintava();
        if (!$fintava->is_active()) {
            wp_send_json_error(['message' => __('Fintava is not configured. Add the live API key on the Gateways page first.', 'matrix-mlm')]);
        }

        // Pass the typed account_number through as a hint — on Live tiers
        // where /virtual-wallet/{id} 404s, get_virtual_wallet_details() will
        // fall back to /wallet/details?accountNumber=... and verify the
        // returned wallet matches the requested wallet_id.
        $details = $fintava->get_virtual_wallet_details($wallet_id, $account_number ?: null);
        if (is_wp_error($details)) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s: API error message returned by Fintava */
                    __('Fintava could not find that wallet: %s', 'matrix-mlm'),
                    $details->get_error_message()
                ),
            ]);
        }

        // Normalize the API response into a stable shape regardless of which
        // field names Fintava chose to return (virtualAcctNo vs account_number,
        // bank vs bank_name, etc.).
        wp_send_json_success([
            'message' => __('Wallet verified — account details auto-filled.', 'matrix-mlm'),
            'wallet'  => [
                'wallet_id'      => Matrix_MLM_Fintava::extract_wallet_id($details) ?: $wallet_id,
                'account_number' => Matrix_MLM_Fintava::extract_account_number($details),
                'account_name'   => Matrix_MLM_Fintava::extract_account_name($details),
                'bank_name'      => Matrix_MLM_Fintava::extract_bank_name($details) ?: 'Fintava',
                'bank_code'      => $details['bank_code'] ?? $details['bankCode'] ?? '',
                'currency'       => $details['currency'] ?? 'NGN',
                'status'         => $details['status'] ?? 'active',
                'customer_email' => $details['email'] ?? $details['customer_email'] ?? '',
                'customer_phone' => $details['phone'] ?? $details['customer_phone'] ?? '',
            ],
        ]);
    }

    /**
     * Backfill missing wallet_id values across all linked Fintava accounts.
     *
     * Delegates to the gateway's backfill_missing_wallet_ids() orchestrator
     * (primary: list endpoint, fallback: webhook log scan; both verified via
     * GET /virtual-wallet/{id} before persisting). Returns a structured
     * report so the migration page can render counts and a per-row table.
     */
    private function fintava_backfill_wallet_ids() {
        $fintava = new Matrix_MLM_Fintava();
        if (!$fintava->is_active()) {
            wp_send_json_error(['message' => __('Fintava is not configured. Add the live API key on the Gateways page first.', 'matrix-mlm')]);
        }

        // Give long Fintava list pagination a chance to finish without tripping
        // the default 30s PHP timeout in shared hosting environments.
        @set_time_limit(180);

        $report = $fintava->backfill_missing_wallet_ids();
        if (is_wp_error($report)) {
            wp_send_json_error(['message' => $report->get_error_message()]);
        }

        // Build a friendly summary line for the toast.
        $summary = sprintf(
            __('Backfill complete: %d via list API, %d via webhook logs, %d mismatched, %d still missing (started with %d).', 'matrix-mlm'),
            $report['backfilled_via_list_api'],
            $report['backfilled_via_webhook'],
            $report['mismatched'],
            $report['still_missing'],
            $report['total_missing_before']
        );

        wp_send_json_success([
            'message' => $summary,
            'report'  => $report,
        ]);
    }

    /**
     * Backfill missing bank_code values across all linked Fintava wallets.
     *
     * Delegates to the gateway's backfill_missing_bank_codes() orchestrator,
     * which retries the same self-heal chain that ajax_transfer_matrix_to_virtual()
     * exercises lazily on the first transfer (resolve_wallet_id_from_customer →
     * enrich_partner_bank_metadata → derive_bank_code_from_name). Returns a
     * structured per-row report so the migration page can render counts and a
     * table indicating which step resolved each row, or — for rows that
     * remain unresolvable — a precise reason the operator can act on.
     */
    private function fintava_backfill_bank_codes() {
        $fintava = new Matrix_MLM_Fintava();
        if (!$fintava->is_active()) {
            wp_send_json_error(['message' => __('Fintava is not configured. Add the live API key on the Gateways page first.', 'matrix-mlm')]);
        }

        // Per-row chain can fire up to 5 Fintava round-trips. 180s gives a
        // 100-row batch headroom over the throttled inner loop.
        @set_time_limit(180);

        $report = $fintava->backfill_missing_bank_codes();
        if (is_wp_error($report)) {
            wp_send_json_error(['message' => $report->get_error_message()]);
        }

        $resolved_total = $report['resolved_via_wallet_details']
                        + $report['resolved_via_customer_api']
                        + $report['resolved_via_static_lookup'];

        $summary = sprintf(
            /* translators: 1: total resolved, 2: starting count, 3: still missing */
            __('Bank-code backfill complete: %1$d resolved out of %2$d (%3$d still missing).', 'matrix-mlm'),
            $resolved_total,
            $report['total_missing_before'],
            $report['still_missing']
        );

        wp_send_json_success([
            'message' => $summary,
            'report'  => $report,
        ]);
    }

    /**
     * Update the partner-bank metadata (bank_name + bank_code) on a single
     * user's Fintava virtual wallet row. Used by the "Fintava Virtual Wallet"
     * card on the user detail page when none of the auto-resolve steps in
     * ajax_transfer_matrix_to_virtual()'s self-heal chain landed bank_code —
     * e.g., wallets stuck on the schema-default placeholder bank_name=Fintava
     * with no partner-bank info available from any Fintava endpoint.
     *
     * Validates that bank_code matches Fintava's accepted sortCode shape
     * (3-digit CBN or 5-6 digit NIBSS-issued institution codes); other
     * values are rejected on the upstream side anyway and would just fail
     * the next /bank/credit/merchant call silently.
     */
    private function update_fintava_wallet_bank() {
        global $wpdb;

        $user_id   = intval($_POST['user_id'] ?? 0);
        $bank_name = sanitize_text_field($_POST['bank_name'] ?? '');
        $bank_code = sanitize_text_field($_POST['bank_code'] ?? '');

        if (!$user_id) {
            wp_send_json_error(['message' => __('Invalid user', 'matrix-mlm')]);
        }
        if ($bank_name === '' || $bank_code === '') {
            wp_send_json_error(['message' => __('Both bank name and bank code are required.', 'matrix-mlm')]);
        }
        // Reject the schema-default placeholder. Saving "Fintava" as the
        // bank_name puts the row right back into the failing state every
        // self-heal step explicitly skips — let the operator know up
        // front instead of writing it and silently breaking transfers.
        if (strcasecmp($bank_name, 'Fintava') === 0) {
            wp_send_json_error(['message' => __('"Fintava" is the schema placeholder, not a CBN bank. Pick the real partner bank Fintava issued the NUBAN through (e.g., Globus, Wema, Providus).', 'matrix-mlm')]);
        }
        // Fintava's /bank/credit/merchant validator coerces sortCode to a
        // numeric, and the CBN/NIBSS registry uses 3-6 digit codes.
        if (!preg_match('/^\d{3,6}$/', $bank_code)) {
            wp_send_json_error(['message' => __('Bank code must be 3-6 numeric digits (CBN or NIBSS sortCode).', 'matrix-mlm')]);
        }

        $wallet = $wpdb->get_row($wpdb->prepare(
            "SELECT id, bank_name, bank_code FROM {$wpdb->prefix}matrix_fintava_wallets WHERE user_id = %d",
            $user_id
        ));
        if (!$wallet) {
            wp_send_json_error(['message' => __('User has no Fintava wallet row to update. Use Migration → Link Single User to create one first.', 'matrix-mlm')]);
        }

        $updated = $wpdb->update(
            $wpdb->prefix . 'matrix_fintava_wallets',
            [
                'bank_name'  => $bank_name,
                'bank_code'  => $bank_code,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $wallet->id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            wp_send_json_error(['message' => __('Database update failed.', 'matrix-mlm')]);
        }

        wp_send_json_success([
            'message' => sprintf(
                /* translators: 1: bank name, 2: bank code */
                __('Bank details saved: %1$s (%2$s). Matrix→virtual transfers are unblocked for this wallet.', 'matrix-mlm'),
                $bank_name,
                $bank_code
            ),
        ]);
    }

    /**
     * Force-re-run every plugin schema bootstrap path and return a structured
     * report of what was created or left as-is. Surfaced from Migration →
     * Database Schema as a one-click recovery tool for installs whose
     * version-stamp short-circuit lied about reality (e.g. a row of CREATE
     * TABLE silently failed when the activator first ran, leaving the stamp
     * saved but the table missing — see Matrix_MLM_Database::maybe_upgrade()
     * for the full rationale).
     *
     * Idempotent: every call eventually delegates to dbDelta or
     * `CREATE TABLE IF NOT EXISTS`, so running it on a healthy install is a
     * no-op that just confirms everything's already in place.
     */
    private function repair_database_schema() {
        if (!class_exists('Matrix_MLM_Database')) {
            wp_send_json_error(['message' => __('Database helper class not loaded — cannot run repair.', 'matrix-mlm')]);
        }

        // dbDelta + extension CREATE TABLEs run quickly even on a fully
        // missing schema, but bump the time limit defensively so a slow
        // shared-hosting MySQL instance does not trip the 30s default
        // mid-bootstrap and leave the schema half-built.
        @set_time_limit(120);

        $report = Matrix_MLM_Database::repair();

        $created_count = count($report['created']);
        $missing_count = count($report['after']['missing']);
        $error_count   = count($report['errors']);

        if ($created_count === 0 && $missing_count === 0 && $error_count === 0) {
            $message = __('Schema is up to date — every expected table was already on disk.', 'matrix-mlm');
        } else {
            $message = sprintf(
                /* translators: 1: tables newly created on this run, 2: tables still missing, 3: DB errors emitted */
                __('Schema repair complete: %1$d created, %2$d still missing, %3$d DB errors.', 'matrix-mlm'),
                $created_count,
                $missing_count,
                $error_count
            );
        }

        wp_send_json_success([
            'message' => $message,
            'report'  => $report,
        ]);
    }

    /**
     * Link a Fintava wallet/card to a user (admin action)
     */
    private function link_fintava_account() {
        global $wpdb;

        $username = sanitize_text_field($_POST['username'] ?? '');
        if (empty($username)) {
            wp_send_json_error(['message' => __('Username is required', 'matrix-mlm')]);
        }

        $user = get_user_by('login', $username);
        if (!$user) {
            wp_send_json_error(['message' => __('User not found', 'matrix-mlm')]);
        }

        $user_id = $user->ID;
        $wallet_id = sanitize_text_field($_POST['wallet_id'] ?? '');
        $customer_id = sanitize_text_field($_POST['customer_id'] ?? '');
        $account_number = sanitize_text_field($_POST['account_number'] ?? '');
        $account_name = sanitize_text_field($_POST['account_name'] ?? '');
        $bank_name = sanitize_text_field($_POST['bank_name'] ?? 'Fintava');
        $card_id = sanitize_text_field($_POST['card_id'] ?? '');
        $last_four = sanitize_text_field($_POST['last_four'] ?? '');
        // Default to 'pending' so a manually-linked card whose true Fintava
        // state is unknown doesn't get marked active locally without an
        // actual link+activate round-trip. Admins who genuinely know the
        // card is active can pick that explicitly in the migration dropdown.
        $card_status = sanitize_text_field($_POST['card_status'] ?? 'pending');

        $linked = [];

        // Link wallet
        if ($account_number || $wallet_id) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}matrix_fintava_wallets WHERE user_id = %d", $user_id
            ));

            $wallet_data = [
                'user_id' => $user_id,
                'wallet_id' => $wallet_id,
                'customer_id' => $customer_id ?: null,
                'account_number' => $account_number,
                'account_name' => $account_name ?: $user->display_name,
                'bank_name' => $bank_name,
                'currency' => 'NGN',
                'customer_email' => $user->user_email,
                'status' => 'active',
            ];

            if ($existing) {
                $wpdb->update($wpdb->prefix . 'matrix_fintava_wallets', $wallet_data, ['id' => $existing]);
            } else {
                $wpdb->insert($wpdb->prefix . 'matrix_fintava_wallets', $wallet_data);
            }
            $linked[] = __('wallet', 'matrix-mlm');
        }

        // Link card
        if ($card_id) {
            $existing_card = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}matrix_fintava_cards WHERE user_id = %d", $user_id
            ));

            $card_data = [
                'user_id' => $user_id,
                'card_id' => $card_id,
                'wallet_id' => $wallet_id,
                'last_four' => $last_four,
                'status' => $card_status,
            ];

            if ($existing_card) {
                $wpdb->update($wpdb->prefix . 'matrix_fintava_cards', $card_data, ['id' => $existing_card]);
            } else {
                $wpdb->insert($wpdb->prefix . 'matrix_fintava_cards', $card_data);
            }
            $linked[] = __('card', 'matrix-mlm');
        }

        if (empty($linked)) {
            wp_send_json_error(['message' => __('No wallet or card data provided', 'matrix-mlm')]);
        }

        wp_send_json_success(['message' => sprintf(__('Successfully linked %s to %s', 'matrix-mlm'), implode(' & ', $linked), $username)]);
    }

    /**
     * Reset a user's card row to a pending state so they can self-serve
     * the link+activate flow again.
     *
     * Used when the local DB row says the card is in some advanced state
     * (linked/active) but Fintava either never had it linked or has lost
     * the association. Most common cause is the pre-API-rewrite import
     * path which defaulted status to 'active' on rows whose card was
     * never round-tripped through Fintava.
     *
     * What stays:
     *   - The row itself, including card_id (= Fintava cardMapId), so the
     *     fresh link call uses the right cardMapId Fintava is expecting.
     *   - wallet_id (the row's cross-reference to the wallet).
     *   - metadata (historical Fintava response, useful for debugging).
     *
     * What gets cleared:
     *   - status     → 'pending'
     *   - last_four  → NULL (no PAN entered yet on this attempt)
     *   - activated_at → NULL (no real activation)
     *
     * After this, the user reloads their card page, sees the
     * 'Activate Card' button on the pending state, types in their PAN,
     * and goes through the proper link+activate flow.
     */
    private function reset_card() {
        global $wpdb;

        $user_id = intval($_POST['user_id'] ?? 0);
        if ($user_id <= 0) {
            wp_send_json_error(['message' => __('User ID required', 'matrix-mlm')]);
        }

        // Pull card_id (= Fintava cardMapId) alongside id/status so we can
        // run a best-effort Fintava-side preflight before mutating the
        // local row. The reset is purely local — the cardMapId on
        // Fintava is preserved — so this snapshot is diagnostic only:
        // it confirms (or disputes) the desync hypothesis the admin is
        // acting on, and gives support a postmortem record of what the
        // card actually looked like on Fintava when the reset ran.
        $card = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status, card_id FROM {$wpdb->prefix}matrix_fintava_cards WHERE user_id = %d ORDER BY id DESC LIMIT 1",
            $user_id
        ));
        if (!$card) {
            wp_send_json_error(['message' => __('No card found for this user', 'matrix-mlm')]);
        }

        $previous_status = $card->status;

        // Best-effort Fintava-side snapshot. We do NOT block the reset on
        // this call — the reset is the recovery tool for cases where
        // Fintava is unreachable or returning conflicting data, and
        // forcing this round-trip to succeed would defeat that. We just
        // record what we saw so the admin and support can correlate the
        // local view (about to be mutated) against what Fintava thinks
        // the card looks like at the same moment.
        $fintava_status = null;
        $fintava_note   = null;
        if (!empty($card->card_id)
            && class_exists('Matrix_MLM_Fintava')
            && class_exists('Matrix_MLM_Fintava_Card')) {
            $fintava_main = new Matrix_MLM_Fintava();
            if ($fintava_main->is_active()) {
                $fintava_card = new Matrix_MLM_Fintava_Card();
                $details = $fintava_card->fetch_card($card->card_id);
                if (is_wp_error($details)) {
                    $fintava_note = $details->get_error_message();
                    error_log(sprintf(
                        '[Matrix MLM] reset_card preflight: user_id=%d cardMapId=%s local_status=%s fintava_lookup_failed=%s',
                        $user_id,
                        $card->card_id,
                        $previous_status,
                        $fintava_note
                    ));
                } else {
                    $fintava_status = isset($details['status'])
                        ? Matrix_MLM_Fintava_Card::normalize_status((string) $details['status'])
                        : null;
                    error_log(sprintf(
                        '[Matrix MLM] reset_card preflight: user_id=%d cardMapId=%s local_status=%s fintava_status=%s',
                        $user_id,
                        $card->card_id,
                        $previous_status,
                        $fintava_status ?? '(unknown)'
                    ));
                }
            }
        }

        $updated = $wpdb->update(
            $wpdb->prefix . 'matrix_fintava_cards',
            [
                'status'       => 'pending',
                'last_four'    => null,
                'activated_at' => null,
                'updated_at'   => current_time('mysql'),
            ],
            ['id' => $card->id]
        );

        if ($updated === false) {
            wp_send_json_error(['message' => __('Failed to reset card', 'matrix-mlm')]);
        }

        // Build the admin-visible summary. Surface the Fintava-side
        // snapshot when we managed to get one so the admin can confirm
        // whether the reset actually correlated with a desync. When the
        // preflight failed we still complete the reset, but include the
        // upstream error so support sees that Fintava is the unreachable
        // side rather than (silently) treating the reset as a clean
        // refresh.
        $base_message = sprintf(
            /* translators: 1: previous card status the row was in before reset */
            __('Card reset to pending (was %s). The user can now reload their card page and re-enter their PAN to link and activate it. The cardMapId on Fintava is preserved.', 'matrix-mlm'),
            $previous_status
        );
        if ($fintava_status !== null) {
            $base_message .= ' ' . sprintf(
                /* translators: 1: Fintava-side status at reset time */
                __('Fintava-side status at reset time: %s.', 'matrix-mlm'),
                $fintava_status
            );
        } elseif ($fintava_note !== null) {
            $base_message .= ' ' . sprintf(
                /* translators: 1: error message returned by Fintava during the preflight check */
                __('Fintava preflight check could not be completed (%s); proceeded with reset anyway.', 'matrix-mlm'),
                $fintava_note
            );
        }

        wp_send_json_success([
            'message'         => $base_message,
            'previous_status' => $previous_status,
            'fintava_status'  => $fintava_status,
        ]);
    }
}
