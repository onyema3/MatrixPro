<?php
/**
 * Paystack Payment Gateway
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Paystack {

    private $public_key;
    private $secret_key;
    private $base_url = 'https://api.paystack.co';

    public function __construct() {
        $this->load_credentials();
    }

    private function load_credentials() {
        global $wpdb;
        $gateway = $wpdb->get_row(
            "SELECT * FROM {$wpdb->prefix}matrix_gateways WHERE slug = 'paystack'"
        );

        if ($gateway && $gateway->gateway_parameters) {
            $params = json_decode($gateway->gateway_parameters, true);
            $this->public_key = $params['public_key'] ?? '';
            $this->secret_key = $params['secret_key'] ?? '';
        }
    }

    /**
     * Initialize a payment
     */
    public function initialize_payment($deposit_id, $amount, $user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            return ['success' => false, 'message' => __('User not found', 'matrix-mlm')];
        }

        $reference = 'MTX-' . $deposit_id . '-' . time();
        $callback_url = home_url('/matrix-dashboard/?tab=deposits&status=verify&gateway=paystack&reference=' . $reference);

        $response = wp_remote_post($this->base_url . '/transaction/initialize', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->secret_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'email' => $user->user_email,
                'amount' => intval($amount * 100), // Paystack uses kobo
                'reference' => $reference,
                'callback_url' => $callback_url,
                'metadata' => [
                    'deposit_id' => $deposit_id,
                    'user_id' => $user_id,
                    'plugin' => 'matrix-mlm'
                ]
            ])
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($body['status'] === true) {
            // Update deposit with transaction reference
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'matrix_deposits',
                ['transaction_id' => $reference],
                ['id' => $deposit_id]
            );

            return [
                'success' => true,
                'authorization_url' => $body['data']['authorization_url'],
                'reference' => $reference,
                'access_code' => $body['data']['access_code']
            ];
        }

        return ['success' => false, 'message' => $body['message'] ?? __('Payment initialization failed', 'matrix-mlm')];
    }

    /**
     * Verify a payment
     */
    public function verify_payment($reference) {
        $response = wp_remote_get($this->base_url . '/transaction/verify/' . $reference, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->secret_key,
            ]
        ]);

        if (is_wp_error($response)) {
            return new WP_REST_Response(['status' => 'error', 'message' => $response->get_error_message()], 500);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($body['status'] === true && $body['data']['status'] === 'success') {
            $this->complete_deposit($reference, $body['data']);
            return new WP_REST_Response(['status' => 'success', 'message' => __('Payment verified', 'matrix-mlm')], 200);
        }

        return new WP_REST_Response(['status' => 'failed', 'message' => __('Payment verification failed', 'matrix-mlm')], 400);
    }

    /**
     * Handle Paystack webhook
     *
     * Security:
     * - Signature verification is MANDATORY. If no webhook_secret is
     *   configured, all webhook calls are rejected with 401. This prevents
     *   forged deposit-completion events from anyone on the internet.
     * - Uses hash_equals() to avoid timing-attack leaks.
     * - complete_deposit() validates that the verified gateway amount and
     *   currency match the server-stored deposit row before crediting.
     */
    public function handle_webhook($request) {
        $payload = $request->get_body();
        $signature = $request->get_header('x-paystack-signature');

        $gateway_params = $this->get_gateway_params();
        $webhook_secret = $gateway_params['webhook_secret'] ?? '';

        // Mandatory signature: refuse to process unsigned/unconfigured webhooks.
        if (empty($webhook_secret) || !is_string($signature) || $signature === '') {
            error_log('[Matrix Paystack Webhook] Rejected: missing webhook_secret or signature header');
            return new WP_REST_Response(['status' => 'error', 'message' => 'Webhook signature required'], 401);
        }

        $computed = hash_hmac('sha512', $payload, $webhook_secret);
        if (!hash_equals($computed, $signature)) {
            return new WP_REST_Response(['status' => 'error', 'message' => 'Invalid signature'], 401);
        }

        $event = json_decode($payload, true);
        if (!is_array($event) || empty($event['event'])) {
            return new WP_REST_Response(['status' => 'error', 'message' => 'Invalid payload'], 400);
        }

        if ($event['event'] === 'charge.success') {
            $data = $event['data'] ?? [];
            $reference = $data['reference'] ?? '';
            if ($reference !== '') {
                $this->complete_deposit($reference, $data);
            }
        }

        return new WP_REST_Response(['status' => 'success'], 200);
    }

    /**
     * Complete deposit after successful payment.
     *
     * Server-side amount and currency validation: the gateway-verified
     * payment is compared against the deposit row stored at initialization
     * time. Mismatches are recorded (status='disputed') and never credited.
     */
    private function complete_deposit($reference, $payment_data) {
        global $wpdb;

        $deposit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_deposits WHERE transaction_id = %s AND status = 'pending'",
            $reference
        ));

        if (!$deposit) {
            return;
        }

        // Paystack reports amounts in the smallest currency unit (kobo for NGN).
        // The deposit was initialized with $amount * 100, so divide back to compare.
        $paid_minor = isset($payment_data['amount']) ? intval($payment_data['amount']) : 0;
        $expected_minor = intval(round(floatval($deposit->amount) * 100));
        $paid_currency = isset($payment_data['currency']) ? strtoupper((string) $payment_data['currency']) : '';
        $expected_currency = strtoupper((string) ($deposit->currency ?? get_option('matrix_mlm_currency', 'NGN')));

        if ($paid_minor < $expected_minor || ($paid_currency !== '' && $paid_currency !== $expected_currency)) {
            // Underpayment or currency mismatch: do NOT credit. Mark as disputed.
            $wpdb->update(
                $wpdb->prefix . 'matrix_deposits',
                [
                    'status' => 'disputed',
                    'gateway_response' => json_encode([
                        'reason' => 'amount_or_currency_mismatch',
                        'expected_amount_minor' => $expected_minor,
                        'paid_amount_minor' => $paid_minor,
                        'expected_currency' => $expected_currency,
                        'paid_currency' => $paid_currency,
                        'payment_data' => $payment_data,
                    ]),
                ],
                ['id' => $deposit->id]
            );
            error_log(sprintf(
                '[Matrix Paystack Webhook] Disputed deposit #%d ref=%s: expected %d %s got %d %s',
                $deposit->id, $reference, $expected_minor, $expected_currency, $paid_minor, $paid_currency
            ));
            Matrix_MLM_Notifications::send_admin_notification(
                'paystack_deposit_disputed',
                sprintf(__('Paystack deposit disputed (Ref: %s) - amount/currency mismatch', 'matrix-mlm'), $reference)
            );
            return;
        }

        // Conditional update: only the first webhook delivery wins.
        $updated = $wpdb->update(
            $wpdb->prefix . 'matrix_deposits',
            [
                'status' => 'completed',
                'gateway_response' => json_encode($payment_data),
            ],
            ['id' => $deposit->id, 'status' => 'pending']
        );

        if ($updated !== 1) {
            // A concurrent delivery already completed this deposit.
            return;
        }

        $wallet = new Matrix_MLM_Wallet();
        $wallet->credit(
            $deposit->user_id,
            $deposit->net_amount,
            'deposit',
            sprintf(__('Paystack deposit (Ref: %s)', 'matrix-mlm'), $reference),
            $reference
        );

        Matrix_MLM_Notifications::send_deposit_notification($deposit->user_id, $deposit->amount, 'completed');
    }

    /**
     * Get gateway params
     */
    private function get_gateway_params() {
        global $wpdb;
        $gateway = $wpdb->get_row(
            "SELECT gateway_parameters FROM {$wpdb->prefix}matrix_gateways WHERE slug = 'paystack'"
        );
        return $gateway ? json_decode($gateway->gateway_parameters, true) : [];
    }

    /**
     * Get public key for frontend
     */
    public function get_public_key() {
        return $this->public_key;
    }

    /**
     * GET /bank — fetch Paystack's Nigerian bank list. Cached in a 24h
     * transient because the list is stable (the CBN doesn't add Nigerian
     * banks daily) and we hit it on every name-enquiry race.
     *
     * Returns an array of `[ ['name' => ..., 'code' => ..., 'longcode' => ...], ...]`.
     * Returns `[]` on any failure mode (no key configured, network error,
     * non-success response) so callers can treat "list empty" as "Paystack
     * resolver unavailable" without a separate error type.
     */
    public function get_banks() {
        $cache_key = 'matrix_paystack_banks_v1';
        $cached    = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        if (empty($this->secret_key)) {
            return [];
        }

        $response = wp_remote_get($this->base_url . '/bank?country=nigeria&perPage=200', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->secret_key,
                'Accept'        => 'application/json',
            ],
            'timeout' => 8,
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['status']) || empty($body['data']) || !is_array($body['data'])) {
            return [];
        }

        $banks = [];
        foreach ($body['data'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $banks[] = [
                'name'     => (string) ($row['name'] ?? ''),
                'code'     => (string) ($row['code'] ?? ''),
                'longcode' => (string) ($row['longcode'] ?? ''),
            ];
        }
        set_transient($cache_key, $banks, DAY_IN_SECONDS);
        return $banks;
    }

    /**
     * Translate a Fintava-style 6-digit NIBSS sortCode (e.g. "000014")
     * into the matching Paystack CBN code (e.g. "044") so the
     * /bank/resolve endpoint can be called with input the form already
     * collected via the Fintava-shaped bank dropdown.
     *
     * Strategy:
     *   1. Hard-coded fast path for the ~25 Nigerian banks where the
     *      sortCode <-> CBN mapping is stable and well-known. Avoids
     *      a Paystack /bank round-trip on the hot path.
     *   2. Fallback to dynamic name-matching against the cached
     *      Paystack /bank list. This is how new / fintech banks
     *      keep working without code changes — they get added to
     *      Paystack's list before they get added to ours.
     *   3. Returns '' when the bank can't be resolved on either
     *      path. Caller drops the Paystack leg of the race and
     *      runs Fintava-only, same behaviour as today.
     *
     * @param string $sortcode    NIBSS sortCode from Fintava /banks.
     * @param string $bank_name   Optional bank display name from the
     *                            same /banks row, used by step 2 above.
     * @return string CBN code (3-digit), or '' if no match.
     */
    public function nibss_sortcode_to_cbn_code($sortcode, $bank_name = '') {
        $sortcode = trim((string) $sortcode);
        if ($sortcode === '') {
            return '';
        }

        // Static map - the well-known Nigerian commercial and merchant
        // banks plus the major fintechs. Source: NIBSS sortCode
        // publication + Paystack's published bank list. Two flavours:
        //
        //   - Commercial / merchant banks: Fintava sortCode (3-digit
        //     CBN with leading zeros, e.g. "000014") translates to
        //     Paystack CBN code (3-digit no-leading-zero, e.g. "044").
        //
        //   - Fintech / digital banks (OPay, PalmPay, Kuda,
        //     Moniepoint, 9PSB, etc.): Fintava and Paystack both use
        //     the NIBSS-issued institution code verbatim, so the map
        //     is an identity (e.g. "100004" -> "100004" for OPay).
        //     The identity entries look redundant but they sit in the
        //     fast path so we don't pay a Paystack /bank round-trip
        //     for the most common fintech lookups.
        //
        // When a new bank's sortCode <-> CBN code pair becomes stable,
        // add it here. Until then the dynamic name-match and identity
        // fallbacks below pick up new banks Paystack adds.
        $map = [
            // Commercial banks
            '000014' => '044', // Access Bank
            '000005' => '063', // Access (Diamond)
            '000009' => '023', // Citibank
            '000010' => '050', // Ecobank Nigeria
            '000007' => '070', // Fidelity Bank
            '000016' => '011', // First Bank of Nigeria
            '000003' => '214', // First City Monument Bank (FCMB)
            '000013' => '058', // Guaranty Trust Bank (GTBank)
            '000020' => '030', // Heritage Bank
            '000006' => '301', // Jaiz Bank
            '000002' => '082', // Keystone Bank
            '000008' => '076', // Polaris Bank
            '000023' => '101', // Providus Bank
            '000012' => '221', // Stanbic IBTC Bank
            '000021' => '068', // Standard Chartered Bank
            '000001' => '232', // Sterling Bank
            '000022' => '100', // SunTrust Bank
            '000025' => '102', // TitanTrust Bank (Taj Bank)
            '000004' => '033', // United Bank for Africa (UBA)
            '000018' => '032', // Union Bank of Nigeria
            '000011' => '215', // Unity Bank
            '000017' => '035', // Wema Bank
            '000015' => '057', // Zenith Bank
            '000027' => '103', // Globus Bank
            '000030' => '104', // Parallex Bank
            '000031' => '105', // PremiumTrust Bank
            // Merchant banks
            '060001' => '559', // Coronation Merchant Bank
            '060002' => '401', // FBNQuest Merchant Bank
            '060003' => '060', // Nova Merchant Bank
            // Fintech / digital banks. NIBSS-identity codes - both
            // Fintava and Paystack accept the sortCode as the CBN
            // code on /bank/resolve for these institutions.
            '100004' => '100004', // OPay (PayCom)
            '100033' => '100033', // PalmPay
            '100002' => '100002', // Paga
            '100003' => '100003', // Parkway Readycash
            '50211'  => '50211',  // Kuda Microfinance Bank
            '50515'  => '50515',  // Moniepoint Microfinance Bank
            '120001' => '120001', // 9 Payment Service Bank (9PSB)
            '51310'  => '51310',  // Sparkle Microfinance Bank
            '565'    => '565',    // Carbon (One Finance MFB)
            '566'    => '566',    // VFD Microfinance Bank
            '125'    => '125',    // Rubies Microfinance Bank
        ];
        if (isset($map[$sortcode])) {
            return $map[$sortcode];
        }

        // Dynamic fallback #1: name match.
        //
        // If the operator gave us a bank name from Fintava's /banks
        // row, look for a Paystack bank whose name matches by
        // normalised compare. The normaliser strips parenthetical
        // content, corporate suffixes, and the "bank" trailer so
        // "OPay (PayCom)" and "OPay Digital Services Limited (OPay)"
        // both collide on "opay". This keeps fintech banks working
        // when Paystack onboards a new one before we ship a static
        // map update.
        if ($bank_name !== '') {
            $needle = self::normalize_bank_name_for_match($bank_name);
            if ($needle !== '') {
                foreach ($this->get_banks() as $bank) {
                    if (self::normalize_bank_name_for_match($bank['name']) === $needle) {
                        return $bank['code'];
                    }
                }
            }
        }

        // Dynamic fallback #2: identity match against Paystack's
        // bank list.
        //
        // Many institutions (every fintech, plus a handful of
        // microfinance banks) carry the same NIBSS-issued code on
        // both Fintava's /banks and Paystack's /bank. If Paystack
        // returns a bank with code == our sortCode, it's safe to
        // use the sortCode as-is. This catches anything the static
        // map and name match miss without any code archaeology -
        // we're literally just confirming Paystack accepts this
        // code.
        foreach ($this->get_banks() as $bank) {
            if (isset($bank['code']) && $bank['code'] === $sortcode) {
                return $sortcode;
            }
        }

        return '';
    }

    /**
     * Normalise a bank name for fuzzy matching across Fintava's and
     * Paystack's /banks responses, which use different naming
     * conventions for the same institution.
     *
     * Steps:
     *   1. Lowercase + trim.
     *   2. Strip parenthetical content. This is the critical step
     *      for fintechs: Fintava returns "OPay (PayCom)" and
     *      Paystack returns "OPay Digital Services Limited (OPay)".
     *      Without this, the two never match.
     *   3. Strip corporate-noise tokens (plc/ltd/limited/nigeria,
     *      "digital services", "financial services", "fintech").
     *   4. Strip a trailing "bank" / "microfinance bank" / "mfb"
     *      so "Wema Bank" / "Wema Bank Plc" / "Wema" all collide.
     *   5. Collapse whitespace, trim again.
     *
     * Symmetric on both sides — Fintava's name and Paystack's name
     * both go through the same transform and either both match or
     * neither does.
     */
    public static function normalize_bank_name_for_match($name) {
        $n = strtolower((string) $name);
        // Strip parenthetical content first so contents inside parens
        // can't survive into later steps and trip up the trailing
        // "bank" stripper.
        $n = preg_replace('/\([^)]*\)/u', ' ', $n);
        // Corporate / common-noise tokens.
        $n = preg_replace('/\b(plc|limited|ltd|nigeria|nig|digital|services|financial|fintech)\b/u', ' ', $n);
        $n = preg_replace('/\s+/', ' ', $n);
        $n = trim($n);
        // Trailing institution-type tokens. "microfinance bank" /
        // "mfb" / "bank" — order matters so the longer phrase
        // gets stripped before the shorter "bank" suffix would
        // partial-match it.
        $n = preg_replace('/\s+(microfinance bank|mfb|bank)$/u', '', $n);
        return trim($n);
    }

    /**
     * GET /bank/resolve - verify a Nigerian bank account via NIBSS
     * Name Enquiry, resold by Paystack.
     *
     * Used as one of two parallel resolvers in
     * Matrix_MLM_Fintava::ajax_resolve_account - see the race_resolve_account
     * docblock there for the full design. Paystack's resolver is free,
     * NIBSS-backed, and tends to have wider coverage than Fintava's
     * basic-tier /name/enquiry, especially for fintech banks (OPay,
     * Kuda, PalmPay, Moniepoint).
     *
     * Bank-code shape: 3-digit CBN code (e.g. "044" for Access).
     * Callers translating from Fintava's 6-digit NIBSS sortCode must
     * use nibss_sortcode_to_cbn_code() first.
     *
     * Returns a normalized array on success, or WP_Error on any
     * failure mode (network, non-success response, empty name).
     */
    public function resolve_account($account_number, $cbn_bank_code) {
        if (empty($this->secret_key)) {
            return new WP_Error(
                'paystack_not_configured',
                __('Paystack secret key not configured', 'matrix-mlm')
            );
        }

        $url = $this->base_url . '/bank/resolve?' . http_build_query([
            'account_number' => $account_number,
            'bank_code'      => $cbn_bank_code,
        ]);

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->secret_key,
                'Accept'        => 'application/json',
            ],
            'timeout' => 8,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['status']) || !$body['status']) {
            return new WP_Error(
                'paystack_resolve_error',
                (string) ($body['message'] ?? __('Could not resolve account via Paystack', 'matrix-mlm'))
            );
        }

        $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];
        $name = trim((string) ($data['account_name'] ?? ''));
        if ($name === '') {
            return new WP_Error(
                'paystack_resolve_empty',
                __('Paystack returned no account name', 'matrix-mlm')
            );
        }

        return [
            'account_name'   => $name,
            'account_number' => (string) ($data['account_number'] ?? $account_number),
            'bank_id'        => (string) ($data['bank_id'] ?? ''),
        ];
    }

    /**
     * Parse a raw Paystack /bank/resolve JSON body (already decoded to
     * array) into the same normalized shape as resolve_account().
     * Used by the parallel-race code path which doesn't go through
     * resolve_account because it builds the HTTP request itself for
     * Requests::request_multiple. Returns null when the body has no
     * usable name.
     */
    public static function parse_resolve_body($body) {
        if (!is_array($body) || empty($body['status']) || !$body['status']) {
            return null;
        }
        $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];
        $name = trim((string) ($data['account_name'] ?? ''));
        if ($name === '') {
            return null;
        }
        return [
            'account_name'   => $name,
            'account_number' => (string) ($data['account_number'] ?? ''),
        ];
    }

    /**
     * Return the secret key for read-only resolvers (bank-resolve).
     * Used by the parallel-race code path so it can build the
     * Authorization header for Requests::request_multiple without
     * reaching into the private property. Returns '' when Paystack
     * isn't configured - caller drops the Paystack leg of the race.
     */
    public function get_secret_key_for_resolver() {
        return (string) $this->secret_key;
    }
}
