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
    // Every slug the plugin has ever owned across its layout
    // history is listed so installs activated under any prior
    // shape get fully cleaned up too:
    //
    //   - Original (pre-PR #339):
    //       matrix-login, matrix-register
    //   - Post-PR #339 (~24h window):
    //       matrix-login, matrix    (matrix held [matrix_register])
    //   - Current:
    //       matrix (login), signup
    //
    // Activator's migrate_auth_page_slugs() converts older shapes
    // into the current one in place on activation, so on most
    // installs only `matrix` and `signup` will exist and the legacy
    // lookups are safe no-ops — but the explicit listing protects
    // installs where the migration didn't run (operator deleted
    // a page manually after creation, an admin hand-created a
    // colliding page that blocked the migration's gate, etc.).
    $pages = ['matrix-dashboard', 'matrix', 'matrix-login', 'matrix-register', 'signup', 'matrix-plans'];
    foreach ($pages as $slug) {
        $page = get_page_by_path($slug);
        if ($page) {
            wp_delete_post($page->ID, true);
        }
    }
}
