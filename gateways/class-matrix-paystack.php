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
     */
    public function handle_webhook($request) {
        $payload = $request->get_body();
        $signature = $request->get_header('x-paystack-signature');

        // Verify webhook signature
        $gateway_params = $this->get_gateway_params();
        $webhook_secret = $gateway_params['webhook_secret'] ?? $this->secret_key;

        if ($signature !== hash_hmac('sha512', $payload, $webhook_secret)) {
            return new WP_REST_Response(['status' => 'error'], 401);
        }

        $event = json_decode($payload, true);

        if ($event['event'] === 'charge.success') {
            $data = $event['data'];
            $reference = $data['reference'];
            $this->complete_deposit($reference, $data);
        }

        return new WP_REST_Response(['status' => 'success'], 200);
    }

    /**
     * Complete deposit after successful payment
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

        // Update deposit status
        $wpdb->update(
            $wpdb->prefix . 'matrix_deposits',
            [
                'status' => 'completed',
                'gateway_response' => json_encode($payment_data)
            ],
            ['id' => $deposit->id]
        );

        // Credit user wallet
        $wallet = new Matrix_MLM_Wallet();
        $wallet->credit(
            $deposit->user_id,
            $deposit->net_amount,
            'deposit',
            sprintf(__('Paystack deposit (Ref: %s)', 'matrix-mlm'), $reference),
            $reference
        );

        // Send notification
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
}
