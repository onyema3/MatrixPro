<?php
/**
 * Database Schema for Matrix MLM
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Database {

    /**
     * Create all plugin tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Matrix Plans table
        $table_plans = $wpdb->prefix . 'matrix_plans';
        $sql_plans = "CREATE TABLE $table_plans (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            width int(11) NOT NULL DEFAULT 2,
            depth int(11) NOT NULL DEFAULT 3,
            price decimal(12,2) NOT NULL DEFAULT 0.00,
            joining_fee decimal(12,2) NOT NULL DEFAULT 0.00,
            level_commission text,
            referral_commission decimal(12,2) NOT NULL DEFAULT 0.00,
            matrix_completion_bonus decimal(12,2) NOT NULL DEFAULT 0.00,
            max_members int(11) NOT NULL DEFAULT 0,
            status enum('active','inactive') NOT NULL DEFAULT 'active',
            description text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        dbDelta($sql_plans);

        // User Matrix positions
        $table_positions = $wpdb->prefix . 'matrix_positions';
        $sql_positions = "CREATE TABLE $table_positions (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            plan_id bigint(20) UNSIGNED NOT NULL,
            sponsor_id bigint(20) UNSIGNED DEFAULT NULL,
            parent_id bigint(20) UNSIGNED DEFAULT NULL,
            position int(11) NOT NULL DEFAULT 0,
            level int(11) NOT NULL DEFAULT 1,
            left_count int(11) NOT NULL DEFAULT 0,
            right_count int(11) NOT NULL DEFAULT 0,
            total_downline int(11) NOT NULL DEFAULT 0,
            status enum('active','inactive','completed') NOT NULL DEFAULT 'active',
            joined_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY plan_id (plan_id),
            KEY sponsor_id (sponsor_id),
            KEY parent_id (parent_id)
        ) $charset_collate;";
        dbDelta($sql_positions);

        // Transactions/Wallet
        $table_wallet = $wpdb->prefix . 'matrix_wallet';
        $sql_wallet = "CREATE TABLE $table_wallet (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            amount decimal(12,2) NOT NULL DEFAULT 0.00,
            post_balance decimal(12,2) NOT NULL DEFAULT 0.00,
            type enum('credit','debit') NOT NULL,
            transaction_type varchar(50) NOT NULL,
            description text,
            reference varchar(100) DEFAULT NULL,
            status enum('pending','completed','rejected','cancelled') NOT NULL DEFAULT 'completed',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY type (type),
            KEY transaction_type (transaction_type)
        ) $charset_collate;";
        dbDelta($sql_wallet);

        // Deposits
        $table_deposits = $wpdb->prefix . 'matrix_deposits';
        $sql_deposits = "CREATE TABLE $table_deposits (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            gateway varchar(50) NOT NULL,
            amount decimal(12,2) NOT NULL,
            charge decimal(12,2) NOT NULL DEFAULT 0.00,
            net_amount decimal(12,2) NOT NULL,
            currency varchar(10) NOT NULL DEFAULT 'NGN',
            transaction_id varchar(255) DEFAULT NULL,
            gateway_response text,
            status enum('pending','completed','rejected','cancelled') NOT NULL DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY status (status)
        ) $charset_collate;";
        dbDelta($sql_deposits);

        // Withdrawals
        $table_withdrawals = $wpdb->prefix . 'matrix_withdrawals';
        $sql_withdrawals = "CREATE TABLE $table_withdrawals (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            method varchar(50) NOT NULL,
            amount decimal(12,2) NOT NULL,
            charge decimal(12,2) NOT NULL DEFAULT 0.00,
            net_amount decimal(12,2) NOT NULL,
            currency varchar(10) NOT NULL DEFAULT 'NGN',
            account_details text,
            admin_note text,
            status enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY status (status)
        ) $charset_collate;";
        dbDelta($sql_withdrawals);

        // Commissions
        $table_commissions = $wpdb->prefix . 'matrix_commissions';
        $sql_commissions = "CREATE TABLE $table_commissions (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            from_user_id bigint(20) UNSIGNED NOT NULL,
            plan_id bigint(20) UNSIGNED DEFAULT NULL,
            type enum('referral','level','matrix_completion') NOT NULL,
            level int(11) NOT NULL DEFAULT 0,
            amount decimal(12,2) NOT NULL,
            status enum('pending','paid','cancelled') NOT NULL DEFAULT 'paid',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY from_user_id (from_user_id),
            KEY type (type)
        ) $charset_collate;";
        dbDelta($sql_commissions);

        // E-Pins
        $table_epins = $wpdb->prefix . 'matrix_epins';
        $sql_epins = "CREATE TABLE $table_epins (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            pin_code varchar(50) NOT NULL UNIQUE,
            plan_id bigint(20) UNSIGNED NOT NULL,
            amount decimal(12,2) NOT NULL,
            created_by bigint(20) UNSIGNED NOT NULL,
            used_by bigint(20) UNSIGNED DEFAULT NULL,
            status enum('unused','used','expired') NOT NULL DEFAULT 'unused',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            used_at datetime DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY pin_code (pin_code),
            KEY plan_id (plan_id),
            KEY status (status)
        ) $charset_collate;";
        dbDelta($sql_epins);

        // Support Tickets
        $table_tickets = $wpdb->prefix . 'matrix_tickets';
        $sql_tickets = "CREATE TABLE $table_tickets (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            subject varchar(255) NOT NULL,
            priority enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
            status enum('open','answered','customer_reply','closed') NOT NULL DEFAULT 'open',
            department varchar(100) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY status (status)
        ) $charset_collate;";
        dbDelta($sql_tickets);

        // Ticket Messages
        $table_ticket_messages = $wpdb->prefix . 'matrix_ticket_messages';
        $sql_ticket_messages = "CREATE TABLE $table_ticket_messages (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id bigint(20) UNSIGNED NOT NULL,
            user_id bigint(20) UNSIGNED NOT NULL,
            message text NOT NULL,
            attachments text,
            is_admin tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ticket_id (ticket_id)
        ) $charset_collate;";
        dbDelta($sql_ticket_messages);

        // Payment Gateways
        $table_gateways = $wpdb->prefix . 'matrix_gateways';
        $sql_gateways = "CREATE TABLE $table_gateways (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            slug varchar(50) NOT NULL UNIQUE,
            gateway_parameters text,
            supported_currencies text,
            min_amount decimal(12,2) NOT NULL DEFAULT 0.00,
            max_amount decimal(12,2) NOT NULL DEFAULT 999999.99,
            fixed_charge decimal(12,2) NOT NULL DEFAULT 0.00,
            percent_charge decimal(5,2) NOT NULL DEFAULT 0.00,
            status tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) $charset_collate;";
        dbDelta($sql_gateways);

        // User Meta Extended
        $table_user_meta = $wpdb->prefix . 'matrix_user_meta';
        $sql_user_meta = "CREATE TABLE $table_user_meta (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            balance decimal(12,2) NOT NULL DEFAULT 0.00,
            referral_code varchar(50) DEFAULT NULL,
            referred_by bigint(20) UNSIGNED DEFAULT NULL,
            phone varchar(20) DEFAULT NULL,
            address text,
            city varchar(100) DEFAULT NULL,
            state varchar(100) DEFAULT NULL,
            country varchar(100) DEFAULT NULL,
            zip_code varchar(20) DEFAULT NULL,
            two_factor_enabled tinyint(1) NOT NULL DEFAULT 0,
            two_factor_secret varchar(255) DEFAULT NULL,
            email_verified tinyint(1) NOT NULL DEFAULT 0,
            sms_verified tinyint(1) NOT NULL DEFAULT 0,
            kyc_verified tinyint(1) NOT NULL DEFAULT 0,
            status enum('active','banned','pending') NOT NULL DEFAULT 'active',
            last_login datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id),
            UNIQUE KEY referral_code (referral_code)
        ) $charset_collate;";
        dbDelta($sql_user_meta);

        // Balance Transfers
        $table_transfers = $wpdb->prefix . 'matrix_transfers';
        $sql_transfers = "CREATE TABLE $table_transfers (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            from_user_id bigint(20) UNSIGNED NOT NULL,
            to_user_id bigint(20) UNSIGNED NOT NULL,
            amount decimal(12,2) NOT NULL,
            charge decimal(12,2) NOT NULL DEFAULT 0.00,
            note text,
            status enum('completed','pending','cancelled') NOT NULL DEFAULT 'completed',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY from_user_id (from_user_id),
            KEY to_user_id (to_user_id)
        ) $charset_collate;";
        dbDelta($sql_transfers);

        // Subscribers
        $table_subscribers = $wpdb->prefix . 'matrix_subscribers';
        $sql_subscribers = "CREATE TABLE $table_subscribers (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            email varchar(255) NOT NULL UNIQUE,
            status tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY email (email)
        ) $charset_collate;";
        dbDelta($sql_subscribers);

        // Fintava Webhook Logs
        $table_webhook_logs = $wpdb->prefix . 'matrix_fintava_webhook_logs';
        $sql_webhook_logs = "CREATE TABLE $table_webhook_logs (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type varchar(100) NOT NULL,
            reference varchar(255) DEFAULT NULL,
            payload longtext,
            signature varchar(255) DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'received',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_type (event_type),
            KEY reference (reference),
            KEY created_at (created_at)
        ) $charset_collate;";
        dbDelta($sql_webhook_logs);

        // Pages/Content
        $table_pages = $wpdb->prefix . 'matrix_pages';
        $sql_pages = "CREATE TABLE $table_pages (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            content longtext,
            type varchar(50) NOT NULL DEFAULT 'page',
            status tinyint(1) NOT NULL DEFAULT 1,
            meta_title varchar(255) DEFAULT NULL,
            meta_description text,
            meta_keywords text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY slug (slug)
        ) $charset_collate;";
        dbDelta($sql_pages);

        update_option('matrix_mlm_db_version', MATRIX_MLM_DB_VERSION);
    }

    /**
     * Drop all plugin tables
     */
    public static function drop_tables() {
        global $wpdb;
        $tables = [
            'matrix_plans', 'matrix_positions', 'matrix_wallet',
            'matrix_deposits', 'matrix_withdrawals', 'matrix_commissions',
            'matrix_epins', 'matrix_tickets', 'matrix_ticket_messages',
            'matrix_gateways', 'matrix_user_meta', 'matrix_transfers',
            'matrix_subscribers', 'matrix_pages', 'matrix_fintava_webhook_logs'
        ];

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
        }
    }

    /**
     * Seed default data
     */
    public static function seed_defaults() {
        global $wpdb;

        // Insert default payment gateways
        $gateways_table = $wpdb->prefix . 'matrix_gateways';
        
        $existing = $wpdb->get_var("SELECT COUNT(*) FROM $gateways_table");
        if ($existing == 0) {
            $wpdb->insert($gateways_table, [
                'name' => 'Paystack',
                'slug' => 'paystack',
                'gateway_parameters' => json_encode([
                    'public_key' => '',
                    'secret_key' => '',
                    'webhook_secret' => ''
                ]),
                'supported_currencies' => json_encode(['NGN', 'GHS', 'ZAR', 'USD']),
                'min_amount' => 100.00,
                'max_amount' => 5000000.00,
                'fixed_charge' => 0.00,
                'percent_charge' => 1.50,
                'status' => 1
            ]);

            $wpdb->insert($gateways_table, [
                'name' => 'Flutterwave',
                'slug' => 'flutterwave',
                'gateway_parameters' => json_encode([
                    'public_key' => '',
                    'secret_key' => '',
                    'encryption_key' => '',
                    'webhook_hash' => ''
                ]),
                'supported_currencies' => json_encode(['NGN', 'GHS', 'KES', 'ZAR', 'USD', 'GBP', 'EUR']),
                'min_amount' => 100.00,
                'max_amount' => 10000000.00,
                'fixed_charge' => 0.00,
                'percent_charge' => 1.40,
                'status' => 1
            ]);
        }

        // Seed Fintava Pay default settings (stored in wp_options)
        $fintava_defaults = [
            'matrix_mlm_fintava_environment' => 'sandbox',
            'matrix_mlm_fintava_public_key' => '',
            'matrix_mlm_fintava_secret_key' => '',
            'matrix_mlm_fintava_base_url' => '',
            'matrix_mlm_fintava_webhook_secret' => '',
            'matrix_mlm_fintava_status' => 0,
        ];

        foreach ($fintava_defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }

        // Insert default plans
        $plans_table = $wpdb->prefix . 'matrix_plans';
        $existing_plans = $wpdb->get_var("SELECT COUNT(*) FROM $plans_table");
        if ($existing_plans == 0) {
            $default_plans = [
                ['name' => 'Starter 2x3', 'width' => 2, 'depth' => 3, 'price' => 5000.00],
                ['name' => 'Basic 3x3', 'width' => 3, 'depth' => 3, 'price' => 10000.00],
                ['name' => 'Standard 5x5', 'width' => 5, 'depth' => 5, 'price' => 25000.00],
                ['name' => 'Pro 4x7', 'width' => 4, 'depth' => 7, 'price' => 50000.00],
                ['name' => 'Premium 5x7', 'width' => 5, 'depth' => 7, 'price' => 75000.00],
                ['name' => 'Elite 3x9', 'width' => 3, 'depth' => 9, 'price' => 100000.00],
                ['name' => 'Ultimate 2x12', 'width' => 2, 'depth' => 12, 'price' => 150000.00],
            ];

            foreach ($default_plans as $plan) {
                $level_commissions = [];
                for ($i = 1; $i <= $plan['depth']; $i++) {
                    $level_commissions[$i] = round($plan['price'] * (0.10 - ($i * 0.005)), 2);
                }
                $wpdb->insert($plans_table, array_merge($plan, [
                    'level_commission' => json_encode($level_commissions),
                    'referral_commission' => $plan['price'] * 0.10,
                    'matrix_completion_bonus' => $plan['price'] * 0.50,
                    'status' => 'active'
                ]));
            }
        }

        // Create default root user for referral system
        self::create_default_user();

        // Default settings
        $defaults = [
            'matrix_mlm_site_title' => 'Matrix MLM Pro',
            'matrix_mlm_currency' => 'NGN',
            'matrix_mlm_currency_symbol' => '₦',
            'matrix_mlm_min_deposit' => 1000,
            'matrix_mlm_max_deposit' => 5000000,
            'matrix_mlm_min_withdraw' => 1000,
            'matrix_mlm_max_withdraw' => 1000000,
            'matrix_mlm_withdraw_charge_type' => 'percent',
            'matrix_mlm_withdraw_charge' => 5,
            'matrix_mlm_transfer_charge_type' => 'fixed',
            'matrix_mlm_transfer_charge' => 100,
            'matrix_mlm_min_transfer' => 500,
            'matrix_mlm_email_verification' => 1,
            'matrix_mlm_sms_verification' => 0,
            'matrix_mlm_2fa_enabled' => 1,
            'matrix_mlm_registration_enabled' => 1,
            'matrix_mlm_gdpr_enabled' => 1,
            'matrix_mlm_captcha_enabled' => 0,
            'matrix_mlm_captcha_site_key' => '',
            'matrix_mlm_captcha_secret_key' => '',
            'matrix_mlm_livechat_enabled' => 0,
            'matrix_mlm_livechat_code' => '',
            'matrix_mlm_primary_color' => '#4f46e5',
            'matrix_mlm_secondary_color' => '#7c3aed',
        ];

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }

    /**
     * Create a default root user for the referral system.
     * Since referral codes are mandatory for registration, this user serves
     * as the top of the referral tree so the first real users can sign up.
     */
    public static function create_default_user() {
        global $wpdb;

        // Check if a default root user already exists
        $default_ref_code = 'MATRIX01';
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}matrix_user_meta WHERE referral_code = %s",
            $default_ref_code
        ));

        if ($existing) {
            return; // Already created
        }

        // Use the current WordPress admin (the user activating the plugin) as the root user
        $admin_id = get_current_user_id();

        // If no user is logged in (unlikely during activation), get the first admin
        if (!$admin_id) {
            $admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC']);
            if (!empty($admins)) {
                $admin_id = $admins[0]->ID;
            }
        }

        // If still no admin found, create a dedicated root user
        if (!$admin_id) {
            $admin_email = get_option('admin_email', 'admin@' . parse_url(home_url(), PHP_URL_HOST));
            $admin_id = wp_create_user('matrix_admin', wp_generate_password(16, true), $admin_email);
            if (is_wp_error($admin_id)) {
                return; // Cannot create user, skip
            }
            $user = new WP_User($admin_id);
            $user->set_role('administrator');
        }

        // Check if this user already has a matrix_user_meta entry
        $has_meta = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}matrix_user_meta WHERE user_id = %d",
            $admin_id
        ));

        if (!$has_meta) {
            $wpdb->insert($wpdb->prefix . 'matrix_user_meta', [
                'user_id' => $admin_id,
                'referral_code' => $default_ref_code,
                'referred_by' => null,
                'phone' => '',
                'balance' => 0.00,
                'status' => 'active',
                'email_verified' => 1,
            ]);
        }

        // Store the default referral code in options for easy display/reference
        update_option('matrix_mlm_default_referral_code', $default_ref_code);
    }
}
