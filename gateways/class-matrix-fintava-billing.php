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
 *
 * Service fee (added in item C):
 *   Each category supports an optional flat + percentage markup
 *   configured in Settings -> Bill Payments. The fee is computed
 *   server-side via compute_service_fee() and added on top of the
 *   user's chosen amount, so:
 *
 *       nominal      = what the user typed / picked
 *       service_fee  = compute_service_fee(category, nominal)
 *       total        = nominal + service_fee
 *
 *   The wallet is debited for `total` in one debit. Fintava is
 *   called with `nominal` only — the telco doesn't know about our
 *   markup. On API failure the full `total` is refunded so the
 *   user is made whole including the never-accrued fee. The
 *   transaction row records all three values in their own
 *   columns (nominal_amount, service_fee, total_charged) so
 *   revenue reporting can sum service_fee without parsing JSON.
 *   When markup is unconfigured (default) the fee is 0 and the
 *   flow collapses to the pre-C single-amount path.
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

    /**
     * Canonical list of bill categories. The order here is the
     * display order in the user-facing tab strip and on the admin
     * Bill Payments visibility toggle page. Adding a new category
     * is a one-row change here PLUS:
     *   - a render_<type>() method on Matrix_MLM_User_Billing,
     *   - an entry in get_user_history() schema (see
     *     Matrix_MLM_User_Billing::render_history_details()),
     *   - a buy_<type>() method + ajax_buy_<type>() handler here,
     *   - a row in DEFAULT_CAPS above.
     */
    const BILL_CATEGORIES = ['airtime', 'data', 'cable', 'electricity'];

    public function __construct() {
        $this->register_hooks();
    }

    /**
     * Whether a given bill category is currently enabled for the
     * user-facing dashboard.
     *
     * Resolution order:
     *   1. Unknown / unregistered category -> false. Defensive: an
     *      AJAX handler that lets a typo'd type through would be a
     *      bug, so we fail closed.
     *   2. The matrix_mlm_billing_category_visibility option is
     *      read as a JSON-encoded category=>0|1 map. Missing keys
     *      and an unparseable blob both default to TRUE so:
     *        a. Fresh installs with no option saved show every
     *           category (preserves the pre-toggle behaviour).
     *        b. New categories added in a future plugin version
     *           are visible by default until an admin toggles them.
     *
     * Called from three layers:
     *   - The user-facing sub-tab nav (cosmetic — hides the link).
     *   - The user-facing render_<type>() methods (defensive — a
     *     direct ?service= URL or a stale page-cached link to a
     *     since-disabled category falls through to the first
     *     enabled category instead of rendering an orphan form).
     *   - Each buy_<type> AJAX handler + verify_meter (server-side
     *     defensive — refuses calls that bypass the rendered UI).
     *
     * The list_<type> handlers (data bundles, cable providers,
     * cable plans, discos) are NOT gated — they return innocuous
     * public Fintava catalog data, gating them would just stale
     * the in-flight UI for an admin who toggled mid-session, and
     * they all funnel into a buy_* call that IS gated.
     *
     * @param string $category One of self::BILL_CATEGORIES.
     * @return bool TRUE if the category is enabled / visible.
     */
    public static function is_category_enabled($category) {
        if (!in_array($category, self::BILL_CATEGORIES, true)) {
            return false;
        }

        $raw = get_option('matrix_mlm_billing_category_visibility', '');
        if (!is_string($raw) || $raw === '') {
            return true;
        }

        $map = json_decode($raw, true);
        if (!is_array($map) || !array_key_exists($category, $map)) {
            return true;
        }

        return (bool) $map[$category];
    }

    /**
     * Reject the in-flight AJAX request when the targeted bill
     * category has been disabled by the admin. Centralised so the
     * rejection message stays consistent across all five gated
     * handlers (buy_airtime/data/cable/electricity, verify_meter)
     * and so the gate sits in exactly one place — adding category
     * #5 won't drift the gate semantics for the existing four.
     *
     * Note: this short-circuits via wp_send_json_error + exit, so
     * callers do not need to handle a return value.
     *
     * @param string $category One of self::BILL_CATEGORIES.
     */
    private static function require_category_enabled_or_die($category) {
        if (self::is_category_enabled($category)) {
            return;
        }
        wp_send_json_error([
            'message' => sprintf(
                /* translators: %s: the bill category (e.g. "airtime"). */
                __('%s purchases are temporarily disabled. Please try again later or contact support.', 'matrix-mlm'),
                ucfirst($category)
            ),
            'category_disabled' => $category,
        ]);
    }

    /**
     * Compute the platform service fee for a given bill category and
     * nominal bill amount.
     *
     *   fee = flat + nominal * percent / 100,  rounded to 2dp.
     *
     * The flat and percent components are read from the
     * matrix_mlm_fintava_billing_markup option, which is an assoc
     * array shaped like:
     *
     *     [
     *         'airtime'     => ['flat' => 20.00, 'percent' => 1.5],
     *         'data'        => ['flat' => 0.00,  'percent' => 2.0],
     *         'cable'       => ['flat' => 100.0, 'percent' => 0.0],
     *         'electricity' => ['flat' => 50.00, 'percent' => 0.5],
     *     ]
     *
     * Resolution rules — every branch fails safe to ZERO so a corrupt
     * option can never *over*-charge a user. The worst it can do is
     * skip a fee that should have been applied, which is a revenue
     * miss but not a customer-trust event:
     *   - Unknown category               -> 0
     *   - Non-positive nominal           -> 0
     *   - Option not array / missing key -> 0
     *   - Non-array per-category entry   -> 0
     *   - Negative flat or percent       -> coerced to 0
     *   - Percent > 100                  -> capped at 100 (sanity)
     *
     * Defaults to zero everywhere — fresh installs and admins who
     * have not configured markup behave exactly as before C landed.
     *
     * @param string $type    One of self::BILL_CATEGORIES.
     * @param float  $nominal The bill amount the user entered / picked.
     * @return float Service fee in major units, >= 0, rounded 2dp.
     */
    public static function compute_service_fee($type, $nominal) {
        if (!in_array($type, self::BILL_CATEGORIES, true)) {
            return 0.0;
        }
        $nominal = floatval($nominal);
        if ($nominal <= 0) {
            return 0.0;
        }

        $cfg = get_option('matrix_mlm_fintava_billing_markup', []);
        if (!is_array($cfg) || !isset($cfg[$type]) || !is_array($cfg[$type])) {
            return 0.0;
        }

        $flat    = max(0.0, floatval($cfg[$type]['flat']    ?? 0));
        $percent = max(0.0, floatval($cfg[$type]['percent'] ?? 0));
        $percent = min(100.0, $percent);

        return round($flat + ($nominal * $percent / 100.0), 2);
    }

    /**
     * Public accessor for the markup config so the user-facing
     * billing forms can render a client-side fee preview without
     * round-tripping. The server is still authoritative — every
     * ajax_buy_* handler recomputes fee via compute_service_fee()
     * before debiting and before logging — so this is purely a UX
     * hint.
     *
     * Always returns a complete map for every BILL_CATEGORIES slug
     * with both flat and percent fields populated, so the consumer
     * can dereference without null checks.
     *
     * @return array<string, array{flat: float, percent: float}>
     */
    public static function get_markup_config() {
        $cfg = get_option('matrix_mlm_fintava_billing_markup', []);
        if (!is_array($cfg)) { $cfg = []; }
        $out = [];
        foreach (self::BILL_CATEGORIES as $slug) {
            $entry = is_array($cfg[$slug] ?? null) ? $cfg[$slug] : [];
            $flat    = max(0.0, floatval($entry['flat']    ?? 0));
            $percent = max(0.0, floatval($entry['percent'] ?? 0));
            $percent = min(100.0, $percent);
            $out[$slug] = [
                'flat'    => round($flat, 2),
                'percent' => round($percent, 2),
            ];
        }
        return $out;
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

        // M4: bound airtime-purchase volume per user. The user's
        // wallet is debited up-front so spam costs the attacker
        // money, but a compromised account would otherwise drain at
        // line speed.
        if (Matrix_MLM_Rate_Limiter::throttle(
            'fintava_buy_airtime',
            Matrix_MLM_Rate_Limiter::key_for_request(),
            ['max_attempts' => 30, 'window_seconds' => 15 * MINUTE_IN_SECONDS]
        )) {
            wp_send_json_error(['message' => __('Too many airtime purchases. Please wait a few minutes and try again.', 'matrix-mlm')]);
        }

        // Per-category kill switch (admin -> Settings -> Bill Payments).
        // Runs AFTER the rate limit so a flood against a disabled
        // category still costs the attacker counter slots, not just
        // a cheap probe of the visibility option.
        self::require_category_enabled_or_die('airtime');

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

        // Service fee (item C). Computed server-side from the
        // matrix_mlm_fintava_billing_markup option — the client may
        // have shown a preview, but the wallet is debited and the
        // transaction is logged based on this value alone. Defaults
        // to 0 for installs that haven't configured markup, in which
        // case nominal == total and the flow is identical to pre-C.
        $nominal = round($amount, 2);
        $fee     = self::compute_service_fee('airtime', $nominal);
        $total   = round($nominal + $fee, 2);

        // Debit the TOTAL (nominal + fee). Fintava is called with
        // the NOMINAL only — the telco doesn't know about our fee.
        $debit = self::debit_for_purchase($user_id, $total, 'airtime', sprintf(__('Airtime purchase to %s', 'matrix-mlm'), $phone));
        if (is_wp_error($debit)) {
            wp_send_json_error(['message' => $debit->get_error_message()]);
        }
        $debit_reference = $debit;

        $result = $this->buy_airtime($phone, $nominal, $network);
        if (is_wp_error($result)) {
            // Refund the TOTAL — user is made whole including fee.
            // The fee never accrued because no upstream call landed.
            self::refund_failed_purchase($user_id, $total, 'airtime', $debit_reference, $result->get_error_message());
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        $this->log_transaction($user_id, 'airtime', [
            'nominal' => $nominal,
            'fee'     => $fee,
            'total'   => $total,
        ], ['phone' => $phone, 'network' => $network, 'debit_ref' => $debit_reference], $result);

        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $message  = sprintf(
            /* translators: %1$s: currency symbol, %2$s: nominal amount, %3$s: phone */
            __('%1$s%2$s airtime sent to %3$s.', 'matrix-mlm'),
            $currency,
            number_format($nominal, 2),
            $phone
        );
        if ($fee > 0) {
            $message .= ' ' . sprintf(
                /* translators: %1$s: currency symbol + total, %2$s: currency symbol + fee */
                __('Wallet debited %1$s (includes %2$s service fee).', 'matrix-mlm'),
                $currency . number_format($total, 2),
                $currency . number_format($fee, 2)
            );
        } else {
            $message .= ' ' . __('(debited from your wallet)', 'matrix-mlm');
        }
        wp_send_json_success([
            'message' => $message,
            'nominal' => $nominal,
            'fee'     => $fee,
            'total'   => $total,
        ]);
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

        // M4: bound bill-purchase volume per user. Same threat model
        // as ajax_buy_airtime.
        if (Matrix_MLM_Rate_Limiter::throttle(
            'fintava_buy_data',
            Matrix_MLM_Rate_Limiter::key_for_request(),
            ['max_attempts' => 30, 'window_seconds' => 15 * MINUTE_IN_SECONDS]
        )) {
            wp_send_json_error(['message' => __('Too many data purchases. Please wait a few minutes and try again.', 'matrix-mlm')]);
        }

        self::require_category_enabled_or_die('data');

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

        // Service fee (item C). See ajax_buy_airtime for the
        // nominal/fee/total contract — same rules apply here, only
        // the category and the upstream call differ.
        $nominal = round($amount, 2);
        $fee     = self::compute_service_fee('data', $nominal);
        $total   = round($nominal + $fee, 2);

        $debit = self::debit_for_purchase($user_id, $total, 'data', sprintf(__('Data bundle to %s', 'matrix-mlm'), $phone));
        if (is_wp_error($debit)) {
            wp_send_json_error(['message' => $debit->get_error_message()]);
        }
        $debit_reference = $debit;

        $result = $this->buy_data($phone, $plan_id, $network);
        if (is_wp_error($result)) {
            self::refund_failed_purchase($user_id, $total, 'data', $debit_reference, $result->get_error_message());
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        $this->log_transaction($user_id, 'data', [
            'nominal' => $nominal,
            'fee'     => $fee,
            'total'   => $total,
        ], ['phone' => $phone, 'network' => $network, 'plan_id' => $plan_id, 'debit_ref' => $debit_reference], $result);

        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $message  = __('Data bundle purchased successfully!', 'matrix-mlm');
        if ($fee > 0) {
            $message .= ' ' . sprintf(
                __('Wallet debited %1$s (includes %2$s service fee).', 'matrix-mlm'),
                $currency . number_format($total, 2),
                $currency . number_format($fee, 2)
            );
        } else {
            $message .= ' ' . __('(debited from your wallet)', 'matrix-mlm');
        }
        wp_send_json_success([
            'message' => $message,
            'nominal' => $nominal,
            'fee'     => $fee,
            'total'   => $total,
        ]);
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

        // M4: bound bill-purchase volume per user.
        if (Matrix_MLM_Rate_Limiter::throttle(
            'fintava_buy_cable',
            Matrix_MLM_Rate_Limiter::key_for_request(),
            ['max_attempts' => 30, 'window_seconds' => 15 * MINUTE_IN_SECONDS]
        )) {
            wp_send_json_error(['message' => __('Too many cable purchases. Please wait a few minutes and try again.', 'matrix-mlm')]);
        }

        self::require_category_enabled_or_die('cable');

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

        // Service fee (item C). See ajax_buy_airtime for the
        // nominal/fee/total contract.
        $nominal = round($amount, 2);
        $fee     = self::compute_service_fee('cable', $nominal);
        $total   = round($nominal + $fee, 2);

        $debit = self::debit_for_purchase($user_id, $total, 'cable', sprintf(__('Cable subscription %s', 'matrix-mlm'), $provider));
        if (is_wp_error($debit)) {
            wp_send_json_error(['message' => $debit->get_error_message()]);
        }
        $debit_reference = $debit;

        $result = $this->buy_cable($smartcard, $plan_id, $provider);
        if (is_wp_error($result)) {
            self::refund_failed_purchase($user_id, $total, 'cable', $debit_reference, $result->get_error_message());
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        $this->log_transaction($user_id, 'cable', [
            'nominal' => $nominal,
            'fee'     => $fee,
            'total'   => $total,
        ], ['smartcard' => $smartcard, 'provider' => $provider, 'plan_id' => $plan_id, 'debit_ref' => $debit_reference], $result);

        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $message  = __('Cable subscription purchased successfully!', 'matrix-mlm');
        if ($fee > 0) {
            $message .= ' ' . sprintf(
                __('Wallet debited %1$s (includes %2$s service fee).', 'matrix-mlm'),
                $currency . number_format($total, 2),
                $currency . number_format($fee, 2)
            );
        } else {
            $message .= ' ' . __('(debited from your wallet)', 'matrix-mlm');
        }
        wp_send_json_success([
            'message' => $message,
            'nominal' => $nominal,
            'fee'     => $fee,
            'total'   => $total,
        ]);
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

        // M4: meter-number enumeration oracle. /billing/meter/verify
        // returns customer name + tariff data for a valid meter, so
        // unbounded calls let an attacker enumerate meter numbers
        // against a disco. Tighter cap than purchase endpoints.
        if (Matrix_MLM_Rate_Limiter::throttle(
            'fintava_verify_meter',
            Matrix_MLM_Rate_Limiter::key_for_request(),
            ['max_attempts' => 20, 'window_seconds' => 15 * MINUTE_IN_SECONDS]
        )) {
            wp_send_json_error(['message' => __('Too many meter lookups. Please wait a few minutes and try again.', 'matrix-mlm')]);
        }

        // verify_meter is only useful inside the Electricity flow,
        // so it shares the electricity kill switch.
        self::require_category_enabled_or_die('electricity');

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

        // M4: bound bill-purchase volume per user.
        if (Matrix_MLM_Rate_Limiter::throttle(
            'fintava_buy_electricity',
            Matrix_MLM_Rate_Limiter::key_for_request(),
            ['max_attempts' => 30, 'window_seconds' => 15 * MINUTE_IN_SECONDS]
        )) {
            wp_send_json_error(['message' => __('Too many electricity purchases. Please wait a few minutes and try again.', 'matrix-mlm')]);
        }

        self::require_category_enabled_or_die('electricity');

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

        // Service fee (item C). See ajax_buy_airtime for the
        // nominal/fee/total contract.
        $nominal = round($amount, 2);
        $fee     = self::compute_service_fee('electricity', $nominal);
        $total   = round($nominal + $fee, 2);

        $debit = self::debit_for_purchase($user_id, $total, 'electricity', sprintf(__('Electricity purchase for meter %s', 'matrix-mlm'), $meter_number));
        if (is_wp_error($debit)) {
            wp_send_json_error(['message' => $debit->get_error_message()]);
        }
        $debit_reference = $debit;

        $result = $this->buy_electricity($meter_number, $nominal, $disco, $meter_type);
        if (is_wp_error($result)) {
            self::refund_failed_purchase($user_id, $total, 'electricity', $debit_reference, $result->get_error_message());
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        $token = $result['data']['token'] ?? $result['token'] ?? '';
        $this->log_transaction($user_id, 'electricity', [
            'nominal' => $nominal,
            'fee'     => $fee,
            'total'   => $total,
        ], ['meter' => $meter_number, 'disco' => $disco, 'type' => $meter_type, 'token' => $token, 'debit_ref' => $debit_reference], $result);

        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $msg      = __('Electricity purchased successfully!', 'matrix-mlm');
        if ($fee > 0) {
            $msg .= ' ' . sprintf(
                __('Wallet debited %1$s (includes %2$s service fee).', 'matrix-mlm'),
                $currency . number_format($total, 2),
                $currency . number_format($fee, 2)
            );
        } else {
            $msg .= ' ' . __('(debited from your wallet)', 'matrix-mlm');
        }
        if ($token) { $msg .= ' Token: ' . $token; }
        wp_send_json_success([
            'message' => $msg,
            'token'   => $token,
            'nominal' => $nominal,
            'fee'     => $fee,
            'total'   => $total,
        ]);
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

    private function log_transaction($user_id, $type, $amounts, $details, $api_response) {
        global $wpdb;

        // $amounts is the breakdown computed by the calling
        // ajax_buy_<type> handler. Defensive: accept missing keys
        // and a legacy scalar (single $amount) so an older caller
        // — or a hand-rolled invocation in dev — keeps working.
        // Production callers in this file always pass the full
        // associative form.
        if (is_array($amounts)) {
            $nominal = round(floatval($amounts['nominal'] ?? 0), 2);
            $fee     = round(floatval($amounts['fee']     ?? 0), 2);
            $total   = round(floatval($amounts['total']   ?? ($nominal + $fee)), 2);
        } else {
            $nominal = round(floatval($amounts), 2);
            $fee     = 0.0;
            $total   = $nominal;
        }

        $wpdb->insert($wpdb->prefix . 'matrix_billing_transactions', [
            'user_id'        => $user_id,
            'type'           => $type,
            // Legacy column kept in sync with nominal so any
            // third-party reader that selects `amount` (and the
            // existing user-history template) keeps working until
            // the readers migrate to nominal_amount.
            'amount'         => $nominal,
            'nominal_amount' => $nominal,
            'service_fee'    => $fee,
            'total_charged'  => $total,
            'details'        => json_encode($details),
            'api_response'   => json_encode($api_response),
            'status'         => 'completed',
            'created_at'     => current_time('mysql'),
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
     * Create / migrate the matrix_billing_transactions table.
     *
     * Idempotent. Runs on every plugin pageload via
     * Matrix_MLM_Database, so any new column / index added here
     * shows up automatically the next time the operator hits a
     * Matrix admin page — no manual migration step required.
     *
     * Item C (PR #260) added three columns:
     *   - nominal_amount  — bill amount sent to Fintava
     *   - service_fee     — platform markup (>= 0)
     *   - total_charged   — what was actually debited from the user
     * The legacy `amount` column is kept and written to (via
     * log_transaction) in lock-step with nominal_amount so any
     * third-party reader that hardcoded `SELECT amount` keeps
     * working until they migrate.
     */
    public static function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'matrix_billing_transactions';

        // Detect whether the C-era columns already exist BEFORE
        // dbDelta runs, so we know whether to backfill afterwards.
        // dbDelta is happy to add a NOT NULL DEFAULT 0 column to an
        // existing table, but it won't seed the new column from the
        // legacy `amount` column — that's our job here.
        $needs_backfill = !$wpdb->get_var($wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME, $table, 'nominal_amount'
        ));

        $sql = "CREATE TABLE $table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            type enum('airtime','data','cable','electricity') NOT NULL,
            amount decimal(12,2) NOT NULL,
            nominal_amount decimal(12,2) NOT NULL DEFAULT 0.00,
            service_fee decimal(12,2) NOT NULL DEFAULT 0.00,
            total_charged decimal(12,2) NOT NULL DEFAULT 0.00,
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

        // First-run migration only: seed the C-era columns from the
        // legacy `amount` column on every existing row. Guarded by
        // the COLUMN_NAME probe above so this never runs twice — on
        // subsequent pageloads the column exists and $needs_backfill
        // is false.
        //
        // We touch only rows whose nominal_amount is still the
        // dbDelta default (0). New rows post-C always insert with
        // the real values, so the WHERE clause is also a defence
        // against accidentally clobbering a legitimate 0-row.
        if ($needs_backfill) {
            $wpdb->query(
                "UPDATE $table
                 SET nominal_amount = amount,
                     total_charged  = amount
                 WHERE nominal_amount = 0
                   AND amount > 0"
            );
        }
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
