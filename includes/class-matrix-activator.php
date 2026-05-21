<?php
/**
 * Plugin Activator
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Activator {

    public static function activate() {
        // Create database tables
        Matrix_MLM_Database::create_tables();

        // Create Fintava payout table
        Matrix_MLM_Fintava::create_table();

        // Create Fintava card table
        Matrix_MLM_Fintava_Card::create_table();

        // Create billing transactions table
        Matrix_MLM_Fintava_Billing::create_table();

        // Seed default data
        Matrix_MLM_Database::seed_defaults();

        // Create required pages
        self::create_pages();

        // Set capabilities
        self::set_capabilities();

        // Flush rewrite rules
        flush_rewrite_rules();

        // Set activation flag
        update_option('matrix_mlm_activated', true);
        update_option('matrix_mlm_activation_time', current_time('mysql'));
    }

    private static function create_pages() {
        $pages = [
            'matrix-dashboard' => [
                'title' => 'Matrix Dashboard',
                'content' => '[matrix_dashboard]'
            ],
            'matrix-login' => [
                'title' => 'Login',
                'content' => '[matrix_login]'
            ],
            'matrix-register' => [
                'title' => 'Register',
                'content' => '[matrix_register]'
            ],
            'matrix-plans' => [
                'title' => 'Plans',
                'content' => '[matrix_plans]'
            ],
        ];

        foreach ($pages as $slug => $page) {
            $existing = get_page_by_path($slug);
            if (!$existing) {
                wp_insert_post([
                    'post_title' => $page['title'],
                    'post_content' => $page['content'],
                    'post_status' => 'publish',
                    'post_type' => 'page',
                    'post_name' => $slug,
                ]);
            }
        }
    }

    private static function set_capabilities() {
        $admin_role = get_role('administrator');
        if ($admin_role) {
            $admin_role->add_cap('manage_matrix_mlm');
            $admin_role->add_cap('manage_matrix_plans');
            $admin_role->add_cap('manage_matrix_users');
            $admin_role->add_cap('manage_matrix_deposits');
            $admin_role->add_cap('manage_matrix_withdrawals');
            $admin_role->add_cap('manage_matrix_tickets');
            $admin_role->add_cap('manage_matrix_settings');
        }
    }
}
