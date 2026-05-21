<?php
/**
 * Fintava Pay Gateway - Merchant Bank Credit & Virtual Wallet
 * 
 * Integrates with the Fintava Pay API (https://dev.fintavapay.com/api/dev/)
 * to allow users to transfer funds from their Matrix wallet to their bank accounts
 * via the merchant credit endpoint.
 * 
 * Endpoints used:
 * - GET  /merchant/balance        - Get merchant wallet balance
 * - POST /bank/credit/merchant    - Credit a bank account (payout from merchant)
 * - POST /virtual-wallet/generate - Generate virtual wallet for user
 * - GET  /banks                   - List supported banks
 * - POST /resolve-account         - Verify bank account (name lookup)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Fintava {

    private $secret_key;
    private $public_key;
    private $base_url;
    private $environment;

    public function __construct() {
        $this->load_credentials();
        $this->register_hooks();
    }

    /**
     * Load API credentials from settings
     */
    private function load_credentials() {
        $this->environment = get_option('matrix_mlm_fintava_environment', 'sandbox');
        $this->secret_key = get_option('matrix_mlm_fintava_secret_key', '');
        $this->public_key = get_option('matrix_mlm_fintava_public_key', '');

        // Default base URL: Fintava Pay API
        $this->base_url = 'https://dev.fintavapay.com/api/dev';

        // Allow override via settings
        $custom_url = get_option('matrix_mlm_fintava_base_url', '');
        if (!empty($custom_url)) {
            $this->base_url = rtrim($custom_url, '/');
        }
    }

    /**
     * Register WordPress hooks
     */
    private function register_hooks() {
        add_action('wp_ajax_matrix_fintava_get_banks', [$this, 'ajax_get_banks']);
        add_action('wp_ajax_matrix_fintava_resolve_account', [$this, 'ajax_resolve_account']);
        add_action('wp_ajax_matrix_fintava_initiate_transfer', [$this, 'ajax_initiate_transfer']);
        add_action('wp_ajax_matrix_fintava_check_status', [$this, 'ajax_check_transfer_status']);
        add_action('wp_ajax_matrix_fintava_merchant_balance', [$this, 'ajax_get_merchant_balance']);

        // Virtual Wallet AJAX handlers
        add_action('wp_ajax_matrix_fintava_create_virtual_wallet', [$this, 'ajax_create_virtual_wallet']);
        add_action('wp_ajax_matrix_fintava_get_virtual_wallet', [$this, 'ajax_get_virtual_wallet']);

        // REST API endpoint for webhook callbacks
        add_action('rest_api_init', [$this, 'register_webhook_routes']);
    }

    /**
     * Register webhook route for transfer status updates
     */
    public function register_webhook_routes() {
        register_rest_route('matrix-mlm/v1', '/fintava/webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    // =========================================================================
    // API METHODS
    // =========================================================================

    /**
     * Get list of supported banks
     * GET /banks
     */
    public function get_banks() {
        $cache_key = 'matrix_fintava_banks_list';
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $response = $this->make_request('GET', '/banks');

        if (is_wp_error($response)) {
            return $response;
        }

        if (isset($response['status']) && $response['status'] === true && isset($response['data'])) {
            // Cache bank list for 24 hours
            set_transient($cache_key, $response['data'], DAY_IN_SECONDS);
            return $response['data'];
        }

        return new WP_Error('fintava_error', $response['message'] ?? __('Failed to retrieve bank list', 'matrix-mlm'));
    }

    /**
     * Resolve/verify bank account details (name lookup)
     * POST /resolve-account
     * 
     * @param string $account_number The bank account number
     * @param string $bank_code The bank code
     * @return array|WP_Error Account details or error
     */
    public function resolve_account($account_number, $bank_code) {
        $response = $this->make_request('POST', '/resolve-account', [
            'account_number' => $account_number,
            'bank_code' => $bank_code,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        if (isset($response['status']) && $response['status'] === true && isset($response['data'])) {
            return $response['data'];
        }

        return new WP_Error(
            'fintava_resolve_error',
            $response['message'] ?? __('Could not resolve account details', 'matrix-mlm')
        );
    }

    /**
     * Initiate a bank transfer/payout
     * POST /transfer
     * 
     * @param array $transfer_data Transfer parameters
     * @return array|WP_Error Transfer result or error
     */
    public function initiate_transfer($transfer_data) {
        $required_fields = ['amount', 'account_number', 'bank_code', 'narration'];
        foreach ($required_fields as $field) {
            if (empty($transfer_data[$field])) {
                return new WP_Error('missing_field', sprintf(__('Missing required field: %s', 'matrix-mlm'), $field));
            }
        }

        $payload = [
            'amount' => floatval($transfer_data['amount']),
            'account_number' => sanitize_text_field($transfer_data['account_number']),
            'bank_code' => sanitize_text_field($transfer_data['bank_code']),
            'narration' => sanitize_text_field($transfer_data['narration']),
            'currency' => $transfer_data['currency'] ?? 'NGN',
            'reference' => $transfer_data['reference'] ?? $this->generate_reference(),
            'callback_url' => rest_url('matrix-mlm/v1/fintava/webhook'),
        ];

        // Optional fields
        if (!empty($transfer_data['account_name'])) {
            $payload['account_name'] = sanitize_text_field($transfer_data['account_name']);
        }
        if (!empty($transfer_data['bank_name'])) {
            $payload['bank_name'] = sanitize_text_field($transfer_data['bank_name']);
        }

        $response = $this->make_request('POST', '/transfer', $payload);

        if (is_wp_error($response)) {
            return $response;
        }

        if (isset($response['status']) && $response['status'] === true) {
            return [
                'success' => true,
                'transfer_id' => $response['data']['id'] ?? null,
                'reference' => $response['data']['reference'] ?? $payload['reference'],
                'status' => $response['data']['status'] ?? 'pending',
                'message' => $response['message'] ?? __('Transfer initiated successfully', 'matrix-mlm'),
            ];
        }

        return new WP_Error(
            'fintava_transfer_error',
            $response['message'] ?? __('Transfer failed', 'matrix-mlm')
        );
    }

    /**
     * Check transfer status
     * GET /transfer/:id
     * 
     * @param string $transfer_id The transfer ID or reference
     * @return array|WP_Error
     */
    public function check_transfer_status($transfer_id) {
        $response = $this->make_request('GET', '/transfer/' . $transfer_id);

        if (is_wp_error($response)) {
            return $response;
        }

        if (isset($response['status']) && $response['status'] === true && isset($response['data'])) {
            return $response['data'];
        }

        return new WP_Error(
            'fintava_status_error',
            $response['message'] ?? __('Could not fetch transfer status', 'matrix-mlm')
        );
    }

    /**
     * Get merchant wallet balance
     * GET /merchant/balance
     * 
     * Returns the current balance of the merchant's Fintava wallet.
     * This is the pool from which user payouts are funded.
     * 
     * @return array|WP_Error Balance data or error
     */
    public function get_merchant_balance() {
        $response = $this->make_request('GET', '/merchant/balance');

        if (is_wp_error($response)) {
            return $response;
        }

        if (isset($response['status']) && $response['status'] === true && isset($response['data'])) {
            return $response['data'];
        }

        // Some API responses return balance directly
        if (isset($response['balance'])) {
            return [
                'balance' => floatval($response['balance']),
                'currency' => $response['currency'] ?? 'NGN',
                'available_balance' => floatval($response['available_balance'] ?? $response['balance']),
                'ledger_balance' => floatval($response['ledger_balance'] ?? $response['balance']),
            ];
        }

        return new WP_Error(
            'fintava_balance_error',
            $response['message'] ?? __('Could not retrieve merchant balance', 'matrix-mlm')
        );
    }

    /**
     * Merchant transfer - Credit a bank account from merchant wallet
     * POST /bank/credit/merchant
     * 
     * This is the actual payout endpoint that debits the merchant's Fintava wallet
     * and credits the recipient's bank account.
     * 
     * @param array $transfer_data Transfer parameters
     * @return array|WP_Error Transfer result or error
     */
    public function merchant_bank_credit($transfer_data) {
        $required_fields = ['amount', 'account_number', 'bank_code'];
        foreach ($required_fields as $field) {
            if (empty($transfer_data[$field])) {
                return new WP_Error('missing_field', sprintf(__('Missing required field: %s', 'matrix-mlm'), $field));
            }
        }

        $payload = [
            'amount' => floatval($transfer_data['amount']),
            'account_number' => sanitize_text_field($transfer_data['account_number']),
            'bank_code' => sanitize_text_field($transfer_data['bank_code']),
        ];

        // Optional fields commonly accepted by the API
        if (!empty($transfer_data['narration'])) {
            $payload['narration'] = sanitize_text_field($transfer_data['narration']);
        }
        if (!empty($transfer_data['reference'])) {
            $payload['reference'] = sanitize_text_field($transfer_data['reference']);
        }
        if (!empty($transfer_data['account_name'])) {
            $payload['account_name'] = sanitize_text_field($transfer_data['account_name']);
        }
        if (!empty($transfer_data['bank_name'])) {
            $payload['bank_name'] = sanitize_text_field($transfer_data['bank_name']);
        }
        if (!empty($transfer_data['currency'])) {
            $payload['currency'] = sanitize_text_field($transfer_data['currency']);
        }

        $response = $this->make_request('POST', '/bank/credit/merchant', $payload);

        if (is_wp_error($response)) {
            return $response;
        }

        if (isset($response['status']) && $response['status'] === true) {
            return [
                'success' => true,
                'transfer_id' => $response['data']['id'] ?? $response['data']['transfer_id'] ?? null,
                'reference' => $response['data']['reference'] ?? ($transfer_data['reference'] ?? ''),
                'status' => $response['data']['status'] ?? 'pending',
                'message' => $response['message'] ?? __('Merchant transfer initiated successfully', 'matrix-mlm'),
            ];
        }

        return new WP_Error(
            'fintava_merchant_transfer_error',
            $response['message'] ?? __('Merchant transfer failed', 'matrix-mlm')
        );
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    /**
     * AJAX: Get bank list
     */
    public function ajax_get_banks() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Authentication required', 'matrix-mlm')]);
        }

        $banks = $this->get_banks();

        if (is_wp_error($banks)) {
            wp_send_json_error(['message' => $banks->get_error_message()]);
        }

        wp_send_json_success(['banks' => $banks]);
    }

    /**
     * AJAX: Resolve bank account
     */
    public function ajax_resolve_account() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Authentication required', 'matrix-mlm')]);
        }

        $account_number = sanitize_text_field($_POST['account_number'] ?? '');
        $bank_code = sanitize_text_field($_POST['bank_code'] ?? '');

        if (empty($account_number) || empty($bank_code)) {
            wp_send_json_error(['message' => __('Account number and bank are required', 'matrix-mlm')]);
        }

        if (!preg_match('/^\d{10}$/', $account_number)) {
            wp_send_json_error(['message' => __('Account number must be 10 digits', 'matrix-mlm')]);
        }

        $result = $this->resolve_account($account_number, $bank_code);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'account_name' => $result['account_name'] ?? '',
            'account_number' => $result['account_number'] ?? $account_number,
            'bank_name' => $result['bank_name'] ?? '',
        ]);
    }

    /**
     * AJAX: Initiate bank transfer from wallet
     */
    public function ajax_initiate_transfer() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('Authentication required', 'matrix-mlm')]);
        }

        // Check if user is active
        if (!Matrix_MLM_User::is_active($user_id)) {
            wp_send_json_error(['message' => __('Your account is suspended', 'matrix-mlm')]);
        }

        // Validate inputs
        $amount = floatval($_POST['amount'] ?? 0);
        $account_number = sanitize_text_field($_POST['account_number'] ?? '');
        $bank_code = sanitize_text_field($_POST['bank_code'] ?? '');
        $bank_name = sanitize_text_field($_POST['bank_name'] ?? '');
        $account_name = sanitize_text_field($_POST['account_name'] ?? '');
        $narration = sanitize_text_field($_POST['narration'] ?? '');

        if (empty($narration)) {
            $narration = sprintf('Matrix Payout - %s', wp_get_current_user()->user_login);
        }

        // Validate amount limits
        $min_payout = floatval(get_option('matrix_mlm_fintava_min_payout', 1000));
        $max_payout = floatval(get_option('matrix_mlm_fintava_max_payout', 5000000));

        if ($amount < $min_payout) {
            wp_send_json_error(['message' => sprintf(__('Minimum payout amount is %s%s', 'matrix-mlm'), get_option('matrix_mlm_currency_symbol', '₦'), number_format($min_payout, 2))]);
        }

        if ($amount > $max_payout) {
            wp_send_json_error(['message' => sprintf(__('Maximum payout amount is %s%s', 'matrix-mlm'), get_option('matrix_mlm_currency_symbol', '₦'), number_format($max_payout, 2))]);
        }

        if (empty($account_number) || empty($bank_code)) {
            wp_send_json_error(['message' => __('Bank account details are required', 'matrix-mlm')]);
        }

        // Calculate charges
        $charge_type = get_option('matrix_mlm_fintava_charge_type', 'fixed');
        $charge_value = floatval(get_option('matrix_mlm_fintava_charge_value', 50));

        if ($charge_type === 'percent') {
            $charge = round($amount * $charge_value / 100, 2);
        } else {
            $charge = $charge_value;
        }

        $total_debit = $amount + $charge;

        // Check wallet balance
        $wallet = new Matrix_MLM_Wallet();
        $balance = $wallet->get_balance($user_id);

        if ($balance < $total_debit) {
            wp_send_json_error(['message' => sprintf(
                __('Insufficient balance. You need %s%s (Amount: %s%s + Charge: %s%s)', 'matrix-mlm'),
                get_option('matrix_mlm_currency_symbol', '₦'), number_format($total_debit, 2),
                get_option('matrix_mlm_currency_symbol', '₦'), number_format($amount, 2),
                get_option('matrix_mlm_currency_symbol', '₦'), number_format($charge, 2)
            )]);
        }

        // Generate reference
        $reference = $this->generate_reference();

        // Debit wallet first (hold funds)
        $wallet->debit(
            $user_id,
            $total_debit,
            'fintava_payout',
            sprintf(__('Bank transfer to %s (%s) - Ref: %s', 'matrix-mlm'), $account_name, $bank_name, $reference),
            $reference
        );

        // Record the payout in database
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'matrix_fintava_payouts', [
            'user_id' => $user_id,
            'reference' => $reference,
            'amount' => $amount,
            'charge' => $charge,
            'total_debit' => $total_debit,
            'bank_code' => $bank_code,
            'bank_name' => $bank_name,
            'account_number' => $account_number,
            'account_name' => $account_name,
            'narration' => $narration,
            'currency' => 'NGN',
            'status' => 'pending',
            'created_at' => current_time('mysql'),
        ]);

        $payout_id = $wpdb->insert_id;

        // Initiate transfer via Fintava API (merchant bank credit)
        $result = $this->merchant_bank_credit([
            'amount' => $amount,
            'account_number' => $account_number,
            'bank_code' => $bank_code,
            'bank_name' => $bank_name,
            'account_name' => $account_name,
            'narration' => $narration,
            'reference' => $reference,
            'currency' => 'NGN',
        ]);

        if (is_wp_error($result)) {
            // Transfer failed - refund the user
            $wallet->credit(
                $user_id,
                $total_debit,
                'fintava_payout_refund',
                sprintf(__('Refund: Bank transfer failed - %s', 'matrix-mlm'), $result->get_error_message()),
                $reference
            );

            // Update payout status
            $wpdb->update($wpdb->prefix . 'matrix_fintava_payouts', [
                'status' => 'failed',
                'failure_reason' => $result->get_error_message(),
                'updated_at' => current_time('mysql'),
            ], ['id' => $payout_id]);

            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        // Update payout with transfer details
        $wpdb->update($wpdb->prefix . 'matrix_fintava_payouts', [
            'transfer_id' => $result['transfer_id'] ?? '',
            'status' => $result['status'] ?? 'processing',
            'updated_at' => current_time('mysql'),
        ], ['id' => $payout_id]);

        // Send notification
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        Matrix_MLM_Notifications::send_admin_notification(
            'fintava_payout',
            sprintf(
                __('Bank payout initiated: %s%s to %s (%s - %s). Ref: %s', 'matrix-mlm'),
                $currency, number_format($amount, 2), $account_name, $bank_name, $account_number, $reference
            )
        );

        wp_send_json_success([
            'message' => sprintf(
                __('Transfer of %s%s initiated to %s (%s). It will be processed shortly.', 'matrix-mlm'),
                $currency, number_format($amount, 2), $account_name, $bank_name
            ),
            'reference' => $reference,
            'status' => $result['status'] ?? 'processing',
        ]);
    }

    /**
     * AJAX: Check transfer status
     */
    public function ajax_check_transfer_status() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Authentication required', 'matrix-mlm')]);
        }

        $reference = sanitize_text_field($_POST['reference'] ?? '');
        if (empty($reference)) {
            wp_send_json_error(['message' => __('Reference is required', 'matrix-mlm')]);
        }

        global $wpdb;
        $payout = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_fintava_payouts WHERE reference = %s AND user_id = %d",
            $reference, get_current_user_id()
        ));

        if (!$payout) {
            wp_send_json_error(['message' => __('Payout not found', 'matrix-mlm')]);
        }

        // If we have a transfer_id, check with Fintava
        if (!empty($payout->transfer_id) && in_array($payout->status, ['pending', 'processing'])) {
            $status = $this->check_transfer_status($payout->transfer_id);
            if (!is_wp_error($status)) {
                $new_status = $status['status'] ?? $payout->status;
                if ($new_status !== $payout->status) {
                    $wpdb->update($wpdb->prefix . 'matrix_fintava_payouts', [
                        'status' => $new_status,
                        'updated_at' => current_time('mysql'),
                    ], ['id' => $payout->id]);
                    $payout->status = $new_status;
                }
            }
        }

        wp_send_json_success([
            'reference' => $payout->reference,
            'amount' => $payout->amount,
            'bank_name' => $payout->bank_name,
            'account_name' => $payout->account_name,
            'account_number' => $payout->account_number,
            'status' => $payout->status,
            'created_at' => $payout->created_at,
        ]);
    }

    /**
     * AJAX: Get merchant wallet balance (admin only)
     */
    public function ajax_get_merchant_balance() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        if (!current_user_can('manage_matrix_mlm')) {
            wp_send_json_error(['message' => __('Unauthorized', 'matrix-mlm')]);
        }

        $balance = $this->get_merchant_balance();

        if (is_wp_error($balance)) {
            wp_send_json_error(['message' => $balance->get_error_message()]);
        }

        wp_send_json_success(['balance' => $balance]);
    }

    // =========================================================================
    // WEBHOOK HANDLER
    // =========================================================================

    /**
     * Handle Fintava webhook notifications for transfer status updates
     */
    public function handle_webhook($request) {
        $payload = $request->get_body();
        $signature = $request->get_header('x-fintava-signature');

        // Verify webhook signature
        $webhook_secret = get_option('matrix_mlm_fintava_webhook_secret', '');
        if (!empty($webhook_secret)) {
            $computed_signature = hash_hmac('sha512', $payload, $webhook_secret);
            if (!hash_equals($computed_signature, $signature ?? '')) {
                return new WP_REST_Response(['status' => 'error', 'message' => 'Invalid signature'], 401);
            }
        }

        $event = json_decode($payload, true);
        if (!$event) {
            return new WP_REST_Response(['status' => 'error', 'message' => 'Invalid payload'], 400);
        }

        // Support both 'event' and 'type' keys for event identification
        $event_type = $event['event'] ?? $event['type'] ?? '';
        $data = $event['data'] ?? [];

        // Log webhook event for debugging/auditing
        $this->log_webhook_event($event_type, $data, $signature);

        switch ($event_type) {
            case 'transfer.success':
            case 'transfer.completed':
                $this->handle_transfer_success($data);
                break;
            case 'transfer.failed':
            case 'transfer.reversed':
                $this->handle_transfer_failure($data);
                break;
            case 'wallet_to_wallet_transfer_v2':
                $this->handle_wallet_to_wallet_transfer($data);
                break;
            case 'account_funded':
                $this->handle_account_funded($data);
                break;
            default:
                // Unknown event type - log and acknowledge
                error_log(sprintf('[Matrix Fintava Webhook] Unhandled event type: %s', $event_type));
                break;
        }

        return new WP_REST_Response(['status' => 'success'], 200);
    }

    /**
     * Handle successful transfer webhook
     */
    private function handle_transfer_success($data) {
        global $wpdb;
        $reference = $data['reference'] ?? '';

        if (empty($reference)) {
            return;
        }

        $payout = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_fintava_payouts WHERE reference = %s",
            $reference
        ));

        if (!$payout || $payout->status === 'completed') {
            return;
        }

        $wpdb->update($wpdb->prefix . 'matrix_fintava_payouts', [
            'status' => 'completed',
            'completed_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], ['id' => $payout->id]);

        // Send success notification to user
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $user = get_userdata($payout->user_id);
        if ($user) {
            Matrix_MLM_Notifications::send_deposit_notification(
                $payout->user_id,
                $payout->amount,
                'completed'
            );
        }
    }

    /**
     * Handle failed/reversed transfer webhook
     */
    private function handle_transfer_failure($data) {
        global $wpdb;
        $reference = $data['reference'] ?? '';
        $reason = $data['reason'] ?? $data['message'] ?? __('Transfer failed', 'matrix-mlm');

        if (empty($reference)) {
            return;
        }

        $payout = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_fintava_payouts WHERE reference = %s",
            $reference
        ));

        if (!$payout || in_array($payout->status, ['failed', 'refunded'])) {
            return;
        }

        // Update status
        $wpdb->update($wpdb->prefix . 'matrix_fintava_payouts', [
            'status' => 'failed',
            'failure_reason' => $reason,
            'updated_at' => current_time('mysql'),
        ], ['id' => $payout->id]);

        // Refund the user
        $wallet = new Matrix_MLM_Wallet();
        $wallet->credit(
            $payout->user_id,
            $payout->total_debit,
            'fintava_payout_refund',
            sprintf(__('Refund: Bank transfer failed - %s (Ref: %s)', 'matrix-mlm'), $reason, $reference),
            $reference
        );

        // Update to refunded
        $wpdb->update($wpdb->prefix . 'matrix_fintava_payouts', [
            'status' => 'refunded',
            'updated_at' => current_time('mysql'),
        ], ['id' => $payout->id]);

        // Notify user
        Matrix_MLM_Notifications::send_admin_notification(
            'fintava_payout_failed',
            sprintf(__('Bank payout FAILED and refunded. Ref: %s, Reason: %s', 'matrix-mlm'), $reference, $reason)
        );
    }

    // =========================================================================
    // INCOMING PAYMENT WEBHOOK HANDLERS
    // =========================================================================

    /**
     * Handle wallet-to-wallet transfer webhook (wallet_to_wallet_transfer_v2)
     * 
     * Triggered when a user receives funds via wallet-to-wallet transfer on Fintava.
     * Maps the recipient virtual wallet to a Matrix user and credits their balance.
     */
    private function handle_wallet_to_wallet_transfer($data) {
        global $wpdb;

        $reference = $data['reference'] ?? $data['transaction_reference'] ?? '';
        $amount = floatval($data['amount'] ?? 0);
        $recipient_account = $data['recipient_account_number'] ?? $data['recipient_wallet'] ?? $data['account_number'] ?? '';
        $sender_name = $data['sender_name'] ?? $data['sender'] ?? __('External Wallet', 'matrix-mlm');
        $narration = $data['narration'] ?? $data['description'] ?? '';
        $currency = $data['currency'] ?? 'NGN';

        if (empty($reference) || $amount <= 0 || empty($recipient_account)) {
            error_log('[Matrix Fintava Webhook] wallet_to_wallet_transfer_v2: Missing required data - ref: ' . $reference . ', amount: ' . $amount . ', account: ' . $recipient_account);
            return;
        }

        // Check for duplicate processing
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}matrix_deposits WHERE transaction_id = %s AND status = 'completed'",
            $reference
        ));

        if ($existing) {
            error_log('[Matrix Fintava Webhook] wallet_to_wallet_transfer_v2: Duplicate reference - ' . $reference);
            return;
        }

        // Find the user by their virtual wallet account number
        $wallet_record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_fintava_wallets WHERE account_number = %s AND status = 'active'",
            $recipient_account
        ));

        if (!$wallet_record) {
            error_log('[Matrix Fintava Webhook] wallet_to_wallet_transfer_v2: No user found for account ' . $recipient_account . ' (ref: ' . $reference . ')');
            return;
        }

        $user_id = $wallet_record->user_id;

        // Verify user is active
        if (!Matrix_MLM_User::is_active($user_id)) {
            error_log('[Matrix Fintava Webhook] wallet_to_wallet_transfer_v2: User ' . $user_id . ' is not active (ref: ' . $reference . ')');
            return;
        }

        // Record the deposit
        $wpdb->insert($wpdb->prefix . 'matrix_deposits', [
            'user_id' => $user_id,
            'amount' => $amount,
            'charge' => 0.00,
            'net_amount' => $amount,
            'gateway' => 'fintava_wallet_transfer',
            'currency' => $currency,
            'transaction_id' => $reference,
            'gateway_response' => json_encode([
                'type' => 'wallet_to_wallet_transfer_v2',
                'sender_name' => $sender_name,
                'narration' => $narration,
                'recipient_account' => $recipient_account,
            ]),
            'status' => 'completed',
            'created_at' => current_time('mysql'),
        ]);

        // Credit the user's Matrix wallet
        $wallet = new Matrix_MLM_Wallet();
        $description = sprintf(
            __('Wallet transfer received from %s%s', 'matrix-mlm'),
            $sender_name,
            !empty($narration) ? ' - ' . $narration : ''
        );
        $wallet->credit($user_id, $amount, 'fintava_wallet_transfer', $description, $reference);

        // Send notification to user
        Matrix_MLM_Notifications::send_deposit_notification($user_id, $amount, 'completed');

        // Notify admin
        $currency_symbol = get_option('matrix_mlm_currency_symbol', '₦');
        $user = get_userdata($user_id);
        Matrix_MLM_Notifications::send_admin_notification(
            'fintava_wallet_transfer',
            sprintf(
                __('Wallet transfer received: %s%s to %s (Account: %s, From: %s). Ref: %s', 'matrix-mlm'),
                $currency_symbol,
                number_format($amount, 2),
                $user ? $user->user_login : "User #$user_id",
                $recipient_account,
                $sender_name,
                $reference
            )
        );

        do_action('matrix_fintava_wallet_transfer_received', $user_id, $amount, $data);
    }

    /**
     * Handle account funded webhook (account_funded)
     * 
     * Triggered when a user's virtual account receives a bank transfer/deposit.
     * Maps the funded virtual account to a Matrix user and credits their balance.
     */
    private function handle_account_funded($data) {
        global $wpdb;

        $reference = $data['reference'] ?? $data['transaction_reference'] ?? $data['session_id'] ?? '';
        $amount = floatval($data['amount'] ?? 0);
        $account_number = $data['account_number'] ?? $data['virtual_account_number'] ?? '';
        $sender_name = $data['sender_name'] ?? $data['payer_name'] ?? $data['originator_name'] ?? __('Bank Transfer', 'matrix-mlm');
        $sender_bank = $data['sender_bank'] ?? $data['payer_bank'] ?? $data['originator_bank'] ?? '';
        $narration = $data['narration'] ?? $data['description'] ?? $data['remark'] ?? '';
        $currency = $data['currency'] ?? 'NGN';

        if (empty($reference) || $amount <= 0 || empty($account_number)) {
            error_log('[Matrix Fintava Webhook] account_funded: Missing required data - ref: ' . $reference . ', amount: ' . $amount . ', account: ' . $account_number);
            return;
        }

        // Check for duplicate processing
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}matrix_deposits WHERE transaction_id = %s AND status = 'completed'",
            $reference
        ));

        if ($existing) {
            error_log('[Matrix Fintava Webhook] account_funded: Duplicate reference - ' . $reference);
            return;
        }

        // Find the user by their virtual wallet account number
        $wallet_record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_fintava_wallets WHERE account_number = %s AND status = 'active'",
            $account_number
        ));

        if (!$wallet_record) {
            error_log('[Matrix Fintava Webhook] account_funded: No user found for account ' . $account_number . ' (ref: ' . $reference . ')');
            return;
        }

        $user_id = $wallet_record->user_id;

        // Verify user is active
        if (!Matrix_MLM_User::is_active($user_id)) {
            error_log('[Matrix Fintava Webhook] account_funded: User ' . $user_id . ' is not active (ref: ' . $reference . ')');
            return;
        }

        // Record the deposit
        $wpdb->insert($wpdb->prefix . 'matrix_deposits', [
            'user_id' => $user_id,
            'amount' => $amount,
            'charge' => 0.00,
            'net_amount' => $amount,
            'gateway' => 'fintava_account_funded',
            'currency' => $currency,
            'transaction_id' => $reference,
            'gateway_response' => json_encode([
                'type' => 'account_funded',
                'sender_name' => $sender_name,
                'sender_bank' => $sender_bank,
                'narration' => $narration,
                'account_number' => $account_number,
            ]),
            'status' => 'completed',
            'created_at' => current_time('mysql'),
        ]);

        // Credit the user's Matrix wallet
        $wallet = new Matrix_MLM_Wallet();
        $description = sprintf(
            __('Account funded by %s%s%s', 'matrix-mlm'),
            $sender_name,
            !empty($sender_bank) ? ' (' . $sender_bank . ')' : '',
            !empty($narration) ? ' - ' . $narration : ''
        );
        $wallet->credit($user_id, $amount, 'fintava_account_funded', $description, $reference);

        // Send notification to user
        Matrix_MLM_Notifications::send_deposit_notification($user_id, $amount, 'completed');

        // Notify admin
        $currency_symbol = get_option('matrix_mlm_currency_symbol', '₦');
        $user = get_userdata($user_id);
        Matrix_MLM_Notifications::send_admin_notification(
            'fintava_account_funded',
            sprintf(
                __('Account funded: %s%s deposited to %s (Account: %s, From: %s%s). Ref: %s', 'matrix-mlm'),
                $currency_symbol,
                number_format($amount, 2),
                $user ? $user->user_login : "User #$user_id",
                $account_number,
                $sender_name,
                !empty($sender_bank) ? ' via ' . $sender_bank : '',
                $reference
            )
        );

        do_action('matrix_fintava_account_funded', $user_id, $amount, $data);
    }

    // =========================================================================
    // WEBHOOK LOGGING
    // =========================================================================

    /**
     * Log webhook events for debugging and auditing
     * 
     * Stores webhook events in the database for troubleshooting and audit trail.
     */
    private function log_webhook_event($event_type, $data, $signature) {
        global $wpdb;

        $table = $wpdb->prefix . 'matrix_fintava_webhook_logs';

        // Check if logging table exists (graceful fallback)
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            // Fallback to error_log if table doesn't exist
            error_log(sprintf(
                '[Matrix Fintava Webhook] Event: %s | Reference: %s | Data: %s',
                $event_type,
                $data['reference'] ?? $data['transaction_reference'] ?? 'N/A',
                json_encode($data)
            ));
            return;
        }

        $wpdb->insert($table, [
            'event_type' => $event_type,
            'reference' => $data['reference'] ?? $data['transaction_reference'] ?? '',
            'payload' => json_encode($data),
            'signature' => $signature ?? '',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'status' => 'received',
            'created_at' => current_time('mysql'),
        ]);
    }

    // =========================================================================
    // UTILITY METHODS
    // =========================================================================

    /**
     * Make HTTP request to Fintava API
     */
    private function make_request($method, $endpoint, $body = null) {
        $url = $this->base_url . $endpoint;

        $args = [
            'method' => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->secret_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
        ];

        if ($body && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $args['body'] = json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code >= 400) {
            $error_message = $body['message'] ?? sprintf(__('API Error (HTTP %d)', 'matrix-mlm'), $status_code);
            return new WP_Error('fintava_api_error', $error_message);
        }

        return $body;
    }

    /**
     * Generate unique transfer reference
     */
    private function generate_reference() {
        return 'MTX-FTV-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 12)) . '-' . time();
    }

    /**
     * Check if Fintava is configured and active
     */
    public function is_active() {
        return !empty($this->secret_key) && get_option('matrix_mlm_fintava_enabled', 0);
    }

    /**
     * Get payout history for a user
     */
    public function get_user_payouts($user_id, $limit = 20, $offset = 0) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_fintava_payouts 
             WHERE user_id = %d 
             ORDER BY created_at DESC 
             LIMIT %d OFFSET %d",
            $user_id, $limit, $offset
        ));
    }

    /**
     * Get all payouts (admin)
     */
    public function get_all_payouts($status = null, $limit = 50, $offset = 0) {
        global $wpdb;

        $where = "WHERE 1=1";
        $params = [];

        if ($status) {
            $where .= " AND p.status = %s";
            $params[] = $status;
        }

        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, u.user_login, u.user_email 
             FROM {$wpdb->prefix}matrix_fintava_payouts p 
             LEFT JOIN {$wpdb->users} u ON p.user_id = u.ID 
             $where ORDER BY p.created_at DESC LIMIT %d OFFSET %d",
            $params
        ));
    }

    // =========================================================================
    // VIRTUAL WALLET API METHODS
    // =========================================================================

    /**
     * Generate a virtual wallet/account for a user
     * POST /virtual-wallet/generate
     * 
     * @param array $customer_data Customer details for wallet creation
     * @return array|WP_Error Wallet details or error
     */
    public function generate_virtual_wallet($customer_data) {
        $required_fields = ['first_name', 'last_name', 'email', 'phone'];
        foreach ($required_fields as $field) {
            if (empty($customer_data[$field])) {
                return new WP_Error('missing_field', sprintf(__('Missing required field: %s', 'matrix-mlm'), $field));
            }
        }

        $payload = [
            'first_name' => sanitize_text_field($customer_data['first_name']),
            'last_name' => sanitize_text_field($customer_data['last_name']),
            'email' => sanitize_email($customer_data['email']),
            'phone' => sanitize_text_field($customer_data['phone']),
            'currency' => $customer_data['currency'] ?? 'NGN',
        ];

        // Optional fields
        if (!empty($customer_data['bvn'])) {
            $payload['bvn'] = sanitize_text_field($customer_data['bvn']);
        }
        if (!empty($customer_data['nin'])) {
            $payload['nin'] = sanitize_text_field($customer_data['nin']);
        }
        if (!empty($customer_data['date_of_birth'])) {
            $payload['date_of_birth'] = sanitize_text_field($customer_data['date_of_birth']);
        }
        if (!empty($customer_data['gender'])) {
            $payload['gender'] = sanitize_text_field($customer_data['gender']);
        }
        if (!empty($customer_data['address'])) {
            $payload['address'] = sanitize_text_field($customer_data['address']);
        }
        if (!empty($customer_data['reference'])) {
            $payload['reference'] = sanitize_text_field($customer_data['reference']);
        }

        $response = $this->make_request('POST', '/virtual-wallet/generate', $payload);

        if (is_wp_error($response)) {
            return $response;
        }

        if (isset($response['status']) && $response['status'] === true && isset($response['data'])) {
            return [
                'success' => true,
                'wallet_id' => $response['data']['wallet_id'] ?? $response['data']['id'] ?? null,
                'account_number' => $response['data']['account_number'] ?? '',
                'account_name' => $response['data']['account_name'] ?? ($customer_data['first_name'] . ' ' . $customer_data['last_name']),
                'bank_name' => $response['data']['bank_name'] ?? $response['data']['bank'] ?? 'Fintava',
                'bank_code' => $response['data']['bank_code'] ?? null,
                'currency' => $response['data']['currency'] ?? 'NGN',
                'status' => $response['data']['status'] ?? 'active',
            ];
        }

        return new WP_Error(
            'fintava_wallet_error',
            $response['message'] ?? __('Failed to generate virtual wallet', 'matrix-mlm')
        );
    }

    /**
     * Get virtual wallet details
     * GET /virtual-wallet/:wallet_id
     * 
     * @param string $wallet_id The wallet ID
     * @return array|WP_Error Wallet details or error
     */
    public function get_virtual_wallet_details($wallet_id) {
        $response = $this->make_request('GET', '/virtual-wallet/' . $wallet_id);

        if (is_wp_error($response)) {
            return $response;
        }

        if (isset($response['status']) && $response['status'] === true && isset($response['data'])) {
            return $response['data'];
        }

        return new WP_Error(
            'fintava_wallet_error',
            $response['message'] ?? __('Could not retrieve wallet details', 'matrix-mlm')
        );
    }

    /**
     * Get user's virtual wallet from local database
     */
    public function get_user_wallet($user_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_fintava_wallets WHERE user_id = %d AND status = 'active'",
            $user_id
        ));
    }

    /**
     * Check if user already has a virtual wallet
     */
    public function user_has_wallet($user_id) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}matrix_fintava_wallets WHERE user_id = %d AND status = 'active'",
            $user_id
        ));
    }

    // =========================================================================
    // VIRTUAL WALLET AJAX HANDLERS
    // =========================================================================

    /**
     * AJAX: Create virtual wallet for the current user
     */
    public function ajax_create_virtual_wallet() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('Authentication required', 'matrix-mlm')]);
        }

        if (!Matrix_MLM_User::is_active($user_id)) {
            wp_send_json_error(['message' => __('Your account is suspended', 'matrix-mlm')]);
        }

        // Check if user already has a wallet
        if ($this->user_has_wallet($user_id)) {
            wp_send_json_error(['message' => __('You already have a virtual wallet. Only one wallet per user is allowed.', 'matrix-mlm')]);
        }

        // Validate required inputs
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $bvn = sanitize_text_field($_POST['bvn'] ?? '');
        $date_of_birth = sanitize_text_field($_POST['date_of_birth'] ?? '');
        $gender = sanitize_text_field($_POST['gender'] ?? '');

        if (empty($first_name) || empty($last_name)) {
            wp_send_json_error(['message' => __('First name and last name are required', 'matrix-mlm')]);
        }

        if (empty($email) || !is_email($email)) {
            wp_send_json_error(['message' => __('A valid email address is required', 'matrix-mlm')]);
        }

        if (empty($phone)) {
            wp_send_json_error(['message' => __('Phone number is required', 'matrix-mlm')]);
        }

        // BVN validation (11 digits for Nigeria)
        if (!empty($bvn) && !preg_match('/^\d{11}$/', $bvn)) {
            wp_send_json_error(['message' => __('BVN must be 11 digits', 'matrix-mlm')]);
        }

        // Generate reference
        $reference = 'MTX-VW-' . $user_id . '-' . time();

        // Call Fintava API to generate virtual wallet
        $result = $this->generate_virtual_wallet([
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'phone' => $phone,
            'bvn' => $bvn,
            'date_of_birth' => $date_of_birth,
            'gender' => $gender,
            'reference' => $reference,
            'currency' => 'NGN',
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        // Store wallet in database
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'matrix_fintava_wallets', [
            'user_id' => $user_id,
            'wallet_id' => $result['wallet_id'],
            'account_number' => $result['account_number'],
            'account_name' => $result['account_name'],
            'bank_name' => $result['bank_name'],
            'bank_code' => $result['bank_code'],
            'currency' => $result['currency'],
            'customer_email' => $email,
            'customer_phone' => $phone,
            'bvn' => $bvn ?: null,
            'status' => 'active',
            'metadata' => json_encode([
                'first_name' => $first_name,
                'last_name' => $last_name,
                'date_of_birth' => $date_of_birth,
                'gender' => $gender,
                'reference' => $reference,
            ]),
        ]);

        // Send notification
        Matrix_MLM_Notifications::send_admin_notification(
            'virtual_wallet_created',
            sprintf(__('Virtual wallet created for user %s (%s). Account: %s', 'matrix-mlm'),
                $first_name . ' ' . $last_name, $email, $result['account_number'])
        );

        wp_send_json_success([
            'message' => __('Virtual wallet created successfully!', 'matrix-mlm'),
            'wallet' => [
                'account_number' => $result['account_number'],
                'account_name' => $result['account_name'],
                'bank_name' => $result['bank_name'],
            ],
        ]);
    }

    /**
     * AJAX: Get current user's virtual wallet details
     */
    public function ajax_get_virtual_wallet() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('Authentication required', 'matrix-mlm')]);
        }

        $wallet = $this->get_user_wallet($user_id);

        if (!$wallet) {
            wp_send_json_error(['message' => __('No virtual wallet found', 'matrix-mlm'), 'has_wallet' => false]);
        }

        // Optionally refresh from API if wallet_id exists
        if (!empty($wallet->wallet_id)) {
            $api_details = $this->get_virtual_wallet_details($wallet->wallet_id);
            if (!is_wp_error($api_details) && isset($api_details['status'])) {
                // Update local status if changed
                if ($api_details['status'] !== $wallet->status) {
                    global $wpdb;
                    $wpdb->update($wpdb->prefix . 'matrix_fintava_wallets', [
                        'status' => $api_details['status'],
                        'updated_at' => current_time('mysql'),
                    ], ['id' => $wallet->id]);
                    $wallet->status = $api_details['status'];
                }
            }
        }

        wp_send_json_success([
            'has_wallet' => true,
            'wallet' => [
                'account_number' => $wallet->account_number,
                'account_name' => $wallet->account_name,
                'bank_name' => $wallet->bank_name,
                'currency' => $wallet->currency,
                'status' => $wallet->status,
                'created_at' => $wallet->created_at,
            ],
        ]);
    }

    /**
     * Create the fintava_payouts database table
     */
    public static function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'matrix_fintava_payouts';

        $sql = "CREATE TABLE $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            reference varchar(100) NOT NULL UNIQUE,
            transfer_id varchar(100) DEFAULT NULL,
            amount decimal(12,2) NOT NULL,
            charge decimal(12,2) NOT NULL DEFAULT 0.00,
            total_debit decimal(12,2) NOT NULL,
            bank_code varchar(20) NOT NULL,
            bank_name varchar(100) NOT NULL,
            account_number varchar(20) NOT NULL,
            account_name varchar(255) NOT NULL,
            narration varchar(255) DEFAULT NULL,
            currency varchar(5) NOT NULL DEFAULT 'NGN',
            status enum('pending','processing','completed','failed','refunded') NOT NULL DEFAULT 'pending',
            failure_reason text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime DEFAULT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY reference (reference),
            KEY user_id (user_id),
            KEY status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Virtual wallets table
        $table_wallets = $wpdb->prefix . 'matrix_fintava_wallets';
        $sql_wallets = "CREATE TABLE $table_wallets (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            wallet_id varchar(100) DEFAULT NULL,
            account_number varchar(20) NOT NULL,
            account_name varchar(255) NOT NULL,
            bank_name varchar(100) NOT NULL DEFAULT 'Fintava',
            bank_code varchar(20) DEFAULT NULL,
            currency varchar(5) NOT NULL DEFAULT 'NGN',
            customer_email varchar(255) DEFAULT NULL,
            customer_phone varchar(20) DEFAULT NULL,
            bvn varchar(20) DEFAULT NULL,
            status enum('active','inactive','frozen','closed') NOT NULL DEFAULT 'active',
            metadata text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id),
            KEY account_number (account_number),
            KEY status (status)
        ) $charset_collate;";
        dbDelta($sql_wallets);
    }
}
