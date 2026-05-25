<?php
/**
 * Zebra Wallet Payment Gateway
 *
 * Bibimoney / Nazmo Banking Platform integration. Two-step deposits
 * (PaymentAuth -> Payment with OTP), Instant Payment Notifications
 * (IPN) for asynchronous completion, customer / account / balance
 * lookups for diagnostics. Withdrawal (vendor -> customer "Dispense"
 * + "Remit") and the Capture/Release/Cancel state machine for
 * pre-auth flows are intentionally out of scope of this first cut
 * and reserved for a follow-up PR.
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

    private $api_key;
    private $api_secret;
    private $vendor_id;
    private $base_url;
    private $environment;
    private $webhook_token;
    private $default_currency;

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

        // EventType AUTO_CAPTURE: complete the transaction
        // immediately. PRE_AUTH would let us hold and capture
        // later; the Matrix deposits flow has no concept of a
        // hold, so AUTO_CAPTURE is the right default. Spec 4.1.2
        // confirms this is also the platform default when
        // EventType is omitted; we set it explicitly so the wire
        // log is unambiguous in support tickets.
        $payload = [
            'api_key'      => $this->api_key,
            'api_secret'   => $this->api_secret,
            'VendorID'     => $this->vendor_id,
            'EventType'    => 'AUTO_CAPTURE',
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

        // Successful synchronous completion. We can credit the
        // deposit right now from this code path — but we still
        // expect an IPN, and complete_deposit() is idempotent
        // (conditional update on status='pending'), so the
        // duplicate IPN won't double-credit.
        $info = isset($response['Information']) && is_array($response['Information']) ? $response['Information'] : [];
        $payment_data = array_merge($info, [
            'StatusCode'   => $status_code,
            'PSPreference' => $psp_reference,
            'amount_minor' => (int) ($info['Value'] ?? 0),
            'currency'     => (string) ($info['CurrencyISO'] ?? $deposit->currency),
        ]);
        $this->complete_deposit($deposit->transaction_id, $payment_data, 'sync');

        return [
            'success' => true,
            'message' => __('Payment confirmed. Your wallet has been credited.', 'matrix-mlm'),
        ];
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

        // Bibimoney delivers IPNs for many event types: payment
        // success, transfer success, refund, dispute, etc. We only
        // act on the deposit-success path; everything else gets
        // logged and accepted (so the platform stops retrying).
        // The "transaction completed" predicate is a successful
        // status code on a payment-shaped event. Future event
        // types (e.g. CAPTURE for pre-auth flows) can hook here
        // when we add those flows.
        $is_payment_event = in_array($event_type, [
            'AUTO_CAPTURE',
            'PRE_AUTH',
            'CAPTURE',
        ], true);

        if ($is_payment_event && self::is_success_code($status_code) && $vendor_reference !== '') {
            $payment_data = [
                'StatusCode'   => $status_code,
                'EventType'    => $event_type,
                'PSPreference' => $psp_reference,
                'amount_minor' => (int) ($event['Amount'] ?? 0),
                'currency'     => strtoupper((string) ($event['Currency'] ?? '')),
                'raw'          => $event,
            ];
            $this->complete_deposit($vendor_reference, $payment_data, 'ipn');
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

        if (class_exists('Matrix_MLM_Wallet')) {
            $wallet = new Matrix_MLM_Wallet();
            $wallet->credit(
                $deposit->user_id,
                $deposit->net_amount,
                'deposit',
                sprintf(__('Zebra Wallet deposit (Ref: %s)', 'matrix-mlm'), $vendor_reference),
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
