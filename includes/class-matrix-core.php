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

        // Initialize CUG (Closed User Group) handler — its
        // constructor registers the wp_ajax_matrix_submit_cug
        // endpoint that the Benefits-tab CUG card form posts to.
        new Matrix_MLM_User_CUG();

        // Initialize Loan handler — its constructor registers the
        // wp_ajax_matrix_submit_loan endpoint that the Benefits-tab
        // loans card application form posts to.
        new Matrix_MLM_User_Loan();

        // Initialize Healthcare handler — its constructor registers
        // the wp_ajax_matrix_submit_healthcare endpoint that the
        // Benefits-tab healthcare card application form posts to.
        new Matrix_MLM_User_Healthcare();

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

        // Suppress HTTP-level caching of any page that hosts the
        // [matrix_dashboard] shortcode. Reported symptom: after a
        // successful Wallet→Wallet transfer or e-pin recharge the
        // user sees a flash of the old balance until they manually
        // clear the app cache. Root cause is that the dashboard
        // page is served from an HTTP-level cache (browser cache,
        // server-side full-page cache, CDN edge cache) so the
        // post-transaction location.reload() in the inline JS gets
        // the stale HTML back even though the database is correct.
        //
        // Calling nocache_headers() here adds the standard
        // no-store/no-cache/must-revalidate header trio early in
        // the request lifecycle (before the shortcode emits any
        // output and therefore before the headers are flushed),
        // which all standards-compliant caches honour. We gate it
        // on actually being on a dashboard-bearing page so we
        // don't poison cacheability of the marketing/landing pages
        // — `wp` fires after the main query is set up, so $post
        // and has_shortcode() are usable at that point.
        add_action('wp', [$this, 'nocache_dashboard_pages']);
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

    /**
     * Send no-cache HTTP headers on any page that hosts the
     * [matrix_dashboard] shortcode.
     *
     * Hooked on `wp` (after the main query is set up, before any
     * output is sent) so $post is populated and we can detect the
     * shortcode without scanning the whole site. Limiting to
     * singular() avoids touching archive/feed responses where the
     * dashboard shortcode wouldn't run anyway, and the
     * `has_shortcode($post->post_content, ...)` check keeps
     * marketing/landing pages cacheable.
     *
     * Pairs with the JS-side cache-busting reload in
     * matrix-public.js (matrixMLMReload): the headers stop
     * intermediate caches from storing the dashboard HTML, and the
     * cache-busting URL on reload makes sure even non-compliant
     * caches (Cloudflare APO, some hosting-provider edge caches)
     * still serve a fresh page after a balance-changing action.
     */
    public function nocache_dashboard_pages() {
        if (!is_singular()) {
            return;
        }
        global $post;
        if (!$post || !is_a($post, 'WP_Post')) {
            return;
        }
        if (!has_shortcode($post->post_content, 'matrix_dashboard')) {
            return;
        }
        nocache_headers();
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
            case 'fetch_subtree':
                $this->process_fetch_subtree();
                break;
            case 'search_genealogy':
                $this->process_genealogy_search();
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

        // Create matrix user meta. generate_unique_referral_code()
        // retries on collision against the UNIQUE index on
        // matrix_user_meta.referral_code — same helper the importer
        // and the admin "Backfill Referral Codes" tool use, so all
        // three paths share one algorithm and one uniqueness story.
        $ref_code = Matrix_MLM_User::generate_unique_referral_code($user_id);

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

    /**
     * Process a user-to-user (Matrix wallet → Matrix wallet) transfer.
     *
     * Atomicity contract: either ALL three persistence steps succeed
     * (sender debit, recipient credit, matrix_transfers audit row) or
     * the whole thing rolls back and the user sees a real error. The
     * previous revision called debit/credit/insert in sequence without
     * checking any return value and unconditionally returned
     * "Transfer completed successfully", which is the bug behind the
     * "wallet-to-wallet says success but my balance is unchanged"
     * report on the consolidated Wallet page: a silent $wpdb->update
     * no-op (e.g. missing matrix_user_meta row) presented to the user
     * as a successful transfer that didn't actually move any money.
     *
     * The fix has three layers:
     *
     *   1. Matrix_MLM_Wallet::debit()/credit() now return false when
     *      the underlying $wpdb->update or $wpdb->insert fails or
     *      affects zero rows. (See those docblocks for the failure
     *      modes covered.)
     *
     *   2. This method checks every return value and bails with a
     *      real wp_send_json_error() — instead of pretending success —
     *      whenever any persistence step fails. The error_log() lines
     *      capture $wpdb->last_error so the next time this fires we
     *      can see exactly which DB op failed and why.
     *
     *   3. The whole flow runs inside a $wpdb->query('START
     *      TRANSACTION') / COMMIT / ROLLBACK envelope so partial
     *      failures don't leave the system in an inconsistent state
     *      (sender debited but recipient un-credited, or money moved
     *      but no matrix_transfers row to reconcile against). InnoDB
     *      is the WordPress default engine and dbDelta uses it without
     *      ENGINE= override, so the transaction is honoured.
     */
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

        global $wpdb;

        // All three persistence steps below are part of the same
        // logical operation; either they all commit or none of them
        // do. Wrapping in START TRANSACTION + COMMIT/ROLLBACK gives
        // us that guarantee on InnoDB tables (the WordPress default
        // and the engine dbDelta uses for our matrix_* tables).
        $wpdb->query('START TRANSACTION');

        // Sender debit — the only step that can legitimately fail on
        // a balance check at this point (the early `if ($balance <
        // $total)` above only narrows the window; a concurrent
        // transfer from the same user could still race past it). A
        // false return here means either insufficient balance OR a
        // $wpdb->update no-op (missing user_meta row, schema
        // mismatch, read-only DB connection, etc.) — surface the
        // failure to the user instead of pretending the money moved.
        $debit_result = $wallet->debit(
            $user_id,
            $total,
            'transfer_out',
            sprintf(__('Transfer to %s', 'matrix-mlm'), $recipient_username)
        );
        if ($debit_result === false) {
            $wpdb->query('ROLLBACK');
            error_log(sprintf(
                '[Matrix MLM] process_transfer: debit() returned false for user_id=%d, total=%s, last_error=%s',
                $user_id, $total, $wpdb->last_error
            ));
            wp_send_json_error([
                'message' => __('Could not debit your wallet. Please refresh and try again, or contact support if the problem persists.', 'matrix-mlm')
            ]);
        }

        // Recipient credit. Same false-return contract as debit: a
        // false here means the recipient's user_meta row is missing
        // or the insert/update failed. Don't ship the money via the
        // matrix_transfers audit row if the credit didn't actually
        // land.
        $credit_result = $wallet->credit(
            $recipient->ID,
            $amount,
            'transfer_in',
            sprintf(__('Transfer from %s', 'matrix-mlm'), wp_get_current_user()->user_login)
        );
        if ($credit_result === false) {
            $wpdb->query('ROLLBACK');
            error_log(sprintf(
                '[Matrix MLM] process_transfer: credit() returned false for recipient_id=%d, amount=%s, last_error=%s',
                $recipient->ID, $amount, $wpdb->last_error
            ));
            wp_send_json_error([
                'message' => __('Could not credit the recipient. Your wallet was not debited. Please contact support if the recipient should exist.', 'matrix-mlm')
            ]);
        }

        // Audit row. Treated as required: if we can't write the
        // matrix_transfers row, we don't have a way to reconcile this
        // movement later, so roll back the whole thing rather than
        // leave a silent debit/credit pair with no transfer record.
        $insert_result = $wpdb->insert($wpdb->prefix . 'matrix_transfers', [
            'from_user_id' => $user_id,
            'to_user_id' => $recipient->ID,
            'amount' => $amount,
            'charge' => $charge,
            'status' => 'completed'
        ]);
        if ($insert_result === false) {
            $wpdb->query('ROLLBACK');
            error_log(sprintf(
                '[Matrix MLM] process_transfer: matrix_transfers insert failed; from=%d, to=%d, amount=%s, last_error=%s',
                $user_id, $recipient->ID, $amount, $wpdb->last_error
            ));
            wp_send_json_error([
                'message' => __('Could not record the transfer. Please try again or contact support.', 'matrix-mlm')
            ]);
        }

        $wpdb->query('COMMIT');

        // Post-COMMIT verification. Re-read both balances directly
        // from matrix_user_meta and confirm they match what the
        // debit/credit calls reported. If they don't, something
        // outside this function (a shutdown hook, an HTTP-cached
        // dashboard page, a HyperDB master/replica split, a MyISAM
        // table that silently no-op'd the START TRANSACTION) ate
        // our writes — we should NOT report success in that case,
        // because the user will refresh and see balances that
        // contradict the success message they just dismissed (the
        // exact "transfer succeeds but no money moves" report this
        // PR is fixing). Logging $wpdb->last_error and the
        // before/after values gives support a forensic trail to
        // chase the actual root cause if the symptom recurs.
        $sender_balance_after    = $wallet->get_balance($user_id);
        $recipient_balance_after = $wallet->get_balance($recipient->ID);

        $sender_ok    = is_numeric($debit_result)  && abs($sender_balance_after    - floatval($debit_result))  < 0.01;
        $recipient_ok = is_numeric($credit_result) && abs($recipient_balance_after - floatval($credit_result)) < 0.01;

        if (!$sender_ok || !$recipient_ok) {
            error_log(sprintf(
                '[Matrix MLM] process_transfer: post-COMMIT balance mismatch. ' .
                'sender_id=%d expected_after=%s actual_after=%s | ' .
                'recipient_id=%d expected_after=%s actual_after=%s | ' .
                'amount=%s charge=%s last_error=%s',
                $user_id, $debit_result, $sender_balance_after,
                $recipient->ID, $credit_result, $recipient_balance_after,
                $amount, $charge, $wpdb->last_error
            ));
            wp_send_json_error([
                'message' => __('Transfer could not be confirmed. Please refresh your wallet page and verify the balance before retrying. If the problem persists, contact support.', 'matrix-mlm')
            ]);
        }

        // Notify recipient about the incoming transfer. Done AFTER
        // the verification check so a balance mismatch surfaces as a
        // real error instead of an email-but-no-money outcome. Done
        // AFTER commit so a failing email send (downed SMTP, bad
        // template, etc.) doesn't roll back the money movement —
        // the user has already been debited and credited, the
        // transfer is real.
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

    /**
     * AJAX endpoint for the genealogy "Show more" button.
     *
     * The genealogy view server-renders only the first 4 levels of a
     * member's matrix on initial pageload — below that, leaf nodes
     * with downline get a "Show more" expand button. Click fires this
     * endpoint with the position id being expanded plus its current
     * absolute level; the server fetches the next 3 levels of subtree
     * rooted there and returns it as ready-to-inject HTML matching the
     * rest of the tree's markup.
     *
     * Why a chunked / on-demand model rather than a single deep
     * initial render:
     *
     *   - A 3x9 plan has up to 29,524 positions; rendering all of them
     *     server-side on every dashboard pageload would balloon both
     *     query time and HTML payload.
     *   - Most members never need to navigate past their direct
     *     downline. Lazy-loading deeper levels only when a member
     *     actually clicks to see them keeps the typical pageload
     *     small while still letting curious members drill all the
     *     way down.
     *   - Each click adds 3 levels (so 4 → 7 → 10 → …, capped at the
     *     plan's depth). The renderer places another expand button at
     *     the new bottom edge if more remains, so deep dives compose
     *     naturally without any client-side state.
     *
     * Authorization: a member can only fetch a subtree they're already
     * entitled to see — namely, anything that descends from their own
     * position in the same plan. Matrix_MLM_Plan_Engine::user_can_view_position()
     * walks parent_id up from the requested position and confirms the
     * caller's user id appears on the chain. That auth check costs
     * O(depth) queries (typically <= 9), well below the budget for
     * the rest of this call.
     *
     * Returns an HTML payload (server-rendered) rather than JSON
     * structured data because the rendering layer is PHP-only — the
     * genealogy view server-renders every other piece of tree markup,
     * and duplicating the render logic in JS just so this one
     * endpoint can return JSON would double the maintenance surface.
     * The response HTML is wrapped in a single <div class="matrix-tree-children">
     * so the client side just replaces the expand button with it
     * verbatim.
     */
    private function process_fetch_subtree() {
        $position_id = isset($_POST['position_id']) ? (int) $_POST['position_id'] : 0;
        $from_level  = isset($_POST['from_level'])  ? (int) $_POST['from_level']  : 0;
        // Optional: when the genealogy view is rendered against a
        // pivoted root (?pivot_user_id=X), the JS forwards the current
        // tree's root user id so the lazy-loaded subtree is classified
        // as direct vs spillover relative to that pivot — matching how
        // every node already on the page was rendered. Falls back to
        // the actual viewer when the request omits it (the unpivoted
        // case, which is also the common case).
        $root_user_id_raw = isset($_POST['root_user_id']) ? (int) $_POST['root_user_id'] : 0;

        if ($position_id <= 0 || $from_level <= 0) {
            wp_send_json_error(['message' => __('Invalid request.', 'matrix-mlm')]);
        }

        global $wpdb;

        // Look up the position to authorize the read AND get its plan
        // dimensions in one trip. We need plan width to render the
        // empty-slot placeholders consistently with the initial render,
        // and plan depth as the absolute ceiling for the expansion
        // chunk so we don't query past where the matrix can possibly
        // hold members.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT p.id AS position_id, p.user_id AS owner_user_id, p.plan_id,
                    pl.width AS plan_width, pl.depth AS plan_depth
               FROM {$wpdb->prefix}matrix_positions p
               LEFT JOIN {$wpdb->prefix}matrix_plans pl ON pl.id = p.plan_id
              WHERE p.id = %d",
            $position_id
        ));

        if (!$row || !$row->plan_id) {
            wp_send_json_error(['message' => __('Position not found.', 'matrix-mlm')]);
        }

        $current_user_id = get_current_user_id();
        $plan_engine     = new Matrix_MLM_Plan_Engine();

        // Refuse the lookup if the caller can't see this branch of the
        // tree. See user_can_view_position()'s docblock for the
        // walk-up algorithm and why parent_id is the right structural
        // truth to query against.
        if (!$plan_engine->user_can_view_position($current_user_id, $position_id)) {
            wp_send_json_error(['message' => __('You do not have access to this part of the genealogy.', 'matrix-mlm')]);
        }

        // Each click reveals 3 more absolute levels, capped by the
        // plan's depth. Three is small enough to keep the round-trip
        // payload bounded yet large enough to feel like meaningful
        // progress on each click — picking 1 would leave members
        // clicking endlessly down a 9-level plan.
        $expand_levels = 3;
        $max_depth     = min((int) $row->plan_depth, $from_level + $expand_levels);

        $tree = $plan_engine->build_subtree($position_id, (int) $row->plan_id, $from_level, $max_depth);
        if (!$tree) {
            wp_send_json_error(['message' => __('Could not build subtree.', 'matrix-mlm')]);
        }

        // Decide which user id the renderer should treat as the tree's
        // root for direct/spillover classification. Defaults to the
        // viewer themselves; if the client passed a different
        // root_user_id (the pivoted-view case) we accept it only when
        // that user has a position in this plan AND the viewer can see
        // it — i.e., the same auth gate the page-level pivot uses, so
        // the AJAX path can never grant visibility the pivoted page
        // wouldn't have.
        $effective_root_user_id = $current_user_id;
        if ($root_user_id_raw > 0 && $root_user_id_raw !== $current_user_id) {
            $root_position_id = $plan_engine->get_position_id_for_user_in_plan(
                $root_user_id_raw,
                (int) $row->plan_id
            );
            if ($root_position_id > 0
                && $plan_engine->user_can_view_position($current_user_id, $root_position_id)) {
                $effective_root_user_id = $root_user_id_raw;
            }
            // Silently fall back to the viewer otherwise — same
            // behaviour as the page render rejects an unauthorised
            // ?pivot_user_id and shows the viewer's own tree.
        }

        // Render only the children block (the parent node itself is
        // already on the page — this AJAX call replaces the expand
        // button under it with a freshly-rendered .matrix-tree-children
        // sibling).
        ob_start();
        echo '<div class="matrix-tree-children">';
        $renderer = new Matrix_MLM_User_Genealogy();
        $renderer->render_children_inner(
            $tree,
            (int) $row->plan_width,
            $effective_root_user_id,
            $max_depth
        );
        echo '</div>';
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }

    /**
     * AJAX: search the current viewer's downline for members whose
     * username, email or display name matches a free-text query.
     *
     * Powers the typeahead in the genealogy view's search box. The
     * request shape is intentionally minimal — `q` and `plan_id` —
     * because the box only ever runs against the currently-viewed
     * plan and the only thing the viewer is allowed to do with a
     * result is jump to it (so we precompute the pivot URL on the
     * server and ship it down with the row).
     *
     * Authorization model:
     *   - Standard nonce check (already done in the dispatcher).
     *   - Must be logged in (get_current_user_id > 0). Anonymous
     *     callers can't have a downline so their search is never
     *     meaningful.
     *   - The downline scope itself is enforced inside
     *     Matrix_MLM_Plan_Engine::search_downline_users(), which
     *     runs each candidate position through user_can_view_position()
     *     before letting the row out. That's the same gate the
     *     ?pivot_user_id=X page render and the fetch_subtree AJAX
     *     endpoint use, so what comes back from the search will
     *     always be a valid pivot target — the JS can navigate
     *     straight to result.pivot_url without re-validating.
     *
     * Response shape on success:
     *     {
     *       success: true,
     *       data: {
     *         query:   "ali",
     *         results: [
     *           {
     *             user_id:      42,
     *             username:     "alice",
     *             display_name: "Alice Wonderland",
     *             email_masked: "a***@example.com",
     *             level:        3,
     *             pivot_url:    "https://…/matrix-dashboard/?tab=genealogy&plan_id=…&pivot_user_id=42"
     *           },
     *           …
     *         ]
     *       }
     *     }
     *
     * The email is *masked* before being returned because the
     * downline list above contains members the viewer hasn't
     * necessarily transacted with — they're often just structural
     * placements via spillover. Showing full email addresses to
     * upline members would be a privacy regression compared to
     * the rest of the genealogy view, which only shows usernames.
     * The masked form is a disambiguator (so two members named
     * "Alice" can be told apart), not a contact channel.
     */
    private function process_genealogy_search() {
        $current_user_id = get_current_user_id();
        if ($current_user_id <= 0) {
            wp_send_json_error(['message' => __('You must be logged in to search.', 'matrix-mlm')]);
        }

        $query   = isset($_POST['q'])       ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
        $plan_id = isset($_POST['plan_id']) ? (int) $_POST['plan_id']                       : 0;

        if ($plan_id <= 0) {
            wp_send_json_error(['message' => __('Plan is required.', 'matrix-mlm')]);
        }

        // Mirror the engine's own minimum-length contract here so
        // we return a clean empty payload (rather than an error)
        // when the user has only typed one character — that lets
        // the JS render the empty-state hint instead of an error
        // toast every keystroke.
        if (mb_strlen($query) < 2) {
            wp_send_json_success([
                'query'   => $query,
                'results' => [],
            ]);
        }

        $plan_engine = new Matrix_MLM_Plan_Engine();
        $matches     = $plan_engine->search_downline_users(
            $current_user_id,
            $plan_id,
            $query,
            10
        );

        // Build the pivot URL on the server so the JS doesn't have
        // to know about pretty-permalink vs query-string
        // dashboards. Matrix_MLM_User_Dashboard::tab_url() already
        // makes that decision for the rest of the dashboard, and
        // routing every pivot URL through it keeps the search
        // results consistent with the breadcrumb crumb hrefs.
        $base_url = class_exists('Matrix_MLM_User_Dashboard')
            ? Matrix_MLM_User_Dashboard::tab_url('genealogy')
            : home_url('/matrix-dashboard/?tab=genealogy');

        $rows = [];
        foreach ($matches as $m) {
            $rows[] = [
                'user_id'      => (int) $m['user_id'],
                'username'     => (string) $m['username'],
                'display_name' => (string) $m['display_name'],
                'email_masked' => $this->mask_email_for_search((string) $m['email']),
                'level'        => (int) $m['level'],
                'pivot_url'    => add_query_arg(
                    [
                        'plan_id'       => $plan_id,
                        'pivot_user_id' => (int) $m['user_id'],
                    ],
                    $base_url
                ),
            ];
        }

        wp_send_json_success([
            'query'   => $query,
            'results' => $rows,
        ]);
    }

    /**
     * Mask an email address for display in the genealogy search
     * dropdown.
     *
     * Renders alice@example.com as a***@example.com — enough for
     * the searcher to disambiguate two members with the same
     * display name but not enough to harvest contact details from
     * the downline tree. Used only by the search response; full
     * email addresses are never returned to upline members through
     * any genealogy-view surface.
     *
     * Edge cases that fall through to a generic placeholder:
     *   - Missing or malformed addresses (no '@'): returns ''.
     *   - Single-character local part: keeps the visible char and
     *     replaces the rest of the local with three asterisks
     *     (e.g. 'a@x.com' -> 'a***@x.com') so the format reads
     *     consistently in the dropdown.
     */
    private function mask_email_for_search($email) {
        $email = trim((string) $email);
        if ($email === '' || strpos($email, '@') === false) {
            return '';
        }
        list($local, $domain) = explode('@', $email, 2);
        if ($local === '') {
            return '';
        }
        $first = mb_substr($local, 0, 1);
        return $first . '***@' . $domain;
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

        // Self-heal: if the persisted rewrite_rules cache predates this rule
        // (e.g. the plugin was upgraded without re-activation, or a third
        // party plugin called flush_rewrite_rules() between our register
        // and persist), add_rewrite_rule() alone won't make the route
        // resolvable — WP would 404 the pretty /matrix-dashboard/{tab}/
        // URLs that Matrix_MLM_User_Dashboard::tab_url() now emits. Detect
        // that state and trigger one flush, then mark a flag so we don't
        // flush on every page load forever after.
        //
        // Skipped on Plain-permalink installs (empty permalink_structure)
        // because rewrite rules are inert in that mode; tab_url() falls
        // back to ?tab=X on those sites and the flush would be a no-op.
        if ((string) get_option('permalink_structure', '') !== '' &&
            (int) get_option('matrix_mlm_rewrite_rules_v2_flushed', 0) !== 1) {
            $persisted = get_option('rewrite_rules');
            if (!is_array($persisted) || !isset($persisted['^matrix-dashboard/([^/]+)/?$'])) {
                flush_rewrite_rules(false);
            }
            update_option('matrix_mlm_rewrite_rules_v2_flushed', 1, false);
        }
    }

    public function add_query_vars($vars) {
        $vars[] = 'matrix_tab';
        return $vars;
    }

    public function daily_cron() {
        // Expire only pins that have a real expiry date that has passed.
        // Previously this also unconditionally flipped every 'unused' pin
        // to 'expired', which silently destroyed valid pins overnight.
        global $wpdb;
        $wpdb->query(
            "UPDATE {$wpdb->prefix}matrix_epins
                SET status = 'expired'
              WHERE status = 'unused'
                AND expires_at IS NOT NULL
                AND expires_at < NOW()"
        );
    }
}
