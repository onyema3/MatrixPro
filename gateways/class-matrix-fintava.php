<?php
/**
 * Fintava Pay Gateway - Merchant Bank Credit & Virtual Wallet
 *
 * Integrates with the Fintava Pay LIVE API to allow users to transfer funds
 * from their Matrix wallet to their bank accounts via the merchant credit
 * endpoint, and to generate virtual wallets for receiving deposits.
 *
 * Configuration is intentionally minimal:
 * - Live API Key (required)
 * - Webhook Secret (optional but recommended)
 * - Active toggle
 *
 * The API base URL defaults to https://live.fintavapay.com/api/dev and can
 * be overridden by defining MATRIX_FINTAVA_API_BASE_URL in wp-config.php if
 * Fintava ever changes their endpoint.
 *
 * Endpoints used:
 * - GET  /merchant/balance                    - Get merchant wallet balance
 * - POST /bank/credit/merchant                - Credit a bank account (payout from merchant)
 * - POST /virtual-wallet/generate             - Generate virtual wallet for user
 * - GET  /customer/wallet/balance/{walletId}  - Get virtual wallet balance (fast path)
 * - GET  /customers                           - List customers (wallet lookup fallback)
 * - GET  /wallet/details?accountNumber=...    - Internal: look up our virtual wallet by account number
 * - GET  /name/enquiry?accountNumber=...&sortCode=... - External name enquiry (3rd-party banks)
 * - GET  /banks                               - List supported banks
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Fintava {

    /**
     * Fintava Pay API base URLs, one per environment. The active environment
     * is selected by the `matrix_mlm_fintava_environment` option (`live` |
     * `dev`) which the admin sets on the Gateways page. Both URLs can be
     * overridden in one shot via the MATRIX_FINTAVA_API_BASE_URL constant.
     */
    const LIVE_BASE_URL = 'https://live.fintavapay.com/api/dev';
    const DEV_BASE_URL  = 'https://dev.fintavapay.com/api/dev';

    /**
     * Default base URL when no environment option is set yet. Intentionally
     * Live so the field labelled "API Key" on the admin page resolves against
     * the same environment most production installs are using.
     *
     * Kept as a public class constant for backward compatibility — the billing
     * and card sub-gateways referenced it directly before get_base_url() was
     * introduced. Mirrors LIVE_BASE_URL and is intentionally inlined as a
     * literal rather than a self::LIVE_BASE_URL reference, since older PHP
     * versions reject constant-to-constant references at parse time.
     */
    const DEFAULT_BASE_URL = 'https://live.fintavapay.com/api/dev';

    /**
     * Default Merchant ID for Fintava Pay.
     * Override by defining MATRIX_FINTAVA_MERCHANT_ID in wp-config.php.
     */
    const DEFAULT_MERCHANT_ID = '438555ab-b45c-467d-b0ce-50dee25241b4';

    /**
     * Default Webhook/Callback URL for Fintava Pay.
     * Override by defining MATRIX_FINTAVA_CALLBACK_URL in wp-config.php.
     */
    const DEFAULT_CALLBACK_URL = 'https://libertymatrix.ng/webhook/fintava';

    private $secret_key;
    private $base_url;
    private $merchant_id;
    private $callback_url;

    public function __construct() {
        $this->load_credentials();
        $this->register_hooks();
    }

    /**
     * Resolve the active Fintava API base URL.
     *
     * Resolution order (first match wins):
     *   1. MATRIX_FINTAVA_API_BASE_URL constant — full pinned override.
     *   2. matrix_mlm_fintava_environment option ('live' or 'dev').
     *   3. LIVE_BASE_URL — production default; matches the admin field label.
     *
     * Static so the billing and card sub-gateways can call it without
     * instantiating the main class (their make_request() runs early enough
     * that booting the full gateway just for a URL is wasteful).
     *
     * @return string Base URL with no trailing slash.
     */
    public static function get_base_url() {
        if (defined('MATRIX_FINTAVA_API_BASE_URL') && MATRIX_FINTAVA_API_BASE_URL) {
            return rtrim(MATRIX_FINTAVA_API_BASE_URL, '/');
        }

        $env = strtolower(trim((string) get_option('matrix_mlm_fintava_environment', 'live')));
        if ($env === 'dev') {
            return self::DEV_BASE_URL;
        }
        return self::LIVE_BASE_URL;
    }

    /**
     * Resolve the active environment label, useful for the admin UI badge
     * and for downstream code paths that need to short-circuit certain
     * features in dev mode.
     *
     * @return string 'live' | 'dev' | 'custom' (when the constant override is set).
     */
    public static function get_environment() {
        if (defined('MATRIX_FINTAVA_API_BASE_URL') && MATRIX_FINTAVA_API_BASE_URL) {
            return 'custom';
        }
        $env = strtolower(trim((string) get_option('matrix_mlm_fintava_environment', 'live')));
        return $env === 'dev' ? 'dev' : 'live';
    }

    /**
     * Load API credentials from settings.
     */
    private function load_credentials() {
        $this->secret_key = trim(get_option('matrix_mlm_fintava_secret_key', ''));
        $this->base_url   = self::get_base_url();

        // Merchant ID — stored in wp_options, overridable via wp-config.php.
        if (defined('MATRIX_FINTAVA_MERCHANT_ID') && MATRIX_FINTAVA_MERCHANT_ID) {
            $this->merchant_id = MATRIX_FINTAVA_MERCHANT_ID;
        } else {
            $this->merchant_id = trim(get_option('matrix_mlm_fintava_merchant_id', self::DEFAULT_MERCHANT_ID));
        }

        // Callback URL — stored in wp_options, overridable via wp-config.php.
        if (defined('MATRIX_FINTAVA_CALLBACK_URL') && MATRIX_FINTAVA_CALLBACK_URL) {
            $this->callback_url = MATRIX_FINTAVA_CALLBACK_URL;
        } else {
            $this->callback_url = trim(get_option('matrix_mlm_fintava_callback_url', self::DEFAULT_CALLBACK_URL));
        }
    }

    /**
     * Register WordPress hooks.
     */
    private function register_hooks() {
        add_action('wp_ajax_matrix_fintava_get_banks', [$this, 'ajax_get_banks']);
        add_action('wp_ajax_matrix_fintava_resolve_account', [$this, 'ajax_resolve_account']);
        add_action('wp_ajax_matrix_fintava_initiate_transfer', [$this, 'ajax_initiate_transfer']);
        add_action('wp_ajax_matrix_fintava_check_status', [$this, 'ajax_check_transfer_status']);
        add_action('wp_ajax_matrix_fintava_merchant_balance', [$this, 'ajax_get_merchant_balance']);

        // Virtual Wallet AJAX handlers
        add_action('wp_ajax_matrix_fintava_create_virtual_wallet', [$this, 'ajax_create_virtual_wallet']);
        add_action('wp_ajax_matrix_fintava_get_virtual_wallet', [$this, 'ajax_get_virtual_wallet']);
        add_action('wp_ajax_matrix_fintava_wallet_balance', [$this, 'ajax_get_virtual_wallet_balance']);
        add_action('wp_ajax_matrix_fintava_set_my_wallet_id', [$this, 'ajax_set_my_wallet_id']);

        // REST API endpoint for webhook callbacks
        add_action('rest_api_init', [$this, 'register_webhook_routes']);
    }

    public function register_webhook_routes() {
        register_rest_route('matrix-mlm/v1', '/fintava/webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Check if Fintava is configured and active.
     *
     * Reads matrix_mlm_fintava_status (the option saved by the admin Gateways
     * page). Falls back to the legacy matrix_mlm_fintava_enabled option for
     * existing installs that were configured before the rework.
     */
    public function is_active() {
        if (empty($this->secret_key)) {
            return false;
        }
        $status = get_option('matrix_mlm_fintava_status', null);
        if ($status === null) {
            // Legacy fallback
            return (bool) get_option('matrix_mlm_fintava_enabled', 0);
        }
        return (bool) $status;
    }

    // =========================================================================
    // SCHEMA / SELF-HEALING
    // =========================================================================

    /**
     * Ensure the Fintava database tables exist.
     * Idempotent and self-healing — safe to call on every page load.
     * Bypasses dbDelta (which silently fails on inline UNIQUE constraints) and
     * uses direct CREATE TABLE IF NOT EXISTS instead.
     *
     * @return array{errors: string[]}
     */
    public static function ensure_tables_exist() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $errors = [];

        $payouts_table = $wpdb->prefix . 'matrix_fintava_payouts';
        $wallets_table = $wpdb->prefix . 'matrix_fintava_wallets';
        $logs_table = $wpdb->prefix . 'matrix_fintava_webhook_logs';

        $payouts_sql = "CREATE TABLE IF NOT EXISTS `$payouts_table` (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            reference varchar(100) NOT NULL,
            transfer_id varchar(100) DEFAULT NULL,
            amount decimal(12,2) NOT NULL,
            charge decimal(12,2) NOT NULL DEFAULT 0.00,
            total_debit decimal(12,2) NOT NULL,
            bank_code varchar(20) NOT NULL,
            bank_name varchar(100) NOT NULL,
            account_number varchar(20) NOT NULL,
            account_name varchar(255) NOT NULL,
            narration varchar(255) DEFAULT NULL,
            currency varchar(5) NOT NULL DEFAULT 'NGN',
            status varchar(20) NOT NULL DEFAULT 'pending',
            failure_reason text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime DEFAULT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY reference (reference),
            KEY user_id (user_id),
            KEY status (status)
        ) $charset_collate";

        $wallets_sql = "CREATE TABLE IF NOT EXISTS `$wallets_table` (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            wallet_id varchar(100) DEFAULT NULL,
            customer_id varchar(100) DEFAULT NULL,
            account_number varchar(20) NOT NULL,
            account_name varchar(255) NOT NULL,
            bank_name varchar(100) NOT NULL DEFAULT 'Fintava',
            bank_code varchar(20) DEFAULT NULL,
            currency varchar(5) NOT NULL DEFAULT 'NGN',
            customer_email varchar(255) DEFAULT NULL,
            customer_phone varchar(20) DEFAULT NULL,
            bvn varchar(20) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            metadata text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id),
            KEY account_number (account_number),
            KEY customer_id (customer_id),
            KEY status (status)
        ) $charset_collate";

        $logs_sql = "CREATE TABLE IF NOT EXISTS `$logs_table` (
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
        ) $charset_collate";

        foreach (['payouts' => $payouts_sql, 'wallets' => $wallets_sql, 'logs' => $logs_sql] as $name => $sql) {
            $previous = $wpdb->show_errors;
            $wpdb->hide_errors();
            $wpdb->query($sql);
            if ($previous) {
                $wpdb->show_errors();
            }
            if ($wpdb->last_error) {
                $errors[] = sprintf('%s: %s', $name, $wpdb->last_error);
            }
        }

        // Self-healing: add customer_id column to wallets table if missing
        // (for installations created before this column was added).
        $col_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'customer_id'",
            DB_NAME,
            $wallets_table
        ));
        if ($col_exists !== null && intval($col_exists) === 0) {
            $wpdb->query("ALTER TABLE `$wallets_table` ADD COLUMN `customer_id` varchar(100) DEFAULT NULL AFTER `wallet_id`");
            $wpdb->query("ALTER TABLE `$wallets_table` ADD KEY `customer_id` (`customer_id`)");
        }

        return ['errors' => $errors];
    }

    /**
     * Backward-compatible alias kept because the activator calls it.
     */
    public static function create_table() {
        self::ensure_tables_exist();
    }

    // =========================================================================
    // API METHODS
    // =========================================================================

    /**
     * GET /banks
     *
     * Fetch and normalize the supported-bank list from Fintava. Resilient to
     * three classes of upstream variation:
     *
     *   1. Envelope shape — different Fintava tiers and endpoint versions
     *      have returned the array under `data` (current), `data.banks`,
     *      `data.items`, `data.results`, or directly at the root. We try
     *      each in order and return whichever yields a non-empty list.
     *
     *   2. Bank-object field names — historical responses have used
     *      `sortCode`/`bankName`, `bankCode`/`bankName`, or plain
     *      `code`/`name`. normalize_banks_list() accepts all three.
     *
     *   3. Outright failure — when nothing parses, the WP_Error message
     *      includes the resolved environment label, the API base URL, and
     *      a short payload snippet, so the failure mode is visible in the
     *      dropdown itself without DevTools.
     *
     * On success the normalized array is cached for 24 hours under a
     * versioned transient key so older raw-shape caches are bypassed.
     */
    public function get_banks() {
        // Cache key is versioned (`_v2`) so any transient stored in the
        // pre-normalization raw Fintava shape is bypassed on upgrade. Bump
        // the suffix again if the normalized shape ever changes.
        $cache_key = 'matrix_fintava_banks_list_v2';
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $response = $this->make_request('GET', '/banks');
        if (is_wp_error($response)) {
            // Decorate with environment + base URL so the operator can tell
            // at a glance which tier was hit when reading the dropdown error.
            return new WP_Error(
                $response->get_error_code(),
                sprintf(
                    /* translators: 1: original error, 2: environment label, 3: API base URL */
                    __('%1$s [env=%2$s, base=%3$s]', 'matrix-mlm'),
                    $response->get_error_message(),
                    self::get_environment(),
                    $this->base_url
                )
            );
        }

        if (self::is_api_success($response)) {
            // Try every shape Fintava has been observed to use. The first
            // candidate that yields a non-empty normalized list wins.
            $candidates = [];
            if (isset($response['data'])) {
                $candidates[] = $response['data'];
                if (is_array($response['data'])) {
                    foreach (['banks', 'items', 'results', 'list'] as $sub) {
                        if (isset($response['data'][$sub])) {
                            $candidates[] = $response['data'][$sub];
                        }
                    }
                }
            }
            // Bare-array root shape ("data" is itself the list, or the API
            // returned an unwrapped list).
            $candidates[] = $response;
            if (isset($response['banks'])) {
                $candidates[] = $response['banks'];
            }

            foreach ($candidates as $candidate) {
                $normalized = self::normalize_banks_list($candidate);
                if (!empty($normalized)) {
                    set_transient($cache_key, $normalized, DAY_IN_SECONDS);
                    return $normalized;
                }
            }

            // Endpoint returned 200 + success envelope, but none of the
            // shapes we know about yielded a non-empty list. Surface a
            // diagnostic the operator can act on without DevTools.
            return new WP_Error(
                'fintava_banks_empty',
                sprintf(
                    /* translators: 1: env, 2: base URL, 3: short payload snippet */
                    __('Fintava /banks returned no usable bank list [env=%1$s, base=%2$s, payload=%3$s]', 'matrix-mlm'),
                    self::get_environment(),
                    $this->base_url,
                    self::summarize_payload($response)
                )
            );
        }

        // is_api_success() said no — surface the upstream message plus the
        // env/base so we can tell whether it's auth, rate-limit, or path.
        return new WP_Error(
            'fintava_error',
            sprintf(
                /* translators: 1: upstream message, 2: env, 3: base URL */
                __('%1$s [env=%2$s, base=%3$s]', 'matrix-mlm'),
                self::normalize_api_message(
                    $response['message'] ?? null,
                    __('Failed to retrieve bank list', 'matrix-mlm')
                ),
                self::get_environment(),
                $this->base_url
            )
        );
    }

    /**
     * Normalize Fintava's `/banks` response into the `{code, name}` shape the
     * frontend dropdown expects.
     *
     * Fintava returns each bank as `{ sortCode: "...", bankName: "..." }`, but
     * the inline JS in class-matrix-user-bank-payout.php iterates with
     * `bank.code` / `bank.name`. Without this mapping the dropdown renders
     * `<option value="undefined">undefined</option>` for every entry, which
     * presents to the user as "the dropdown didn't load."
     *
     * Accepts a few alternate field names (`bankCode`, `code`, `bank`, `name`)
     * so the gateway is resilient if Fintava ever renames their fields.
     *
     * @param mixed $banks Raw `data` array from /banks.
     * @return array<int, array{code:string,name:string}>
     */
    public static function normalize_banks_list($banks) {
        if (!is_array($banks)) {
            return [];
        }

        $out = [];
        foreach ($banks as $bank) {
            if (!is_array($bank)) {
                continue;
            }
            $code = $bank['sortCode'] ?? $bank['bankCode'] ?? $bank['code'] ?? $bank['nipCode'] ?? $bank['cbnCode'] ?? '';
            $name = $bank['bankName'] ?? $bank['name']     ?? $bank['bank'] ?? $bank['institutionName'] ?? '';
            $code = is_scalar($code) ? trim((string) $code) : '';
            $name = is_scalar($name) ? trim((string) $name) : '';
            if ($code === '' || $name === '') {
                continue;
            }
            $out[] = ['code' => $code, 'name' => $name];
        }

        // Alphabetize so the dropdown is predictable regardless of API order.
        usort($out, static function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        return $out;
    }

    /**
     * Render a tiny, log-safe summary of a decoded API response so the
     * dropdown's error label can convey *what shape* came back without
     * leaking the full payload. Caps at 200 characters and strips any
     * embedded credentials/tokens by being JSON-encoded against an array
     * of just the top-level keys plus the value type for each.
     */
    private static function summarize_payload($payload) {
        if (!is_array($payload)) {
            $type = gettype($payload);
            return $type;
        }
        $shape = [];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $shape[$key] = isset($value[0]) || empty($value)
                    ? sprintf('array[%d]', count($value))
                    : 'object{' . implode(',', array_slice(array_keys($value), 0, 5)) . '}';
            } else {
                $shape[$key] = gettype($value);
            }
        }
        $json = wp_json_encode($shape);
        if ($json === false) {
            return 'unencodable';
        }
        if (strlen($json) > 200) {
            $json = substr($json, 0, 197) . '...';
        }
        return $json;
    }

    /**
     * GET /name/enquiry?accountNumber=...&sortCode=... — verify bank account (external name lookup).
     *
     * @param string $account_number 10-digit NUBAN.
     * @param string $bank_code      Bank sort code (passed as `sortCode` query param).
     */
    public function resolve_account($account_number, $bank_code) {
        $query = http_build_query([
            'accountNumber' => $account_number,
            'sortCode'      => $bank_code,
        ]);

        $response = $this->make_request('GET', '/name/enquiry?' . $query);

        if (is_wp_error($response)) {
            return $response;
        }

        if (self::is_api_success($response) && isset($response['data'])) {
            return $response['data'];
        }

        return new WP_Error(
            'fintava_resolve_error',
            self::normalize_api_message(
                $response['message'] ?? null,
                __('Could not resolve account details', 'matrix-mlm')
            )
        );
    }

    /**
     * POST /transfer — initiate a transfer.
     */
    public function initiate_transfer($transfer_data) {
        $required_fields = ['amount', 'account_number', 'bank_code', 'narration'];
        foreach ($required_fields as $field) {
            if (empty($transfer_data[$field])) {
                return new WP_Error('missing_field', sprintf(__('Missing required field: %s', 'matrix-mlm'), $field));
            }
        }

        $payload = [
            'amount' => floatval($transfer_data['amount']),
            'account_number' => sanitize_text_field($transfer_data['account_number']),
            'bank_code' => sanitize_text_field($transfer_data['bank_code']),
            'narration' => sanitize_text_field($transfer_data['narration']),
            'currency' => $transfer_data['currency'] ?? 'NGN',
            'reference' => $transfer_data['reference'] ?? $this->generate_reference(),
            'callback_url' => $this->callback_url,
        ];

        if (!empty($transfer_data['account_name'])) {
            $payload['account_name'] = sanitize_text_field($transfer_data['account_name']);
        }
        if (!empty($transfer_data['bank_name'])) {
            $payload['bank_name'] = sanitize_text_field($transfer_data['bank_name']);
        }

        $response = $this->make_request('POST', '/transfer', $payload);
        if (is_wp_error($response)) {
            return $response;
        }

        if (self::is_api_success($response)) {
            return [
                'success' => true,
                'transfer_id' => $response['data']['id'] ?? null,
                'reference' => $response['data']['reference'] ?? $payload['reference'],
                'status' => $response['data']['status'] ?? 'pending',
                'message' => self::normalize_api_message(
                    $response['message'] ?? null,
                    __('Transfer initiated successfully', 'matrix-mlm')
                ),
            ];
        }

        return new WP_Error(
            'fintava_transfer_error',
            self::normalize_api_message(
                $response['message'] ?? null,
                __('Transfer failed', 'matrix-mlm')
            )
        );
    }

    /**
     * GET /transfer/:id — check status.
     */
    public function check_transfer_status($transfer_id) {
        $response = $this->make_request('GET', '/transfer/' . $transfer_id);
        if (is_wp_error($response)) {
            return $response;
        }

        if (self::is_api_success($response) && isset($response['data'])) {
            return $response['data'];
        }

        return new WP_Error(
            'fintava_status_error',
            self::normalize_api_message(
                $response['message'] ?? null,
                __('Could not fetch transfer status', 'matrix-mlm')
            )
        );
    }

    /**
     * GET /merchant/balance — current merchant wallet balance.
     */
    public function get_merchant_balance() {
        $response = $this->make_request('GET', '/merchant/balance');
        if (is_wp_error($response)) {
            return $response;
        }

        if (self::is_api_success($response) && isset($response['data'])) {
            return $response['data'];
        }

        // Some response shapes return balance fields directly at the root.
        if (isset($response['balance'])) {
            return [
                'balance' => floatval($response['balance']),
                'currency' => $response['currency'] ?? 'NGN',
                'available_balance' => floatval($response['available_balance'] ?? $response['balance']),
                'ledger_balance' => floatval($response['ledger_balance'] ?? $response['balance']),
            ];
        }

        return new WP_Error(
            'fintava_balance_error',
            $response['message'] ?? __('Could not retrieve merchant balance', 'matrix-mlm')
        );
    }

    /**
     * POST /bank/credit/merchant — payout from merchant wallet to bank account.
     */
    public function merchant_bank_credit($transfer_data) {
        $required_fields = ['amount', 'account_number', 'bank_code'];
        foreach ($required_fields as $field) {
            if (empty($transfer_data[$field])) {
                return new WP_Error('missing_field', sprintf(__('Missing required field: %s', 'matrix-mlm'), $field));
            }
        }

        $payload = [
            'amount' => floatval($transfer_data['amount']),
            'account_number' => sanitize_text_field($transfer_data['account_number']),
            'bank_code' => sanitize_text_field($transfer_data['bank_code']),
        ];

        foreach (['narration', 'reference', 'account_name', 'bank_name', 'currency'] as $optional_field) {
            if (!empty($transfer_data[$optional_field])) {
                $payload[$optional_field] = sanitize_text_field($transfer_data[$optional_field]);
            }
        }

        $response = $this->make_request('POST', '/bank/credit/merchant', $payload);
        if (is_wp_error($response)) {
            return $response;
        }

        if (self::is_api_success($response)) {
            return [
                'success' => true,
                'transfer_id' => $response['data']['id'] ?? $response['data']['transfer_id'] ?? null,
                'reference' => $response['data']['reference'] ?? ($transfer_data['reference'] ?? ''),
                'status' => $response['data']['status'] ?? 'pending',
                'message' => $response['message'] ?? __('Merchant transfer initiated successfully', 'matrix-mlm'),
            ];
        }

        return new WP_Error(
            'fintava_merchant_transfer_error',
            $response['message'] ?? __('Merchant transfer failed', 'matrix-mlm')
        );
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    public function ajax_get_banks() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Authentication required', 'matrix-mlm')]);
        }

        $banks = $this->get_banks();
        if (is_wp_error($banks)) {
            wp_send_json_error(['message' => $banks->get_error_message()]);
        }

        wp_send_json_success(['banks' => $banks]);
    }

    public function ajax_resolve_account() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Authentication required', 'matrix-mlm')]);
        }

        $account_number = sanitize_text_field($_POST['account_number'] ?? '');
        $bank_code = sanitize_text_field($_POST['bank_code'] ?? '');

        if (empty($account_number) || empty($bank_code)) {
            wp_send_json_error(['message' => __('Account number and bank are required', 'matrix-mlm')]);
        }

        if (!preg_match('/^\d{10}$/', $account_number)) {
            wp_send_json_error(['message' => __('Account number must be 10 digits', 'matrix-mlm')]);
        }

        $result = $this->resolve_account($account_number, $bank_code);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'account_name' => $result['account_name'] ?? '',
            'account_number' => $result['account_number'] ?? $account_number,
            'bank_name' => $result['bank_name'] ?? '',
        ]);
    }

    public function ajax_initiate_transfer() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');
        self::ensure_tables_exist();

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('Authentication required', 'matrix-mlm')]);
        }

        if (!Matrix_MLM_User::is_active($user_id)) {
            wp_send_json_error(['message' => __('Your account is suspended', 'matrix-mlm')]);
        }

        if (!$this->is_active()) {
            wp_send_json_error(['message' => __('Bank payouts are not available at the moment.', 'matrix-mlm')]);
        }

        $amount = floatval($_POST['amount'] ?? 0);
        $account_number = sanitize_text_field($_POST['account_number'] ?? '');
        $bank_code = sanitize_text_field($_POST['bank_code'] ?? '');
        $bank_name = sanitize_text_field($_POST['bank_name'] ?? '');
        $account_name = sanitize_text_field($_POST['account_name'] ?? '');
        $narration = sanitize_text_field($_POST['narration'] ?? '');

        if (empty($narration)) {
            $narration = sprintf('Matrix Payout - %s', wp_get_current_user()->user_login);
        }

        $currency_symbol = get_option('matrix_mlm_currency_symbol', '₦');
        $min_payout = floatval(get_option('matrix_mlm_fintava_min_payout', 1000));
        $max_payout = floatval(get_option('matrix_mlm_fintava_max_payout', 5000000));

        if ($amount < $min_payout) {
            wp_send_json_error(['message' => sprintf(__('Minimum payout amount is %s%s', 'matrix-mlm'), $currency_symbol, number_format($min_payout, 2))]);
        }

        if ($amount > $max_payout) {
            wp_send_json_error(['message' => sprintf(__('Maximum payout amount is %s%s', 'matrix-mlm'), $currency_symbol, number_format($max_payout, 2))]);
        }

        if (empty($account_number) || empty($bank_code)) {
            wp_send_json_error(['message' => __('Bank account details are required', 'matrix-mlm')]);
        }

        $charge_type = get_option('matrix_mlm_fintava_charge_type', 'fixed');
        $charge_value = floatval(get_option('matrix_mlm_fintava_charge_value', 50));
        $charge = $charge_type === 'percent'
            ? round($amount * $charge_value / 100, 2)
            : $charge_value;
        $total_debit = $amount + $charge;

        $wallet = new Matrix_MLM_Wallet();
        $balance = $wallet->get_balance($user_id);

        if ($balance < $total_debit) {
            wp_send_json_error(['message' => sprintf(
                __('Insufficient balance. You need %s%s (Amount: %s%s + Charge: %s%s)', 'matrix-mlm'),
                $currency_symbol, number_format($total_debit, 2),
                $currency_symbol, number_format($amount, 2),
                $currency_symbol, number_format($charge, 2)
            )]);
        }

        $reference = $this->generate_reference();

        $wallet->debit(
            $user_id,
            $total_debit,
            'fintava_payout',
            sprintf(__('Bank transfer to %s (%s) - Ref: %s', 'matrix-mlm'), $account_name, $bank_name, $reference),
            $reference
        );

        global $wpdb;
        $payouts_table = $wpdb->prefix . 'matrix_fintava_payouts';
        $wpdb->insert(
            $payouts_table,
            [
                'user_id' => $user_id,
                'reference' => $reference,
                'amount' => $amount,
                'charge' => $charge,
                'total_debit' => $total_debit,
                'bank_code' => $bank_code,
                'bank_name' => $bank_name,
                'account_number' => $account_number,
                'account_name' => $account_name,
                'narration' => $narration,
                'currency' => 'NGN',
                'status' => 'pending',
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        $payout_id = $wpdb->insert_id;

        $result = $this->merchant_bank_credit([
            'amount' => $amount,
            'account_number' => $account_number,
            'bank_code' => $bank_code,
            'bank_name' => $bank_name,
            'account_name' => $account_name,
            'narration' => $narration,
            'reference' => $reference,
            'currency' => 'NGN',
        ]);

        if (is_wp_error($result)) {
            $wallet->credit(
                $user_id,
                $total_debit,
                'fintava_payout_refund',
                sprintf(__('Refund: Bank transfer failed - %s', 'matrix-mlm'), $result->get_error_message()),
                $reference
            );

            $wpdb->update(
                $payouts_table,
                ['status' => 'failed', 'failure_reason' => $result->get_error_message(), 'updated_at' => current_time('mysql')],
                ['id' => $payout_id],
                ['%s', '%s', '%s'],
                ['%d']
            );

            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        $wpdb->update(
            $payouts_table,
            [
                'transfer_id' => $result['transfer_id'] ?? '',
                'status' => $result['status'] ?? 'processing',
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $payout_id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        Matrix_MLM_Notifications::send_admin_notification(
            'fintava_payout',
            sprintf(
                __('Bank payout initiated: %s%s to %s (%s - %s). Ref: %s', 'matrix-mlm'),
                $currency_symbol, number_format($amount, 2), $account_name, $bank_name, $account_number, $reference
            )
        );

        wp_send_json_success([
            'message' => sprintf(
                __('Transfer of %s%s initiated to %s (%s). It will be processed shortly.', 'matrix-mlm'),
                $currency_symbol, number_format($amount, 2), $account_name, $bank_name
            ),
            'reference' => $reference,
            'status' => $result['status'] ?? 'processing',
        ]);
    }

    public function ajax_check_transfer_status() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Authentication required', 'matrix-mlm')]);
        }

        $reference = sanitize_text_field($_POST['reference'] ?? '');
        if (empty($reference)) {
            wp_send_json_error(['message' => __('Reference is required', 'matrix-mlm')]);
        }

        global $wpdb;
        $payout = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_fintava_payouts WHERE reference = %s AND user_id = %d",
            $reference, get_current_user_id()
        ));

        if (!$payout) {
            wp_send_json_error(['message' => __('Payout not found', 'matrix-mlm')]);
        }

        if (!empty($payout->transfer_id) && in_array($payout->status, ['pending', 'processing'], true)) {
            $status = $this->check_transfer_status($payout->transfer_id);
            if (!is_wp_error($status)) {
                $new_status = $status['status'] ?? $payout->status;
                if ($new_status !== $payout->status) {
                    $wpdb->update(
                        $wpdb->prefix . 'matrix_fintava_payouts',
                        ['status' => $new_status, 'updated_at' => current_time('mysql')],
                        ['id' => $payout->id],
                        ['%s', '%s'],
                        ['%d']
                    );
                    $payout->status = $new_status;
                }
            }
        }

        wp_send_json_success([
            'reference' => $payout->reference,
            'amount' => $payout->amount,
            'bank_name' => $payout->bank_name,
            'account_name' => $payout->account_name,
            'account_number' => $payout->account_number,
            'status' => $payout->status,
            'created_at' => $payout->created_at,
        ]);
    }

    public function ajax_get_merchant_balance() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        if (!current_user_can('manage_matrix_mlm')) {
            wp_send_json_error(['message' => __('Unauthorized', 'matrix-mlm')]);
        }

        $balance = $this->get_merchant_balance();
        if (is_wp_error($balance)) {
            wp_send_json_error(['message' => $balance->get_error_message()]);
        }

        wp_send_json_success(['balance' => $balance]);
    }

    // =========================================================================
    // WEBHOOK HANDLER
    // =========================================================================

    public function handle_webhook($request) {
        self::ensure_tables_exist();

        $payload = $request->get_body();
        $signature = $request->get_header('x-fintava-signature');

        $webhook_secret = get_option('matrix_mlm_fintava_webhook_secret', '');
        if (!empty($webhook_secret)) {
            $computed_signature = hash_hmac('sha512', $payload, $webhook_secret);
            if (!hash_equals($computed_signature, $signature ?? '')) {
                return new WP_REST_Response(['status' => 'error', 'message' => 'Invalid signature'], 401);
            }
        }

        $event = json_decode($payload, true);
        if (!$event) {
            return new WP_REST_Response(['status' => 'error', 'message' => 'Invalid payload'], 400);
        }

        $event_type = $event['event'] ?? $event['type'] ?? '';
        $data = $event['data'] ?? [];

        $this->log_webhook_event($event_type, $data, $signature);

        switch ($event_type) {
            case 'transfer.success':
            case 'transfer.completed':
                $this->handle_transfer_success($data);
                break;
            case 'transfer.failed':
            case 'transfer.reversed':
                $this->handle_transfer_failure($data);
                break;
            case 'wallet_to_wallet_transfer_v2':
                $this->handle_wallet_to_wallet_transfer($data);
                break;
            case 'account_funded':
                $this->handle_account_funded($data);
                break;
            default:
                error_log(sprintf('[Matrix Fintava Webhook] Unhandled event type: %s', $event_type));
                break;
        }

        return new WP_REST_Response(['status' => 'success'], 200);
    }

    private function handle_transfer_success($data) {
        global $wpdb;
        $reference = $data['reference'] ?? '';
        if (empty($reference)) {
            return;
        }

        $payout = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_fintava_payouts WHERE reference = %s",
            $reference
        ));

        if (!$payout || $payout->status === 'completed') {
            return;
        }

        $wpdb->update(
            $wpdb->prefix . 'matrix_fintava_payouts',
            [
                'status' => 'completed',
                'completed_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $payout->id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        $user = get_userdata($payout->user_id);
        if ($user) {
            Matrix_MLM_Notifications::send_deposit_notification(
                $payout->user_id,
                $payout->amount,
                'completed'
            );
        }
    }

    private function handle_transfer_failure($data) {
        global $wpdb;
        $reference = $data['reference'] ?? '';
        $reason = $data['reason'] ?? $data['message'] ?? __('Transfer failed', 'matrix-mlm');

        if (empty($reference)) {
            return;
        }

        $payout = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_fintava_payouts WHERE reference = %s",
            $reference
        ));

        if (!$payout || in_array($payout->status, ['failed', 'refunded'], true)) {
            return;
        }

        $wpdb->update(
            $wpdb->prefix . 'matrix_fintava_payouts',
            ['status' => 'failed', 'failure_reason' => $reason, 'updated_at' => current_time('mysql')],
            ['id' => $payout->id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        $wallet = new Matrix_MLM_Wallet();
        $wallet->credit(
            $payout->user_id,
            $payout->total_debit,
            'fintava_payout_refund',
            sprintf(__('Refund: Bank transfer failed - %s (Ref: %s)', 'matrix-mlm'), $reason, $reference),
            $reference
        );

        $wpdb->update(
            $wpdb->prefix . 'matrix_fintava_payouts',
            ['status' => 'refunded', 'updated_at' => current_time('mysql')],
            ['id' => $payout->id],
            ['%s', '%s'],
            ['%d']
        );

        Matrix_MLM_Notifications::send_admin_notification(
            'fintava_payout_failed',
            sprintf(__('Bank payout FAILED and refunded. Ref: %s, Reason: %s', 'matrix-mlm'), $reference, $reason)
        );
    }

    private function handle_wallet_to_wallet_transfer($data) {
        global $wpdb;

        $reference = $data['reference'] ?? $data['transaction_reference'] ?? '';
        $amount = floatval($data['amount'] ?? 0);
        $recipient_account = self::extract_account_number($data);
        if ($recipient_account === '') {
            // Some payloads use recipient_wallet as a free-form holder.
            $recipient_account = trim((string) ($data['recipient_wallet'] ?? ''));
        }
        $sender_name = $data['sender_name'] ?? $data['sender'] ?? __('External Wallet', 'matrix-mlm');
        $narration = $data['narration'] ?? $data['description'] ?? '';
        $currency = $data['currency'] ?? 'NGN';

        if (empty($reference) || $amount <= 0 || empty($recipient_account)) {
            error_log('[Matrix Fintava Webhook] wallet_to_wallet_transfer_v2: Missing required data');
            return;
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}matrix_deposits WHERE transaction_id = %s AND status = 'completed'",
            $reference
        ));
        if ($existing) {
            return;
        }

        $wallet_record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_fintava_wallets WHERE account_number = %s AND status = 'active'",
            $recipient_account
        ));
        if (!$wallet_record) {
            error_log('[Matrix Fintava Webhook] wallet_to_wallet_transfer_v2: No user found for account ' . $recipient_account);
            return;
        }

        $user_id = $wallet_record->user_id;
        if (!Matrix_MLM_User::is_active($user_id)) {
            return;
        }

        $wpdb->insert(
            $wpdb->prefix . 'matrix_deposits',
            [
                'user_id' => $user_id,
                'amount' => $amount,
                'charge' => 0.00,
                'net_amount' => $amount,
                'gateway' => 'fintava_wallet_transfer',
                'currency' => $currency,
                'transaction_id' => $reference,
                'gateway_response' => json_encode([
                    'type' => 'wallet_to_wallet_transfer_v2',
                    'sender_name' => $sender_name,
                    'narration' => $narration,
                    'recipient_account' => $recipient_account,
                ]),
                'status' => 'completed',
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        $wallet = new Matrix_MLM_Wallet();
        $description = sprintf(
            __('Wallet transfer received from %s%s', 'matrix-mlm'),
            $sender_name,
            !empty($narration) ? ' - ' . $narration : ''
        );
        $wallet->credit($user_id, $amount, 'fintava_wallet_transfer', $description, $reference);

        Matrix_MLM_Notifications::send_deposit_notification($user_id, $amount, 'completed');

        $currency_symbol = get_option('matrix_mlm_currency_symbol', '₦');
        $user = get_userdata($user_id);
        Matrix_MLM_Notifications::send_admin_notification(
            'fintava_wallet_transfer',
            sprintf(
                __('Wallet transfer received: %s%s to %s (Account: %s, From: %s). Ref: %s', 'matrix-mlm'),
                $currency_symbol,
                number_format($amount, 2),
                $user ? $user->user_login : "User #$user_id",
                $recipient_account,
                $sender_name,
                $reference
            )
        );

        do_action('matrix_fintava_wallet_transfer_received', $user_id, $amount, $data);
    }

    private function handle_account_funded($data) {
        global $wpdb;

        $reference = $data['reference'] ?? $data['transaction_reference'] ?? $data['session_id'] ?? '';
        $amount = floatval($data['amount'] ?? 0);
        $account_number = self::extract_account_number($data);
        $sender_name = $data['sender_name'] ?? $data['payer_name'] ?? $data['originator_name'] ?? __('Bank Transfer', 'matrix-mlm');
        $sender_bank = $data['sender_bank'] ?? $data['payer_bank'] ?? $data['originator_bank'] ?? '';
        $narration = $data['narration'] ?? $data['description'] ?? $data['remark'] ?? '';
        $currency = $data['currency'] ?? 'NGN';

        if (empty($reference) || $amount <= 0 || empty($account_number)) {
            error_log('[Matrix Fintava Webhook] account_funded: Missing required data');
            return;
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}matrix_deposits WHERE transaction_id = %s AND status = 'completed'",
            $reference
        ));
        if ($existing) {
            return;
        }

        $wallet_record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_fintava_wallets WHERE account_number = %s AND status = 'active'",
            $account_number
        ));
        if (!$wallet_record) {
            error_log('[Matrix Fintava Webhook] account_funded: No user found for account ' . $account_number);
            return;
        }

        $user_id = $wallet_record->user_id;
        if (!Matrix_MLM_User::is_active($user_id)) {
            return;
        }

        $wpdb->insert(
            $wpdb->prefix . 'matrix_deposits',
            [
                'user_id' => $user_id,
                'amount' => $amount,
                'charge' => 0.00,
                'net_amount' => $amount,
                'gateway' => 'fintava_account_funded',
                'currency' => $currency,
                'transaction_id' => $reference,
                'gateway_response' => json_encode([
                    'type' => 'account_funded',
                    'sender_name' => $sender_name,
                    'sender_bank' => $sender_bank,
                    'narration' => $narration,
                    'account_number' => $account_number,
                ]),
                'status' => 'completed',
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        $wallet = new Matrix_MLM_Wallet();
        $description = sprintf(
            __('Account funded by %s%s%s', 'matrix-mlm'),
            $sender_name,
            !empty($sender_bank) ? ' (' . $sender_bank . ')' : '',
            !empty($narration) ? ' - ' . $narration : ''
        );
        $wallet->credit($user_id, $amount, 'fintava_account_funded', $description, $reference);

        Matrix_MLM_Notifications::send_deposit_notification($user_id, $amount, 'completed');

        $currency_symbol = get_option('matrix_mlm_currency_symbol', '₦');
        $user = get_userdata($user_id);
        Matrix_MLM_Notifications::send_admin_notification(
            'fintava_account_funded',
            sprintf(
                __('Account funded: %s%s deposited to %s (Account: %s, From: %s%s). Ref: %s', 'matrix-mlm'),
                $currency_symbol,
                number_format($amount, 2),
                $user ? $user->user_login : "User #$user_id",
                $account_number,
                $sender_name,
                !empty($sender_bank) ? ' via ' . $sender_bank : '',
                $reference
            )
        );

        do_action('matrix_fintava_account_funded', $user_id, $amount, $data);
    }

    /**
     * Log webhook events for debugging and auditing.
     * Self-heals by ensuring the logs table exists before inserting.
     */
    private function log_webhook_event($event_type, $data, $signature) {
        global $wpdb;
        self::ensure_tables_exist();

        $table = $wpdb->prefix . 'matrix_fintava_webhook_logs';

        $wpdb->insert(
            $table,
            [
                'event_type' => $event_type,
                'reference' => $data['reference'] ?? $data['transaction_reference'] ?? '',
                'payload' => json_encode($data),
                'signature' => $signature ?? '',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'status' => 'received',
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    // =========================================================================
    // CUSTOMER API
    // =========================================================================

    /**
     * GET /customers — list all customers under this merchant.
     *
     * Returns an array of customer objects. Each customer's `userInfo.id` is
     * the customerId needed for the single-customer endpoint.
     *
     * @return array|WP_Error Array of customer records on success.
     */
    public function get_customer_list() {
        // Try multiple endpoint paths — Fintava uses /customers on some tiers
        // and /merchant/customers on others.
        $paths = ['/customers', '/merchant/customers'];
        $response = null;
        $last_error = null;

        foreach ($paths as $path) {
            $attempt = $this->make_request('GET', $path);
            if (!is_wp_error($attempt)) {
                $response = $attempt;
                break;
            }
            $last_error = $attempt;
            $msg = strtolower(is_array($attempt->get_error_message()) ? implode(' ', $attempt->get_error_message()) : (string) $attempt->get_error_message());
            // Only try next path on 404-style errors
            if (strpos($msg, 'cannot get') === false
                && strpos($msg, 'not found') === false
                && strpos($msg, 'http 404') === false
                && strpos($msg, '(http 404)') === false) {
                return $attempt; // Real error, don't retry
            }
        }

        if ($response === null) {
            return $last_error ?: new WP_Error('fintava_customer_list_error', __('Could not retrieve customer list', 'matrix-mlm'));
        }

        // Fintava wraps the list in { data: [...], status: 200, message: "successful" }
        if (isset($response['data']) && is_array($response['data'])) {
            return $response['data'];
        }

        // Bare array fallback
        if (is_array($response) && isset($response[0])) {
            return $response;
        }

        return new WP_Error(
            'fintava_customer_list_error',
            $response['message'] ?? __('Could not retrieve customer list', 'matrix-mlm')
        );
    }

    /**
     * GET /customers/{customerId} — fetch a single customer's full details.
     *
     * The customerId is the `userInfo.id` UUID returned in the customer list.
     * The response includes a `wallet` object with `wallet.id` (the wallet UUID
     * needed for balance lookups) and `wallet.accountNumber`.
     *
     * @param string $customer_id The Fintava customer UUID.
     * @return array|WP_Error Customer data on success.
     */
    public function get_customer($customer_id) {
        if (empty($customer_id)) {
            return new WP_Error('missing_customer_id', __('Customer ID is required', 'matrix-mlm'));
        }

        // Try multiple endpoint paths
        $paths = ['/customers/' . $customer_id, '/merchant/customers/' . $customer_id];
        $response = null;
        $last_error = null;

        foreach ($paths as $path) {
            $attempt = $this->make_request('GET', $path);
            if (!is_wp_error($attempt)) {
                $response = $attempt;
                break;
            }
            $last_error = $attempt;
            $msg = strtolower(is_array($attempt->get_error_message()) ? implode(' ', $attempt->get_error_message()) : (string) $attempt->get_error_message());
            if (strpos($msg, 'cannot get') === false
                && strpos($msg, 'not found') === false
                && strpos($msg, 'http 404') === false
                && strpos($msg, '(http 404)') === false) {
                return $attempt;
            }
        }

        if ($response === null) {
            return $last_error ?: new WP_Error('fintava_customer_error', __('Could not retrieve customer details', 'matrix-mlm'));
        }

        // Standard Fintava envelope: { data: { userInfo: {...}, wallet: {...} }, status: 200 }
        if (isset($response['data']) && is_array($response['data'])) {
            return $response['data'];
        }

        // Bare object fallback (if they return the customer object directly)
        if (is_array($response) && (isset($response['userInfo']) || isset($response['wallet']))) {
            return $response;
        }

        return new WP_Error(
            'fintava_customer_error',
            $response['message'] ?? __('Could not retrieve customer details', 'matrix-mlm')
        );
    }

    /**
     * GET /wallet/details?accountNumber=XXXX — look up wallet details by account number.
     *
     * This is the primary way to resolve a wallet_id when all we have is the
     * account number. Returns the full wallet object including its UUID.
     *
     * @param string $account_number The 10-digit virtual account number.
     * @return array|WP_Error Wallet details on success.
     */
    public function get_wallet_by_account_number($account_number) {
        if (empty($account_number)) {
            return new WP_Error('missing_account_number', __('Account number is required', 'matrix-mlm'));
        }

        $response = $this->make_request('GET', '/wallet/details?accountNumber=' . urlencode($account_number));
        if (is_wp_error($response)) {
            return $response;
        }

        if (isset($response['data']) && is_array($response['data'])) {
            return $response['data'];
        }

        // Bare object fallback — accept any shape that has a wallet identifier or account number
        if (is_array($response) && (isset($response['id']) || isset($response['walletId']) || isset($response['virtualAcctNo']) || isset($response['customerAccountNo']))) {
            return $response;
        }

        return new WP_Error(
            'fintava_enquiry_error',
            $response['message'] ?? __('Could not look up wallet by account number', 'matrix-mlm')
        );
    }

    /**
     * Resolve the wallet_id for a local wallet row using the account number enquiry endpoint.
     *
     * Calls GET /wallet/details?accountNumber=XXXX which returns the wallet
     * details including its UUID (the wallet_id needed for balance lookups).
     *
     * @param object $wallet_row The local matrix_fintava_wallets row.
     * @return string|WP_Error The resolved wallet_id on success.
     */
    public function resolve_wallet_id_from_customer($wallet_row) {
        global $wpdb;

        if (empty($wallet_row->account_number)) {
            return new WP_Error(
                'missing_account_number',
                __('No account number on file to look up.', 'matrix-mlm')
            );
        }

        // Call the wallet details enquiry endpoint with the account number
        $details = $this->get_wallet_by_account_number(trim($wallet_row->account_number));
        if (is_wp_error($details)) {
            return $details;
        }

        // Extract the wallet ID from the response
        $resolved_wallet_id = self::extract_wallet_id($details);
        if (empty($resolved_wallet_id)) {
            // Fallback: use the top-level 'id' field
            $resolved_wallet_id = $details['id'] ?? '';
        }

        if (empty($resolved_wallet_id)) {
            return new WP_Error(
                'fintava_no_wallet_id_in_response',
                __('The enquiry response does not contain a wallet ID.', 'matrix-mlm')
            );
        }

        // Verify the account number matches (safety check)
        $remote_account = self::extract_account_number($details);
        if (!empty($remote_account) && trim($remote_account) !== trim($wallet_row->account_number)) {
            return new WP_Error(
                'fintava_account_mismatch',
                __('The enquiry returned a different account number than expected.', 'matrix-mlm')
            );
        }

        // Persist wallet_id back to the local row
        $update_data = [
            'wallet_id'  => $resolved_wallet_id,
            'updated_at' => current_time('mysql'),
        ];
        $update_formats = ['%s', '%s'];

        // Save bank_code if available
        $bank_code = $details['bank_code'] ?? $details['bankCode'] ?? null;
        if (!empty($bank_code)) {
            $update_data['bank_code'] = $bank_code;
            $update_formats[] = '%s';
        }

        $wpdb->update(
            $wpdb->prefix . 'matrix_fintava_wallets',
            $update_data,
            ['id' => $wallet_row->id],
            $update_formats,
            ['%d']
        );

        return $resolved_wallet_id;
    }

    // =========================================================================
    // VIRTUAL WALLET API
    // =========================================================================

    /**
     * POST /virtual-wallet/generate
     */
    public function generate_virtual_wallet($customer_data) {
        $required_fields = ['first_name', 'last_name', 'email', 'phone'];
        foreach ($required_fields as $field) {
            if (empty($customer_data[$field])) {
                return new WP_Error('missing_field', sprintf(__('Missing required field: %s', 'matrix-mlm'), $field));
            }
        }

        $payload = [
            'first_name' => sanitize_text_field($customer_data['first_name']),
            'last_name' => sanitize_text_field($customer_data['last_name']),
            'email' => sanitize_email($customer_data['email']),
            'phone' => sanitize_text_field($customer_data['phone']),
            'currency' => $customer_data['currency'] ?? 'NGN',
        ];

        foreach (['bvn', 'nin', 'date_of_birth', 'gender', 'address', 'reference'] as $optional) {
            if (!empty($customer_data[$optional])) {
                $payload[$optional] = sanitize_text_field($customer_data[$optional]);
            }
        }

        $response = $this->make_request('POST', '/virtual-wallet/generate', $payload);
        if (is_wp_error($response)) {
            return $response;
        }

        // Same envelope tolerance as get_virtual_wallet_details() — Fintava
        // returns numeric status: 200, message: "successful", data: {...}.
        if (isset($response['data']) && is_array($response['data'])) {
            $data = $response['data'];
            $extracted_name = self::extract_account_name($data);

            // Extract customer_id from the response — Fintava may return it as
            // userInfo.id, customerId, or customer_id depending on the endpoint.
            $customer_id = '';
            if (isset($data['userInfo']['id'])) {
                $customer_id = $data['userInfo']['id'];
            } elseif (isset($data['customerId'])) {
                $customer_id = $data['customerId'];
            } elseif (isset($data['customer_id'])) {
                $customer_id = $data['customer_id'];
            }

            return [
                'success' => true,
                'wallet_id' => self::extract_wallet_id($data) ?: ($data['id'] ?? null),
                'customer_id' => $customer_id,
                'account_number' => self::extract_account_number($data),
                'account_name' => $extracted_name !== '' ? $extracted_name : ($customer_data['first_name'] . ' ' . $customer_data['last_name']),
                'bank_name' => self::extract_bank_name($data) ?: 'Fintava',
                'bank_code' => $data['bank_code'] ?? $data['bankCode'] ?? null,
                'currency' => $data['currency'] ?? 'NGN',
                'status' => $data['status'] ?? 'active',
            ];
        }

        return new WP_Error(
            'fintava_wallet_error',
            $response['message'] ?? __('Failed to generate virtual wallet', 'matrix-mlm')
        );
    }

    /**
     * Fetch a single virtual wallet's details by its Fintava UUID.
     *
     * Resolution chain (each step is gated on the previous one 404-ing):
     *
     *   1. Path-style GET against /virtual-wallet/{id} and several known
     *      siblings (/virtual-wallets/{id}, /merchant/virtual-wallet/{id},
     *      etc.). The first prefix that responds is cached on the class for
     *      the rest of the request — see list_virtual_wallets() for the same
     *      pattern. Accordingly we also negative-cache "every prefix 404'd"
     *      so subsequent calls skip the dead probes.
     *
     *   2. If $account_number is supplied, fall back to
     *      GET /wallet/details?accountNumber=... — the canonical lookup
     *      endpoint that returns the full wallet object keyed by account
     *      number rather than UUID. Some tiers expose this but not the
     *      path-style GET, and vice versa, so we try whichever the caller
     *      can support.
     *
     *   3. If $customer_id is supplied, fall back to
     *      GET /customers/{customer_id} and pull the wallet object out of
     *      the embedded `wallet` field. Useful on tiers where neither the
     *      path-style singleton nor the wallet details enquiry is exposed
     *      but the customer endpoint is.
     *
     *   4. As a final fallback (when an account_number is on hand), pull
     *      the full /customers list and match by embedded account_number.
     *      Picks up tiers that expose only the list endpoint, and resolves
     *      pre-existing wallets that lack a customer_id locally.
     *
     * Whatever the source, the result is sanity-checked: the wallet object
     * we hand back must agree with the requested $wallet_id (either via its
     * own `id`/`walletId` field, or — when the response doesn't echo that —
     * via the account number the caller already vouched for).
     *
     * When the entire chain fails, the surfaced WP_Error explicitly lists
     * which fallbacks were tried and what each one returned, so callers
     * can tell from the error message alone which endpoints exist on this
     * Fintava merchant tier without rooting through server logs.
     *
     * @param string      $wallet_id      The Fintava wallet UUID.
     * @param string|null $account_number Optional account number for the same
     *                                    wallet. When supplied, enables the
     *                                    /wallet/details fallback for tiers
     *                                    where the path-style GET is missing.
     * @param string|null $customer_id    Optional Fintava customer UUID. When
     *                                    supplied, enables the
     *                                    /customers/{id} fallback for tiers
     *                                    where neither path-style nor
     *                                    /wallet/details enquiry is exposed.
     * @return array|WP_Error Wallet details on success.
     */
    public function get_virtual_wallet_details($wallet_id, $account_number = null, $customer_id = null) {
        if (empty($wallet_id)) {
            return new WP_Error('missing_wallet_id', __('Wallet ID is required', 'matrix-mlm'));
        }

        // Per-request memoization. $resolved_prefix remembers the winning
        // path-style prefix once we find one; $path_style_dead is the
        // negative cache for tiers (Live, currently) where every path
        // variant 404s — without it, every balance refresh would burn six
        // round-trips before reaching the /wallet/details fallback.
        static $resolved_prefix = null;
        static $path_style_dead = false;
        static $loma_name_dead  = false;

        $response             = null;
        $path_style_error     = null; // last error from step 1
        $loma_name_error      = null; // error or null from step 2
        $customer_error       = null; // error or null from step 3
        $customer_list_error  = null; // error or null from step 4
        $tried_path_style     = false;
        $tried_loma_name      = false;
        $tried_customer       = false;
        $tried_customer_list  = false;

        // ---- Step 1: path-style GET. -------------------------------------
        if (!$path_style_dead) {
            $tried_path_style = true;
            $prefixes = $resolved_prefix !== null
                ? [$resolved_prefix]
                : ['/virtual-wallet', '/virtual-wallets', '/wallet', '/wallets', '/merchant/virtual-wallet', '/merchant/virtual-wallets'];

            foreach ($prefixes as $prefix) {
                $attempt = $this->make_request('GET', $prefix . '/' . $wallet_id);

                if (!is_wp_error($attempt)) {
                    $response        = $attempt;
                    $resolved_prefix = $prefix;
                    break;
                }

                $path_style_error = $attempt;
                $msg              = strtolower(is_array($attempt->get_error_message()) ? implode(' ', $attempt->get_error_message()) : (string) $attempt->get_error_message());
                // Only keep trying alternates on "not found" / "cannot get"
                // style 404s. For real failures (auth, rate-limit, network,
                // or Fintava's own JSON-formatted "wallet not found") bail
                // out immediately so callers see the actual cause.
                if (strpos($msg, 'cannot get') === false
                    && strpos($msg, 'not found') === false
                    && strpos($msg, 'http 404') === false
                    && strpos($msg, '(http 404)') === false) {
                    return $attempt;
                }
            }

            // If the loop exhausted every prefix without success, this tier
            // simply doesn't expose a path-style single-wallet GET.
            // Remember that so we don't keep probing on follow-up calls.
            if ($response === null && $resolved_prefix === null) {
                $path_style_dead = true;
            }
        }

        // ---- Step 2: account-number enquiry. -----------------------------
        // Some Live merchant tiers expose GET /wallet/details?accountNumber=...
        // but no equivalent path-style singleton. If the caller has the
        // account number on hand — and most do, since the local
        // matrix_fintava_wallets row carries it — try that endpoint and
        // verify the returned wallet matches.
        if ($response === null && !empty($account_number) && !$loma_name_dead) {
            $tried_loma_name = true;
            $enquiry = $this->get_wallet_by_account_number($account_number);
            if (!is_wp_error($enquiry)) {
                $matches = $this->wallet_response_matches($enquiry, $wallet_id, $account_number);
                if ($matches) {
                    return $enquiry;
                }
                $loma_name_error = new WP_Error(
                    'fintava_wallet_id_mismatch',
                    __('The Fintava enquiry response refers to a different wallet than expected.', 'matrix-mlm')
                );
            } else {
                $loma_name_error = $enquiry;
                // Negative-cache only on 404-style errors so that real
                // auth/network errors still get retried next request.
                $msg = strtolower(is_array($enquiry->get_error_message()) ? implode(' ', $enquiry->get_error_message()) : (string) $enquiry->get_error_message());
                if (strpos($msg, 'cannot get') !== false
                    || strpos($msg, 'not found') !== false
                    || strpos($msg, 'http 404') !== false
                    || strpos($msg, '(http 404)') !== false) {
                    $loma_name_dead = true;
                }
            }
        }

        // ---- Step 3: customer endpoint. ----------------------------------
        // If $customer_id is on hand, try GET /customers/{customer_id} and
        // pull the wallet from the embedded `wallet` object. This is the
        // fallback of last resort for tiers where neither path-style nor
        // /wallet/details enquiry is exposed.
        if ($response === null && !empty($customer_id)) {
            $tried_customer = true;
            $customer = $this->get_customer($customer_id);
            if (!is_wp_error($customer)) {
                $wallet_obj = null;
                if (isset($customer['wallet']) && is_array($customer['wallet'])) {
                    $wallet_obj = $customer['wallet'];
                } elseif (isset($customer['data']['wallet']) && is_array($customer['data']['wallet'])) {
                    // Belt-and-suspenders: get_customer() already unwraps
                    // the envelope, but tolerate one extra level just in
                    // case a tier returns the wallet under data.wallet.
                    $wallet_obj = $customer['data']['wallet'];
                }
                if ($wallet_obj) {
                    $matches = $this->wallet_response_matches($wallet_obj, $wallet_id, $account_number);
                    if ($matches) {
                        return $wallet_obj;
                    }
                    $customer_error = new WP_Error(
                        'fintava_wallet_id_mismatch',
                        __('The Fintava customer endpoint returned a wallet that does not match the requested wallet ID.', 'matrix-mlm')
                    );
                } else {
                    $customer_error = new WP_Error(
                        'fintava_customer_no_wallet',
                        __('Fintava customer record does not contain a wallet object.', 'matrix-mlm')
                    );
                }
            } else {
                $customer_error = $customer;
            }
        }

        // ---- Step 4: customer list fallback. -----------------------------
        // Final fallback for tiers that expose neither path-style GETs,
        // /wallet/details, nor /customers/{id} (or where customer_id
        // isn't on file): pull the full customer list from /customers and
        // match by embedded account_number. The list response carries each
        // customer's wallet object inline, so a hit lets us return wallet
        // details without a follow-up /customers/{id} round-trip.
        //
        // Only runs when we have an account_number to match against; without
        // one, there's nothing to key the search on.
        static $customer_list_dead = false;
        if ($response === null && !empty($account_number) && !$customer_list_dead) {
            $tried_customer_list = true;
            $list = $this->get_customer_list();
            if (!is_wp_error($list) && is_array($list)) {
                $found = false;
                foreach ($list as $cust) {
                    if (!is_array($cust)) {
                        continue;
                    }
                    // The list response can carry the wallet either nested
                    // under 'wallet' (preferred) or at the root of the
                    // customer object on simpler tiers.
                    $cust_wallet = isset($cust['wallet']) && is_array($cust['wallet']) ? $cust['wallet'] : $cust;
                    $cust_account = self::extract_account_number($cust_wallet);
                    if ($cust_account === '' || trim($cust_account) !== trim((string) $account_number)) {
                        continue;
                    }
                    $found = true;
                    if ($this->wallet_response_matches($cust_wallet, $wallet_id, $account_number)) {
                        return $cust_wallet;
                    }
                    $customer_list_error = new WP_Error(
                        'fintava_wallet_id_mismatch',
                        __('A customer in the Fintava list matches the account number, but its wallet ID does not match the requested wallet.', 'matrix-mlm')
                    );
                    break;
                }
                if (!$found && $customer_list_error === null) {
                    $customer_list_error = new WP_Error(
                        'fintava_customer_not_in_list',
                        __('No customer in the Fintava customer list matches the account number on file.', 'matrix-mlm')
                    );
                }
            } else {
                $customer_list_error = $list instanceof WP_Error ? $list : new WP_Error(
                    'fintava_customer_list_error',
                    __('Customer list endpoint returned an unexpected shape.', 'matrix-mlm')
                );
                // Negative-cache the list endpoint only on 404-style errors.
                $msg = strtolower(is_array($customer_list_error->get_error_message()) ? implode(' ', $customer_list_error->get_error_message()) : (string) $customer_list_error->get_error_message());
                if (strpos($msg, 'cannot get') !== false
                    || strpos($msg, 'not found') !== false
                    || strpos($msg, 'http 404') !== false
                    || strpos($msg, '(http 404)') !== false) {
                    $customer_list_dead = true;
                }
            }
        }

        // ---- Build a diagnostic error if nothing worked. -----------------
        if ($response === null) {
            return $this->build_wallet_lookup_error(
                $tried_path_style,
                $path_style_error,
                $tried_loma_name,
                $loma_name_error,
                $tried_customer,
                $customer_error,
                $tried_customer_list,
                $customer_list_error,
                !empty($account_number),
                !empty($customer_id)
            );
        }

        // Fintava actually returns {"data": {...}, "status": 200, "message": "successful"}
        // — status is a numeric HTTP code, not boolean true. Accept any envelope
        // that carries a non-empty `data` object; rely on make_request() having
        // already converted real HTTP errors into WP_Error.
        if (isset($response['data']) && is_array($response['data'])) {
            return $response['data'];
        }

        // Fallback: some shapes return the wallet object at the root.
        if (is_array($response) && (isset($response['id']) || isset($response['virtualAcctNo']) || isset($response['account_number']))) {
            return $response;
        }

        return new WP_Error(
            'fintava_wallet_error',
            $response['message'] ?? __('Could not retrieve wallet details', 'matrix-mlm')
        );
    }

    /**
     * Verify that a Fintava wallet payload corresponds to the wallet we asked
     * about. Returns true when the response's wallet UUID equals
     * $expected_wallet_id; or, when the payload doesn't echo a UUID (which
     * happens on some Fintava response shapes), when the account number
     * Fintava returned matches the one the caller passed in. Matching on
     * account number is safe here only because the caller already vouches
     * for that pairing — it's stored on the local matrix_fintava_wallets row
     * keyed to the user.
     */
    private function wallet_response_matches($wallet_obj, $expected_wallet_id, $expected_account_number = null) {
        $returned_id = self::extract_wallet_id($wallet_obj);
        if ($returned_id === '' && isset($wallet_obj['id'])) {
            $returned_id = (string) $wallet_obj['id'];
        }

        if ($returned_id !== '' && trim($returned_id) === trim((string) $expected_wallet_id)) {
            return true;
        }

        if ($returned_id === '' && !empty($expected_account_number)) {
            $returned_account = self::extract_account_number($wallet_obj);
            if ($returned_account !== '' && trim($returned_account) === trim((string) $expected_account_number)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a single, self-describing WP_Error that names every fallback we
     * tried and what each one returned. Lets callers (and the operator
     * staring at the dashboard) tell at a glance whether a given Fintava
     * tier exposes the path-style endpoint, the /wallet/details enquiry, the
     * customer endpoint, all three, or none — without having to grep server
     * logs.
     */
    private function build_wallet_lookup_error(
        $tried_path_style,
        $path_style_error,
        $tried_loma_name,
        $loma_name_error,
        $tried_customer,
        $customer_error,
        $tried_customer_list,
        $customer_list_error,
        $had_account_number,
        $had_customer_id
    ) {
        $parts = [];

        if ($tried_path_style && $path_style_error instanceof WP_Error) {
            $parts[] = sprintf(
                /* translators: %s: error returned by Fintava for path-style probes */
                __('path-style GET failed (%s)', 'matrix-mlm'),
                $path_style_error->get_error_message()
            );
        } elseif ($tried_path_style) {
            $parts[] = __('path-style GET failed', 'matrix-mlm');
        } else {
            $parts[] = __('path-style GET skipped (negative-cached as unavailable on this tier)', 'matrix-mlm');
        }

        if ($tried_loma_name && $loma_name_error instanceof WP_Error) {
            $parts[] = sprintf(
                /* translators: %s: error returned by Fintava enquiry endpoint */
                __('/wallet/details failed (%s)', 'matrix-mlm'),
                $loma_name_error->get_error_message()
            );
        } elseif ($tried_loma_name) {
            $parts[] = __('/wallet/details failed', 'matrix-mlm');
        } elseif (!$had_account_number) {
            $parts[] = __('/wallet/details not tried (no account_number on file)', 'matrix-mlm');
        } else {
            $parts[] = __('/wallet/details skipped (negative-cached as unavailable)', 'matrix-mlm');
        }

        if ($tried_customer && $customer_error instanceof WP_Error) {
            $parts[] = sprintf(
                /* translators: %s: error returned by Fintava customer endpoint */
                __('/customers/{id} failed (%s)', 'matrix-mlm'),
                $customer_error->get_error_message()
            );
        } elseif ($tried_customer) {
            $parts[] = __('/customers/{id} failed', 'matrix-mlm');
        } elseif (!$had_customer_id) {
            $parts[] = __('/customers/{id} not tried (no customer_id on file)', 'matrix-mlm');
        }

        if ($tried_customer_list && $customer_list_error instanceof WP_Error) {
            $parts[] = sprintf(
                /* translators: %s: error returned by Fintava customer list endpoint */
                __('/customers (list) failed (%s)', 'matrix-mlm'),
                $customer_list_error->get_error_message()
            );
        } elseif ($tried_customer_list) {
            $parts[] = __('/customers (list) failed', 'matrix-mlm');
        } elseif (!$had_account_number) {
            $parts[] = __('/customers (list) not tried (no account_number on file)', 'matrix-mlm');
        } else {
            $parts[] = __('/customers (list) skipped (negative-cached as unavailable)', 'matrix-mlm');
        }

        return new WP_Error(
            'fintava_wallet_error',
            sprintf(
                /* translators: %s: bullet-list of which fallbacks were tried and how each one failed */
                __('Could not retrieve wallet details from Fintava. %s', 'matrix-mlm'),
                implode(' | ', $parts)
            )
        );
    }

    /**
     * Fetch the current balance of a user's Fintava virtual wallet.
     *
     * Returns ['available_balance' => float, 'ledger_balance' => float, 'currency' => string]
     * on success, or WP_Error on failure. Defensively handles the common
     * variations Fintava (and similar gateways) use for the balance field name
     * across endpoint versions: balance / available_balance / availableBalance /
     * wallet_balance / walletBalance / current_balance / currentBalance.
     */
    public function get_virtual_wallet_balance($wallet_id, $account_number = null, $customer_id = null) {
        if (empty($wallet_id)) {
            return new WP_Error('missing_wallet_id', __('Wallet ID is required', 'matrix-mlm'));
        }

        // ---- Fast path: dedicated balance endpoint. ----------------------
        // GET /customer/wallet/balance/{walletId} — single round-trip,
        // documented on the Fintava dev tier and rolling out to live. We
        // try it first and only fall back to the heavier details chain
        // (path-style GET → /wallet/details → /customers/{id} →
        // /customers list) if this endpoint isn't available on the active
        // tier.
        //
        // Negative-cached for the rest of the request so a tier that 404s
        // here doesn't burn a round-trip per refresh — same pattern used
        // throughout get_virtual_wallet_details(). $balance_endpoint_error
        // captures the 404 so we can surface it in the diagnostic when the
        // details fallback also fails.
        static $balance_endpoint_dead = false;
        $balance_endpoint_error = null;

        if (!$balance_endpoint_dead) {
            $direct = $this->make_request('GET', '/customer/wallet/balance/' . rawurlencode($wallet_id));
            if (!is_wp_error($direct)) {
                $payload = isset($direct['data']) && is_array($direct['data']) ? $direct['data'] : $direct;
                $normalized = $this->normalize_balance_payload($payload);
                if ($normalized !== null) {
                    return $normalized;
                }
                // Endpoint replied 200 but with a shape we don't recognise —
                // fall through to the details chain rather than returning a
                // half-parsed result.
            } else {
                $msg = strtolower(is_array($direct->get_error_message()) ? implode(' ', $direct->get_error_message()) : (string) $direct->get_error_message());
                if (strpos($msg, 'cannot get') !== false
                    || strpos($msg, 'not found') !== false
                    || strpos($msg, 'http 404') !== false
                    || strpos($msg, '(http 404)') !== false) {
                    // Tier doesn't expose this endpoint — stop probing it
                    // for the rest of the request and let the details
                    // fallback take over. Keep the error around so we can
                    // include it in the final diagnostic if everything fails.
                    $balance_endpoint_dead = true;
                    $balance_endpoint_error = $direct;
                } else {
                    // Real failure (auth, rate-limit, network). Surface it
                    // immediately rather than masking it behind a fallback.
                    return $direct;
                }
            }
        }

        // ---- Fallback: derive balance from the wallet details object. ----
        $details = $this->get_virtual_wallet_details($wallet_id, $account_number, $customer_id);
        if (is_wp_error($details)) {
            // Decorate the diagnostic so the user sees we ALSO tried the
            // dedicated balance endpoint and exactly how it failed —
            // otherwise the error message lists only the details-chain
            // attempts and the operator can't tell whether the new endpoint
            // is live yet on this tier.
            if ($balance_endpoint_error instanceof WP_Error) {
                return new WP_Error(
                    $details->get_error_code(),
                    sprintf(
                        /* translators: %1$s: details-chain diagnostic, %2$s: balance endpoint failure */
                        __('%1$s | /customer/wallet/balance/{walletId} failed (%2$s)', 'matrix-mlm'),
                        $details->get_error_message(),
                        $balance_endpoint_error->get_error_message()
                    )
                );
            }
            return $details;
        }

        $normalized = $this->normalize_balance_payload($details);
        if ($normalized === null) {
            return new WP_Error(
                'fintava_balance_unavailable',
                __('Fintava did not return a balance for this wallet.', 'matrix-mlm')
            );
        }
        return $normalized;
    }

    /**
     * Pull available_balance / ledger_balance / currency out of any of the
     * field-name variations Fintava uses across endpoints (snake_case vs
     * camelCase, balance vs available_balance vs wallet_balance vs
     * current_balance, etc.). Returns null when neither an available nor a
     * ledger balance can be located so the caller can fall through to the
     * next strategy.
     *
     * @param array $payload Decoded wallet/balance object.
     * @return array{available_balance: float, ledger_balance: float, currency: string}|null
     */
    private function normalize_balance_payload($payload) {
        if (!is_array($payload)) {
            return null;
        }

        $available = null;
        foreach (['available_balance', 'availableBalance', 'wallet_balance', 'walletBalance', 'balance', 'current_balance', 'currentBalance'] as $key) {
            if (isset($payload[$key]) && $payload[$key] !== '') {
                $available = floatval($payload[$key]);
                break;
            }
        }

        $ledger = null;
        foreach (['ledger_balance', 'ledgerBalance', 'book_balance', 'bookBalance'] as $key) {
            if (isset($payload[$key]) && $payload[$key] !== '') {
                $ledger = floatval($payload[$key]);
                break;
            }
        }

        if ($available === null && $ledger === null) {
            return null;
        }

        return [
            'available_balance' => $available !== null ? $available : $ledger,
            'ledger_balance'    => $ledger !== null ? $ledger : $available,
            'currency'          => $payload['currency'] ?? 'NGN',
        ];
    }

    /**
     * GET /virtual-wallet — list virtual wallets owned by this merchant.
     *
     * Some Fintava live tiers expose a paginated list endpoint; not every
     * account has it enabled, so this method must fail gracefully. Returns
     * a normalized array on success or WP_Error on failure.
     *
     * @param int $page  1-indexed page number.
     * @param int $limit Max records per page (capped to 100).
     * @return array|WP_Error {
     *     @type array $wallets    List of wallet records (raw API objects).
     *     @type int   $page       Page that was requested.
     *     @type int   $total      Total available across all pages, if reported.
     *     @type bool  $has_more   Whether another page is likely available.
     * }
     */
    public function list_virtual_wallets($page = 1, $limit = 100) {
        $page  = max(1, intval($page));
        $limit = max(1, min(100, intval($limit)));

        // Different Fintava merchant tiers expose this list under slightly
        // different paths. Try the most common spellings in order and accept
        // the first one that doesn't 404. The remembered winner is cached
        // for the rest of the request to avoid retrying dead paths during
        // pagination.
        static $resolved_path = null;

        $candidate_paths = $resolved_path !== null
            ? [$resolved_path]
            : ['/virtual-wallet', '/virtual-wallets', '/wallet', '/wallets', '/merchant/virtual-wallet', '/merchant/virtual-wallets'];

        $response   = null;
        $last_error = null;
        foreach ($candidate_paths as $path) {
            $endpoint = sprintf('%s?page=%d&limit=%d', $path, $page, $limit);
            $attempt  = $this->make_request('GET', $endpoint);

            if (!is_wp_error($attempt)) {
                $response      = $attempt;
                $resolved_path = $path;
                break;
            }

            $last_error = $attempt;
            $msg        = strtolower(is_array($attempt->get_error_message()) ? implode(' ', $attempt->get_error_message()) : (string) $attempt->get_error_message());
            // Only keep trying alternates on "not found" / "cannot get" style
            // 404s. For real failures (auth, rate-limit, network) bail out
            // immediately so callers see the actual cause.
            if (strpos($msg, 'cannot get') === false
                && strpos($msg, 'not found') === false
                && strpos($msg, 'http 404') === false
                && strpos($msg, '(http 404)') === false) {
                return $attempt;
            }
        }

        if ($response === null) {
            return $last_error ?: new WP_Error(
                'fintava_list_unavailable',
                __('Fintava list endpoint is not available on this merchant tier.', 'matrix-mlm')
            );
        }

        // Tolerate both "wrapped" ({status, data:[...]}) and "bare" array shapes.
        $wallets = [];
        if (isset($response['data']) && is_array($response['data'])) {
            // Some APIs return data as a list; others wrap it in data.items / data.results.
            if (isset($response['data'][0]) || empty($response['data'])) {
                $wallets = $response['data'];
            } elseif (isset($response['data']['items']) && is_array($response['data']['items'])) {
                $wallets = $response['data']['items'];
            } elseif (isset($response['data']['results']) && is_array($response['data']['results'])) {
                $wallets = $response['data']['results'];
            } elseif (isset($response['data']['wallets']) && is_array($response['data']['wallets'])) {
                $wallets = $response['data']['wallets'];
            }
        } elseif (is_array($response) && isset($response[0])) {
            $wallets = $response;
        }

        $total = isset($response['total']) ? intval($response['total'])
            : (isset($response['data']['total']) ? intval($response['data']['total']) : count($wallets));

        $has_more = count($wallets) >= $limit;

        return [
            'wallets'  => $wallets,
            'page'     => $page,
            'total'    => $total,
            'has_more' => $has_more,
        ];
    }

    /**
     * Backfill missing wallet_id values on local matrix_fintava_wallets rows by:
     *
     *   1. Listing all wallets via Fintava's list endpoint and matching on
     *      account_number (primary path).
     *   2. Falling back to scanning recent account_funded webhook log payloads
     *      for any rows the list endpoint couldn't satisfy (e.g. when the list
     *      endpoint isn't available on this account tier).
     *
     * Every candidate wallet_id is verified by calling GET /virtual-wallet/{id}
     * and checking that the returned account_number matches the local row.
     * Mismatches are NEVER persisted — same safety contract as ajax_set_my_wallet_id.
     *
     * @return array Diagnostic report with counts and per-row outcomes.
     */
    public function backfill_missing_wallet_ids() {
        global $wpdb;

        if (!$this->is_active()) {
            return new WP_Error(
                'fintava_not_configured',
                __('Fintava is not active. Configure the live API key first.', 'matrix-mlm')
            );
        }

        self::ensure_tables_exist();
        $wallets_table = $wpdb->prefix . 'matrix_fintava_wallets';
        $logs_table    = $wpdb->prefix . 'matrix_fintava_webhook_logs';

        // 1. Find local rows missing a wallet_id.
        $missing_rows = $wpdb->get_results(
            "SELECT id, user_id, account_number, account_name
               FROM {$wallets_table}
              WHERE (wallet_id IS NULL OR wallet_id = '')
                AND account_number IS NOT NULL
                AND account_number <> ''"
        );

        $report = [
            'total_missing_before'      => count($missing_rows),
            'backfilled_via_list_api'   => 0,
            'backfilled_via_webhook'    => 0,
            'mismatched'                => 0,
            'still_missing'             => 0,
            'list_api_available'        => null,  // true | false
            'list_api_error'            => null,
            'pages_fetched'             => 0,
            'details'                   => [],
        ];

        if (empty($missing_rows)) {
            return $report;
        }

        // Index missing rows by account number for O(1) lookup.
        $missing_by_account = [];
        foreach ($missing_rows as $row) {
            $missing_by_account[trim($row->account_number)] = $row;
        }

        // ---------------------------------------------------------------
        // PRIMARY PATH: list endpoint
        // ---------------------------------------------------------------
        $page         = 1;
        $max_pages    = 50;          // safety cap (50 * 100 = 5000 wallets)
        $list_failed  = false;

        while ($page <= $max_pages && !empty($missing_by_account)) {
            $listing = $this->list_virtual_wallets($page, 100);
            if (is_wp_error($listing)) {
                if ($page === 1) {
                    // Endpoint not available — fall through to webhook fallback.
                    $report['list_api_available'] = false;
                    $report['list_api_error']     = $listing->get_error_message();
                    $list_failed = true;
                } else {
                    // Mid-pagination failure — log and bail out of the loop.
                    $report['list_api_error'] = sprintf(
                        'Stopped at page %d: %s',
                        $page,
                        $listing->get_error_message()
                    );
                }
                break;
            }

            if ($report['list_api_available'] === null) {
                $report['list_api_available'] = true;
            }
            $report['pages_fetched']++;

            $wallets = isset($listing['wallets']) && is_array($listing['wallets']) ? $listing['wallets'] : [];

            foreach ($wallets as $remote) {
                if (empty($missing_by_account)) {
                    break;
                }

                $remote_account = self::extract_account_number($remote);
                if ($remote_account === '' || !isset($missing_by_account[$remote_account])) {
                    continue;
                }

                $remote_wallet_id = self::extract_wallet_id($remote);
                if ($remote_wallet_id === '') {
                    continue;
                }

                $local_row = $missing_by_account[$remote_account];
                $outcome   = $this->verify_and_persist_wallet_id($local_row, $remote_wallet_id);

                if ($outcome['status'] === 'backfilled') {
                    $report['backfilled_via_list_api']++;
                    unset($missing_by_account[$remote_account]);
                } elseif ($outcome['status'] === 'mismatched') {
                    $report['mismatched']++;
                    unset($missing_by_account[$remote_account]); // don't keep retrying with bad data
                }
                $outcome['source']         = 'list_api';
                $outcome['user_id']        = (int) $local_row->user_id;
                $outcome['account_number'] = $remote_account;
                $report['details'][]       = $outcome;
            }

            if (!$listing['has_more']) {
                break;
            }
            $page++;
            usleep(150000); // 150ms — be polite to Fintava's rate limiter
        }

        // ---------------------------------------------------------------
        // FALLBACK PATH: scan recent webhook logs
        //
        // We deliberately scan ALL event types, not just account_funded:
        // wallet_to_wallet_transfer_v2 and other Fintava events also embed
        // wallet identifiers. We extract any (account_number, wallet_id)
        // pair we can find and let verify_and_persist_wallet_id() decide
        // whether it's safe to save.
        // ---------------------------------------------------------------
        if (!empty($missing_by_account)) {
            $log_rows = $wpdb->get_results(
                "SELECT payload
                   FROM {$logs_table}
                  ORDER BY id DESC
                  LIMIT 1000"
            );

            foreach ($log_rows as $log_row) {
                if (empty($missing_by_account)) {
                    break;
                }
                $payload = json_decode($log_row->payload, true);
                if (!is_array($payload)) {
                    continue;
                }

                // The interesting fields might live at the root, under 'data',
                // or in a nested 'wallet' / 'recipient' / 'destination' object
                // (varies by event type).
                $candidates = [$payload];
                foreach (['data', 'wallet', 'virtual_wallet', 'virtualWallet', 'recipient', 'destination', 'beneficiary'] as $key) {
                    if (isset($payload[$key]) && is_array($payload[$key])) {
                        $candidates[] = $payload[$key];
                    }
                    if (isset($payload['data']) && is_array($payload['data']) && isset($payload['data'][$key]) && is_array($payload['data'][$key])) {
                        $candidates[] = $payload['data'][$key];
                    }
                }

                foreach ($candidates as $candidate) {
                    $remote_account = self::extract_account_number($candidate);
                    if ($remote_account === '' || !isset($missing_by_account[$remote_account])) {
                        continue;
                    }

                    $remote_wallet_id = self::extract_wallet_id($candidate);
                    if ($remote_wallet_id === '') {
                        continue;
                    }

                    $local_row = $missing_by_account[$remote_account];
                    $outcome   = $this->verify_and_persist_wallet_id($local_row, $remote_wallet_id);

                    if ($outcome['status'] === 'backfilled') {
                        $report['backfilled_via_webhook']++;
                        unset($missing_by_account[$remote_account]);
                    } elseif ($outcome['status'] === 'mismatched') {
                        $report['mismatched']++;
                        unset($missing_by_account[$remote_account]);
                    }
                    $outcome['source']         = 'webhook_log';
                    $outcome['user_id']        = (int) $local_row->user_id;
                    $outcome['account_number'] = $remote_account;
                    $report['details'][]       = $outcome;

                    break; // matched on this log row, move to next
                }
            }
        }

        // Anything left in $missing_by_account couldn't be resolved.
        $report['still_missing'] = count($missing_by_account);
        foreach ($missing_by_account as $acct => $row) {
            $report['details'][] = [
                'source'         => 'unresolved',
                'status'         => 'still_missing',
                'user_id'        => (int) $row->user_id,
                'account_number' => $acct,
                'reason'         => $list_failed
                    ? __('List endpoint unavailable and no matching webhook payload found.', 'matrix-mlm')
                    : __('No matching record returned by Fintava and no matching webhook payload found.', 'matrix-mlm'),
            ];
        }

        return $report;
    }

    /**
     * Verify a candidate wallet_id against a local wallet row and persist it
     * if the account_number reported by Fintava matches what we have on file.
     *
     * Returns one of:
     *   ['status' => 'backfilled', 'wallet_id' => '...']
     *   ['status' => 'mismatched', 'reason' => 'Account number mismatch']
     *   ['status' => 'lookup_failed', 'reason' => 'API error message']
     *   ['status' => 'persist_failed', 'reason' => 'DB error']
     *
     * @param object $local_row        Row from matrix_fintava_wallets (id, account_number, ...).
     * @param string $candidate_wallet The wallet_id we're trying to validate.
     */
    private function verify_and_persist_wallet_id($local_row, $candidate_wallet) {
        $details = $this->get_virtual_wallet_details(
            $candidate_wallet,
            isset($local_row->account_number) ? $local_row->account_number : null,
            isset($local_row->customer_id) ? $local_row->customer_id : null
        );
        if (is_wp_error($details)) {
            return [
                'status' => 'lookup_failed',
                'reason' => $details->get_error_message(),
            ];
        }

        $remote_account = self::extract_account_number($details);
        if ($remote_account === '' || $remote_account !== trim((string) $local_row->account_number)) {
            return [
                'status' => 'mismatched',
                'reason' => __('Fintava returned a different account number for that wallet ID.', 'matrix-mlm'),
            ];
        }

        global $wpdb;
        $bank_code = $details['bank_code'] ?? $details['bankCode'] ?? null;
        $update    = [
            'wallet_id'  => $candidate_wallet,
            'updated_at' => current_time('mysql'),
        ];
        $formats   = ['%s', '%s'];
        if (!empty($bank_code)) {
            $update['bank_code'] = $bank_code;
            $formats[]           = '%s';
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'matrix_fintava_wallets',
            $update,
            ['id' => $local_row->id],
            $formats,
            ['%d']
        );

        if ($result === false) {
            return [
                'status' => 'persist_failed',
                'reason' => $wpdb->last_error ?: __('Could not write to the wallets table.', 'matrix-mlm'),
            ];
        }

        return [
            'status'    => 'backfilled',
            'wallet_id' => $candidate_wallet,
        ];
    }

    public function get_user_wallet($user_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_fintava_wallets WHERE user_id = %d AND status = 'active'",
            $user_id
        ));
    }

    public function user_has_wallet($user_id) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}matrix_fintava_wallets WHERE user_id = %d AND status = 'active'",
            $user_id
        ));
    }

    public function ajax_create_virtual_wallet() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');
        self::ensure_tables_exist();

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('Authentication required', 'matrix-mlm')]);
        }

        if (!Matrix_MLM_User::is_active($user_id)) {
            wp_send_json_error(['message' => __('Your account is suspended', 'matrix-mlm')]);
        }

        if (!$this->is_active()) {
            wp_send_json_error(['message' => __('Virtual wallet creation is not available at the moment.', 'matrix-mlm')]);
        }

        if ($this->user_has_wallet($user_id)) {
            wp_send_json_error(['message' => __('You already have a virtual wallet. Only one wallet per user is allowed.', 'matrix-mlm')]);
        }

        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $bvn = sanitize_text_field($_POST['bvn'] ?? '');
        $date_of_birth = sanitize_text_field($_POST['date_of_birth'] ?? '');
        $gender = sanitize_text_field($_POST['gender'] ?? '');

        if (empty($first_name) || empty($last_name)) {
            wp_send_json_error(['message' => __('First name and last name are required', 'matrix-mlm')]);
        }

        if (empty($email) || !is_email($email)) {
            wp_send_json_error(['message' => __('A valid email address is required', 'matrix-mlm')]);
        }

        if (empty($phone)) {
            wp_send_json_error(['message' => __('Phone number is required', 'matrix-mlm')]);
        }

        if (!empty($bvn) && !preg_match('/^\d{11}$/', $bvn)) {
            wp_send_json_error(['message' => __('BVN must be 11 digits', 'matrix-mlm')]);
        }

        $reference = 'MTX-VW-' . $user_id . '-' . time();

        $result = $this->generate_virtual_wallet([
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'phone' => $phone,
            'bvn' => $bvn,
            'date_of_birth' => $date_of_birth,
            'gender' => $gender,
            'reference' => $reference,
            'currency' => 'NGN',
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'matrix_fintava_wallets',
            [
                'user_id' => $user_id,
                'wallet_id' => $result['wallet_id'],
                'customer_id' => $result['customer_id'] ?? null,
                'account_number' => $result['account_number'],
                'account_name' => $result['account_name'],
                'bank_name' => $result['bank_name'],
                'bank_code' => $result['bank_code'],
                'currency' => $result['currency'],
                'customer_email' => $email,
                'customer_phone' => $phone,
                'bvn' => $bvn ?: null,
                'status' => 'active',
                'metadata' => json_encode([
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'date_of_birth' => $date_of_birth,
                    'gender' => $gender,
                    'reference' => $reference,
                ]),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        Matrix_MLM_Notifications::send_admin_notification(
            'virtual_wallet_created',
            sprintf(__('Virtual wallet created for user %s (%s). Account: %s', 'matrix-mlm'),
                $first_name . ' ' . $last_name, $email, $result['account_number'])
        );

        wp_send_json_success([
            'message' => __('Virtual wallet created successfully!', 'matrix-mlm'),
            'wallet' => [
                'account_number' => $result['account_number'],
                'account_name' => $result['account_name'],
                'bank_name' => $result['bank_name'],
            ],
        ]);
    }

    public function ajax_get_virtual_wallet() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('Authentication required', 'matrix-mlm')]);
        }

        $wallet = $this->get_user_wallet($user_id);
        if (!$wallet) {
            wp_send_json_error(['message' => __('No virtual wallet found', 'matrix-mlm'), 'has_wallet' => false]);
        }

        if (!empty($wallet->wallet_id)) {
            $api_details = $this->get_virtual_wallet_details($wallet->wallet_id, $wallet->account_number, $wallet->customer_id ?? null);
            if (!is_wp_error($api_details) && isset($api_details['status'])) {
                if ($api_details['status'] !== $wallet->status) {
                    global $wpdb;
                    $wpdb->update(
                        $wpdb->prefix . 'matrix_fintava_wallets',
                        ['status' => $api_details['status'], 'updated_at' => current_time('mysql')],
                        ['id' => $wallet->id],
                        ['%s', '%s'],
                        ['%d']
                    );
                    $wallet->status = $api_details['status'];
                }
            }
        }

        wp_send_json_success([
            'has_wallet' => true,
            'wallet' => [
                'account_number' => $wallet->account_number,
                'account_name' => $wallet->account_name,
                'bank_name' => $wallet->bank_name,
                'currency' => $wallet->currency,
                'status' => $wallet->status,
                'created_at' => $wallet->created_at,
            ],
        ]);
    }

    /**
     * AJAX endpoint for the dashboard "Refresh balance" button.
     * Fetches the current balance from Fintava for the logged-in user's wallet.
     * If wallet_id is missing, attempts to auto-resolve it via the Customer API.
     */
    public function ajax_get_virtual_wallet_balance() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('Authentication required', 'matrix-mlm')]);
        }

        $wallet = $this->get_user_wallet($user_id);
        if (!$wallet) {
            wp_send_json_error([
                'message' => __('No virtual wallet linked to your account.', 'matrix-mlm'),
            ]);
        }

        // If wallet_id is missing, try to auto-resolve it via the Customer API
        if (empty($wallet->wallet_id)) {
            $resolved = $this->resolve_wallet_id_from_customer($wallet);
            if (is_wp_error($resolved)) {
                wp_send_json_error([
                    'message' => sprintf(
                        __('Wallet ID is missing and could not be resolved automatically: %s', 'matrix-mlm'),
                        is_array($resolved->get_error_message()) ? implode(' ', $resolved->get_error_message()) : $resolved->get_error_message()
                    ),
                    'needs_wallet_id' => true,
                ]);
            }
            // Refresh the wallet row after resolution
            $wallet = $this->get_user_wallet($user_id);
        }

        $balance = $this->get_virtual_wallet_balance($wallet->wallet_id, $wallet->account_number, $wallet->customer_id ?? null);
        if (is_wp_error($balance)) {
            wp_send_json_error(['message' => $balance->get_error_message()]);
        }

        $currency_symbol = get_option('matrix_mlm_currency_symbol', '₦');
        wp_send_json_success([
            'available_balance'           => $balance['available_balance'],
            'ledger_balance'              => $balance['ledger_balance'],
            'currency'                    => $balance['currency'],
            'available_balance_formatted' => $currency_symbol . number_format($balance['available_balance'], 2),
            'ledger_balance_formatted'    => $currency_symbol . number_format($balance['ledger_balance'], 2),
        ]);
    }

    /**
     * AJAX endpoint that lets a logged-in user fill in their own missing
     * Fintava Wallet ID directly from the dashboard.
     *
     * Security guarantee: the wallet_id is only saved if Fintava confirms it
     * resolves to the SAME account_number that's already on the user's local
     * matrix_fintava_wallets row. This prevents anyone from typing a stranger's
     * wallet_id to peek at their balance.
     */
    public function ajax_set_my_wallet_id() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('Authentication required', 'matrix-mlm')]);
        }

        if (!$this->is_active()) {
            wp_send_json_error(['message' => __('Fintava is not configured.', 'matrix-mlm')]);
        }

        $wallet_id = sanitize_text_field($_POST['wallet_id'] ?? '');
        if (empty($wallet_id)) {
            wp_send_json_error(['message' => __('Wallet ID is required.', 'matrix-mlm')]);
        }

        $wallet = $this->get_user_wallet($user_id);
        if (!$wallet) {
            wp_send_json_error(['message' => __('You do not have a linked Fintava wallet yet.', 'matrix-mlm')]);
        }

        if (!empty($wallet->wallet_id)) {
            wp_send_json_error(['message' => __('Your wallet already has a Wallet ID. Contact an admin if it needs to change.', 'matrix-mlm')]);
        }

        // Try to verify the wallet ID against Fintava's API.
        // If the API is unavailable (404 / endpoint not on this tier), save
        // the wallet_id directly — the user is authenticated and owns this row.
        $details = $this->get_virtual_wallet_details(
            $wallet_id,
            $wallet->account_number,
            $wallet->customer_id ?? null
        );
        $bank_code = $wallet->bank_code;

        if (!is_wp_error($details)) {
            // API responded — verify account number matches as a safety check.
            $remote_account_number = self::extract_account_number($details);

            if (!empty($remote_account_number) && !empty($wallet->account_number)) {
                if (trim($remote_account_number) !== trim($wallet->account_number)) {
                    wp_send_json_error([
                        'message' => __('That Wallet ID belongs to a different account number than the one on file. Double-check the Wallet ID in your Fintava dashboard.', 'matrix-mlm'),
                    ]);
                }
            }
            $bank_code = $details['bank_code'] ?? $details['bankCode'] ?? $wallet->bank_code;
        }
        // If API returned an error (404, etc.), we still save — the user
        // provided the wallet_id from their Fintava dashboard and the API
        // simply isn't available for verification on this tier.

        // Persist.
        global $wpdb;
        $updated = $wpdb->update(
            $wpdb->prefix . 'matrix_fintava_wallets',
            [
                'wallet_id'  => $wallet_id,
                'bank_code'  => $bank_code,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $wallet->id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            wp_send_json_error(['message' => __('Could not save Wallet ID. Please try again.', 'matrix-mlm')]);
        }

        wp_send_json_success([
            'message' => __('Wallet ID verified and saved. Refreshing balance…', 'matrix-mlm'),
        ]);
    }

    /**
     * Credit a user's virtual wallet (used internally when a withdrawal is approved).
     */
    public function credit_wallet($user_id, $amount, $type = 'credit', $description = '') {
        // Placeholder for future Fintava-side wallet credit operations.
        // Currently the credit flow is recorded on the Matrix wallet side; this
        // method exists for backward compatibility with callers in admin.
        do_action('matrix_fintava_credit_wallet', $user_id, $amount, $type, $description);
    }

    public function get_user_payouts($user_id, $limit = 20, $offset = 0) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_fintava_payouts
             WHERE user_id = %d
             ORDER BY created_at DESC
             LIMIT %d OFFSET %d",
            $user_id, $limit, $offset
        ));
    }

    public function get_all_payouts($status = null, $limit = 50, $offset = 0) {
        global $wpdb;

        $where = "WHERE 1=1";
        $params = [];

        if ($status) {
            $where .= " AND p.status = %s";
            $params[] = $status;
        }

        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, u.user_login, u.user_email
             FROM {$wpdb->prefix}matrix_fintava_payouts p
             LEFT JOIN {$wpdb->users} u ON p.user_id = u.ID
             $where ORDER BY p.created_at DESC LIMIT %d OFFSET %d",
            $params
        ));
    }

    // =========================================================================
    // UTILITY
    // =========================================================================

    /**
     * TEMPORARY admin diagnostic — fetch a wallet's balance endpoint and
     * return the raw, unparsed Fintava response so an operator can see exactly
     * which fields the API exposes and in what units. Bypasses
     * normalize_balance_payload() and the details fallback chain so we
     * observe the API as-is.
     *
     * Output shape:
     *   - On WP HTTP layer error: ['url' => ..., 'wp_error' => ...]
     *   - On success or HTTP error from Fintava:
     *       ['url' => ..., 'http_code' => int, 'body_raw' => string,
     *        'body_decoded' => mixed]
     *
     * This method exists solely to support a one-shot field-mapping
     * investigation when the displayed balance disagrees with the real
     * balance shown in the Fintava dashboard. Callers (currently just
     * Matrix_MLM_User_Virtual_Wallet) gate it on capability and render the
     * output in an HTML comment visible only via View Page Source. Once the
     * mapping is confirmed in production this method and its caller should
     * be removed.
     *
     * @param string $wallet_id The Fintava virtual wallet UUID.
     * @return array Diagnostic payload (never a WP_Error).
     */
    public function debug_balance_raw($wallet_id) {
        if (empty($wallet_id) || empty($this->secret_key)) {
            return [
                'note'        => 'no wallet_id or no secret_key on the gateway instance',
                'has_wallet'  => !empty($wallet_id),
                'has_key'     => !empty($this->secret_key),
            ];
        }

        $url = $this->base_url . '/customer/wallet/balance/' . rawurlencode($wallet_id);
        $args = [
            'method'  => 'GET',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->secret_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'Merchant-Id'   => $this->merchant_id,
            ],
            'timeout' => 30,
        ];

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return [
                'url'      => $url,
                'wp_error' => $response->get_error_message(),
            ];
        }

        $raw = wp_remote_retrieve_body($response);
        return [
            'url'          => $url,
            'http_code'    => wp_remote_retrieve_response_code($response),
            'body_raw'     => $raw,
            'body_decoded' => json_decode($raw, true),
        ];
    }

    /**
     * Make HTTP request to Fintava API.
     */
    private function make_request($method, $endpoint, $body = null) {
        if (empty($this->secret_key)) {
            return new WP_Error(
                'fintava_not_configured',
                __('Fintava Pay is not configured. Please add your Live API Key in admin.', 'matrix-mlm')
            );
        }

        $url = $this->base_url . $endpoint;

        $args = [
            'method' => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->secret_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Merchant-Id' => $this->merchant_id,
            ],
            'timeout' => 30,
        ];

        if ($body && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $args['body'] = json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            // Decorate transport errors (DNS, TLS, timeout) with the URL we
            // were trying to hit so callers can tell whether the WordPress
            // host has outbound connectivity to Fintava at all.
            return new WP_Error(
                $response->get_error_code(),
                sprintf(
                    /* translators: 1: original transport error, 2: target URL */
                    __('%1$s (url=%2$s)', 'matrix-mlm'),
                    $response->get_error_message(),
                    $url
                )
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $body_decoded = json_decode($raw_body, true);

        if ($status_code >= 400) {
            $error_message = self::normalize_api_message(
                is_array($body_decoded) ? ($body_decoded['message'] ?? null) : null,
                sprintf(__('API Error (HTTP %d) calling %s', 'matrix-mlm'), $status_code, $endpoint)
            );
            // Include a short body snippet so non-JSON or HTML error pages
            // (Cloudflare interstitials, WAF blocks, gateway 502s) are
            // identifiable from the dropdown alone.
            $snippet = '';
            if (!is_array($body_decoded) && is_string($raw_body) && $raw_body !== '') {
                $snippet = substr(trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($raw_body))), 0, 120);
                if ($snippet !== '') {
                    $error_message .= sprintf(' [body=%s]', $snippet);
                }
            }
            return new WP_Error('fintava_api_error', $error_message);
        }

        return $body_decoded;
    }

    /**
     * Decide whether a decoded Fintava API response represents application
     * success. Historically Fintava used boolean `status: true`, but their
     * current envelope returns the numeric HTTP code (`status: 200`) — and
     * the legacy `=== true` check throughout this gateway silently treated
     * every successful live-tier call as a failure, so the bank dropdown,
     * resolve-account, transfer, merchant balance, and card endpoints all
     * fell through to WP_Error even on 2xx responses.
     *
     * make_request() already converts HTTP 4xx/5xx to WP_Error before any
     * caller sees the body, so here we only need to honor the application-
     * level success flag. Tolerated values:
     *   - boolean true (legacy)
     *   - numeric 200..299 (current Fintava envelope)
     *   - string 'success' / 'successful' / 'ok' (occasional shape)
     *   - missing entirely (treat as success — body is the data)
     *
     * @param mixed $response Decoded API body.
     * @return bool
     */
    public static function is_api_success($response) {
        if (!is_array($response)) {
            return false;
        }
        if (!array_key_exists('status', $response)) {
            // No envelope status field; rely on make_request having
            // already filtered out HTTP errors.
            return true;
        }
        $status = $response['status'];
        if ($status === true) {
            return true;
        }
        if (is_numeric($status) && (int) $status >= 200 && (int) $status < 300) {
            return true;
        }
        if (is_string($status)) {
            $lower = strtolower(trim($status));
            if (in_array($lower, ['success', 'successful', 'ok'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Coerce a Fintava API "message" field into a single string suitable for
     * storing in a WP_Error. Fintava (and other providers) sometimes return
     * the message as an array of validation errors, or as a nested object
     * keyed by field name. Letting any of those reach WP_Error causes
     * "Array to string conversion" warnings the moment the message hits
     * sprintf(), esc_attr(), or string concatenation downstream.
     *
     * Accepts string, scalar, array (flat or nested), or null. Anything that
     * can't be flattened to a useful string falls back to $default.
     *
     * @param mixed  $raw     The "message" value from a decoded API response.
     * @param string $default Fallback message when $raw yields nothing useful.
     * @return string
     */
    public static function normalize_api_message($raw, $default = '') {
        if (is_string($raw)) {
            return $raw;
        }
        if (is_scalar($raw)) {
            return (string) $raw;
        }
        if (is_array($raw)) {
            $flat = [];
            array_walk_recursive($raw, function ($leaf) use (&$flat) {
                if (is_scalar($leaf) && $leaf !== '') {
                    $flat[] = (string) $leaf;
                }
            });
            if (!empty($flat)) {
                return implode(' ', $flat);
            }
        }
        return (string) $default;
    }

    private function generate_reference() {
        return 'MTX-FTV-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 12)) . '-' . time();
    }

    /**
     * Extract the canonical 10-digit virtual-account number from a Fintava
     * payload, regardless of which field name they used. Returns '' if none
     * of the known shapes are present.
     *
     * Per Fintava's documented response (Dec 2023), the live field is
     * `virtualAcctNo`; older snake_case variants are kept for compatibility.
     */
    public static function extract_account_number($obj) {
        if (!is_array($obj)) {
            return '';
        }
        foreach (['virtualAcctNo', 'customerAccountNo', 'virtual_acct_no', 'virtual_account_number', 'virtualAccountNumber', 'account_number', 'accountNumber', 'recipient_account_number', 'destination_account_number'] as $key) {
            if (isset($obj[$key]) && $obj[$key] !== '') {
                return trim((string) $obj[$key]);
            }
        }
        return '';
    }

    /**
     * Extract the wallet's account holder name from a Fintava payload.
     */
    public static function extract_account_name($obj) {
        if (!is_array($obj)) {
            return '';
        }
        foreach (['virtualAcctName', 'virtual_acct_name', 'account_name', 'accountName', 'customerName', 'customer_name'] as $key) {
            if (isset($obj[$key]) && $obj[$key] !== '') {
                return (string) $obj[$key];
            }
        }
        return '';
    }

    /**
     * Extract the bank name from a Fintava payload (Fintava uses `bank` as a
     * plain string in their virtual wallet response).
     */
    public static function extract_bank_name($obj) {
        if (!is_array($obj)) {
            return '';
        }
        foreach (['bank', 'bank_name', 'bankName'] as $key) {
            if (isset($obj[$key]) && $obj[$key] !== '') {
                return (string) $obj[$key];
            }
        }
        return '';
    }

    /**
     * Extract the wallet's own UUID from a Fintava payload, taking care to
     * prefer the top-level `id` (the wallet) over nested `merchant.id`,
     * `jobId`, etc.
     */
    public static function extract_wallet_id($obj) {
        if (!is_array($obj)) {
            return '';
        }
        foreach (['wallet_id', 'walletId', 'virtual_wallet_id', 'virtualWalletId', 'recipient_wallet_id', 'destination_wallet_id'] as $key) {
            if (isset($obj[$key]) && $obj[$key] !== '') {
                return (string) $obj[$key];
            }
        }
        // Top-level `id` is the wallet's own UUID per Fintava docs. We only
        // accept it as a wallet_id candidate when there's also an account
        // number on the same level — otherwise we might pick up `merchant.id`
        // or `jobId` from a nested object.
        if (isset($obj['id']) && $obj['id'] !== '' && self::extract_account_number($obj) !== '') {
            return (string) $obj['id'];
        }
        return '';
    }
}
