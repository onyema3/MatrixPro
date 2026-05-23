<?php
/**
 * Database Schema for Matrix MLM
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Database {

    /**
     * The complete set of tables this plugin owns, by short name (without the
     * site's $wpdb->prefix). Used for the schema-status probe in
     * maybe_upgrade(), the admin "Repair Database Schema" tool, and as the
     * single source of truth when adding new tables — append the short name
     * here and add a corresponding dbDelta block in create_tables() (or, for
     * Fintava-extension tables, an entry in the relevant gateway's
     * ensure_tables_exist() / create_table()).
     *
     * Includes the gateway-extension tables (matrix_fintava_payouts,
     * matrix_fintava_wallets, matrix_fintava_cards, matrix_billing_transactions,
     * matrix_subscriptions) on purpose: they are bootstrapped automatically by
     * Matrix_MLM_Core::run() on every pageload, so any of them missing is just
     * as much a schema drift as a core table missing, and the operator-facing
     * Repair tool needs to surface and heal them with the same one click.
     */
    const CRITICAL_TABLES = [
        // Core tables — created by self::create_tables() via dbDelta.
        'matrix_plans',
        'matrix_positions',
        'matrix_wallet',
        'matrix_deposits',
        'matrix_withdrawals',
        'matrix_commissions',
        'matrix_epins',
        'matrix_tickets',
        'matrix_ticket_messages',
        'matrix_gateways',
        'matrix_user_meta',
        'matrix_transfers',
        'matrix_subscribers',
        'matrix_pages',
        'matrix_fintava_webhook_logs',
        // Fintava gateway tables — created by Matrix_MLM_Fintava::ensure_tables_exist()
        // (payouts, wallets) and by the *_Card / *_Billing / Subscription helpers.
        'matrix_fintava_payouts',
        'matrix_fintava_wallets',
        'matrix_fintava_cards',
        'matrix_billing_transactions',
        'matrix_subscriptions',
        'matrix_benefits',
        'matrix_cug_requests',
        'matrix_loan_applications',
        'matrix_healthcare_applications',
    ];

    /**
     * Run schema migrations on every load if the stored DB version is older
     * than the constant — OR if any expected table is missing on disk even
     * when the version stamp claims we're up to date. Safe to call
     * repeatedly: dbDelta is idempotent and the gateway helpers all use
     * CREATE TABLE IF NOT EXISTS.
     *
     * Why the missing-table probe matters even when the version stamp is
     * current: a previous activation (or a past maybe_upgrade run) may
     * have written `matrix_mlm_db_version` to wp_options successfully
     * while one of the table CREATE statements silently failed on the
     * same request — this happens in the wild when the DB user lost
     * CREATE privilege between activations, when a DB restore was loaded
     * from a snapshot taken before a table was added, or when dbDelta's
     * fuzzy SQL parser rejects a particular CREATE on certain MySQL
     * collation/strict-mode combinations. The version-stamp short
     * circuit alone would let those installs stay in a broken state
     * indefinitely; the cheap INFORMATION_SCHEMA probe (one query that
     * MySQL caches internally) is the suspenders to its belt and is the
     * specific reason wp_matrix_fintava_webhook_logs went missing on
     * the install that prompted this code to be written.
     */
    public static function maybe_upgrade() {
        $installed = get_option('matrix_mlm_db_version');

        // Fast path — stamp matches and every expected table is on disk.
        if ($installed === MATRIX_MLM_DB_VERSION && self::critical_tables_present()) {
            return;
        }

        // Re-run the schema (dbDelta is idempotent).
        self::create_tables();

        // Defensive ALTER for the e-pins plan_id NOT NULL → NULL change.
        // dbDelta does not always alter NULL constraints reliably across MySQL versions.
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_epins';
        $col = $wpdb->get_row($wpdb->prepare(
            "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'plan_id'",
            DB_NAME, $table
        ));
        if ($col && strtoupper($col->IS_NULLABLE) === 'NO') {
            $wpdb->query("ALTER TABLE {$table} MODIFY plan_id BIGINT(20) UNSIGNED NULL DEFAULT NULL");
        }

        update_option('matrix_mlm_db_version', MATRIX_MLM_DB_VERSION);
        update_option('matrix_mlm_last_schema_sync', current_time('mysql'));
    }

    /**
     * Return per-table existence status for the schema probe, broken into
     * a present/missing pair so callers can branch on whether anything is
     * out of sync without a second pass.
     *
     * @return array{present: string[], missing: string[]} Fully-prefixed
     *     table names so the result is render-ready for the admin UI.
     */
    public static function get_schema_status() {
        global $wpdb;
        $present = [];
        $missing = [];
        foreach (self::CRITICAL_TABLES as $name) {
            $full = $wpdb->prefix . $name;
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $full
            ));
            if ($exists > 0) {
                $present[] = $full;
            } else {
                $missing[] = $full;
            }
        }
        return ['present' => $present, 'missing' => $missing];
    }

    /**
     * Convenience wrapper — true iff every critical table is on disk.
     */
    public static function critical_tables_present() {
        $status = self::get_schema_status();
        return empty($status['missing']);
    }

    /**
     * Force-re-run every schema bootstrap path the activator runs (core
     * dbDelta + every gateway extension's CREATE TABLE IF NOT EXISTS),
     * regardless of the stored version stamp. Returns a structured report
     * so the admin "Repair Database Schema" UI can render exactly which
     * tables existed before, which exist after, what was created on this
     * call, and any DB errors emitted along the way.
     *
     * Idempotent: if everything is already healthy, the report shows zero
     * created tables and zero errors. Safe to expose to admins as a
     * one-click recovery tool.
     */
    public static function repair() {
        $errors = [];
        $before = self::get_schema_status();

        // Core schema. dbDelta absorbs already-existing tables silently.
        self::create_tables();

        // Fintava gateway tables (payouts, wallets, webhook_logs). The helper
        // collects per-table CREATE errors and returns them so we can surface
        // them in the report instead of swallowing them.
        if (class_exists('Matrix_MLM_Fintava')) {
            $fintava_result = Matrix_MLM_Fintava::ensure_tables_exist();
            if (!empty($fintava_result['errors'])) {
                foreach ($fintava_result['errors'] as $err) {
                    $errors[] = 'fintava: ' . $err;
                }
            }
        }

        // Optional extension tables — each helper uses CREATE TABLE IF NOT
        // EXISTS internally, so calling them when the table is already
        // present is a no-op.
        if (class_exists('Matrix_MLM_Fintava_Card')) {
            Matrix_MLM_Fintava_Card::create_table();
        }
        if (class_exists('Matrix_MLM_Fintava_Billing')) {
            Matrix_MLM_Fintava_Billing::create_table();
        }
        if (class_exists('Matrix_MLM_Subscription')) {
            Matrix_MLM_Subscription::create_table();
        }

        update_option('matrix_mlm_db_version', MATRIX_MLM_DB_VERSION);
        update_option('matrix_mlm_last_schema_sync', current_time('mysql'));

        $after   = self::get_schema_status();
        $created = array_values(array_diff($after['present'], $before['present']));

        return [
            'before'  => $before,
            'after'   => $after,
            'created' => $created,
            'errors'  => $errors,
        ];
    }

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
            pin_code varchar(50) NOT NULL,
            plan_id bigint(20) UNSIGNED DEFAULT NULL,
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
            slug varchar(50) NOT NULL,
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
            email varchar(255) NOT NULL,
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

        // Member Benefits — admin-managed cards rendered on the user
        // dashboard's Benefits tab. Gated to users with at least one
        // active position; see Matrix_MLM_User_Benefits::render() for
        // the entry point and class-matrix-admin-benefits.php for the
        // CRUD UI. The icon column accepts either a Dashicons class
        // (e.g. "dashicons-phone") or a fully-qualified image URL —
        // the renderer detects which based on the leading characters.
        $table_benefits = $wpdb->prefix . 'matrix_benefits';
        $sql_benefits = "CREATE TABLE $table_benefits (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            slug varchar(100) NOT NULL,
            icon varchar(500) DEFAULT NULL,
            short_description text,
            long_description longtext,
            cta_label varchar(100) DEFAULT NULL,
            cta_url varchar(500) DEFAULT NULL,
            display_order int(11) NOT NULL DEFAULT 0,
            status enum('active','inactive') NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY status_order (status, display_order)
        ) $charset_collate;";
        dbDelta($sql_benefits);

        // CUG enrolment requests — submitted by users from the Benefits
        // tab when they click "Apply" on the CUG card. Captures the
        // four fields specified by the operator: first_name, last_name,
        // nin, airtel_number (optional). Status defaults to 'pending'
        // so admins can review and approve/reject from the admin
        // (admin UI for that is intentionally out-of-scope for the
        // initial form rollout — rows are visible via the table).
        //
        // user_id is unique-keyed: one open request per user. On
        // resubmission we UPDATE the existing row instead of inserting
        // a duplicate, which keeps the audit trail simple and prevents
        // a user from spamming the table by clicking submit repeatedly.
        $table_cug = $wpdb->prefix . 'matrix_cug_requests';
        $sql_cug = "CREATE TABLE $table_cug (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            first_name varchar(60) NOT NULL,
            last_name varchar(60) NOT NULL,
            nin varchar(20) NOT NULL,
            airtel_number varchar(20) DEFAULT NULL,
            status enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
            admin_notes text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id),
            KEY status (status)
        ) $charset_collate;";
        dbDelta($sql_cug);

        // Business loan applications — submitted by members from the
        // Benefits tab when they click "Apply for Loan" on the loans
        // card. The schema is wide because the form has four sections
        // (personal / account / project / guarantor) plus six file
        // upload URLs, but it's flat by design: keeping all of it in
        // one row makes admin triage straightforward and avoids the
        // join overhead that a normalised parent/children layout
        // would introduce. Loan amounts and project gross value use
        // DECIMAL(15,2) so they comfortably hold up to ₦9.9 trillion
        // — well above the ₦5,000,000 cap in the operator's T&Cs but
        // future-proof against a corporate loan tier.
        //
        // user_id is unique-keyed for the same reason as the CUG
        // table: one open application per user. Resubmission UPDATEs
        // the existing row, so a member who needs to fix a typo or
        // re-upload a rejected document doesn't end up with a forest
        // of half-completed applications. When admins approve or
        // reject an application, the operator workflow can clear the
        // row (or transition it to 'cancelled') so the user can
        // submit a new one — this is a deliberate trade-off in the
        // initial rollout. If we later want a full loan history per
        // user, we'll relax the UNIQUE constraint and add a
        // status-scoped index instead.
        $table_loans = $wpdb->prefix . 'matrix_loan_applications';
        $sql_loans = "CREATE TABLE $table_loans (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            first_name varchar(60) NOT NULL,
            last_name varchar(60) NOT NULL,
            email varchar(120) NOT NULL,
            phone varchar(20) NOT NULL,
            address_line1 varchar(200) NOT NULL,
            address_line2 varchar(200) DEFAULT NULL,
            city varchar(100) NOT NULL,
            state varchar(100) NOT NULL,
            zip_code varchar(20) DEFAULT NULL,
            country varchar(100) NOT NULL,
            date_of_birth date NOT NULL,
            trade_name varchar(120) DEFAULT NULL,
            bank_name varchar(200) NOT NULL,
            account_number varchar(20) NOT NULL,
            account_name varchar(200) NOT NULL,
            applying_as enum('sole_proprietor','partnership','corporation') NOT NULL,
            business_address_line1 varchar(200) NOT NULL,
            business_address_line2 varchar(200) DEFAULT NULL,
            business_city varchar(100) NOT NULL,
            business_state varchar(100) NOT NULL,
            business_zip varchar(20) DEFAULT NULL,
            business_country varchar(100) NOT NULL,
            bvn varchar(20) NOT NULL,
            nin_file_url varchar(500) DEFAULT NULL,
            utility_bill_url varchar(500) DEFAULT NULL,
            valid_id_url varchar(500) DEFAULT NULL,
            passport_photo_url varchar(500) DEFAULT NULL,
            marketing_material_url varchar(500) DEFAULT NULL,
            project_info_url varchar(500) DEFAULT NULL,
            has_assets_statement tinyint(1) NOT NULL DEFAULT 0,
            previously_financed tinyint(1) NOT NULL DEFAULT 0,
            loan_reason enum('sme','farming','refinancing','other') NOT NULL,
            project_gross_value decimal(15,2) NOT NULL,
            loan_amount decimal(15,2) NOT NULL,
            repayment_plan enum('daily','weekly','monthly') NOT NULL,
            guarantor_first_name varchar(60) NOT NULL,
            guarantor_last_name varchar(60) NOT NULL,
            guarantor_phone varchar(20) NOT NULL,
            guarantor_valid_id_url varchar(500) DEFAULT NULL,
            guarantor_passport_url varchar(500) DEFAULT NULL,
            agreed_terms tinyint(1) NOT NULL DEFAULT 0,
            status enum('pending','under_review','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
            admin_notes text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        dbDelta($sql_loans);

        // Healthcare (HMO) enrolment applications — submitted from
        // the Benefits-tab healthcare card. Mirrors the loan table
        // shape (UNIQUE on user_id for UPSERT-on-resubmit, status
        // enum + index, admin_notes column, both timestamps) but is
        // shorter: no guarantor section, no T&Cs / agreed_terms, no
        // bank account block. Adds a `policy_number` column the
        // admin triage UI can stamp when status flips to 'approved'
        // so the user-facing email can quote the issued policy ID.
        $table_healthcare = $wpdb->prefix . 'matrix_healthcare_applications';
        $sql_healthcare = "CREATE TABLE $table_healthcare (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            first_name varchar(60) NOT NULL,
            last_name varchar(60) NOT NULL,
            middle_name varchar(60) DEFAULT NULL,
            email varchar(120) NOT NULL,
            phone varchar(20) NOT NULL,
            date_of_birth date NOT NULL,
            gender enum('male','female','other') NOT NULL,
            marital_status enum('single','married','divorced','widowed') NOT NULL,
            address_line1 varchar(200) NOT NULL,
            address_line2 varchar(200) DEFAULT NULL,
            city varchar(100) NOT NULL,
            state varchar(100) NOT NULL,
            zip_code varchar(20) DEFAULT NULL,
            country varchar(100) NOT NULL,
            nin varchar(20) NOT NULL,
            occupation varchar(120) DEFAULT NULL,
            plan_tier enum('basic','standard','premium','family') NOT NULL,
            coverage_type enum('individual','family') NOT NULL,
            preferred_hospital varchar(200) NOT NULL,
            dependants_count tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
            blood_group enum('A+','A-','B+','B-','AB+','AB-','O+','O-','unknown') NOT NULL DEFAULT 'unknown',
            genotype enum('AA','AS','SS','AC','SC','unknown') NOT NULL DEFAULT 'unknown',
            height_cm smallint(5) UNSIGNED DEFAULT NULL,
            weight_kg smallint(5) UNSIGNED DEFAULT NULL,
            pre_existing_conditions text,
            allergies text,
            current_medications text,
            is_smoker tinyint(1) NOT NULL DEFAULT 0,
            is_pregnant tinyint(1) NOT NULL DEFAULT 0,
            nok_name varchar(120) NOT NULL,
            nok_relationship varchar(50) NOT NULL,
            nok_phone varchar(20) NOT NULL,
            passport_photo_url varchar(500) DEFAULT NULL,
            nin_slip_url varchar(500) DEFAULT NULL,
            utility_bill_url varchar(500) DEFAULT NULL,
            medical_history_url varchar(500) DEFAULT NULL,
            status enum('pending','under_review','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
            policy_number varchar(50) DEFAULT NULL,
            admin_notes text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        dbDelta($sql_healthcare);

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
            'matrix_subscribers', 'matrix_pages', 'matrix_fintava_webhook_logs',
            'matrix_benefits',
            'matrix_cug_requests',
            'matrix_loan_applications',
            'matrix_healthcare_applications',
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
            'matrix_mlm_fintava_secret_key'   => '',
            'matrix_mlm_fintava_webhook_secret' => '',
            'matrix_mlm_fintava_status'       => 0,
            // Default to Live so the API Key field on the admin Gateways page
            // matches a real production endpoint without forcing the admin to
            // edit wp-config.php. Switchable via the Environment dropdown.
            'matrix_mlm_fintava_environment'  => 'live',
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

        // Seed the two starter benefits — only when the table is
        // empty so we don't clobber rows the operator has already
        // edited or extended through Matrix MLM → Benefits. The
        // copy is intentionally generic and editable; the icons use
        // Dashicons classes so they render without requiring an
        // image upload on first install.
        $benefits_table = $wpdb->prefix . 'matrix_benefits';
        $existing_benefits = (int) $wpdb->get_var("SELECT COUNT(*) FROM $benefits_table");
        if ($existing_benefits === 0) {
            $now = current_time('mysql');
            $wpdb->insert($benefits_table, [
                'title'             => 'CUG',
                'slug'              => 'cug',
                'icon'              => 'dashicons-phone',
                'short_description' => __('Closed User Group calls — talk to fellow members at preferential rates.', 'matrix-mlm'),
                'long_description'  => __('Members enrolled in the Closed User Group benefit get reduced-rate (or free) on-net calls and SMS to other CUG members. Activation details and the supported telco are configured by the administrator.', 'matrix-mlm'),
                'cta_label'         => '',
                'cta_url'           => '',
                'display_order'     => 10,
                'status'            => 'active',
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
            $wpdb->insert($benefits_table, [
                'title'             => 'Health Insurance',
                'slug'              => 'health-insurance',
                'icon'              => 'dashicons-heart',
                'short_description' => __('Comprehensive health cover for you and your immediate family.', 'matrix-mlm'),
                'long_description'  => __('Active members are entitled to enrol in our partner Health Insurance scheme. Coverage includes outpatient consultations, emergency care, and selected inpatient procedures. The administrator will publish the partner provider, eligible plans, and enrolment instructions on this page.', 'matrix-mlm'),
                'cta_label'         => '',
                'cta_url'           => '',
                'display_order'     => 20,
                'status'            => 'active',
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
            // Loans card. Slug 'loans' is what
            // Matrix_MLM_User_Benefits::is_loan_slug() matches against
            // to swap the generic Read more CTA for the Apply for
            // Loan modal. Operators can rename the title freely; they
            // can also rename the slug as long as it stays prefixed
            // 'loan-' (e.g. 'loan-sme', 'loan-farming') and the
            // detection still fires.
            $wpdb->insert($benefits_table, [
                'title'             => 'Loans',
                'slug'              => 'loans',
                'icon'              => 'dashicons-money-alt',
                'short_description' => __('Apply for a business loan with flexible repayment plans tailored to your matrix level.', 'matrix-mlm'),
                'long_description'  => __('Active members in good standing are eligible to apply for a Liberty Hub business loan. Loans range from ₦50,000 to ₦5,000,000 with a 1-12 month tenor and flat interest of 5-6.5% per month. The application captures personal, account, project, and guarantor details — your administrator reviews each submission before disbursement.', 'matrix-mlm'),
                'cta_label'         => '',
                'cta_url'           => '',
                'display_order'     => 30,
                'status'            => 'active',
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
        }

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
