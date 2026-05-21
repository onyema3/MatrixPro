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
     */
    public function handle_webhook($request) {
        $payload = $request->get_body();
        $signature = $request->get_header('verif-hash');

        // Verify webhook hash
        $gateway_params = $this->get_gateway_params();
        $webhook_hash = $gateway_params['webhook_hash'] ?? '';

        if ($webhook_hash && $signature !== $webhook_hash) {
            return new WP_REST_Response(['status' => 'error'], 401);
        }

        $event = json_decode($payload, true);

        if (isset($event['event']) && $event['event'] === 'charge.completed') {
            $data = $event['data'];
            if ($data['status'] === 'successful') {
                $tx_ref = $data['tx_ref'];
                $this->complete_deposit($tx_ref, $data);
            }
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
            sprintf(__('Flutterwave deposit (Ref: %s)', 'matrix-mlm'), $reference),
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
