<?php
/**
 * Plugin Name: Matrix MLM Pro
 * Plugin URI: https://github.com/onyema3/Matrix
 * Description: A comprehensive Matrix MLM plugin with multiple plan structures, payment gateways (Paystack & Flutterwave), user dashboards, admin management, 2FA security, multi-language support, and more.
 * Version: 1.0.0
 * Author: Matrix Team
 * Author URI: https://github.com/onyema3
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: matrix-mlm
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin Constants
define('MATRIX_MLM_VERSION', '1.0.0');
define('MATRIX_MLM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MATRIX_MLM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MATRIX_MLM_PLUGIN_FILE', __FILE__);
define('MATRIX_MLM_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('MATRIX_MLM_DB_VERSION', '1.0.0');

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'Matrix_MLM_';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative_class = substr($class, strlen($prefix));
    $file = MATRIX_MLM_PLUGIN_DIR . 'includes/' . str_replace('_', '-', strtolower($relative_class)) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Core includes
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/class-matrix-database.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/class-matrix-activator.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/class-matrix-deactivator.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/class-matrix-core.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/class-matrix-plan-engine.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/class-matrix-user.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/class-matrix-commission.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/class-matrix-epin.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/class-matrix-wallet.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/class-matrix-support.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/class-matrix-notifications.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/class-matrix-two-factor.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/class-matrix-gdpr.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/class-matrix-language.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/class-matrix-seo.php';

// Payment Gateways
require_once MATRIX_MLM_PLUGIN_DIR . 'gateways/class-matrix-paystack.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'gateways/class-matrix-flutterwave.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'gateways/class-matrix-fintava.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'gateways/class-matrix-fintava-card.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'gateways/class-matrix-fintava-billing.php';

// Admin
if (is_admin()) {
    require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin.php';
    require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-plans.php';
    require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-users.php';
    require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-deposits.php';
    require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-withdrawals.php';
    require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-gateways.php';
    require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-tickets.php';
    require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-reports.php';
    require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-settings.php';
    require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-frontend.php';
}

// User Dashboard
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/user/class-matrix-user-dashboard.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/user/class-matrix-user-deposits.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/user/class-matrix-user-withdrawals.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/user/class-matrix-user-referrals.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/user/class-matrix-user-genealogy.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/user/class-matrix-user-profile.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/user/class-matrix-user-epin.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/user/class-matrix-user-transfer.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/user/class-matrix-user-tickets.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/user/class-matrix-user-bank-payout.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/user/class-matrix-user-virtual-wallet.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/user/class-matrix-user-card.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/user/class-matrix-user-billing.php';

// Activation/Deactivation hooks
register_activation_hook(__FILE__, ['Matrix_MLM_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['Matrix_MLM_Deactivator', 'deactivate']);

/**
 * Initialize the plugin
 */
function matrix_mlm_init() {
    $plugin = new Matrix_MLM_Core();
    $plugin->run();
}
add_action('plugins_loaded', 'matrix_mlm_init');

/**
 * Load plugin text domain for translations
 */
function matrix_mlm_load_textdomain() {
    load_plugin_textdomain('matrix-mlm', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('init', 'matrix_mlm_load_textdomain');
