<?php
/**
 * Uninstall Matrix MLM Pro
 * Fires when the plugin is deleted via WP admin
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Only clean up if the user opts for full removal
$clean_data = get_option('matrix_mlm_clean_on_uninstall', false);

if ($clean_data) {
    // Remove tables
    global $wpdb;
    $tables = [
        'matrix_plans', 'matrix_positions', 'matrix_wallet',
        'matrix_deposits', 'matrix_withdrawals', 'matrix_commissions',
        'matrix_epins', 'matrix_tickets', 'matrix_ticket_messages',
        'matrix_gateways', 'matrix_user_meta', 'matrix_transfers',
        'matrix_subscribers', 'matrix_pages'
    ];

    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
    }

    // Remove options
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'matrix_mlm_%'");

    // Remove capabilities
    $admin_role = get_role('administrator');
    if ($admin_role) {
        $admin_role->remove_cap('manage_matrix_mlm');
        $admin_role->remove_cap('manage_matrix_plans');
        $admin_role->remove_cap('manage_matrix_users');
        $admin_role->remove_cap('manage_matrix_deposits');
        $admin_role->remove_cap('manage_matrix_withdrawals');
        $admin_role->remove_cap('manage_matrix_tickets');
        $admin_role->remove_cap('manage_matrix_settings');
    }

    // Remove created pages.
    //
    // Both `matrix` (current) and `matrix-register` (legacy slug
    // pre-rename) are listed so installs that activated under the
    // old slug get fully cleaned up too. Activator's
    // migrate_register_page_slug() converts the legacy slug to the
    // new one in place on activation, so on most installs only
    // `matrix` will exist and the `matrix-register` lookup is a
    // safe no-op — but the explicit listing protects installs
    // where the migration didn't run (e.g. the operator deleted
    // the page manually after creation, or an admin hand-created
    // a /matrix/ page that blocked the migration's gate).
    $pages = ['matrix-dashboard', 'matrix-login', 'matrix', 'matrix-register', 'matrix-plans'];
    foreach ($pages as $slug) {
        $page = get_page_by_path($slug);
        if ($page) {
            wp_delete_post($page->ID, true);
        }
    }
}
