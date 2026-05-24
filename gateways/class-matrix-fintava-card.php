<?php
/**
 * Fintava Pay - Physical Card Management (Verve Card)
 *
 * Integrates with Fintava Pay card endpoints for physical Verve card
 * operations. Card type: STATIC_NO_ACCOUNT (Verve Card).
 *
 * The physical Verve cards are pre-produced and held by the merchant —
 * there is no shipping/delivery step. The end-user flow is:
 *
 *   1. Create the card record on Fintava's side (POST /cards/physical/request)
 *   2. Activate it by typing in the PAN printed on the physical card.
 *      "Activate" is a server-side composite of:
 *        - PATCH /cards/link      (link the PAN to the user's wallet)
 *        - PATCH /cards/activate  (flip the card to ACTIVE)
 *      The user only enters the PAN once.
 *   3. Optionally view full card details (GET /cards/fetch/{cardMapId}).
 *
 * Endpoints (all under the same base URL as Matrix_MLM_Fintava):
 *   - POST  /cards/physical/request   - Create a card record
 *   - GET   /cards/fetch/{cardMapId}  - Fetch card details (and status)
 *   - PATCH /cards/link               - Link card PAN to wallet
 *   - PATCH /cards/activate           - Activate card
 *   - PATCH /cards/deactivate         - Freeze/deactivate card
 *
 * Notable correctness points worth pinning down here, since they were the
 * source of the original 404 and several latent bugs:
 *
 *   - The create path is /cards/physical/REQUEST, not /cards/physical.
 *     Earlier versions of this class hit /cards/physical and got a hard
 *     404 from Fintava's router; PR #202 misdiagnosed it as a trailing
 *     slash issue.
 *
 *   - Link / activate / deactivate are PATCH, not POST. Fintava returns
 *     405 for the POST variants.
 *
 *   - The create payload is {cardBrand, cardName, accountNumber, cardType}
 *     — camelCase, NUBAN-driven. Customer KYC (address, BVN, etc.) lives
 *     on the Fintava customer record, which Matrix_MLM_Fintava already
 *     creates at wallet-provisioning time via POST /create/customer. By
 *     the time we get here, $wallet->account_number IS the customer's
 *     virtualAcctNo, which is exactly what /cards/physical/request wants.
 *
 *   - Fintava returns card statuses in UPPERCASE (ACTIVE, FROZEN, ...).
 *     We normalize to lowercase on read via self::normalize_status() so
 *     the local DB stays case-consistent.
 *
 *   - The card PAN is never persisted. We send it through to Fintava on
 *     link/activate/deactivate and only store its last four digits in
 *     wp_matrix_fintava_cards.last_four for display.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Fintava_Card {

    private $fintava;

    /**
     * Local DB column wp_matrix_fintava_cards.card_id stores Fintava's
     * cardMapId (the ID of the card *record*, not a PAN). The column was
     * named before that distinction was clear; it's kept for backward
     * compat with the import path. All API calls treat it as cardMapId.
     */

    public function __construct() {
        $this->fintava = new Matrix_MLM_Fintava();
        $this->register_hooks();
    }

    /**
     * Register AJAX hooks.
     *
     * `setup_card` is the user-facing combined link+activate flow (single
     * PAN entry, two server-side PATCH calls). `link_card` and
     * `activate_card` remain individually callable for admin tooling and
     * recovery from a partially-failed setup.
     */
    private function register_hooks() {
        add_action('wp_ajax_matrix_fintava_request_card',    [$this, 'ajax_request_card']);
        add_action('wp_ajax_matrix_fintava_setup_card',      [$this, 'ajax_setup_card']);
        add_action('wp_ajax_matrix_fintava_link_card',       [$this, 'ajax_link_card']);
        add_action('wp_ajax_matrix_fintava_activate_card',   [$this, 'ajax_activate_card']);
        add_action('wp_ajax_matrix_fintava_deactivate_card', [$this, 'ajax_deactivate_card']);
        add_action('wp_ajax_matrix_fintava_fetch_card',      [$this, 'ajax_fetch_card']);
    }

    // =========================================================================
    // API METHODS
    // =========================================================================

    /**
     * Create a physical card record on Fintava's side.
     *
     * POST /cards/physical/request
     *
     * Payload (all camelCase keys, per Fintava's DTO):
     *   - cardBrand:     'VERVE' — Fintava enforces a strict UPPERCASE
     *                    enum (VISA | MASTERCARD | VERVE). Sending
     *                    'Verve' yields:
     *                    "cardBrand must be one of the following
     *                     values: VISA, MASTERCARD, VERVE".
     *   - cardName:      Embossed name on the card.
     *   - accountNumber: The customer's virtual NUBAN
     *                    (= $wallet->account_number).
     *   - cardType:      'STATIC_NO_ACCOUNT'.
     *
     * The customer record on Fintava's side already exists at this point
     * because Matrix_MLM_Fintava::create_customer() ran during wallet
     * provisioning. We do NOT need to send address/email/phone here — that
     * KYC lives on the customer record, keyed off accountNumber.
     *
     * @param int    $user_id    WP user ID (must already have a wallet).
     * @param string $first_name Cardholder first name (embossed).
     * @param string $last_name  Cardholder last name (embossed).
     * @return array|WP_Error    On success: ['card_map_id', 'status', ...]
     */
    public function request_physical_card($user_id, $first_name, $last_name) {
        $wallet = $this->fintava->get_user_wallet($user_id);
        if (!$wallet || empty($wallet->account_number)) {
            return new WP_Error(
                'no_wallet',
                __('A Fintava wallet is required before creating a card.', 'matrix-mlm')
            );
        }

        $card_name = trim(
            sanitize_text_field($first_name) . ' ' . sanitize_text_field($last_name)
        );
        if ($card_name === '') {
            return new WP_Error(
                'missing_card_name',
                __('Cardholder name is required.', 'matrix-mlm')
            );
        }

        $payload = [
            'cardBrand'     => 'VERVE',
            'cardName'      => $card_name,
            'accountNumber' => $wallet->account_number,
            'cardType'      => 'STATIC_NO_ACCOUNT',
        ];

        $response = $this->make_request('POST', '/cards/physical/request', $payload);
        if (is_wp_error($response)) {
            return $response;
        }

        if (!Matrix_MLM_Fintava::is_api_success($response)) {
            return new WP_Error(
                'fintava_card_error',
                self::stringify_error_message(
                    $response['message'] ?? __('Failed to create card', 'matrix-mlm')
                )
            );
        }

        $data = $response['data'] ?? [];
        return [
            'success'     => true,
            // Fintava returns the cardMapId at data.cardMapId in current
            // shape, but older tier responses surface it as data.id. Try
            // both, in that order.
            'card_map_id' => $data['cardMapId'] ?? $data['id'] ?? null,
            'status'      => self::normalize_status($data['status'] ?? 'pending'),
            'message'     => $response['message'] ?? __('Card created successfully', 'matrix-mlm'),
            'data'        => $data,
        ];
    }

    /**
     * Link the physical card's PAN to the user's wallet.
     *
     * PATCH /cards/link
     *
     * Payload: {cardMapId, cardPan, customerAccountNo, userId}
     *
     * `userId` here is Fintava's customer-record id (userInfo.id), NOT
     * the WordPress user_id. We persist that as wallets.customer_id at
     * wallet-creation time. For older wallets where it was never written
     * we fall back to Matrix_MLM_Fintava::resolve_customer_id() and
     * back-fill the row.
     *
     * @param int    $user_id  WP user ID.
     * @param string $card_pan 16-digit PAN typed by the user from their
     *                         physical card. Sent to Fintava but never
     *                         persisted locally.
     * @return array|WP_Error
     */
    public function link_card($user_id, $card_pan) {
        $card = $this->get_user_card($user_id);
        if (!$card || empty($card->card_id)) {
            return new WP_Error('no_card', __('No card to link', 'matrix-mlm'));
        }

        $wallet = $this->fintava->get_user_wallet($user_id);
        if (!$wallet || empty($wallet->account_number)) {
            return new WP_Error('no_wallet', __('No wallet to link to', 'matrix-mlm'));
        }

        $pan_digits = self::sanitize_pan($card_pan);
        if ($pan_digits === '') {
            return new WP_Error('invalid_pan', __('A valid card PAN is required.', 'matrix-mlm'));
        }

        $customer_id = $this->resolve_user_customer_id($wallet);
        if (is_wp_error($customer_id)) {
            return $customer_id;
        }

        $payload = [
            'cardMapId'         => $card->card_id,
            'cardPan'           => $pan_digits,
            'customerAccountNo' => $wallet->account_number,
            'userId'            => $customer_id,
        ];

        $response = $this->make_request('PATCH', '/cards/link', $payload);
        if (is_wp_error($response)) {
            return $response;
        }

        if (!Matrix_MLM_Fintava::is_api_success($response)) {
            return new WP_Error(
                'fintava_card_link_error',
                self::stringify_error_message(
                    $response['message'] ?? __('Failed to link card', 'matrix-mlm')
                )
            );
        }

        return [
            'success' => true,
            'message' => $response['message'] ?? __('Card linked successfully', 'matrix-mlm'),
            'data'    => $response['data'] ?? [],
        ];
    }

    /**
     * Activate a card.
     *
     * PATCH /cards/activate — payload {cardNo, cardMapId}.
     *
     * Note: there is no PIN/CVV exchange on this endpoint. Earlier
     * versions of this class invented those fields; Fintava ignores them
     * (or rejects on stricter tiers).
     *
     * @param int    $user_id
     * @param string $card_pan
     * @return array|WP_Error
     */
    public function activate_card($user_id, $card_pan) {
        return $this->card_state_change($user_id, $card_pan, '/cards/activate', 'active');
    }

    /**
     * Deactivate (freeze) a card.
     *
     * PATCH /cards/deactivate — payload {cardNo, cardMapId}.
     *
     * @param int    $user_id
     * @param string $card_pan
     * @return array|WP_Error
     */
    public function deactivate_card($user_id, $card_pan) {
        return $this->card_state_change($user_id, $card_pan, '/cards/deactivate', 'frozen');
    }

    /**
     * Shared body of activate / deactivate — they differ only in the
     * endpoint and the local status we write on success.
     */
    private function card_state_change($user_id, $card_pan, $endpoint, $local_status_on_success) {
        $card = $this->get_user_card($user_id);
        if (!$card || empty($card->card_id)) {
            return new WP_Error('no_card', __('No card found', 'matrix-mlm'));
        }

        $pan_digits = self::sanitize_pan($card_pan);
        if ($pan_digits === '') {
            return new WP_Error('invalid_pan', __('A valid card PAN is required.', 'matrix-mlm'));
        }

        $payload = [
            'cardNo'    => $pan_digits,
            'cardMapId' => $card->card_id,
        ];

        $response = $this->make_request('PATCH', $endpoint, $payload);
        if (is_wp_error($response)) {
            return $response;
        }

        if (!Matrix_MLM_Fintava::is_api_success($response)) {
            return new WP_Error(
                'fintava_card_state_error',
                self::stringify_error_message(
                    $response['message'] ?? __('Card state change failed', 'matrix-mlm')
                )
            );
        }

        return [
            'success'      => true,
            'local_status' => $local_status_on_success,
            'message'      => $response['message'] ?? __('Card updated successfully', 'matrix-mlm'),
            'data'         => $response['data'] ?? [],
        ];
    }

    /**
     * Combined link + activate. Single user step (one PAN entry) but two
     * Fintava round-trips. If the link succeeds and the activate fails,
     * the local card row is left at status='linked' so the user can retry
     * activation without re-linking.
     *
     * Tolerates "already linked" responses on the link call. This recovers
     * cards whose local state desynced from Fintava — most often legacy
     * rows whose old POST /cards/link call (pre-API-rewrite, wrong body
     * shape) wrote status='linked' to the local DB without Fintava
     * actually linking anything; running the proper PATCH /cards/link now
     * may surface as "already linked" if Fintava DID quietly accept the
     * old call, or succeed cleanly if it didn't. Either way we proceed to
     * activate, which is the source of truth.
     */
    public function setup_card($user_id, $card_pan) {
        $link = $this->link_card($user_id, $card_pan);
        if (is_wp_error($link) && !self::is_already_linked_error($link)) {
            return $link;
        }

        // Persist linked state immediately so a subsequent activate
        // failure leaves a recoverable row, not a phantom one.
        global $wpdb;
        $card = $this->get_user_card($user_id);
        $last_four = substr(self::sanitize_pan($card_pan), -4);
        $wpdb->update($wpdb->prefix . 'matrix_fintava_cards', [
            'status'     => 'linked',
            'last_four'  => $last_four,
            'updated_at' => current_time('mysql'),
        ], ['id' => $card->id]);

        $activate = $this->activate_card($user_id, $card_pan);
        if (is_wp_error($activate)) {
            return $activate;
        }

        $wpdb->update($wpdb->prefix . 'matrix_fintava_cards', [
            'status'       => 'active',
            'activated_at' => current_time('mysql'),
            'updated_at'   => current_time('mysql'),
        ], ['id' => $card->id]);

        return [
            'success' => true,
            'message' => __('Card linked and activated.', 'matrix-mlm'),
        ];
    }

    /**
     * Detect "card is already linked" responses from PATCH /cards/link so
     * setup_card can treat them as success and proceed to activate.
     *
     * Fintava's exact wording isn't documented and varies by tier, so we
     * match a small set of common substrings rather than an exact string.
     * False positives are bounded: the worst case is that we proceed to
     * activate when we shouldn't, and the activate call rejects with its
     * own clear error message — the user sees something diagnosable
     * either way.
     */
    public static function is_already_linked_error($wp_error) {
        if (!is_wp_error($wp_error)) {
            return false;
        }
        $msg = strtolower((string) $wp_error->get_error_message());
        if ($msg === '') {
            return false;
        }
        return (bool) preg_match(
            '/\balready\b.{0,40}\b(linked|mapped|exists|associated|active)\b|\bduplicate\b|\bconflict\b/',
            $msg
        );
    }

    /**
     * Fetch full card details (and current status) by cardMapId.
     *
     * GET /cards/fetch/{cardMapId}
     *
     * This subsumes the old /cards/status diagnostic — Fintava returns
     * the card's status as part of the fetch response, so a separate
     * status-only endpoint is unnecessary.
     */
    public function fetch_card($card_map_id) {
        $response = $this->make_request('GET', '/cards/fetch/' . rawurlencode($card_map_id));
        if (is_wp_error($response)) {
            return $response;
        }

        if (Matrix_MLM_Fintava::is_api_success($response) && isset($response['data'])) {
            return $response['data'];
        }

        return new WP_Error(
            'fintava_card_fetch_error',
            self::stringify_error_message(
                $response['message'] ?? __('Could not retrieve card details', 'matrix-mlm')
            )
        );
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    /**
     * AJAX: Create a physical card record (Verve, pre-produced).
     *
     * Inputs (POST):
     *   - first_name (falls back to user_meta)
     *   - last_name  (falls back to user_meta)
     */
    public function ajax_request_card() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('Authentication required', 'matrix-mlm')]);
        }

        // Rate limit: 5 card-creation attempts per user per hour.
        // Each attempt hits Fintava's POST /create/card endpoint and
        // is a real Fintava resource burn even when the call fails;
        // a real user creates a card exactly once.
        Matrix_MLM_Rate_Limiter::enforce(
            (int) $user_id,
            'fintava_request_card',
            5,
            HOUR_IN_SECONDS
        );

        if (!Matrix_MLM_User::is_active($user_id)) {
            wp_send_json_error(['message' => __('Your account is suspended', 'matrix-mlm')]);
        }

        if ($this->get_user_card($user_id)) {
            wp_send_json_error(['message' => __('You already have a card. Only one card per user is allowed.', 'matrix-mlm')]);
        }

        $first_name = sanitize_text_field($_POST['first_name'] ?? get_user_meta($user_id, 'first_name', true));
        $last_name  = sanitize_text_field($_POST['last_name']  ?? get_user_meta($user_id, 'last_name',  true));

        if ($first_name === '' || $last_name === '') {
            wp_send_json_error(['message' => __('First name and last name are required', 'matrix-mlm')]);
        }

        $result = $this->request_physical_card($user_id, $first_name, $last_name);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        // Persist. wallet_id is stored for cross-reference even though the
        // link step (PATCH /cards/link) drives off the wallet's
        // account_number, not its wallet_id.
        $wallet = $this->fintava->get_user_wallet($user_id);
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'matrix_fintava_cards', [
            'user_id'    => $user_id,
            'card_id'    => $result['card_map_id'],
            'wallet_id'  => $wallet ? $wallet->wallet_id : null,
            'card_type'  => 'STATIC_NO_ACCOUNT',
            'card_brand' => 'VERVE',
            'status'     => $result['status'] ?: 'pending',
            'metadata'   => wp_json_encode($result['data']),
        ]);

        wp_send_json_success([
            'message'     => __('Verve card created. Next, type in the PAN from your physical card to activate it.', 'matrix-mlm'),
            'card_map_id' => $result['card_map_id'],
            'status'      => $result['status'],
        ]);
    }

    /**
     * AJAX: Combined link + activate (the user's "Activate Card" button).
     *
     * Inputs (POST):
     *   - pan: 16-digit card PAN
     */
    public function ajax_setup_card() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('Authentication required', 'matrix-mlm')]);
        }

        // Rate limit: 5 PAN-bearing attempts per user per hour, shared
        // with ajax_link_card and ajax_activate_card under the
        // 'fintava_card_pan' key. All three handlers accept a PAN and
        // their distinct Fintava error responses are a server-side
        // PAN-validity oracle; a unified key keeps an attacker from
        // multiplying their budget by rotating between handlers.
        Matrix_MLM_Rate_Limiter::enforce(
            (int) $user_id,
            'fintava_card_pan',
            5,
            HOUR_IN_SECONDS
        );

        $pan = wp_unslash($_POST['pan'] ?? '');
        $result = $this->setup_card($user_id, $pan);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        wp_send_json_success(['message' => $result['message']]);
    }

    /**
     * AJAX: Link only. Useful for retrying a failed link without
     * re-creating the card record.
     */
    public function ajax_link_card() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('Authentication required', 'matrix-mlm')]);
        }

        // Rate limit: shared 'fintava_card_pan' key with ajax_setup_card
        // and ajax_activate_card. See ajax_setup_card for rationale.
        Matrix_MLM_Rate_Limiter::enforce(
            (int) $user_id,
            'fintava_card_pan',
            5,
            HOUR_IN_SECONDS
        );

        $pan = wp_unslash($_POST['pan'] ?? '');
        $result = $this->link_card($user_id, $pan);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        $card = $this->get_user_card($user_id);
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'matrix_fintava_cards', [
            'status'     => 'linked',
            'last_four'  => substr(self::sanitize_pan($pan), -4),
            'updated_at' => current_time('mysql'),
        ], ['id' => $card->id]);

        wp_send_json_success(['message' => $result['message']]);
    }

    /**
     * AJAX: Activate only. Used to recover from a setup_card() that linked
     * but failed to activate.
     */
    public function ajax_activate_card() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('Authentication required', 'matrix-mlm')]);
        }

        // Rate limit: shared 'fintava_card_pan' key with ajax_setup_card
        // and ajax_link_card. See ajax_setup_card for rationale.
        Matrix_MLM_Rate_Limiter::enforce(
            (int) $user_id,
            'fintava_card_pan',
            5,
            HOUR_IN_SECONDS
        );

        $pan = wp_unslash($_POST['pan'] ?? '');
        $result = $this->activate_card($user_id, $pan);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        $card = $this->get_user_card($user_id);
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'matrix_fintava_cards', [
            'status'       => 'active',
            'activated_at' => current_time('mysql'),
            'updated_at'   => current_time('mysql'),
        ], ['id' => $card->id]);

        wp_send_json_success(['message' => $result['message']]);
    }

    /**
     * AJAX: Deactivate (freeze) the user's card.
     */
    public function ajax_deactivate_card() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('Authentication required', 'matrix-mlm')]);
        }

        $pan = wp_unslash($_POST['pan'] ?? '');
        $result = $this->deactivate_card($user_id, $pan);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        $card = $this->get_user_card($user_id);
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'matrix_fintava_cards', [
            'status'     => 'frozen',
            'updated_at' => current_time('mysql'),
        ], ['id' => $card->id]);

        wp_send_json_success(['message' => $result['message']]);
    }

    /**
     * AJAX: Fetch card details. Doubles as a status refresh — Fintava's
     * fetch response includes the current status, which we normalize
     * and persist back to the local row.
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

        global $wpdb;
        $update = ['updated_at' => current_time('mysql')];
        if (isset($details['last_four'])) {
            $update['last_four'] = $details['last_four'];
        }
        if (isset($details['status'])) {
            $update['status'] = self::normalize_status($details['status']);
        }
        $wpdb->update($wpdb->prefix . 'matrix_fintava_cards', $update, ['id' => $card->id]);

        wp_send_json_success(['card' => $details]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Get the user's card row from the local DB.
     */
    public function get_user_card($user_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_fintava_cards WHERE user_id = %d ORDER BY id DESC LIMIT 1",
            $user_id
        ));
    }

    /**
     * Resolve Fintava's customer_id (userInfo.id) for a given wallet row.
     *
     * Reads from wallets.customer_id when present (the fast path on
     * KYC-era wallets). Falls back to Matrix_MLM_Fintava::resolve_customer_id(),
     * which has a three-strategy resolver covering legacy wallets, and
     * back-fills the row so subsequent calls hit the fast path.
     *
     * @return string|WP_Error
     */
    private function resolve_user_customer_id($wallet) {
        if (!empty($wallet->customer_id)) {
            return $wallet->customer_id;
        }

        $resolved = $this->fintava->resolve_customer_id($wallet);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'matrix_fintava_wallets',
            ['customer_id' => $resolved],
            ['id' => $wallet->id]
        );

        return $resolved;
    }

    /**
     * Strip everything that isn't a digit out of a PAN.
     *
     * Users typing PANs from their physical card commonly insert spaces or
     * dashes ("5399 4400 1234 5678"). Fintava expects an unbroken digit
     * string.
     */
    public static function sanitize_pan($pan) {
        return preg_replace('/\D+/', '', (string) $pan);
    }

    /**
     * Normalize a Fintava-side status to the local DB enum.
     *
     * Fintava emits UPPERCASE statuses (ACTIVE, FROZEN, INACTIVE, FAILED).
     * Our DB enum is lowercase for legacy reasons. Unknown statuses fall
     * back to 'pending' rather than ever rejecting a row.
     */
    public static function normalize_status($remote) {
        if (!is_string($remote) || $remote === '') {
            return 'pending';
        }
        $lower = strtolower(trim($remote));
        $allowed = [
            'pending'    => 1,
            'processing' => 1,
            'shipped'    => 1,
            'delivered'  => 1,
            'linked'     => 1,
            'active'     => 1,
            'frozen'     => 1,
            'blocked'    => 1,
            'expired'    => 1,
            'inactive'   => 1,
            'failed'     => 1,
        ];
        return isset($allowed[$lower]) ? $lower : 'pending';
    }

    /**
     * Make an API request. Mirrors Matrix_MLM_Fintava's auth/headers so
     * the card sub-gateway always honours the same env selector and
     * wp-config overrides.
     */
    private function make_request($method, $endpoint, $body = null) {
        $url = Matrix_MLM_Fintava::get_base_url() . $endpoint;

        $secret_key = get_option('matrix_mlm_fintava_secret_key', '');
        if (empty($secret_key)) {
            return new WP_Error(
                'fintava_not_configured',
                __('Fintava Pay is not configured. Please add your Live API Key in admin.', 'matrix-mlm')
            );
        }

        $merchant_id = defined('MATRIX_FINTAVA_MERCHANT_ID') && MATRIX_FINTAVA_MERCHANT_ID
            ? MATRIX_FINTAVA_MERCHANT_ID
            : trim(get_option('matrix_mlm_fintava_merchant_id', Matrix_MLM_Fintava::DEFAULT_MERCHANT_ID));

        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $secret_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'Merchant-Id'   => $merchant_id,
            ],
            'timeout' => 30,
        ];

        if ($body !== null && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $raw_body    = wp_remote_retrieve_body($response);
        $payload     = json_decode($raw_body, true);

        if ($status_code >= 400) {
            // Log the full response body so operators can see Fintava's
            // actual error shape — Fintava sometimes returns `message`
            // as a structured object (per-field validation errors,
            // nested `errors[]`, etc.) which used to surface in the UI
            // as the literal string "[object Object]". The log line
            // includes endpoint, status, and raw body for grepping.
            // Gated on WP_DEBUG so production noise stays low.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[Matrix Fintava Card] %s %s -> HTTP %d, body=%s',
                    $method,
                    $endpoint,
                    $status_code,
                    is_string($raw_body) ? substr($raw_body, 0, 2000) : '(non-string)'
                ));
            }

            $error_message = isset($payload['message'])
                ? self::stringify_error_message($payload['message'])
                : '';
            if ($error_message === '') {
                $error_message = sprintf(
                    __('API Error (HTTP %d) calling %s', 'matrix-mlm'),
                    $status_code,
                    $endpoint
                );
            }
            return new WP_Error('fintava_card_api_error', $error_message);
        }

        return $payload;
    }

    /**
     * Coerce a Fintava `message` field into a human-readable string.
     *
     * Fintava is inconsistent across endpoints and tiers: `message` is
     * usually a string, but on validation/business-rule failures it can
     * arrive as:
     *
     *   - An associative array describing one field
     *       e.g. ['field' => 'accountNumber', 'issue' => 'not found']
     *   - A flat list of error strings
     *       e.g. ['accountNumber is required', 'cardName too long']
     *   - A nested `{errors: [...]}` envelope
     *       e.g. ['errors' => ['...', '...']]
     *
     * Without coercion these flow into WP_Error verbatim, get
     * json_encoded by wp_send_json_error, and surface in the browser as
     * the literal string "[object Object]" — useless for diagnosis.
     *
     * The strategy is intentionally conservative: stringify recursively,
     * skip empties, join on ", ". Anything truly opaque falls back to
     * a JSON dump so at least the shape survives the trip to the UI.
     */
    public static function stringify_error_message($message) {
        if (is_string($message)) {
            return $message;
        }
        if (is_numeric($message) || is_bool($message)) {
            return (string) $message;
        }
        if ($message === null) {
            return '';
        }

        // Treat WP_Error transparently — callers occasionally pass one in.
        if (is_wp_error($message)) {
            return self::stringify_error_message($message->get_error_message());
        }

        if (is_array($message)) {
            // Nested {errors: [...]} envelope — unwrap and recurse.
            if (isset($message['errors']) && is_array($message['errors'])) {
                return self::stringify_error_message($message['errors']);
            }

            $parts = [];
            foreach ($message as $key => $value) {
                $piece = self::stringify_error_message($value);
                if ($piece === '') {
                    continue;
                }
                // Preserve string keys ("field: issue") but drop numeric
                // ones to keep flat lists clean.
                $parts[] = is_string($key) ? sprintf('%s: %s', $key, $piece) : $piece;
            }
            if (!empty($parts)) {
                return implode(', ', $parts);
            }
        }

        // Last resort: stash the raw shape so it isn't silently dropped.
        $encoded = wp_json_encode($message);
        return is_string($encoded) ? $encoded : '';
    }

    /**
     * Create the cards table. Bootstrapped on every pageload via
     * Matrix_MLM_Core::run().
     *
     * The status enum is widened from the original 9 values to add
     * 'inactive' and 'failed', which Fintava can return on
     * /cards/fetch. dbDelta does not reliably alter ENUM definitions on
     * existing tables, so a one-shot ALTER is gated behind a
     * schema-version option.
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
            status enum('pending','processing','shipped','delivered','linked','active','frozen','blocked','expired','inactive','failed') NOT NULL DEFAULT 'pending',
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

        // Schema upgrade: existing installs predate the 'inactive'/'failed'
        // enum values. Issue a one-shot ALTER and bump a schema-version
        // option so we don't re-run it every pageload.
        $current_version = (int) get_option('matrix_fintava_cards_schema_version', 1);
        if ($current_version < 2) {
            $wpdb->query("ALTER TABLE $table_name MODIFY status enum('pending','processing','shipped','delivered','linked','active','frozen','blocked','expired','inactive','failed') NOT NULL DEFAULT 'pending'");
            update_option('matrix_fintava_cards_schema_version', 2);
        }
    }
}
