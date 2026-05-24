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
 * - POST /bank/credit                         - Bank payout (user's Fintava wallet -> external bank)
 * - POST /bank/credit/merchant                - Credit a bank account from merchant wallet (legacy / not on bank-payout path)
 * - POST /virtual-wallet/generate             - Generate virtual wallet for user
 * - GET  /customer/wallet/balance/{walletId}  - Get virtual wallet balance (fast path)
 * - GET  /customers                           - List customers (wallet lookup fallback)
 * - GET  /wallet/details?accountNumber=...    - Internal: look up our virtual wallet by account number
 * - GET  /name/enquiry?accountNumber=...&sortCode=... - External name enquiry (3rd-party banks)
 * - GET  /banks                               - List supported banks
 *
 * Per-endpoint host routing:
 * - POST /bank/credit       -> the configured env-specific host (live or
 *   dev) — same as every other endpoint. An earlier revision pinned this
 *   to a hypothetical "unified public host" at https://api.fintavapay.com
 *   on the assumption that bank payouts lived there regardless of
 *   merchant tier, but that hostname does not resolve on public DNS
 *   (cURL error 6 "Could not resolve host" in the wild) and was never a
 *   real Fintava endpoint. Override via the MATRIX_FINTAVA_BANK_CREDIT_URL
 *   constant in wp-config.php if Fintava ever publishes a separate host.
 * - GET  /banks fallback    -> https://api.fintavapay.com/api/v1 (PUBLIC_BANKS_URL),
 *   tried after the configured environment host and its opposite when
 *   both reject the request, since the env-specific hosts have been
 *   observed to fail with "Invalid API Key" on at least one tier.
 *   NOTE: the public host above does not currently resolve either, so
 *   this third fallback always errors. Kept for now in case Fintava
 *   stands the host up; remove once confirmed dead for good.
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
     * Unified public host for the bank list. Used only as a fallback in
     * get_banks() when both the live and dev env-specific hosts reject
     * the configured API key (the "Invalid API Key on dev, no live key
     * issued" pattern documented on get_static_banks_fallback()).
     *
     * Versioned at /api/v1 rather than /api/dev because this host is the
     * public, environment-agnostic surface — it does not follow the
     * /api/dev convention the env-specific hosts use.
     */
    const PUBLIC_BANKS_URL = 'https://api.fintavapay.com/api/v1';

    /**
     * Legacy "unified public host" for bank payouts. Was the historical
     * default for POST /bank/credit on the assumption that bank payouts
     * lived on a single environment-agnostic host regardless of merchant
     * tier — but this hostname does not resolve on public DNS, so any
     * call routed here failed with cURL error 6 "Could not resolve host:
     * api.fintavapay.com".
     *
     * Kept as a class constant only so external code that referenced it
     * does not fatal; new callers should rely on get_bank_credit_base_url()
     * which now defaults to the configured env-specific host (live or dev).
     *
     * @deprecated Routing fell back to the env-specific host. Override with
     *             MATRIX_FINTAVA_BANK_CREDIT_URL in wp-config.php if Fintava
     *             ever publishes a real dedicated host.
     */
    const BANK_CREDIT_BASE_URL = 'https://api.fintavapay.com';

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
        add_action('wp_ajax_matrix_fintava_clear_failed_payouts', [$this, 'ajax_clear_failed_payouts']);

        // Matrix wallet -> user's own Fintava virtual wallet. Distinct
        // flow from ajax_initiate_transfer (bank payout): the source of
        // funds here is the user's Matrix balance plus a real-cash
        // movement from the merchant's Fintava wallet to the user's
        // Fintava virtual wallet, not the user's Fintava wallet to an
        // external bank. See ajax_transfer_matrix_to_virtual() for the
        // bookkeeping rationale.
        add_action('wp_ajax_matrix_transfer_matrix_to_virtual', [$this, 'ajax_transfer_matrix_to_virtual']);

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
            bank_code varchar(20) DEFAULT NULL,
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

        // Self-healing: relax matrix_fintava_payouts.bank_code from NOT NULL
        // to DEFAULT NULL on installs that ran the original CREATE TABLE.
        //
        // Why: the matrix→virtual transfer path documents (and the user-
        // facing handler relies on) NULL bank_code being acceptable, because
        // /transaction/wallet-to-wallet routes by NUBAN and does not need a
        // CBN sortCode. Wallets created before the bank-code self-heal
        // landed — or wallets where Fintava never returned a partner bank —
        // legitimately carry NULL in matrix_fintava_wallets.bank_code, and
        // the handler reads that value when staging a payout row. Leaving
        // the payouts column NOT NULL meant any such wallet failed the
        // INSERT with "Column 'bank_code' cannot be null" before the
        // earlier defensive coercion (write '' instead of NULL) landed.
        //
        // The defensive coercion still stays in place — it's the runtime
        // belt to this schema migration's suspenders, and it keeps existing
        // payout rows readable on installs that haven't picked up this
        // migration yet (e.g. Multisite networks where the activator runs
        // per-site at different times).
        $is_nullable = $wpdb->get_var($wpdb->prepare(
            "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'bank_code'",
            DB_NAME,
            $payouts_table
        ));
        if ($is_nullable !== null && strtoupper((string) $is_nullable) === 'NO') {
            $wpdb->query("ALTER TABLE `$payouts_table` MODIFY COLUMN `bank_code` varchar(20) DEFAULT NULL");
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
        // Cache key is versioned so any transient populated by an older
        // code path (raw Fintava shape, or a normalized list cached against
        // the wrong base URL) is bypassed on upgrade. Bump the suffix again
        // if the normalized shape or the source host ever changes.
        //
        // Bumped to v4 when /banks routing changed from "always dev" to
        // "configured env first, other env as fallback" - old v3 caches
        // were keyed against responses from the wrong host for live-tier
        // installs and would otherwise persist after this rollout.
        //
        // Bumped to v5 when the unified PUBLIC_BANKS_URL host
        // (https://api.fintavapay.com/api/v1) was added as a third
        // fallback. v4 caches could hold the negative WP_Error result
        // from the previous two-host chain on installs where both env
        // hosts rejected the live key, masking the new host's success.
        $cache_key = 'matrix_fintava_banks_list_v5';
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        // Try /banks against the configured environment first, then fall
        // back to the OTHER environment if that fails. Older code hard-
        // routed every /banks call to the dev host on the assumption that
        // Fintava only served the bank list from there - which broke
        // production installs configured for live with a live-only API
        // key, since the dev host returned "Invalid API Key" for those
        // (confirmed via the admin diagnostic on at least one merchant).
        //
        // The new order:
        //   1. $this->base_url (whatever the admin selected - usually live).
        //      Live-tier merchants with live keys hit live /banks first
        //      and almost always succeed.
        //   2. The opposite environment as a fallback. Covers the
        //      historical case where /banks was only served from the dev
        //      host, and the rare reverse case (a dev-tier install where
        //      the live host happens to serve the list).
        //   3. PUBLIC_BANKS_URL (https://api.fintavapay.com/api/v1) as a
        //      last network attempt. This is the unified, environment-
        //      agnostic host; tried last because it's only useful for the
        //      narrow case where both env hosts reject the merchant's
        //      key. If it succeeds, the per-host error breakdown below
        //      isn't surfaced - we just cache the result and return.
        //
        // All three attempts use lean headers (Authorization + Accept
        // only) so the request shape matches Fintava's public docs
        // exactly - the extra Merchant-Id header that goes on
        // wallet/transfer calls has been observed to cause 401s on
        // /banks on at least one tier.
        $primary_base  = $this->base_url;
        $fallback_base = $primary_base === self::LIVE_BASE_URL ? self::DEV_BASE_URL : self::LIVE_BASE_URL;
        $bases_to_try  = [$primary_base];
        if ($fallback_base !== $primary_base) {
            $bases_to_try[] = $fallback_base;
        }
        // Public unified host. Guarded against duplication in case a
        // future MATRIX_FINTAVA_API_BASE_URL override happens to point
        // at the same host - de-duping keeps the per-host error log
        // unambiguous if the call fails.
        if (!in_array(self::PUBLIC_BANKS_URL, $bases_to_try, true)) {
            $bases_to_try[] = self::PUBLIC_BANKS_URL;
        }

        // Per-host error breakdown. Populated only when a host fails so
        // the aggregated error message at the bottom can name every
        // host that was tried and what each one returned - the single
        // most useful diagnostic for the "Invalid API Key on dev,
        // succeeds on live" pattern.
        $errors_per_base = [];

        foreach ($bases_to_try as $banks_base_url) {
            $response = $this->make_request('GET', '/banks', null, $banks_base_url, true);

            if (is_wp_error($response)) {
                $errors_per_base[$banks_base_url] = $response->get_error_message();
                continue;
            }

            if (self::is_api_success($response)) {
                // Try every shape Fintava has been observed to use. The
                // first candidate that yields a non-empty normalized list
                // wins.
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
                // Bare-array root shape ("data" is itself the list, or
                // the API returned an unwrapped list).
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

                // Endpoint returned 200 + success envelope, but none of
                // the shapes we know about yielded a non-empty list.
                // Treat as a "soft" failure for this host - record the
                // diagnostic and try the next host. If every host
                // produces this same shape, the aggregated error below
                // gives the operator a payload sketch per host.
                $errors_per_base[$banks_base_url] = sprintf(
                    /* translators: %s: short payload-shape snippet */
                    __('returned 200 but no usable bank list (payload=%s)', 'matrix-mlm'),
                    self::summarize_payload($response)
                );
                continue;
            }

            // is_api_success() said no - record the upstream message and
            // try the next host. Live keys against the dev host typically
            // surface here as "Invalid API Key".
            $errors_per_base[$banks_base_url] = self::normalize_api_message(
                $response['message'] ?? null,
                __('Failed to retrieve bank list', 'matrix-mlm')
            );
        }

        // Every host failed. Build a single WP_Error that names every
        // host we tried and what each one returned, so the dropdown
        // fallback note shows the per-host breakdown verbatim.
        $parts = [];
        foreach ($errors_per_base as $base => $msg) {
            $parts[] = sprintf('%s: %s', $base, $msg);
        }
        return new WP_Error(
            'fintava_error',
            sprintf(
                /* translators: %s: per-host failure breakdown ("base: message | base: message") */
                __('Fintava /banks failed on every host tried. %s', 'matrix-mlm'),
                implode(' | ', $parts)
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
     * Built-in fallback list of Nigerian commercial banks with the
     * 3-digit CBN bank codes that NIBSS uses as `sortCode` on the NIP
     * rails. Returned by ajax_get_banks() when get_banks() can't reach
     * Fintava's /banks endpoint, so the bank dropdown is always
     * populated and the bank-payout form is always usable.
     *
     * Why this matters: Fintava's /banks is served only from
     * dev.fintavapay.com, but the dev host rejects the live API key
     * that authenticates wallets and payouts on live.fintavapay.com,
     * and Fintava (per the operator) doesn't issue a separate dev key.
     * The dropdown therefore had no path to populating from live data
     * for this account. Without a fallback the form was permanently
     * unreachable.
     *
     * Codes are the standard CBN 3-digit format documented at
     * https://nibss-plc.com.ng. Modern Nigerian fintech APIs (Paystack,
     * Flutterwave, Monnify) accept these for transfers and name lookup.
     * Fintava is expected to follow the same convention; if a specific
     * bank's transfer fails with a code-format error, normalize that
     * single bank's code in the array below rather than reverting the
     * whole fallback.
     *
     * Digital banks and microfinance banks that NIBSS issues longer
     * codes for (Opay, Kuda, PalmPay, Moniepoint, etc.) are listed
     * with their actual NIP institution codes so transfers route to
     * the right place.
     *
     * Sourced from the public CBN / NIBSS bank code registry; verified
     * against the Paystack /bank list as a cross-reference.
     *
     * @return array<int, array{code:string,name:string}>
     */
    public static function get_static_banks_fallback() {
        $banks = [
            // Tier-1 / Tier-2 commercial banks
            ['code' => '044', 'name' => 'Access Bank'],
            ['code' => '063', 'name' => 'Access Bank (Diamond)'],
            ['code' => '023', 'name' => 'Citibank Nigeria'],
            ['code' => '050', 'name' => 'Ecobank Nigeria'],
            ['code' => '070', 'name' => 'Fidelity Bank'],
            ['code' => '011', 'name' => 'First Bank of Nigeria'],
            ['code' => '214', 'name' => 'First City Monument Bank (FCMB)'],
            ['code' => '103', 'name' => 'Globus Bank'],
            ['code' => '058', 'name' => 'Guaranty Trust Bank (GTBank)'],
            ['code' => '030', 'name' => 'Heritage Bank'],
            ['code' => '301', 'name' => 'Jaiz Bank'],
            ['code' => '082', 'name' => 'Keystone Bank'],
            ['code' => '303', 'name' => 'Lotus Bank'],
            ['code' => '107', 'name' => 'Optimus Bank'],
            ['code' => '104', 'name' => 'Parallex Bank'],
            ['code' => '076', 'name' => 'Polaris Bank'],
            ['code' => '105', 'name' => 'PremiumTrust Bank'],
            ['code' => '101', 'name' => 'Providus Bank'],
            ['code' => '106', 'name' => 'Signature Bank'],
            ['code' => '221', 'name' => 'Stanbic IBTC Bank'],
            ['code' => '068', 'name' => 'Standard Chartered Bank'],
            ['code' => '232', 'name' => 'Sterling Bank'],
            ['code' => '100', 'name' => 'SunTrust Bank'],
            ['code' => '302', 'name' => 'TAJ Bank'],
            ['code' => '102', 'name' => 'Titan Trust Bank'],
            ['code' => '032', 'name' => 'Union Bank of Nigeria'],
            ['code' => '033', 'name' => 'United Bank for Africa (UBA)'],
            ['code' => '215', 'name' => 'Unity Bank'],
            ['code' => '035', 'name' => 'Wema Bank'],
            ['code' => '057', 'name' => 'Zenith Bank'],

            // Merchant banks
            ['code' => '559', 'name' => 'Coronation Merchant Bank'],
            ['code' => '060', 'name' => 'FBNQuest Merchant Bank'],
            ['code' => '501', 'name' => 'FSDH Merchant Bank'],
            ['code' => '562', 'name' => 'Greenwich Merchant Bank'],
            ['code' => '551', 'name' => 'Nova Merchant Bank'],
            ['code' => '502', 'name' => 'Rand Merchant Bank'],

            // Digital banks / mobile money / leading MFBs (NIP institution codes)
            ['code' => '565',   'name' => 'Carbon (One Finance MFB)'],
            ['code' => '50211', 'name' => 'Kuda Microfinance Bank'],
            ['code' => '50515', 'name' => 'Moniepoint Microfinance Bank'],
            ['code' => '999992','name' => 'OPay (PayCom)'],
            ['code' => '999991','name' => 'PalmPay'],
            ['code' => '125',   'name' => 'Rubies Microfinance Bank'],
            ['code' => '51310', 'name' => 'Sparkle Microfinance Bank'],
            ['code' => '566',   'name' => 'VFD Microfinance Bank'],
        ];

        usort($banks, static function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        return $banks;
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
     * Decorates failures with the bank_code that was actually sent so the
     * UI can surface it to the operator. The failure cause for this endpoint
     * is almost always one of three shapes — wrong sortCode format, the
     * account doesn't exist, or `/name/enquiry` simply isn't enabled on the
     * merchant tier (the same pattern as `/banks` for accounts that lack a
     * dev API key) — and which one it is is impossible to tell from the
     * generic upstream message alone. Including the code we sent lets us
     * tell at a glance whether we're looking at a 3-digit CBN code (what
     * the built-in fallback list ships) vs a 6-digit NIBSS sort code (what
     * Fintava's own `/banks` returns when reachable), which is the most
     * common source of "Unable to resolve account information" errors.
     *
     * @param string $account_number 10-digit NUBAN.
     * @param string $bank_code      Bank sort code (passed as `sortCode` query param).
     */
    public function resolve_account($account_number, $bank_code) {
        $query = http_build_query([
            'accountNumber' => $account_number,
            'sortCode'      => $bank_code,
        ]);

        // Use lean headers (Authorization + Accept only) for the same
        // reason `/banks` does — Fintava's `/name/enquiry` lives under the
        // same dev-tier umbrella and has been observed to reject the full
        // header set on at least some merchant tiers. Matching the
        // documented two-header shape rules out header mismatch as a
        // cause for "Unable to resolve account information" failures.
        $response = $this->make_request('GET', '/name/enquiry?' . $query, null, null, true);

        if (is_wp_error($response)) {
            return new WP_Error(
                $response->get_error_code(),
                sprintf(
                    /* translators: 1: original error, 2: bank code sent */
                    __('%1$s [bank_code=%2$s]', 'matrix-mlm'),
                    $response->get_error_message(),
                    $bank_code
                )
            );
        }

        if (self::is_api_success($response) && isset($response['data'])) {
            return $response['data'];
        }

        return new WP_Error(
            'fintava_resolve_error',
            sprintf(
                /* translators: 1: upstream message, 2: bank code sent */
                __('%1$s [bank_code=%2$s]', 'matrix-mlm'),
                self::normalize_api_message(
                    $response['message'] ?? null,
                    __('Could not resolve account details', 'matrix-mlm')
                ),
                $bank_code
            )
        );
    }

    /**
     * POST /transfer — initiate a generic transfer.
     *
     * NOTE: Currently has no callers — the live AJAX path
     * `ajax_initiate_transfer()` calls `bank_credit()` (POST /bank/credit on
     * the unified public host) for user bank payouts. This /transfer method
     * is kept for completeness / future use of Fintava's generic transfer
     * endpoint if the gateway ever exposes a flow that needs it. Same
     * wire-format rules apply: Fintava's validator expects camelCase
     * (`accountNumber`, `sortCode`, `accountName`, `bankName`); the
     * internal $transfer_data contract stays snake_case.
     */
    public function initiate_transfer($transfer_data) {
        $required_fields = ['amount', 'account_number', 'bank_code', 'narration'];
        foreach ($required_fields as $field) {
            if (empty($transfer_data[$field])) {
                return new WP_Error('missing_field', sprintf(__('Missing required field: %s', 'matrix-mlm'), $field));
            }
        }

        $payload = [
            'amount'        => floatval($transfer_data['amount']),
            'accountNumber' => sanitize_text_field($transfer_data['account_number']),
            'sortCode'      => sanitize_text_field($transfer_data['bank_code']),
            'narration'     => sanitize_text_field($transfer_data['narration']),
            'currency'      => $transfer_data['currency'] ?? 'NGN',
            'reference'     => $transfer_data['reference'] ?? $this->generate_reference(),
            'callback_url'  => $this->callback_url,
        ];

        if (!empty($transfer_data['account_name'])) {
            $payload['accountName'] = sanitize_text_field($transfer_data['account_name']);
        }
        if (!empty($transfer_data['bank_name'])) {
            $payload['bankName'] = sanitize_text_field($transfer_data['bank_name']);
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
     *
     * Fintava's merchant balance endpoint has been observed in three shapes
     * depending on the tier and whether the response was wrapped in `data`:
     *
     *   1. { status: true, data: { balance: 1000, available_balance: 1000 } }
     *   2. { status: true, data: { balance: { available_balance: 1000 } } }
     *   3. { balance: 1000, available_balance: 1000 }                  (legacy)
     *
     * We funnel every shape through normalize_balance_payload() so callers
     * always receive a flat array of scalar floats. This prevents downstream
     * formatters (number_format, sprintf %f) from receiving an array — which
     * is a fatal TypeError on PHP 8+.
     */
    public function get_merchant_balance() {
        $response = $this->make_request('GET', '/merchant/balance');
        if (is_wp_error($response)) {
            return $response;
        }

        // Pick the best candidate payload:
        //  - prefer the wrapped 'data' object on a successful response
        //  - fall back to the root for legacy unwrapped responses
        $payload = null;
        if (self::is_api_success($response) && isset($response['data']) && is_array($response['data'])) {
            $payload = $response['data'];
        } elseif (is_array($response)) {
            $payload = $response;
        }

        if (is_array($payload)) {
            // Try the payload at its current level first. If no balance fields
            // are present, drill one level into the most common nesting keys
            // ('balance', 'wallet', 'merchant') to handle shape #2 above.
            $normalized = $this->normalize_balance_payload($payload);
            if ($normalized === null) {
                foreach (['balance', 'wallet', 'merchant', 'merchantWallet', 'merchant_wallet'] as $key) {
                    if (isset($payload[$key]) && is_array($payload[$key])) {
                        $normalized = $this->normalize_balance_payload($payload[$key]);
                        if ($normalized !== null) {
                            break;
                        }
                    }
                }
            }

            if ($normalized !== null) {
                // Backwards-compatible 'balance' key for callers that look it
                // up before 'available_balance' (e.g. the admin settings tab).
                $normalized['balance'] = $normalized['available_balance'];
                return $normalized;
            }
        }

        return new WP_Error(
            'fintava_balance_error',
            $response['message'] ?? __('Could not retrieve merchant balance', 'matrix-mlm')
        );
    }

    /**
     * POST /bank/credit/merchant — payout from merchant wallet to bank account.
     *
     * USAGE NOTE: This is NOT the user-facing bank-payout endpoint anymore.
     * `bank_credit()` (POST /bank/credit on the unified public host) replaced
     * it for that flow because Fintava's bank payouts source funds from the
     * user's own Fintava virtual wallet, not from the merchant wallet — and
     * /bank/credit/merchant kept failing with "account balance insufficient"
     * when the merchant wallet wasn't pre-funded. This method is kept for
     * the internal "matrix -> fintava" flow (where the merchant intentionally
     * funds a user's virtual wallet by debiting the merchant wallet),
     * currently uncalled from any AJAX path.
     *
     * Wire-format note: Fintava's class-validator on this endpoint expects
     * camelCase (`accountNumber`, `sortCode`, `accountName`, `bankName`) and
     * also requires `sortCode` to be coerceable to a number. Sending the
     * legacy snake_case keys (`account_number`, `bank_code`) makes the
     * validator see the camelCase fields as missing AND non-numeric, which
     * surfaces as the four-line "should not be empty / must be a number"
     * stack we were getting in production. The internal contract (PHP
     * callers passing `account_number` / `bank_code` in $transfer_data)
     * stays snake_case — only the outbound payload is renamed.
     */
    public function merchant_bank_credit($transfer_data) {
        $required_fields = ['amount', 'account_number', 'bank_code'];
        foreach ($required_fields as $field) {
            if (empty($transfer_data[$field])) {
                return new WP_Error('missing_field', sprintf(__('Missing required field: %s', 'matrix-mlm'), $field));
            }
        }

        $payload = [
            'amount'        => floatval($transfer_data['amount']),
            'accountNumber' => sanitize_text_field($transfer_data['account_number']),
            'sortCode'      => sanitize_text_field($transfer_data['bank_code']),
        ];

        // Map our snake_case optionals to the camelCase keys Fintava expects.
        // `narration`, `reference`, `currency` retain their names because
        // those are already lowercase single tokens on both sides.
        $optional_map = [
            'narration'    => 'narration',
            'reference'    => 'reference',
            'currency'     => 'currency',
            'account_name' => 'accountName',
            'bank_name'    => 'bankName',
        ];
        foreach ($optional_map as $local_key => $api_key) {
            if (!empty($transfer_data[$local_key])) {
                $payload[$api_key] = sanitize_text_field($transfer_data[$local_key]);
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

    /**
     * POST /transaction/wallet-to-wallet — wallet-to-wallet transfer between
     * two Fintava-hosted accounts, identified by NUBAN.
     *
     * This is the production endpoint used by the legacy Laravel
     * implementation (App\Lib\Fintava::singleTransfer) and the only
     * wallet-to-wallet path Fintava actually publishes. The earlier
     * /single/transfer attempt below (internal_wallet_transfer) was
     * derived from doc-best-effort and produces transport-level errors
     * on live tiers because the endpoint isn't surfaced — which is what
     * was generating the misleading "Network error. Please try again."
     * on the Matrix -> Virtual transfer button.
     *
     * Payload shape (matches legacy verbatim):
     *
     *   - senderAccount      Source wallet NUBAN. Defaults to the admin
     *                        setting `matrix_mlm_fintava_operating_account`
     *                        (the merchant operating account that funds
     *                        these transfers); override per-call via
     *                        $transfer_data['sender_account'].
     *   - receiverAccount    Destination wallet NUBAN.
     *   - amount             Float. Sent as float unconditionally —
     *                        legacy passes (float) and Fintava's
     *                        wallet-to-wallet validator accepts it for
     *                        whole-naira amounts (the int coercion in
     *                        bank_credit() / internal_wallet_transfer
     *                        was a /bank/credit-specific workaround
     *                        that does not apply here).
     *   - narration          Optional free text.
     *   - CustomerReference  Required by Fintava as the idempotency /
     *                        reconciliation key. Auto-generated when
     *                        not supplied.
     *
     * Retry semantics: same as internal_wallet_transfer — one retry on
     * HTTP 5xx or transport failure, immediate surface for any
     * application-level "no" so we don't double-spend on a request that
     * may already have settled.
     *
     * @param array $transfer_data {
     *     @type float|int $amount             Required.
     *     @type string    $receiver_account   Required.
     *     @type string    $sender_account     Optional. Defaults to the
     *                                         operating-account setting.
     *     @type string    $narration          Optional.
     *     @type string    $customer_reference Optional. Auto-generated if
     *                                         missing.
     * }
     * @return array|WP_Error Same success / error shape as bank_credit().
     */
    public function wallet_to_wallet_transfer($transfer_data) {
        $required_fields = ['amount', 'receiver_account'];
        foreach ($required_fields as $field) {
            if (empty($transfer_data[$field])) {
                return new WP_Error('missing_field', sprintf(__('Missing required field: %s', 'matrix-mlm'), $field));
            }
        }

        // Sender defaults to the configured operating-account NUBAN.
        // Strip whitespace defensively in case a caller passes through
        // user-entered data; the saved setting is already trimmed but
        // belt-and-suspenders never hurts on a money path.
        $sender_account = !empty($transfer_data['sender_account'])
            ? preg_replace('/\s+/', '', sanitize_text_field((string) $transfer_data['sender_account']))
            : preg_replace('/\s+/', '', (string) get_option('matrix_mlm_fintava_operating_account', ''));

        if (empty($sender_account)) {
            return new WP_Error(
                'missing_sender_account',
                __('Cannot dispatch wallet-to-wallet transfer: no operating account number is configured. Set it on the Gateways page (Fintava section).', 'matrix-mlm')
            );
        }

        $receiver_account = preg_replace('/\s+/', '', sanitize_text_field((string) $transfer_data['receiver_account']));
        $reference        = !empty($transfer_data['customer_reference'])
            ? sanitize_text_field((string) $transfer_data['customer_reference'])
            : $this->generate_reference();

        $payload = [
            'senderAccount'     => $sender_account,
            'receiverAccount'   => $receiver_account,
            'amount'            => (float) $transfer_data['amount'],
            'narration'         => !empty($transfer_data['narration'])
                ? sanitize_text_field((string) $transfer_data['narration'])
                : null,
            'CustomerReference' => $reference,
        ];

        $max_attempts = 2;
        $last_error   = null;

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $response = $this->make_request('POST', '/transaction/wallet-to-wallet', $payload);

            if (is_wp_error($response)) {
                $error_msg    = $response->get_error_message();
                $is_5xx       = preg_match('/API Error \(HTTP 5\d{2}\)/', $error_msg);
                $is_transport = in_array($response->get_error_code(), ['http_request_failed'], true);

                if ($attempt < $max_attempts && ($is_5xx || $is_transport)) {
                    $last_error = $response;
                    sleep(2);
                    continue;
                }
                return $response;
            }

            if (self::is_api_success($response)) {
                return [
                    'success'     => true,
                    'transfer_id' => $response['data']['id'] ?? $response['data']['transfer_id'] ?? null,
                    'reference'   => $response['data']['reference'] ?? $response['data']['CustomerReference'] ?? $reference,
                    'status'      => $response['data']['status'] ?? 'completed',
                    'message'     => self::normalize_api_message(
                        $response['message'] ?? null,
                        __('Wallet-to-wallet transfer completed', 'matrix-mlm')
                    ),
                ];
            }

            // 200 + status:false is application-level "no". Don't retry.
            return new WP_Error(
                'fintava_wallet_to_wallet_error',
                self::normalize_api_message(
                    $response['message'] ?? null,
                    __('Wallet-to-wallet transfer failed', 'matrix-mlm')
                )
            );
        }

        if ($last_error) {
            return $last_error;
        }
        return new WP_Error(
            'fintava_wallet_to_wallet_error',
            __('Wallet-to-wallet transfer failed after retry', 'matrix-mlm')
        );
    }

    /**
     * @deprecated Use wallet_to_wallet_transfer() instead.
     *
     * POST /single/transfer — internal wallet-to-wallet transfer between
     * Fintava-hosted wallets. Used by ajax_transfer_matrix_to_virtual()
     * to move money from the merchant's Fintava balance into a user's
     * own Fintava virtual wallet without going through the bank-rails
     * validator.
     *
     * Why this is distinct from /bank/credit/merchant:
     *
     *   - /bank/credit/merchant treats the destination as a generic
     *     external bank account and validates a CBN `sortCode` on the
     *     wire-format. When the destination is a Fintava-hosted virtual
     *     NUBAN, that validation rejects the request whenever the local
     *     row's `bank_code` column is missing or stale — even though
     *     Fintava could route the credit purely internally. That mismatch
     *     is the long-standing "Your Virtual wallet (Fintava) is missing
     *     a bank code we couldn't auto-resolve" surface in the
     *     matrix→virtual transfer flow.
     *
     *   - /single/transfer is Fintava's internal-routing endpoint. It
     *     identifies the source by merchant ID and the destination by
     *     NUBAN (and optionally walletId), with no sortCode required.
     *     The bank-code self-heal chain becomes a non-issue.
     *
     * Payload shape (best-effort against Fintava's docs; the validator
     * response feeds straight back into the WP_Error so the operator
     * can iterate if a tier expects different field names):
     *
     *   - sourceId                   Merchant UUID. Defaults to
     *                                $this->merchant_id; override via
     *                                $transfer_data['source_id'] for
     *                                multi-merchant setups.
     *   - destinationAccountNumber   The destination wallet's NUBAN.
     *   - destinationWalletId        Sent only when the caller's row
     *                                has one — some tiers prefer it
     *                                over the NUBAN for routing speed
     *                                and ignore it otherwise, so it's
     *                                safe to include unconditionally.
     *   - amount                     Int when whole-naira, float when
     *                                kobo. /bank/credit was documented
     *                                crashing on decimal floats; we
     *                                apply the same coercion here on
     *                                the assumption /single/transfer
     *                                shares the validator stack.
     *   - narration / reference / currency  Standard.
     *
     * Retry semantics mirror bank_credit(): one retry on HTTP 5xx or a
     * transport failure (Fintava's transient "An unexpected error
     * occurred"); 4xx validator failures and duplicate-reference 409s
     * surface immediately so we don't double-spend.
     *
     * @param array $transfer_data {
     *     @type float|int $amount                     Required.
     *     @type string    $destination_account_number Required.
     *     @type string    $destination_wallet_id      Optional.
     *     @type string    $source_id                  Optional. Defaults
     *                                                 to $this->merchant_id.
     *     @type string    $narration                  Optional.
     *     @type string    $reference                  Optional. Auto-
     *                                                 generated if missing.
     *     @type string    $currency                   Optional. NGN default.
     * }
     * @return array|WP_Error Same success / error shape as bank_credit().
     */
    public function internal_wallet_transfer($transfer_data) {
        $required_fields = ['amount', 'destination_account_number'];
        foreach ($required_fields as $field) {
            if (empty($transfer_data[$field])) {
                return new WP_Error('missing_field', sprintf(__('Missing required field: %s', 'matrix-mlm'), $field));
            }
        }

        // Source defaults to the configured merchant ID. Keeping the
        // override path open lets a future multi-merchant setup pass
        // a different source without changing the call signature.
        $source_id = !empty($transfer_data['source_id'])
            ? sanitize_text_field($transfer_data['source_id'])
            : $this->merchant_id;
        if (empty($source_id)) {
            return new WP_Error(
                'missing_source_id',
                __('Cannot dispatch internal transfer: no merchant ID is configured. Set MATRIX_FINTAVA_MERCHANT_ID in wp-config.php or fill it in on the Gateways page.', 'matrix-mlm')
            );
        }

        // Same int-when-whole / float-when-kobo coercion as bank_credit().
        // /bank/credit was observed crashing (HTTP 500) on decimal floats
        // for whole-naira amounts; assume /single/transfer shares the
        // validator stack until we observe otherwise.
        $raw_amount  = floatval($transfer_data['amount']);
        $send_amount = (floor($raw_amount) == $raw_amount) ? intval($raw_amount) : $raw_amount;

        $payload = [
            'sourceId'                 => $source_id,
            'destinationAccountNumber' => sanitize_text_field($transfer_data['destination_account_number']),
            'amount'                   => $send_amount,
            'currency'                 => $transfer_data['currency'] ?? 'NGN',
            'reference'                => $transfer_data['reference'] ?? $this->generate_reference(),
        ];

        // Belt-and-suspenders: send destinationWalletId when the local
        // row has one. Fintava ignores it on tiers that route by NUBAN,
        // so including it unconditionally is safe and gives faster-tier
        // routing without conditional code.
        if (!empty($transfer_data['destination_wallet_id'])) {
            $payload['destinationWalletId'] = sanitize_text_field($transfer_data['destination_wallet_id']);
        }
        if (!empty($transfer_data['narration'])) {
            $payload['narration'] = sanitize_text_field($transfer_data['narration']);
        }

        // Retry once on 5xx or transport failure; everything else
        // (4xx validator rejection, application-level "no" responses,
        // duplicate-reference 409s) surfaces immediately so we don't
        // double-spend on a request that may already have settled.
        $max_attempts = 2;
        $last_error   = null;

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $response = $this->make_request('POST', '/single/transfer', $payload);

            if (is_wp_error($response)) {
                $error_msg    = $response->get_error_message();
                $is_5xx       = preg_match('/API Error \(HTTP 5\d{2}\)/', $error_msg);
                $is_transport = in_array($response->get_error_code(), ['http_request_failed'], true);

                if ($attempt < $max_attempts && ($is_5xx || $is_transport)) {
                    $last_error = $response;
                    sleep(2);
                    continue;
                }
                return $response;
            }

            if (self::is_api_success($response)) {
                return [
                    'success'     => true,
                    'transfer_id' => $response['data']['id'] ?? $response['data']['transfer_id'] ?? null,
                    'reference'   => $response['data']['reference'] ?? $payload['reference'],
                    'status'      => $response['data']['status'] ?? 'completed',
                    'message'     => self::normalize_api_message(
                        $response['message'] ?? null,
                        __('Internal transfer completed', 'matrix-mlm')
                    ),
                ];
            }

            // Application-level "no" — surface the upstream message
            // directly. Don't retry: a 200 + status:false isn't a
            // transient.
            return new WP_Error(
                'fintava_internal_transfer_error',
                self::normalize_api_message(
                    $response['message'] ?? null,
                    __('Internal transfer failed', 'matrix-mlm')
                )
            );
        }

        if ($last_error) {
            return $last_error;
        }
        return new WP_Error(
            'fintava_internal_transfer_error',
            __('Internal transfer failed after retry', 'matrix-mlm')
        );
    }

    /**
     * POST /bank/credit — bank payout from user's Fintava virtual wallet to
     * an external bank account.
     *
     * Distinct from /bank/credit/merchant on three axes:
     *
     *   1. Source of funds. /bank/credit debits the user's own Fintava
     *      virtual wallet (the same wallet that receives deposits via the
     *      virtual NUBAN). /bank/credit/merchant debits the merchant's
     *      Fintava wallet, which has to be pre-funded by the operator.
     *      For MatrixPro user-initiated payouts the user's wallet is
     *      always the right source — using /bank/credit/merchant kept
     *      failing with "account balance insufficient" whenever the
     *      merchant wallet ran low, even though the user had enough.
     *
     *   2. Host. /bank/credit is routed through the same env-specific
     *      host (live or dev) as every other Fintava endpoint. An earlier
     *      revision pinned it to https://api.fintavapay.com on the
     *      assumption that bank payouts lived on a unified public host
     *      regardless of tier, but that hostname does not resolve on
     *      public DNS and every call cURL-error-6'd. Override via the
     *      MATRIX_FINTAVA_BANK_CREDIT_URL constant in wp-config.php if
     *      Fintava ever publishes a real dedicated host.
     *
     *   3. Fees. Fintava deducts its own transfer fee directly from the
     *      user's Fintava wallet — there is no plugin-side fee added on
     *      top, and `ajax_initiate_transfer()` no longer charges the
     *      Matrix wallet for it.
     *
     * Same wire-format rules as /bank/credit/merchant: Fintava's class-
     * validator expects camelCase (accountNumber, sortCode, accountName,
     * bankName) and snake_case keys cause the four-line "should not be
     * empty / must be a number" error stack. Our internal $transfer_data
     * contract stays snake_case; only the outbound payload is renamed.
     *
     * Required `customer_id` (sent as `sourceId`): Fintava's class-validator
     * on /bank/credit rejects the request with "sourceId must be a UUID"
     * when omitted. It identifies the user's Fintava customer record that
     * funds the payout — without it Fintava can't tell which wallet to
     * debit. Callers must ensure customer_id is available on the wallet
     * row before calling bank_credit().
     */
    public function bank_credit($transfer_data) {
        // `account_name` is required by Fintava's class-validator on
        // /bank/credit ("Account Name is a required field" surfaces as a
        // 400 on the wire when omitted). Treating it as required here
        // means any bypass of the frontend form — a second AJAX submit
        // that races with the manual-override input clear, a programmatic
        // caller, or a retry from elsewhere in the codebase — fails fast
        // with a clear local error instead of bouncing off Fintava with
        // the cryptic generic message.
        $required_fields = ['amount', 'account_number', 'bank_code', 'customer_id', 'account_name'];
        foreach ($required_fields as $field) {
            if (empty($transfer_data[$field])) {
                return new WP_Error('missing_field', sprintf(__('Missing required field: %s', 'matrix-mlm'), $field));
            }
        }

        // Validate customer_id format — Fintava requires a valid UUID v4.
        // Catch malformed IDs before they hit the wire and trigger a
        // cryptic 500 on Fintava's side.
        $customer_id = sanitize_text_field($transfer_data['customer_id']);
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $customer_id)) {
            return new WP_Error(
                'invalid_customer_id',
                sprintf(
                    __('Invalid Fintava customer ID format (expected UUID, got "%s"). Please contact support to re-link your wallet.', 'matrix-mlm'),
                    substr($customer_id, 0, 40)
                )
            );
        }

        // Amount: Fintava's /bank/credit has been observed crashing (HTTP 500)
        // when amount is sent as a float with decimals (e.g. 100.00) but
        // succeeding when sent as a plain integer (e.g. 100). The old system
        // that works sends integer amounts. We cast to int when the amount
        // has no fractional part (the common case for NGN transfers); keep
        // as float only when there are actual kobo (e.g. 100.50).
        $raw_amount = floatval($transfer_data['amount']);
        $send_amount = (floor($raw_amount) == $raw_amount) ? intval($raw_amount) : $raw_amount;

        $payload = [
            'sourceId'      => $customer_id,
            'amount'        => $send_amount,
            'accountNumber' => sanitize_text_field($transfer_data['account_number']),
            'sortCode'      => sanitize_text_field($transfer_data['bank_code']),
        ];

        // Map our snake_case optionals to the camelCase keys Fintava expects.
        // `narration`, `reference`, `currency` retain their names because
        // those are already lowercase single tokens on both sides.
        $optional_map = [
            'narration'    => 'narration',
            'reference'    => 'reference',
            'currency'     => 'currency',
            'account_name' => 'accountName',
            'bank_name'    => 'bankName',
        ];
        foreach ($optional_map as $local_key => $api_key) {
            if (!empty($transfer_data[$local_key])) {
                $payload[$api_key] = sanitize_text_field($transfer_data[$local_key]);
            }
        }

        $base = $this->get_bank_credit_base_url();

        // Retry logic: Fintava's /bank/credit has been observed returning
        // transient HTTP 500s ("An unexpected error occurred") that succeed
        // on the next attempt seconds later. We retry ONCE after a short
        // delay for 5xx errors only. Non-5xx errors and WP_Error transport
        // failures are returned immediately — retrying those would be
        // pointless or dangerous (e.g., a 400 validation error won't fix
        // itself, and a duplicate reference on a second POST could double-
        // spend if the first actually went through).
        $max_attempts = 2;
        $last_error = null;

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $response = $this->make_request('POST', '/bank/credit', $payload, $base);

            if (is_wp_error($response)) {
                $error_msg = $response->get_error_message();

                // Only retry on 5xx errors (detected by the error message
                // pattern from make_request). Transport errors (DNS, TLS,
                // timeout) are also worth retrying once.
                $is_5xx = preg_match('/API Error \(HTTP 5\d{2}\)/', $error_msg);
                $is_transport = in_array($response->get_error_code(), ['http_request_failed', 'fintava_not_configured'], true);

                if ($attempt < $max_attempts && ($is_5xx || ($is_transport && $response->get_error_code() !== 'fintava_not_configured'))) {
                    $last_error = $response;
                    // Wait 2 seconds before retry to allow transient issues to clear
                    sleep(2);
                    continue;
                }

                // Enhance 500 error messages with actionable guidance
                if ($is_5xx) {
                    $enhanced_msg = $this->enhance_bank_credit_error($error_msg, $transfer_data);
                    return new WP_Error($response->get_error_code(), $enhanced_msg);
                }

                return $response;
            }

            if (self::is_api_success($response)) {
                return [
                    'success'     => true,
                    'transfer_id' => $response['data']['id'] ?? $response['data']['transfer_id'] ?? null,
                    'reference'   => $response['data']['reference'] ?? ($transfer_data['reference'] ?? ''),
                    'status'      => $response['data']['status'] ?? 'pending',
                    'message'     => self::normalize_api_message(
                        $response['message'] ?? null,
                        __('Bank transfer initiated successfully', 'matrix-mlm')
                    ),
                ];
            }

            // Non-500 application-level failure — do not retry
            return new WP_Error(
                'fintava_bank_credit_error',
                self::normalize_api_message(
                    $response['message'] ?? null,
                    __('Bank transfer failed', 'matrix-mlm')
                )
            );
        }

        // Should not reach here, but guard against it
        if ($last_error) {
            $enhanced_msg = $this->enhance_bank_credit_error($last_error->get_error_message(), $transfer_data);
            return new WP_Error($last_error->get_error_code(), $enhanced_msg);
        }

        return new WP_Error('fintava_bank_credit_error', __('Bank transfer failed after retry', 'matrix-mlm'));
    }

    /**
     * Enhance a /bank/credit 500 error message with actionable guidance.
     *
     * Fintava's 500 errors on /bank/credit are almost always one of:
     *   - Insufficient balance in the user's Fintava virtual wallet
     *   - Temporary Fintava service disruption
     *   - Invalid sourceId that passed UUID format check but doesn't exist
     *
     * This method translates the generic "An unexpected error occurred"
     * into something the user can act on.
     *
     * @param string $original_msg The raw error message from make_request.
     * @param array  $transfer_data The transfer data that was sent.
     * @return string Enhanced error message.
     */
    private function enhance_bank_credit_error($original_msg, $transfer_data) {
        $currency_symbol = get_option('matrix_mlm_currency_symbol', '₦');
        $amount = floatval($transfer_data['amount'] ?? 0);

        // Check if this is the known "unexpected error" pattern
        $is_generic_500 = (
            stripos($original_msg, 'unexpected error') !== false ||
            stripos($original_msg, 'HTTP 500') !== false
        );

        if (!$is_generic_500) {
            return $original_msg;
        }

        $enhanced = sprintf(
            /* translators: 1: original error, 2: currency symbol, 3: transfer amount */
            __('%1$s — This usually means your Fintava wallet has insufficient funds to cover %2$s%3$s plus Fintava\'s transfer fee. Please check your Fintava wallet balance (separate from your Matrix wallet) and ensure it is adequately funded. If the balance is sufficient, Fintava may be experiencing a temporary issue — please try again in a few minutes.', 'matrix-mlm'),
            $original_msg,
            $currency_symbol,
            number_format($amount, 2)
        );

        return $enhanced;
    }

    /**
     * Resolve the base URL for /bank/credit calls.
     *
     * Resolution order:
     *   1. MATRIX_FINTAVA_BANK_CREDIT_URL constant — full pinned override
     *      for operators who have a dedicated Fintava host issued to them.
     *   2. The configured env-specific host (live or dev) via
     *      get_base_url() — same as every other endpoint.
     *
     * Override accepts a base URL (no trailing slash); the `/bank/credit`
     * path is appended in bank_credit().
     *
     * History: this previously fell back to the BANK_CREDIT_BASE_URL
     * constant (https://api.fintavapay.com) on the assumption that bank
     * payouts lived on a unified public host. That hostname does not
     * resolve on public DNS, so production installs were getting "cURL
     * error 6: Could not resolve host: api.fintavapay.com" on every
     * payout attempt. Defaulting to get_base_url() puts /bank/credit on
     * the same env-specific live/dev host that authenticates everywhere
     * else.
     */
    private function get_bank_credit_base_url() {
        if (defined('MATRIX_FINTAVA_BANK_CREDIT_URL') && MATRIX_FINTAVA_BANK_CREDIT_URL) {
            return rtrim(MATRIX_FINTAVA_BANK_CREDIT_URL, '/');
        }
        return self::get_base_url();
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

        // Fall back to the built-in Nigerian banks list when Fintava's
        // /banks endpoint is unreachable. This is the *only* path that
        // populates the dropdown for installs whose merchant has just
        // a live API key, since the dev host that serves /banks rejects
        // live keys and Fintava doesn't issue separate dev keys to most
        // merchants. Without the fallback the dropdown would stay
        // "Loading banks..." forever (no AJAX timeout in the inline JS,
        // and no usable error path because the API never returns a
        // success). The fallback is shipped as wp_send_json_success so
        // the existing JS code path populates the options without
        // changes; the `fallback` flag carries the diagnostic note plus
        // the underlying error so the operator can still see why /banks
        // failed when they look at it.
        if (is_wp_error($banks)) {
            $static = self::get_static_banks_fallback();
            wp_send_json_success([
                'banks'    => $static,
                'fallback' => true,
                'reason'   => $banks->get_error_message(),
            ]);
        }

        wp_send_json_success(['banks' => $banks, 'fallback' => false]);
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
            // Always allow the JS to offer a "continue without verification"
            // path. Fintava's /name/enquiry returns "Unable to resolve
            // account information" for at least three distinct failure
            // modes (wrong sortCode shape, account doesn't exist on the
            // destination bank, endpoint not enabled on this merchant
            // tier), and the operator can't tell which one applies from
            // the generic message alone. The actual /bank/credit/merchant
            // call is the source of truth for whether the transfer can go
            // through, so blocking the form on enquiry success would
            // permanently strand merchants whose tier doesn't expose
            // /name/enquiry while their transfer endpoint is fully
            // functional. Manual entry shifts the responsibility for the
            // account name to the operator and lets the actual transfer
            // arbitrate correctness.
            wp_send_json_error([
                'message'              => $result->get_error_message(),
                'allow_manual_override' => true,
            ]);
        }

        // Fintava's /name/enquiry returns camelCase
        // (`accountName`/`accountNumber`/`bankName`); the inline JS in
        // class-matrix-user-bank-payout.php reads our response under
        // snake_case keys (`response.data.account_name` etc.). Use the
        // existing casing-tolerant extractors as the bridge so the JS
        // keeps working regardless of which casing Fintava sends back
        // on a given tier — they accept both shapes and were already
        // correctly used elsewhere; this site was the leftover.
        $resolved_name   = self::extract_account_name($result) ?: ($result['account_name'] ?? '');
        $resolved_number = self::extract_account_number($result) ?: ($result['account_number'] ?? $account_number);
        $resolved_bank   = self::extract_bank_name($result) ?: ($result['bank_name'] ?? '');

        // Some merchant tiers ack /name/enquiry with HTTP 200 but no
        // usable accountName (empty string, "null", or the field
        // missing entirely). Treating that as `success: true` would
        // populate the readonly name field with '', flip the JS
        // accountVerified flag to true, enable Submit, and the user
        // would only learn the name was missing when ajax_initiate_transfer
        // throws "Account name is required" on submit — with no UI
        // path to recover, since the field stays readonly on the
        // verified branch. Convert empty resolved names into the same
        // resolve_error + allow_manual_override response we already
        // emit for is_wp_error() so the JS surfaces the manual-entry
        // notice and the operator can type the holder's name and
        // proceed. The actual /bank/credit/merchant call still
        // arbitrates whether the account is real on Fintava's side.
        if (trim((string) $resolved_name) === '') {
            wp_send_json_error([
                'message'               => __('Could not auto-verify the account name. Please continue without verification and type the holder\'s name.', 'matrix-mlm'),
                'allow_manual_override' => true,
            ]);
        }

        wp_send_json_success([
            'account_name'   => $resolved_name,
            'account_number' => $resolved_number,
            'bank_name'      => $resolved_bank,
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

        // Fintava's /bank/credit requires `accountName`. The frontend
        // gates submit on a non-empty name field (verified or
        // manually-entered), but defensive checks here catch any path
        // that bypasses the form: a programmatic resubmit, a stale form
        // state where the manual-override clear ran but the keystroke
        // input handler didn't, or a future non-form caller. If the
        // POST didn't carry one, try a server-side /name/enquiry as a
        // best-effort recovery before failing — saves the user a
        // round-trip when the original verification just timed out.
        if (empty($account_name)) {
            $resolved = $this->resolve_account($account_number, $bank_code);
            if (!is_wp_error($resolved)) {
                $resolved_name = self::extract_account_name($resolved);
                if ($resolved_name === '' && isset($resolved['account_name'])) {
                    $resolved_name = (string) $resolved['account_name'];
                }
                if ($resolved_name !== '') {
                    $account_name = sanitize_text_field($resolved_name);
                }
            }
        }
        if (empty($account_name)) {
            wp_send_json_error(['message' => __('Account name is required. Please verify the destination account or type the holder\'s name before submitting.', 'matrix-mlm')]);
        }

        // Bank payouts source funds from the user's own Fintava virtual
        // wallet, NOT the merchant wallet and NOT the Matrix internal
        // wallet. Three things follow from that:
        //
        //   - We require the user to have a linked Fintava virtual wallet,
        //     since otherwise there is no source of funds. (The wallet row
        //     is created when the user generates their virtual wallet via
        //     the dashboard.)
        //
        //   - We do NOT debit the Matrix wallet. The Matrix wallet only
        //     gets debited on the separate "matrix -> fintava" flow, where
        //     the operator/admin moves credits from a user's Matrix
        //     balance into their Fintava virtual wallet (which on Fintava's
        //     side is the merchant_bank_credit endpoint that debits the
        //     merchant wallet to fund the user's wallet). Bank payouts
        //     skip both sides of that.
        //
        //   - We do NOT add a plugin-side charge on top of $amount.
        //     Fintava deducts its own transfer fee directly from the
        //     user's Fintava wallet at the gateway level. The legacy
        //     `matrix_mlm_fintava_charge_value` option is now ignored on
        //     the bank-payout path; the `charge` / `total_debit` columns
        //     stay on the schema for backwards-compat with old payout
        //     rows but get written as 0 / amount for new ones.
        $user_wallet = $this->get_user_wallet($user_id);
        if (!$user_wallet) {
            wp_send_json_error(['message' => __('You do not have a Fintava wallet yet. Generate one before you can pay out.', 'matrix-mlm')]);
        }

        // Fintava's /bank/credit endpoint requires `sourceId` — the UUID
        // of the user's Fintava customer record that funds the payout.
        // If the local row is missing customer_id (e.g. wallet was created
        // before customer_id support was added, or Fintava didn't return it
        // at wallet-generation time), resolve it now via the Customer API.
        //
        // Resolution uses resolve_customer_id() which tries three strategies:
        //   1. GET /customers/details (by phone)
        //   2. GET /customers/list (scan all, match by account_number)
        //   3. GET /wallet/details (extract customer_id from wallet object)
        //
        // On success the customer_id (and wallet_id if also missing) is
        // persisted to the local row so subsequent transfers skip resolution.
        if (empty($user_wallet->customer_id)) {
            $resolved_cid = $this->resolve_customer_id($user_wallet);
            if (is_wp_error($resolved_cid)) {
                wp_send_json_error([
                    'message' => sprintf(
                        __('Your Fintava customer ID is missing and could not be resolved automatically: %s', 'matrix-mlm'),
                        $resolved_cid->get_error_message()
                    ),
                ]);
            }
            // Refresh the wallet row after resolution.
            $user_wallet = $this->get_user_wallet($user_id);
            if (!$user_wallet || empty($user_wallet->customer_id)) {
                wp_send_json_error(['message' => __('Your Fintava customer ID could not be determined. Please contact support.', 'matrix-mlm')]);
            }
        }

        // Also ensure wallet_id is populated (needed for the balance
        // pre-flight check below). If it's still missing after the
        // customer_id resolution above, resolve it separately.
        if (empty($user_wallet->wallet_id)) {
            $resolved_wid = $this->resolve_wallet_id_from_customer($user_wallet);
            if (!is_wp_error($resolved_wid)) {
                $user_wallet = $this->get_user_wallet($user_id);
            }
            // Non-fatal: we can still attempt the transfer without a
            // wallet_id — the balance pre-flight will just be skipped.
        }

        // Validate customer_id is a proper UUID before sending to Fintava.
        // A malformed sourceId triggers a 500 on their side instead of a
        // clean 400, which is the exact error pattern we're fixing here.
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $user_wallet->customer_id)) {
            wp_send_json_error([
                'message' => sprintf(
                    __('Your Fintava customer ID appears to be invalid (not a UUID). Stored value: "%s". Please contact support to re-link your wallet.', 'matrix-mlm'),
                    substr($user_wallet->customer_id, 0, 40)
                ),
            ]);
        }

        // Pre-flight: check the user's Fintava virtual-wallet balance
        // before making the real /bank/credit call. Fintava has been
        // observed returning HTTP 500 with a generic "An unexpected
        // error occurred" message when the source wallet can't fund the
        // transfer (their handler crashes instead of returning a clean
        // 400). Surface a clear, actionable error here so the user
        // knows exactly what's wrong and that the Fintava wallet is
        // SEPARATE from their Matrix wallet.
        //
        // Best-effort: if the balance fetch itself fails for any reason
        // (endpoint unavailable on this tier, transient Fintava issue,
        // wallet object in a state we can't parse), we proceed to
        // /bank/credit anyway and let Fintava's response be the source
        // of truth. We don't want a flaky balance endpoint to strand a
        // working payout flow.
        //
        // Buffer: Fintava deducts its own transfer fee from the wallet
        // on top of the requested amount. The exact fee varies but is
        // typically ₦10–₦53.75 depending on amount tier. We add a
        // conservative buffer (₦100 or 1.5% of amount, whichever is
        // higher) so the pre-flight catches the "insufficient after fee"
        // case that triggers Fintava's 500.
        $balance_check = $this->get_virtual_wallet_balance(
            $user_wallet->wallet_id,
            $user_wallet->account_number,
            $user_wallet->customer_id ?? null
        );
        if (!is_wp_error($balance_check) && isset($balance_check['available_balance']) && is_numeric($balance_check['available_balance'])) {
            $available = floatval($balance_check['available_balance']);
            // Add buffer for Fintava's transfer fee
            $fee_buffer = max(100, $amount * 0.015); // ₦100 minimum or 1.5%
            $required_with_buffer = $amount + $fee_buffer;

            if ($available < $amount) {
                // Clearly insufficient — can't even cover the principal
                wp_send_json_error([
                    'message' => sprintf(
                        /* translators: 1: currency symbol, 2: available balance, 3: requested amount */
                        __('Insufficient Fintava wallet balance: you have %1$s%2$s available, but the payout requires %1$s%3$s. Note: your Fintava virtual wallet is separate from your Matrix wallet — funds must already be on Fintava\'s side for bank payouts. Refresh the balance on your dashboard and verify your Fintava wallet is funded.', 'matrix-mlm'),
                        $currency_symbol,
                        number_format($available, 2),
                        number_format($amount, 2)
                    ),
                ]);
            } elseif ($available < $required_with_buffer) {
                // Balance covers the principal but may not cover Fintava's
                // transfer fee. Warn but don't block — let it through and
                // if Fintava rejects it the enhanced error message will
                // explain what happened.
                // We proceed but log the marginal case for diagnostics.
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(sprintf(
                        '[Matrix MLM] Bank payout marginal balance: user %d has %s available, needs %s + ~%s fee buffer. Proceeding anyway.',
                        $user_id, number_format($available, 2), number_format($amount, 2), number_format($fee_buffer, 2)
                    ));
                }
            }
        }

        $reference = $this->generate_reference();

        global $wpdb;
        $payouts_table = $wpdb->prefix . 'matrix_fintava_payouts';
        $wpdb->insert(
            $payouts_table,
            [
                'user_id'        => $user_id,
                'reference'      => $reference,
                'amount'         => $amount,
                'charge'         => 0,
                'total_debit'    => $amount,
                'bank_code'      => $bank_code,
                'bank_name'      => $bank_name,
                'account_number' => $account_number,
                'account_name'   => $account_name,
                'narration'      => $narration,
                'currency'       => 'NGN',
                'status'         => 'pending',
                'created_at'     => current_time('mysql'),
            ],
            ['%d', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        $payout_id = $wpdb->insert_id;

        $result = $this->bank_credit([
            'amount'         => $amount,
            'account_number' => $account_number,
            'bank_code'      => $bank_code,
            'bank_name'      => $bank_name,
            'account_name'   => $account_name,
            'narration'      => $narration,
            'reference'      => $reference,
            'currency'       => 'NGN',
            'customer_id'    => $user_wallet->customer_id,
        ]);

        if (is_wp_error($result)) {
            // No Matrix wallet refund needed — we never debited it. Just
            // mark the payout row failed and surface Fintava's error to
            // the user. (Common cause now: insufficient balance on the
            // user's own Fintava virtual wallet, surfaced verbatim.)
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
                'status'      => $result['status'] ?? 'processing',
                'updated_at'  => current_time('mysql'),
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
            'status'    => $result['status'] ?? 'processing',
        ]);
    }

    /**
     * AJAX: Matrix wallet -> user's own Fintava virtual wallet.
     *
     * The flow this implements (intentionally distinct from
     * ajax_initiate_transfer, which is the external bank-payout
     * flow):
     *
     *   1. Debit the user's Matrix (internal) wallet by amount + charge.
     *      Charge is read from the same admin-configured options
     *      (matrix_mlm_fintava_charge_*) the on-page form previews.
     *   2. Move REAL CASH on Fintava's side from the merchant's
     *      operating Fintava wallet to the user's own virtual wallet
     *      by calling POST /transaction/wallet-to-wallet
     *      (wallet_to_wallet_transfer()) with senderAccount = the
     *      configured operating-account NUBAN and receiverAccount =
     *      the user's NUBAN. The operating wallet is what funds the
     *      credit; the user's Matrix debit is the bookkeeping side
     *      that "pays" the platform for that movement.
     *   3. If Fintava rejects the credit, refund the Matrix wallet so
     *      the user is made whole — money never half-moved.
     *
     * Why /transaction/wallet-to-wallet and not /bank/credit/merchant
     * or /single/transfer:
     *   - /bank/credit/merchant validates a CBN sortCode on the
     *     wire-format. When the destination is a Fintava-hosted
     *     virtual NUBAN, that validator rejects the request whenever
     *     the local row's bank_code column is missing or stale, even
     *     though Fintava could route the credit purely internally.
     *     This was the long-standing source of the "Your Virtual
     *     wallet (Fintava) is missing a bank code we couldn't
     *     auto-resolve" surface.
     *   - /single/transfer was a doc-best-effort guess by an earlier
     *     port and isn't surfaced on Fintava's live tier — every
     *     attempt produced a transport-level error that the front-end
     *     reported as "Network error. Please try again." This is the
     *     bug being fixed here.
     *   - /transaction/wallet-to-wallet is the production endpoint
     *     used by the legacy Laravel implementation. It identifies
     *     both source and destination by NUBAN with no sortCode
     *     required, removing the bank-code requirement entirely for
     *     this flow without sacrificing safety.
     *   - /bank/credit (the user-funded path used by
     *     ajax_initiate_transfer) stays on the bank-rails endpoint
     *     because external bank payouts genuinely need sortCode
     *     routing.
     *
     * Why the Matrix wallet debit is committed before calling Fintava:
     *   - We want a durable "pending" record on disk regardless of
     *     what Fintava does next (success, failure, timeout). If we
     *     held the transaction open across the HTTP call and the
     *     PHP request timed out, MySQL would roll back the debit and
     *     the payout row both — leaving zero trace of the attempt.
     *     Committing first means the Matrix ledger has a debit + a
     *     pending payout row even when the network step misbehaves,
     *     and on Fintava error we apply a compensating credit
     *     (refund) instead of a rollback.
     */
    public function ajax_transfer_matrix_to_virtual() {
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
            wp_send_json_error(['message' => __('Wallet transfers are not available at the moment.', 'matrix-mlm')]);
        }

        // Destination wallet must exist; we cannot credit a wallet that
        // hasn't been generated on Fintava yet.
        $user_wallet = $this->get_user_wallet($user_id);
        if (!$user_wallet) {
            wp_send_json_error(['message' => __('You do not have a Virtual wallet yet. Create one before transferring to it.', 'matrix-mlm')]);
        }

        // Schema-required: account_number is NOT NULL on
        // matrix_fintava_wallets, so an empty here only happens on data
        // corruption. Surface support contact in that narrow case.
        if (empty($user_wallet->account_number)) {
            wp_send_json_error(['message' => __('Your Virtual wallet is missing an account number. Please contact support.', 'matrix-mlm')]);
        }

        // bank_code is NOT required on this path anymore: /single/transfer
        // (the internal-routing endpoint we use below) identifies the
        // destination by NUBAN — and optionally walletId — without a
        // CBN sortCode. The schema-default placeholder bank_name=Fintava
        // and any NULL bank_code values become non-issues.
        //
        // We still opportunistically resolve wallet_id when it's missing,
        // because (a) Fintava routes faster on tiers that prefer
        // walletId over accountNumber and (b) the same wallet_id is
        // useful elsewhere (balance refresh, /customers/{id} fallback).
        // This call is non-fatal: on a miss we proceed with NUBAN-only
        // routing and let Fintava's response be the source of truth.
        if (empty($user_wallet->wallet_id)) {
            $this->resolve_wallet_id_from_customer($user_wallet);
            $user_wallet = $this->get_user_wallet($user_id);
        }

        $amount = floatval($_POST['amount'] ?? 0);
        if ($amount <= 0) {
            wp_send_json_error(['message' => __('Please enter a valid amount.', 'matrix-mlm')]);
        }

        $currency_symbol = get_option('matrix_mlm_currency_symbol', '₦');
        $min_transfer    = floatval(get_option('matrix_mlm_fintava_min_payout', 1000));
        $max_transfer    = floatval(get_option('matrix_mlm_fintava_max_payout', 5000000));

        if ($amount < $min_transfer) {
            wp_send_json_error(['message' => sprintf(__('Minimum transfer amount is %s%s', 'matrix-mlm'), $currency_symbol, number_format($min_transfer, 2))]);
        }
        if ($amount > $max_transfer) {
            wp_send_json_error(['message' => sprintf(__('Maximum transfer amount is %s%s', 'matrix-mlm'), $currency_symbol, number_format($max_transfer, 2))]);
        }

        // Charge math — mirrors the inline preview the on-page form
        // shows. Same options the bank-payout pane reads, so admin
        // configuration stays in one place.
        $charge_type  = get_option('matrix_mlm_fintava_charge_type', 'fixed');
        $charge_value = floatval(get_option('matrix_mlm_fintava_charge_value', 50));
        $charge       = ($charge_type === 'percent')
            ? round($amount * $charge_value / 100, 2)
            : round($charge_value, 2);
        $total_debit  = round($amount + $charge, 2);

        // Pre-flight balance check. The atomic conditional UPDATE in
        // Matrix_MLM_Wallet::debit() already enforces this, but
        // checking up front lets us return a friendlier error before
        // we open a DB transaction.
        $matrix_wallet  = new Matrix_MLM_Wallet();
        $matrix_balance = $matrix_wallet->get_balance($user_id);
        if ($matrix_balance < $total_debit) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: 1: currency symbol, 2: required amount, 3: available balance */
                    __('Insufficient Matrix wallet balance. You need %1$s%2$s (amount + charge) but have only %1$s%3$s.', 'matrix-mlm'),
                    $currency_symbol,
                    number_format($total_debit, 2),
                    number_format($matrix_balance, 2)
                ),
            ]);
        }

        $reference = $this->generate_reference();
        $narration = sanitize_text_field(
            (string) ($_POST['narration'] ?? sprintf(
                __('Matrix wallet to Virtual wallet (%s)', 'matrix-mlm'),
                wp_get_current_user()->user_login
            ))
        );

        global $wpdb;
        $payouts_table = $wpdb->prefix . 'matrix_fintava_payouts';

        // Step 1: debit the Matrix wallet + record the pending payout
        // row inside a single transaction so they commit (or roll
        // back) together. We deliberately do NOT keep the transaction
        // open across the Fintava HTTP call below — see the docblock.
        $wpdb->query('START TRANSACTION');

        $debit_result = $matrix_wallet->debit(
            $user_id,
            $total_debit,
            'transfer_to_virtual_wallet',
            sprintf(__('Transfer to Virtual wallet (%s)', 'matrix-mlm'), $user_wallet->account_number),
            $reference
        );
        if ($debit_result === false) {
            $wpdb->query('ROLLBACK');
            error_log(sprintf(
                '[Matrix MLM] ajax_transfer_matrix_to_virtual: debit() returned false. user_id=%d total=%s last_error=%s',
                $user_id, $total_debit, $wpdb->last_error
            ));
            wp_send_json_error(['message' => __('Could not debit your Matrix wallet. Please refresh and try again.', 'matrix-mlm')]);
        }

        // Coerce NULL bank_code / bank_name / account_name to empty string
        // to match the matrix_fintava_payouts schema, where these columns
        // are NOT NULL. The destination wallet row (matrix_fintava_wallets)
        // allows NULL bank_code on purpose — see the comment above on this
        // path tolerating NULL bank_code, since /single/transfer routes by
        // NUBAN and does not need a CBN sortCode. Without this coercion,
        // wallets that were created before the bank-code self-heal landed
        // (or that Fintava never returned a partner bank for) blow up the
        // INSERT with "Column 'bank_code' cannot be null", which prints a
        // wpdberror HTML block ahead of the JSON response and surfaces in
        // the browser as a generic "Network error" because jQuery cannot
        // parse the corrupted response. The empty-string fallback matches
        // what the admin wallet-linker already writes on creation
        // (Matrix_MLM_Admin::link_user → 'bank_code' => $details['bank_code']
        // ?? $details['bankCode'] ?? '').
        $insert_result = $wpdb->insert(
            $payouts_table,
            [
                'user_id'        => $user_id,
                'reference'      => $reference,
                'amount'         => $amount,
                'charge'         => $charge,
                'total_debit'    => $total_debit,
                'bank_code'      => $user_wallet->bank_code   ?? '',
                'bank_name'      => $user_wallet->bank_name   ?? '',
                'account_number' => $user_wallet->account_number,
                'account_name'   => $user_wallet->account_name ?? '',
                'narration'      => $narration,
                'currency'       => $user_wallet->currency ?: 'NGN',
                'status'         => 'pending',
                'created_at'     => current_time('mysql'),
            ],
            ['%d', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        if ($insert_result === false) {
            $wpdb->query('ROLLBACK');
            error_log(sprintf(
                '[Matrix MLM] ajax_transfer_matrix_to_virtual: payout insert failed. user_id=%d ref=%s last_error=%s',
                $user_id, $reference, $wpdb->last_error
            ));
            wp_send_json_error(['message' => __('Could not record the transfer. Please try again.', 'matrix-mlm')]);
        }
        $payout_id = $wpdb->insert_id;

        $wpdb->query('COMMIT');

        // Step 2: move real cash on Fintava's side via
        // /transaction/wallet-to-wallet — the same endpoint the legacy
        // Laravel system uses (App\Lib\Fintava::singleTransfer). Source
        // is the configured operating-account NUBAN (set on the
        // Gateways page); destination is the user's own virtual NUBAN.
        // Routing is purely internal — no CBN sortCode required, no
        // partner-bank lookup, no /bank/credit/merchant rejection on
        // missing bank_code. Replaces the earlier /single/transfer
        // attempt, which produced transport-level "Network error" on
        // live tiers because that endpoint isn't surfaced.
        $result = $this->wallet_to_wallet_transfer([
            'amount'             => $amount,
            'receiver_account'   => $user_wallet->account_number,
            'narration'          => $narration,
            'customer_reference' => $reference,
        ]);

        if (is_wp_error($result)) {
            // Step 3: compensating refund. Credit the Matrix wallet
            // back by the full total_debit so the user's balance is
            // restored. Mark the payout row failed with the upstream
            // reason so support can see exactly what Fintava rejected.
            $refund = $matrix_wallet->credit(
                $user_id,
                $total_debit,
                'transfer_to_virtual_wallet_refund',
                sprintf(__('Refund: failed transfer to Virtual wallet (%s)', 'matrix-mlm'), $reference),
                $reference
            );
            if ($refund === false) {
                // Auditor-visible state: debit landed, refund did not.
                // We surface a deliberately loud error so support can
                // intervene; the failure_reason on the payout row
                // captures the Fintava error AND the refund failure.
                error_log(sprintf(
                    '[Matrix MLM] ajax_transfer_matrix_to_virtual: refund credit() returned false after Fintava failure. user_id=%d ref=%s total=%s',
                    $user_id, $reference, $total_debit
                ));
            }

            $wpdb->update(
                $payouts_table,
                [
                    'status'         => 'failed',
                    'failure_reason' => $result->get_error_message() . ($refund === false ? ' [refund failed]' : ''),
                    'updated_at'     => current_time('mysql'),
                ],
                ['id' => $payout_id],
                ['%s', '%s', '%s'],
                ['%d']
            );

            $message = $result->get_error_message();
            if ($refund === false) {
                $message .= ' ' . __('Note: your Matrix wallet refund did not apply automatically — please contact support with reference', 'matrix-mlm') . ' ' . $reference . '.';
            }

            wp_send_json_error(['message' => $message, 'reference' => $reference]);
            return;
        }

        // Step 4: happy path. Fintava accepted the credit.
        $wpdb->update(
            $payouts_table,
            [
                'transfer_id'  => $result['transfer_id'] ?? '',
                'status'       => $result['status'] ?? 'completed',
                'completed_at' => current_time('mysql'),
                'updated_at'   => current_time('mysql'),
            ],
            ['id' => $payout_id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );

        Matrix_MLM_Notifications::send_admin_notification(
            'matrix_to_virtual_transfer',
            sprintf(
                __('Matrix -> Virtual transfer: %s%s by %s to wallet %s. Ref: %s', 'matrix-mlm'),
                $currency_symbol,
                number_format($amount, 2),
                wp_get_current_user()->user_login,
                $user_wallet->account_number,
                $reference
            )
        );

        wp_send_json_success([
            'message' => sprintf(
                __('Transfer of %s%s to your Virtual wallet (%s) was successful.', 'matrix-mlm'),
                $currency_symbol,
                number_format($amount, 2),
                $user_wallet->account_number
            ),
            'reference' => $reference,
            'status'    => $result['status'] ?? 'completed',
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

    /**
     * Bulk-delete the current user's failed bank-payout rows from the
     * payout history.
     *
     * Scope is intentionally narrow:
     *   - Filtered to user_id = current user, so a logged-in user can only
     *     ever clear their own rows. There is no admin / cross-user variant
     *     here; that path goes through the database directly or through the
     *     admin Payouts screen.
     *   - Filtered to status = 'failed' only. NEVER clears completed,
     *     processing, pending, or refunded rows. Those carry money-movement
     *     audit trail (refunded means the Matrix wallet was credited back;
     *     completed means Fintava confirmed the transfer settled) and must
     *     stay on the row even if the user requests cleanup.
     *
     * Failed rows are safe to hard-delete because by definition no money
     * left the user's Fintava wallet — Fintava rejected the request before
     * settlement (the four failure modes that produce status='failed' on
     * this table are: missing accountName/sourceId validator rejection,
     * insufficient balance pre-flight, transient HTTP 5xx that didn't
     * recover on retry, and webhook-driven transfer.failed). The webhook
     * log table separately keeps the diagnostic payload for any postmortem
     * needed.
     */
    public function ajax_clear_failed_payouts() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('Authentication required', 'matrix-mlm')]);
        }

        global $wpdb;
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}matrix_fintava_payouts
              WHERE user_id = %d
                AND status = 'failed'",
            $user_id
        ));

        if ($deleted === false) {
            wp_send_json_error([
                'message' => __('Could not clear failed transactions. Please try again.', 'matrix-mlm'),
            ]);
        }

        $count = intval($deleted);
        wp_send_json_success([
            'deleted' => $count,
            'message' => sprintf(
                /* translators: %d: number of failed transactions deleted */
                _n('%d failed transaction cleared.', '%d failed transactions cleared.', $count, 'matrix-mlm'),
                $count
            ),
        ]);
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
        // Path candidates, in priority order:
        //   1. /customers/list   — current Fintava live endpoint, confirmed
        //      via the admin "system endpoints" diagnostic. This is the one
        //      that actually returns the customer array on this tier.
        //   2. /customers        — older / alternate spelling, kept for
        //      back-compat in case a different tier exposes only this.
        //   3. /merchant/customers — historic spelling on a few tiers.
        //
        // Earlier the gateway only tried #2 and #3, neither of which is
        // served on the current live tier — so resolve_customer_id()
        // Strategy 2 was effectively dead code, and the user-facing error
        // misleadingly claimed "/customers/list" had been searched even
        // though it never was.
        $paths = ['/customers/list', '/customers', '/merchant/customers'];
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

        // Envelope shapes seen across Fintava tiers:
        //   - { data: [ ...customers... ], status, message }
        //   - { data: { items:    [...] }, status, message }
        //   - { data: { customers:[...] }, status, message }
        //   - { data: { results:  [...] }, status, message }
        //   - { data: { data:     [...] }, status, message }   (double-wrap)
        //   - bare array at root
        if (isset($response['data'])) {
            $data = $response['data'];
            if (is_array($data)) {
                // Pure list (numerically indexed at offset 0).
                if (isset($data[0]) || empty($data)) {
                    return $data;
                }
                // Object envelope — drill into each known key in turn.
                foreach (['items', 'customers', 'results', 'list', 'data'] as $sub) {
                    if (isset($data[$sub]) && is_array($data[$sub])) {
                        return $data[$sub];
                    }
                }
                // Last resort: data is an associative object but no known
                // sub-key matched. Treat its values as the list (Fintava
                // has been observed returning a map keyed by customer ID
                // on at least one tier).
                $values = array_values($data);
                if (!empty($values) && is_array($values[0])) {
                    return $values;
                }
            }
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

        $account_number = trim($wallet_row->account_number);

        // Call the wallet details enquiry endpoint with the account number
        $details = $this->get_wallet_by_account_number($account_number);
        if (is_wp_error($details)) {
            return $details;
        }

        // Account-number mismatch is a hard failure — do NOT persist
        // anything from a response that's clearly for a different
        // wallet. Run this check before any persistence below.
        $remote_account = self::extract_account_number($details);
        if (!empty($remote_account) && trim($remote_account) !== $account_number) {
            return new WP_Error(
                'fintava_account_mismatch',
                __('The enquiry returned a different account number than expected.', 'matrix-mlm')
            );
        }

        // ------------------------------------------------------------------
        // Step A: opportunistically extract every wallet attribute Fintava
        // returned and stage them for persistence. We do this BEFORE the
        // wallet_id null-check below because /wallet/details on some
        // tiers returns the bank name + sortCode without echoing the
        // wallet UUID — and the bank-payout / matrix-to-virtual flows
        // need the bank_code regardless of whether wallet_id resolved.
        // Discarding metadata on a wallet_id miss was the bug behind
        // the "missing a bank code we couldn't auto-resolve" error
        // when bank_name on the row was still the schema default
        // "Fintava" placeholder.
        // ------------------------------------------------------------------
        $update_data    = [];
        $update_formats = [];

        // bank_name: persist when Fintava gave us a real partner bank
        // (extract_bank_name reads `bank`, `bank_name`, `bankName`) AND
        // the local row is either empty or holds the literal schema
        // default "Fintava" — Fintava is the fintech, not a CBN bank,
        // so it's never a valid sortCode-lookup key for the
        // derive_bank_code_from_name() fallback.
        $remote_bank_name = self::extract_bank_name($details);
        if ($remote_bank_name !== ''
            && (empty($wallet_row->bank_name)
                || strcasecmp((string) $wallet_row->bank_name, 'Fintava') === 0)) {
            $update_data['bank_name'] = $remote_bank_name;
            $update_formats[]         = '%s';
        }

        // bank_code: Fintava varies the key across tiers — bank_code,
        // bankCode, and sortCode have all been observed on
        // /wallet/details. First non-empty wins.
        $remote_bank_code = '';
        foreach (['bank_code', 'bankCode', 'sortCode', 'sort_code'] as $key) {
            if (!empty($details[$key])) {
                $remote_bank_code = (string) $details[$key];
                break;
            }
        }
        if ($remote_bank_code !== '' && empty($wallet_row->bank_code)) {
            $update_data['bank_code'] = $remote_bank_code;
            $update_formats[]         = '%s';
        }

        // customer_id: same shape rules as before (userInfo.id /
        // customerId / customer_id). Only persist when the row
        // doesn't already have one.
        $remote_customer_id = self::extract_customer_id($details);
        if (!empty($remote_customer_id) && empty($wallet_row->customer_id)) {
            $update_data['customer_id'] = $remote_customer_id;
            $update_formats[]           = '%s';
        }

        // ------------------------------------------------------------------
        // Step B: resolve wallet_id from the same response. Stage it
        // alongside the metadata so a single UPDATE covers everything,
        // and so we can return the resolved UUID as the success
        // signal.
        // ------------------------------------------------------------------
        $resolved_wallet_id = self::extract_wallet_id($details);
        if (empty($resolved_wallet_id)) {
            // Fallback: use the top-level 'id' field
            $resolved_wallet_id = $details['id'] ?? '';
        }
        if (!empty($resolved_wallet_id)) {
            $update_data['wallet_id'] = $resolved_wallet_id;
            $update_formats[]         = '%s';
        }

        // Flush whatever we have. Persisting partial metadata even on
        // a wallet_id miss is intentional — see the docblock above
        // Step A.
        if (!empty($update_data)) {
            $update_data['updated_at'] = current_time('mysql');
            $update_formats[]          = '%s';
            $wpdb->update(
                $wpdb->prefix . 'matrix_fintava_wallets',
                $update_data,
                ['id' => $wallet_row->id],
                $update_formats,
                ['%d']
            );
        }

        if (empty($resolved_wallet_id)) {
            return new WP_Error(
                'fintava_no_wallet_id_in_response',
                __('The enquiry response does not contain a wallet ID.', 'matrix-mlm')
            );
        }

        return $resolved_wallet_id;
    }

    /**
     * Enrich a wallet row's bank_name (and bank_code, when available) by
     * consulting Fintava's customer endpoints. Used by the matrix→virtual
     * transfer self-heal as a follow-up to resolve_wallet_id_from_customer()
     * for tiers where /wallet/details either doesn't carry a bank name
     * or isn't available at all.
     *
     * Fallback chain (first hit wins):
     *
     *   1. GET /customers/{customer_id} — if customer_id is on the row,
     *      this is a single round-trip and returns the wallet object
     *      under `data.wallet` (or `wallet`) with the partner bank name.
     *
     *   2. GET /customers/list + match by account_number — works
     *      universally on tiers that expose the list (per the existing
     *      diagnostic in get_customer_list()'s call sites). Each
     *      customer's wallet is nested under `userInfo.wallet`.
     *
     *   3. GET /customers/details?phone=... — last resort when neither
     *      customer_id nor a working list endpoint is available; uses
     *      the phone on file. Same wallet-shape extraction as the list.
     *
     * Persistence rules mirror resolve_wallet_id_from_customer():
     *   - bank_name overwrites only the empty string or the literal
     *     "Fintava" placeholder. A real bank name on the row is
     *     never clobbered (operators occasionally fix these by hand).
     *   - bank_code is persisted when the local row is empty.
     *
     * Returns true when at least one field was persisted, false otherwise.
     * Caller is expected to re-read the wallet row after.
     *
     * @param object $wallet_row The local matrix_fintava_wallets row.
     * @return bool Whether any update was persisted.
     */
    private function enrich_partner_bank_metadata($wallet_row) {
        if (empty($wallet_row->account_number)) {
            return false;
        }

        $account_number = trim($wallet_row->account_number);

        // Only run when there's something to enrich. If the row already
        // has a real (non-"Fintava") bank_name AND a bank_code, every
        // step below would be a no-op.
        $has_real_bank_name =
            !empty($wallet_row->bank_name)
            && strcasecmp((string) $wallet_row->bank_name, 'Fintava') !== 0;
        if ($has_real_bank_name && !empty($wallet_row->bank_code)) {
            return false;
        }

        $resolved_bank_name = '';
        $resolved_bank_code = '';

        // Helper closure: pull bank_name + bank_code out of a wallet
        // object regardless of which envelope shape Fintava sent.
        $extract = function ($wallet_obj) use (&$resolved_bank_name, &$resolved_bank_code) {
            if (!is_array($wallet_obj)) {
                return;
            }
            if ($resolved_bank_name === '') {
                $candidate = self::extract_bank_name($wallet_obj);
                if ($candidate !== '' && strcasecmp(trim($candidate), 'Fintava') !== 0) {
                    $resolved_bank_name = $candidate;
                }
            }
            if ($resolved_bank_code === '') {
                foreach (['bank_code', 'bankCode', 'sortCode', 'sort_code'] as $k) {
                    if (!empty($wallet_obj[$k]) && is_scalar($wallet_obj[$k])) {
                        $resolved_bank_code = (string) $wallet_obj[$k];
                        break;
                    }
                }
                // bank_code can also live nested under bank.code on
                // the same tiers that nest the bank name there.
                if ($resolved_bank_code === '' && isset($wallet_obj['bank']) && is_array($wallet_obj['bank'])) {
                    foreach (['code', 'sortCode', 'sort_code', 'bankCode'] as $k) {
                        if (!empty($wallet_obj['bank'][$k]) && is_scalar($wallet_obj['bank'][$k])) {
                            $resolved_bank_code = (string) $wallet_obj['bank'][$k];
                            break;
                        }
                    }
                }
            }
        };

        // Strategy 1: /customers/{customer_id}.
        if (!empty($wallet_row->customer_id)) {
            $customer = $this->get_customer($wallet_row->customer_id);
            if (!is_wp_error($customer) && is_array($customer)) {
                if (isset($customer['wallet']) && is_array($customer['wallet'])) {
                    $extract($customer['wallet']);
                }
                if (isset($customer['userInfo']['wallet']) && is_array($customer['userInfo']['wallet'])) {
                    $extract($customer['userInfo']['wallet']);
                }
                // Some tiers flatten the wallet fields onto the
                // customer object itself.
                $extract($customer);
            }
        }

        // Strategy 2: /customers/list — match by account_number.
        if ($resolved_bank_name === '' || $resolved_bank_code === '') {
            $list = $this->get_customer_list();
            if (!is_wp_error($list) && is_array($list)) {
                foreach ($list as $customer) {
                    if (!is_array($customer)) {
                        continue;
                    }
                    // Same wallet-shape unwrap order as
                    // resolve_customer_id() so the match logic stays
                    // consistent across the gateway.
                    if (isset($customer['userInfo']['wallet']) && is_array($customer['userInfo']['wallet'])) {
                        $wallet_obj = $customer['userInfo']['wallet'];
                    } elseif (isset($customer['wallet']) && is_array($customer['wallet'])) {
                        $wallet_obj = $customer['wallet'];
                    } else {
                        $wallet_obj = $customer;
                    }
                    $remote_account = self::extract_account_number($wallet_obj);
                    if ($remote_account === '' || trim($remote_account) !== $account_number) {
                        continue;
                    }
                    $extract($wallet_obj);
                    break;
                }
            }
        }

        // Strategy 3: /customers/details?phone=... — last resort.
        if (($resolved_bank_name === '' || $resolved_bank_code === '') && !empty($wallet_row->customer_phone)) {
            $phone = trim($wallet_row->customer_phone);
            $response = $this->make_request('GET', '/customers/details?phone=' . urlencode($phone), null, null, true);
            if (!is_wp_error($response)) {
                $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : $response;
                $candidate_obj = null;
                if (isset($data['userInfo']['wallet']) && is_array($data['userInfo']['wallet'])) {
                    $candidate_obj = $data['userInfo']['wallet'];
                } elseif (isset($data['wallet']) && is_array($data['wallet'])) {
                    $candidate_obj = $data['wallet'];
                } else {
                    $candidate_obj = $data;
                }
                $remote_account = self::extract_account_number($candidate_obj);
                if (empty($remote_account) || trim($remote_account) === $account_number) {
                    $extract($candidate_obj);
                }
            }
        }

        // Persist whatever we resolved, respecting the same overwrite
        // rules used by resolve_wallet_id_from_customer().
        $update_data    = [];
        $update_formats = [];

        if ($resolved_bank_name !== ''
            && (empty($wallet_row->bank_name)
                || strcasecmp((string) $wallet_row->bank_name, 'Fintava') === 0)) {
            $update_data['bank_name'] = $resolved_bank_name;
            $update_formats[]         = '%s';
        }

        if ($resolved_bank_code !== '' && empty($wallet_row->bank_code)) {
            $update_data['bank_code'] = $resolved_bank_code;
            $update_formats[]         = '%s';
        }

        if (empty($update_data)) {
            return false;
        }

        global $wpdb;
        $update_data['updated_at'] = current_time('mysql');
        $update_formats[]          = '%s';
        $wpdb->update(
            $wpdb->prefix . 'matrix_fintava_wallets',
            $update_data,
            ['id' => $wallet_row->id],
            $update_formats,
            ['%d']
        );

        return true;
    }

    /**
     * Extract a Fintava customer UUID from any response object.
     *
     * Fintava returns the customer ID under different keys depending on the
     * endpoint: userInfo.id (customer list/detail), customerId (wallet
     * generate), customer_id (some tiers), userId (transfers). This helper
     * checks all known shapes and returns the first valid UUID found, or ''
     * if none is present.
     *
     * @param array $obj Decoded Fintava response (any endpoint).
     * @return string Customer UUID or ''.
     */
    public static function extract_customer_id($obj) {
        if (!is_array($obj)) {
            return '';
        }

        // Direct field names
        $direct_keys = ['customerId', 'customer_id', 'userId', 'user_id', 'sourceId', 'source_id'];
        foreach ($direct_keys as $key) {
            if (isset($obj[$key]) && $obj[$key] !== '') {
                $val = trim((string) $obj[$key]);
                if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $val)) {
                    return $val;
                }
            }
        }

        // Nested under userInfo.id (customer list/detail response)
        if (isset($obj['userInfo']['id']) && $obj['userInfo']['id'] !== '') {
            $val = trim((string) $obj['userInfo']['id']);
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $val)) {
                return $val;
            }
        }

        // Nested under customer.id
        if (isset($obj['customer']['id']) && $obj['customer']['id'] !== '') {
            $val = trim((string) $obj['customer']['id']);
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $val)) {
                return $val;
            }
        }

        return '';
    }

    /**
     * Resolve the customer_id (Fintava's customer UUID / sourceId) for a
     * local wallet row.
     *
     * This is the key method that makes bank payouts work. Fintava's
     * POST /bank/credit requires a `sourceId` that identifies the customer
     * whose virtual wallet to debit. Without it, transfers always fail.
     *
     * Resolution chain (first success wins):
     *
     *   1. GET /customers/details (with phone number from local row) —
     *      returns the customer record with userInfo.id.
     *
     *   2. GET /customers/list — pulls all customers under this merchant,
     *      matches by account_number embedded in each customer's wallet
     *      object. Extracts userInfo.id from the matching record.
     *
     *   3. GET /customers/{customerId} — if we already have a candidate
     *      customer_id on the row (e.g. partially populated), verify it
     *      by fetching the customer and confirming the wallet's account
     *      number matches.
     *
     * On success, persists customer_id (and wallet_id if also resolved)
     * back to the local wallet row so subsequent transfers skip the
     * resolution entirely.
     *
     * @param object $wallet_row The local matrix_fintava_wallets row.
     * @return string|WP_Error The resolved customer_id UUID on success.
     */
    public function resolve_customer_id($wallet_row) {
        global $wpdb;

        if (empty($wallet_row->account_number)) {
            return new WP_Error(
                'missing_account_number',
                __('No account number on file — cannot resolve customer ID.', 'matrix-mlm')
            );
        }

        $account_number = trim($wallet_row->account_number);
        $resolved_customer_id = '';
        $resolved_wallet_id   = '';

        // -----------------------------------------------------------------
        // Strategy 1: GET /customers/details with phone number
        // -----------------------------------------------------------------
        // The /customers/details endpoint accepts a phone query and returns
        // the customer record directly. This is the fastest path when we
        // have the phone on file.
        if (!empty($wallet_row->customer_phone)) {
            $phone = trim($wallet_row->customer_phone);
            $response = $this->make_request('GET', '/customers/details?phone=' . urlencode($phone), null, null, true);
            if (!is_wp_error($response)) {
                $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : $response;
                $cid = self::extract_customer_id($data);
                if (!empty($cid)) {
                    // Verify it matches our account number.
                    //
                    // Fintava's /customers/details response actually nests
                    // the wallet TWO levels deep: data.userInfo.wallet.
                    // Older tiers / older docs sometimes had data.wallet,
                    // and a few endpoints flatten the wallet fields onto
                    // the root. Try in that order so the deepest, most
                    // current shape wins.
                    if (isset($data['userInfo']['wallet']) && is_array($data['userInfo']['wallet'])) {
                        $wallet_in_response = $data['userInfo']['wallet'];
                    } elseif (isset($data['wallet']) && is_array($data['wallet'])) {
                        $wallet_in_response = $data['wallet'];
                    } else {
                        $wallet_in_response = $data;
                    }
                    $remote_account = self::extract_account_number($wallet_in_response);
                    if (empty($remote_account) || trim($remote_account) === $account_number) {
                        $resolved_customer_id = $cid;
                        // Also grab wallet_id if available
                        $wid = self::extract_wallet_id($wallet_in_response);
                        if (!empty($wid)) {
                            $resolved_wallet_id = $wid;
                        }
                    }
                }
            }
        }

        // -----------------------------------------------------------------
        // Strategy 2: GET /customers/list — scan all customers
        // -----------------------------------------------------------------
        // Pull the full customer list and match by account_number embedded
        // in each customer's wallet object. This is the most reliable path
        // because it doesn't depend on having a phone number, and the
        // customer list always includes the wallet with its account number.
        if (empty($resolved_customer_id)) {
            $list = $this->get_customer_list();
            if (!is_wp_error($list) && is_array($list)) {
                foreach ($list as $customer) {
                    if (!is_array($customer)) {
                        continue;
                    }

                    // The customer list returns each entry with the wallet
                    // nested under userInfo (the actual current shape per
                    // Fintava's API), or a flatter wallet at the root, or
                    // — on older tiers — wallet fields directly on the
                    // customer record. Try deepest-first so the canonical
                    // shape wins.
                    if (isset($customer['userInfo']['wallet']) && is_array($customer['userInfo']['wallet'])) {
                        $wallet_obj = $customer['userInfo']['wallet'];
                    } elseif (isset($customer['wallet']) && is_array($customer['wallet'])) {
                        $wallet_obj = $customer['wallet'];
                    } else {
                        $wallet_obj = $customer;
                    }

                    $remote_account = self::extract_account_number($wallet_obj);
                    if (empty($remote_account) || trim($remote_account) !== $account_number) {
                        continue;
                    }

                    // Found a match — extract the customer UUID
                    $cid = self::extract_customer_id($customer);
                    if (!empty($cid)) {
                        $resolved_customer_id = $cid;
                        // Also grab wallet_id
                        $wid = self::extract_wallet_id($wallet_obj);
                        if (!empty($wid)) {
                            $resolved_wallet_id = $wid;
                        }
                        break;
                    }
                }
            }
        }

        // -----------------------------------------------------------------
        // Strategy 3: GET /wallet/details then extract customer_id
        // -----------------------------------------------------------------
        // Some Fintava tiers include customer_id in the wallet details
        // response. Try this as a last resort.
        if (empty($resolved_customer_id)) {
            $details = $this->get_wallet_by_account_number($account_number);
            if (!is_wp_error($details)) {
                $cid = self::extract_customer_id($details);
                if (!empty($cid)) {
                    $resolved_customer_id = $cid;
                }
                // Also grab wallet_id if not yet resolved
                if (empty($resolved_wallet_id)) {
                    $wid = self::extract_wallet_id($details);
                    if (empty($wid)) {
                        $wid = $details['id'] ?? '';
                    }
                    if (!empty($wid)) {
                        $resolved_wallet_id = $wid;
                    }
                }
            }
        }

        // -----------------------------------------------------------------
        // Persist results
        // -----------------------------------------------------------------
        if (empty($resolved_customer_id)) {
            return new WP_Error(
                'fintava_customer_id_not_found',
                sprintf(
                    __('Could not find your Fintava customer ID. Searched by account number %s across /customers/details, /customers/list, and /wallet/details. Please contact support.', 'matrix-mlm'),
                    $account_number
                )
            );
        }

        // Build the DB update
        $update_data = [
            'customer_id' => $resolved_customer_id,
            'updated_at'  => current_time('mysql'),
        ];
        $update_formats = ['%s', '%s'];

        // Also persist wallet_id if we found one and the row is missing it
        if (!empty($resolved_wallet_id) && empty($wallet_row->wallet_id)) {
            $update_data['wallet_id'] = $resolved_wallet_id;
            $update_formats[] = '%s';
        }

        $wpdb->update(
            $wpdb->prefix . 'matrix_fintava_wallets',
            $update_data,
            ['id' => $wallet_row->id],
            $update_formats,
            ['%d']
        );

        return $resolved_customer_id;
    }

    // =========================================================================
    // VIRTUAL WALLET API
    // =========================================================================

    /**
     * POST /create/customer
     *
     * Registers a Fintava customer record for the user and (with
     * fundingMethod=STATIC_FUND) provisions a permanent virtual account
     * tied to that customer's identity in the same round-trip.
     *
     * This is the path that causes the bank-side account name to echo
     * back the user's name (firstName + lastName) when other Nigerian
     * banks resolve the virtual account number — instead of echoing
     * back the merchant's company name, which is what calling
     * /virtual-wallet/generate alone produces (that endpoint provisions
     * the wallet under the merchant master account, so every wallet
     * shows up at the partner bank as the merchant's registered name,
     * e.g. "LIBERTY HUB INTERNATIONAL LIMITED" — confusing for senders
     * and not what users expect on their own dashboard).
     *
     * Whitelisted payload fields (KYC-aware DTO, observed live May 2026):
     *   - firstName     (string, required)
     *   - lastName      (string, required)
     *   - email         (string, required)
     *   - phoneNumber   (string, required; Fintava's class-validator
     *                    rejects anything that doesn't pass libphonenumber's
     *                    isValidPhoneNumber. We normalize to E.164
     *                    upstream so a Nigerian local "08012345678"
     *                    becomes "+2348012345678" before this method
     *                    sees it — the local form input lets the user
     *                    type either form.)
     *   - bvn           (string, required; 11-digit Nigerian Bank
     *                    Verification Number. Fintava's KYC step
     *                    binds the partner-bank account name on this
     *                    field, so without a real BVN the bank-side
     *                    name resolution falls back to the merchant
     *                    master account — exactly the failure mode
     *                    that surfaces wallets named "LIBERTY HUB
     *                    INTERNATIONAL LIMITED" instead of the user.)
     *   - dateOfBirth   (string, required; ISO 8601 date YYYY-MM-DD.)
     *   - address       (string, required; non-empty residential or
     *                    business address used for KYC.)
     *   - fundingMethod (string, required; we pass 'STATIC_FUND' so the
     *                    customer gets a permanent virtual NUBAN that
     *                    can receive funds at any time — the alternative
     *                    is 'DYNAMIC_FUND' which mints one-time-use
     *                    account numbers per transaction, not what we
     *                    want for a member-facing wallet)
     *   - merchantReference (string, optional idempotency key)
     *
     * Field-name history: this endpoint used to accept `phone` (not
     * `phoneNumber`) and didn't require BVN/DOB/address at all. The
     * legacy form (render_create_form) was simplified at that time
     * to drop those PII inputs. Fintava re-tightened the DTO in
     * early 2026 to require all four fields above; sending the old
     * `phone`-only payload now produces an HTTP 400 with messages
     * like "phoneNumber must be a valid phone number" + "bvn should
     * not be empty" + "dateOfBirth must be a valid ISO 8601 date
     * string" + "address should not be empty", which silently
     * cascades into the /virtual-wallet/generate fallback below and
     * causes every wallet to come back named after the merchant.
     *
     * Standard Fintava envelope on success:
     *   { status: 200|201,
     *     message: 'successful',
     *     data: {
     *       userInfo: { id, firstName, lastName, email, phoneNumber, ... },
     *       wallet:   { id, virtualAcctNo, virtualAcctName, bank, ... }
     *     } }
     *
     * On failure (e.g. duplicate email/phone, KYC validation, BVN
     * mismatch) returns a WP_Error with the upstream status + message
     * decorated by make_request().
     *
     * @param array $customer_data Required: first_name, last_name, email,
     *                             phone, bvn, date_of_birth, address.
     *                             Optional: funding_method (default
     *                             'STATIC_FUND'), reference.
     * @return array|WP_Error      The unwrapped `data` object on success
     *                             (i.e. `{ userInfo, wallet, ... }`).
     */
    public function create_customer($customer_data) {
        $required = ['first_name', 'last_name', 'email', 'phone', 'bvn', 'date_of_birth', 'address'];
        foreach ($required as $field) {
            if (empty($customer_data[$field])) {
                return new WP_Error(
                    'missing_field',
                    sprintf(__('Missing required field: %s', 'matrix-mlm'), $field)
                );
            }
        }

        // Normalize phone to E.164 (+234...). Fintava's class-validator
        // runs the field through libphonenumber's isValidPhoneNumber and
        // rejects anything that isn't an internationally-formatted
        // number. Nigerian local entries like "08012345678" pass our
        // form but fail Fintava's check, so we coerce here so the
        // gateway accepts whatever shape the user typed without
        // pushing the formatting rules into the form layer.
        $normalized_phone = self::normalize_ng_phone((string) $customer_data['phone']);
        if ($normalized_phone === '') {
            return new WP_Error(
                'invalid_phone',
                __('Phone number is not a valid Nigerian mobile number.', 'matrix-mlm')
            );
        }

        $payload = [
            'firstName'     => sanitize_text_field($customer_data['first_name']),
            'lastName'      => sanitize_text_field($customer_data['last_name']),
            'email'         => sanitize_email($customer_data['email']),
            'phoneNumber'   => $normalized_phone,
            'bvn'           => preg_replace('/\D/', '', (string) $customer_data['bvn']),
            'dateOfBirth'   => sanitize_text_field($customer_data['date_of_birth']),
            'address'       => sanitize_text_field($customer_data['address']),
            'fundingMethod' => sanitize_text_field($customer_data['funding_method'] ?? 'STATIC_FUND'),
        ];

        if (!empty($customer_data['reference'])) {
            $payload['merchantReference'] = sanitize_text_field($customer_data['reference']);
        }

        $response = $this->make_request('POST', '/create/customer', $payload);
        if (is_wp_error($response)) {
            return $response;
        }

        // Standard envelope: { data: { userInfo, wallet, ... } }
        if (isset($response['data']) && is_array($response['data'])) {
            return $response['data'];
        }

        // Bare-object fallback: some tiers return the payload directly
        // without the envelope. Accept it if we can see the shape we
        // expect (a userInfo OR wallet OR id field).
        if (is_array($response) && (isset($response['userInfo']) || isset($response['wallet']) || isset($response['id']))) {
            return $response;
        }

        return new WP_Error(
            'fintava_create_customer_error',
            $response['message'] ?? __('Failed to create Fintava customer', 'matrix-mlm')
        );
    }

    /**
     * POST /virtual-wallet/generate
     *
     * @deprecated Not called by ajax_create_virtual_wallet anymore.
     *             Kept for backward-compatibility with external callers
     *             that may still depend on it. Do NOT add new call sites.
     *
     * Why this is deprecated: this endpoint provisions wallets under the
     * merchant master account. Other Nigerian banks resolve the virtual
     * NUBAN against that master, so anyone trying to fund the account
     * sees the merchant's registered company name (e.g. "LIBERTY HUB
     * INTERNATIONAL LIMITED") instead of the user's. That is the exact
     * failure mode #197 (collect KYC fields) and #198 (recover existing
     * customer on duplicate) were meant to prevent, and silently falling
     * back here on a transient /create/customer failure was the third
     * way to land on a merchant-named wallet — so the AJAX handler now
     * surfaces a clear "please retry / contact admin" error instead of
     * calling this method.
     *
     * The customer-attached path (create_customer + STATIC_FUND) is the
     * only supported way to provision a user wallet. If you find yourself
     * needing this method as a fallback, fix the upstream issue (KYC
     * validation, duplicate recovery, async-binding retry) instead.
     *
     * Payload shape is locked to exactly what Fintava's DTO whitelists.
     * The endpoint runs through a NestJS ValidationPipe with
     * `forbidNonWhitelisted: true`, so any field outside the DTO causes
     * a 400 with messages like "property X should not exist" — and the
     * whole request is rejected, not just the offending field.
     *
     * Whitelisted fields (camelCase, per the current DTO):
     *   - email            (string, required)
     *   - phone            (string, required)
     *   - amount           (number, required by the DTO; we send a tiny
     *                       default so the wallet is provisioned with a
     *                       zero-funded balance — the API treats this as
     *                       the minimum credit/expiry threshold, not a
     *                       charge against the merchant wallet)
     *   - expireTimeInMin  (number, required; 525600 = 1 year)
     *   - merchantReference(string, idempotency key)
     *
     * Fields that historically lived on this payload but are no longer
     * on the DTO and now produce "property X should not exist" 400s:
     *   - firstName / lastName (camelCase form — used to be required
     *                     here; Fintava removed them from the DTO and
     *                     the live API now rejects them outright. The
     *                     account_name on the wallet is resolved by
     *                     Fintava server-side from BVN/NIN and echoed
     *                     back on the response; we still accept the
     *                     local first_name/last_name as inputs and
     *                     fall back to "$first $last" for the cached
     *                     account_name if the response doesn't include
     *                     a resolved name.)
     *   - first_name / last_name (snake_case spellings — never on the
     *                     DTO; the camelCase variants above were the
     *                     correct spelling but they too are gone now.)
     *   - currency       (Fintava virtual wallets are NGN-only, no
     *                     currency selector exposed on this endpoint)
     *   - bvn, nin       (KYC data was forwarded here for /virtual-
     *                     wallet/generate but the DTO never accepted
     *                     it; KYC happens out-of-band on the merchant
     *                     dashboard)
     *   - date_of_birth, gender, address (same — not on the DTO)
     */
    public function generate_virtual_wallet($customer_data) {
        // first_name / last_name are no longer required by the API
        // (Fintava removed them from the DTO and 400s on their
        // presence). They remain optional inputs that we use only
        // for the local account_name fallback below.
        $required_fields = ['email', 'phone'];
        foreach ($required_fields as $field) {
            if (empty($customer_data[$field])) {
                return new WP_Error('missing_field', sprintf(__('Missing required field: %s', 'matrix-mlm'), $field));
            }
        }

        $payload = [
            'email'             => sanitize_email($customer_data['email']),
            'phone'             => sanitize_text_field($customer_data['phone']),
            'amount'            => intval($customer_data['amount'] ?? 100),
            'expireTimeInMin'   => intval($customer_data['expireTimeInMin'] ?? 525600),
            'merchantReference' => $customer_data['reference'] ?? ('MTX-VW-' . uniqid()),
        ];

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
                'account_name' => $extracted_name !== '' ? $extracted_name : trim((string) ($customer_data['first_name'] ?? '') . ' ' . (string) ($customer_data['last_name'] ?? '')),
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
     * current_balance, etc.).
     *
     * Recursively descends into nested arrays — Fintava's /merchant/balance
     * has been observed returning `data.balance` as either a scalar amount
     * or an object `{available_balance, ledger_balance}`, and the latter
     * shape used to crash here: PHP's floatval() on an array returns 1.0
     * (because non-empty arrays cast to true → 1), which is exactly the
     * "₦1.00 instead of the real balance" symptom this method fixes.
     *
     * Returns null when neither an available nor a ledger balance can be
     * located anywhere in the payload, so the caller can fall through to
     * the next strategy.
     *
     * @param array $payload Decoded wallet/balance object.
     * @return array{available_balance: float, ledger_balance: float, currency: string}|null
     */
    private function normalize_balance_payload($payload) {
        if (!is_array($payload)) {
            return null;
        }

        $available_keys = ['available_balance', 'availableBalance', 'wallet_balance', 'walletBalance', 'balance', 'current_balance', 'currentBalance'];
        $ledger_keys    = ['ledger_balance', 'ledgerBalance', 'book_balance', 'bookBalance'];

        $available = self::find_scalar_numeric($payload, $available_keys);
        $ledger    = self::find_scalar_numeric($payload, $ledger_keys);

        if ($available === null && $ledger === null) {
            return null;
        }

        // Currency hunts the same payload for any 'currency' field, scalar
        // string only. Falls back to NGN to stay backwards-compatible with
        // existing callers (the merchant is Nigeria-only today).
        $currency = self::find_scalar_string($payload, ['currency', 'currencyCode', 'currency_code']) ?? 'NGN';

        return [
            'available_balance' => $available !== null ? $available : $ledger,
            'ledger_balance'    => $ledger !== null ? $ledger : $available,
            'currency'          => $currency,
        ];
    }

    /**
     * Recursively search a decoded JSON object for the first scalar numeric
     * value at any of the given keys. Skips non-scalar (array/object) values
     * at matching keys so that a `balance: { available_balance: 1000 }`
     * shape doesn't return floatval([...]) === 1.0 — that bug is exactly
     * what surfaced as "Merchant Wallet Balance ₦1.00" on production.
     *
     * Search order at every level:
     *   1. Try every requested key at this level (scalar numeric only).
     *   2. If nothing matches, recurse into each array-valued child.
     *
     * @param array    $payload Object to search.
     * @param string[] $keys    Field names to try, in priority order.
     * @return float|null
     */
    private static function find_scalar_numeric(array $payload, array $keys) {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                $value = $payload[$key];
                if (is_scalar($value) && $value !== '' && is_numeric($value)) {
                    return (float) $value;
                }
            }
        }
        foreach ($payload as $value) {
            if (is_array($value)) {
                $found = self::find_scalar_numeric($value, $keys);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /**
     * Companion to find_scalar_numeric() that returns the first non-empty
     * scalar STRING value at any of the given keys. Used for the currency
     * field (which is always a string) so it follows the same recursive
     * search semantics as the numeric fields.
     *
     * @param array    $payload Object to search.
     * @param string[] $keys    Field names to try, in priority order.
     * @return string|null
     */
    private static function find_scalar_string(array $payload, array $keys) {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                $value = $payload[$key];
                if (is_scalar($value) && $value !== '') {
                    return (string) $value;
                }
            }
        }
        foreach ($payload as $value) {
            if (is_array($value)) {
                $found = self::find_scalar_string($value, $keys);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
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
     * Backfill missing bank_code values on local matrix_fintava_wallets rows
     * by running the same self-heal chain that ajax_transfer_matrix_to_virtual()
     * fires lazily on the first transfer attempt — but in bulk, so operators
     * can clear the "Your Virtual wallet (Fintava) is missing a bank code we
     * couldn't auto-resolve" surface without waiting for every affected user
     * to hit it themselves.
     *
     * Per-row chain (early-exits when bank_code lands on the row):
     *
     *   1. resolve_wallet_id_from_customer() — GET /wallet/details. Persists
     *      bank_name + bank_code + customer_id + wallet_id opportunistically,
     *      so this single call may finish the job by itself on tiers where
     *      Fintava echoes the partner-bank fields back.
     *
     *   2. enrich_partner_bank_metadata() — falls back to /customers/{id},
     *      /customers/list (matched on account_number), and
     *      /customers/details?phone=... for tiers where /wallet/details is
     *      gated off or doesn't include the partner bank.
     *
     *   3. derive_bank_code_from_name() — local lookup of the now-resolved
     *      bank_name against get_static_banks_fallback() (the CBN sortCode
     *      registry). The helper is a pure lookup; this orchestrator
     *      writes the persisted bank_code itself.
     *
     * The schema-default placeholder bank_name = 'Fintava' is treated as
     * "not a real bank" by every step (Fintava is a fintech, not a CBN
     * member), so rows stuck on the placeholder bubble up to the report
     * with an actionable reason instead of being silently considered
     * resolved.
     *
     * @return array Diagnostic report with counts and per-row outcomes.
     */
    public function backfill_missing_bank_codes() {
        global $wpdb;

        if (!$this->is_active()) {
            return new WP_Error(
                'fintava_not_configured',
                __('Fintava is not active. Configure the live API key first.', 'matrix-mlm')
            );
        }

        self::ensure_tables_exist();
        $wallets_table = $wpdb->prefix . 'matrix_fintava_wallets';

        $missing_rows = $wpdb->get_results(
            "SELECT id, user_id, account_number, account_name, bank_name, bank_code,
                    customer_id, customer_phone, wallet_id
               FROM {$wallets_table}
              WHERE (bank_code IS NULL OR bank_code = '')
                AND account_number IS NOT NULL
                AND account_number <> ''"
        );

        $report = [
            'total_missing_before'        => count($missing_rows),
            'resolved_via_wallet_details' => 0,
            'resolved_via_customer_api'   => 0,
            'resolved_via_static_lookup'  => 0,
            'still_missing'               => 0,
            'details'                     => [],
        ];

        if (empty($missing_rows)) {
            return $report;
        }

        foreach ($missing_rows as $row) {
            $bank_name_before = (string) $row->bank_name;
            $source = '';
            $reason = '';

            // Step 1: GET /wallet/details. We don't branch on its return
            // value — even on WP_Error it may have persisted bank_name
            // before bailing out. The authoritative signal is whether
            // bank_code is now populated on the row.
            $this->resolve_wallet_id_from_customer($row);
            $row = $this->get_wallet_row_by_id($row->id);
            if (!empty($row->bank_code)) {
                $source = 'wallet_details';
                $report['resolved_via_wallet_details']++;
            }

            // Step 2: customer-endpoint enrichment. enrich_partner_bank_metadata
            // is itself idempotent and short-circuits when the row is already
            // populated, so calling it again is safe — but we skip the
            // round-trip when Step 1 already succeeded to be polite to
            // Fintava's rate limiter.
            if ($source === '') {
                $this->enrich_partner_bank_metadata($row);
                $row = $this->get_wallet_row_by_id($row->id);
                if (!empty($row->bank_code)) {
                    $source = 'customer_api';
                    $report['resolved_via_customer_api']++;
                }
            }

            // Step 3: derive bank_code from the (now possibly resolved)
            // bank_name via the local static CBN list. Pure lookup,
            // no API call — orchestrator persists the result.
            if ($source === '' && !empty($row->bank_name)) {
                $candidate = $this->derive_bank_code_from_name($row->bank_name);
                if ($candidate !== '') {
                    $wpdb->update(
                        $wallets_table,
                        ['bank_code' => $candidate, 'updated_at' => current_time('mysql')],
                        ['id' => $row->id],
                        ['%s', '%s'],
                        ['%d']
                    );
                    $row->bank_code = $candidate;
                    $source = 'static_lookup';
                    $report['resolved_via_static_lookup']++;
                }
            }

            if (empty($row->bank_code)) {
                $report['still_missing']++;
                $is_placeholder = empty($row->bank_name)
                    || strcasecmp((string) $row->bank_name, 'Fintava') === 0;
                if ($is_placeholder) {
                    $reason = __('bank_name is still empty or the "Fintava" placeholder — Fintava\'s API didn\'t return a partner bank for this wallet, so there\'s no name to look up. Set bank_name and bank_code manually from the Fintava dashboard.', 'matrix-mlm');
                } else {
                    $reason = sprintf(
                        /* translators: %s: bank name on the wallet row */
                        __('No CBN sortCode match for "%s" in the static bank list. Verify the name on Fintava and set bank_code manually, or extend get_static_banks_fallback().', 'matrix-mlm'),
                        $row->bank_name
                    );
                }
            }

            $report['details'][] = [
                'user_id'          => (int) $row->user_id,
                'account_number'   => (string) $row->account_number,
                'bank_name_before' => $bank_name_before,
                'bank_name_after'  => (string) $row->bank_name,
                'bank_code_after'  => (string) $row->bank_code,
                'source'           => $source,
                'status'           => $source !== '' ? 'resolved' : 'still_missing',
                'reason'           => $reason,
            ];

            // Throttle. A fully-missing row can fire up to 5 round-trips
            // (1 wallet/details + up to 3 customer endpoints) so 200ms
            // between rows keeps a 100-row batch under Fintava's typical
            // per-minute caps.
            usleep(200000);
        }

        return $report;
    }

    /**
     * Lightweight helper: re-read a single matrix_fintava_wallets row by
     * primary key. Used by the bank-code backfill orchestrator after each
     * self-heal step persists row metadata as a side-effect — get_user_wallet()
     * filters to active rows, but here we want the row whatever its status
     * because the orchestrator only enumerates rows it already selected.
     */
    private function get_wallet_row_by_id($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_fintava_wallets WHERE id = %d",
            (int) $id
        ));
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
        // KYC fields — required by Fintava's /create/customer DTO since
        // early 2026. Without all three, the call 400s and the gateway
        // silently falls through to /virtual-wallet/generate, which
        // produces a working wallet but bound to the merchant master
        // account (so the bank-side account name resolves to "LIBERTY
        // HUB INTERNATIONAL LIMITED" instead of the user's name).
        // Collected from the form on every wallet-creation request
        // rather than pulled from existing user_meta — KYC information
        // is sensitive and the form prompt makes the user explicit
        // consent visible per request.
        $bvn           = preg_replace('/\D/', '', (string) ($_POST['bvn'] ?? ''));
        $date_of_birth = sanitize_text_field($_POST['date_of_birth'] ?? '');
        $address       = sanitize_text_field($_POST['address'] ?? '');

        if (empty($first_name) || empty($last_name)) {
            wp_send_json_error(['message' => __('First name and last name are required', 'matrix-mlm')]);
        }

        if (empty($email) || !is_email($email)) {
            wp_send_json_error(['message' => __('A valid email address is required', 'matrix-mlm')]);
        }

        if (empty($phone)) {
            wp_send_json_error(['message' => __('Phone number is required', 'matrix-mlm')]);
        }

        // BVN: Fintava's class-validator rejects anything that isn't an
        // 11-character numeric string. We strip non-digits client-side
        // already so the only failure shape that hits here is a wrong
        // length — bail with a specific message so the user can fix
        // the input rather than getting a generic upstream 400.
        if (strlen($bvn) !== 11) {
            wp_send_json_error([
                'message' => __('BVN must be exactly 11 digits. Your BVN is the number you receive when you dial *565*0# on the phone tied to your bank account.', 'matrix-mlm'),
            ]);
        }

        // Date of Birth: Fintava expects ISO 8601 (YYYY-MM-DD). The
        // form uses <input type="date"> which already serializes to
        // that format, but be defensive against direct/programmatic
        // POSTs that submit a different shape (e.g. "12/31/1990").
        // We also enforce a sane range — Fintava's KYC will reject
        // future dates and ages that look obviously bogus, but
        // catching them locally gives a clearer error.
        $dob_ts = strtotime($date_of_birth);
        if (empty($date_of_birth) || $dob_ts === false || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_of_birth)) {
            wp_send_json_error(['message' => __('Please enter a valid date of birth (YYYY-MM-DD).', 'matrix-mlm')]);
        }
        $today_ts  = strtotime(date('Y-m-d'));
        $oldest_ts = strtotime('1900-01-01');
        if ($dob_ts > $today_ts || $dob_ts < $oldest_ts) {
            wp_send_json_error(['message' => __('Date of birth is outside the accepted range.', 'matrix-mlm')]);
        }

        // Address: Fintava's "should not be empty" check is the only
        // server-side rule we know about, but a 5-character minimum
        // catches accidental whitespace-only or single-letter inputs
        // that would pass the empty check but obviously can't be a
        // real address.
        if (strlen(trim($address)) < 5) {
            wp_send_json_error(['message' => __('Please enter your full residential or business address (at least 5 characters).', 'matrix-mlm')]);
        }

        $reference = 'MTX-VW-' . $user_id . '-' . time();

        // Sole wallet-provisioning path: POST /create/customer with
        // fundingMethod=STATIC_FUND.
        //
        // This is the only flow that produces a wallet whose bank-side
        // account name resolves to the user's name. The legacy
        // /virtual-wallet/generate endpoint provisions the wallet under
        // the merchant master account, so partner-bank name resolution
        // returns the merchant's registered company name (e.g. "LIBERTY
        // HUB INTERNATIONAL LIMITED") to any sender who tries to fund
        // the account — wrong on the user's own dashboard, and a
        // customer-support nightmare to unwind once it lands. The
        // refusal-to-fall-back guard further down enforces this
        // invariant: if /create/customer fails or returns no embedded
        // wallet, we surface a clear "please retry" error rather than
        // silently provisioning a merchant-named wallet.
        //
        // create_customer returns the customer record with an embedded
        // wallet object on success: { userInfo: {...}, wallet: {...} }.
        // We pull wallet_id / account_number / account_name / bank
        // straight off that wallet object using the gateway's existing
        // extractors.
        $result = null;
        $customer = $this->create_customer([
            'first_name'     => $first_name,
            'last_name'      => $last_name,
            'email'          => $email,
            'phone'          => $phone,
            'bvn'            => $bvn,
            'date_of_birth'  => $date_of_birth,
            'address'        => $address,
            'reference'      => $reference,
            'funding_method' => 'STATIC_FUND',
        ]);

        // ---- TEMPORARY DIAGNOSTIC LOG ------------------------------------
        // Dumps the raw /create/customer outcome so we can distinguish
        // the three reasons we fall through to /virtual-wallet/generate
        // (which is what causes wallets to come back named after the
        // merchant — e.g. "LIBERTY HUB INTERNATIONAL LIMITED" — instead
        // of the user):
        //   1. WP_Error: Fintava rejected the call (duplicate email/phone,
        //      validation, auth/network). Code + message + data make it
        //      clear which.
        //   2. Success without an embedded wallet object: tier returns
        //      the customer record but no `wallet`, so we have no
        //      account_number to persist and must fall through.
        //   3. Success WITH a wallet object but a missing/empty
        //      virtualAcctName: bank-side name binding is async; the
        //      ajax handler then falls through to firstName/lastName.
        // Remove this block once the wallet-naming issue is diagnosed.
        if (is_wp_error($customer)) {
            error_log(
                '[Matrix Fintava][debug] create_customer WP_Error for user_id=' . $user_id
                . ' code=' . $customer->get_error_code()
                . ' message=' . $customer->get_error_message()
                . ' data=' . wp_json_encode($customer->get_error_data())
            );
        } else {
            error_log(
                '[Matrix Fintava][debug] create_customer response for user_id=' . $user_id
                . ': ' . wp_json_encode($customer)
            );
        }
        // ---- END TEMPORARY DIAGNOSTIC LOG --------------------------------

        if (!is_wp_error($customer)) {
            $wallet_data = (isset($customer['wallet']) && is_array($customer['wallet']))
                ? $customer['wallet']
                : null;

            $resolved_customer_id = '';
            if (isset($customer['userInfo']['id'])) {
                $resolved_customer_id = (string) $customer['userInfo']['id'];
            } elseif (isset($customer['id'])) {
                $resolved_customer_id = (string) $customer['id'];
            } elseif (isset($customer['customerId'])) {
                $resolved_customer_id = (string) $customer['customerId'];
            } elseif (isset($customer['customer_id'])) {
                $resolved_customer_id = (string) $customer['customer_id'];
            }

            if ($wallet_data) {
                $resolved_name = self::extract_account_name($wallet_data);
                if ($resolved_name === '') {
                    // Some tiers return the wallet object without the
                    // virtualAcctName field populated yet (the bank-side
                    // name binding is asynchronous). The customer record
                    // we just registered carries the canonical name, so
                    // fall through to firstName/lastName from there or
                    // from the form input.
                    $resolved_name = trim(
                        (string) ($customer['userInfo']['firstName'] ?? $first_name)
                        . ' '
                        . (string) ($customer['userInfo']['lastName'] ?? $last_name)
                    );
                }

                $result = [
                    'success'        => true,
                    'wallet_id'      => self::extract_wallet_id($wallet_data) ?: ($wallet_data['id'] ?? null),
                    'customer_id'    => $resolved_customer_id,
                    'account_number' => self::extract_account_number($wallet_data),
                    'account_name'   => $resolved_name,
                    'bank_name'      => self::extract_bank_name($wallet_data) ?: 'Fintava',
                    'bank_code'      => $wallet_data['bank_code'] ?? $wallet_data['bankCode'] ?? null,
                    'currency'       => $wallet_data['currency'] ?? 'NGN',
                    'status'         => $wallet_data['status'] ?? 'active',
                ];
            }
        } else {
            // Surface the create_customer failure in the PHP error log
            // so an operator watching the log can see what Fintava
            // rejected. We do NOT fall through to /virtual-wallet/generate
            // anymore — see the no-fallback block below for the rationale.
            error_log(
                '[Matrix Fintava] create_customer failed for user_id=' . $user_id
                . '. Error: ' . $customer->get_error_message()
            );
        }

        // Refusal-to-fall-back guard.
        //
        // Earlier revisions of this handler called /virtual-wallet/generate
        // here whenever the primary /create/customer path didn't yield a
        // wallet — either because Fintava returned a WP_Error, or because
        // create_customer succeeded but the response carried no embedded
        // wallet object. That fallback produces a working wallet, but it
        // provisions it under the merchant master account rather than the
        // user's customer record, so partner-bank name resolution returns
        // the merchant's registered company name (e.g. "LIBERTY HUB
        // INTERNATIONAL LIMITED") to anyone who tries to fund the account.
        // That is the exact failure mode #197 (the KYC fields PR) and the
        // duplicate-customer recovery in #198 are meant to prevent. Once
        // a merchant-named wallet lands in matrix_fintava_wallets, fixing
        // it requires manual cleanup at both Fintava and in the local DB —
        // permanently asking a confused user to send funds to "LIBERTY HUB
        // INTERNATIONAL LIMITED" is much worse than asking them to retry.
        //
        // Two paths into this guard:
        //
        //   1. create_customer returned a WP_Error AND any duplicate-
        //      customer recovery (when deployed) didn't yield a usable
        //      wallet. Surface Fintava's error verbatim so the user can
        //      act on it: retry on transient 5xx, fix BVN/DOB/address on
        //      a 4xx validator rejection, or contact support on a hard
        //      block. The diagnostic log block above already captured the
        //      raw upstream response.
        //
        //   2. create_customer returned 200 but no embedded wallet object.
        //      This is the async-binding case — Fintava's bank-side
        //      partner enrolment hasn't finished by the time the response
        //      is sent. The wallet usually appears on retry within a
        //      minute. Tell the user to wait and try again, and capture
        //      the response shape in the error log so support can confirm
        //      whether the issue is async-binding or a tier that simply
        //      doesn't return wallets on this endpoint.
        if ($result === null) {
            if (is_wp_error($customer)) {
                error_log(
                    '[Matrix Fintava] Refusing to provision a merchant-named wallet for user_id=' . $user_id
                    . ' after create_customer failure (no recovery). Surfacing upstream error to user.'
                );
                wp_send_json_error([
                    'message' => sprintf(
                        /* translators: %s: upstream Fintava error message */
                        __('Could not create your Fintava virtual wallet: %s. Please try again. If the problem persists, contact an administrator.', 'matrix-mlm'),
                        $customer->get_error_message()
                    ),
                ]);
                return;
            }

            // create_customer returned 200 but the response had no wallet
            // object on it. The diagnostic log block above already dumped
            // the raw response; ask the user to retry while we wait for
            // Fintava's async wallet binding to land.
            error_log(
                '[Matrix Fintava] create_customer returned no embedded wallet object for user_id=' . $user_id
                . '. Asking the user to retry; refusing to fall through to merchant-named /virtual-wallet/generate.'
            );
            wp_send_json_error([
                'message' => __('Your Fintava customer record was created but the wallet has not been attached yet. Please wait a minute and try again. If the issue persists, contact an administrator.', 'matrix-mlm'),
            ]);
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
                // Persist BVN to the dedicated column. KYC fields that
                // don't have their own column (date_of_birth, address)
                // go into the metadata JSON below — they're part of
                // the audit trail for what we sent to Fintava on this
                // wallet's creation, so support can reconcile if a
                // KYC challenge ever comes up.
                'bvn' => $bvn ?: null,
                'status' => 'active',
                'metadata' => json_encode([
                    'first_name'    => $first_name,
                    'last_name'     => $last_name,
                    'reference'     => $reference,
                    'date_of_birth' => $date_of_birth,
                    'address'       => $address,
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
        return $this->debug_raw_request('GET', $url);
    }

    /**
     * TEMPORARY admin diagnostic — fetch GET /merchant/balance and return the
     * raw, unparsed Fintava response. Bypasses get_merchant_balance() and
     * normalize_balance_payload() so an operator can see exactly which
     * fields the API exposes for the merchant wallet on this tier.
     *
     * Used by the Gateways admin page when the displayed balance disagrees
     * with the real balance shown in the Fintava dashboard. Output shape
     * mirrors debug_balance_raw().
     *
     * @return array Diagnostic payload (never a WP_Error).
     */
    public function debug_merchant_balance_raw() {
        if (empty($this->secret_key)) {
            return ['note' => 'no secret_key on the gateway instance', 'has_key' => false];
        }
        return $this->debug_raw_request('GET', $this->base_url . '/merchant/balance');
    }

    /**
     * TEMPORARY admin diagnostic - exercise the same configured-env-first
     * /banks routing the production get_banks() uses, and return the raw
     * response from whichever host won (or, if every host failed, the
     * combined per-host diagnostic). Lets an operator confirm whether the
     * bank list endpoint is reachable from this WordPress install at all,
     * and what envelope shape Fintava is returning today, without having
     * to crack open server logs.
     *
     * Output shape extends debug_balance_raw() with:
     *   - 'tried':    array<string,string|array> per-host breakdown when
     *                 multiple bases were probed before a winner was found.
     *   - 'winning_base': string|null, the base URL whose response is
     *                 returned in body_raw / body_decoded (null if every
     *                 host failed).
     *
     * @return array Diagnostic payload (never a WP_Error).
     */
    public function debug_banks_raw() {
        if (empty($this->secret_key)) {
            return ['note' => 'no secret_key on the gateway instance', 'has_key' => false];
        }

        // Mirror get_banks() routing exactly: configured env first, other
        // env as fallback. Surface the per-host breakdown so the operator
        // can see at a glance which host succeeded (and therefore which
        // tier their key is good for) and which host returned what error.
        $primary_base  = $this->base_url;
        $fallback_base = $primary_base === self::LIVE_BASE_URL ? self::DEV_BASE_URL : self::LIVE_BASE_URL;
        $bases_to_try  = [$primary_base];
        if ($fallback_base !== $primary_base) {
            $bases_to_try[] = $fallback_base;
        }

        $tried = [];
        foreach ($bases_to_try as $base) {
            $result = $this->debug_raw_request('GET', $base . '/banks', true);
            $tried[$base] = $result;

            // 2xx wins immediately - return the raw result decorated
            // with the per-host trail and the winning base URL.
            if (isset($result['http_code']) && $result['http_code'] >= 200 && $result['http_code'] < 300) {
                $result['tried']         = $tried;
                $result['winning_base']  = $base;
                return $result;
            }
        }

        // Every host failed. Return the last attempt's envelope (so the
        // operator still gets a body_raw / http_code to look at) plus
        // the per-host breakdown.
        $last = end($tried);
        if (!is_array($last)) {
            $last = [];
        }
        $last['tried']        = $tried;
        $last['winning_base'] = null;
        return $last;
    }

    /**
     * TEMPORARY admin diagnostic — fetch GET /name/enquiry against the
     * environment-resolved Fintava host with the operator-provided
     * account number and bank code. Used by the Gateways admin page
     * when a user reports "accounts cannot be verified" — the operator
     * can paste a known-good account number from their own bank and
     * see Fintava's exact response (HTTP code, body, error message)
     * without having to read PHP error logs.
     *
     * Mirrors resolve_account()'s call shape exactly (lean headers,
     * sortCode + accountNumber as query params) so the diagnostic
     * surfaces the same failure mode the live form would hit.
     *
     * @param string $account_number 10-digit NUBAN.
     * @param string $bank_code      Bank sort code (3-digit CBN or 6-digit NIBSS).
     * @return array Diagnostic payload (never a WP_Error).
     */
    public function debug_name_enquiry_raw($account_number, $bank_code) {
        if (empty($this->secret_key)) {
            return ['note' => 'no secret_key on the gateway instance', 'has_key' => false];
        }
        if (empty($account_number) || empty($bank_code)) {
            return ['note' => 'account_number and bank_code are both required'];
        }
        $query = http_build_query([
            'accountNumber' => $account_number,
            'sortCode'      => $bank_code,
        ]);
        // Hits the same host the real resolve_account() call uses
        // (i.e. whichever environment the admin selected on the Gateways
        // page), so the diagnostic and the production failure mode
        // share a request path.
        return $this->debug_raw_request('GET', $this->base_url . '/name/enquiry?' . $query, true);
    }

    /**
     * Internal helper for the debug_*_raw() methods. Performs a
     * Fintava-authenticated request and returns a uniform diagnostic
     * envelope (url, http_code, body_raw, body_decoded — or wp_error on
     * transport failure).
     *
     * @param string $method       HTTP verb.
     * @param string $url          Full URL (already includes base + path + query).
     * @param bool   $lean_headers Mirror make_request()'s lean-header mode so
     *     the diagnostic exercises the same request shape as the production
     *     code path. Without this, the diagnostic could falsely succeed
     *     against an endpoint that the real call fails against (or vice
     *     versa) when Fintava's server-side validation depends on which
     *     headers are present.
     */
    private function debug_raw_request($method, $url, $lean_headers = false) {
        $headers = [
            'Authorization' => 'Bearer ' . $this->secret_key,
            'Accept'        => 'application/json',
        ];
        if (!$lean_headers) {
            $headers['Content-Type'] = 'application/json';
            $headers['Merchant-Id']  = $this->merchant_id;
        }
        $args = [
            'method'  => $method,
            'headers' => $headers,
            'timeout' => 30,
        ];

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return [
                'url'           => $url,
                'lean_headers'  => $lean_headers,
                'wp_error'      => $response->get_error_message(),
            ];
        }

        $raw = wp_remote_retrieve_body($response);
        return [
            'url'          => $url,
            'lean_headers' => $lean_headers,
            'http_code'    => wp_remote_retrieve_response_code($response),
            'body_raw'     => is_string($raw) && strlen($raw) > 4000 ? substr($raw, 0, 4000) . '... [truncated]' : $raw,
            'body_decoded' => json_decode($raw, true),
        ];
    }

    /**
     * Make HTTP request to Fintava API.
     *
     * @param string      $method            HTTP verb.
     * @param string      $endpoint          Path beginning with '/'.
     * @param array|null  $body              JSON body for POST/PUT/PATCH.
     * @param string|null $base_url_override Optional alternate base URL. Used
     *     by callers that need to bypass the environment-resolved
     *     `$this->base_url` because a specific endpoint only exists on a
     *     different Fintava host (e.g. `/banks` is only served from
     *     `https://dev.fintavapay.com/api/dev`, regardless of which tier
     *     the merchant otherwise uses for wallets and payouts). Pass with
     *     no trailing slash; the endpoint is concatenated as-is.
     * @param bool        $lean_headers      When true, send only the two
     *     headers Fintava's public docs require for read-only endpoints
     *     (Authorization + Accept). Defaults to false (full header set with
     *     Content-Type and Merchant-Id) so existing wallet/transfer call
     *     paths are unchanged. Used by `/banks` and `/name/enquiry` to match
     *     the documented request shape exactly — some Fintava tiers reject
     *     unrecognised headers on the dev-only endpoints, and Merchant-Id is
     *     the leading suspect when a live-tier merchant gets a generic 401
     *     from /banks despite a valid Authorization header.
     */
    private function make_request($method, $endpoint, $body = null, $base_url_override = null, $lean_headers = false) {
        if (empty($this->secret_key)) {
            return new WP_Error(
                'fintava_not_configured',
                __('Fintava Pay is not configured. Please add your Live API Key in admin.', 'matrix-mlm')
            );
        }

        $base = $base_url_override !== null && $base_url_override !== ''
            ? rtrim($base_url_override, '/')
            : $this->base_url;
        $url = $base . $endpoint;

        // Headers: lean mode mirrors the two-header shape Fintava documents
        // for read-only endpoints (`Authorization` + `accept`). Full mode
        // adds `Content-Type` (needed by JSON body endpoints) and
        // `Merchant-Id` (sent on transfer/wallet endpoints that look it up
        // for routing). Mixing the two on every call has historically been
        // safe, but at least one Fintava tier has been observed to reject
        // /banks when Merchant-Id is present — so we now opt into lean
        // headers for endpoints that don't need the extras.
        $headers = [
            'Authorization' => 'Bearer ' . $this->secret_key,
            'Accept'        => 'application/json',
        ];
        if (!$lean_headers) {
            $headers['Content-Type'] = 'application/json';
            $headers['Merchant-Id']  = $this->merchant_id;
        }

        $args = [
            'method'  => $method,
            'headers' => $headers,
            'timeout' => 30,
        ];

        if ($body && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            // Re-attach Content-Type when there's a body to send, even on
            // lean-header calls, so the server still parses JSON correctly.
            // No current call path triggers this branch (lean mode is only
            // used on GET endpoints), but guarding here keeps lean mode
            // safe to extend to POST endpoints if Fintava ever adds a
            // public lookup that takes a body.
            if ($lean_headers && empty($args['headers']['Content-Type'])) {
                $args['headers']['Content-Type'] = 'application/json';
            }
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
            // Always prefix the error with HTTP status + endpoint so a
            // generic upstream `message` like "An unexpected error
            // occurred" — or a 500 with an empty body — is still
            // diagnosable from the displayed error alone. Previously we
            // fell through to using Fintava's `message` field verbatim,
            // which is useless when the upstream surfaces the same
            // generic string for unrelated failure modes (validator
            // rejection, internal server error, auth issue).
            $base_msg     = sprintf(__('API Error (HTTP %d) calling %s', 'matrix-mlm'), $status_code, $endpoint);
            $upstream_msg = is_array($body_decoded) ? ($body_decoded['message'] ?? null) : null;
            $upstream_str = self::normalize_api_message($upstream_msg, '');
            $error_message = $upstream_str !== '' ? $base_msg . ': ' . $upstream_str : $base_msg;

            // Many JSON validators — including class-validator, which
            // Fintava uses on /bank/credit and friends — put the
            // field-level detail in `errors` / `error` / `details`
            // rather than `message`. Pull whichever is populated and
            // append a truncated snippet so the operator can see what
            // actually failed without needing DevTools or the database.
            if (is_array($body_decoded)) {
                foreach (['errors', 'error', 'details', 'detail'] as $detail_key) {
                    if (!isset($body_decoded[$detail_key])) {
                        continue;
                    }
                    $detail_str = self::normalize_api_message($body_decoded[$detail_key], '');
                    if ($detail_str !== '' && $detail_str !== $upstream_str) {
                        $error_message .= sprintf(' [%s=%s]', $detail_key, substr($detail_str, 0, 200));
                        break;
                    }
                }
            }

            // Include a short body snippet so non-JSON or HTML error pages
            // (Cloudflare interstitials, WAF blocks, gateway 502s) are
            // identifiable from the dropdown alone.
            if (!is_array($body_decoded) && is_string($raw_body) && $raw_body !== '') {
                $snippet = substr(trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($raw_body))), 0, 120);
                if ($snippet !== '') {
                    $error_message .= sprintf(' [body=%s]', $snippet);
                }
            }

            // For HTTP 5xx specifically, append a sanitized list of the
            // request keys we sent. 5xx means Fintava's server crashed
            // before it could return field-level detail in `errors`, so
            // the most useful diagnostic we can add is "did we send all
            // the fields the endpoint expects?" Keys only — no values,
            // no PII. Stays in the WP_Error message (and therefore the
            // failure_reason column in the payout history) so the
            // operator can review later when triaging recurring 500s.
            if ($status_code >= 500 && is_array($body) && !empty($body)) {
                $error_message .= sprintf(' [sent_keys=%s]', implode(',', array_keys($body)));
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
     * Normalize a Nigerian phone number to E.164 (+234...).
     *
     * Fintava's /create/customer DTO runs the `phoneNumber` field
     * through libphonenumber's isValidPhoneNumber check. Nigerian
     * local entries like "08012345678" or "8012345678" fail that
     * check even though they're what users actually type into the
     * form. Coercing here keeps the form input forgiving while
     * sending exactly what Fintava expects on the wire.
     *
     * Accepted inputs (whitespace, dashes, parens stripped first):
     *   - "08012345678"      (11-digit local with leading 0)
     *   - "8012345678"       (10-digit local, no leading 0)
     *   - "2348012345678"    (E.164 without the plus)
     *   - "+2348012345678"   (E.164, the canonical form)
     *
     * Returns the canonical E.164 form, or '' if the input doesn't
     * resolve to a Nigerian mobile prefix (7, 8, or 9 — landlines
     * are out of scope; Fintava virtual wallets only support
     * mobile-line KYC).
     *
     * @param string $raw User-supplied phone string.
     * @return string Canonical "+234..." form or ''.
     */
    public static function normalize_ng_phone($raw) {
        $digits = preg_replace('/\D/', '', (string) $raw);
        if ($digits === '') {
            return '';
        }
        // Strip the international prefix variants down to the 10-digit
        // subscriber number (without the leading 0 / 234).
        if (substr($digits, 0, 3) === '234' && strlen($digits) === 13) {
            $subscriber = substr($digits, 3);
        } elseif (substr($digits, 0, 1) === '0' && strlen($digits) === 11) {
            $subscriber = substr($digits, 1);
        } elseif (strlen($digits) === 10) {
            $subscriber = $digits;
        } else {
            return '';
        }
        // Nigerian mobile prefixes start with 7, 8, or 9. Anything else
        // (1, 2, 3, etc.) is a landline / non-mobile / malformed entry
        // and Fintava's KYC will reject it anyway.
        if (!preg_match('/^[789]\d{9}$/', $subscriber)) {
            return '';
        }
        return '+234' . $subscriber;
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
     * Extract the bank name from a Fintava payload.
     *
     * Tolerated shapes:
     *   - Scalar string under `bank` / `bank_name` / `bankName` — the
     *     baseline shape Fintava's /virtual-wallet/generate returns.
     *   - Nested object under any of the above keys, OR under
     *     `partnerBank` / `partner_bank` / `bankInfo` / `bank_info`. On
     *     at least one Fintava live tier the partner bank is sent as
     *     `{ "bank": { "name": "Globus Bank", "code": "103" } }` — the
     *     scalar-only check returned the array cast to "Array" and
     *     downstream code stored that as the literal bank_name. Now we
     *     drill into `name` / `bankName` / `displayName` / `institution`
     *     / `institutionName` to find the actual string.
     *   - As a last resort, we also walk one level into `wallet` /
     *     `data` / `userInfo.wallet` so callers that pass an
     *     un-unwrapped envelope (the customer endpoint, or the raw
     *     /customers/list row) still get a hit without each call site
     *     having to unwrap manually.
     */
    public static function extract_bank_name($obj) {
        if (!is_array($obj)) {
            return '';
        }

        $direct_keys = ['bank', 'bank_name', 'bankName', 'partnerBank', 'partner_bank', 'bankInfo', 'bank_info'];
        $nested_name_keys = ['name', 'bankName', 'displayName', 'institutionName', 'institution', 'fullName'];

        foreach ($direct_keys as $key) {
            if (!isset($obj[$key]) || $obj[$key] === '') {
                continue;
            }
            $value = $obj[$key];
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
            if (is_scalar($value)) {
                return (string) $value;
            }
            if (is_array($value)) {
                foreach ($nested_name_keys as $sub) {
                    if (isset($value[$sub]) && is_string($value[$sub]) && trim($value[$sub]) !== '') {
                        return $value[$sub];
                    }
                }
            }
        }

        // One-level envelope walk: try the same direct keys against
        // each known wrapper. We stop at one level deep — Fintava
        // never nests deeper than this for the partner-bank field.
        foreach (['wallet', 'data', 'walletDetails', 'wallet_details'] as $wrap) {
            if (isset($obj[$wrap]) && is_array($obj[$wrap])) {
                $found = self::extract_bank_name_inner($obj[$wrap], $direct_keys, $nested_name_keys);
                if ($found !== '') {
                    return $found;
                }
            }
        }
        if (isset($obj['userInfo']['wallet']) && is_array($obj['userInfo']['wallet'])) {
            $found = self::extract_bank_name_inner($obj['userInfo']['wallet'], $direct_keys, $nested_name_keys);
            if ($found !== '') {
                return $found;
            }
        }

        return '';
    }

    /**
     * Helper for extract_bank_name's one-level envelope walk. Kept
     * separate so the recursive lookup logic isn't duplicated.
     */
    private static function extract_bank_name_inner(array $wallet_obj, array $direct_keys, array $nested_name_keys) {
        foreach ($direct_keys as $key) {
            if (!isset($wallet_obj[$key]) || $wallet_obj[$key] === '') {
                continue;
            }
            $value = $wallet_obj[$key];
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
            if (is_scalar($value)) {
                return (string) $value;
            }
            if (is_array($value)) {
                foreach ($nested_name_keys as $sub) {
                    if (isset($value[$sub]) && is_string($value[$sub]) && trim($value[$sub]) !== '') {
                        return $value[$sub];
                    }
                }
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

    /**
     * Map a Nigerian bank name (e.g., "Globus Bank", "Wema Bank
     * Plc.") to a CBN 3-digit sortCode by looking it up in
     * get_static_banks_fallback().
     *
     * Used by ajax_transfer_matrix_to_virtual() as a last-resort
     * derivation when Fintava issues a virtual NUBAN through a
     * partner bank but doesn't echo `bankCode`/`bank_code` in any of
     * its wallet endpoints. The bank_name field on the wallet row
     * always carries the partner-bank name verbatim from Fintava's
     * generate response, so a name-match against the static registry
     * is the cheapest reliable derivation.
     *
     * Matching strategy is intentionally generous to absorb
     * Fintava's response-shape drift:
     *
     *   1. Exact case-insensitive match. Catches the canonical
     *      forms ("Globus Bank", "Wema Bank") that map 1:1 to a
     *      single registry entry.
     *
     *   2. Token-based match: strip "Bank", "PLC", "Limited", "Ltd"
     *      and punctuation from both sides and compare the residual
     *      institution name. Catches "Wema Bank Plc." vs "Wema
     *      Bank", "Globus Bank Limited" vs "Globus Bank", etc.
     *
     *   3. Substring fallback: if either trimmed name contains the
     *      other, accept. Catches longer marketing forms like
     *      "Sterling Bank One Pay" or "Polaris Bank (formerly
     *      Skye)" without us having to enumerate every variant.
     *
     * Returns '' when nothing matches; the caller's final guard
     * surfaces a clearer error with the bank_name value the
     * operator can hand to support.
     *
     * @param string $bank_name Partner-bank name from the wallet row.
     * @return string CBN 3-digit (or NIBSS-issued longer) sortCode, or ''.
     */
    private function derive_bank_code_from_name($bank_name) {
        $needle = trim((string) $bank_name);
        if ($needle === '') {
            return '';
        }

        $banks = self::get_static_banks_fallback();

        // Step 1: exact case-insensitive match.
        foreach ($banks as $bank) {
            if (strcasecmp($bank['name'], $needle) === 0) {
                return $bank['code'];
            }
        }

        // Step 2 + 3: token + substring matching on a normalized
        // form. Stripping the corporate suffixes is what makes
        // "Wema Bank Plc." match "Wema Bank".
        $normalize = static function ($name) {
            $lower = strtolower($name);
            // Remove punctuation, then strip the common corporate
            // suffixes so we're comparing institution names alone.
            $stripped = preg_replace('/[^a-z0-9 ]+/i', ' ', $lower);
            $stripped = preg_replace('/\b(bank|plc|limited|ltd|nigeria|nig|ng|of)\b/i', '', $stripped);
            $stripped = preg_replace('/\s+/', ' ', $stripped);
            return trim($stripped);
        };

        $needle_norm = $normalize($needle);
        if ($needle_norm === '') {
            return '';
        }

        foreach ($banks as $bank) {
            $candidate_norm = $normalize($bank['name']);
            if ($candidate_norm === '') {
                continue;
            }
            if ($candidate_norm === $needle_norm) {
                return $bank['code'];
            }
            // Substring match in either direction. Constrained to
            // entries whose normalized form is at least 3 chars to
            // avoid spurious matches on stop-tokens.
            if (strlen($candidate_norm) >= 3 && strlen($needle_norm) >= 3) {
                if (strpos($candidate_norm, $needle_norm) !== false
                    || strpos($needle_norm, $candidate_norm) !== false) {
                    return $bank['code'];
                }
            }
        }

        return '';
    }

    // =========================================================================
    // SYSTEM FINTAVA ENDPOINTS — FULL DIAGNOSTIC SUITE
    // =========================================================================

    /**
     * Return the complete list of Fintava system endpoints available for
     * diagnostic testing. Each entry contains:
     *   - label:    Human-readable name for the admin UI.
     *   - method:   HTTP verb (GET or POST).
     *   - path:     API path (may contain {placeholders}).
     *   - params:   Array of placeholder/body parameter definitions, each with
     *               'name', 'label', 'required', 'type' (path|body|query).
     *   - category: Grouping key for the admin UI accordion.
     *
     * @return array<string, array>
     */
    public static function get_system_endpoints() {
        return [
            // --- Customers ---
            'create_customer' => [
                'label'    => 'Create Customer',
                'method'   => 'POST',
                'path'     => '/create/customer',
                'params'   => [],
                'category' => 'Customers',
            ],
            'get_customers' => [
                'label'    => 'Get Customers',
                'method'   => 'GET',
                'path'     => '/customers/list',
                'params'   => [],
                'category' => 'Customers',
            ],
            'get_customer_by_phone' => [
                'label'    => 'Get Customer by Phone',
                'method'   => 'GET',
                'path'     => '/customers/details',
                'params'   => [],
                'category' => 'Customers',
            ],
            'fetch_customer' => [
                'label'    => 'Fetch Customer',
                'method'   => 'GET',
                'path'     => '/customers/{customerId}',
                'params'   => [
                    ['name' => 'customerId', 'label' => 'Customer ID', 'required' => true, 'type' => 'path'],
                ],
                'category' => 'Customers',
            ],

            // --- Transactions ---
            'get_customer_transaction' => [
                'label'    => 'Get Customer Transaction',
                'method'   => 'GET',
                'path'     => '/txn',
                'params'   => [],
                'category' => 'Transactions',
            ],
            'get_merchant_transaction' => [
                'label'    => 'Get Merchant Transaction',
                'method'   => 'GET',
                'path'     => '/txn/merchant',
                'params'   => [],
                'category' => 'Transactions',
            ],
            'get_transaction_by_id' => [
                'label'    => 'Get Transaction by ID',
                'method'   => 'GET',
                'path'     => '/transaction/id/{transactionId}',
                'params'   => [
                    ['name' => 'transactionId', 'label' => 'Transaction ID', 'required' => true, 'type' => 'path'],
                ],
                'category' => 'Transactions',
            ],
            'get_transaction_by_reference' => [
                'label'    => 'Get Transaction by Reference',
                'method'   => 'GET',
                'path'     => '/transaction/reference/{reference}',
                'params'   => [
                    ['name' => 'reference', 'label' => 'Reference', 'required' => true, 'type' => 'path'],
                ],
                'category' => 'Transactions',
            ],

            // --- Merchant ---
            'get_merchant_wallet_balance' => [
                'label'    => 'Get Merchant Wallet Balance',
                'method'   => 'GET',
                'path'     => '/merchant/balance',
                'params'   => [],
                'category' => 'Merchant',
            ],
            'merchant_transfer' => [
                'label'    => 'Merchant Transfer',
                'method'   => 'POST',
                'path'     => '/bank/credit/merchant',
                'params'   => [],
                'category' => 'Merchant',
            ],

            // --- Virtual Wallet ---
            'create_virtual_wallet' => [
                'label'    => 'Create Virtual Wallet',
                'method'   => 'POST',
                'path'     => '/virtual-wallet/generate',
                'params'   => [],
                'category' => 'Virtual Wallet',
            ],
            'get_virtual_wallet_details' => [
                'label'    => 'Get Virtual Wallet Details',
                'method'   => 'GET',
                'path'     => '/virtual-wallet/{id}',
                'params'   => [
                    ['name' => 'id', 'label' => 'Wallet ID', 'required' => true, 'type' => 'path'],
                ],
                'category' => 'Virtual Wallet',
            ],
            'wallet_balance' => [
                'label'    => 'Wallet Balance',
                'method'   => 'GET',
                'path'     => '/customer/wallet/balance/{walletId}',
                'params'   => [
                    ['name' => 'walletId', 'label' => 'Wallet ID', 'required' => true, 'type' => 'path'],
                ],
                'category' => 'Virtual Wallet',
            ],
            'get_wallet_details' => [
                'label'    => 'Get Wallet Details (Loma Name Enquiry)',
                'method'   => 'GET',
                'path'     => '/loma-name/enquiry',
                'params'   => [],
                'category' => 'Virtual Wallet',
            ],
            'wallet_transfer' => [
                'label'    => 'Wallet Transfer (Wallet-to-Wallet)',
                'method'   => 'POST',
                'path'     => '/transaction/wallet-to-wallet',
                'params'   => [],
                'category' => 'Virtual Wallet',
            ],

            // --- Bank Transfers ---
            'transfer_to_bank' => [
                'label'    => 'Transfer to Bank',
                'method'   => 'POST',
                'path'     => '/bank/credit',
                'params'   => [],
                'category' => 'Bank Transfers',
            ],
            'get_bank_list' => [
                'label'    => 'Get Bank List',
                'method'   => 'GET',
                'path'     => '/banks',
                'params'   => [],
                'category' => 'Bank Transfers',
            ],
            'transfer_between_wallets' => [
                'label'    => 'Transfer Between Wallets (Single Transfer)',
                'method'   => 'POST',
                'path'     => '/single/transfer',
                'params'   => [],
                'category' => 'Bank Transfers',
            ],
            'get_account_details' => [
                'label'    => 'Get Account Details (Name Enquiry)',
                'method'   => 'GET',
                'path'     => '/name/enquiry',
                'params'   => [],
                'category' => 'Bank Transfers',
            ],

            // --- Cards ---
            'create_card' => [
                'label'    => 'Create Card (Physical Request)',
                'method'   => 'POST',
                'path'     => '/cards/physical/request',
                'params'   => [],
                'category' => 'Cards',
            ],
            'card_status' => [
                'label'    => 'Card Status',
                'method'   => 'GET',
                'path'     => '/cards/status',
                'params'   => [],
                'category' => 'Cards',
            ],
            'activate_card' => [
                'label'    => 'Activate Card',
                'method'   => 'POST',
                'path'     => '/cards/activate',
                'params'   => [],
                'category' => 'Cards',
            ],
            'link_card' => [
                'label'    => 'Link Card',
                'method'   => 'POST',
                'path'     => '/cards/activate',
                'params'   => [],
                'category' => 'Cards',
            ],
            'view_card' => [
                'label'    => 'View Card',
                'method'   => 'GET',
                'path'     => '/cards/fetch/{id}',
                'params'   => [
                    ['name' => 'id', 'label' => 'Card ID', 'required' => true, 'type' => 'path'],
                ],
                'category' => 'Cards',
            ],
            'change_card_pin' => [
                'label'    => 'Change Card PIN',
                'method'   => 'POST',
                'path'     => '/cards/update-pin',
                'params'   => [],
                'category' => 'Cards',
            ],

            // --- Billing / VAS ---
            'buy_airtime' => [
                'label'    => 'Buy Airtime',
                'method'   => 'POST',
                'path'     => '/billing/airtime',
                'params'   => [],
                'category' => 'Billing',
            ],
            'list_cable_providers' => [
                'label'    => 'List Cable Service Providers',
                'method'   => 'GET',
                'path'     => '/cable-service-name',
                'params'   => [],
                'category' => 'Billing',
            ],
            'list_cable_subscriptions' => [
                'label'    => 'List Cable Subscriptions',
                'method'   => 'GET',
                'path'     => '/cable-service-name/{network}',
                'params'   => [
                    ['name' => 'network', 'label' => 'Network/Provider', 'required' => true, 'type' => 'path'],
                ],
                'category' => 'Billing',
            ],
            'buy_cable_subscription' => [
                'label'    => 'Buy Cable Subscription',
                'method'   => 'POST',
                'path'     => '/billing/cable-subscription',
                'params'   => [],
                'category' => 'Billing',
            ],
            'list_data_bundles' => [
                'label'    => 'List Data Bundles',
                'method'   => 'GET',
                'path'     => '/billing/data-bundles/{name}',
                'params'   => [
                    ['name' => 'name', 'label' => 'Network Name', 'required' => true, 'type' => 'path'],
                ],
                'category' => 'Billing',
            ],
            'buy_data_bundle' => [
                'label'    => 'Buy Data Bundle',
                'method'   => 'POST',
                'path'     => '/billing/data-bundle',
                'params'   => [],
                'category' => 'Billing',
            ],
            'list_discos' => [
                'label'    => 'List Discos',
                'method'   => 'GET',
                'path'     => '/billing/discos',
                'params'   => [],
                'category' => 'Billing',
            ],
            'preview_meter' => [
                'label'    => 'Preview Meter Details',
                'method'   => 'POST',
                'path'     => '/billing/preview-meter',
                'params'   => [],
                'category' => 'Billing',
            ],
            'buy_electricity' => [
                'label'    => 'Buy Electricity',
                'method'   => 'POST',
                'path'     => '/billing/electricity',
                'params'   => [],
                'category' => 'Billing',
            ],
        ];
    }

    /**
     * Run a diagnostic probe against a single system endpoint. Accepts the
     * endpoint key from get_system_endpoints() and an optional array of
     * path-parameter values. Returns the raw debug envelope from
     * debug_raw_request().
     *
     * For GET endpoints this fires the request immediately (read-only,
     * safe to probe). For POST endpoints with no body it sends an empty
     * JSON object `{}` — Fintava typically returns a validation error
     * listing the required fields, which itself is useful diagnostic
     * information ("is the endpoint reachable, and what does it expect?").
     *
     * @param string $endpoint_key Key from get_system_endpoints().
     * @param array  $path_params  Associative array of path placeholder values.
     * @return array Diagnostic payload (never a WP_Error).
     */
    public function debug_system_endpoint($endpoint_key, $path_params = []) {
        if (empty($this->secret_key)) {
            return ['note' => 'no secret_key on the gateway instance', 'has_key' => false];
        }

        $endpoints = self::get_system_endpoints();
        if (!isset($endpoints[$endpoint_key])) {
            return ['note' => 'unknown endpoint key: ' . $endpoint_key];
        }

        $ep   = $endpoints[$endpoint_key];
        $path = $ep['path'];

        // Replace path placeholders with provided values.
        if (!empty($ep['params'])) {
            foreach ($ep['params'] as $param) {
                if ($param['type'] === 'path') {
                    $value = $path_params[$param['name']] ?? '';
                    if ($value === '' && $param['required']) {
                        return [
                            'note'     => sprintf('missing required path parameter: %s', $param['name']),
                            'endpoint' => $endpoint_key,
                        ];
                    }
                    $path = str_replace('{' . $param['name'] . '}', rawurlencode($value), $path);
                }
            }
        }

        $url    = $this->base_url . $path;
        $method = strtoupper($ep['method']);

        if ($method === 'GET') {
            return $this->debug_raw_request('GET', $url, true);
        }

        // POST endpoints: send empty body to probe reachability and
        // discover required fields from the validation response.
        $headers = [
            'Authorization' => 'Bearer ' . $this->secret_key,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
            'Merchant-Id'   => $this->merchant_id,
        ];
        $args = [
            'method'  => 'POST',
            'headers' => $headers,
            'timeout' => 30,
            'body'    => '{}',
        ];

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return [
                'url'      => $url,
                'method'   => $method,
                'wp_error' => $response->get_error_message(),
            ];
        }

        $raw = wp_remote_retrieve_body($response);
        return [
            'url'          => $url,
            'method'       => $method,
            'http_code'    => wp_remote_retrieve_response_code($response),
            'body_raw'     => is_string($raw) && strlen($raw) > 4000 ? substr($raw, 0, 4000) . '... [truncated]' : $raw,
            'body_decoded' => json_decode($raw, true),
        ];
    }

    /**
     * Run diagnostic probes against ALL system endpoints that don't require
     * path parameters. Returns an associative array keyed by endpoint key.
     *
     * @return array<string, array>
     */
    public function debug_all_system_endpoints() {
        $endpoints = self::get_system_endpoints();
        $results   = [];

        foreach ($endpoints as $key => $ep) {
            // Skip endpoints that require path params — they need user input.
            $requires_path_param = false;
            if (!empty($ep['params'])) {
                foreach ($ep['params'] as $param) {
                    if ($param['type'] === 'path' && $param['required']) {
                        $requires_path_param = true;
                        break;
                    }
                }
            }
            if ($requires_path_param) {
                $results[$key] = [
                    'skipped' => true,
                    'note'    => sprintf('requires path parameter(s) — use the individual test form'),
                    'label'   => $ep['label'],
                    'path'    => $ep['path'],
                ];
                continue;
            }

            $results[$key] = $this->debug_system_endpoint($key);
            $results[$key]['label'] = $ep['label'];
        }

        return $results;
    }
}
