<?php
/**
 * Core Plugin Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Core {

    protected $admin;
    protected $user_dashboard;

    public function __construct() {
        $this->define_hooks();
    }

    public function run() {
        // Self-healing schema upgrade: run any pending migrations on load
        // so admins don't have to deactivate/reactivate after a plugin update.
        if (class_exists('Matrix_MLM_Database')) {
            Matrix_MLM_Database::maybe_upgrade();
        }

        // Self-healing seed: ensure default gateways exist on every load.
        // The admin gateways page also calls this when rendering, so it works
        // regardless of which page the user visits first.
        if (class_exists('Matrix_MLM_Admin_Gateways')) {
            Matrix_MLM_Admin_Gateways::ensure_default_gateways();
        }

        // Self-healing seed: ensure Fintava tables exist on every load.
        if (class_exists('Matrix_MLM_Fintava')) {
            Matrix_MLM_Fintava::ensure_tables_exist();
        }

        // Ensure default root user exists for the referral system
        if (class_exists('Matrix_MLM_Database')) {
            Matrix_MLM_Database::create_default_user();
        }

        // Initialize components
        if (is_admin()) {
            $this->admin = new Matrix_MLM_Admin();
        }
        $this->user_dashboard = new Matrix_MLM_User_Dashboard();

        // Initialize Fintava gateway (registers AJAX hooks)
        new Matrix_MLM_Fintava();

        // Initialize Fintava Card (registers card AJAX hooks)
        new Matrix_MLM_Fintava_Card();

        // Initialize Fintava Billing (registers billing AJAX hooks)
        new Matrix_MLM_Fintava_Billing();

        // Initialize monthly subscription
        new Matrix_MLM_Subscription();

        // Register password reset email hooks
        Matrix_MLM_Notifications::register_password_reset_hooks();
    }

    private function define_hooks() {
        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', [$this, 'enqueue_public_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // Register shortcodes
        add_action('init', [$this, 'register_shortcodes']);

        // AJAX handlers
        add_action('wp_ajax_matrix_mlm_action', [$this, 'handle_ajax']);
        add_action('wp_ajax_nopriv_matrix_mlm_action', [$this, 'handle_public_ajax']);

        // REST API
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Cron events
        add_action('matrix_mlm_daily_cron', [$this, 'daily_cron']);

        // Schedule cron if not scheduled
        if (!wp_next_scheduled('matrix_mlm_daily_cron')) {
            wp_schedule_event(time(), 'daily', 'matrix_mlm_daily_cron');
        }

        // Custom rewrite rules
        add_action('init', [$this, 'add_rewrite_rules']);
        add_filter('query_vars', [$this, 'add_query_vars']);

        // Email verification handler
        add_action('init', [$this, 'handle_email_verification']);
    }

    public function enqueue_public_assets() {
        wp_enqueue_style(
            'matrix-mlm-public',
            MATRIX_MLM_PLUGIN_URL . 'public/css/matrix-public.css',
            [],
            MATRIX_MLM_VERSION
        );

        wp_enqueue_style(
            'matrix-mlm-dashboard',
            MATRIX_MLM_PLUGIN_URL . 'public/css/matrix-dashboard.css',
            [],
            MATRIX_MLM_VERSION
        );

        wp_enqueue_script(
            'matrix-mlm-public',
            MATRIX_MLM_PLUGIN_URL . 'public/js/matrix-public.js',
            ['jquery'],
            MATRIX_MLM_VERSION,
            true
        );

        wp_localize_script('matrix-mlm-public', 'matrixMLM', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('matrix-mlm/v1/'),
            'nonce' => wp_create_nonce('matrix_mlm_nonce'),
            'currency' => get_option('matrix_mlm_currency_symbol', '₦'),
            'siteUrl' => home_url(),
            'paystackKey' => (new Matrix_MLM_Paystack())->get_public_key(),
            'flutterwaveKey' => (new Matrix_MLM_Flutterwave())->get_public_key(),
            'userEmail' => is_user_logged_in() ? wp_get_current_user()->user_email : '',
            'userName' => is_user_logged_in() ? wp_get_current_user()->display_name : '',
        ]);
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'matrix-mlm') === false) {
            return;
        }

        wp_enqueue_style(
            'matrix-mlm-admin',
            MATRIX_MLM_PLUGIN_URL . 'admin/css/matrix-admin.css',
            [],
            MATRIX_MLM_VERSION
        );

        wp_enqueue_script(
            'matrix-mlm-admin',
            MATRIX_MLM_PLUGIN_URL . 'admin/js/matrix-admin.js',
            ['jquery', 'wp-color-picker'],
            MATRIX_MLM_VERSION,
            true
        );

        wp_enqueue_style('wp-color-picker');

        wp_localize_script('matrix-mlm-admin', 'matrixMLMAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('matrix_mlm_admin_nonce'),
        ]);
    }

    public function register_shortcodes() {
        add_shortcode('matrix_dashboard', [$this->user_dashboard ?? new Matrix_MLM_User_Dashboard(), 'render_dashboard']);
        add_shortcode('matrix_login', [$this, 'render_login']);
        add_shortcode('matrix_register', [$this, 'render_register']);
        add_shortcode('matrix_plans', [$this, 'render_plans']);
    }

    public function render_login($atts) {
        if (is_user_logged_in()) {
            wp_redirect(home_url('/matrix-dashboard'));
            exit;
        }
        ob_start();
        include MATRIX_MLM_PLUGIN_DIR . 'public/templates/login.php';
        return ob_get_clean();
    }

    public function render_register($atts) {
        if (is_user_logged_in()) {
            wp_redirect(home_url('/matrix-dashboard'));
            exit;
        }
        ob_start();
        include MATRIX_MLM_PLUGIN_DIR . 'public/templates/register.php';
        return ob_get_clean();
    }

    public function render_plans($atts) {
        ob_start();
        include MATRIX_MLM_PLUGIN_DIR . 'public/templates/plans.php';
        return ob_get_clean();
    }

    public function handle_ajax() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        $action = isset($_POST['matrix_action']) ? sanitize_text_field($_POST['matrix_action']) : '';

        switch ($action) {
            case 'deposit':
                $this->process_deposit();
                break;
            case 'withdraw':
                $this->process_withdrawal();
                break;
            case 'transfer':
                $this->process_transfer();
                break;
            case 'join_plan':
                $this->process_join_plan();
                break;
            case 'redeem_epin':
                $this->process_epin_redeem();
                break;
            case 'submit_ticket':
                $this->process_ticket();
                break;
            case 'update_profile':
                $this->process_profile_update();
                break;
            case 'upload_avatar':
                $this->process_upload_avatar();
                break;
            case 'enable_2fa':
                $this->process_enable_2fa();
                break;
            case 'pay_subscription':
                $this->process_pay_subscription();
                break;
            default:
                wp_send_json_error(['message' => __('Invalid action', 'matrix-mlm')]);
        }
    }

    public function handle_public_ajax() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        $action = isset($_POST['matrix_action']) ? sanitize_text_field($_POST['matrix_action']) : '';

        switch ($action) {
            case 'login':
                $this->process_login();
                break;
            case 'register':
                $this->process_registration();
                break;
            case 'subscribe':
                $this->process_subscribe();
                break;
            default:
                wp_send_json_error(['message' => __('Invalid action', 'matrix-mlm')]);
        }
    }

    private function process_login() {
        $username = sanitize_text_field($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $user = wp_authenticate($username, $password);
        if (is_wp_error($user)) {
            wp_send_json_error(['message' => $user->get_error_message()]);
        }

        wp_set_auth_cookie($user->ID);
        wp_send_json_success(['redirect' => home_url('/matrix-dashboard')]);
    }

    private function process_registration() {
        $username = sanitize_text_field($_POST['username'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $referral_code = sanitize_text_field($_POST['referral_code'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');

        if (empty($username) || empty($email) || empty($password)) {
            wp_send_json_error(['message' => __('All fields are required', 'matrix-mlm')]);
        }

        if (empty($phone)) {
            wp_send_json_error(['message' => __('Phone number is required', 'matrix-mlm')]);
        }

        if (empty($referral_code)) {
            wp_send_json_error(['message' => __('Referral code is required. Please ask the person who invited you for their code.', 'matrix-mlm')]);
        }

        // Validate referral code exists
        global $wpdb;
        $referrer = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}matrix_user_meta WHERE referral_code = %s",
            $referral_code
        ));

        if (!$referrer) {
            wp_send_json_error(['message' => __('Invalid referral code. Please check and try again.', 'matrix-mlm')]);
        }

        if (username_exists($username) || email_exists($email)) {
            wp_send_json_error(['message' => __('Username or email already exists', 'matrix-mlm')]);
        }

        $user_id = wp_create_user($username, $password, $email);
        if (is_wp_error($user_id)) {
            wp_send_json_error(['message' => $user_id->get_error_message()]);
        }

        // Save first name, last name to WP user
        wp_update_user([
            'ID' => $user_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
        ]);

        // Create matrix user meta
        $ref_code = strtoupper(substr(md5($user_id . time()), 0, 8));

        $wpdb->insert($wpdb->prefix . 'matrix_user_meta', [
            'user_id' => $user_id,
            'referral_code' => $ref_code,
            'referred_by' => $referrer->user_id,
            'phone' => $phone,
            'balance' => 0.00,
            'status' => 'active'
        ]);

        // Send verification email
        Matrix_MLM_Notifications::send_verification_email($user_id);

        wp_set_auth_cookie($user_id);
        wp_send_json_success(['redirect' => home_url('/matrix-dashboard')]);
    }

    private function process_deposit() {
        $user_id = get_current_user_id();
        $amount = floatval($_POST['amount'] ?? 0);
        $gateway = sanitize_text_field($_POST['gateway'] ?? '');

        if ($amount < get_option('matrix_mlm_min_deposit', 1000)) {
            wp_send_json_error(['message' => __('Amount below minimum deposit', 'matrix-mlm')]);
        }

        if ($amount > get_option('matrix_mlm_max_deposit', 5000000)) {
            wp_send_json_error(['message' => __('Amount exceeds maximum deposit', 'matrix-mlm')]);
        }

        global $wpdb;
        $gateway_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_gateways WHERE slug = %s AND status = 1",
            $gateway
        ));

        if (!$gateway_data) {
            wp_send_json_error(['message' => __('Payment gateway not available', 'matrix-mlm')]);
        }

        $charge = $gateway_data->fixed_charge + ($amount * $gateway_data->percent_charge / 100);
        $net_amount = $amount - $charge;

        $wpdb->insert($wpdb->prefix . 'matrix_deposits', [
            'user_id' => $user_id,
            'gateway' => $gateway,
            'amount' => $amount,
            'charge' => $charge,
            'net_amount' => $net_amount,
            'currency' => get_option('matrix_mlm_currency', 'NGN'),
            'status' => 'pending'
        ]);

        $deposit_id = $wpdb->insert_id;

        // Initialize payment based on gateway
        if ($gateway === 'paystack') {
            $paystack = new Matrix_MLM_Paystack();
            $result = $paystack->initialize_payment($deposit_id, $amount, $user_id);
        } elseif ($gateway === 'flutterwave') {
            $flutterwave = new Matrix_MLM_Flutterwave();
            $result = $flutterwave->initialize_payment($deposit_id, $amount, $user_id);
        } else {
            wp_send_json_error(['message' => __('Unsupported gateway', 'matrix-mlm')]);
            return;
        }

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    private function process_withdrawal() {
        $user_id = get_current_user_id();
        $amount = floatval($_POST['amount'] ?? 0);
        $method = sanitize_text_field($_POST['method'] ?? '');
        $account_details = sanitize_textarea_field($_POST['account_details'] ?? '');

        $min = get_option('matrix_mlm_min_withdraw', 1000);
        $max = get_option('matrix_mlm_max_withdraw', 1000000);

        if ($amount < $min || $amount > $max) {
            wp_send_json_error(['message' => sprintf(__('Amount must be between %s and %s', 'matrix-mlm'), $min, $max)]);
        }

        // User must have a Fintava wallet — funds move from Matrix wallet to Fintava wallet on approval
        $fintava = new Matrix_MLM_Fintava();
        if (!$fintava->user_has_wallet($user_id)) {
            wp_send_json_error(['message' => __('You need a Fintava virtual wallet to withdraw. Please create one from the Virtual Wallet tab first.', 'matrix-mlm')]);
        }

        // Calculate charge
        $charge_type = get_option('matrix_mlm_withdraw_charge_type', 'percent');
        $charge_value = get_option('matrix_mlm_withdraw_charge', 5);
        $charge = $charge_type === 'percent' ? ($amount * $charge_value / 100) : $charge_value;
        $total = $amount + $charge;

        // Check Matrix wallet has enough for full amount + charge
        $wallet = new Matrix_MLM_Wallet();
        $balance = $wallet->get_balance($user_id);

        if ($balance < $total) {
            wp_send_json_error(['message' => __('Insufficient Matrix wallet balance', 'matrix-mlm')]);
        }

        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'matrix_withdrawals', [
            'user_id' => $user_id,
            'method' => $method,
            'amount' => $amount,
            'charge' => $charge,
            'net_amount' => $amount - $charge,
            'currency' => get_option('matrix_mlm_currency', 'NGN'),
            'account_details' => $account_details,
            'status' => 'pending'
        ]);

        // Debit full amount + charge from Matrix wallet (funds move to Fintava wallet on approval)
        $wallet->debit($user_id, $total, 'withdrawal', sprintf(__('Withdrawal request: %s%s to Fintava wallet', 'matrix-mlm'), get_option('matrix_mlm_currency_symbol', '₦'), number_format($amount, 2)));

        wp_send_json_success(['message' => __('Withdrawal request submitted successfully. Funds have been held from your Matrix wallet and will be credited to your Fintava wallet upon admin approval.', 'matrix-mlm')]);
    }

    private function process_transfer() {
        $user_id = get_current_user_id();
        $amount = floatval($_POST['amount'] ?? 0);
        $recipient_username = sanitize_text_field($_POST['recipient'] ?? '');

        $min = get_option('matrix_mlm_min_transfer', 500);
        if ($amount < $min) {
            wp_send_json_error(['message' => sprintf(__('Minimum transfer amount is %s', 'matrix-mlm'), $min)]);
        }

        $recipient = get_user_by('login', $recipient_username);
        if (!$recipient || $recipient->ID === $user_id) {
            wp_send_json_error(['message' => __('Invalid recipient', 'matrix-mlm')]);
        }

        $wallet = new Matrix_MLM_Wallet();
        $charge_type = get_option('matrix_mlm_transfer_charge_type', 'fixed');
        $charge_value = get_option('matrix_mlm_transfer_charge', 100);
        $charge = $charge_type === 'percent' ? ($amount * $charge_value / 100) : $charge_value;
        $total = $amount + $charge;

        $balance = $wallet->get_balance($user_id);
        if ($balance < $total) {
            wp_send_json_error(['message' => __('Insufficient balance', 'matrix-mlm')]);
        }

        // Process transfer
        $wallet->debit($user_id, $total, 'transfer_out', sprintf(__('Transfer to %s', 'matrix-mlm'), $recipient_username));
        $wallet->credit($recipient->ID, $amount, 'transfer_in', sprintf(__('Transfer from %s', 'matrix-mlm'), wp_get_current_user()->user_login));

        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'matrix_transfers', [
            'from_user_id' => $user_id,
            'to_user_id' => $recipient->ID,
            'amount' => $amount,
            'charge' => $charge,
            'status' => 'completed'
        ]);

        // Notify recipient about the incoming transfer
        Matrix_MLM_Notifications::send_transfer_notification($recipient->ID, $user_id, $amount);

        wp_send_json_success(['message' => __('Transfer completed successfully', 'matrix-mlm')]);
    }

    private function process_join_plan() {
        $user_id = get_current_user_id();
        $plan_id = intval($_POST['plan_id'] ?? 0);

        $plan_engine = new Matrix_MLM_Plan_Engine();
        $result = $plan_engine->join_plan($user_id, $plan_id);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    private function process_epin_redeem() {
        $user_id = get_current_user_id();
        $pin_code = sanitize_text_field($_POST['pin_code'] ?? '');

        $epin = new Matrix_MLM_Epin();
        $result = $epin->redeem($user_id, $pin_code);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    private function process_ticket() {
        $user_id = get_current_user_id();
        $subject = sanitize_text_field($_POST['subject'] ?? '');
        $message = sanitize_textarea_field($_POST['message'] ?? '');
        $priority = sanitize_text_field($_POST['priority'] ?? 'medium');

        if (empty($subject) || empty($message)) {
            wp_send_json_error(['message' => __('Subject and message are required', 'matrix-mlm')]);
        }

        $support = new Matrix_MLM_Support();
        $result = $support->create_ticket($user_id, $subject, $message, $priority);

        wp_send_json_success(['message' => __('Ticket created successfully', 'matrix-mlm'), 'ticket_id' => $result]);
    }

    private function process_profile_update() {
        $user_id = get_current_user_id();
        $data = [
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'address' => sanitize_textarea_field($_POST['address'] ?? ''),
            'city' => sanitize_text_field($_POST['city'] ?? ''),
            'state' => sanitize_text_field($_POST['state'] ?? ''),
            'country' => sanitize_text_field($_POST['country'] ?? ''),
            'zip_code' => sanitize_text_field($_POST['zip_code'] ?? ''),
        ];

        global $wpdb;
        $wpdb->update($wpdb->prefix . 'matrix_user_meta', $data, ['user_id' => $user_id]);

        // Update WP user data
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');
        if ($first_name) update_user_meta($user_id, 'first_name', $first_name);
        if ($last_name) update_user_meta($user_id, 'last_name', $last_name);

        // Update extended profile fields
        $date_of_birth = sanitize_text_field($_POST['date_of_birth'] ?? '');
        $gender = sanitize_text_field($_POST['gender'] ?? '');
        $bio = sanitize_textarea_field($_POST['bio'] ?? '');
        $bank_name = sanitize_text_field($_POST['bank_name'] ?? '');
        $bank_account = sanitize_text_field($_POST['bank_account'] ?? '');
        $bank_account_name = sanitize_text_field($_POST['bank_account_name'] ?? '');

        update_user_meta($user_id, 'matrix_date_of_birth', $date_of_birth);
        update_user_meta($user_id, 'matrix_gender', $gender);
        update_user_meta($user_id, 'matrix_bio', $bio);
        update_user_meta($user_id, 'matrix_bank_name', $bank_name);
        update_user_meta($user_id, 'matrix_bank_account', $bank_account);
        update_user_meta($user_id, 'matrix_bank_account_name', $bank_account_name);

        wp_send_json_success(['message' => __('Profile updated successfully', 'matrix-mlm')]);
    }

    private function process_upload_avatar() {
        $user_id = get_current_user_id();

        if (empty($_FILES['avatar'])) {
            wp_send_json_error(['message' => __('No file uploaded', 'matrix-mlm')]);
        }

        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        $file = $_FILES['avatar'];
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file['type'], $allowed)) {
            wp_send_json_error(['message' => __('Invalid file type. Allowed: JPG, PNG, GIF, WebP', 'matrix-mlm')]);
        }
        if ($file['size'] > 2 * 1024 * 1024) {
            wp_send_json_error(['message' => __('File too large. Maximum 2MB', 'matrix-mlm')]);
        }

        $upload = wp_handle_upload($file, ['test_form' => false]);
        if (isset($upload['error'])) {
            wp_send_json_error(['message' => $upload['error']]);
        }

        update_user_meta($user_id, 'matrix_avatar_url', $upload['url']);
        wp_send_json_success(['message' => __('Avatar updated', 'matrix-mlm'), 'url' => $upload['url']]);
    }

    private function process_enable_2fa() {
        $user_id = get_current_user_id();
        $two_factor = new Matrix_MLM_Two_Factor();
        $result = $two_factor->enable($user_id);
        wp_send_json_success($result);
    }

    private function process_pay_subscription() {
        $user_id = get_current_user_id();
        $subscription = new Matrix_MLM_Subscription();
        $result = $subscription->manual_pay($user_id);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    private function process_subscribe() {
        $email = sanitize_email($_POST['email'] ?? '');
        if (!is_email($email)) {
            wp_send_json_error(['message' => __('Invalid email address', 'matrix-mlm')]);
        }

        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}matrix_subscribers WHERE email = %s",
            $email
        ));

        if ($exists) {
            wp_send_json_error(['message' => __('Email already subscribed', 'matrix-mlm')]);
        }

        $wpdb->insert($wpdb->prefix . 'matrix_subscribers', ['email' => $email]);
        wp_send_json_success(['message' => __('Subscribed successfully!', 'matrix-mlm')]);
    }

    public function register_rest_routes() {
        register_rest_route('matrix-mlm/v1', '/payment/callback/(?P<gateway>[a-z]+)', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_payment_callback'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('matrix-mlm/v1', '/payment/verify/(?P<gateway>[a-z]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'handle_payment_verify'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_payment_callback($request) {
        $gateway = $request->get_param('gateway');

        if ($gateway === 'paystack') {
            $paystack = new Matrix_MLM_Paystack();
            return $paystack->handle_webhook($request);
        } elseif ($gateway === 'flutterwave') {
            $flutterwave = new Matrix_MLM_Flutterwave();
            return $flutterwave->handle_webhook($request);
        }

        return new WP_REST_Response(['status' => 'error'], 400);
    }

    public function handle_payment_verify($request) {
        $gateway = $request->get_param('gateway');
        $reference = $request->get_param('reference') ?? $request->get_param('tx_ref') ?? '';

        if (empty($reference)) {
            return new WP_REST_Response(['status' => 'error', 'message' => 'Reference is required'], 400);
        }

        if ($gateway === 'paystack') {
            $paystack = new Matrix_MLM_Paystack();
            return $paystack->verify_payment($reference);
        } elseif ($gateway === 'flutterwave') {
            $flutterwave = new Matrix_MLM_Flutterwave();
            return $flutterwave->verify_payment($reference);
        }

        return new WP_REST_Response(['status' => 'error', 'message' => 'Unsupported gateway'], 400);
    }

    /**
     * Handle email verification link
     */
    public function handle_email_verification() {
        if (!isset($_GET['action']) || $_GET['action'] !== 'matrix_verify_email') {
            return;
        }

        $token = sanitize_text_field($_GET['token'] ?? '');
        $user_id = intval($_GET['user'] ?? 0);

        if (empty($token) || !$user_id) {
            wp_die(__('Invalid verification link.', 'matrix-mlm'));
        }

        $stored_token = get_user_meta($user_id, 'matrix_email_verify_token', true);
        $expiry = get_user_meta($user_id, 'matrix_email_verify_expiry', true);

        if ($stored_token !== $token) {
            wp_die(__('Invalid or expired verification link.', 'matrix-mlm'));
        }

        if ($expiry && time() > $expiry) {
            wp_die(__('This verification link has expired. Please request a new one.', 'matrix-mlm'));
        }

        // Mark email as verified
        update_user_meta($user_id, 'matrix_email_verified', true);
        delete_user_meta($user_id, 'matrix_email_verify_token');
        delete_user_meta($user_id, 'matrix_email_verify_expiry');

        // Send welcome email
        Matrix_MLM_Notifications::send_welcome_email($user_id);

        // Redirect to login with success message
        $redirect_url = add_query_arg('verified', '1', home_url('/matrix-login'));
        wp_redirect($redirect_url);
        exit;
    }

    public function add_rewrite_rules() {
        add_rewrite_rule('^matrix-dashboard/([^/]+)/?$', 'index.php?pagename=matrix-dashboard&matrix_tab=$matches[1]', 'top');
    }

    public function add_query_vars($vars) {
        $vars[] = 'matrix_tab';
        return $vars;
    }

    public function daily_cron() {
        // Check expired e-pins
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'matrix_epins',
            ['status' => 'expired'],
            ['status' => 'unused'],
            ['%s'],
            ['%s']
        );
        // Only expire pins that have an expiry date set and passed
        $wpdb->query("UPDATE {$wpdb->prefix}matrix_epins SET status = 'expired' WHERE status = 'unused' AND expires_at IS NOT NULL AND expires_at < NOW()");
    }
}
