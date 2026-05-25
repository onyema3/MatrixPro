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
 *   wallet when these endpoints are called (the wallet whose NUBAN
 *   is configured as the "operating account" admin-side — Fintava
 *   identifies it via the merchant ID in the request header).
 *   To make the user pay for what they actually consume, every
 *   bill-purchase handler in this class moves the bill total from
 *   the user's Fintava virtual wallet into the merchant's
 *   master/operating wallet via POST /transaction/wallet-to-wallet
 *   BEFORE calling the upstream billing endpoint. On billing-API
 *   failure the wallet-to-wallet is reversed (merchant -> user's
 *   virtual wallet) so the user is made whole; on billing-API
 *   success the move stands and the merchant has been reimbursed
 *   by the user with the service fee accruing on the merchant
 *   wallet as the difference between the wallet-to-wallet `total`
 *   and the billing endpoint's `nominal` debit.
 *
 *   This is a deliberate change from the pre-PR-#294 architecture
 *   where the user's MatrixPro internal wallet (matrix_user_meta.
 *   balance) was the source. The internal-wallet path required
 *   users to pre-fund the Matrix wallet (typically by transferring
 *   from their Fintava virtual wallet first), which created a
 *   confusing two-step "fund Fintava → transfer to Matrix → buy
 *   bill" experience for what is conceptually one purchase. The
 *   virtual-wallet-as-source model collapses that into one step
 *   and aligns bill payments with the bank-payout flow, which has
 *   always sourced from the user's virtual wallet.
 *
 *   For migration safety, every matrix_billing_transactions row
 *   carries a `source_wallet` column ('matrix' or 'virtual') that
 *   records which wallet was actually debited. Pre-PR-#294 rows
 *   are stamped 'matrix' (the column DEFAULT) so an admin-initiated
 *   refund or a future reconciliation worker can route the credit
 *   back to the correct wallet without having to infer it from
 *   row age. Post-PR-#294 rows are stamped 'virtual'.
 *
 *   Before C1, these handlers ran the API call without ever
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
 *
 * Idempotency lifecycle (added in item E):
 *   Every bill-purchase request now generates a stable
 *   client_reference BEFORE the upstream call, persists a
 *   `pending` transaction row carrying that reference, then
 *   transitions the row based on the upstream outcome:
 *
 *     pending  -> completed   (HTTP 2xx)
 *     pending  -> failed      (HTTP 4xx/5xx with body, refund wallet)
 *     pending  -> pending     (transport-level error, DON'T refund)
 *
 *   The transport-level / HTTP-level distinction is the central
 *   correctness change of E: a connection timeout is ambiguous
 *   ("did Fintava get the call or not?"), so we leave the wallet
 *   debited and the row pending. A reconciliation worker (out
 *   of scope here, deferred to a follow-up) replays the
 *   client_reference against Fintava and finalises the row.
 *
 *   Auto-refunding on transport errors is the OLD behaviour and
 *   was the silent-double-spend bug E closes: if Fintava DID
 *   get the call but the connection died on the response, an
 *   auto-refund credits the user back AND lets the upstream
 *   purchase complete, so they get the airtime AND keep the money.
 *
 *   The reference is sent under both `client_reference` (snake_case,
 *   matching the rest of the billing endpoints' field naming) and
 *   `clientReference` (camelCase, matching the card-API's DTO
 *   conventions). Fintava ignores unknown JSON keys, so dual-key
 *   is robust against either internal convention without a
 *   downside on the wire.
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
     * Short opaque support-reference token minted by
     * begin_transaction() when its INSERT into
     * matrix_billing_transactions fails. process_purchase()'s
     * begin_failed branch reads this and embeds it in the
     * user-visible error message so a user reporting "Internal
     * error. Please try again." can quote the token in a support
     * ticket and ops can grep the PHP error log for the matching
     * [Matrix MLM DB] line and recover the underlying $wpdb error
     * (typically a schema-drift "Unknown column 'X'" after a copy
     * deploy that didn't trigger dbDelta).
     *
     * NULL after a successful begin_transaction or before the
     * first begin_transaction call. Read-once: process_purchase()
     * captures the value into a local before invoking
     * refund_failed_purchase(), because the wallet credit there
     * does its own DB write that may clobber $wpdb->last_error
     * (and, hypothetically, a future caller resetting this
     * property).
     *
     * Not static: a single Matrix_MLM_Fintava_Billing instance
     * processes one AJAX request, so the property's lifetime
     * matches the failed begin_transaction it describes.
     *
     * @var string|null
     */
    private $last_begin_support_ref = null;

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
        // Self-heal cap on flat. See resolve_max_flat_fee() for the
        // default and the filter override. Cheap to evaluate every
        // call; matches the percent cap above so neither field can
        // be exploited by an option-value typo to produce an absurd
        // total.
        $flat    = min($flat, self::resolve_max_flat_fee($type));

        return round($flat + ($nominal * $percent / 100.0), 2);
    }

    /**
     * The default upper bound (in major currency units) on the flat
     * component of the per-category service fee. The percent
     * component is capped at 100% in code; the flat component used
     * to be uncapped, which let an admin who typed 14000 in airtime's
     * flat field block every member whose wallet balance was below
     * ~14k from buying any airtime at all (compute total = 100 + 14000
     * = 14100 > balance -> "Insufficient wallet balance").
     *
     * 1000 (~₦1,000 in NGN, ~$1 in USD-equivalent) is comfortably
     * above the realistic flat-fee range for telco-style services
     * (typically 20–200) and well below the smallest plausible bill
     * cap, so it catches obvious typos without surprising operators
     * who have a legitimately-elevated flat fee. Operators who
     * genuinely need more can:
     *
     *   - Set a higher per-category cap via the
     *     matrix_mlm_fintava_billing_max_flat_fee filter
     *     (return scalar -> applies to all categories;
     *      return array  -> {category: cap}).
     *   - OR shift the burden onto the percent component, which
     *     scales with nominal and can't accidentally exceed it.
     */
    const DEFAULT_MAX_FLAT_FEE = 1000;

    /**
     * Resolve the effective max-flat cap for a given bill category.
     * Filterable so an operator with a genuine high-flat use case
     * can override without forking the plugin. Used by
     * compute_service_fee() (compute-time self-heal) AND by the
     * admin Settings save handler (save-time clamp + admin notice).
     *
     * Filter contract:
     *   - Scalar return    -> applies to every category.
     *   - Array return     -> per-category override; missing keys
     *                         fall back to DEFAULT_MAX_FLAT_FEE.
     *   - Anything else    -> ignored, default applies.
     *   - Non-numeric / negative values -> coerced to 0 (effectively
     *                         forbids flat fees on that category).
     *
     * @param string $type  One of self::BILL_CATEGORIES.
     * @return float        Max flat fee in major units, >= 0.
     */
    public static function resolve_max_flat_fee($type) {
        $default  = (float) self::DEFAULT_MAX_FLAT_FEE;
        $filtered = apply_filters('matrix_mlm_fintava_billing_max_flat_fee', $default, $type);
        if (is_array($filtered)) {
            $filtered = $filtered[$type] ?? $default;
        }
        if (!is_numeric($filtered)) {
            return $default;
        }
        return max(0.0, (float) $filtered);
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
            // Mirror the compute_service_fee() flat clamp so the
            // client-side preview shows the SAME total the server
            // will charge — a stored option whose flat is above the
            // resolved max would otherwise display a higher
            // "Total to debit" than the user actually pays.
            $flat    = min($flat, self::resolve_max_flat_fee($slug));
            $out[$slug] = [
                'flat'    => round($flat, 2),
                'percent' => round($percent, 2),
            ];
        }
        return $out;
    }

    /**
     * Build a stable, collision-resistant client_reference for an
     * outbound Fintava billing call. Format:
     *
     *     MTRX-BILL-{type}-{user_id}-{base32_random}
     *
     * Components:
     *   - "MTRX-BILL"  : namespace prefix so a Fintava operator
     *                    eyeballing a transaction can attribute it
     *                    to MatrixPro instantly. Mirrors the
     *                    BILL-/REFUND-BILL- prefixes already in use
     *                    on matrix_wallet auditor rows.
     *   - {type}       : airtime|data|cable|electricity. Lets a log
     *                    grep narrow to a single category without
     *                    joining back to the transactions table.
     *   - {user_id}    : the matrix user id. Useful both for grep
     *                    and as a partial namespace — even in the
     *                    extraordinarily unlikely event the
     *                    base32_random component collided, the
     *                    per-user namespacing means the collision
     *                    would have to happen for the same user,
     *                    making it harmless under our UNIQUE index.
     *   - base32_random: 13 base32 chars (RFC 4648 alphabet) of 8
     *                    random bytes (64 bits of entropy). The full
     *                    13-char encoding fits within the 64-char
     *                    column budget after the prefix and is
     *                    case-flat / URL-safe.
     *
     * 64 bits of entropy means a 1-in-1.8e19 collision per generation
     * — the column's UNIQUE index is the belt; this is the braces.
     * Failing-loud on a UNIQUE collision (rather than retrying) is
     * the deliberate choice: if it ever fires, something more
     * interesting than a coincidence is going on (e.g. a clock skew
     * messing with random_bytes() seeding) and we want to know.
     *
     * @param string $type    One of self::BILL_CATEGORIES.
     * @param int    $user_id The matrix user initiating the purchase.
     * @return string A reference string, max 64 chars.
     */
    public static function build_client_reference($type, $user_id) {
        // 8 bytes = 64 bits = 13 base32 chars after encoding.
        // random_bytes() is the canonical CSPRNG; the openssl_*
        // fallback handles the (rare) install where random_bytes is
        // unavailable, e.g. PHP without the standard /dev/urandom
        // accessor on a hardened container.
        $bytes = function_exists('random_bytes')
            ? random_bytes(8)
            : openssl_random_pseudo_bytes(8);

        // RFC 4648 base32 alphabet. Case-flat (all uppercase),
        // confusables-removed (no 0/O, 1/I/L), URL-safe.
        static $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = 0;
        $bits_count = 0;
        $out = '';
        for ($i = 0, $len = strlen($bytes); $i < $len; $i++) {
            $bits = ($bits << 8) | ord($bytes[$i]);
            $bits_count += 8;
            while ($bits_count >= 5) {
                $bits_count -= 5;
                $out .= $alphabet[($bits >> $bits_count) & 0x1F];
            }
        }
        // The last partial group (1 bit left after 8 bytes -> 13
        // chars) gets flushed by left-padding the residual bits.
        if ($bits_count > 0) {
            $out .= $alphabet[($bits << (5 - $bits_count)) & 0x1F];
        }

        return sprintf('MTRX-BILL-%s-%d-%s', $type, (int) $user_id, $out);
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
     *
     * Fintava's /billing/airtime endpoint expects VTU-convention
     * field names (Virtual Top-Up — the standard Nigerian fintech
     * naming for telco airtime/data top-ups), NOT the descriptive
     * names the rest of this class uses internally. Without these
     * keys the endpoint rejects the call with HTTP 400 and the
     * message:
     *   "vtu_network must be a string;
     *    vtu_amount must be a number conforming to the specified constraints;
     *    vtu_number must be a string"
     *
     * Mapping (internal name -> Fintava wire name):
     *   $phone   -> vtu_number   (the recipient phone, as a string)
     *   $amount  -> vtu_amount   (numeric, in major units / NGN)
     *   $network -> vtu_network  (MTN / GLO / AIRTEL / 9MOBILE)
     *
     * The legacy `phone` / `amount` / `network` keys are NOT sent
     * — Fintava rejects the request when only those are present,
     * which was the user-visible "bills payment error … vtu_network
     * must be a string; …" report. The PHP method signature keeps
     * the descriptive names so the four ajax_buy_<type> handlers
     * (and any non-AJAX caller) don't have to know the wire convention.
     *
     * @param string      $phone
     * @param float       $amount
     * @param string      $network
     * @param string|null $client_reference Item E. When supplied, sent on the
     *                                      request body for upstream
     *                                      idempotency. When NULL the call
     *                                      is sent without a reference,
     *                                      which preserves backward compat
     *                                      for any non-AJAX caller (none in
     *                                      tree today, but the contract is
     *                                      kept stable).
     */
    public function buy_airtime($phone, $amount, $network, $client_reference = null) {
        return $this->make_request('POST', '/billing/airtime', $this->with_client_reference([
            'vtu_number'  => (string) $phone,
            'vtu_amount'  => floatval($amount),
            'vtu_network' => (string) $network,
        ], $client_reference));
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
     *
     * @param string      $smartcard_number
     * @param string      $plan_id
     * @param string      $provider
     * @param string|null $client_reference Item E. See buy_airtime() for
     *                                      semantics.
     */
    public function buy_cable($smartcard_number, $plan_id, $provider, $client_reference = null) {
        return $this->make_request('POST', '/billing/cable-subscription', $this->with_client_reference([
            'smartcard_number' => $smartcard_number,
            'plan_id' => $plan_id,
            'provider' => $provider,
        ], $client_reference));
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
     *
     * @param string      $phone
     * @param string      $plan_id
     * @param string      $network
     * @param string|null $client_reference Item E. See buy_airtime() for
     *                                      semantics.
     */
    public function buy_data($phone, $plan_id, $network, $client_reference = null) {
        return $this->make_request('POST', '/billing/data-bundle', $this->with_client_reference([
            'phone' => $phone,
            'plan_id' => $plan_id,
            'network' => $network,
        ], $client_reference));
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
     *
     * @param string      $meter_number
     * @param float       $amount
     * @param string      $disco
     * @param string      $meter_type
     * @param string|null $client_reference Item E. See buy_airtime() for
     *                                      semantics.
     */
    public function buy_electricity($meter_number, $amount, $disco, $meter_type, $client_reference = null) {
        return $this->make_request('POST', '/billing/electricity', $this->with_client_reference([
            'meter_number' => $meter_number,
            'amount' => floatval($amount),
            'disco' => $disco,
            'meter_type' => $meter_type,
        ], $client_reference));
    }

    /**
     * Add the client_reference to a Fintava request body under both
     * snake_case and camelCase keys so the API accepts it regardless
     * of which convention the upstream parser expects.
     *
     * Existing billing endpoints (this class) take snake_case
     * (smartcard_number, plan_id, meter_number); the card endpoints
     * (Matrix_MLM_Fintava_Card) take camelCase (cardMapId, cardBrand).
     * Fintava's billing docs are silent on the idempotency-key field
     * name at time of writing, so we send both — Fintava ignores
     * unknown JSON keys, so the dual-key emit costs us nothing and
     * gives us robustness against either internal convention.
     *
     * NULL / empty reference is a no-op so legacy callers (none in
     * tree, but the public buy_* methods take a nullable param) keep
     * the pre-E payload shape exactly.
     *
     * @param array       $body
     * @param string|null $client_reference
     * @return array Body with both keys added (or unchanged if null).
     */
    private function with_client_reference(array $body, $client_reference) {
        if ($client_reference === null || $client_reference === '') {
            return $body;
        }
        $body['client_reference'] = (string) $client_reference;
        $body['clientReference']  = (string) $client_reference;
        return $body;
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

        // Transaction PIN gate (PR 2). All four bill handlers
        // (airtime / data / cable / electricity) gate on the same
        // 'bills' path key — admins toggle them as a single
        // bucket on Settings → Financial. Runs AFTER the per-
        // category kill switch above so disabled categories don't
        // waste a PIN-verify slot on the user's throttle, and
        // BEFORE process_purchase() debits the wallet or writes
        // the pending-transaction row, so a wrong PIN here costs
        // zero DB rows and zero outbound Fintava calls.
        Matrix_MLM_Transaction_Pin::require_pin_for_request($user_id, 'bills');

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

        // Hand off to the centralised lifecycle (debit -> begin
        // pending row -> upstream call -> finalize). The closure
        // captures the per-category upstream call shape so the
        // helper can stay generic.
        $outcome = $this->process_purchase(
            'airtime',
            round($amount, 2),
            $user_id,
            sprintf(__('Airtime purchase to %s', 'matrix-mlm'), $phone),
            ['phone' => $phone, 'network' => $network],
            function ($nominal, $client_reference) use ($phone, $network) {
                return $this->buy_airtime($phone, $nominal, $network, $client_reference);
            }
        );

        $this->respond_to_outcome(
            $outcome,
            'airtime',
            sprintf(
                /* translators: %1$s: currency symbol, %2$s: nominal amount, %3$s: phone */
                __('%1$s%2$s airtime sent to %3$s.', 'matrix-mlm'),
                get_option('matrix_mlm_currency_symbol', '₦'),
                number_format(round($amount, 2), 2),
                $phone
            )
        );
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

        // Transaction PIN gate (PR 2). Shared 'bills' path key —
        // see ajax_buy_airtime for the full rationale.
        Matrix_MLM_Transaction_Pin::require_pin_for_request($user_id, 'bills');

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

        $outcome = $this->process_purchase(
            'data',
            round($amount, 2),
            $user_id,
            sprintf(__('Data bundle to %s', 'matrix-mlm'), $phone),
            ['phone' => $phone, 'network' => $network, 'plan_id' => $plan_id],
            function ($nominal, $client_reference) use ($phone, $plan_id, $network) {
                return $this->buy_data($phone, $plan_id, $network, $client_reference);
            }
        );

        $this->respond_to_outcome($outcome, 'data', __('Data bundle purchased successfully!', 'matrix-mlm'));
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

        // Transaction PIN gate (PR 2). Shared 'bills' path key —
        // see ajax_buy_airtime for the full rationale.
        Matrix_MLM_Transaction_Pin::require_pin_for_request($user_id, 'bills');

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

        $outcome = $this->process_purchase(
            'cable',
            round($amount, 2),
            $user_id,
            sprintf(__('Cable subscription %s', 'matrix-mlm'), $provider),
            ['smartcard' => $smartcard, 'provider' => $provider, 'plan_id' => $plan_id],
            function ($nominal, $client_reference) use ($smartcard, $plan_id, $provider) {
                return $this->buy_cable($smartcard, $plan_id, $provider, $client_reference);
            }
        );

        $this->respond_to_outcome($outcome, 'cable', __('Cable subscription purchased successfully!', 'matrix-mlm'));
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

        // Transaction PIN gate (PR 2). Shared 'bills' path key —
        // see ajax_buy_airtime for the full rationale.
        Matrix_MLM_Transaction_Pin::require_pin_for_request($user_id, 'bills');

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

        $outcome = $this->process_purchase(
            'electricity',
            round($amount, 2),
            $user_id,
            sprintf(__('Electricity purchase for meter %s', 'matrix-mlm'), $meter_number),
            ['meter' => $meter_number, 'disco' => $disco, 'type' => $meter_type],
            function ($nominal, $client_reference) use ($meter_number, $disco, $meter_type) {
                return $this->buy_electricity($meter_number, $nominal, $disco, $meter_type, $client_reference);
            }
        );

        // Electricity is the one category whose success message
        // surfaces an upstream-issued field (the prepaid token).
        // respond_to_outcome handles the standard pending/error
        // paths; on success we tack the token on to the success
        // payload here.
        $this->respond_to_outcome(
            $outcome,
            'electricity',
            __('Electricity purchased successfully!', 'matrix-mlm'),
            function ($api_response) {
                $token = $api_response['data']['token'] ?? $api_response['token'] ?? '';
                return [
                    'token' => $token,
                    'message_suffix' => $token !== '' ? ' Token: ' . $token : '',
                ];
            }
        );
    }

    // =========================================================================
    // INTERNAL: PURCHASE LIFECYCLE (item E)
    // =========================================================================

    /**
     * Run the full debit -> upstream call -> finalize cycle for one
     * bill purchase. Returns a structured outcome that callers
     * (typically the four ajax_buy_<type> handlers) hand to
     * respond_to_outcome() for the JSON response.
     *
     * Outcome shapes:
     *
     *   ['kind' => 'wallet_error',  'wp_error'   => WP_Error]
     *      Wallet debit refused (insufficient balance, locked, etc).
     *      Nothing else has happened — no row written, no upstream
     *      call. Caller surfaces the WP_Error message.
     *
     *   ['kind' => 'begin_failed',  'wp_error'   => WP_Error]
     *      Wallet was debited but the pending row INSERT failed.
     *      We've already issued a compensating refund. Caller
     *      surfaces a generic "internal error" — the underlying
     *      WP_Error (typically a UNIQUE collision, see
     *      build_client_reference's docblock for the odds) is in
     *      the error log for ops.
     *
     *   ['kind' => 'http_error',    'wp_error'   => WP_Error,
     *    'transaction_id' => int,   'amounts'    => [...]]
     *      Fintava returned 4xx/5xx with a body. The transaction
     *      row is now `failed` and the wallet has been refunded.
     *      Caller surfaces the upstream error message.
     *
     *   ['kind' => 'transport_error', 'wp_error' => WP_Error,
     *    'transaction_id' => int,    'amounts'  => [...],
     *    'client_reference' => string]
     *      The HTTP request itself failed (timeout, DNS, connection
     *      refused). We DON'T know whether Fintava processed the
     *      call. The row is left `pending`, the wallet stays
     *      debited. Caller surfaces the "verifying" message — a
     *      reconciliation worker (out of scope) will resolve later.
     *      THIS IS THE CENTRAL CORRECTNESS CHANGE OF ITEM E.
     *
     *   ['kind' => 'success', 'transaction_id' => int,
     *    'api_response' => array, 'amounts' => [...]]
     *      Fintava returned 2xx. Row is `completed`, completed_at
     *      stamped, api_response persisted.
     *
     * The `amounts` map is always {nominal, fee, total} so the
     * caller can format consistent success / status messages.
     *
     * @param string   $type             One of self::BILL_CATEGORIES.
     * @param float    $nominal          The bill amount (already rounded 2dp).
     * @param int      $user_id          The matrix user.
     * @param string   $debit_description Wallet auditor description.
     * @param array    $details          Per-category details (phone, meter, etc.).
     *                                   Stored on the transactions row's JSON.
     * @param callable $api_call         function($nominal, $client_reference) -> array|WP_Error
     */
    private function process_purchase($type, $nominal, $user_id, $debit_description, array $details, callable $api_call) {
        $fee   = self::compute_service_fee($type, $nominal);
        $total = round($nominal + $fee, 2);

        // 1. Move the full total from the user's Fintava virtual wallet
        //    into the merchant master/operating wallet. The /billing/<type>
        //    call below debits the merchant master wallet, so funding it
        //    from the user's virtual wallet first is what makes the user
        //    the actual payer. The fee accrues on the merchant wallet as
        //    the gap between this `total` debit and the `nominal` upstream
        //    debit.
        $debit = self::debit_via_virtual_wallet($user_id, $total, $type, $debit_description);
        if (is_wp_error($debit)) {
            // Special-case the insufficient-balance error: rebuild
            // it with a fee-aware message that surfaces the
            // breakdown (nominal / fee / total / balance / shortfall).
            // The default "Insufficient Fintava virtual wallet
            // balance." is correct but misleading when the user can
            // plainly see their wallet has more than the bill amount
            // but is being told it's not enough — that's the symptom
            // of a too-large flat-fee config, and a breakdown lets
            // the user (and ops) diagnose it immediately rather than
            // guess.
            if ($debit->get_error_code() === 'insufficient_balance') {
                $err_data = $debit->get_error_data();
                $balance  = is_array($err_data) && isset($err_data['balance'])
                    ? (float) $err_data['balance']
                    : 0.0;
                $debit = new WP_Error(
                    'insufficient_balance',
                    self::format_insufficient_balance_message(
                        $balance, $nominal, $fee, $total
                    ),
                    $err_data
                );
            }
            return [
                'kind'     => 'wallet_error',
                'wp_error' => $debit,
                'amounts'  => ['nominal' => $nominal, 'fee' => $fee, 'total' => $total],
            ];
        }
        $debit_reference = $debit;
        $details['debit_ref']     = $debit_reference;
        $details['source_wallet'] = 'virtual';

        // 2. Generate the idempotency reference and INSERT a
        //    `pending` row BEFORE the upstream call. The unique
        //    index on client_reference makes a re-submit (e.g. an
        //    AJAX retry triggered by a transient-error message)
        //    fail loudly at the DB layer if the same reference is
        //    re-used — and a fresh reference per invocation makes
        //    that essentially never happen in practice. The row
        //    being persisted before the call is what lets the
        //    reconciliation worker find pending rows after a
        //    transport failure.
        $client_reference = self::build_client_reference($type, $user_id);
        $tx_id = $this->begin_transaction(
            $user_id,
            $type,
            ['nominal' => $nominal, 'fee' => $fee, 'total' => $total],
            $details,
            $client_reference,
            'virtual'
        );
        if ($tx_id === 0) {
            // Row insert failed (DB error or, vanishingly, UNIQUE
            // collision on client_reference). The wallet was
            // debited; compensate so the user is made whole, then
            // surface a generic error.
            //
            // Capture the begin_transaction support-reference
            // token BEFORE refund_failed_purchase() runs — that
            // helper performs a wallet credit (its own DB write)
            // which can clobber $wpdb->last_error, and we want
            // the token in the user-visible error to point at
            // the begin_transaction failure, not at any later
            // wallet-side noise.
            $support_ref = $this->last_begin_support_ref;
            self::refund_failed_purchase(
                $user_id, $total, $type, $debit_reference, 'virtual',
                __('Internal error: could not record transaction.', 'matrix-mlm')
            );
            // The token is null only on the defensive code path
            // where begin_transaction() didn't get to mint one
            // (shouldn't happen given the current implementation,
            // but a future refactor that returns 0 from a
            // pre-INSERT validation branch would land here). Fall
            // back to the legacy generic message in that case so
            // the user still gets actionable copy.
            $message = $support_ref
                ? sprintf(
                    /* translators: %s: short opaque support-reference token (8 alphanumeric chars), e.g. "Ax7K2pQs". */
                    __('Internal error. Please try again. Reference: %s', 'matrix-mlm'),
                    $support_ref
                )
                : __('Internal error. Please try again.', 'matrix-mlm');
            return [
                'kind'     => 'begin_failed',
                'wp_error' => new WP_Error('begin_failed', $message),
                'amounts'  => ['nominal' => $nominal, 'fee' => $fee, 'total' => $total],
            ];
        }

        // 3. Make the upstream call. The closure captures the
        //    per-category buy_<type>() invocation.
        $result = $api_call($nominal, $client_reference);

        // 4. Branch on outcome. Transport-level errors (the
        //    `http_request_failed` code) leave the row pending and
        //    the wallet debited. HTTP-level errors (everything
        //    else) flip the row to failed and refund the wallet.
        //    See the file-level docblock for why this distinction
        //    is the central correctness change of E.
        if (is_wp_error($result)) {
            if ($this->is_transport_error($result)) {
                $this->mark_pending_with_error($tx_id, $result);
                return [
                    'kind'             => 'transport_error',
                    'wp_error'         => $result,
                    'transaction_id'   => $tx_id,
                    'client_reference' => $client_reference,
                    'amounts'          => ['nominal' => $nominal, 'fee' => $fee, 'total' => $total],
                ];
            }
            $this->fail_transaction($tx_id, $result);
            self::refund_failed_purchase(
                $user_id, $total, $type, $debit_reference, 'virtual', $result->get_error_message()
            );
            return [
                'kind'           => 'http_error',
                'wp_error'       => $result,
                'transaction_id' => $tx_id,
                'amounts'        => ['nominal' => $nominal, 'fee' => $fee, 'total' => $total],
            ];
        }

        // 5. Success. Finalize the row.
        $this->complete_transaction($tx_id, $result);

        // Append a row to matrix_wallet so this bill purchase
        // shows up in the user's unified Transaction History on
        // the Wallet page. The actual debit landed on the user's
        // Fintava virtual wallet via the wallet-to-wallet step at
        // (1), so we use record_ledger() — that helper writes the
        // audit row WITHOUT touching matrix_user_meta.balance,
        // which is exactly what we want for a non-Matrix-wallet
        // movement. Without this call, bill purchases stayed
        // invisible to the user's transaction history (only
        // matrix_billing_transactions saw them, and that table
        // is admin-only).
        //
        // post_balance is read back from the user's virtual
        // wallet after the debit landed. Best-effort: if the
        // balance probe fails for transient reasons, fall through
        // to 0.0 so the row still records (a single zero-stamped
        // post_balance is preferable to dropping the audit row).
        $post_balance = 0.0;
        $fintava_for_balance = new Matrix_MLM_Fintava();
        $virtual_wallet_row = $fintava_for_balance->get_user_wallet($user_id);
        if ($virtual_wallet_row) {
            $balance_after = $fintava_for_balance->get_virtual_wallet_balance(
                $virtual_wallet_row->wallet_id ?? '',
                $virtual_wallet_row->account_number ?? null,
                $virtual_wallet_row->customer_id ?? null
            );
            if (!is_wp_error($balance_after)
                && isset($balance_after['available_balance'])
                && is_numeric($balance_after['available_balance'])) {
                $post_balance = (float) $balance_after['available_balance'];
            }
        }

        $matrix_wallet_logger = new Matrix_MLM_Wallet();
        $matrix_wallet_logger->record_ledger(
            $user_id,
            (float) $total,
            'debit',
            'bill_' . preg_replace('/[^a-z0-9_]/', '_', strtolower((string) $type)),
            $debit_description . ' (from Virtual Wallet)',
            $post_balance,
            $debit_reference
        );

        return [
            'kind'           => 'success',
            'transaction_id' => $tx_id,
            'api_response'   => is_array($result) ? $result : [],
            'amounts'        => ['nominal' => $nominal, 'fee' => $fee, 'total' => $total],
        ];
    }

    /**
     * Format the outcome of process_purchase() into a JSON response
     * and emit it via wp_send_json_*. Centralises the success-message
     * shape (including the optional service-fee disclosure) so the
     * four ajax_buy_<type> handlers stay short.
     *
     * @param array         $outcome           The structured outcome
     *                                         from process_purchase().
     * @param string        $type              Category — used in the
     *                                         pending-row fallback
     *                                         message.
     * @param string        $success_message   Category-specific
     *                                         success line (e.g.
     *                                         "Cable subscription
     *                                         purchased successfully!").
     * @param callable|null $extra_success     Optional closure that
     *                                         can derive extra
     *                                         payload fields and a
     *                                         message-suffix from the
     *                                         api_response. Used by
     *                                         electricity to surface
     *                                         the prepaid token.
     */
    private function respond_to_outcome(array $outcome, $type, $success_message, ?callable $extra_success = null) {
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $amounts  = $outcome['amounts'] ?? ['nominal' => 0, 'fee' => 0, 'total' => 0];

        switch ($outcome['kind']) {
            case 'wallet_error':
            case 'begin_failed':
            case 'http_error':
                /** @var WP_Error $err */
                $err = $outcome['wp_error'];
                // Belt-and-braces: WP_Error::get_error_message()
                // returns whatever was stored, including arrays /
                // objects when a caller mis-set the message slot.
                // make_request() now stringifies upstream-API
                // messages at their boundary, so this branch
                // normally receives a clean string — but if a
                // future code path constructs a WP_Error with a
                // non-string message (or a third-party plugin
                // hooks our error filter and inserts one), the
                // user must NOT see "[object Object]" again.
                $message = $err->get_error_message();
                $message = self::stringify_api_message(
                    $message,
                    __('An unexpected error occurred. Please try again.', 'matrix-mlm')
                );
                wp_send_json_error(['message' => $message]);
                return;

            case 'transport_error':
                // The user's wallet IS still debited — make sure the
                // surfaced message reflects that. The "your Fintava
                // virtual wallet will be refunded if it didn't go
                // through" wording sets the right expectation:
                // reconciliation may refund, but it also may finalize
                // the purchase as completed.
                wp_send_json_success([
                    'message' => __('Your purchase is being verified. Please check Bill Payments History in a few minutes — your Fintava virtual wallet will be refunded if the purchase did not go through.', 'matrix-mlm'),
                    'pending' => true,
                    'transaction_id'   => $outcome['transaction_id'],
                    'client_reference' => $outcome['client_reference'],
                    'nominal' => $amounts['nominal'],
                    'fee'     => $amounts['fee'],
                    'total'   => $amounts['total'],
                ]);
                return;

            case 'success':
                $message = $success_message;
                if ($amounts['fee'] > 0) {
                    $message .= ' ' . sprintf(
                        /* translators: %1$s: currency symbol + total, %2$s: currency symbol + fee */
                        __('Fintava virtual wallet debited %1$s (includes %2$s service fee).', 'matrix-mlm'),
                        $currency . number_format($amounts['total'], 2),
                        $currency . number_format($amounts['fee'], 2)
                    );
                } else {
                    $message .= ' ' . __('(debited from your Fintava virtual wallet)', 'matrix-mlm');
                }

                $payload = [
                    'message'        => $message,
                    'transaction_id' => $outcome['transaction_id'],
                    'nominal'        => $amounts['nominal'],
                    'fee'            => $amounts['fee'],
                    'total'          => $amounts['total'],
                ];
                if ($extra_success !== null) {
                    $extra = $extra_success($outcome['api_response']);
                    if (is_array($extra)) {
                        if (!empty($extra['message_suffix'])) {
                            $payload['message'] .= $extra['message_suffix'];
                            unset($extra['message_suffix']);
                        }
                        $payload = array_merge($payload, $extra);
                    }
                }

                // In-app notification (1.0.15) — sender-side. Bills
                // payment is a one-sided action (no receiver to
                // notify; the merchant is paid out-of-band by the
                // gateway), so this is the only notification this
                // path emits. Surfaces in the dashboard bell so a
                // user can prove from their notification history
                // that the airtime/data/cable/electricity purchase
                // succeeded, even after the on-form toast disappears.
                if (class_exists('Matrix_MLM_In_App_Notifications')) {
                    $type_label = ucfirst(strtolower((string) $type));
                    $amount_str = $currency . number_format((float) $amounts['total'], 2);
                    Matrix_MLM_In_App_Notifications::enqueue(
                        get_current_user_id(),
                        'bill_payment_' . preg_replace('/[^a-z0-9_]/', '_', strtolower((string) $type)),
                        sprintf(
                            /* translators: 1: bill type, 2: total amount */
                            __('%1$s purchase: %2$s', 'matrix-mlm'),
                            $type_label,
                            $amount_str
                        ),
                        $message,
                        '/matrix-dashboard/billing/',
                        [
                            'type'           => (string) $type,
                            'transaction_id' => isset($outcome['transaction_id']) ? (int) $outcome['transaction_id'] : 0,
                            'nominal'        => (float) $amounts['nominal'],
                            'fee'            => (float) $amounts['fee'],
                            'total'          => (float) $amounts['total'],
                        ]
                    );
                }
                wp_send_json_success($payload);
                return;
        }

        // Defensive fallback — should never run given the cases above.
        wp_send_json_error(['message' => __('Unknown purchase state.', 'matrix-mlm')]);
    }

    /**
     * Decide whether a WP_Error returned from a Fintava call is a
     * transport-level failure (timeout / DNS / connection refused)
     * versus an HTTP-level failure (4xx / 5xx with a body).
     *
     * Transport-level: leave the row pending, do NOT refund. We
     *   don't know whether Fintava processed the call.
     * HTTP-level: flip the row to failed, refund the wallet.
     *   Fintava said "no" with a clear status code.
     *
     * `wp_remote_request` is documented to return WP_Error with code
     * `http_request_failed` for cURL-level errors. WordPress has
     * historically been consistent on this — the inner cURL error
     * code is exposed via $err->get_error_data() — but we match by
     * code to keep the logic readable.
     *
     * `fintava_billing_error` is the code make_request() emits when
     * Fintava returns a 4xx/5xx with a JSON envelope; that's an
     * HTTP-level error.
     *
     * @param WP_Error $err
     * @return bool TRUE iff the row should stay pending.
     */
    private function is_transport_error(WP_Error $err) {
        return $err->get_error_code() === 'http_request_failed';
    }

    /**
     * INSERT a `pending` row on matrix_billing_transactions BEFORE
     * the upstream Fintava call. Carries the client_reference so a
     * reconciliation worker can later cross-reference the row
     * against Fintava's record.
     *
     * Returns the row's id, or 0 on failure (typically a UNIQUE
     * collision on client_reference, which is essentially
     * impossible — see build_client_reference's docblock — but the
     * caller compensates anyway).
     *
     * Item E: replaces the old log_transaction() insert, which
     * happened post-success and unconditionally wrote
     * status='completed'. The pre-call insert is what makes the
     * row visible to the reconciliation worker before any upstream
     * outcome is known.
     *
     * @param int    $user_id
     * @param string $type
     * @param array  $amounts {nominal, fee, total}
     * @param array  $details Per-category map serialised to the
     *                       details JSON column.
     * @param string $client_reference Generated by build_client_reference.
     * @param string $source_wallet    Which wallet was debited.
     *                                 'virtual' (default for the
     *                                 post-PR-#294 path) or
     *                                 'matrix' (legacy / kept so a
     *                                 future caller restoring the
     *                                 internal-wallet path doesn't
     *                                 silently mis-tag rows).
     * @return int row id or 0 on failure
     */
    private function begin_transaction($user_id, $type, array $amounts, array $details, $client_reference, $source_wallet = 'virtual') {
        global $wpdb;

        $nominal = round((float) ($amounts['nominal'] ?? 0), 2);
        $fee     = round((float) ($amounts['fee']     ?? 0), 2);
        $total   = round((float) ($amounts['total']   ?? ($nominal + $fee)), 2);

        // Normalise: only the two known values are accepted; anything
        // else falls back to the post-PR-#294 default. Defensive
        // against a typo in a future caller (e.g. 'fintava' instead
        // of 'virtual') that would otherwise sneak past the column's
        // ENUM constraint and land as the empty string.
        if (!in_array($source_wallet, ['matrix', 'virtual'], true)) {
            $source_wallet = 'virtual';
        }

        $table   = $wpdb->prefix . 'matrix_billing_transactions';
        $payload = [
            'user_id'          => (int) $user_id,
            'type'             => (string) $type,
            // Legacy `amount` column kept in sync with nominal so a
            // third-party reader that hardcoded `SELECT amount`
            // continues to see the user-facing bill amount.
            'amount'           => $nominal,
            'nominal_amount'   => $nominal,
            'service_fee'      => $fee,
            'total_charged'    => $total,
            'details'          => json_encode($details),
            'api_response'     => null,
            'status'           => 'pending',
            'client_reference' => (string) $client_reference,
            'source_wallet'    => (string) $source_wallet,
            'created_at'       => current_time('mysql'),
        ];

        $inserted = $wpdb->insert($table, $payload);

        // Schema-drift self-heal: if the INSERT failed AND the
        // $wpdb error matches one of the canonical schema-drift
        // signatures (column missing, status enum value missing,
        // table missing entirely), force-run create_table() and
        // retry the INSERT once. Items C/D/E each added new
        // columns and enum values; a copy-deploy that hadn't
        // triggered Matrix_MLM_Database::create_tables() (e.g.
        // FTP-replaced plugin files but never opened a Matrix
        // admin page) lands here today with the user-visible
        // "Internal error" message and no usable diagnostic.
        //
        // Heuristic intentionally narrow:
        //   - "Unknown column 'X' in 'field list'" — C/D/E added
        //     a column the table doesn't have yet.
        //   - "Data truncated for column 'status' at row 1" — E
        //     widened the status enum to include 'pending'; the
        //     pre-E table still has the narrow enum.
        //   - "Table '<db>.<prefix>matrix_billing_transactions'
        //     doesn't exist" — fresh install where activation
        //     didn't run (e.g. plugin deployed via mu-plugins
        //     drop-in that bypasses register_activation_hook).
        //
        // Anything else (deadlock, charset issue, FK violation,
        // disk full, UNIQUE collision on client_reference) takes
        // the legacy fail-and-refund path: re-running dbDelta
        // wouldn't help and could mask a real bug.
        //
        // Single retry only — no recursion. If create_table()
        // can't fix the underlying cause (e.g. the DB user lacks
        // ALTER permission), the second insert fails the same
        // way and we surface the error normally.
        if ($inserted === false && self::looks_like_schema_drift((string) $wpdb->last_error)) {
            $drift_error = (string) $wpdb->last_error;
            error_log(sprintf(
                '[Matrix Fintava Billing] begin_transaction schema-drift detected user_id=%d type=%s last_error=%s; running create_table() and retrying once',
                (int) $user_id, $type, $drift_error
            ));
            self::create_table();
            $inserted = $wpdb->insert($table, $payload);
            if ($inserted !== false) {
                error_log(sprintf(
                    '[Matrix Fintava Billing] begin_transaction self-heal succeeded user_id=%d type=%s ref=%s tx_id=%d',
                    (int) $user_id, $type, $client_reference, (int) $wpdb->insert_id
                ));
            }
        }

        if ($inserted === false) {
            $last_error = (string) $wpdb->last_error;
            // Existing structured-context line — preserves any ops
            // grep tooling that keys off the [Matrix Fintava Billing]
            // tag and the user_id / type / ref columns.
            error_log(sprintf(
                '[Matrix Fintava Billing] begin_transaction failed user_id=%d type=%s ref=%s last_error=%s',
                (int) $user_id, $type, $client_reference, $last_error
            ));
            // Mint a short opaque token that goes into the
            // user-visible "Internal error" message and a paired
            // [Matrix MLM DB] log line carrying the same $wpdb
            // error. A user reporting the message via a support
            // ticket quotes the token; ops greps the log to
            // recover the real cause without bouncing through
            // user_id / timestamp triangulation.
            $this->last_begin_support_ref = Matrix_MLM_DB_Error::log_and_token(
                'fintava_billing.begin_transaction',
                $last_error
            );
            return 0;
        }
        $this->last_begin_support_ref = null;
        return (int) $wpdb->insert_id;
    }

    /**
     * Heuristic: does this $wpdb->last_error string look like a
     * schema-drift / missing-table issue that re-running
     * create_table() (the idempotent dbDelta migration) would fix?
     *
     * Returns TRUE for the canonical MySQL/MariaDB error texts
     * emitted when the live schema is missing a column / enum
     * value / the entire table that the current code expects:
     *   - "Unknown column 'X' in 'field list'"
     *   - "Table '<db>.<table>' doesn't exist"
     *   - "Data truncated for column 'status' at row 1"
     *
     * Any other error returns FALSE so the caller takes the
     * legacy fail-and-refund path. Re-running dbDelta on a
     * deadlock / charset / FK / disk-full failure wouldn't help
     * and could mask a real bug — the regex is deliberately
     * narrow.
     *
     * Case-insensitive match because some MariaDB locales return
     * the message with different capitalisation than upstream
     * MySQL ("doesn't exist" vs "Doesn't exist"). The English
     * driver text is the same across locales — the texts are not
     * localised by the server, only formatted — so a literal-text
     * match is portable enough for this use.
     *
     * @param string $wpdb_error Raw $wpdb->last_error value.
     * @return bool TRUE iff the caller should retry after
     *              create_table(); FALSE for any other failure.
     */
    private static function looks_like_schema_drift($wpdb_error) {
        if ($wpdb_error === '') {
            return false;
        }
        return (bool) preg_match(
            '/(unknown column|doesn\'t exist|data truncated for column)/i',
            $wpdb_error
        );
    }

    /**
     * UPDATE a pending row to `completed` after a successful
     * Fintava call. Stamps completed_at and persists the upstream
     * response so support has the wire data without rebuilding
     * the request.
     *
     * Idempotent at the DB layer — if the row has already been
     * finalized (e.g. by a future reconciliation worker that ran
     * concurrently), the WHERE status='pending' clause will match
     * zero rows and we no-op. We log the no-op because it should
     * never happen in the synchronous path, only with the
     * reconciler in the loop.
     */
    private function complete_transaction($tx_id, $api_response) {
        global $wpdb;
        $rows = $wpdb->update(
            $wpdb->prefix . 'matrix_billing_transactions',
            [
                'status'       => 'completed',
                'completed_at' => current_time('mysql'),
                'api_response' => json_encode($api_response),
            ],
            ['id' => (int) $tx_id, 'status' => 'pending'],
            ['%s', '%s', '%s'],
            ['%d', '%s']
        );
        if ($rows !== 1) {
            error_log(sprintf(
                '[Matrix Fintava Billing] complete_transaction expected 1 row, got %s tx_id=%d',
                var_export($rows, true), (int) $tx_id
            ));
        }
    }

    /**
     * UPDATE a pending row to `failed` after Fintava returned an
     * HTTP-level error (4xx/5xx with body). The wallet refund is
     * the caller's responsibility — this just records the row's
     * post-call state and the upstream response for support.
     *
     * Same idempotent-update pattern as complete_transaction().
     */
    private function fail_transaction($tx_id, WP_Error $err) {
        global $wpdb;
        $rows = $wpdb->update(
            $wpdb->prefix . 'matrix_billing_transactions',
            [
                'status'       => 'failed',
                'api_response' => json_encode([
                    'error_code'    => $err->get_error_code(),
                    'error_message' => $err->get_error_message(),
                    'error_data'    => $err->get_error_data(),
                ]),
            ],
            ['id' => (int) $tx_id, 'status' => 'pending'],
            ['%s', '%s'],
            ['%d', '%s']
        );
        if ($rows !== 1) {
            error_log(sprintf(
                '[Matrix Fintava Billing] fail_transaction expected 1 row, got %s tx_id=%d',
                var_export($rows, true), (int) $tx_id
            ));
        }
    }

    /**
     * Persist the transport-level WP_Error onto a pending row's
     * api_response WITHOUT changing its status. Used after a
     * transport-level failure — the row stays pending so a
     * reconciliation worker can finalize it later, but we still
     * want the error data on disk for ops to grep.
     *
     * Same idempotency note as complete/fail_transaction.
     */
    private function mark_pending_with_error($tx_id, WP_Error $err) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'matrix_billing_transactions',
            [
                'api_response' => json_encode([
                    'transport_error_code' => $err->get_error_code(),
                    'transport_error_msg'  => $err->get_error_message(),
                    'transport_error_data' => $err->get_error_data(),
                    'recorded_at'          => current_time('mysql'),
                ]),
            ],
            ['id' => (int) $tx_id, 'status' => 'pending'],
            ['%s'],
            ['%d', '%s']
        );
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
     * Build a fee-aware "insufficient balance" message that surfaces
     * the full breakdown (balance / nominal / fee / total / shortfall)
     * so the user — and ops, if the user copies it into a support
     * ticket — can see at a glance whether the gap is genuine
     * (small shortfall) or symptomatic of a misconfigured fee
     * (huge fee, e.g. flat=14000 typo on airtime).
     *
     * Wording references the user's Fintava virtual wallet (the
     * post-PR-#294 source of funds) so a user who reads "your
     * wallet" doesn't conflate it with the Matrix internal wallet
     * — the two were kept conceptually distinct precisely to make
     * "fund Fintava → buy bill" a one-step flow.
     *
     * Branches on the relationship between fee, nominal, and balance:
     *
     *   - fee == 0      : pre-C single-amount message — same shape
     *                     as the legacy error, just enriched with
     *                     the actual balance and shortfall.
     *
     *   - fee > nominal : the fee is larger than the bill itself,
     *                     which is a strong signal that the markup
     *                     option is misconfigured. The message
     *                     explicitly flags it as "unusually high"
     *                     and asks the user to contact support
     *                     before adding funds — protects the user
     *                     from depositing more money to satisfy a
     *                     fee that shouldn't have been charged.
     *
     *   - otherwise     : standard "you're a bit short" message
     *                     with the breakdown.
     *
     * The "fee > nominal" heuristic catches the user-reported
     * report verbatim (₦100 airtime + ₦14,000 fee) without flagging
     * legitimate small-bill flat fees (₦100 + ₦20 fee = false; ₦50
     * + ₦150 fee = TRUE, which is correct because that's also
     * unusual and worth flagging).
     */
    private static function format_insufficient_balance_message($balance, $nominal, $fee, $total) {
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $shortfall = max(0.0, round((float) $total - (float) $balance, 2));
        $fmt = function ($v) use ($currency) {
            return $currency . number_format((float) $v, 2);
        };

        if ($fee <= 0) {
            return sprintf(
                /* translators: 1: balance, 2: bill amount, 3: shortfall */
                __('Your Fintava virtual wallet has %1$s but this purchase requires %2$s. Add %3$s to continue.', 'matrix-mlm'),
                $fmt($balance), $fmt($nominal), $fmt($shortfall)
            );
        }

        if ($fee > $nominal) {
            return sprintf(
                /* translators: 1: balance, 2: total to debit, 3: bill amount, 4: service fee, 5: shortfall */
                __('Your Fintava virtual wallet has %1$s but this purchase costs %2$s — that\'s %3$s for the bill plus a %4$s service fee. The service fee looks unusually high; please contact support if this is unexpected. Otherwise, add %5$s to continue.', 'matrix-mlm'),
                $fmt($balance), $fmt($total), $fmt($nominal), $fmt($fee), $fmt($shortfall)
            );
        }

        return sprintf(
            /* translators: 1: balance, 2: total to debit, 3: bill amount, 4: service fee, 5: shortfall */
            __('Your Fintava virtual wallet has %1$s but this purchase costs %2$s (%3$s bill + %4$s service fee). Add %5$s to continue.', 'matrix-mlm'),
            $fmt($balance), $fmt($total), $fmt($nominal), $fmt($fee), $fmt($shortfall)
        );
    }

    /**
     * Move the bill total from the user's Fintava virtual wallet
     * into the merchant's master/operating wallet via
     * /transaction/wallet-to-wallet, so the upstream /billing/<type>
     * call (which debits the merchant master wallet server-side)
     * is reimbursed by the user before it runs.
     *
     * Returns a unique reference string on success, or WP_Error on
     * failure. The most common failure modes:
     *
     *   - no_virtual_wallet     : user hasn't generated a Fintava
     *                             virtual wallet yet. The UI gates
     *                             this with a CTA before the form
     *                             renders, but the AJAX handler
     *                             must also defend against direct
     *                             calls.
     *   - missing_operating_account : the merchant operating
     *                             account NUBAN admin setting is
     *                             unset. Configuration error.
     *   - insufficient_balance  : user's virtual wallet balance is
     *                             below `total`. Carries balance +
     *                             requested amount on the WP_Error
     *                             data slot so process_purchase()
     *                             can rebuild a fee-aware message.
     *   - virtual_wallet_debit_failed : Fintava rejected the
     *                             wallet-to-wallet for any other
     *                             reason. The upstream message is
     *                             surfaced verbatim so the user
     *                             (and ops) can see what Fintava
     *                             said.
     *
     * The pre-flight balance check is best-effort: if Fintava's
     * balance endpoint is transiently unavailable on this tier we
     * skip the check and let the wallet-to-wallet itself be the
     * source of truth (its "insufficient balance on source wallet"
     * response is normalised back to insufficient_balance below).
     * The bank-payout flow follows the same pattern for the same
     * reason — a flaky balance endpoint shouldn't strand a working
     * payment flow.
     *
     * Wallet-to-wallet narration carries the same description the
     * caller would have used as the wallet auditor row description
     * pre-PR-#294 ("Airtime purchase to 0801…"), so the user's
     * Fintava transaction history reads naturally without us having
     * to build a separate naming convention.
     *
     * @return string|WP_Error reference identifier
     */
    private static function debit_via_virtual_wallet($user_id, $amount, $type, $description) {
        $fintava = new Matrix_MLM_Fintava();
        $wallet_row = $fintava->get_user_wallet($user_id);
        if (!$wallet_row) {
            return new WP_Error(
                'no_virtual_wallet',
                __('You need a Fintava virtual wallet to pay bills. Open the Wallet tab to set one up.', 'matrix-mlm')
            );
        }

        $sender_account = preg_replace('/\s+/', '', (string) ($wallet_row->account_number ?? ''));
        if ($sender_account === '') {
            return new WP_Error(
                'no_virtual_wallet',
                __('Your Fintava virtual wallet is missing its account number. Refresh it from the Wallet tab and try again.', 'matrix-mlm')
            );
        }

        $receiver_account = preg_replace('/\s+/', '', (string) get_option('matrix_mlm_fintava_operating_account', ''));
        if ($receiver_account === '') {
            // The /transaction/wallet-to-wallet endpoint requires
            // a senderAccount AND a receiverAccount. The wallet-to-
            // wallet helper would surface this same error code, but
            // we surface it here too so a misconfigured install
            // fails before opening a PIN-gated request and burning
            // a counter slot on the rate limiter.
            return new WP_Error(
                'missing_operating_account',
                __('Bill payments are not configured: the merchant operating account is missing. Please contact support.', 'matrix-mlm')
            );
        }

        // Pre-flight balance check. Fail-open if the balance endpoint
        // is unavailable on this tier — the wallet-to-wallet call
        // itself will refuse with an upstream "insufficient balance
        // on source wallet" error which we normalise below. Same
        // pattern as the bank-payout flow.
        $balance_info = $fintava->get_virtual_wallet_balance(
            $wallet_row->wallet_id ?? '',
            $sender_account,
            $wallet_row->customer_id ?? null
        );
        if (!is_wp_error($balance_info) && isset($balance_info['available_balance']) && is_numeric($balance_info['available_balance'])) {
            $available = (float) $balance_info['available_balance'];
            if ($available < $amount) {
                return new WP_Error(
                    'insufficient_balance',
                    __('Insufficient Fintava virtual wallet balance.', 'matrix-mlm'),
                    ['balance' => $available, 'requested' => (float) $amount]
                );
            }
        }

        $reference = 'BILL-' . $type . '-' . $user_id . '-' . wp_generate_uuid4();
        $transfer = $fintava->wallet_to_wallet_transfer([
            'amount'             => floatval($amount),
            'sender_account'     => $sender_account,
            'receiver_account'   => $receiver_account,
            'narration'          => (string) $description,
            'customer_reference' => $reference,
        ]);

        if (is_wp_error($transfer)) {
            $msg = (string) $transfer->get_error_message();
            // Fintava's /transaction/wallet-to-wallet returns
            // "insufficient balance on source wallet to complete
            // transfer" when the user's virtual wallet doesn't
            // cover the requested amount. Normalise it to our
            // insufficient_balance shape so process_purchase()
            // formats the same fee-aware breakdown the pre-flight
            // path produces. Match on the substring "insufficient"
            // so a future tier-specific rewording (e.g.
            // "Insufficient funds on source wallet") still hits.
            // Carry balance=0 so format_insufficient_balance_message
            // produces a "shortfall = total" message — we don't
            // have a definitive balance here (the pre-flight either
            // didn't run or saw a stale value), and 0 is the safe
            // floor.
            if (stripos($msg, 'insufficient') !== false) {
                return new WP_Error(
                    'insufficient_balance',
                    __('Insufficient Fintava virtual wallet balance.', 'matrix-mlm'),
                    ['balance' => 0.0, 'requested' => (float) $amount, 'upstream' => $msg]
                );
            }
            return new WP_Error(
                'virtual_wallet_debit_failed',
                sprintf(
                    /* translators: %s: upstream Fintava error message */
                    __('Could not debit your Fintava virtual wallet: %s', 'matrix-mlm'),
                    $msg
                )
            );
        }

        return $reference;
    }

    /**
     * Reverse a virtual-wallet debit by transferring the same
     * amount from the merchant operating wallet back to the user's
     * Fintava virtual wallet. Used by refund_failed_purchase() on
     * the post-PR-#294 path.
     *
     * Returns TRUE on success, FALSE on any failure. Failures are
     * logged and surfaced via send_admin_notification() because a
     * failed reversal means the user has been debited but not
     * refunded — manual intervention required.
     *
     * The customer_reference is REFUND-{original} so the Fintava
     * audit trail links the refund back to the failing purchase
     * one-to-one.
     */
    private static function reverse_virtual_wallet_debit($user_id, $amount, $type, $original_reference, $reason) {
        $fintava = new Matrix_MLM_Fintava();
        $wallet_row = $fintava->get_user_wallet($user_id);
        if (!$wallet_row) {
            error_log(sprintf(
                '[Matrix Fintava Billing] CRITICAL: cannot reverse virtual-wallet debit, user has no wallet. user_id=%d amount=%.2f ref=%s reason=%s',
                $user_id, $amount, $original_reference, $reason
            ));
            Matrix_MLM_Notifications::send_admin_notification(
                'fintava_bill_refund_failed',
                sprintf(
                    /* translators: 1: user id, 2: amount, 3: debit ref */
                    __('Bill purchase virtual-wallet reversal FAILED for user #%1$d, amount %2$.2f, ref %3$s — user has no Fintava virtual wallet on file. Manual intervention required.', 'matrix-mlm'),
                    $user_id, $amount, $original_reference
                )
            );
            return false;
        }

        $sender_account   = preg_replace('/\s+/', '', (string) get_option('matrix_mlm_fintava_operating_account', ''));
        $receiver_account = preg_replace('/\s+/', '', (string) ($wallet_row->account_number ?? ''));

        if ($sender_account === '' || $receiver_account === '') {
            error_log(sprintf(
                '[Matrix Fintava Billing] CRITICAL: cannot reverse virtual-wallet debit, missing NUBAN. user_id=%d amount=%.2f ref=%s sender=%s receiver=%s',
                $user_id, $amount, $original_reference, $sender_account, $receiver_account
            ));
            Matrix_MLM_Notifications::send_admin_notification(
                'fintava_bill_refund_failed',
                sprintf(
                    __('Bill purchase virtual-wallet reversal FAILED for user #%1$d, amount %2$.2f, ref %3$s — operating account or user wallet NUBAN missing. Manual intervention required.', 'matrix-mlm'),
                    $user_id, $amount, $original_reference
                )
            );
            return false;
        }

        $refund_ref = 'REFUND-' . $original_reference;
        $description = sprintf(
            /* translators: %1$s: bill type, %2$s: failure reason */
            __('Refund: %1$s purchase failed (%2$s)', 'matrix-mlm'),
            $type,
            $reason
        );

        $transfer = $fintava->wallet_to_wallet_transfer([
            'amount'             => floatval($amount),
            'sender_account'     => $sender_account,
            'receiver_account'   => $receiver_account,
            'narration'          => $description,
            'customer_reference' => $refund_ref,
        ]);

        if (is_wp_error($transfer)) {
            error_log(sprintf(
                '[Matrix Fintava Billing] CRITICAL: virtual-wallet reverse-transfer failed user_id=%d amount=%.2f ref=%s reason=%s upstream=%s',
                $user_id, $amount, $original_reference, $reason, $transfer->get_error_message()
            ));
            Matrix_MLM_Notifications::send_admin_notification(
                'fintava_bill_refund_failed',
                sprintf(
                    /* translators: 1: user id, 2: amount, 3: debit ref, 4: upstream error */
                    __('Bill purchase virtual-wallet reversal FAILED for user #%1$d, amount %2$.2f, ref %3$s. Upstream error: %4$s. Manual intervention required.', 'matrix-mlm'),
                    $user_id, $amount, $original_reference, $transfer->get_error_message()
                )
            );
            return false;
        }

        return true;
    }

    /**
     * Refund a failed bill purchase. Dispatches on the
     * `source_wallet` parameter:
     *
     *   - 'matrix'  : credit the user's Matrix internal wallet
     *                 (legacy path; kept so admin-initiated
     *                 refunds and any future reconciliation
     *                 worker can correctly process pre-PR-#294
     *                 rows whose original debit landed there).
     *   - 'virtual' : reverse the wallet-to-wallet transfer back
     *                 to the user's Fintava virtual wallet (the
     *                 post-PR-#294 path).
     *
     * Catastrophic failures (debited-but-cannot-refund) are logged
     * loudly and an admin notification is dispatched so manual
     * reconciliation can happen before the user notices.
     */
    private static function refund_failed_purchase($user_id, $amount, $type, $debit_reference, $source_wallet, $reason) {
        if ($source_wallet === 'virtual') {
            self::reverse_virtual_wallet_debit($user_id, $amount, $type, $debit_reference, $reason);
            return;
        }

        // Legacy path — Matrix internal wallet. Reached only by
        // refund flows operating on rows that were debited before
        // PR #294 flipped the source wallet. Kept verbatim so any
        // reconciliation worker that finalises a stale pending
        // pre-PR-#294 row settles to the wallet that was actually
        // debited.
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
            // Fintava (and several upstream payment APIs) commonly
            // return validation errors as a NESTED structure rather
            // than a string — e.g. `{"message":{"phone":"Invalid
            // phone number"}}` or `{"message":["err1","err2"]}`. The
            // raw value used to be passed straight into WP_Error,
            // which stores it as-is, so get_error_message() returned
            // an array. respond_to_outcome() then sent it through
            // wp_send_json_error(['message' => <array>]) and the
            // browser saw `data.message` as an object — which JS
            // coerced via implicit toString into the literal string
            // "[object Object]" the moment any caller did
            // `alert(r.data.message)`. That was the user-visible
            // "[object Object]" bug on Buy Airtime.
            //
            // Stringify HERE — at the boundary where untrusted
            // upstream JSON enters the WP_Error pipeline — so every
            // downstream consumer (respond_to_outcome, the legacy
            // wp_send_json_error sites in the four ajax_buy_*
            // handlers, the ops log) sees a usable human-readable
            // string. The fallback HTTP-status message is preserved
            // for the (rare) case where Fintava returns no body or
            // an unrecognised shape.
            $message = self::stringify_api_message(
                $body['message'] ?? null,
                sprintf(__('API Error (HTTP %d)', 'matrix-mlm'), $status_code)
            );
            return new WP_Error('fintava_billing_error', $message);
        }

        return $body;
    }

    /**
     * Coerce an upstream API "message" field into a single
     * human-readable string. The Fintava billing endpoints (and many
     * other payment gateways) ship validation errors as nested
     * objects or arrays rather than strings:
     *
     *   - String:        "Insufficient balance"   → kept as-is
     *   - Flat list:     ["err1","err2"]          → "err1; err2"
     *   - Field map:     {"phone":"Bad number"}   → "phone: Bad number"
     *   - Mixed map:     {"phone":["a","b"]}      → "phone: a; b"
     *   - Anything else  (deeply nested, etc.)    → JSON-encoded
     *
     * Returns the supplied $fallback when the message slot is null
     * or empty so call sites don't have to special-case "no body".
     *
     * @param mixed  $raw      The raw `message` value from the upstream JSON body.
     * @param string $fallback Sentinel used when $raw is null/empty.
     * @return string Always a non-empty string.
     */
    private static function stringify_api_message($raw, $fallback) {
        if (is_string($raw)) {
            return $raw !== '' ? $raw : $fallback;
        }
        if ($raw === null) {
            return $fallback;
        }
        if (is_scalar($raw)) {
            // numeric / bool — extremely unlikely from Fintava but
            // cheap to handle.
            return (string) $raw;
        }
        if (is_array($raw)) {
            if (empty($raw)) {
                return $fallback;
            }
            // Detect a flat list of scalars (numeric keys, string
            // values) and join with semicolons.
            $is_list = array_keys($raw) === range(0, count($raw) - 1);
            $parts   = [];
            foreach ($raw as $key => $val) {
                if (is_array($val)) {
                    // One level deeper: flatten a list of strings
                    // (typical Laravel/validation-style "errors":
                    // {"phone":["err1","err2"]}). Anything more
                    // complex falls back to JSON.
                    $sub_is_list = array_keys($val) === range(0, max(0, count($val) - 1));
                    if ($sub_is_list && $val !== [] && self::all_scalars($val)) {
                        $val_str = implode('; ', array_map('strval', $val));
                    } else {
                        $val_str = wp_json_encode($val);
                    }
                } elseif (is_scalar($val)) {
                    $val_str = (string) $val;
                } else {
                    $val_str = wp_json_encode($val);
                }
                $parts[] = $is_list ? $val_str : ($key . ': ' . $val_str);
            }
            $joined = implode('; ', array_filter($parts, function ($s) { return $s !== ''; }));
            return $joined !== '' ? $joined : $fallback;
        }
        // Object or resource — JSON-encode as a last resort.
        $encoded = wp_json_encode($raw);
        return is_string($encoded) && $encoded !== '' ? $encoded : $fallback;
    }

    /**
     * True when every element of $arr is a PHP scalar. Helper for
     * stringify_api_message()'s nested-list flattening.
     */
    private static function all_scalars(array $arr) {
        foreach ($arr as $v) {
            if (!is_scalar($v)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Create / migrate the matrix_billing_transactions table and the
     * sibling matrix_billing_refunds audit table.
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
     * begin_transaction) in lock-step with nominal_amount so any
     * third-party reader that hardcoded `SELECT amount` keeps
     * working until they migrate.
     *
     * Item D (PR #261) added:
     *   - refunded_amount column on matrix_billing_transactions
     *     (per-row cumulative sum of admin-initiated refunds).
     *   - Two new statuses on the status enum: `refunded` and
     *     `partial_refund`.
     *   - matrix_billing_refunds audit table.
     *
     * Item E (this PR) adds:
     *   - client_reference VARCHAR(64) UNIQUE — the idempotency
     *     key sent on the upstream Fintava call. Reconciliation
     *     workers (deferred) use this column to cross-reference a
     *     local pending row with Fintava's record.
     *   - completed_at DATETIME — stamped when a row transitions
     *     from pending to completed. Useful both for ops
     *     timing-data (latency between INSERT and Fintava 2xx) and
     *     for the reconciliation worker's "rows older than X
     *     minutes still pending" probe.
     *   - The status enum's DEFAULT flips from 'completed' to
     *     'pending' so a row that's INSERTed without the
     *     transition logic explicitly setting status (e.g. a
     *     legacy code path or a hand-rolled DB tool) lands in the
     *     safer pending state instead of falsely claiming the
     *     upstream call succeeded.
     */
    public static function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'matrix_billing_transactions';
        $refunds_table = $wpdb->prefix . 'matrix_billing_refunds';

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
            source_wallet enum('matrix','virtual') NOT NULL DEFAULT 'matrix',
            refunded_amount decimal(12,2) NOT NULL DEFAULT 0.00,
            client_reference varchar(64) DEFAULT NULL,
            details text,
            api_response text,
            status enum('pending','completed','failed','refunded','partial_refund') NOT NULL DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY client_reference (client_reference),
            KEY user_id (user_id),
            KEY type (type),
            KEY status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // First-run migration only: seed the C-era columns from the
        // legacy `amount` column on every existing row.
        if ($needs_backfill) {
            $wpdb->query(
                "UPDATE $table
                 SET nominal_amount = amount,
                     total_charged  = amount
                 WHERE nominal_amount = 0
                   AND amount > 0"
            );
        }

        // D-migration step 1: ensure the refunded_amount column
        // exists. Idempotent: COUNT(*) skips the ALTER once the
        // column exists.
        $refunded_col_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
               AND COLUMN_NAME = 'refunded_amount'",
            DB_NAME, $table
        ));
        if ($refunded_col_exists === 0) {
            $wpdb->query(
                "ALTER TABLE {$table}
                   ADD COLUMN refunded_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00
                   AFTER total_charged"
            );
        }

        // D-migration step 2: widen the status enum to include
        // 'refunded' and 'partial_refund'. dbDelta does not
        // reliably extend enum values on an existing column across
        // MySQL versions, so we probe COLUMN_TYPE directly.
        $status_col = $wpdb->get_row($wpdb->prepare(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'status'",
            DB_NAME, $table
        ));
        if ($status_col
            && (stripos((string) $status_col->COLUMN_TYPE, "'refunded'") === false
                || stripos((string) $status_col->COLUMN_TYPE, "'partial_refund'") === false)) {
            $wpdb->query(
                "ALTER TABLE {$table}
                   MODIFY status ENUM('pending','completed','failed','refunded','partial_refund')
                          NOT NULL DEFAULT 'pending'"
            );
        }

        // D-migration step 3: create the refunds audit table.
        $refunds_sql = "CREATE TABLE $refunds_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            transaction_id bigint(20) UNSIGNED NOT NULL,
            admin_user_id bigint(20) UNSIGNED NOT NULL,
            amount decimal(12,2) NOT NULL,
            reason text NOT NULL,
            wallet_credit_reference varchar(100) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY wallet_credit_reference (wallet_credit_reference),
            KEY transaction_id (transaction_id),
            KEY admin_user_id (admin_user_id)
        ) $charset_collate;";
        dbDelta($refunds_sql);

        // E-migration step 1: ensure the client_reference column
        // exists with its UNIQUE index. Two-step (column then
        // index) so the ALTER is reversible if the index step
        // fails — matches the rest of the migration's idempotency
        // pattern.
        $client_ref_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
               AND COLUMN_NAME = 'client_reference'",
            DB_NAME, $table
        ));
        if ($client_ref_exists === 0) {
            $wpdb->query(
                "ALTER TABLE {$table}
                   ADD COLUMN client_reference VARCHAR(64) DEFAULT NULL
                   AFTER refunded_amount"
            );
        }
        $client_ref_index_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
               AND INDEX_NAME = 'client_reference'",
            DB_NAME, $table
        ));
        if ($client_ref_index_exists === 0) {
            $wpdb->query(
                "ALTER TABLE {$table}
                   ADD UNIQUE KEY client_reference (client_reference)"
            );
        }

        // E-migration step 2: ensure the completed_at column
        // exists. Stamped by complete_transaction(); useful for
        // both ops latency analytics and the reconciliation
        // worker's "stale pending" probe.
        $completed_at_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
               AND COLUMN_NAME = 'completed_at'",
            DB_NAME, $table
        ));
        if ($completed_at_exists === 0) {
            $wpdb->query(
                "ALTER TABLE {$table}
                   ADD COLUMN completed_at DATETIME DEFAULT NULL
                   AFTER created_at"
            );
        }

        // E-migration step 3: flip the status DEFAULT from
        // 'completed' to 'pending'. dbDelta does NOT reliably
        // change a column's DEFAULT on an existing column across
        // MySQL versions (it considers the column "already there"
        // and skips the spec), so we probe COLUMN_DEFAULT and
        // ALTER if needed. The enum value list itself is unchanged
        // from the D step above — we just need the DEFAULT to
        // shift so any code path that INSERTs without setting
        // status explicitly lands in the safer pending state
        // rather than falsely claiming completion.
        $status_default = $wpdb->get_var($wpdb->prepare(
            "SELECT COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'status'",
            DB_NAME, $table
        ));
        if ($status_default !== 'pending') {
            $wpdb->query(
                "ALTER TABLE {$table}
                   MODIFY status ENUM('pending','completed','failed','refunded','partial_refund')
                          NOT NULL DEFAULT 'pending'"
            );
        }

        // PR #294 migration: add the source_wallet column that
        // records which wallet was actually debited for each bill
        // purchase. The post-PR-#294 code path debits the user's
        // Fintava virtual wallet ('virtual'); pre-PR-#294 rows
        // debited the Matrix internal wallet ('matrix'). Refund
        // dispatch (both in-flow and admin-initiated) reads this
        // column so a refund of a stale pending row debits the
        // correct wallet — this is the migration-safety guarantee
        // that lets the source-wallet flip ship without stranding
        // existing pending rows on the wrong refund path.
        //
        // Default 'matrix' so any row that existed before this
        // ALTER ran is correctly tagged as the legacy path. New
        // rows inserted by the post-PR-#294 begin_transaction()
        // explicitly set 'virtual', so the DEFAULT only ever
        // applies to historical rows. Future-proofs against a
        // direct INSERT via wp-cli or third-party tooling that
        // doesn't know about the column.
        $source_wallet_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
               AND COLUMN_NAME = 'source_wallet'",
            DB_NAME, $table
        ));
        if ($source_wallet_exists === 0) {
            $wpdb->query(
                "ALTER TABLE {$table}
                   ADD COLUMN source_wallet ENUM('matrix','virtual')
                       NOT NULL DEFAULT 'matrix'
                       AFTER total_charged"
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
