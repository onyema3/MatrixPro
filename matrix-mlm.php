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
define('MATRIX_MLM_VERSION', '1.0.4');
define('MATRIX_MLM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MATRIX_MLM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MATRIX_MLM_PLUGIN_FILE', __FILE__);
define('MATRIX_MLM_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('MATRIX_MLM_DB_VERSION', '1.0.11');

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
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/class-matrix-position-history.php';
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
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/class-matrix-subscription.php';

// Payment Gateways
require_once MATRIX_MLM_PLUGIN_DIR . 'gateways/class-matrix-paystack.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'gateways/class-matrix-flutterwave.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'gateways/class-matrix-fintava.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'gateways/class-matrix-fintava-card.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'gateways/class-matrix-fintava-billing.php';

// Always loaded — used by the self-healing seed in Matrix_MLM_Core::run().
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-gateways.php';

// Always loaded — registers the /genealogy/share/{token}/ public route, the
// AJAX hooks for minting/revoking share tokens, and the template_redirect
// intercept that renders the prospect-facing read-only tree page. Has to
// load on every request (not just admin) because the public route handler
// fires for anonymous viewers and the AJAX hooks fire on admin-ajax.php
// before is_admin() becomes meaningful.
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/class-matrix-share.php';
Matrix_MLM_Share::init();

// Always loaded — registers admin-post handlers and the WP-Cron event
// for the weekly automatic backup. Has to load on every request (not
// just admin) so that wp-cron, which executes outside is_admin(),
// still has the cron callback wired up.
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-backup.php';
Matrix_MLM_Admin_Backup::init();

// Always loaded — the Laravel importer registers the bcrypt-compatible
// check_password filter, which must run on every front-end login (not
// just admin requests). The same class also registers the chunked
// AJAX handler that drives the commit phase of an import, so it has
// to be wired up for wp_ajax_* dispatch which happens before is_admin()
// is meaningful in the request lifecycle.
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-import.php';
Matrix_MLM_Admin_Import::init();

// Admin
if (is_admin()) {
    require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin.php';
    require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-plans.php';
    require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-users.php';
    require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-deposits.php';
    require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-withdrawals.php';
    require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-tickets.php';
    require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-reports.php';
    require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-settings.php';
    require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-frontend.php';
    require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-migration.php';
    require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-benefits.php';
    require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-cug.php';
    require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-loans.php';
    require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-healthcare.php';
    require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-hospitals.php';
    require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-genealogy.php';
    // Aggregate, company-wide views over the matrix tree —
    // orphan branches, stuck levels, top sponsors, dormant
    // subtrees. Read-only, no schema changes; sits next to the
    // per-user Genealogy editor on the admin menu.
    require_once MATRIX_MLM_PLUGIN_DIR . 'includes/admin/class-matrix-admin-genealogy-analytics.php';
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
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/user/class-matrix-user-wallet.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/user/class-matrix-user-card.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/user/class-matrix-user-billing.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/user/class-matrix-user-benefits.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/user/class-matrix-user-cug.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/user/class-matrix-user-loan.php';
require_once MATRIX_MLM_PLUGIN_DIR . 'includes/user/class-matrix-user-healthcare.php';

// Activation/Deactivation hooks
register_activation_hook(__FILE__, ['Matrix_MLM_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['Matrix_MLM_Deactivator', 'deactivate']);

/**
 * Suppress wpdb's automatic <div id="error"><p class="wpdberror">...</p></div>
 * HTML emission for AJAX requests targeting one of this plugin's own actions.
 *
 * Without this, any DB write that fails on an AJAX path (UNIQUE collision,
 * NOT NULL violation, deadlock, etc.) prepends an HTML block to the response
 * stream. The handler then emits its JSON via wp_send_json_*, but jQuery's
 * parser sees the leading `<` from the wpdberror div and fails with
 * `parsererror` — masking the real DB error and surfacing in the browser as
 * the generic "Network error. Please try again." alert. The earlier matrix-
 * to-virtual transfer fix patched one instance of this symptom; this guard
 * is the systemic version so the same failure mode doesn't re-emerge in any
 * other AJAX handler the plugin ships now or in the future.
 *
 * Errors are still captured: $wpdb->last_error stays populated, the existing
 * error_log() calls in each handler continue to record them, and operators
 * with WP_DEBUG_LOG on still see the full stack in wp-content/debug.log —
 * we only suppress the inline HTML emission that corrupts JSON responses.
 *
 * Scoped to the plugin's own actions by matching the `matrix_` prefix on
 * $_REQUEST['action'], so other plugins' AJAX handlers retain their own
 * error-display behaviour. Every wp_ajax_* action registered by this plugin
 * uses that prefix (matrix_fintava_*, matrix_mlm_action, matrix_admin_action,
 * matrix_transfer_matrix_to_virtual, matrix_accept_cookies, etc.).
 */
function matrix_mlm_harden_ajax_response() {
    if (!function_exists('wp_doing_ajax') || !wp_doing_ajax()) {
        return;
    }
    $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
    if ($action === '' || strpos($action, 'matrix_') !== 0) {
        return;
    }
    global $wpdb;
    if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'hide_errors')) {
        $wpdb->hide_errors();
    }
}

/**
 * Initialize the plugin
 */
function matrix_mlm_init() {
    // Run before any AJAX handler so the suppression is in place by the time
    // a wp_ajax_matrix_* callback executes.
    matrix_mlm_harden_ajax_response();

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
