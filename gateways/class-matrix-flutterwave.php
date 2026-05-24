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
}
