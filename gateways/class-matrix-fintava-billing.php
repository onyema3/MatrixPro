<?php
/**
 * Fintava Pay - Billing Services (Airtime, Data, Cable TV, Electricity)
 * 
 * Endpoints:
 * - POST /billing/airtime                  - Buy airtime
 * - GET  /cable-service-name               - List cable providers
 * - GET  /cable-service-name/{network}     - List cable subscriptions
 * - POST /billing/cable-subscription       - Buy cable subscription
 * - GET  /billing/data-bundles/{name}      - List data bundles for a network
 * - POST /billing/data-bundle              - Buy data bundle
 * - GET  /billing/discos                   - List electricity disco providers
 * - POST /billing/discos                   - Preview meter details (verify meter)
 * - POST /billing/electricity              - Buy electricity (pay bill)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Fintava_Billing {

    public function __construct() {
        $this->register_hooks();
    }

    private function register_hooks() {
        // Airtime
        add_action('wp_ajax_matrix_fintava_buy_airtime', [$this, 'ajax_buy_airtime']);
        // Data
        add_action('wp_ajax_matrix_fintava_list_data_bundles', [$this, 'ajax_list_data_bundles']);
        add_action('wp_ajax_matrix_fintava_buy_data', [$this, 'ajax_buy_data']);
        // Cable
        add_action('wp_ajax_matrix_fintava_list_cable_providers', [$this, 'ajax_list_cable_providers']);
        add_action('wp_ajax_matrix_fintava_list_cable_plans', [$this, 'ajax_list_cable_plans']);
        add_action('wp_ajax_matrix_fintava_buy_cable', [$this, 'ajax_buy_cable']);
        // Electricity
        add_action('wp_ajax_matrix_fintava_list_discos', [$this, 'ajax_list_discos']);
        add_action('wp_ajax_matrix_fintava_verify_meter', [$this, 'ajax_verify_meter']);
        add_action('wp_ajax_matrix_fintava_buy_electricity', [$this, 'ajax_buy_electricity']);
    }

    // =========================================================================
    // API METHODS
    // =========================================================================

    /**
     * Buy Airtime
     * POST /billing/airtime
     */
    public function buy_airtime($phone, $amount, $network) {
        return $this->make_request('POST', '/billing/airtime', [
            'phone' => $phone,
            'amount' => floatval($amount),
            'network' => $network,
        ]);
    }

    /**
     * List cable TV providers
     * GET /cable-service-name
     */
    public function list_cable_providers() {
        $cache_key = 'matrix_fintava_cable_providers';
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $response = $this->make_request('GET', '/cable-service-name');
        if (is_wp_error($response)) return $response;

        if (isset($response['data'])) {
            set_transient($cache_key, $response['data'], DAY_IN_SECONDS);
            return $response['data'];
        }
        return $response['data'] ?? [];
    }

    /**
     * List cable subscriptions for a provider
     * GET /cable-service-name/{network}
     */
    public function list_cable_plans($network) {
        $cache_key = 'matrix_fintava_cable_plans_' . sanitize_key($network);
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $response = $this->make_request('GET', '/cable-service-name/' . urlencode($network));
        if (is_wp_error($response)) return $response;

        if (isset($response['data'])) {
            set_transient($cache_key, $response['data'], HOUR_IN_SECONDS * 6);
            return $response['data'];
        }
        return $response['data'] ?? [];
    }

    /**
     * Buy cable subscription
     * POST /billing/cable-subscription
     */
    public function buy_cable($smartcard_number, $plan_id, $provider) {
        return $this->make_request('POST', '/billing/cable-subscription', [
            'smartcard_number' => $smartcard_number,
            'plan_id' => $plan_id,
            'provider' => $provider,
        ]);
    }

    /**
     * List data bundles for a network
     * GET /billing/data-bundles/{name}
     */
    public function list_data_bundles($network) {
        $cache_key = 'matrix_fintava_data_bundles_' . sanitize_key($network);
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $response = $this->make_request('GET', '/billing/data-bundles/' . urlencode($network));
        if (is_wp_error($response)) return $response;

        if (isset($response['data'])) {
            set_transient($cache_key, $response['data'], HOUR_IN_SECONDS * 6);
            return $response['data'];
        }
        return $response['data'] ?? [];
    }

    /**
     * Buy data bundle
     * POST /billing/data-bundle
     */
    public function buy_data($phone, $plan_id, $network) {
        return $this->make_request('POST', '/billing/data-bundle', [
            'phone' => $phone,
            'plan_id' => $plan_id,
            'network' => $network,
        ]);
    }

    /**
     * List electricity disco providers
     * GET /billing/discos
     */
    public function list_discos() {
        $cache_key = 'matrix_fintava_discos';
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $response = $this->make_request('GET', '/billing/discos');
        if (is_wp_error($response)) return $response;

        if (isset($response['data'])) {
            set_transient($cache_key, $response['data'], DAY_IN_SECONDS);
            return $response['data'];
        }
        return $response['data'] ?? [];
    }

    /**
     * Preview/verify meter details
     * POST /billing/discos
     */
    public function verify_meter($meter_number, $disco, $meter_type) {
        return $this->make_request('POST', '/billing/discos', [
            'meter_number' => $meter_number,
            'disco' => $disco,
            'meter_type' => $meter_type,
        ]);
    }

    /**
     * Buy electricity
     * POST /billing/electricity
     */
    public function buy_electricity($meter_number, $amount, $disco, $meter_type) {
        return $this->make_request('POST', '/billing/electricity', [
            'meter_number' => $meter_number,
            'amount' => floatval($amount),
            'disco' => $disco,
            'meter_type' => $meter_type,
        ]);
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    public function ajax_buy_airtime() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) { wp_send_json_error(['message' => __('Auth required', 'matrix-mlm')]); }

        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $network = sanitize_text_field($_POST['network'] ?? '');

        if (empty($phone) || $amount <= 0 || empty($network)) {
            wp_send_json_error(['message' => __('Phone, amount and network are required', 'matrix-mlm')]);
        }

        // Debit matrix wallet
        $wallet = new Matrix_MLM_Wallet();
        if ($wallet->get_balance($user_id) < $amount) {
            wp_send_json_error(['message' => __('Insufficient Matrix wallet balance', 'matrix-mlm')]);
        }
        $wallet->debit($user_id, $amount, 'billing_airtime', sprintf(__('Airtime %s - %s', 'matrix-mlm'), $network, $phone));

        $result = $this->buy_airtime($phone, $amount, $network);
        if (is_wp_error($result)) {
            $wallet->credit($user_id, $amount, 'billing_refund', __('Airtime purchase failed - refund', 'matrix-mlm'));
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        $this->log_transaction($user_id, 'airtime', $amount, ['phone' => $phone, 'network' => $network], $result);
        wp_send_json_success(['message' => sprintf(__('%s%s airtime sent to %s', 'matrix-mlm'), get_option('matrix_mlm_currency_symbol', '₦'), number_format($amount), $phone)]);
    }

    public function ajax_list_data_bundles() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');
        if (!is_user_logged_in()) { wp_send_json_error(['message' => __('Auth required', 'matrix-mlm')]); }

        $network = sanitize_text_field($_POST['network'] ?? '');
        if (empty($network)) { wp_send_json_error(['message' => __('Network required', 'matrix-mlm')]); }

        $bundles = $this->list_data_bundles($network);
        if (is_wp_error($bundles)) { wp_send_json_error(['message' => $bundles->get_error_message()]); }
        wp_send_json_success(['bundles' => $bundles]);
    }

    public function ajax_buy_data() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) { wp_send_json_error(['message' => __('Auth required', 'matrix-mlm')]); }

        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $plan_id = sanitize_text_field($_POST['plan_id'] ?? '');
        $network = sanitize_text_field($_POST['network'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);

        if (empty($phone) || empty($plan_id) || empty($network) || $amount <= 0) {
            wp_send_json_error(['message' => __('All fields are required', 'matrix-mlm')]);
        }

        $wallet = new Matrix_MLM_Wallet();
        if ($wallet->get_balance($user_id) < $amount) {
            wp_send_json_error(['message' => __('Insufficient balance', 'matrix-mlm')]);
        }
        $wallet->debit($user_id, $amount, 'billing_data', sprintf(__('Data bundle %s - %s', 'matrix-mlm'), $network, $phone));

        $result = $this->buy_data($phone, $plan_id, $network);
        if (is_wp_error($result)) {
            $wallet->credit($user_id, $amount, 'billing_refund', __('Data purchase failed - refund', 'matrix-mlm'));
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        $this->log_transaction($user_id, 'data', $amount, ['phone' => $phone, 'network' => $network, 'plan_id' => $plan_id], $result);
        wp_send_json_success(['message' => __('Data bundle purchased successfully!', 'matrix-mlm')]);
    }

    public function ajax_list_cable_providers() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');
        if (!is_user_logged_in()) { wp_send_json_error(['message' => __('Auth required', 'matrix-mlm')]); }

        $providers = $this->list_cable_providers();
        if (is_wp_error($providers)) { wp_send_json_error(['message' => $providers->get_error_message()]); }
        wp_send_json_success(['providers' => $providers]);
    }

    public function ajax_list_cable_plans() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');
        if (!is_user_logged_in()) { wp_send_json_error(['message' => __('Auth required', 'matrix-mlm')]); }

        $network = sanitize_text_field($_POST['provider'] ?? '');
        if (empty($network)) { wp_send_json_error(['message' => __('Provider required', 'matrix-mlm')]); }

        $plans = $this->list_cable_plans($network);
        if (is_wp_error($plans)) { wp_send_json_error(['message' => $plans->get_error_message()]); }
        wp_send_json_success(['plans' => $plans]);
    }

    public function ajax_buy_cable() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) { wp_send_json_error(['message' => __('Auth required', 'matrix-mlm')]); }

        $smartcard = sanitize_text_field($_POST['smartcard_number'] ?? '');
        $plan_id = sanitize_text_field($_POST['plan_id'] ?? '');
        $provider = sanitize_text_field($_POST['provider'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);

        if (empty($smartcard) || empty($plan_id) || empty($provider) || $amount <= 0) {
            wp_send_json_error(['message' => __('All fields are required', 'matrix-mlm')]);
        }

        $wallet = new Matrix_MLM_Wallet();
        if ($wallet->get_balance($user_id) < $amount) {
            wp_send_json_error(['message' => __('Insufficient balance', 'matrix-mlm')]);
        }
        $wallet->debit($user_id, $amount, 'billing_cable', sprintf(__('Cable TV %s - %s', 'matrix-mlm'), $provider, $smartcard));

        $result = $this->buy_cable($smartcard, $plan_id, $provider);
        if (is_wp_error($result)) {
            $wallet->credit($user_id, $amount, 'billing_refund', __('Cable subscription failed - refund', 'matrix-mlm'));
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        $this->log_transaction($user_id, 'cable', $amount, ['smartcard' => $smartcard, 'provider' => $provider, 'plan_id' => $plan_id], $result);
        wp_send_json_success(['message' => __('Cable subscription purchased successfully!', 'matrix-mlm')]);
    }

    public function ajax_list_discos() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');
        if (!is_user_logged_in()) { wp_send_json_error(['message' => __('Auth required', 'matrix-mlm')]); }

        $discos = $this->list_discos();
        if (is_wp_error($discos)) { wp_send_json_error(['message' => $discos->get_error_message()]); }
        wp_send_json_success(['discos' => $discos]);
    }

    public function ajax_verify_meter() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');
        if (!is_user_logged_in()) { wp_send_json_error(['message' => __('Auth required', 'matrix-mlm')]); }

        $meter_number = sanitize_text_field($_POST['meter_number'] ?? '');
        $disco = sanitize_text_field($_POST['disco'] ?? '');
        $meter_type = sanitize_text_field($_POST['meter_type'] ?? 'prepaid');

        if (empty($meter_number) || empty($disco)) {
            wp_send_json_error(['message' => __('Meter number and disco are required', 'matrix-mlm')]);
        }

        $result = $this->verify_meter($meter_number, $disco, $meter_type);
        if (is_wp_error($result)) { wp_send_json_error(['message' => $result->get_error_message()]); }

        wp_send_json_success(['meter' => $result['data'] ?? $result]);
    }

    public function ajax_buy_electricity() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) { wp_send_json_error(['message' => __('Auth required', 'matrix-mlm')]); }

        $meter_number = sanitize_text_field($_POST['meter_number'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $disco = sanitize_text_field($_POST['disco'] ?? '');
        $meter_type = sanitize_text_field($_POST['meter_type'] ?? 'prepaid');

        if (empty($meter_number) || $amount <= 0 || empty($disco)) {
            wp_send_json_error(['message' => __('All fields are required', 'matrix-mlm')]);
        }

        $wallet = new Matrix_MLM_Wallet();
        if ($wallet->get_balance($user_id) < $amount) {
            wp_send_json_error(['message' => __('Insufficient balance', 'matrix-mlm')]);
        }
        $wallet->debit($user_id, $amount, 'billing_electricity', sprintf(__('Electricity %s - %s', 'matrix-mlm'), $disco, $meter_number));

        $result = $this->buy_electricity($meter_number, $amount, $disco, $meter_type);
        if (is_wp_error($result)) {
            $wallet->credit($user_id, $amount, 'billing_refund', __('Electricity purchase failed - refund', 'matrix-mlm'));
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        $token = $result['data']['token'] ?? $result['token'] ?? '';
        $this->log_transaction($user_id, 'electricity', $amount, ['meter' => $meter_number, 'disco' => $disco, 'type' => $meter_type, 'token' => $token], $result);

        $msg = __('Electricity purchased successfully!', 'matrix-mlm');
        if ($token) { $msg .= ' Token: ' . $token; }
        wp_send_json_success(['message' => $msg, 'token' => $token]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function log_transaction($user_id, $type, $amount, $details, $api_response) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'matrix_billing_transactions', [
            'user_id' => $user_id,
            'type' => $type,
            'amount' => $amount,
            'details' => json_encode($details),
            'api_response' => json_encode($api_response),
            'status' => 'completed',
            'created_at' => current_time('mysql'),
        ]);
    }

    private function make_request($method, $endpoint, $body = null) {
        $base_url = get_option('matrix_mlm_fintava_base_url', '') ?: 'https://dev.fintavapay.com/api/dev';
        $url = rtrim($base_url, '/') . $endpoint;
        $secret_key = get_option('matrix_mlm_fintava_secret_key', '');

        $args = [
            'method' => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $secret_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
        ];

        if ($body && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $args['body'] = json_encode($body);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) return $response;

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code >= 400) {
            return new WP_Error('fintava_billing_error', $body['message'] ?? sprintf(__('API Error (HTTP %d)', 'matrix-mlm'), $status_code));
        }

        return $body;
    }

    /**
     * Create billing transactions table
     */
    public static function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'matrix_billing_transactions';

        $sql = "CREATE TABLE $table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            type enum('airtime','data','cable','electricity') NOT NULL,
            amount decimal(12,2) NOT NULL,
            details text,
            api_response text,
            status enum('pending','completed','failed') NOT NULL DEFAULT 'completed',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY type (type)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Get user billing history
     */
    public function get_user_history($user_id, $type = null, $limit = 20) {
        global $wpdb;
        $where = "WHERE user_id = %d";
        $params = [$user_id];
        if ($type) { $where .= " AND type = %s"; $params[] = $type; }
        $params[] = $limit;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_billing_transactions $where ORDER BY created_at DESC LIMIT %d", $params
        ));
    }
}
