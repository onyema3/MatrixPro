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
 *
 * Funds flow:
 *   The Fintava billing API debits the merchant's master Fintava
 *   account when these endpoints are called. To make the user pay
 *   for what they actually consume, every bill-purchase handler in
 *   this class debits the user's MatrixPro internal wallet
 *   (matrix_user_meta.balance) BEFORE calling the upstream API.
 *   On API failure the wallet is refunded; on API success the
 *   debit stands and the merchant has been reimbursed by the user.
 *
 *   Previously these handlers ran the API call without ever
 *   debiting the user, so any logged-in member could buy unlimited
 *   airtime / data / cable / electricity at the merchant's expense.
 *   See security audit C1.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Fintava_Billing {

    /**
     * Per-transaction caps for each bill type. Operators can override
     * via the matrix_mlm_fintava_billing_caps option (assoc array
     * keyed by type) but these defaults bound the worst-case loss on
     * a single fraudulent purchase.
     */
    const DEFAULT_CAPS = [
        'airtime'     => 50000,    // 50,000
        'data'        => 50000,
        'cable'       => 100000,
        'electricity' => 500000,
    ];

    /** Lower bound — refuse zero/negative or absurdly small purchases. */
    const MIN_AMOUNT = 50;

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

        $cap_check = self::validate_amount('airtime', $amount);
        if (is_wp_error($cap_check)) {
            wp_send_json_error(['message' => $cap_check->get_error_message()]);
        }

        $debit = self::debit_for_purchase($user_id, $amount, 'airtime', sprintf(__('Airtime purchase to %s', 'matrix-mlm'), $phone));
        if (is_wp_error($debit)) {
            wp_send_json_error(['message' => $debit->get_error_message()]);
        }
        $debit_reference = $debit;

        $result = $this->buy_airtime($phone, $amount, $network);
        if (is_wp_error($result)) {
            self::refund_failed_purchase($user_id, $amount, 'airtime', $debit_reference, $result->get_error_message());
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        $this->log_transaction($user_id, 'airtime', $amount, ['phone' => $phone, 'network' => $network, 'debit_ref' => $debit_reference], $result);
        wp_send_json_success(['message' => sprintf(__('%s%s airtime sent to %s (debited from your wallet)', 'matrix-mlm'), get_option('matrix_mlm_currency_symbol', '₦'), number_format($amount), $phone)]);
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

        $cap_check = self::validate_amount('data', $amount);
        if (is_wp_error($cap_check)) {
            wp_send_json_error(['message' => $cap_check->get_error_message()]);
        }

        $debit = self::debit_for_purchase($user_id, $amount, 'data', sprintf(__('Data bundle to %s', 'matrix-mlm'), $phone));
        if (is_wp_error($debit)) {
            wp_send_json_error(['message' => $debit->get_error_message()]);
        }
        $debit_reference = $debit;

        $result = $this->buy_data($phone, $plan_id, $network);
        if (is_wp_error($result)) {
            self::refund_failed_purchase($user_id, $amount, 'data', $debit_reference, $result->get_error_message());
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        $this->log_transaction($user_id, 'data', $amount, ['phone' => $phone, 'network' => $network, 'plan_id' => $plan_id, 'debit_ref' => $debit_reference], $result);
        wp_send_json_success(['message' => __('Data bundle purchased successfully! (debited from your wallet)', 'matrix-mlm')]);
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

        $cap_check = self::validate_amount('cable', $amount);
        if (is_wp_error($cap_check)) {
            wp_send_json_error(['message' => $cap_check->get_error_message()]);
        }

        $debit = self::debit_for_purchase($user_id, $amount, 'cable', sprintf(__('Cable subscription %s', 'matrix-mlm'), $provider));
        if (is_wp_error($debit)) {
            wp_send_json_error(['message' => $debit->get_error_message()]);
        }
        $debit_reference = $debit;

        $result = $this->buy_cable($smartcard, $plan_id, $provider);
        if (is_wp_error($result)) {
            self::refund_failed_purchase($user_id, $amount, 'cable', $debit_reference, $result->get_error_message());
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        $this->log_transaction($user_id, 'cable', $amount, ['smartcard' => $smartcard, 'provider' => $provider, 'plan_id' => $plan_id, 'debit_ref' => $debit_reference], $result);
        wp_send_json_success(['message' => __('Cable subscription purchased successfully! (debited from your wallet)', 'matrix-mlm')]);
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

        $cap_check = self::validate_amount('electricity', $amount);
        if (is_wp_error($cap_check)) {
            wp_send_json_error(['message' => $cap_check->get_error_message()]);
        }

        $debit = self::debit_for_purchase($user_id, $amount, 'electricity', sprintf(__('Electricity purchase for meter %s', 'matrix-mlm'), $meter_number));
        if (is_wp_error($debit)) {
            wp_send_json_error(['message' => $debit->get_error_message()]);
        }
        $debit_reference = $debit;

        $result = $this->buy_electricity($meter_number, $amount, $disco, $meter_type);
        if (is_wp_error($result)) {
            self::refund_failed_purchase($user_id, $amount, 'electricity', $debit_reference, $result->get_error_message());
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        $token = $result['data']['token'] ?? $result['token'] ?? '';
        $this->log_transaction($user_id, 'electricity', $amount, ['meter' => $meter_number, 'disco' => $disco, 'type' => $meter_type, 'token' => $token, 'debit_ref' => $debit_reference], $result);

        $msg = __('Electricity purchased successfully! (debited from your wallet)', 'matrix-mlm');
        if ($token) { $msg .= ' Token: ' . $token; }
        wp_send_json_success(['message' => $msg, 'token' => $token]);
    }

    // =========================================================================
    // INTERNAL: USER WALLET DEBIT / REFUND
    // =========================================================================

    /**
     * Validate that the requested amount is within the per-bill cap and
     * is not below the floor. Operators can override caps via the
     * matrix_mlm_fintava_billing_caps option.
     *
     * @return true|WP_Error
     */
    private static function validate_amount($type, $amount) {
        if ($amount < self::MIN_AMOUNT) {
            return new WP_Error('amount_too_low', sprintf(
                /* translators: %1$s: currency symbol, %2$s: minimum amount */
                __('Minimum bill amount is %1$s%2$s.', 'matrix-mlm'),
                get_option('matrix_mlm_currency_symbol', '₦'),
                number_format(self::MIN_AMOUNT, 2)
            ));
        }

        $caps = get_option('matrix_mlm_fintava_billing_caps', []);
        if (!is_array($caps)) { $caps = []; }
        $caps = array_merge(self::DEFAULT_CAPS, $caps);
        $cap = isset($caps[$type]) ? floatval($caps[$type]) : 0;

        if ($cap > 0 && $amount > $cap) {
            return new WP_Error('amount_over_cap', sprintf(
                /* translators: %1$s: bill type, %2$s: currency symbol, %3$s: cap */
                __('%1$s purchases are capped at %2$s%3$s per transaction.', 'matrix-mlm'),
                ucfirst($type),
                get_option('matrix_mlm_currency_symbol', '₦'),
                number_format($cap, 2)
            ));
        }
        return true;
    }

    /**
     * Debit the user's MatrixPro wallet for a bill purchase.
     *
     * Returns a unique reference string on success, or WP_Error on
     * failure (insufficient funds is the most common case — surfaced
     * to the user with a helpful message).
     *
     * @return string|WP_Error reference identifier
     */
    private static function debit_for_purchase($user_id, $amount, $type, $description) {
        $wallet = new Matrix_MLM_Wallet();
        if ($wallet->get_balance($user_id) < $amount) {
            return new WP_Error('insufficient_balance', __('Insufficient wallet balance. Please fund your wallet first.', 'matrix-mlm'));
        }

        $reference = 'BILL-' . $type . '-' . $user_id . '-' . wp_generate_uuid4();
        $result = $wallet->debit(
            $user_id,
            $amount,
            'bill_payment',
            $description,
            $reference
        );
        if ($result === false) {
            // debit() already logged; surface the same insufficient/locked message.
            return new WP_Error('debit_failed', __('Could not debit wallet. Please try again or contact support.', 'matrix-mlm'));
        }
        return $reference;
    }

    /**
     * Refund a failed bill purchase by crediting the user's wallet
     * back. Records the refund as a wallet transaction tied to the
     * original debit reference so reconciliation is straightforward.
     */
    private static function refund_failed_purchase($user_id, $amount, $type, $debit_reference, $reason) {
        $wallet = new Matrix_MLM_Wallet();
        $refund_ref = 'REFUND-' . $debit_reference;
        $description = sprintf(
            /* translators: %1$s: bill type, %2$s: failure reason */
            __('Refund: %1$s purchase failed (%2$s)', 'matrix-mlm'),
            $type,
            $reason
        );
        $credited = $wallet->credit($user_id, $amount, 'bill_refund', $description, $refund_ref);

        if ($credited === false) {
            // Catastrophic: we debited but couldn't refund. Alert ops loudly.
            error_log(sprintf(
                '[Matrix Fintava Billing] CRITICAL: refund failed user_id=%d amount=%.2f debit_ref=%s reason=%s',
                $user_id, $amount, $debit_reference, $reason
            ));
            Matrix_MLM_Notifications::send_admin_notification(
                'fintava_bill_refund_failed',
                sprintf(
                    __('Bill purchase refund FAILED for user #%1$d, amount %2$.2f, debit ref %3$s. Manual intervention required.', 'matrix-mlm'),
                    $user_id, $amount, $debit_reference
                )
            );
        }
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
        // Delegate base URL resolution to Matrix_MLM_Fintava so the billing
        // sub-gateway always honours the same environment selector (and any
        // wp-config override) the main gateway is using.
        $url = Matrix_MLM_Fintava::get_base_url() . $endpoint;
        $secret_key = get_option('matrix_mlm_fintava_secret_key', '');

        if (empty($secret_key)) {
            return new WP_Error(
                'fintava_not_configured',
                __('Fintava Pay is not configured. Please add your Live API Key in admin.', 'matrix-mlm')
            );
        }

        // Merchant ID — resolved by the main Fintava class so the rule
        // lives in one place. Empty string means "not configured" and is
        // a hard error here: dispatching with an empty Merchant-Id header
        // either fails on Fintava's side or, worse, silently routes under
        // the wrong identity if Fintava ever defaults it server-side.
        $merchant_id = Matrix_MLM_Fintava::resolve_merchant_id();
        if ($merchant_id === '') {
            return new WP_Error(
                'fintava_not_configured',
                __('Fintava Pay merchant ID is not configured. Set it in admin under Gateways > Fintava.', 'matrix-mlm')
            );
        }

        $args = [
            'method' => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $secret_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Merchant-Id' => $merchant_id,
            ],
            'timeout' => 30,
        ];

        if ($body && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
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
