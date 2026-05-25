<?php
/**
 * Flutterwave Payment Gateway
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Flutterwave {

    private $public_key;
    private $secret_key;
    private $encryption_key;
    private $base_url = 'https://api.flutterwave.com/v3';

    public function __construct() {
        $this->load_credentials();
    }

    private function load_credentials() {
        global $wpdb;
        $gateway = $wpdb->get_row(
            "SELECT * FROM {$wpdb->prefix}matrix_gateways WHERE slug = 'flutterwave'"
        );

        if ($gateway && $gateway->gateway_parameters) {
            $params = json_decode($gateway->gateway_parameters, true);
            $this->public_key = $params['public_key'] ?? '';
            $this->secret_key = $params['secret_key'] ?? '';
            $this->encryption_key = $params['encryption_key'] ?? '';
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

        $tx_ref = 'MTX-FW-' . $deposit_id . '-' . time();
        $redirect_url = home_url('/matrix-dashboard/?tab=deposits&status=verify&gateway=flutterwave&tx_ref=' . $tx_ref);

        $response = wp_remote_post($this->base_url . '/payments', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->secret_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'tx_ref' => $tx_ref,
                'amount' => $amount,
                'currency' => get_option('matrix_mlm_currency', 'NGN'),
                'redirect_url' => $redirect_url,
                'payment_options' => 'card,banktransfer,ussd',
                'meta' => [
                    'deposit_id' => $deposit_id,
                    'user_id' => $user_id
                ],
                'customer' => [
                    'email' => $user->user_email,
                    'name' => $user->display_name,
                ],
                'customizations' => [
                    'title' => get_bloginfo('name'),
                    'description' => __('Fund your wallet', 'matrix-mlm'),
                    'logo' => get_option('matrix_mlm_logo_url', '')
                ]
            ])
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['status']) && $body['status'] === 'success') {
            // Update deposit with transaction reference
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'matrix_deposits',
                ['transaction_id' => $tx_ref],
                ['id' => $deposit_id]
            );

            return [
                'success' => true,
                'authorization_url' => $body['data']['link'],
                'reference' => $tx_ref
            ];
        }

        return ['success' => false, 'message' => $body['message'] ?? __('Payment initialization failed', 'matrix-mlm')];
    }

    /**
     * Verify a payment
     */
    public function verify_payment($reference) {
        global $wpdb;

        // Get transaction ID from Flutterwave
        $deposit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_deposits WHERE transaction_id = %s",
            $reference
        ));

        if (!$deposit) {
            return new WP_REST_Response(['status' => 'error', 'message' => 'Deposit not found'], 404);
        }

        // Verify by tx_ref
        $response = wp_remote_get($this->base_url . '/transactions/verify_by_reference?tx_ref=' . $reference, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->secret_key,
            ]
        ]);

        if (is_wp_error($response)) {
            return new WP_REST_Response(['status' => 'error', 'message' => $response->get_error_message()], 500);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['status']) && $body['status'] === 'success' && $body['data']['status'] === 'successful') {
            $this->complete_deposit($reference, $body['data']);
            return new WP_REST_Response(['status' => 'success', 'message' => __('Payment verified', 'matrix-mlm')], 200);
        }

        return new WP_REST_Response(['status' => 'failed', 'message' => __('Payment verification failed', 'matrix-mlm')], 400);
    }

    /**
     * Handle Flutterwave webhook
     *
     * Security:
     * - Signature verification is MANDATORY. If no webhook_hash is
     *   configured, all webhook calls are rejected with 401.
     * - Uses hash_equals() to avoid timing-attack leaks.
     * - complete_deposit() validates that the verified gateway amount and
     *   currency match the server-stored deposit row before crediting.
     *
     * Operator security model (audit L9 — vendor protocol limitation):
     *
     *   Flutterwave's "verif-hash" is a STATIC shared secret that the
     *   platform echoes back verbatim on every webhook call. It is
     *   NOT an HMAC over the request body. That means:
     *
     *     - Any leak of webhook_hash (a backup, a log file, the
     *       wp_options table dumped during a migration, an operator
     *       pasting it into a chat) lets an attacker forge arbitrary
     *       deposit-completion events without ever touching
     *       Flutterwave.
     *     - There is no replay protection at the protocol layer; the
     *       same {hash, body} pair is valid forever.
     *
     *   This is a Flutterwave protocol limitation, not a bug in this
     *   plugin's verification logic. The remaining defence is the
     *   server-side amount / currency cross-check in
     *   complete_deposit() below — even a forged webhook can only
     *   credit amounts that match a deposit row this plugin already
     *   wrote at initiation time. So a successful forgery requires
     *   ALSO knowing the (tx_ref, amount, currency) tuple of a
     *   pending deposit, narrowing the impact significantly.
     *
     *   Operator handling guidance:
     *
     *     1. Treat webhook_hash as a high-sensitivity secret on par
     *        with the API secret key. Rotate quarterly via the
     *        Flutterwave dashboard and update the plugin gateway
     *        settings in the same change window.
     *     2. Never log webhook_hash. Audit log emitters in this file
     *        already redact it; future additions must do the same.
     *     3. If a webhook_hash leak is suspected, rotate immediately
     *        AND audit the matrix_deposits table for any 'completed'
     *        rows in the suspected window whose audit trail does not
     *        match a real Flutterwave dashboard entry.
     *     4. Prefer Paystack or Fintava for new merchant integrations
     *        when both options exist — both use HMAC-of-body which
     *        does not have this protocol shape.
     *
     *   See .kiro/steering/operator-security-notes.md for the
     *   plugin-wide secret-rotation expectations.
     */
    public function handle_webhook($request) {
        $payload = $request->get_body();
        $signature = $request->get_header('verif-hash');

        $gateway_params = $this->get_gateway_params();
        $webhook_hash = $gateway_params['webhook_hash'] ?? '';

        // Mandatory signature: refuse to process unsigned/unconfigured webhooks.
        if (empty($webhook_hash) || !is_string($signature) || $signature === '') {
            error_log('[Matrix Flutterwave Webhook] Rejected: missing webhook_hash or verif-hash header');
            return new WP_REST_Response(['status' => 'error', 'message' => 'Webhook signature required'], 401);
        }

        // Flutterwave's verif-hash is a shared secret echoed back as-is, not an
        // HMAC of the payload. Use a constant-time compare to avoid byte-by-byte
        // timing leaks.
        if (!hash_equals((string) $webhook_hash, $signature)) {
            return new WP_REST_Response(['status' => 'error', 'message' => 'Invalid signature'], 401);
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            return new WP_REST_Response(['status' => 'error', 'message' => 'Invalid payload'], 400);
        }

        if (isset($event['event']) && $event['event'] === 'charge.completed') {
            $data = $event['data'] ?? [];
            if (isset($data['status']) && $data['status'] === 'successful' && !empty($data['tx_ref'])) {
                $this->complete_deposit($data['tx_ref'], $data);
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

        $paid_amount = isset($payment_data['amount']) ? floatval($payment_data['amount']) : 0.0;
        $expected_amount = floatval($deposit->amount);
        $paid_currency = isset($payment_data['currency']) ? strtoupper((string) $payment_data['currency']) : '';
        $expected_currency = strtoupper((string) ($deposit->currency ?? get_option('matrix_mlm_currency', 'NGN')));

        // Allow a 1-kobo tolerance for floating-point fuzz only.
        if ($paid_amount + 0.01 < $expected_amount
            || ($paid_currency !== '' && $paid_currency !== $expected_currency)) {
            $wpdb->update(
                $wpdb->prefix . 'matrix_deposits',
                [
                    'status' => 'disputed',
                    'gateway_response' => json_encode([
                        'reason' => 'amount_or_currency_mismatch',
                        'expected_amount' => $expected_amount,
                        'paid_amount' => $paid_amount,
                        'expected_currency' => $expected_currency,
                        'paid_currency' => $paid_currency,
                        'payment_data' => $payment_data,
                    ]),
                ],
                ['id' => $deposit->id]
            );
            error_log(sprintf(
                '[Matrix Flutterwave Webhook] Disputed deposit #%d ref=%s: expected %.2f %s got %.2f %s',
                $deposit->id, $reference, $expected_amount, $expected_currency, $paid_amount, $paid_currency
            ));
            Matrix_MLM_Notifications::send_admin_notification(
                'flutterwave_deposit_disputed',
                sprintf(__('Flutterwave deposit disputed (Ref: %s) - amount/currency mismatch', 'matrix-mlm'), $reference)
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
            sprintf(__('Flutterwave deposit (Ref: %s)', 'matrix-mlm'), $reference),
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
            "SELECT gateway_parameters FROM {$wpdb->prefix}matrix_gateways WHERE slug = 'flutterwave'"
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
     * Return the secret key for read-only resolvers (account-resolve).
     *
     * Used by the parallel-race code path in
     * Matrix_MLM_Fintava::race_resolve_account so it can build the
     * Authorization header for Requests::request_multiple without
     * reaching into the private property. Returns '' when Flutterwave
     * isn't configured - caller drops the Flutterwave leg of the race.
     */
    public function get_secret_key_for_resolver() {
        return (string) $this->secret_key;
    }

    /**
     * Cached fetch of Flutterwave's GET /banks/NG list.
     *
     * Used as the dynamic fallback by nibss_sortcode_to_flw_code() so
     * banks Flutterwave has onboarded but our static map hasn't picked
     * up keep working without code changes. Cached for a day - the
     * list is essentially static and the static map handles every
     * common bank without a round-trip anyway.
     *
     * @return array<int, array{name:string, code:string}>
     */
    public function get_banks() {
        if (empty($this->secret_key)) {
            return [];
        }

        $cache_key = 'matrix_flw_banks_ng';
        $cached    = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get($this->base_url . '/banks/NG', [
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
        if (!is_array($body) || empty($body['status']) || $body['status'] !== 'success') {
            return [];
        }
        $rows = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];

        $banks = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $banks[] = [
                'name' => (string) ($row['name'] ?? ''),
                'code' => (string) ($row['code'] ?? ''),
            ];
        }
        set_transient($cache_key, $banks, DAY_IN_SECONDS);
        return $banks;
    }

    /**
     * Translate a Fintava-style sortCode (whatever shape Fintava sent
     * with the bank dropdown row) into the matching Flutterwave bank
     * code, so /accounts/resolve can be called with input the form
     * already collected via the Fintava-shaped bank dropdown.
     *
     * Same three-step strategy as
     * Matrix_MLM_Paystack::nibss_sortcode_to_cbn_code():
     *   1. Static fast-path map for the well-known commercial banks
     *      and the major fintechs. Avoids a Flutterwave /banks/NG
     *      round-trip on the hot path.
     *   2. Dynamic name match against Flutterwave's /banks/NG list.
     *      Catches new banks Flutterwave has onboarded before we ship
     *      a static map update.
     *   3. Dynamic identity match: if Flutterwave's list contains a
     *      bank whose code == our sortCode, pass it through. Catches
     *      every fintech where Fintava and Flutterwave already agree
     *      on the NIBSS-issued institution code.
     *
     * Returns '' when nothing matches. Caller drops the Flutterwave
     * leg of the race - the Paystack and Fintava legs still fire.
     *
     * @param string $sortcode    Sort/bank code Fintava sent with the dropdown row.
     * @param string $bank_name   Optional bank display name from the same row.
     * @return string Flutterwave bank code, or '' if no match.
     */
    public function nibss_sortcode_to_flw_code($sortcode, $bank_name = '') {
        $sortcode = trim((string) $sortcode);
        if ($sortcode === '') {
            return '';
        }

        // Static map. Two flavours, mirroring the Paystack equivalent:
        //
        //   - Commercial / merchant banks: Fintava sortCode (e.g.
        //     "000014") translates to the 3-digit CBN code Flutterwave
        //     accepts (e.g. "044").
        //
        //   - Fintech / digital banks: Flutterwave uses Flutterwave-
        //     specific institution codes for OPay (999992) and PalmPay
        //     (999991), which differ from the NIBSS-issued ones
        //     Paystack uses (100004 / 100033). For most other fintechs
        //     the NIBSS code is identity. Fintava's hardcoded /banks
        //     fallback list already returns 999992 / 999991 for OPay /
        //     PalmPay, so the map handles BOTH the NIBSS form AND the
        //     Flutterwave-native form for those two.
        //
        // When Flutterwave onboards a new bank with a code that
        // doesn't match the NIBSS one, add an entry here. Until then
        // the dynamic name match and identity fallbacks below pick up
        // new banks Flutterwave adds.
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
            // Fintech / digital banks - both inbound shapes folded
            // onto Flutterwave's accepted code.
            '999992' => '999992', // OPay (Flutterwave-native)
            '100004' => '999992', // OPay (NIBSS form, same institution)
            '999991' => '999991', // PalmPay (Flutterwave-native)
            '100033' => '999991', // PalmPay (NIBSS form, same institution)
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
        // Reuse Paystack's normaliser so "OPay (PayCom)",
        // "OPay Digital Services Limited (OPay)", and any other
        // parenthetical / corporate-suffix variant collide on a
        // common slug. Symmetric on both sides.
        if ($bank_name !== '' && class_exists('Matrix_MLM_Paystack')) {
            $needle = Matrix_MLM_Paystack::normalize_bank_name_for_match($bank_name);
            if ($needle !== '') {
                foreach ($this->get_banks() as $bank) {
                    if (Matrix_MLM_Paystack::normalize_bank_name_for_match($bank['name']) === $needle) {
                        return $bank['code'];
                    }
                }
            }
        }

        // Dynamic fallback #2: identity match against Flutterwave's
        // bank list. If Flutterwave returns a bank with code == our
        // sortCode, pass the sortCode through. Catches every
        // institution where Fintava and Flutterwave already agree
        // on the NIBSS-issued code without code archaeology.
        foreach ($this->get_banks() as $bank) {
            if (isset($bank['code']) && $bank['code'] === $sortcode) {
                return $sortcode;
            }
        }

        return '';
    }

    /**
     * POST /accounts/resolve - verify a Nigerian bank account via NIBSS
     * Name Enquiry, resold by Flutterwave.
     *
     * Used as one of three parallel resolvers in
     * Matrix_MLM_Fintava::ajax_resolve_account - see the
     * race_resolve_account docblock there for the full design.
     * Flutterwave's resolver is NIBSS-backed and tends to have wider
     * coverage than Fintava's basic-tier /name/enquiry, especially for
     * fintech banks (OPay, Kuda, PalmPay, Moniepoint) where Flutterwave
     * uses its own native codes (999992, 999991, etc.) that bypass the
     * NIBSS sortCode shape mismatches Fintava sometimes hits.
     *
     * Bank-code shape: Flutterwave's account_bank value. Callers
     * translating from Fintava's sortCode must use
     * nibss_sortcode_to_flw_code() first.
     *
     * Returns a normalized array on success, or WP_Error on any
     * failure mode (network, non-success response, empty name).
     */
    public function resolve_account($account_number, $flw_bank_code) {
        if (empty($this->secret_key)) {
            return new WP_Error(
                'flutterwave_not_configured',
                __('Flutterwave secret key not configured', 'matrix-mlm')
            );
        }

        $response = wp_remote_post($this->base_url . '/accounts/resolve', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->secret_key,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ],
            'body'    => json_encode([
                'account_number' => $account_number,
                'account_bank'   => $flw_bank_code,
            ]),
            'timeout' => 8,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['status']) || $body['status'] !== 'success') {
            return new WP_Error(
                'flutterwave_resolve_error',
                (string) ($body['message'] ?? __('Could not resolve account via Flutterwave', 'matrix-mlm'))
            );
        }

        $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];
        $name = trim((string) ($data['account_name'] ?? ''));
        if ($name === '') {
            return new WP_Error(
                'flutterwave_resolve_empty',
                __('Flutterwave returned no account name', 'matrix-mlm')
            );
        }

        return [
            'account_name'   => $name,
            'account_number' => (string) ($data['account_number'] ?? $account_number),
        ];
    }

    /**
     * Parse a raw Flutterwave /accounts/resolve JSON body (already
     * decoded to array) into the same normalized shape as
     * resolve_account(). Used by the parallel-race code path which
     * doesn't go through resolve_account because it builds the HTTP
     * request itself for Requests::request_multiple. Returns null when
     * the body has no usable name.
     */
    public static function parse_resolve_body($body) {
        if (!is_array($body) || empty($body['status']) || $body['status'] !== 'success') {
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
}
