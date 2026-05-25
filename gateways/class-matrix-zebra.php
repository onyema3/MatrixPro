<?php
/**
 * Zebra Wallet Payment Gateway
 *
 * Bibimoney / Nazmo Banking Platform integration. Two-step deposits
 * (PaymentAuth -> Payment with OTP), Instant Payment Notifications
 * (IPN) for asynchronous completion, customer / account / balance
 * lookups for diagnostics. Withdrawal (vendor -> customer "Dispense"
 * + "Remit") is intentionally out of scope of this gateway and
 * reserved for a follow-up that wires the platform into the
 * existing matrix_withdrawals admin-approval flow.
 *
 * Pre-auth deposits (the Capture / Cancel state machine) ARE
 * supported as of the /CaptureOrCancel follow-up: an operator
 * who flips "Hold authorisation; capture later" in Admin ->
 * Gateways -> Zebra Wallet sends step 2 of the OTP flow with
 * EventType=PRE_AUTH instead of AUTO_CAPTURE, parking the
 * deposit at status='pending_capture' until they click Capture
 * or Cancel from the Deposits admin page. See the
 * "/CaptureOrCancel" section below for the full state machine.
 *
 * Key design notes:
 *
 *   - The deposit UX is fundamentally different from Paystack /
 *     Flutterwave. There is NO redirect to a hosted checkout page.
 *     The customer's Zebra wallet account is debited directly via
 *     the API after they enter an OTP that the platform sends to
 *     the wallet's primary phone number. The frontend therefore
 *     runs a 2-step inline flow (collect wallet identifier ->
 *     server returns "OTP sent" -> user enters OTP -> server
 *     completes payment) rather than the redirect-and-return
 *     pattern used by the other gateways.
 *
 *   - HMAC: the published spec is ambiguous between "SHA-256 of a
 *     concatenated string including the secret" and "HMAC-SHA256
 *     keyed by the secret over the same concat". The two readings
 *     produce different digests, and the spec sample code is
 *     missing from the document we received. We default to
 *     HMAC-SHA256 (message = full concat, key = api_secret) which
 *     matches the literal "GetHMACEncryptedData(message, key)"
 *     function-signature reading in section 6.1.2. Operators whose
 *     Bibimoney environment expects the alternate form can flip to
 *     plain SHA-256 via the matrix_zebra_hmac_method filter without
 *     a code change.
 *
 *   - IPN: the platform does NOT send a signature header. The doc
 *     says only "Your endpoint must return HTTP 200 and the body
 *     'accepted' otherwise our system will keep sending until this
 *     condition is met." We compensate with two defenses:
 *
 *       1. A per-install webhook_token baked into the IPN URL
 *          path: rest_url('matrix-mlm/v1/payment/callback/zebra/<token>').
 *          The operator registers this URL with developers@bibimoney.com
 *          so only that endpoint receives IPNs. An attacker who
 *          doesn't know the token can't post forged IPNs to a
 *          guessable URL.
 *
 *       2. Always-on payload validation in handle_webhook(): the
 *          IPN must reference a deposit we initiated (matched by
 *          VendorReference) AND the PSPreference we stored from
 *          step 1 AND the amount AND the currency. An attacker
 *          who DOES know the URL still can't credit a wallet
 *          without also knowing our internal references.
 *
 *   - Server-side amount/currency mismatch -> status='disputed'
 *     with full audit trail, never credits the wallet. Mirrors
 *     the same defense Paystack and Flutterwave already implement.
 *
 *   - All money goes over the wire as integer minor units (per
 *     spec section 6.1.3). NGN has 2 minor digits so amount*100
 *     is the conversion. International deployments using zero-
 *     decimal currencies (JPY, IDR) would need a per-currency
 *     minor-unit lookup; that's a follow-up if/when the operator
 *     onboards a non-NGN Zebra environment.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Zebra {

    /** Default QA / sandbox base URL per Bibimoney developer docs. */
    const QA_BASE_URL   = 'https://qa-api.bibimoney.com/api/vendor';

    /** Production base URL is country-specific per docs and shared
     *  with the operator by developers@bibimoney.com. We don't ship
     *  a default — the operator pastes the live URL into the admin
     *  settings field. Empty -> we fall back to QA so a half-
     *  configured install fails to UAT, never to a guessed prod URL. */
    const LIVE_BASE_URL = '';

    /* ====================================================================
     * Bibimoney EventType vocabulary
     *
     * Centralised so the OTP-completion path (4.1.2), the IPN
     * dispatcher (5.x), and the /CaptureOrCancel state machine
     * (out-of-scope-of-#302 follow-up) all reference the same
     * literal strings the spec uses. AUTO_CAPTURE is "charge
     * immediately"; PRE_AUTH is "authorise and hold"; CAPTURE
     * settles a held auth; CANCEL releases it without charging.
     * ==================================================================== */
    const EVENT_AUTO_CAPTURE = 'AUTO_CAPTURE';
    const EVENT_PRE_AUTH     = 'PRE_AUTH';
    const EVENT_CAPTURE      = 'CAPTURE';
    const EVENT_CANCEL       = 'CANCEL';

    /* ====================================================================
     * Vendor->customer payout EventType (Bibimoney /Remit endpoint).
     *
     * /Remit is the vendor-side counterpart to the deposit flow:
     * the platform debits the merchant's vendor wallet and credits
     * the customer's Zebra Wallet (keyed on the customer's
     * PrimaryAccountNumber — IWAN, MSISDN, or local reference,
     * same identifier shape /PaymentAuth accepts on the deposit
     * side).
     *
     * Wired into the existing matrix_withdrawals admin-approval
     * flow rather than auto-credited so an operator still
     * approves each payout. See remit_to_account() for the
     * pending -> processing -> approved state machine and
     * Matrix_MLM_Admin::approve_withdrawal() for the dispatch
     * branch.
     *
     * The endpoint path itself (defaults to 'Remit') and the
     * EventType literal (defaults to 'REMIT') are filterable
     * because the published Bibimoney spec we have is ambiguous
     * about which token the platform expects; operators whose
     * environment expects e.g. 'VENDOR_REMIT' can override
     * either via filter without a code change.
     * ==================================================================== */
    const EVENT_REMIT = 'REMIT';

    /* ====================================================================
     * /Dispense — the second vendor->customer payout in the
     * Bibimoney pair. Where /Remit settles to the customer's
     * Zebra Wallet (keyed on PrimaryAccountNumber — IWAN /
     * MSISDN / local reference), /Dispense settles to a bank
     * account (keyed on AccountNumber + BankCode) and is the
     * rail to use when the customer wants the funds in a
     * traditional NUBAN-style account rather than back on their
     * mobile wallet.
     *
     * Architecturally this is the asymmetric mirror of the
     * Fintava bank-payout flow: same matrix_withdrawals admin-
     * approval flow we just wired /Remit into, same pending ->
     * processing -> approved state machine, same per-row lock
     * to prevent double-fire, but the destination payload
     * carries bank routing instead of a wallet identifier and
     * the row is tagged method='zebra_bank' so the dispatcher
     * in approve_withdrawal() can branch.
     *
     * Same operator-facing filter knobs as /Remit
     * (matrix_zebra_dispense_endpoint /
     *  matrix_zebra_dispense_event_type) for the same reason —
     * the public spec is ambiguous about which exact tokens
     * each Bibimoney environment expects.
     * ==================================================================== */
    const EVENT_DISPENSE = 'DISPENSE';

    private $api_key;
    private $api_secret;
    private $vendor_id;
    private $base_url;
    private $environment;
    private $webhook_token;
    private $default_currency;
    private $pre_auth_enabled;

    public function __construct() {
        $this->load_credentials();
    }

    /**
     * Load credentials from the matrix_gateways row. Pattern matches
     * Paystack/Flutterwave for consistency — operator manages keys
     * through Admin -> Gateways -> Zebra Wallet.
     */
    private function load_credentials() {
        global $wpdb;
        $gateway = $wpdb->get_row(
            "SELECT * FROM {$wpdb->prefix}matrix_gateways WHERE slug = 'zebra'"
        );

        $params = [];
        if ($gateway && $gateway->gateway_parameters) {
            $decoded = json_decode($gateway->gateway_parameters, true);
            if (is_array($decoded)) {
                $params = $decoded;
            }
        }

        $this->api_key          = (string) ($params['api_key'] ?? '');
        $this->api_secret       = (string) ($params['api_secret'] ?? '');
        $this->vendor_id        = (string) ($params['vendor_id'] ?? '');
        $this->environment      = strtolower((string) ($params['environment'] ?? 'qa'));
        $this->webhook_token    = (string) ($params['webhook_token'] ?? '');
        $this->default_currency = strtoupper((string) ($params['default_currency'] ?? get_option('matrix_mlm_currency', 'NGN')));
        // Operator-controlled toggle. When true, step 2 of the
        // deposit flow uses EventType=PRE_AUTH and parks the
        // deposit at status='pending_capture' instead of
        // crediting the wallet immediately. Defaults off so
        // existing installs preserve the AUTO_CAPTURE behaviour
        // shipped in PR #302.
        $this->pre_auth_enabled = !empty($params['pre_auth_enabled']);

        $override = trim((string) ($params['base_url_override'] ?? ''));
        if ($override !== '') {
            // Operator override wins — Bibimoney country envs each
            // get a unique URL from developers@bibimoney.com and
            // there's no public list to encode statically.
            $this->base_url = rtrim($override, '/');
        } elseif ($this->environment === 'live' && self::LIVE_BASE_URL !== '') {
            $this->base_url = self::LIVE_BASE_URL;
        } else {
            $this->base_url = self::QA_BASE_URL;
        }
    }

    /**
     * Public accessor for the configured webhook token. Used by the
     * admin Gateways panel to render the per-install IPN URL the
     * operator should register with developers@bibimoney.com.
     */
    public function get_webhook_token() {
        return $this->webhook_token;
    }

    /**
     * Whether Zebra is wired up enough to dispatch deposits to it.
     * The slug must be enabled in matrix_gateways AND all three
     * core credentials must be set. We don't gate on webhook_token
     * here because deposits can complete via the user-facing
     * /payment/verify path even before IPN is registered.
     */
    public function is_configured() {
        return $this->api_key !== '' && $this->api_secret !== '' && $this->vendor_id !== '';
    }

    /**
     * Whether the operator has enabled pre-auth deposits.
     *
     * The default is the gateway-row toggle (Admin -> Gateways ->
     * Zebra Wallet -> "Hold authorisation; capture later"). The
     * matrix_zebra_deposit_pre_auth filter lets ops switch a
     * specific deposit to the alternate mode at runtime — useful
     * when only a subset of plans / amounts / users should be
     * gated on manual capture, without enabling pre-auth platform-
     * wide. The filter receives the default boolean plus the
     * deposit_id so callers can branch on context.
     */
    public function is_pre_auth_enabled($deposit_id = null) {
        return (bool) apply_filters(
            'matrix_zebra_deposit_pre_auth',
            $this->pre_auth_enabled,
            $deposit_id
        );
    }

    /**
     * Compute the HMAC digest required by the platform's payment
     * endpoints (section 6.1.2 of the spec).
     *
     * The spec is ambiguous about whether the digest is plain
     * SHA-256 of the concatenated input fields (which already
     * include api_secret) or HMAC-SHA256 keyed by api_secret over
     * the same concat. We default to HMAC-SHA256 because the spec
     * text refers to "GetHMACEncryptedData(string, key)" — a
     * function signature only HMAC matches — and expose the
     * matrix_zebra_hmac_method filter so an operator whose
     * environment expects the alternate form can override without
     * editing this file.
     *
     * Concatenation order is exactly as spec'd:
     *   api_key + api_secret + EventType + VendorID +
     *   VendorReference + PrimaryAccountNumber + Amount + Currency
     */
    private function compute_hmac($event_type, $vendor_reference, $primary_account, $amount_minor, $currency) {
        $message = $this->api_key
                 . $this->api_secret
                 . $event_type
                 . $this->vendor_id
                 . $vendor_reference
                 . $primary_account
                 . (string) $amount_minor
                 . strtoupper($currency);

        $method = (string) apply_filters('matrix_zebra_hmac_method', 'hmac_sha256');
        if ($method === 'sha256') {
            return hash('sha256', $message);
        }
        return hash_hmac('sha256', $message, $this->api_secret);
    }

    /**
     * HMAC for the /CaptureOrCancel endpoint.
     *
     * The /CaptureOrCancel request body is keyed on PSPreference
     * (not VendorReference or PrimaryAccountNumber) so the 8-field
     * concat from compute_hmac() doesn't fit cleanly. The spec
     * doesn't separately publish the capture/cancel signature
     * formula, so we use the same field-shape the request body
     * carries: api_key + api_secret + EventType + VendorID +
     * PSPreference + Amount + Currency.
     *
     * We deliberately omit PrimaryAccountNumber because (a) it's
     * not in the capture request body, and (b) we mask it in the
     * gateway_response audit stash for privacy — rebuilding the
     * full PAN here would defeat the mask.
     *
     * Same matrix_zebra_hmac_method filter as compute_hmac() so
     * operators whose Bibimoney env expects plain SHA-256 over
     * the concat (vs. HMAC-SHA256 keyed by api_secret) flip one
     * filter and both signing paths follow.
     */
    private function compute_capture_hmac($event_type, $psp_reference, $amount_minor, $currency) {
        $message = $this->api_key
                 . $this->api_secret
                 . $event_type
                 . $this->vendor_id
                 . $psp_reference
                 . (string) $amount_minor
                 . strtoupper($currency);

        $method = (string) apply_filters('matrix_zebra_hmac_method', 'hmac_sha256');
        if ($method === 'sha256') {
            return hash('sha256', $message);
        }
        return hash_hmac('sha256', $message, $this->api_secret);
    }

    /**
     * HMAC for the /Remit endpoint (vendor -> customer payout).
     *
     * Mirrors the 8-field concat compute_hmac() uses for the
     * deposit flow because /Remit is the symmetric counterpart:
     * same VendorReference + PrimaryAccountNumber shape, just
     * money moving the other way. Concat order:
     *
     *   api_key + api_secret + EventType + VendorID +
     *   VendorReference + PrimaryAccountNumber + Amount + Currency
     *
     * Same matrix_zebra_hmac_method filter as compute_hmac() and
     * compute_capture_hmac() so an operator whose Bibimoney env
     * expects plain SHA-256 (vs. HMAC-SHA256 keyed by api_secret)
     * flips one filter and all three signing paths follow.
     */
    private function compute_remit_hmac($event_type, $vendor_reference, $primary_account, $amount_minor, $currency) {
        $message = $this->api_key
                 . $this->api_secret
                 . $event_type
                 . $this->vendor_id
                 . $vendor_reference
                 . $primary_account
                 . (string) $amount_minor
                 . strtoupper($currency);

        $method = (string) apply_filters('matrix_zebra_hmac_method', 'hmac_sha256');
        if ($method === 'sha256') {
            return hash('sha256', $message);
        }
        return hash_hmac('sha256', $message, $this->api_secret);
    }

    /**
     * HMAC for the /Dispense endpoint (vendor -> customer bank
     * payout).
     *
     * Distinct from compute_remit_hmac() because the destination
     * shape differs: /Dispense routes to a bank account, not a
     * wallet identifier, so the concat carries AccountNumber and
     * BankCode where /Remit carries PrimaryAccountNumber. Concat
     * order:
     *
     *   api_key + api_secret + EventType + VendorID +
     *   VendorReference + AccountNumber + BankCode + Amount +
     *   Currency
     *
     * AccountNumber is the destination NUBAN (10-digit Nigerian
     * standard, but we don't enforce length — Bibimoney's
     * partner banks sometimes accept regional formats).
     *
     * BankCode is the CBN sortCode for the destination institution
     * (3 to 6 digit numeric, depending on whether it's a
     * commercial bank or fintech). The form-side validator
     * reuses the existing Fintava bank-payout sortCode shape
     * check so a single typo doesn't ride all the way to
     * Bibimoney.
     *
     * Same matrix_zebra_hmac_method filter as the deposit and
     * remit signing paths, so an operator whose Bibimoney env
     * expects plain SHA-256 over the concat (vs. HMAC-SHA256
     * keyed by api_secret) flips one filter and all four
     * signing paths follow.
     */
    private function compute_dispense_hmac($event_type, $vendor_reference, $account_number, $bank_code, $amount_minor, $currency) {
        $message = $this->api_key
                 . $this->api_secret
                 . $event_type
                 . $this->vendor_id
                 . $vendor_reference
                 . $account_number
                 . $bank_code
                 . (string) $amount_minor
                 . strtoupper($currency);

        $method = (string) apply_filters('matrix_zebra_hmac_method', 'hmac_sha256');
        if ($method === 'sha256') {
            return hash('sha256', $message);
        }
        return hash_hmac('sha256', $message, $this->api_secret);
    }

    /**
     * Low-level POST to a vendor endpoint. Always returns an array
     * shaped like the platform's standard JSON envelope (Status /
     * StatusCode / ErrorText / Information / PSPreference / GUID),
     * even on transport-level failures, so callers don't have to
     * juggle WP_Error and array branches.
     *
     * Adds two operator-friendly things on top of wp_remote_post:
     *   - Logs the StatusCode and any ErrorText to error_log when
     *     the platform reports a non-success code. Helps debugging
     *     misconfigured HMACs without leaking secrets to users.
     *   - Rejects calls when the gateway is misconfigured, so the
     *     operator gets a clear error instead of a 401 from the
     *     platform.
     */
    private function post($endpoint, array $body) {
        if (!$this->is_configured()) {
            return [
                'Status'      => 'Error',
                'StatusCode'  => '400',
                'ErrorText'   => __('Zebra Wallet is not fully configured (api_key / api_secret / vendor_id required).', 'matrix-mlm'),
            ];
        }

        $url = rtrim($this->base_url, '/') . '/' . ltrim($endpoint, '/');

        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => '*/*',
            ],
            'body'    => wp_json_encode($body),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            error_log(sprintf(
                '[Matrix Zebra] %s transport error: %s',
                $endpoint,
                $response->get_error_message()
            ));
            return [
                'Status'     => 'Error',
                'StatusCode' => '920',
                'ErrorText'  => $response->get_error_message(),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = (string) wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            error_log(sprintf(
                '[Matrix Zebra] %s non-JSON response (HTTP %d): %s',
                $endpoint,
                $code,
                substr($raw, 0, 300)
            ));
            return [
                'Status'     => 'Error',
                'StatusCode' => (string) $code,
                'ErrorText'  => __('Unexpected response from Zebra Wallet.', 'matrix-mlm'),
            ];
        }

        $status_code = (string) ($data['StatusCode'] ?? '');
        if (!self::is_success_code($status_code)) {
            error_log(sprintf(
                '[Matrix Zebra] %s returned StatusCode=%s ErrorText=%s',
                $endpoint,
                $status_code,
                (string) ($data['ErrorText'] ?? $data['Status'] ?? '')
            ));
        }

        return $data;
    }

    /**
     * Per spec section 6.2.5, status codes are 3 chars; success is
     * "000" (uncharged) or "00A" (charged). Everything else is a
     * failure. Implemented as a static so the IPN handler can also
     * use it without instantiating the gateway.
     */
    public static function is_success_code($code) {
        return $code === '000' || $code === '00A';
    }

    /* ====================================================================
     * Lookup endpoints (section 3 of the spec)
     *
     * Used for admin-side diagnostics and could be wired into the
     * deposit form for "verify wallet exists before sending OTP".
     * Minimal wrappers; callers get the raw decoded response.
     * ==================================================================== */

    /** POST /MobileLookup */
    public function lookup_mobile($msisdn) {
        return $this->post('MobileLookup', [
            'api_key'    => $this->api_key,
            'api_secret' => $this->api_secret,
            'VendorID'   => $this->vendor_id,
            'MSISDN'     => (string) $msisdn,
        ]);
    }

    /** POST /CustomerLookup */
    public function lookup_customer($reference) {
        return $this->post('CustomerLookup', [
            'api_key'    => $this->api_key,
            'api_secret' => $this->api_secret,
            'VendorID'   => $this->vendor_id,
            'Reference'  => (string) $reference,
        ]);
    }

    /** POST /AccountLookup */
    public function lookup_account($reference) {
        return $this->post('AccountLookup', [
            'api_key'    => $this->api_key,
            'api_secret' => $this->api_secret,
            'VendorID'   => $this->vendor_id,
            'Reference'  => (string) $reference,
        ]);
    }

    /** POST /BalanceLookup */
    public function lookup_balance($reference) {
        return $this->post('BalanceLookup', [
            'api_key'    => $this->api_key,
            'api_secret' => $this->api_secret,
            'VendorID'   => $this->vendor_id,
            'Reference'  => (string) $reference,
        ]);
    }

    /** POST /VendorBalanceLookup */
    public function lookup_vendor_balance() {
        return $this->post('VendorBalanceLookup', [
            'api_key'    => $this->api_key,
            'api_secret' => $this->api_secret,
            'VendorID'   => $this->vendor_id,
        ]);
    }

    /* ====================================================================
     * Deposit flow (section 4.1 of the spec)
     *
     * Two-step:
     *   1. initialize_payment()      -> POST /PaymentAuth (sends OTP)
     *   2. complete_otp_payment()    -> POST /Payment     (uses OTP)
     *
     * The matrix_deposits row is created in
     * Matrix_MLM_Core::process_deposit() before step 1 fires, so
     * the deposit_id is always available here.
     * ==================================================================== */

    /**
     * Step 1: PaymentAuth.
     *
     * Generates a unique VendorReference (also stored as
     * transaction_id on the deposit row so the IPN can find it),
     * computes the HMAC, posts /PaymentAuth, and on success stores
     * the platform-issued PSPreference back on the deposit row so
     * step 2 can target it. The same PSPreference is also returned
     * to the frontend so the JS can echo it back in the step-2
     * AJAX call without a server round-trip.
     */
    public function initialize_payment($deposit_id, $amount, $user_id, $primary_account_number) {
        global $wpdb;

        $primary_account_number = trim((string) $primary_account_number);
        if ($primary_account_number === '') {
            return [
                'success' => false,
                'message' => __('Please enter your Zebra Wallet number, IWAN, or mobile number.', 'matrix-mlm'),
            ];
        }

        if (!$this->is_configured()) {
            return [
                'success' => false,
                'message' => __('Zebra Wallet is not fully configured. Contact the administrator.', 'matrix-mlm'),
            ];
        }

        $deposit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_deposits WHERE id = %d AND user_id = %d",
            $deposit_id,
            $user_id
        ));
        if (!$deposit) {
            return ['success' => false, 'message' => __('Deposit record not found.', 'matrix-mlm')];
        }

        $currency = strtoupper((string) ($deposit->currency ?? $this->default_currency));
        // Spec section 6.1.3: amount on the wire is integer minor
        // units. NGN/USD/most ISO currencies have 2 minor digits.
        $amount_minor = (int) round(((float) $amount) * 100);

        // VendorReference must be unique per /PaymentAuth call
        // (spec 4.1.1). Format mirrors Paystack: prefix + deposit
        // id + timestamp, deterministic for support traceability
        // and short enough to fit in transaction_id varchar(255).
        $vendor_reference = sprintf('MTX-ZBR-%d-%d', $deposit_id, time());

        $hmac = $this->compute_hmac(
            'AUTHENTICATE',
            $vendor_reference,
            $primary_account_number,
            $amount_minor,
            $currency
        );

        $payload = [
            'api_key'              => $this->api_key,
            'api_secret'           => $this->api_secret,
            'VendorID'             => $this->vendor_id,
            'EventType'            => 'AUTHENTICATE',
            'VendorReference'      => $vendor_reference,
            'PrimaryAccountNumber' => $primary_account_number,
            'Amount'               => $amount_minor,
            'Currency'             => $currency,
            'HMACdigest'           => $hmac,
        ];

        $response = $this->post('PaymentAuth', $payload);

        $status_code = (string) ($response['StatusCode'] ?? '');
        if (!self::is_success_code($status_code)) {
            return [
                'success' => false,
                'message' => (string) ($response['ErrorText'] ?? $response['Status'] ?? __('Could not start Zebra Wallet payment.', 'matrix-mlm')),
            ];
        }

        $psp_reference = (string) ($response['PSPreference'] ?? '');
        if ($psp_reference === '') {
            return [
                'success' => false,
                'message' => __('Zebra Wallet did not return a payment reference.', 'matrix-mlm'),
            ];
        }

        // Store BOTH our VendorReference AND the platform's
        // PSPreference on the deposit row. VendorReference goes
        // into transaction_id (it's what the IPN will echo back so
        // we can find the row). PSPreference goes into
        // gateway_response as a stash for step 2 + audit.
        $wpdb->update(
            $wpdb->prefix . 'matrix_deposits',
            [
                'transaction_id'   => $vendor_reference,
                'gateway_response' => wp_json_encode([
                    'step'             => 'auth',
                    'psp_reference'    => $psp_reference,
                    'vendor_reference' => $vendor_reference,
                    'primary_account'  => self::mask_account($primary_account_number),
                    'auth_response'    => $response,
                ]),
            ],
            ['id' => $deposit_id]
        );

        return [
            'success'         => true,
            'requires_otp'    => true,
            'deposit_id'      => (int) $deposit_id,
            'psp_reference'   => $psp_reference,
            'vendor_reference'=> $vendor_reference,
            'message'         => __('An OTP has been sent to the wallet holder\'s phone. Enter it below to complete payment.', 'matrix-mlm'),
        ];
    }

    /**
     * Step 2: Payment with OTP.
     *
     * Caller MUST have verified the deposit row belongs to the
     * current user (Matrix_MLM_Core does this in the
     * zebra_complete_otp branch). We re-verify here as a defense
     * in depth, since this method is also reachable from admin
     * tooling.
     */
    public function complete_otp_payment($deposit_id, $user_id, $psp_reference, $otp) {
        global $wpdb;

        $psp_reference = trim((string) $psp_reference);
        $otp           = trim((string) $otp);
        if ($psp_reference === '' || $otp === '') {
            return ['success' => false, 'message' => __('OTP and payment reference are required.', 'matrix-mlm')];
        }

        $deposit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_deposits WHERE id = %d AND user_id = %d AND gateway = 'zebra'",
            $deposit_id,
            $user_id
        ));
        if (!$deposit) {
            return ['success' => false, 'message' => __('Deposit record not found.', 'matrix-mlm')];
        }
        if ($deposit->status === 'completed') {
            return ['success' => true, 'already_completed' => true, 'message' => __('Payment already confirmed.', 'matrix-mlm')];
        }

        // Confirm the PSPreference matches what step 1 stashed.
        // Anything else means the client tampered with the
        // reference or replayed an old one.
        $stash = json_decode((string) $deposit->gateway_response, true);
        $stashed_psp = is_array($stash) ? (string) ($stash['psp_reference'] ?? '') : '';
        if ($stashed_psp === '' || $stashed_psp !== $psp_reference) {
            return ['success' => false, 'message' => __('Payment reference mismatch. Please start the deposit again.', 'matrix-mlm')];
        }

        // EventType branches:
        //   AUTO_CAPTURE — charge the wallet immediately, single
        //                  step settles the deposit.
        //   PRE_AUTH     — authorise + hold; the customer's wallet
        //                  is debited but the funds aren't yet
        //                  released to the merchant. The deposit
        //                  row sits at status='pending_capture'
        //                  until an admin triggers
        //                  /CaptureOrCancel.
        //
        // Selection is operator-driven (Admin -> Gateways -> Zebra
        // Wallet -> "Hold authorisation; capture later") with a
        // per-deposit override via matrix_zebra_deposit_pre_auth.
        // Spec 4.1.2 confirms AUTO_CAPTURE is the platform default
        // when EventType is omitted; we set it explicitly so the
        // wire log is unambiguous in support tickets.
        $use_pre_auth = $this->is_pre_auth_enabled((int) $deposit_id);
        $event_type   = $use_pre_auth ? self::EVENT_PRE_AUTH : self::EVENT_AUTO_CAPTURE;

        $payload = [
            'api_key'      => $this->api_key,
            'api_secret'   => $this->api_secret,
            'VendorID'     => $this->vendor_id,
            'EventType'    => $event_type,
            'PSPreference' => $psp_reference,
            'OTP'          => $otp,
            'UserData'     => [
                'deposit_id' => (int) $deposit_id,
                'user_id'    => (int) $user_id,
                'plugin'     => 'matrix-mlm',
            ],
        ];

        $response = $this->post('Payment', $payload);

        $status_code = (string) ($response['StatusCode'] ?? '');
        if (!self::is_success_code($status_code)) {
            // Common non-success codes per spec 6.2.5:
            //   610 = Pay Auth Fail
            //   620 = OTP Expired
            //   630 = OTP Used
            //   800 = HMAC Invalid
            // Surface ErrorText so the user sees the actual
            // problem rather than a generic "payment failed".
            return [
                'success' => false,
                'message' => (string) ($response['ErrorText'] ?? $response['Status'] ?? __('Payment could not be completed.', 'matrix-mlm')),
            ];
        }

        $info = isset($response['Information']) && is_array($response['Information']) ? $response['Information'] : [];
        $payment_data = array_merge($info, [
            'StatusCode'   => $status_code,
            'EventType'    => $event_type,
            'PSPreference' => $psp_reference,
            'amount_minor' => (int) ($info['Value'] ?? 0),
            'currency'     => (string) ($info['CurrencyISO'] ?? $deposit->currency),
        ]);

        if ($use_pre_auth) {
            // Successful synchronous AUTH-only. Park the deposit
            // at 'pending_capture' (no wallet credit) and report
            // a hold-confirmed message. The IPN may also fire
            // PRE_AUTH; park_pending_capture() is idempotent
            // (conditional UPDATE on status='pending') so the
            // duplicate is a no-op.
            $this->park_pending_capture($deposit->transaction_id, $payment_data, 'sync');
            return [
                'success'         => true,
                'pending_capture' => true,
                'message'         => __('Authorisation confirmed. Funds are on hold; the merchant will capture or release shortly.', 'matrix-mlm'),
            ];
        }

        // AUTO_CAPTURE path: synchronous completion. We can
        // credit the deposit right now from this code path —
        // but we still expect an IPN, and complete_deposit() is
        // idempotent (conditional update on status='pending'),
        // so the duplicate IPN won't double-credit.
        $this->complete_deposit($deposit->transaction_id, $payment_data, 'sync');

        return [
            'success' => true,
            'message' => __('Payment confirmed. Your wallet has been credited.', 'matrix-mlm'),
        ];
    }

    /* ====================================================================
     * /CaptureOrCancel — pre-auth state machine (spec section 4.x)
     *
     * Lifecycle for a pre-auth deposit:
     *
     *   1. process_deposit() inserts a pending row.
     *   2. /PaymentAuth (initialize_payment) -> OTP sent.
     *   3. /Payment with EventType=PRE_AUTH (complete_otp_payment
     *      when is_pre_auth_enabled() is true) -> deposit
     *      transitions pending -> pending_capture. Customer's
     *      wallet is debited but funds aren't released. NO Matrix
     *      wallet credit yet.
     *   4. Admin clicks Capture or Cancel on the deposits page.
     *      capture_deposit() / cancel_deposit() POST to
     *      /CaptureOrCancel and transition pending_capture ->
     *      completed (credit) or pending_capture -> cancelled
     *      (no credit, no refund — funds were never moved to
     *      the merchant operating wallet).
     *   5. Bibimoney also fires CAPTURE / CANCEL IPNs which
     *      handle_webhook() routes to the same idempotent
     *      finalisers, so a double-delivery is a no-op.
     *
     * Both methods enforce gateway='zebra' AND status='pending_capture'
     * at the SQL layer (conditional UPDATE), so a stray click on a
     * deposit row from a different gateway, or on a row that's
     * already been captured/cancelled by an earlier admin / IPN,
     * fails cleanly without side-effects.
     * ==================================================================== */

    /**
     * Capture a held authorisation. Transitions a pending_capture
     * deposit to completed and credits the Matrix wallet.
     *
     * Caller is the admin AJAX handler in class-matrix-admin.php
     * (zebra_capture_deposit), which has already gated on
     * manage_matrix_deposits + nonce. We re-verify the deposit's
     * gateway='zebra' and current status here as defense in
     * depth.
     */
    public function capture_deposit($deposit_id, $note = '') {
        return $this->dispatch_capture_or_cancel((int) $deposit_id, self::EVENT_CAPTURE, (string) $note);
    }

    /**
     * Cancel a held authorisation. Transitions a pending_capture
     * deposit to cancelled. The customer is NOT refunded by the
     * Matrix side because they were never credited — the funds
     * Bibimoney debited from their wallet are released back by
     * the platform when the cancel call succeeds (per spec).
     */
    public function cancel_deposit($deposit_id, $note = '') {
        return $this->dispatch_capture_or_cancel((int) $deposit_id, self::EVENT_CANCEL, (string) $note);
    }

    /**
     * Shared body for capture_deposit() and cancel_deposit().
     * Reads the stashed PSPreference + amount + currency,
     * computes the capture-shape HMAC, posts /CaptureOrCancel,
     * and on success flips the deposit row through the matching
     * idempotent finaliser. Returns a [success, message] envelope
     * the AJAX handler can forward verbatim.
     */
    private function dispatch_capture_or_cancel($deposit_id, $event_type, $note) {
        global $wpdb;

        if (!$this->is_configured()) {
            return [
                'success' => false,
                'message' => __('Zebra Wallet is not fully configured. Contact the administrator.', 'matrix-mlm'),
            ];
        }

        if ($event_type !== self::EVENT_CAPTURE && $event_type !== self::EVENT_CANCEL) {
            return ['success' => false, 'message' => __('Invalid capture action.', 'matrix-mlm')];
        }

        $deposit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_deposits WHERE id = %d AND gateway = 'zebra'",
            $deposit_id
        ));
        if (!$deposit) {
            return ['success' => false, 'message' => __('Zebra deposit not found.', 'matrix-mlm')];
        }
        if ($deposit->status !== 'pending_capture') {
            // Already finalised — return a friendly message
            // rather than calling the platform with a stale
            // PSPreference.
            return [
                'success' => false,
                'message' => sprintf(
                    /* translators: 1: deposit status, 2: action */
                    __('This deposit is %1$s and cannot be %2$s.', 'matrix-mlm'),
                    $deposit->status,
                    $event_type === self::EVENT_CAPTURE ? __('captured', 'matrix-mlm') : __('cancelled', 'matrix-mlm')
                ),
            ];
        }

        $stash = json_decode((string) $deposit->gateway_response, true);
        $psp_reference = is_array($stash) ? (string) ($stash['psp_reference'] ?? '') : '';
        if ($psp_reference === '') {
            // Belt-and-braces: a pending_capture row without a
            // stashed PSPreference is a schema-drift bug, not a
            // recoverable state. Surface it loudly.
            error_log(sprintf(
                '[Matrix Zebra] capture/cancel: missing PSPreference for deposit #%d (status=%s)',
                $deposit_id,
                $deposit->status
            ));
            return ['success' => false, 'message' => __('Missing payment reference for this deposit.', 'matrix-mlm')];
        }

        $currency     = strtoupper((string) ($deposit->currency ?? $this->default_currency));
        $amount_minor = (int) round(((float) $deposit->amount) * 100);

        $hmac = $this->compute_capture_hmac($event_type, $psp_reference, $amount_minor, $currency);

        $payload = [
            'api_key'      => $this->api_key,
            'api_secret'   => $this->api_secret,
            'VendorID'     => $this->vendor_id,
            'EventType'    => $event_type,
            'PSPreference' => $psp_reference,
            'Amount'       => $amount_minor,
            'Currency'     => $currency,
            'HMACdigest'   => $hmac,
            'UserData'     => [
                'deposit_id' => (int) $deposit_id,
                'plugin'     => 'matrix-mlm',
                'note'       => $note,
            ],
        ];

        $response = $this->post('CaptureOrCancel', $payload);

        $status_code = (string) ($response['StatusCode'] ?? '');
        if (!self::is_success_code($status_code)) {
            return [
                'success' => false,
                'message' => (string) ($response['ErrorText'] ?? $response['Status'] ?? __('Capture/Cancel could not be completed.', 'matrix-mlm')),
            ];
        }

        $info = isset($response['Information']) && is_array($response['Information']) ? $response['Information'] : [];
        $payment_data = array_merge($info, [
            'StatusCode'   => $status_code,
            'EventType'    => $event_type,
            'PSPreference' => $psp_reference,
            'amount_minor' => (int) ($info['Value'] ?? $amount_minor),
            'currency'     => (string) ($info['CurrencyISO'] ?? $currency),
            'admin_note'   => $note,
        ]);

        if ($event_type === self::EVENT_CAPTURE) {
            $this->finalize_capture($deposit->transaction_id, $payment_data, 'sync');
            return [
                'success' => true,
                'message' => __('Authorisation captured. The wallet has been credited.', 'matrix-mlm'),
            ];
        }

        // Cancel
        $this->finalize_cancel($deposit->transaction_id, $payment_data, 'sync');
        return [
            'success' => true,
            'message' => __('Authorisation released. The customer was not charged.', 'matrix-mlm'),
        ];
    }

    /* ====================================================================
     * /Remit — vendor -> customer payout
     *
     * Plugs the Zebra (Bibimoney) platform into the existing
     * matrix_withdrawals admin-approval flow as a payout rail:
     *
     *   1. A pending matrix_withdrawals row exists with
     *      gateway='zebra' and account_details holding the
     *      customer's Zebra Wallet identifier (IWAN, MSISDN, or
     *      local reference — same shape /PaymentAuth accepts on
     *      the deposit side).
     *
     *   2. Admin clicks Approve on the Withdrawals page. The
     *      AJAX handler in class-matrix-admin.php dispatches to
     *      remit_to_account(), passing the row id.
     *
     *   3. remit_to_account() acquires a per-row lock by
     *      conditionally UPDATEing pending -> processing. Two
     *      admins clicking Approve on the same row in the same
     *      second see exactly one winner; the loser bails out
     *      with a "already being processed" message before any
     *      money moves.
     *
     *   4. The lock-holder posts /Remit with EventType=REMIT.
     *      Both the endpoint path and the EventType are
     *      filterable (matrix_zebra_remit_endpoint /
     *      matrix_zebra_remit_event_type) because the published
     *      Bibimoney spec we have is ambiguous about the exact
     *      tokens the platform expects, and we don't want a
     *      one-line spec change to require a code patch.
     *
     *   5. On success: processing -> approved with the audit
     *      stash (PSPreference, raw response, source='sync').
     *      Customer notification fires once. Operator sees a
     *      green "Remit completed" banner.
     *
     *   6. On non-success status code: processing -> pending
     *      (release the lock so the operator can retry).
     *      gateway_response stashes the failure detail so the
     *      operator can see why; admin_note is left untouched.
     *
     *   7. On transport error (timeout / network): same as 6.
     *      Bibimoney either credited the customer or didn't,
     *      and we don't know — the operator retries, and the
     *      idempotent VendorReference (regenerated per attempt
     *      because Bibimoney's /Remit doesn't accept a duplicate
     *      VendorReference for the same vendor) means a stuck
     *      retry loop self-resolves once Bibimoney finishes
     *      processing the original.
     *
     * The /Remit endpoint does NOT have an IPN counterpart in
     * scope here. /Notify (dispute / fraud / chargeback) is the
     * platform-side post-settlement event and is reserved for a
     * separate follow-up. /Remit is sync-only for now: if the
     * sync response says success, we treat the payout as
     * settled. A future PR can add an async-confirmation path
     * if the operator's /Remit settlement window grows beyond
     * the HTTP timeout we set in post().
     *
     * Idempotence and double-credit defenses:
     *
     *   - The pending -> processing conditional UPDATE is the
     *     primary lock. Only one /Remit call fires per row.
     *
     *   - VendorReference is freshly minted per attempt
     *     (REM-{withdrawal_id}-{microtime}) so a retry after a
     *     transient failure doesn't reuse the prior reference.
     *     Bibimoney's spec says VendorReference must be unique
     *     per /Remit call (same constraint as /PaymentAuth);
     *     reusing one would either no-op or error depending on
     *     env build, neither of which is acceptable for a
     *     retry.
     *
     *   - Reject path also handles 'processing' (releases lock
     *     to 'rejected' + refunds the Matrix wallet) so an
     *     operator who mis-clicks Approve and immediately
     *     clicks Reject still ends up with consistent state.
     *
     * No /Remit IPN dispatch exists today, but if Bibimoney
     * adds one later, the ack pattern in handle_webhook() can
     * route it through finalize_remit() with a conditional
     * UPDATE keyed on status='processing' (or 'approved' for a
     * post-settle ack), mirroring the deposit path.
     * ==================================================================== */

    /**
     * Public dispatch from class-matrix-admin.php's
     * approve_withdrawal handler. Returns the standard
     * [success, message] envelope the AJAX layer forwards
     * verbatim.
     *
     * Caller has already gated on manage_matrix_mlm + nonce.
     * We re-verify the row's gateway='zebra' here as defense
     * in depth.
     */
    public function remit_to_account($withdrawal_id, $note = '') {
        global $wpdb;

        $withdrawal_id = (int) $withdrawal_id;
        if ($withdrawal_id <= 0) {
            return ['success' => false, 'message' => __('Invalid withdrawal id.', 'matrix-mlm')];
        }

        if (!$this->is_configured()) {
            return [
                'success' => false,
                'message' => __('Zebra Wallet is not fully configured. Contact the administrator.', 'matrix-mlm'),
            ];
        }

        $table = $wpdb->prefix . 'matrix_withdrawals';

        // Read first so we can validate the gateway + amount + the
        // operator-entered Zebra Wallet identifier before we even
        // attempt the lock. Any of these missing means the row
        // wasn't created for /Remit and we should bail without
        // touching the platform.
        $withdrawal = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $withdrawal_id
        ));
        if (!$withdrawal) {
            return ['success' => false, 'message' => __('Withdrawal not found.', 'matrix-mlm')];
        }
        if (strtolower((string) ($withdrawal->gateway ?? '')) !== 'zebra') {
            return ['success' => false, 'message' => __('This withdrawal is not configured for Zebra Wallet.', 'matrix-mlm')];
        }
        if ($withdrawal->status !== 'pending') {
            return [
                'success' => false,
                'message' => sprintf(
                    /* translators: %s = current withdrawal status */
                    __('This withdrawal is %s and cannot be remitted.', 'matrix-mlm'),
                    $withdrawal->status
                ),
            ];
        }

        $primary_account = trim((string) ($withdrawal->account_details ?? ''));
        if ($primary_account === '') {
            return ['success' => false, 'message' => __('Missing customer Zebra Wallet identifier on this withdrawal.', 'matrix-mlm')];
        }

        // Acquire the lock. Conditional UPDATE pending ->
        // processing. Two simultaneous Approve clicks see
        // exactly one winner here; the loser's $rows is 0 and
        // we bail before any platform call fires.
        $rows = $wpdb->update(
            $table,
            ['status' => 'processing'],
            ['id' => $withdrawal_id, 'status' => 'pending']
        );
        if ($rows !== 1) {
            return ['success' => false, 'message' => __('This withdrawal is already being processed.', 'matrix-mlm')];
        }

        $currency       = strtoupper((string) ($withdrawal->currency ?? $this->default_currency));
        // Per spec section 6.1.3 amount on the wire is integer
        // minor units. We send net_amount (the figure already
        // displayed to the user / operator on the row) — the
        // plugin-side charge is bookkept separately on the
        // matrix_withdrawals row.
        $amount_minor   = (int) round(((float) $withdrawal->net_amount) * 100);

        // Fresh VendorReference per attempt (see retry rationale
        // in the section docblock above). Microtime suffix
        // guarantees uniqueness even if an operator double-clicks
        // through the lock somehow.
        $vendor_reference = sprintf(
            'MTX-RMT-%d-%d-%s',
            $withdrawal_id,
            time(),
            substr(str_replace('.', '', (string) microtime(true)), -4)
        );

        $event_type = (string) apply_filters('matrix_zebra_remit_event_type', self::EVENT_REMIT);
        $endpoint   = (string) apply_filters('matrix_zebra_remit_endpoint', 'Remit');

        $hmac = $this->compute_remit_hmac(
            $event_type,
            $vendor_reference,
            $primary_account,
            $amount_minor,
            $currency
        );

        $payload = [
            'api_key'              => $this->api_key,
            'api_secret'           => $this->api_secret,
            'VendorID'             => $this->vendor_id,
            'EventType'            => $event_type,
            'VendorReference'      => $vendor_reference,
            'PrimaryAccountNumber' => $primary_account,
            'Amount'               => $amount_minor,
            'Currency'             => $currency,
            'HMACdigest'           => $hmac,
            'UserData'             => [
                'withdrawal_id' => $withdrawal_id,
                'user_id'       => (int) $withdrawal->user_id,
                'plugin'        => 'matrix-mlm',
                'note'          => (string) $note,
            ],
        ];

        // Stash the in-flight reference + masked account on the
        // row before the network call fires so a crashed PHP
        // worker still leaves an audit trail for the operator
        // to reconcile.
        $wpdb->update(
            $table,
            [
                'transaction_id'   => $vendor_reference,
                'gateway_response' => wp_json_encode([
                    'step'             => 'remit_in_flight',
                    'vendor_reference' => $vendor_reference,
                    'event_type'       => $event_type,
                    'endpoint'         => $endpoint,
                    'primary_account'  => self::mask_account($primary_account),
                    'amount_minor'     => $amount_minor,
                    'currency'         => $currency,
                    'note'             => (string) $note,
                ]),
            ],
            ['id' => $withdrawal_id]
        );

        $response = $this->post($endpoint, $payload);

        $status_code = (string) ($response['StatusCode'] ?? '');
        if (!self::is_success_code($status_code)) {
            // Release the lock back to 'pending' so the operator
            // can retry from the same admin row. Stash the failure
            // for diagnosis in gateway_response.
            $error_text = (string) ($response['ErrorText'] ?? $response['Status'] ?? __('Remit failed.', 'matrix-mlm'));
            $this->release_remit_lock($withdrawal_id, $vendor_reference, $event_type, $endpoint, $primary_account, $amount_minor, $currency, $response, 'pending', $error_text);
            return [
                'success' => false,
                'message' => $error_text,
            ];
        }

        // Success. processing -> approved with the audit stash.
        $info = isset($response['Information']) && is_array($response['Information']) ? $response['Information'] : [];
        $psp_reference = (string) ($response['PSPreference'] ?? $info['PSPreference'] ?? '');

        $this->finalize_remit($withdrawal_id, $vendor_reference, $event_type, $endpoint, $primary_account, $amount_minor, $currency, $response, $psp_reference, (string) $note);

        return [
            'success'          => true,
            'message'          => __('Remit completed. The customer\'s Zebra Wallet has been credited.', 'matrix-mlm'),
            'transaction_id'   => $vendor_reference,
            'psp_reference'    => $psp_reference,
        ];
    }

    /**
     * Internal: success transition. processing -> approved with
     * audit stash. Conditional UPDATE keyed on the lock state so
     * a duplicate sync delivery (which shouldn't happen because
     * the platform-side /Remit is request/response, not async,
     * but defense in depth) collapses to a no-op.
     *
     * Customer notification fires inside the conditional so a
     * row that was already finalised by another process doesn't
     * generate a duplicate "withdrawal approved" email.
     */
    private function finalize_remit($withdrawal_id, $vendor_reference, $event_type, $endpoint, $primary_account, $amount_minor, $currency, $response, $psp_reference, $note) {
        global $wpdb;

        $table = $wpdb->prefix . 'matrix_withdrawals';

        $stash = [
            'step'             => 'remit_completed',
            'vendor_reference' => $vendor_reference,
            'psp_reference'    => $psp_reference,
            'event_type'       => $event_type,
            'endpoint'         => $endpoint,
            'primary_account'  => self::mask_account($primary_account),
            'amount_minor'     => $amount_minor,
            'currency'         => $currency,
            'source'           => 'sync',
            'note'             => $note,
            'response'         => $response,
        ];

        $updated = $wpdb->update(
            $table,
            [
                'status'           => 'approved',
                'gateway_response' => wp_json_encode($stash),
            ],
            ['id' => $withdrawal_id, 'status' => 'processing']
        );

        if ($updated === 1) {
            $withdrawal = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $withdrawal_id
            ));
            if ($withdrawal && class_exists('Matrix_MLM_Notifications')) {
                Matrix_MLM_Notifications::send_withdrawal_notification(
                    $withdrawal->user_id,
                    $withdrawal->amount,
                    'approved'
                );
            }
        }
    }

    /**
     * Internal: failure transition. processing -> $target_status
     * (either 'pending' to allow operator retry, or 'rejected'
     * to surface a non-retryable failure). Stash the failure
     * envelope on gateway_response for diagnosis and audit.
     *
     * No customer notification on this path — a transient
     * /Remit failure that the operator retries should not
     * emit a "withdrawal rejected" email and then a confusing
     * "withdrawal approved" email moments later. The reject_
     * withdrawal admin path remains the single channel for the
     * "rejected" customer notification.
     */
    private function release_remit_lock($withdrawal_id, $vendor_reference, $event_type, $endpoint, $primary_account, $amount_minor, $currency, $response, $target_status, $error_text) {
        global $wpdb;

        $table = $wpdb->prefix . 'matrix_withdrawals';

        $stash = [
            'step'             => 'remit_failed',
            'vendor_reference' => $vendor_reference,
            'event_type'       => $event_type,
            'endpoint'         => $endpoint,
            'primary_account'  => self::mask_account($primary_account),
            'amount_minor'     => $amount_minor,
            'currency'         => $currency,
            'source'           => 'sync',
            'error_text'       => $error_text,
            'response'         => $response,
        ];

        $wpdb->update(
            $table,
            [
                'status'           => $target_status,
                'gateway_response' => wp_json_encode($stash),
            ],
            ['id' => $withdrawal_id, 'status' => 'processing']
        );
    }

    /* ====================================================================
     * /Dispense — vendor -> customer bank payout
     *
     * Plugs Zebra (Bibimoney) /Dispense into the same
     * matrix_withdrawals admin-approval flow that /Remit lives
     * in. Architecturally this method mirrors remit_to_account()
     * one-for-one — same lock acquisition, same per-attempt
     * VendorReference, same processing -> approved/pending
     * transitions, same audit stash shape, same customer
     * notification on success — but the destination payload
     * carries bank routing instead of a wallet identifier:
     *
     *   1. matrix_withdrawals row exists with gateway='zebra'
     *      AND method='zebra_bank'. The dispatcher in
     *      Matrix_MLM_Admin::approve_withdrawal() branches on
     *      method when gateway='zebra' so /Remit and /Dispense
     *      route to the right handler.
     *
     *   2. account_details on the row holds a JSON envelope:
     *
     *        {
     *          "type"          : "bank",
     *          "bank_code"     : "058",            // CBN sortCode
     *          "bank_name"     : "GTBank",         // display only
     *          "account_number": "0123456789",
     *          "account_name"  : "Jane Doe"        // display only
     *        }
     *
     *      JSON shape rather than free-form text because
     *      /Dispense needs structured fields at API call time
     *      and parsing four-line operator notes is fragile. The
     *      schema column stays TEXT so legacy rows that pre-
     *      date this convention still render in the admin
     *      Withdrawals page (the table cell shows the raw blob
     *      and the operator can still triage).
     *
     *   3. Same pending -> processing lock as /Remit. Two
     *      Approve clicks within the same second see exactly
     *      one winner; the loser bails before /Dispense fires.
     *
     *   4. compute_dispense_hmac() builds the 9-field concat
     *      shape (the bank rail adds AccountNumber + BankCode
     *      vs Remit's single PrimaryAccountNumber). HMAC method
     *      is filterable through the same matrix_zebra_hmac_method
     *      hook so an operator only ever flips one switch
     *      between SHA-256 and HMAC-SHA256.
     *
     *   5. On success: processing -> approved with the audit
     *      stash. Customer notification fires once.
     *
     *   6. On non-success: processing -> pending (release the
     *      lock so the operator can retry). gateway_response
     *      stashes the failure detail so the operator can see
     *      why; admin_note is left untouched.
     *
     * Idempotence and double-credit defenses are identical to
     * /Remit's, see remit_to_account() for the rationale. The
     * only operationally meaningful difference is the
     * destination payload — and the fact that the payload
     * fields live behind a JSON parse rather than a single
     * column read.
     * ==================================================================== */

    /**
     * Public dispatch from class-matrix-admin.php's
     * approve_withdrawal handler when method='zebra_bank'.
     * Returns the standard [success, message] envelope the
     * AJAX layer forwards verbatim.
     */
    public function dispense_to_account($withdrawal_id, $note = '') {
        global $wpdb;

        $withdrawal_id = (int) $withdrawal_id;
        if ($withdrawal_id <= 0) {
            return ['success' => false, 'message' => __('Invalid withdrawal id.', 'matrix-mlm')];
        }

        if (!$this->is_configured()) {
            return [
                'success' => false,
                'message' => __('Zebra Wallet is not fully configured. Contact the administrator.', 'matrix-mlm'),
            ];
        }

        $table = $wpdb->prefix . 'matrix_withdrawals';

        $withdrawal = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $withdrawal_id
        ));
        if (!$withdrawal) {
            return ['success' => false, 'message' => __('Withdrawal not found.', 'matrix-mlm')];
        }
        if (strtolower((string) ($withdrawal->gateway ?? '')) !== 'zebra') {
            return ['success' => false, 'message' => __('This withdrawal is not configured for Zebra Wallet.', 'matrix-mlm')];
        }
        if ($withdrawal->status !== 'pending') {
            return [
                'success' => false,
                'message' => sprintf(
                    /* translators: %s = current withdrawal status */
                    __('This withdrawal is %s and cannot be dispensed.', 'matrix-mlm'),
                    $withdrawal->status
                ),
            ];
        }

        // Parse the structured destination envelope. Reject
        // anything that's not the expected JSON shape rather
        // than guessing — we'd rather an operator see a clear
        // "missing destination details" error and fix the row
        // than have a garbled payload land at Bibimoney.
        $details_raw = trim((string) ($withdrawal->account_details ?? ''));
        $details     = json_decode($details_raw, true);
        if (!is_array($details) || ($details['type'] ?? '') !== 'bank') {
            return [
                'success' => false,
                'message' => __('Withdrawal is missing structured bank account details (expected JSON with type=bank).', 'matrix-mlm'),
            ];
        }

        $account_number = trim((string) ($details['account_number'] ?? ''));
        $bank_code      = trim((string) ($details['bank_code'] ?? ''));
        $bank_name      = trim((string) ($details['bank_name'] ?? ''));
        $account_name   = trim((string) ($details['account_name'] ?? ''));

        if ($account_number === '' || $bank_code === '') {
            return [
                'success' => false,
                'message' => __('Bank account number and bank code are both required for /Dispense.', 'matrix-mlm'),
            ];
        }
        // Same shape check the Fintava bank-payout flow uses on
        // the /bank/credit/merchant validator: 3-digit CBN codes
        // for commercial banks, 5-6 digit NIBSS-issued codes for
        // fintechs. Anything else would be rejected by Bibimoney
        // anyway.
        if (!preg_match('/^\d{3,6}$/', $bank_code)) {
            return [
                'success' => false,
                'message' => __('Bank code must be 3-6 numeric digits (CBN or NIBSS sortCode).', 'matrix-mlm'),
            ];
        }
        if (!preg_match('/^\d{6,20}$/', $account_number)) {
            return [
                'success' => false,
                'message' => __('Bank account number must be 6-20 digits.', 'matrix-mlm'),
            ];
        }

        // Acquire the lock. Same conditional-UPDATE pattern as
        // /Remit — only the first request that flips pending ->
        // processing gets to call /Dispense.
        $rows = $wpdb->update(
            $table,
            ['status' => 'processing'],
            ['id' => $withdrawal_id, 'status' => 'pending']
        );
        if ($rows !== 1) {
            return ['success' => false, 'message' => __('This withdrawal is already being processed.', 'matrix-mlm')];
        }

        $currency     = strtoupper((string) ($withdrawal->currency ?? $this->default_currency));
        $amount_minor = (int) round(((float) $withdrawal->net_amount) * 100);

        // Fresh VendorReference per attempt (same retry
        // rationale as /Remit — Bibimoney's spec requires
        // VendorReference uniqueness).
        $vendor_reference = sprintf(
            'MTX-DSP-%d-%d-%s',
            $withdrawal_id,
            time(),
            substr(str_replace('.', '', (string) microtime(true)), -4)
        );

        $event_type = (string) apply_filters('matrix_zebra_dispense_event_type', self::EVENT_DISPENSE);
        $endpoint   = (string) apply_filters('matrix_zebra_dispense_endpoint', 'Dispense');

        $hmac = $this->compute_dispense_hmac(
            $event_type,
            $vendor_reference,
            $account_number,
            $bank_code,
            $amount_minor,
            $currency
        );

        $payload = [
            'api_key'         => $this->api_key,
            'api_secret'      => $this->api_secret,
            'VendorID'        => $this->vendor_id,
            'EventType'       => $event_type,
            'VendorReference' => $vendor_reference,
            'AccountNumber'   => $account_number,
            'BankCode'        => $bank_code,
            'BankName'        => $bank_name,
            'AccountName'     => $account_name,
            'Amount'          => $amount_minor,
            'Currency'        => $currency,
            'HMACdigest'      => $hmac,
            'UserData'        => [
                'withdrawal_id' => $withdrawal_id,
                'user_id'       => (int) $withdrawal->user_id,
                'plugin'        => 'matrix-mlm',
                'note'          => (string) $note,
            ],
        ];

        // Stash the in-flight reference + masked account on the
        // row before the network call so a crashed PHP worker
        // still leaves an audit trail.
        $wpdb->update(
            $table,
            [
                'transaction_id'   => $vendor_reference,
                'gateway_response' => wp_json_encode([
                    'step'             => 'dispense_in_flight',
                    'vendor_reference' => $vendor_reference,
                    'event_type'       => $event_type,
                    'endpoint'         => $endpoint,
                    'account_number'   => self::mask_account($account_number),
                    'bank_code'        => $bank_code,
                    'bank_name'        => $bank_name,
                    'amount_minor'     => $amount_minor,
                    'currency'         => $currency,
                    'note'             => (string) $note,
                ]),
            ],
            ['id' => $withdrawal_id]
        );

        $response = $this->post($endpoint, $payload);

        $status_code = (string) ($response['StatusCode'] ?? '');
        if (!self::is_success_code($status_code)) {
            $error_text = (string) ($response['ErrorText'] ?? $response['Status'] ?? __('Dispense failed.', 'matrix-mlm'));
            $this->release_dispense_lock(
                $withdrawal_id,
                $vendor_reference,
                $event_type,
                $endpoint,
                $account_number,
                $bank_code,
                $bank_name,
                $amount_minor,
                $currency,
                $response,
                'pending',
                $error_text
            );
            return [
                'success' => false,
                'message' => $error_text,
            ];
        }

        $info = isset($response['Information']) && is_array($response['Information']) ? $response['Information'] : [];
        $psp_reference = (string) ($response['PSPreference'] ?? $info['PSPreference'] ?? '');

        $this->finalize_dispense(
            $withdrawal_id,
            $vendor_reference,
            $event_type,
            $endpoint,
            $account_number,
            $bank_code,
            $bank_name,
            $amount_minor,
            $currency,
            $response,
            $psp_reference,
            (string) $note
        );

        return [
            'success'        => true,
            'message'        => __('Dispense completed. The customer\'s bank account has been credited.', 'matrix-mlm'),
            'transaction_id' => $vendor_reference,
            'psp_reference'  => $psp_reference,
        ];
    }

    /**
     * Internal: success transition for /Dispense. processing ->
     * approved with audit stash. Conditional UPDATE keyed on
     * the lock state so a duplicate sync delivery (which the
     * platform shouldn't generate, but defense in depth)
     * collapses to a no-op.
     *
     * Customer notification fires inside the conditional so a
     * row that was already finalised by another process doesn't
     * generate a duplicate "withdrawal approved" email.
     */
    private function finalize_dispense($withdrawal_id, $vendor_reference, $event_type, $endpoint, $account_number, $bank_code, $bank_name, $amount_minor, $currency, $response, $psp_reference, $note) {
        global $wpdb;

        $table = $wpdb->prefix . 'matrix_withdrawals';

        $stash = [
            'step'             => 'dispense_completed',
            'vendor_reference' => $vendor_reference,
            'psp_reference'    => $psp_reference,
            'event_type'       => $event_type,
            'endpoint'         => $endpoint,
            'account_number'   => self::mask_account($account_number),
            'bank_code'        => $bank_code,
            'bank_name'        => $bank_name,
            'amount_minor'     => $amount_minor,
            'currency'         => $currency,
            'source'           => 'sync',
            'note'             => $note,
            'response'         => $response,
        ];

        $updated = $wpdb->update(
            $table,
            [
                'status'           => 'approved',
                'gateway_response' => wp_json_encode($stash),
            ],
            ['id' => $withdrawal_id, 'status' => 'processing']
        );

        if ($updated === 1) {
            $withdrawal = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $withdrawal_id
            ));
            if ($withdrawal && class_exists('Matrix_MLM_Notifications')) {
                Matrix_MLM_Notifications::send_withdrawal_notification(
                    $withdrawal->user_id,
                    $withdrawal->amount,
                    'approved'
                );
            }
        }
    }

    /**
     * Internal: failure transition for /Dispense. processing ->
     * $target_status with the failure envelope on
     * gateway_response for diagnosis. No customer notification
     * on this path — same rationale as release_remit_lock(): a
     * transient failure that the operator retries should not
     * emit a "withdrawal rejected" email and then a confusing
     * "withdrawal approved" email moments later.
     */
    private function release_dispense_lock($withdrawal_id, $vendor_reference, $event_type, $endpoint, $account_number, $bank_code, $bank_name, $amount_minor, $currency, $response, $target_status, $error_text) {
        global $wpdb;

        $table = $wpdb->prefix . 'matrix_withdrawals';

        $stash = [
            'step'             => 'dispense_failed',
            'vendor_reference' => $vendor_reference,
            'event_type'       => $event_type,
            'endpoint'         => $endpoint,
            'account_number'   => self::mask_account($account_number),
            'bank_code'        => $bank_code,
            'bank_name'        => $bank_name,
            'amount_minor'     => $amount_minor,
            'currency'         => $currency,
            'source'           => 'sync',
            'error_text'       => $error_text,
            'response'         => $response,
        ];

        $wpdb->update(
            $table,
            [
                'status'           => $target_status,
                'gateway_response' => wp_json_encode($stash),
            ],
            ['id' => $withdrawal_id, 'status' => 'processing']
        );
    }

    /* ====================================================================
     * IPN webhook (section 5 of the spec)
     * ==================================================================== */

    /**
     * Handle an incoming IPN.
     *
     * The route shape is /payment/callback/zebra/{token}. The
     * permission_callback in core.php has already confirmed the
     * token segment matches our configured webhook_token before
     * we get here, so by the time this method runs the request is
     * "from someone who registered as our IPN endpoint". We still
     * validate VendorReference / PSPreference / amount / currency
     * because token-only auth doesn't bind the IPN body to any
     * specific deposit.
     *
     * Returns the literal string "accepted" with a 200 status
     * (per spec section 5.1: "Your endpoint must return a HTTP
     * 200 header and the body 'accepted' otherwise our system
     * will keep sending until this condition is met.")
     */
    public function handle_webhook($request) {
        $payload = $request->get_body();
        $event   = json_decode($payload, true);

        if (!is_array($event)) {
            error_log('[Matrix Zebra IPN] non-JSON payload rejected');
            return new WP_REST_Response('rejected', 400);
        }

        $vendor_reference = (string) ($event['VendorReference'] ?? '');
        $psp_reference    = (string) ($event['PSPReference'] ?? $event['PSPreference'] ?? '');
        $status_code      = (string) ($event['StatusCode'] ?? '');
        $event_type       = (string) ($event['EventType'] ?? '');

        // Dispatch by EventType. Each branch fans out to an
        // idempotent finaliser that uses a conditional UPDATE
        // keyed on the expected source status, so duplicate
        // deliveries (sync + IPN, or two IPN retries) collapse
        // to a single state transition. Non-actionable events
        // (transfer success, refund, dispute, etc.) fall through
        // to the "log and accept" branch so the platform stops
        // retrying.
        $payment_data = [
            'StatusCode'   => $status_code,
            'EventType'    => $event_type,
            'PSPreference' => $psp_reference,
            'amount_minor' => (int) ($event['Amount'] ?? 0),
            'currency'     => strtoupper((string) ($event['Currency'] ?? '')),
            'raw'          => $event,
        ];

        $is_success = self::is_success_code($status_code);
        $has_ref    = $vendor_reference !== '';

        if ($is_success && $has_ref && $event_type === self::EVENT_AUTO_CAPTURE) {
            // Single-step settle. pending -> completed + credit.
            $this->complete_deposit($vendor_reference, $payment_data, 'ipn');
        } elseif ($is_success && $has_ref && $event_type === self::EVENT_PRE_AUTH) {
            // Hold confirmed. pending -> pending_capture, no credit.
            $this->park_pending_capture($vendor_reference, $payment_data, 'ipn');
        } elseif ($is_success && $has_ref && $event_type === self::EVENT_CAPTURE) {
            // Held auth captured. pending_capture -> completed + credit.
            $this->finalize_capture($vendor_reference, $payment_data, 'ipn');
        } elseif ($is_success && $has_ref && (
            $event_type === self::EVENT_CANCEL
            || $event_type === 'RELEASE'
            || $event_type === 'VOID'
        )) {
            // Held auth released. pending_capture -> cancelled.
            // Bibimoney's spec section 4.x uses CANCEL; some env
            // builds also emit RELEASE / VOID for the same
            // semantic event, so we accept all three.
            $this->finalize_cancel($vendor_reference, $payment_data, 'ipn');
        } else {
            error_log(sprintf(
                '[Matrix Zebra IPN] non-actionable event type=%s status=%s vref=%s',
                $event_type,
                $status_code,
                $vendor_reference
            ));
        }

        // Spec section 5.1 verbatim. The platform retries until
        // it sees this exact body, so deliver it on every
        // recognised + non-actionable event.
        return new WP_REST_Response('accepted', 200);
    }

    /* ====================================================================
     * Verify (user-return safety net)
     *
     * Called from /payment/verify/zebra. There's no redirect in
     * the Zebra deposit flow so this endpoint is a polling helper
     * rather than the primary completion path; it lets the
     * dashboard tell a user their payment is confirmed if they
     * close the OTP dialog before the IPN lands. We can't call a
     * "/transaction/lookup" because the spec doesn't expose one,
     * so the only thing we can do server-side is read the
     * deposit row's current state.
     * ==================================================================== */
    public function verify_payment($vendor_reference) {
        global $wpdb;

        $deposit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_deposits WHERE transaction_id = %s",
            $vendor_reference
        ));
        if (!$deposit) {
            return new WP_REST_Response(['status' => 'error', 'message' => __('Deposit not found', 'matrix-mlm')], 404);
        }

        if ($deposit->status === 'completed') {
            return new WP_REST_Response(['status' => 'success', 'message' => __('Payment confirmed', 'matrix-mlm')], 200);
        }
        if ($deposit->status === 'rejected' || $deposit->status === 'cancelled') {
            return new WP_REST_Response(['status' => 'failed', 'message' => __('Payment was not successful', 'matrix-mlm')], 400);
        }

        // Still pending. Don't try to second-guess the platform —
        // wait for the IPN. The frontend can poll this endpoint.
        return new WP_REST_Response([
            'status'  => 'pending',
            'message' => __('Payment is still being confirmed. Refresh in a few seconds.', 'matrix-mlm'),
        ], 200);
    }

    /* ====================================================================
     * Internal: complete a deposit row.
     *
     * Idempotent: conditional update on status='pending' means
     * only the first delivery (sync from /Payment OR IPN, whichever
     * lands first) credits the wallet. The losing delivery is a
     * no-op. Pattern matches Paystack and Flutterwave.
     *
     * Server-side amount/currency mismatch is recorded as
     * status='disputed' and never credited, so an attacker who
     * somehow forges an IPN with an inflated amount cannot trick
     * us into crediting it.
     * ==================================================================== */
    private function complete_deposit($vendor_reference, array $payment_data, $source) {
        global $wpdb;

        $deposit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_deposits WHERE transaction_id = %s AND status = 'pending'",
            $vendor_reference
        ));
        if (!$deposit) {
            // Either no such deposit (forged IPN — log and ignore)
            // or already completed by the other path (idempotent
            // win — silent).
            return;
        }

        $paid_minor      = (int) ($payment_data['amount_minor'] ?? 0);
        $expected_minor  = (int) round(((float) $deposit->amount) * 100);
        $paid_currency   = strtoupper((string) ($payment_data['currency'] ?? ''));
        $expected_currency = strtoupper((string) ($deposit->currency ?? get_option('matrix_mlm_currency', 'NGN')));

        // Underpayment OR currency mismatch -> dispute. We allow
        // OVERpayment to credit the deposit's net_amount as
        // configured (matches Paystack semantics).
        if ($paid_minor < $expected_minor
            || ($paid_currency !== '' && $paid_currency !== $expected_currency)) {
            $wpdb->update(
                $wpdb->prefix . 'matrix_deposits',
                [
                    'status'           => 'rejected',
                    'gateway_response' => wp_json_encode([
                        'reason'                => 'amount_or_currency_mismatch',
                        'expected_amount_minor' => $expected_minor,
                        'paid_amount_minor'     => $paid_minor,
                        'expected_currency'     => $expected_currency,
                        'paid_currency'         => $paid_currency,
                        'source'                => $source,
                        'payment_data'          => $payment_data,
                    ]),
                ],
                ['id' => $deposit->id]
            );
            error_log(sprintf(
                '[Matrix Zebra %s] Rejected deposit #%d ref=%s: expected %d %s got %d %s',
                strtoupper($source),
                $deposit->id,
                $vendor_reference,
                $expected_minor,
                $expected_currency,
                $paid_minor,
                $paid_currency
            ));
            if (class_exists('Matrix_MLM_Notifications')) {
                Matrix_MLM_Notifications::send_admin_notification(
                    'zebra_deposit_disputed',
                    sprintf(__('Zebra Wallet deposit rejected (Ref: %s) - amount/currency mismatch', 'matrix-mlm'), $vendor_reference)
                );
            }
            return;
        }

        $updated = $wpdb->update(
            $wpdb->prefix . 'matrix_deposits',
            [
                'status'           => 'completed',
                'gateway_response' => wp_json_encode([
                    'source'       => $source,
                    'payment_data' => $payment_data,
                ]),
            ],
            ['id' => $deposit->id, 'status' => 'pending']
        );
        if ($updated !== 1) {
            // Concurrent winner. Done.
            return;
        }

        $this->credit_completed_deposit($deposit, $vendor_reference, __('Zebra Wallet deposit (Ref: %s)', 'matrix-mlm'));
    }

    /* ====================================================================
     * Internal: park a pre-auth deposit at status='pending_capture'.
     *
     * Idempotent: conditional UPDATE on status='pending' means
     * only the first delivery (sync from /Payment OR IPN with
     * EventType=PRE_AUTH) makes the transition. The losing
     * delivery is a no-op.
     *
     * Amount/currency mismatch defenses run identically to
     * complete_deposit() — a forged or tampered IPN that claims
     * a different amount than we initiated never advances the
     * row past 'pending'.
     * ==================================================================== */
    private function park_pending_capture($vendor_reference, array $payment_data, $source) {
        global $wpdb;

        $deposit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_deposits WHERE transaction_id = %s AND status = 'pending'",
            $vendor_reference
        ));
        if (!$deposit) {
            // Either no such deposit, or already advanced past
            // 'pending' by the other path (idempotent win).
            return;
        }

        $paid_minor        = (int) ($payment_data['amount_minor'] ?? 0);
        $expected_minor    = (int) round(((float) $deposit->amount) * 100);
        $paid_currency     = strtoupper((string) ($payment_data['currency'] ?? ''));
        $expected_currency = strtoupper((string) ($deposit->currency ?? get_option('matrix_mlm_currency', 'NGN')));

        // Underpayment OR currency mismatch -> reject. Same
        // defense as complete_deposit() — never advance the row
        // on a payload we don't trust.
        if ($paid_minor < $expected_minor
            || ($paid_currency !== '' && $paid_currency !== $expected_currency)) {
            $wpdb->update(
                $wpdb->prefix . 'matrix_deposits',
                [
                    'status'           => 'rejected',
                    'gateway_response' => wp_json_encode([
                        'reason'                => 'amount_or_currency_mismatch_pre_auth',
                        'expected_amount_minor' => $expected_minor,
                        'paid_amount_minor'     => $paid_minor,
                        'expected_currency'     => $expected_currency,
                        'paid_currency'         => $paid_currency,
                        'source'                => $source,
                        'payment_data'          => $payment_data,
                    ]),
                ],
                ['id' => $deposit->id]
            );
            error_log(sprintf(
                '[Matrix Zebra %s] Rejected pre-auth deposit #%d ref=%s: expected %d %s got %d %s',
                strtoupper($source),
                $deposit->id,
                $vendor_reference,
                $expected_minor,
                $expected_currency,
                $paid_minor,
                $paid_currency
            ));
            if (class_exists('Matrix_MLM_Notifications')) {
                Matrix_MLM_Notifications::send_admin_notification(
                    'zebra_deposit_disputed',
                    sprintf(__('Zebra Wallet pre-auth rejected (Ref: %s) - amount/currency mismatch', 'matrix-mlm'), $vendor_reference)
                );
            }
            return;
        }

        // Preserve step-1's stash (psp_reference / vendor_reference
        // / masked primary_account) by merging the new payment_data
        // alongside it. capture_deposit() needs psp_reference at
        // admin-action time so the merge keeps everything in one
        // JSON blob.
        $existing_stash = json_decode((string) $deposit->gateway_response, true);
        if (!is_array($existing_stash)) {
            $existing_stash = [];
        }
        $existing_stash['step']            = 'pre_auth_held';
        $existing_stash['pre_auth_source'] = $source;
        $existing_stash['pre_auth_data']   = $payment_data;

        $updated = $wpdb->update(
            $wpdb->prefix . 'matrix_deposits',
            [
                'status'           => 'pending_capture',
                'gateway_response' => wp_json_encode($existing_stash),
            ],
            ['id' => $deposit->id, 'status' => 'pending']
        );
        if ($updated !== 1) {
            // Concurrent winner. Done.
            return;
        }

        // Operator notification: a fresh hold has landed and
        // needs an admin decision. We deliberately do NOT send
        // the user the "deposit completed" email yet — that
        // fires on capture.
        if (class_exists('Matrix_MLM_Notifications')) {
            Matrix_MLM_Notifications::send_admin_notification(
                'zebra_deposit_pending_capture',
                sprintf(
                    __('Zebra Wallet pre-auth held (Ref: %s) - Capture or Cancel from the Deposits admin page.', 'matrix-mlm'),
                    $vendor_reference
                )
            );
        }
    }

    /* ====================================================================
     * Internal: finalise a capture.
     *
     * Idempotent: conditional UPDATE on status='pending_capture'.
     * Both the synchronous /CaptureOrCancel response and the
     * matching CAPTURE IPN can fire; the second fires becomes a
     * no-op. Mirrors complete_deposit() for the credit + notify
     * path so user-facing messaging stays consistent across
     * AUTO_CAPTURE and pre-auth-then-capture deposits.
     * ==================================================================== */
    private function finalize_capture($vendor_reference, array $payment_data, $source) {
        global $wpdb;

        $deposit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_deposits WHERE transaction_id = %s AND status = 'pending_capture'",
            $vendor_reference
        ));
        if (!$deposit) {
            // Either no such deposit, already captured/cancelled
            // by the other path, or never reached pending_capture.
            return;
        }

        // We don't re-run amount/currency defenses here: the
        // amount was already validated at park_pending_capture()
        // time, and /CaptureOrCancel doesn't accept a different
        // amount than the original auth. If a future spec
        // revision allows partial capture, this is where the
        // delta check would land.

        $existing_stash = json_decode((string) $deposit->gateway_response, true);
        if (!is_array($existing_stash)) {
            $existing_stash = [];
        }
        $existing_stash['step']           = 'captured';
        $existing_stash['capture_source'] = $source;
        $existing_stash['capture_data']   = $payment_data;

        $updated = $wpdb->update(
            $wpdb->prefix . 'matrix_deposits',
            [
                'status'           => 'completed',
                'gateway_response' => wp_json_encode($existing_stash),
            ],
            ['id' => $deposit->id, 'status' => 'pending_capture']
        );
        if ($updated !== 1) {
            // Concurrent winner. Done.
            return;
        }

        $this->credit_completed_deposit($deposit, $vendor_reference, __('Zebra Wallet captured deposit (Ref: %s)', 'matrix-mlm'));
    }

    /* ====================================================================
     * Internal: finalise a cancel.
     *
     * Idempotent: conditional UPDATE on status='pending_capture'.
     * No wallet credit and no refund — the customer was never
     * credited Matrix-side, and the platform releases the held
     * funds back to their Zebra wallet on the /CaptureOrCancel
     * success path.
     * ==================================================================== */
    private function finalize_cancel($vendor_reference, array $payment_data, $source) {
        global $wpdb;

        $deposit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_deposits WHERE transaction_id = %s AND status = 'pending_capture'",
            $vendor_reference
        ));
        if (!$deposit) {
            return;
        }

        $existing_stash = json_decode((string) $deposit->gateway_response, true);
        if (!is_array($existing_stash)) {
            $existing_stash = [];
        }
        $existing_stash['step']          = 'cancelled';
        $existing_stash['cancel_source'] = $source;
        $existing_stash['cancel_data']   = $payment_data;

        $updated = $wpdb->update(
            $wpdb->prefix . 'matrix_deposits',
            [
                'status'           => 'cancelled',
                'gateway_response' => wp_json_encode($existing_stash),
            ],
            ['id' => $deposit->id, 'status' => 'pending_capture']
        );
        if ($updated !== 1) {
            // Concurrent winner. Done.
            return;
        }

        // No user notification on cancel: the customer was never
        // told "deposit completed" (that only fires on
        // finalize_capture / complete_deposit), so a cancel from
        // pending_capture is a silent no-op from the user's
        // perspective. Admins see the state change in the
        // Deposits page.
    }

    /**
     * Shared credit + user-notification path used by both
     * complete_deposit (AUTO_CAPTURE / single-step) and
     * finalize_capture (pre-auth then capture). Centralised so
     * "successful deposit" semantics stay identical regardless
     * of which lifecycle the row took to reach 'completed'.
     */
    private function credit_completed_deposit($deposit, $vendor_reference, $description_format) {
        if (class_exists('Matrix_MLM_Wallet')) {
            $wallet = new Matrix_MLM_Wallet();
            $wallet->credit(
                $deposit->user_id,
                $deposit->net_amount,
                'deposit',
                sprintf($description_format, $vendor_reference),
                $vendor_reference
            );
        }

        if (class_exists('Matrix_MLM_Notifications')) {
            Matrix_MLM_Notifications::send_deposit_notification(
                $deposit->user_id,
                $deposit->amount,
                'completed'
            );
        }
    }

    /**
     * Mask all but the last 4 chars of a wallet identifier for
     * audit trail storage. Even though only admins can read
     * gateway_response, we don't need full IWANs / phone numbers
     * sitting in plaintext alongside payment data.
     */
    private static function mask_account($value) {
        $value = (string) $value;
        $len = strlen($value);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        return str_repeat('*', $len - 4) . substr($value, -4);
    }
}
