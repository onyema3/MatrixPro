<?php
/**
 * Fintava Pay - Physical Card Management (Verve Card)
 * 
 * Integrates with Fintava Pay card endpoints for physical Verve card operations.
 * Card type: STATIC_NO_ACCOUNT (Verve Card)
 * 
 * Endpoints:
 * - POST /cards/physical/request  - Request a new physical card
 * - GET  /cards/status            - Check card request status
 * - POST /cards/link              - Link card to user's wallet
 * - POST /cards/activate          - Activate a physical card
 * - GET  /cards/fetch/{id}        - View card details
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Fintava_Card {

    private $fintava;

    public function __construct() {
        $this->fintava = new Matrix_MLM_Fintava();
        $this->register_hooks();
    }

    /**
     * Register AJAX hooks
     */
    private function register_hooks() {
        add_action('wp_ajax_matrix_fintava_request_card', [$this, 'ajax_request_card']);
        add_action('wp_ajax_matrix_fintava_card_status', [$this, 'ajax_card_status']);
        add_action('wp_ajax_matrix_fintava_link_card', [$this, 'ajax_link_card']);
        add_action('wp_ajax_matrix_fintava_activate_card', [$this, 'ajax_activate_card']);
        add_action('wp_ajax_matrix_fintava_fetch_card', [$this, 'ajax_fetch_card']);
    }

    // =========================================================================
    // API METHODS
    // =========================================================================

    /**
     * Request a physical card
     * POST /cards/physical/request
     * 
     * @param array $card_data Card request parameters
     * @return array|WP_Error
     */
    public function request_physical_card($card_data) {
        $payload = [
            'card_type' => 'STATIC_NO_ACCOUNT',
        ];

        // Customer details
        if (!empty($card_data['first_name'])) {
            $payload['first_name'] = sanitize_text_field($card_data['first_name']);
        }
        if (!empty($card_data['last_name'])) {
            $payload['last_name'] = sanitize_text_field($card_data['last_name']);
        }
        if (!empty($card_data['email'])) {
            $payload['email'] = sanitize_email($card_data['email']);
        }
        if (!empty($card_data['phone'])) {
            $payload['phone'] = sanitize_text_field($card_data['phone']);
        }
        if (!empty($card_data['address'])) {
            $payload['address'] = sanitize_text_field($card_data['address']);
        }
        if (!empty($card_data['city'])) {
            $payload['city'] = sanitize_text_field($card_data['city']);
        }
        if (!empty($card_data['state'])) {
            $payload['state'] = sanitize_text_field($card_data['state']);
        }
        if (!empty($card_data['country'])) {
            $payload['country'] = sanitize_text_field($card_data['country']);
        }
        if (!empty($card_data['wallet_id'])) {
            $payload['wallet_id'] = sanitize_text_field($card_data['wallet_id']);
        }

        $response = $this->make_request('POST', '/cards/physical/request', $payload);

        if (is_wp_error($response)) {
            return $response;
        }

        if (isset($response['status']) && $response['status'] === true) {
            return [
                'success' => true,
                'card_id' => $response['data']['id'] ?? $response['data']['card_id'] ?? null,
                'status' => $response['data']['status'] ?? 'pending',
                'message' => $response['message'] ?? __('Card request submitted successfully', 'matrix-mlm'),
                'data' => $response['data'] ?? [],
            ];
        }

        return new WP_Error(
            'fintava_card_error',
            $response['message'] ?? __('Failed to request card', 'matrix-mlm')
        );
    }

    /**
     * Check card request status
     * GET /cards/status
     * 
     * @param string $card_id Card ID to check
     * @return array|WP_Error
     */
    public function get_card_status($card_id = null) {
        $endpoint = '/cards/status';
        if ($card_id) {
            $endpoint .= '?card_id=' . urlencode($card_id);
        }

        $response = $this->make_request('GET', $endpoint);

        if (is_wp_error($response)) {
            return $response;
        }

        if (isset($response['status']) && $response['status'] === true && isset($response['data'])) {
            return $response['data'];
        }

        return new WP_Error(
            'fintava_card_status_error',
            $response['message'] ?? __('Could not retrieve card status', 'matrix-mlm')
        );
    }

    /**
     * Link card to user wallet
     * POST /cards/link
     * 
     * @param string $card_id Card ID
     * @param string $wallet_id Wallet ID to link
     * @return array|WP_Error
     */
    public function link_card($card_id, $wallet_id) {
        $response = $this->make_request('POST', '/cards/link', [
            'card_id' => $card_id,
            'wallet_id' => $wallet_id,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        if (isset($response['status']) && $response['status'] === true) {
            return [
                'success' => true,
                'message' => $response['message'] ?? __('Card linked successfully', 'matrix-mlm'),
                'data' => $response['data'] ?? [],
            ];
        }

        return new WP_Error(
            'fintava_card_link_error',
            $response['message'] ?? __('Failed to link card', 'matrix-mlm')
        );
    }

    /**
     * Activate a physical card
     * POST /cards/activate
     * 
     * @param string $card_id Card ID
     * @param array $activation_data Additional activation data (e.g., PIN, CVV)
     * @return array|WP_Error
     */
    public function activate_card($card_id, $activation_data = []) {
        $payload = array_merge(['card_id' => $card_id], $activation_data);

        $response = $this->make_request('POST', '/cards/activate', $payload);

        if (is_wp_error($response)) {
            return $response;
        }

        if (isset($response['status']) && $response['status'] === true) {
            return [
                'success' => true,
                'message' => $response['message'] ?? __('Card activated successfully', 'matrix-mlm'),
                'data' => $response['data'] ?? [],
            ];
        }

        return new WP_Error(
            'fintava_card_activate_error',
            $response['message'] ?? __('Failed to activate card', 'matrix-mlm')
        );
    }

    /**
     * Fetch/view card details
     * GET /cards/fetch/{id}
     * 
     * @param string $card_id Card ID
     * @return array|WP_Error
     */
    public function fetch_card($card_id) {
        $response = $this->make_request('GET', '/cards/fetch/' . $card_id);

        if (is_wp_error($response)) {
            return $response;
        }

        if (isset($response['status']) && $response['status'] === true && isset($response['data'])) {
            return $response['data'];
        }

        return new WP_Error(
            'fintava_card_fetch_error',
            $response['message'] ?? __('Could not retrieve card details', 'matrix-mlm')
        );
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    /**
     * AJAX: Request a physical card
     */
    public function ajax_request_card() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('Authentication required', 'matrix-mlm')]);
        }

        if (!Matrix_MLM_User::is_active($user_id)) {
            wp_send_json_error(['message' => __('Your account is suspended', 'matrix-mlm')]);
        }

        // Check if user already has a card
        $existing = $this->get_user_card($user_id);
        if ($existing) {
            wp_send_json_error(['message' => __('You already have a card request. Only one card per user is allowed.', 'matrix-mlm')]);
        }

        // Check if user has a Fintava wallet (required for card)
        $fintava = new Matrix_MLM_Fintava();
        $wallet = $fintava->get_user_wallet($user_id);
        if (!$wallet) {
            wp_send_json_error(['message' => __('You need a Fintava wallet before requesting a card. Please create one first.', 'matrix-mlm')]);
        }

        $user = get_userdata($user_id);
        $meta = Matrix_MLM_User::get_meta($user_id);

        $first_name = sanitize_text_field($_POST['first_name'] ?? get_user_meta($user_id, 'first_name', true));
        $last_name = sanitize_text_field($_POST['last_name'] ?? get_user_meta($user_id, 'last_name', true));
        $address = sanitize_text_field($_POST['address'] ?? ($meta->address ?? ''));
        $city = sanitize_text_field($_POST['city'] ?? ($meta->city ?? ''));
        $state = sanitize_text_field($_POST['state'] ?? ($meta->state ?? ''));

        if (empty($first_name) || empty($last_name)) {
            wp_send_json_error(['message' => __('First name and last name are required', 'matrix-mlm')]);
        }

        if (empty($address)) {
            wp_send_json_error(['message' => __('Delivery address is required for physical card', 'matrix-mlm')]);
        }

        // Request card from Fintava
        $result = $this->request_physical_card([
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $user->user_email,
            'phone' => $meta->phone ?? '',
            'address' => $address,
            'city' => $city,
            'state' => $state,
            'country' => 'Nigeria',
            'wallet_id' => $wallet->wallet_id,
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        // Store card in database
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'matrix_fintava_cards', [
            'user_id' => $user_id,
            'card_id' => $result['card_id'],
            'wallet_id' => $wallet->wallet_id,
            'card_type' => 'STATIC_NO_ACCOUNT',
            'card_brand' => 'VERVE',
            'status' => $result['status'] ?? 'pending',
            'delivery_address' => $address . ', ' . $city . ', ' . $state,
            'metadata' => json_encode($result['data']),
        ]);

        wp_send_json_success([
            'message' => __('Physical Verve card requested successfully! You will be notified when it is ready for delivery.', 'matrix-mlm'),
            'card_id' => $result['card_id'],
            'status' => $result['status'] ?? 'pending',
        ]);
    }

    /**
     * AJAX: Check card status
     */
    public function ajax_card_status() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('Authentication required', 'matrix-mlm')]);
        }

        $card = $this->get_user_card($user_id);
        if (!$card) {
            wp_send_json_error(['message' => __('No card found', 'matrix-mlm'), 'has_card' => false]);
        }

        // Refresh status from API
        if (!empty($card->card_id)) {
            $api_status = $this->get_card_status($card->card_id);
            if (!is_wp_error($api_status)) {
                $new_status = $api_status['status'] ?? $card->status;
                if ($new_status !== $card->status) {
                    global $wpdb;
                    $wpdb->update($wpdb->prefix . 'matrix_fintava_cards', [
                        'status' => $new_status,
                        'updated_at' => current_time('mysql'),
                    ], ['id' => $card->id]);
                    $card->status = $new_status;
                }
            }
        }

        wp_send_json_success([
            'has_card' => true,
            'card' => [
                'card_id' => $card->card_id,
                'card_type' => $card->card_type,
                'card_brand' => $card->card_brand,
                'status' => $card->status,
                'last_four' => $card->last_four,
                'created_at' => $card->created_at,
            ],
        ]);
    }

    /**
     * AJAX: Link card to wallet
     */
    public function ajax_link_card() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('Authentication required', 'matrix-mlm')]);
        }

        $card = $this->get_user_card($user_id);
        if (!$card) {
            wp_send_json_error(['message' => __('No card found', 'matrix-mlm')]);
        }

        $fintava = new Matrix_MLM_Fintava();
        $wallet = $fintava->get_user_wallet($user_id);
        if (!$wallet) {
            wp_send_json_error(['message' => __('No Fintava wallet found', 'matrix-mlm')]);
        }

        $result = $this->link_card($card->card_id, $wallet->wallet_id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        global $wpdb;
        $wpdb->update($wpdb->prefix . 'matrix_fintava_cards', [
            'status' => 'linked',
            'updated_at' => current_time('mysql'),
        ], ['id' => $card->id]);

        wp_send_json_success(['message' => $result['message']]);
    }

    /**
     * AJAX: Activate card
     */
    public function ajax_activate_card() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('Authentication required', 'matrix-mlm')]);
        }

        $card = $this->get_user_card($user_id);
        if (!$card) {
            wp_send_json_error(['message' => __('No card found', 'matrix-mlm')]);
        }

        $pin = sanitize_text_field($_POST['pin'] ?? '');
        $cvv = sanitize_text_field($_POST['cvv'] ?? '');

        $activation_data = [];
        if (!empty($pin)) $activation_data['pin'] = $pin;
        if (!empty($cvv)) $activation_data['cvv'] = $cvv;

        $result = $this->activate_card($card->card_id, $activation_data);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        global $wpdb;
        $wpdb->update($wpdb->prefix . 'matrix_fintava_cards', [
            'status' => 'active',
            'activated_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], ['id' => $card->id]);

        wp_send_json_success(['message' => $result['message']]);
    }

    /**
     * AJAX: Fetch card details
     */
    public function ajax_fetch_card() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('Authentication required', 'matrix-mlm')]);
        }

        $card = $this->get_user_card($user_id);
        if (!$card || empty($card->card_id)) {
            wp_send_json_error(['message' => __('No card found', 'matrix-mlm')]);
        }

        $details = $this->fetch_card($card->card_id);

        if (is_wp_error($details)) {
            wp_send_json_error(['message' => $details->get_error_message()]);
            return;
        }

        // Update local record with any new info
        global $wpdb;
        $update_data = ['updated_at' => current_time('mysql')];
        if (isset($details['last_four'])) {
            $update_data['last_four'] = $details['last_four'];
        }
        if (isset($details['status'])) {
            $update_data['status'] = $details['status'];
        }
        $wpdb->update($wpdb->prefix . 'matrix_fintava_cards', $update_data, ['id' => $card->id]);

        wp_send_json_success(['card' => $details]);
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Get user's card from local database
     */
    public function get_user_card($user_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_fintava_cards WHERE user_id = %d ORDER BY id DESC LIMIT 1",
            $user_id
        ));
    }

    /**
     * Make API request (uses the same Live API endpoint as Matrix_MLM_Fintava)
     */
    private function make_request($method, $endpoint, $body = null) {
        $base_url = defined('MATRIX_FINTAVA_API_BASE_URL') && MATRIX_FINTAVA_API_BASE_URL
            ? rtrim(MATRIX_FINTAVA_API_BASE_URL, '/')
            : Matrix_MLM_Fintava::DEFAULT_BASE_URL;
        $url = $base_url . $endpoint;

        $secret_key = get_option('matrix_mlm_fintava_secret_key', '');

        if (empty($secret_key)) {
            return new WP_Error(
                'fintava_not_configured',
                __('Fintava Pay is not configured. Please add your Live API Key in admin.', 'matrix-mlm')
            );
        }

        $args = [
            'method' => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $secret_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
        ];

        if ($body && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
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
            return new WP_Error('fintava_card_api_error', $error_message);
        }

        return $body;
    }

    /**
     * Create the cards database table
     */
    public static function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'matrix_fintava_cards';

        $sql = "CREATE TABLE $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            card_id varchar(100) DEFAULT NULL,
            wallet_id varchar(100) DEFAULT NULL,
            card_type varchar(50) NOT NULL DEFAULT 'STATIC_NO_ACCOUNT',
            card_brand varchar(20) NOT NULL DEFAULT 'VERVE',
            last_four varchar(4) DEFAULT NULL,
            status enum('pending','processing','shipped','delivered','linked','active','frozen','blocked','expired') NOT NULL DEFAULT 'pending',
            delivery_address text,
            activated_at datetime DEFAULT NULL,
            metadata text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY card_id (card_id),
            KEY status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}
