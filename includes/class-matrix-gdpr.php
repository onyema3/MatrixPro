<?php
/**
 * GDPR Compliance
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_GDPR {

    public function __construct() {
        add_action('wp_footer', [$this, 'render_cookie_consent']);
        add_action('wp_ajax_matrix_accept_cookies', [$this, 'accept_cookies']);
        add_action('wp_ajax_nopriv_matrix_accept_cookies', [$this, 'accept_cookies']);
        add_action('wp_ajax_matrix_export_user_data', [$this, 'export_user_data']);
        add_action('wp_ajax_matrix_delete_user_data', [$this, 'request_data_deletion']);
    }

    /**
     * Render cookie consent banner
     */
    public function render_cookie_consent() {
        if (!get_option('matrix_mlm_gdpr_enabled', 1)) {
            return;
        }

        if (isset($_COOKIE['matrix_cookie_consent'])) {
            return;
        }

        $cookie_text = get_option('matrix_mlm_cookie_text', __('We use cookies to enhance your experience. By continuing to visit this site you agree to our use of cookies.', 'matrix-mlm'));
        $privacy_url = get_option('matrix_mlm_privacy_url', '/privacy-policy');
        ?>
        <div id="matrix-cookie-consent" style="position: fixed; bottom: 0; left: 0; right: 0; background: #1f2937; color: #fff; padding: 16px 24px; z-index: 99999; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; box-shadow: 0 -4px 12px rgba(0,0,0,0.15);">
            <div style="flex: 1; min-width: 300px;">
                <p style="margin: 0; font-size: 14px; line-height: 1.5;"><?php echo esc_html($cookie_text); ?>
                    <a href="<?php echo esc_url($privacy_url); ?>" style="color: #818cf8; text-decoration: underline;"><?php _e('Learn More', 'matrix-mlm'); ?></a>
                </p>
            </div>
            <div style="display: flex; gap: 8px;">
                <button onclick="matrixAcceptCookies()" style="background: #4f46e5; color: #fff; border: none; padding: 8px 20px; border-radius: 6px; cursor: pointer; font-size: 14px;"><?php _e('Accept', 'matrix-mlm'); ?></button>
                <button onclick="matrixRejectCookies()" style="background: transparent; color: #9ca3af; border: 1px solid #4b5563; padding: 8px 20px; border-radius: 6px; cursor: pointer; font-size: 14px;"><?php _e('Reject', 'matrix-mlm'); ?></button>
            </div>
        </div>
        <script>
        function matrixAcceptCookies() {
            document.cookie = "matrix_cookie_consent=accepted; path=/; max-age=" + (365 * 24 * 60 * 60);
            document.getElementById('matrix-cookie-consent').style.display = 'none';
            // CSRF guard: include the matrix_mlm_nonce localized on
            // window.matrixMLM by the matrix-mlm-public handle. The
            // accept_cookies AJAX handler is a no-op today but the
            // endpoint is reachable from any origin (it has both an
            // authenticated and a wp_ajax_nopriv_* registration), so
            // gating it now prevents a future addition of business
            // logic from silently inheriting a CSRF foothold. The
            // matrixMLM object is enqueued in the head and therefore
            // always defined before this function is invoked from
            // the footer-rendered banner.
            var nonce = (window.matrixMLM && window.matrixMLM.nonce) ? window.matrixMLM.nonce : '';
            jQuery.post(matrixMLM.ajaxUrl, {action: 'matrix_accept_cookies', nonce: nonce, consent: 'accepted'});
        }
        function matrixRejectCookies() {
            document.cookie = "matrix_cookie_consent=rejected; path=/; max-age=" + (365 * 24 * 60 * 60);
            document.getElementById('matrix-cookie-consent').style.display = 'none';
        }
        </script>
        <?php
    }

    /**
     * Handle cookie acceptance.
     *
     * The handler itself does no server-side work today (consent is
     * authoritative on the client cookie set in the banner script),
     * but the endpoint is registered for both wp_ajax_* and
     * wp_ajax_nopriv_*, which makes it reachable from any origin.
     * Gating it with a nonce now means a future addition of logic
     * here (e.g. persisting consent in user_meta for analytics or
     * audit) won't silently inherit a CSRF foothold. The $die=false
     * variant is used so a stale cached banner without the nonce
     * still receives a clean JSON response — no end-user impact,
     * the cookie is already set client-side regardless.
     */
    public function accept_cookies() {
        if (!check_ajax_referer('matrix_mlm_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Invalid request', 'matrix-mlm')], 403);
        }
        wp_send_json_success();
    }

    /**
     * Export user data (GDPR right to access)
     */
    public function export_user_data() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('Not authenticated', 'matrix-mlm')]);
        }

        global $wpdb;

        $data = [
            'user_info' => get_userdata($user_id),
            'matrix_meta' => $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}matrix_user_meta WHERE user_id = %d", $user_id
            )),
            'transactions' => $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}matrix_wallet WHERE user_id = %d", $user_id
            )),
            'deposits' => $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}matrix_deposits WHERE user_id = %d", $user_id
            )),
            'withdrawals' => $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}matrix_withdrawals WHERE user_id = %d", $user_id
            )),
            'commissions' => $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}matrix_commissions WHERE user_id = %d", $user_id
            )),
            'tickets' => $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}matrix_tickets WHERE user_id = %d", $user_id
            )),
        ];

        wp_send_json_success(['data' => $data]);
    }

    /**
     * Request data deletion (GDPR right to erasure)
     */
    public function request_data_deletion() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('Not authenticated', 'matrix-mlm')]);
        }

        // Send notification to admin for manual review
        Matrix_MLM_Notifications::send_admin_notification(
            'data_deletion_request',
            sprintf(__('User #%d has requested data deletion under GDPR.', 'matrix-mlm'), $user_id)
        );

        wp_send_json_success(['message' => __('Your data deletion request has been submitted. We will process it within 30 days.', 'matrix-mlm')]);
    }
}
