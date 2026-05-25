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

    /* ====================================================================
     * /Notify event vocabulary (dispute / fraud / chargeback
     * feed). Bibimoney pushes these as IPN-style POSTs through
     * the existing webhook URL — there's no separate /Notify
     * endpoint we register; the platform decides at runtime
     * which EventType to emit based on what's happening to a
     * past transaction.
     *
     * The three constants below are the well-known strings the
     * spec mentions; the dispatch path also accepts
     * 'REVERSAL' and 'REFUND_NOTIFICATION' (legacy aliases that
     * some env builds emit for the same semantic event) and is
     * filterable via matrix_zebra_notify_event_types so an
     * operator whose Bibimoney env emits e.g. 'CHARGEBACK_RECEIVED'
     * or 'FRAUD_FLAGGED' can extend the recogniser without a
     * code patch.
     *
     * No money moves automatically when a /Notify event arrives.
     * The handler persists a row in matrix_zebra_notifications
     * with state='received' for operator review. The matching
     * admin page (Matrix MLM -> Zebra Notifications) surfaces
     * actionable rows with three operator buttons:
     *
     *   - Acknowledge -> state='acknowledged' (just clears the
     *     unread badge; no other side effect)
     *   - Freeze withdrawal -> when the related row is a
     *     pending/processing matrix_withdrawals, force it to
     *     status='cancelled' and refund amount+charge to the
     *     user's Matrix wallet via WD-FREEZE-{id}. Mark the
     *     notification state='actioned'.
     *   - Dismiss -> state='dismissed' (false positive)
     * ==================================================================== */
    const EVENT_DISPUTE     = 'DISPUTE';
    const EVENT_FRAUD       = 'FRAUD';
    const EVENT_CHARGEBACK  = 'CHARGEBACK';

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
        } elseif (in_array($event_type, self::notify_event_types(), true)) {
            // /Notify dispute / fraud / chargeback feed. No
            // money moves automatically — persist the event
            // for operator review and email the admin so the
            // operator sees it without polling the admin page.
            // See record_notify_event() and notify_admin_email()
            // for the full lifecycle.
            $this->record_notify_event($event_type, $vendor_reference, $psp_reference, $payment_data);
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

    /* ====================================================================
     * User-facing payout AJAX surface.
     *
     * Wired separately from the deposit / OTP flow because the
     * gateway is constructed lazily for those (only inside the
     * matrix_action=deposit / matrix_action=zebra_complete_otp
     * dispatch branches in Matrix_MLM_Core), and we need the
     * AJAX hook reachable on every pageload regardless of whether
     * the OTP flow has been touched. The static
     * register_user_payout_hooks() lets Matrix_MLM_Core::__construct
     * wire the endpoint at boot without paying the cost of
     * constructing the gateway just to register a hook.
     *
     * The single endpoint matrix_zebra_initiate_payout handles
     * both the /Remit and /Dispense rails — the rail is picked by
     * the form's radio button and validated server-side before
     * any state changes. Same nonce / can_move_funds /
     * transaction-PIN / rate-limit gates as the existing Fintava
     * bank-payout path so admin policy applies uniformly across
     * external rails.
     * ==================================================================== */

    /**
     * Register the user-facing payout AJAX endpoint.
     *
     * Static so the bootloader can call it without constructing
     * the gateway — credentials only matter at AJAX dispatch
     * time (we re-construct the instance inside the handler),
     * not at hook registration time.
     */
    public static function register_user_payout_hooks() {
        add_action('wp_ajax_matrix_zebra_initiate_payout', [__CLASS__, 'ajax_initiate_payout_static']);
        // No nopriv twin — only logged-in members can submit
        // payouts (the get_current_user_id() check inside the
        // handler enforces this anyway, but the missing
        // wp_ajax_nopriv hook means the unauthenticated AJAX
        // path 0-bytes immediately rather than hitting our
        // handler at all).
    }

    /**
     * Static AJAX entry point — constructs the gateway and
     * forwards to the instance handler. Splits the static-vs-
     * instance contract so the configuration-loading happens
     * inside the request rather than at hook-registration time.
     */
    public static function ajax_initiate_payout_static() {
        (new self())->ajax_initiate_payout();
    }

    /**
     * Whether the operator has enabled this gateway end-to-end.
     *
     * Pattern mirrors Matrix_MLM_Fintava::is_active(): credentials
     * must be present AND the matrix_gateways row's status
     * column must be set to 1. is_configured() above checks only
     * credentials, so this is the stricter gate the user-facing
     * payout flow needs (we don't want to take a Matrix-wallet
     * debit on a row that the admin disabled mid-session).
     */
    public function is_active() {
        if (!$this->is_configured()) {
            return false;
        }
        global $wpdb;
        $status = $wpdb->get_var(
            "SELECT status FROM {$wpdb->prefix}matrix_gateways WHERE slug = 'zebra'"
        );
        return ((int) $status) === 1;
    }

    /**
     * Handle a user-submitted Zebra payout.
     *
     * Two rails behind a single endpoint:
     *
     *   rail='zebra'      -> /Remit. account_details = the raw
     *                        wallet identifier (IWAN, MSISDN, or
     *                        local reference). 3-64 chars.
     *
     *   rail='zebra_bank' -> /Dispense. account_details = a
     *                        structured JSON envelope
     *                        {type, bank_code, bank_name,
     *                        account_number, account_name}
     *                        that dispense_to_account() decodes
     *                        back out at Approve time.
     *
     * Order of checks (intentionally cheap-first so a wrong
     * input or a disabled gateway can never advance to the wallet
     * debit):
     *
     *   1. nonce
     *   2. logged in
     *   3. rate limit (per user, 30 / 15min)
     *   4. can_move_funds (5-toggle admin policy + active-account
     *      + plan-tier — same policy as the Fintava bank flow,
     *      because semantically both are "user is sending money
     *      out via an external rail")
     *   5. transaction PIN gate (path='bank' — same as Fintava
     *      bank-payout so admin gating applies uniformly)
     *   6. gateway is_active (credentials + matrix_gateways
     *      status column)
     *   7. amount within min/max payout
     *   8. rail-specific destination validation
     *   9. Matrix wallet debit (atomic, fails on insufficient or
     *      missing meta row)
     *   10. INSERT matrix_withdrawals row. On INSERT failure,
     *       FAILSAFE re-credit so we don't trap funds in limbo.
     *   11. Notification fan-out (in-app + email + SMS via
     *       Matrix_MLM_Notifications::send_withdrawal_notification).
     *
     * State machine after this method returns success:
     *
     *   matrix_withdrawals row at status='pending', gateway='zebra',
     *   method=$rail, amount=$amount, charge=$charge,
     *   net_amount=$amount, account_details=<raw or JSON envelope>.
     *
     *   Approve from Withdrawals admin -> dispatch /Remit or
     *   /Dispense per method (PR #304/#305 wiring), processing
     *   -> approved on platform success.
     *
     *   Reject from Withdrawals admin -> refund amount+charge to
     *   Matrix wallet via WD-REFUND-{id} (existing behaviour in
     *   reject_withdrawal()).
     */
    public function ajax_initiate_payout() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('Authentication required', 'matrix-mlm')]);
        }

        // Rate limit. Same shape as the Fintava
        // ajax_initiate_transfer cap — bound abuse from a
        // compromised session without false-positiving a user
        // who's just iterating on a typo'd account number.
        if (class_exists('Matrix_MLM_Rate_Limiter')) {
            if (Matrix_MLM_Rate_Limiter::throttle(
                'zebra_initiate_payout',
                Matrix_MLM_Rate_Limiter::key_for_request(),
                ['max_attempts' => 30, 'window_seconds' => 15 * MINUTE_IN_SECONDS]
            )) {
                wp_send_json_error([
                    'message' => __('Too many payout attempts. Please wait a few minutes and try again.', 'matrix-mlm'),
                ]);
            }
        }

        // Withdrawal policy. Path 'bank_transfer' folds in:
        //   - master kill switch (matrix_mlm_withdrawals_enabled)
        //   - active-account requirement
        //   - plan-tier restriction
        //   - the Bank Transfers toggle on Settings -> Financial
        // Same policy gate the Fintava bank-payout flow applies,
        // because semantically these are external-platform
        // transfers and should be governed by one admin lever
        // rather than two parallel ones.
        if (class_exists('Matrix_MLM_User')) {
            $eligibility = Matrix_MLM_User::can_move_funds($user_id, 'bank_transfer');
            if (!$eligibility['allowed']) {
                wp_send_json_error(['message' => $eligibility['reason']]);
            }
        }

        // Transaction PIN. Path 'bank' is shared with the
        // Fintava bank-payout flow so an admin who already
        // gated their install on Settings -> Financial doesn't
        // have to flip a second toggle. Returns 401 / pin_*
        // shapes via wp_send_json_error directly when the gate
        // fails.
        if (class_exists('Matrix_MLM_Transaction_Pin')) {
            Matrix_MLM_Transaction_Pin::require_pin_for_request($user_id, 'bank');
        }

        if (!$this->is_active()) {
            wp_send_json_error([
                'message' => __('Zebra Wallet payouts are not available at the moment.', 'matrix-mlm'),
            ]);
        }

        $rail = strtolower((string) ($_POST['rail'] ?? 'zebra'));
        if (!in_array($rail, ['zebra', 'zebra_bank'], true)) {
            wp_send_json_error(['message' => __('Unknown payout rail.', 'matrix-mlm')]);
        }

        $amount = (float) ($_POST['amount'] ?? 0);
        if ($amount <= 0) {
            wp_send_json_error(['message' => __('Enter a valid amount.', 'matrix-mlm')]);
        }

        $currency_symbol = (string) get_option('matrix_mlm_currency_symbol', '₦');
        $min_payout = (float) apply_filters(
            'matrix_mlm_zebra_min_payout',
            (float) get_option('matrix_mlm_fintava_min_payout', 1000)
        );
        $max_payout = (float) apply_filters(
            'matrix_mlm_zebra_max_payout',
            (float) get_option('matrix_mlm_fintava_max_payout', 5000000)
        );
        if ($amount < $min_payout) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: 1: currency symbol, 2: minimum amount */
                    __('Minimum payout is %1$s%2$s.', 'matrix-mlm'),
                    $currency_symbol,
                    number_format($min_payout, 2)
                ),
            ]);
        }
        if ($amount > $max_payout) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: 1: currency symbol, 2: maximum amount */
                    __('Maximum payout is %1$s%2$s.', 'matrix-mlm'),
                    $currency_symbol,
                    number_format($max_payout, 2)
                ),
            ]);
        }

        // Plugin-side service charge. Defaults to 0; the
        // matrix_zebra_payout_charge filter receives both the
        // amount and the rail so an operator can express either
        // a flat per-transfer fee or a percentage. We coerce to
        // float and floor at 0 so a buggy filter can't credit
        // the user free money via a negative charge.
        $charge = (float) apply_filters(
            'matrix_zebra_payout_charge',
            0.0,
            $amount,
            $rail
        );
        if ($charge < 0) { $charge = 0.0; }

        // Rail-specific destination validation. We reject
        // structurally-invalid inputs up front so the wallet
        // debit never lands on a row that's guaranteed to fail
        // at Approve time.
        $account_details = '';
        $human_destination = '';
        $narration = isset($_POST['narration']) ? sanitize_text_field((string) $_POST['narration']) : '';

        if ($rail === 'zebra') {
            $identifier = isset($_POST['wallet_identifier'])
                ? sanitize_text_field((string) $_POST['wallet_identifier'])
                : '';
            $identifier = trim($identifier);
            $len = function_exists('mb_strlen') ? mb_strlen($identifier) : strlen($identifier);
            if ($len < 3 || $len > 64) {
                wp_send_json_error([
                    'message' => __('Enter the destination Zebra wallet identifier (IWAN, MSISDN, or local reference, 3-64 chars).', 'matrix-mlm'),
                ]);
            }
            $account_details   = $identifier;
            $human_destination = $identifier;
        } else {
            // zebra_bank rail.
            $bank_code      = isset($_POST['bank_code']) ? sanitize_text_field((string) $_POST['bank_code']) : '';
            $bank_name      = isset($_POST['bank_name']) ? sanitize_text_field((string) $_POST['bank_name']) : '';
            $account_number = isset($_POST['account_number']) ? sanitize_text_field((string) $_POST['account_number']) : '';
            $account_name   = isset($_POST['account_name']) ? sanitize_text_field((string) $_POST['account_name']) : '';

            if (!preg_match('/^\d{3,6}$/', $bank_code)) {
                wp_send_json_error([
                    'message' => __('Bank code must be 3-6 numeric digits (CBN/NIBSS sortCode).', 'matrix-mlm'),
                ]);
            }
            if (!preg_match('/^\d{6,20}$/', $account_number)) {
                wp_send_json_error([
                    'message' => __('Account number must be 6-20 digits.', 'matrix-mlm'),
                ]);
            }
            if ($bank_name === '') {
                wp_send_json_error([
                    'message' => __('Select a bank from the dropdown before submitting.', 'matrix-mlm'),
                ]);
            }
            // Account name: client-side gates submit on a
            // non-empty name field (verified or manually-
            // entered), but a programmatic resubmit could
            // bypass the form. Try a server-side resolve as a
            // best-effort recovery before failing — saves the
            // user a round-trip when their original
            // verification just timed out.
            if ($account_name === '' && class_exists('Matrix_MLM_Fintava')) {
                $fintava  = new Matrix_MLM_Fintava();
                $resolved = $fintava->resolve_account($account_number, $bank_code);
                if (!is_wp_error($resolved)) {
                    $maybe_name = '';
                    if (is_array($resolved)) {
                        if (method_exists('Matrix_MLM_Fintava', 'extract_account_name')) {
                            $maybe_name = (string) Matrix_MLM_Fintava::extract_account_name($resolved);
                        }
                        if ($maybe_name === '' && isset($resolved['account_name'])) {
                            $maybe_name = (string) $resolved['account_name'];
                        }
                    }
                    if ($maybe_name !== '') {
                        $account_name = sanitize_text_field($maybe_name);
                    }
                }
            }
            if ($account_name === '') {
                wp_send_json_error([
                    'message' => __('Account name is required. Verify the destination account or type the holder name before submitting.', 'matrix-mlm'),
                ]);
            }

            // Structured envelope — same shape dispense_to_account()
            // decodes (see PR #305).
            $account_details = wp_json_encode([
                'type'           => 'bank',
                'bank_code'      => $bank_code,
                'bank_name'      => $bank_name,
                'account_number' => $account_number,
                'account_name'   => $account_name,
            ]);
            if (!is_string($account_details) || $account_details === '') {
                wp_send_json_error([
                    'message' => __('Could not encode bank account details. Please try again.', 'matrix-mlm'),
                ]);
            }
            $human_destination = sprintf('%s · %s · %s', $bank_name, $account_number, $account_name);
        }

        // Optional operator-visible narration goes into
        // admin_note so the Withdrawals listing can render it
        // without parsing account_details. We deliberately don't
        // round-trip it to /Remit / /Dispense because Bibimoney's
        // request body has its own Note field that the gateway
        // class fills from the operator note at Approve time;
        // this field is purely a "why I'm sending this" hint
        // for the admin reviewing the row.
        $admin_note = $narration !== ''
            ? sprintf('User narration: %s', $narration)
            : null;

        // Atomic Matrix wallet debit. By the time the row
        // INSERT runs below, the user's matrix_user_meta.balance
        // has already moved by amount+charge. That's the
        // contract reject_withdrawal() depends on (it credits
        // amount+charge back via WD-REFUND-{id} on reject) and
        // approve_withdrawal() implicitly relies on (Approve
        // dispatches /Remit / /Dispense to release the funds
        // off-platform — it does not double-debit).
        $wallet = new Matrix_MLM_Wallet();
        $balance = $wallet->get_balance($user_id);
        $required = $amount + $charge;
        if ($balance < $required) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: 1: currency symbol, 2: required amount, 3: current balance */
                    __('Insufficient Matrix wallet balance. You need %1$s%2$s but you have %1$s%3$s.', 'matrix-mlm'),
                    $currency_symbol,
                    number_format($required, 2),
                    number_format($balance, 2)
                ),
            ]);
        }

        // Mint a unique reference per attempt so a retry doesn't
        // collide with the previous debit's auditor row.
        $debit_ref = sprintf('WD-ZEBRA-%d-%d', $user_id, (int) (microtime(true) * 1000));
        $debit_result = $wallet->debit(
            $user_id,
            $required,
            'withdrawal_request',
            sprintf(
                /* translators: 1: amount, 2: rail label */
                __('Zebra payout request via %2$s', 'matrix-mlm'),
                number_format($required, 2),
                $rail === 'zebra_bank' ? __('Bank Account', 'matrix-mlm') : __('Zebra Wallet', 'matrix-mlm')
            ),
            $debit_ref
        );
        if ($debit_result === false) {
            // debit() already logs the underlying reason. The
            // only realistic causes here (we already pre-flighted
            // balance >= required above) are a concurrent debit
            // on the same user that landed between get_balance()
            // and debit(), or a missing matrix_user_meta row.
            wp_send_json_error([
                'message' => __('Could not debit your Matrix wallet — please try again or contact support.', 'matrix-mlm'),
            ]);
        }

        global $wpdb;
        $row_currency = strtoupper((string) get_option('matrix_mlm_currency', 'NGN'));

        $insert_result = $wpdb->insert(
            $wpdb->prefix . 'matrix_withdrawals',
            [
                'user_id'         => $user_id,
                'method'          => $rail,
                'gateway'         => 'zebra',
                'amount'          => $amount,
                'charge'          => $charge,
                'net_amount'      => $amount,
                'currency'        => $row_currency,
                'account_details' => $account_details,
                'admin_note'      => $admin_note,
                'status'          => 'pending',
            ],
            ['%d', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s']
        );

        if ($insert_result === false || empty($wpdb->insert_id)) {
            // FAILSAFE: the debit landed but the row didn't, so
            // we'd be holding the user's funds without any
            // record of why. Refund immediately under a distinct
            // reference so support can correlate the credit
            // back to the specific failed attempt.
            $failsafe_ref = sprintf('WD-ZEBRA-FAILSAFE-%d', (int) (microtime(true) * 1000));
            $wallet->credit(
                $user_id,
                $required,
                'withdrawal_refund',
                __('Zebra payout request failed to record — automatic refund', 'matrix-mlm'),
                $failsafe_ref
            );
            error_log(sprintf(
                '[Matrix Zebra] Payout INSERT failed for user_id=%d rail=%s amount=%s last_error=%s — auto-refunded via %s',
                $user_id,
                $rail,
                $amount,
                $wpdb->last_error,
                $failsafe_ref
            ));
            wp_send_json_error([
                'message' => __('Could not save your payout request. Your wallet has been refunded; please try again or contact support.', 'matrix-mlm'),
            ]);
        }

        $withdrawal_id = (int) $wpdb->insert_id;

        // Notify (in-app + email + SMS pair). Status='pending'
        // so the user knows the request landed; they get a
        // second notification when an operator approves or
        // rejects (existing wiring in approve_withdrawal /
        // reject_withdrawal).
        if (class_exists('Matrix_MLM_Notifications')) {
            Matrix_MLM_Notifications::send_withdrawal_notification(
                $user_id,
                $amount,
                'pending'
            );
        }

        wp_send_json_success([
            'message' => sprintf(
                /* translators: 1: currency symbol, 2: amount, 3: rail label, 4: withdrawal id */
                __('Payout request submitted: %1$s%2$s to %3$s. Withdrawal #%4$d is now pending operator approval.', 'matrix-mlm'),
                $currency_symbol,
                number_format($amount, 2),
                $human_destination,
                $withdrawal_id
            ),
            'withdrawal_id' => $withdrawal_id,
        ]);
    }

    /* ====================================================================
     * /Notify dispute / fraud / chargeback feed.
     *
     * Bibimoney pushes /Notify events through the same IPN URL
     * the deposit / capture / cancel events use; the EventType
     * field is what tells us a row is a notification rather than
     * a state-transition signal. Routing happens in
     * handle_webhook(); the helpers below own the persistence
     * and admin-alert side.
     *
     * Schema reference: matrix_zebra_notifications, added in
     * 1.0.18. See class-matrix-database.php for the column
     * layout and lifecycle states.
     * ==================================================================== */

    /**
     * Filterable list of EventType strings the IPN dispatcher
     * recognises as /Notify events.
     *
     * Defaults cover the three well-known spec tokens
     * (DISPUTE / FRAUD / CHARGEBACK) plus two legacy aliases
     * (REVERSAL / REFUND_NOTIFICATION) some env builds emit for
     * semantically-equivalent events. Operators whose Bibimoney
     * environment emits other tokens (e.g. CHARGEBACK_RECEIVED,
     * FRAUD_FLAGGED) hook the matrix_zebra_notify_event_types
     * filter to extend without forking. Defensive: forces the
     * result back to a flat string array so a buggy filter that
     * returns a non-array doesn't crash the in_array check on
     * the caller side.
     *
     * @return string[]
     */
    public static function notify_event_types() {
        $defaults = [
            self::EVENT_DISPUTE,
            self::EVENT_FRAUD,
            self::EVENT_CHARGEBACK,
            'REVERSAL',
            'REFUND_NOTIFICATION',
        ];
        $list = apply_filters('matrix_zebra_notify_event_types', $defaults);
        if (!is_array($list)) {
            return $defaults;
        }
        $clean = [];
        foreach ($list as $entry) {
            if (is_string($entry) && $entry !== '') {
                $clean[] = $entry;
            }
        }
        return $clean === [] ? $defaults : $clean;
    }

    /**
     * Map an EventType to a severity bucket used by the admin UI.
     *
     * Default mapping (operator-overridable via
     * matrix_zebra_notify_severity_for_event filter):
     *
     *   FRAUD / CHARGEBACK / REVERSAL  -> 'critical' (immediate
     *                                     action expected;
     *                                     freeze the row before
     *                                     /Remit / /Dispense
     *                                     fires if it's still
     *                                     pending)
     *   DISPUTE                        -> 'warning'  (a
     *                                     customer is contesting
     *                                     a settled transaction;
     *                                     operator should
     *                                     respond but no
     *                                     immediate freeze)
     *   REFUND_NOTIFICATION / unknown  -> 'info'     (FYI /
     *                                     reconciliation hint)
     */
    public static function notify_severity_for_event($event_type) {
        $event_type = strtoupper((string) $event_type);
        $default    = 'info';
        if (in_array($event_type, ['FRAUD', 'CHARGEBACK', 'REVERSAL'], true)) {
            $default = 'critical';
        } elseif ($event_type === 'DISPUTE') {
            $default = 'warning';
        }
        $sev = (string) apply_filters('matrix_zebra_notify_severity_for_event', $default, $event_type);
        if (!in_array($sev, ['info', 'warning', 'critical'], true)) {
            return $default;
        }
        return $sev;
    }

    /**
     * Find the matrix_deposits or matrix_withdrawals row this
     * /Notify event is talking about.
     *
     * Lookup order:
     *   1. VendorReference against matrix_withdrawals.transaction_id
     *      (vendor->customer payouts, set per /Remit / /Dispense
     *      attempt)
     *   2. VendorReference against matrix_deposits.transaction_id
     *      (deposits)
     *   3. PSPReference against matrix_deposits.gateway_response
     *      (deposits, fallback for env builds that send only
     *      PSPReference on /Notify)
     *
     * Returns shape ['type' => 'deposit'|'withdrawal'|'unknown',
     *                'id' => int|null,
     *                'user_id' => int|null,
     *                'amount' => float|null,
     *                'currency' => string|null].
     *
     * 'unknown' is a legitimate result — Bibimoney can /Notify
     * about a transaction we never recorded (probe, stale event,
     * cross-env contamination). The matrix_zebra_notifications
     * row is still persisted for operator review with
     * related_type='unknown' so support can investigate.
     */
    public static function find_related_record($vendor_reference, $psp_reference = '') {
        global $wpdb;

        $vendor_reference = (string) $vendor_reference;
        $psp_reference    = (string) $psp_reference;

        if ($vendor_reference !== '') {
            // Withdrawals first — disputes/chargebacks/reversals
            // most commonly attach to outbound payouts (the
            // money has already left the platform; this is the
            // signal that it's coming back).
            $wd = $wpdb->get_row($wpdb->prepare(
                "SELECT id, user_id, amount, currency
                   FROM {$wpdb->prefix}matrix_withdrawals
                  WHERE transaction_id = %s
                  LIMIT 1",
                $vendor_reference
            ));
            if ($wd) {
                return [
                    'type'     => 'withdrawal',
                    'id'       => (int) $wd->id,
                    'user_id'  => (int) $wd->user_id,
                    'amount'   => (float) $wd->amount,
                    'currency' => (string) $wd->currency,
                ];
            }

            $dep = $wpdb->get_row($wpdb->prepare(
                "SELECT id, user_id, amount, currency
                   FROM {$wpdb->prefix}matrix_deposits
                  WHERE transaction_id = %s
                  LIMIT 1",
                $vendor_reference
            ));
            if ($dep) {
                return [
                    'type'     => 'deposit',
                    'id'       => (int) $dep->id,
                    'user_id'  => (int) $dep->user_id,
                    'amount'   => (float) $dep->amount,
                    'currency' => (string) $dep->currency,
                ];
            }
        }

        if ($psp_reference !== '') {
            // Best-effort PSPReference fallback — it's stashed
            // inside gateway_response JSON, so this scan walks
            // gateway='zebra' rows and matches on the JSON
            // payload. LIMIT 1 plus the gateway filter keeps
            // the scan bounded; in practice Zebra-gateway rows
            // are a small slice of the total.
            $needle = '%' . $wpdb->esc_like($psp_reference) . '%';
            $dep = $wpdb->get_row($wpdb->prepare(
                "SELECT id, user_id, amount, currency
                   FROM {$wpdb->prefix}matrix_deposits
                  WHERE gateway = %s AND gateway_response LIKE %s
                  ORDER BY id DESC
                  LIMIT 1",
                'zebra',
                $needle
            ));
            if ($dep) {
                return [
                    'type'     => 'deposit',
                    'id'       => (int) $dep->id,
                    'user_id'  => (int) $dep->user_id,
                    'amount'   => (float) $dep->amount,
                    'currency' => (string) $dep->currency,
                ];
            }
        }

        return [
            'type'     => 'unknown',
            'id'       => null,
            'user_id'  => null,
            'amount'   => null,
            'currency' => null,
        ];
    }

    /**
     * Persist a /Notify event in matrix_zebra_notifications and
     * fire an admin alert email.
     *
     * Idempotency: not strictly idempotent because Bibimoney
     * doesn't carry a stable per-event id we could dedupe on —
     * the platform may resend the same dispute notification on
     * retry, and we'd record two rows. That's deliberate: an
     * operator-facing audit feed wants to see every delivery
     * attempt rather than silently collapse them, so the
     * matching admin UI shows duplicates but lets the operator
     * dismiss the redundant rows. If/when Bibimoney exposes a
     * NotificationId we can layer in a dedupe path on top of
     * this without changing the storage shape.
     *
     * Returns the inserted row id, or null on failure.
     */
    private function record_notify_event($event_type, $vendor_reference, $psp_reference, array $payment_data) {
        global $wpdb;

        $related   = self::find_related_record($vendor_reference, $psp_reference);
        $severity  = self::notify_severity_for_event($event_type);
        $raw_event = $payment_data['raw'] ?? $payment_data;

        // Best-effort pull of a human-readable message from the
        // event payload. Different Bibimoney env builds use
        // different field names, so we try several before
        // falling back to the event type.
        $message = '';
        $candidates = ['Message', 'message', 'Description', 'description', 'StatusMessage', 'StatusDescription'];
        foreach ($candidates as $key) {
            if (isset($raw_event[$key]) && is_string($raw_event[$key]) && $raw_event[$key] !== '') {
                $message = (string) $raw_event[$key];
                break;
            }
        }
        if ($message === '') {
            $message = sprintf(
                /* translators: %s = the EventType the platform sent */
                __('Bibimoney %s event received.', 'matrix-mlm'),
                $event_type
            );
        }

        // amount is in minor units in the event; convert to
        // major for display consistency with the rest of the
        // platform.
        $amount_minor = (int) ($payment_data['amount_minor'] ?? 0);
        $amount       = $amount_minor > 0 ? ($amount_minor / 100.0) : null;
        $currency     = strtoupper((string) ($payment_data['currency'] ?? ''));
        if ($amount === null && isset($related['amount'])) {
            // Fall back to the related row's amount when the
            // event payload doesn't carry one — gives the
            // operator something useful in the listing's
            // amount column.
            $amount   = $related['amount'];
            $currency = $currency !== '' ? $currency : (string) ($related['currency'] ?? '');
        }

        $insert = $wpdb->insert(
            $wpdb->prefix . 'matrix_zebra_notifications',
            [
                'event_type'       => (string) $event_type,
                'status_code'      => (string) ($payment_data['StatusCode'] ?? ''),
                'severity'         => $severity,
                'vendor_reference' => $vendor_reference !== '' ? $vendor_reference : null,
                'psp_reference'    => $psp_reference !== ''    ? $psp_reference    : null,
                'related_type'     => $related['type'],
                'related_id'       => $related['id'],
                'amount'           => $amount,
                'currency'         => $currency !== '' ? $currency : null,
                'message'          => $message,
                'raw_payload'      => wp_json_encode($raw_event),
                'state'            => 'received',
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%f', '%s', '%s', '%s', '%s']
        );

        if ($insert === false || empty($wpdb->insert_id)) {
            error_log(sprintf(
                '[Matrix Zebra Notify] Could not persist event_type=%s vref=%s last_error=%s',
                $event_type,
                $vendor_reference,
                $wpdb->last_error
            ));
            return null;
        }

        $notification_id = (int) $wpdb->insert_id;

        // Fire the admin alert. wp_mail to the site admin_email
        // is the lowest-complexity path that gets the operator
        // an immediate signal without any UI changes — they can
        // then click through to Matrix MLM -> Zebra Notifications
        // for the full row + actions. We log+continue on send
        // failure so a misconfigured SMTP doesn't break the IPN
        // ack (the platform retries until it sees the literal
        // "accepted" body, so any thrown exception here would
        // strand the row in retry hell).
        try {
            self::notify_admin_email($notification_id, $event_type, $severity, $message, $related, $amount, $currency);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[Matrix Zebra Notify] admin email dispatch failed for notif #%d: %s',
                $notification_id,
                $e->getMessage()
            ));
        }

        return $notification_id;
    }

    /**
     * Send the operator a heads-up email for a /Notify event.
     *
     * Plain-text wp_mail to the site admin_email; the email is
     * intentionally minimal (subject line + 6-line body) so an
     * inbox preview surfaces the gist without opening the
     * message. The deep link points at the new admin page,
     * scoped to the specific notification id so a click lands on
     * the row that needs attention.
     *
     * Static so it can also be called from a future
     * reconciliation worker (e.g. a daily cron that re-walks
     * unhandled critical events) without instantiating the
     * gateway.
     */
    public static function notify_admin_email($notification_id, $event_type, $severity, $message, array $related, $amount, $currency) {
        $admin_email = get_option('admin_email');
        if (empty($admin_email) || !is_email($admin_email)) {
            return false;
        }

        $site_name = (string) get_bloginfo('name');
        $deep_link = admin_url('admin.php?page=matrix-mlm-zebra-notifications&highlight=' . (int) $notification_id);

        $subject = sprintf(
            /* translators: 1: site name, 2: severity tag, 3: event type */
            __('[%1$s] [%2$s] Zebra Wallet %3$s notification received', 'matrix-mlm'),
            $site_name,
            strtoupper($severity),
            $event_type
        );

        $related_line = $related['type'] === 'unknown'
            ? __('Could not match this event to a known deposit/withdrawal — review manually.', 'matrix-mlm')
            : sprintf(
                /* translators: 1: deposit/withdrawal, 2: id */
                __('Related %1$s: #%2$d', 'matrix-mlm'),
                $related['type'],
                (int) ($related['id'] ?? 0)
            );

        $amount_line = $amount !== null
            ? sprintf(
                /* translators: 1: amount, 2: currency */
                __('Amount: %1$s %2$s', 'matrix-mlm'),
                number_format((float) $amount, 2),
                $currency !== '' ? $currency : 'NGN'
            )
            : __('Amount: not specified by the platform', 'matrix-mlm');

        $body  = __('A Zebra Wallet (Bibimoney) /Notify event has just been received and needs operator review.', 'matrix-mlm') . "\n\n";
        $body .= sprintf(__('Event type: %s', 'matrix-mlm'), $event_type) . "\n";
        $body .= sprintf(__('Severity:   %s', 'matrix-mlm'), strtoupper($severity)) . "\n";
        $body .= $related_line . "\n";
        $body .= $amount_line . "\n";
        $body .= sprintf(__('Message:    %s', 'matrix-mlm'), $message) . "\n\n";
        $body .= __('Open in admin:', 'matrix-mlm') . ' ' . $deep_link . "\n";

        return (bool) wp_mail($admin_email, $subject, $body);
    }
}
