<?php
/**
 * Email and SMS Notifications
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Notifications {

    /**
     * Send verification email
     */
    public static function send_verification_email($user_id) {
        $user = get_userdata($user_id);
        if (!$user) return;

        $token = wp_generate_password(32, false);
        update_user_meta($user_id, 'matrix_email_verify_token', $token);
        update_user_meta($user_id, 'matrix_email_verify_expiry', time() + 3600);

        $verify_url = add_query_arg([
            'action' => 'matrix_verify_email',
            'token' => $token,
            'user' => $user_id
        ], home_url());

        $subject = sprintf(__('[%s] Verify Your Email Address', 'matrix-mlm'), get_bloginfo('name'));
        $message = self::get_email_template('verification', [
            'username' => $user->user_login,
            'verify_url' => $verify_url,
            'site_name' => get_bloginfo('name')
        ]);

        self::send_email($user->user_email, $subject, $message);
    }

    /**
     * Send commission notification
     */
    public static function send_commission_notification($user_id, $amount, $type) {
        $user = get_userdata($user_id);
        if (!$user) return;

        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $type_label = ucfirst(str_replace('_', ' ', $type));

        $subject = sprintf(__('[%s] Commission Received!', 'matrix-mlm'), get_bloginfo('name'));
        $message = self::get_email_template('commission', [
            'username' => $user->user_login,
            'amount' => $currency . number_format($amount, 2),
            'type' => $type_label,
            'site_name' => get_bloginfo('name')
        ]);

        self::send_email($user->user_email, $subject, $message);

        // Send SMS if enabled
        if (get_option('matrix_mlm_sms_verification')) {
            $phone = self::get_user_phone($user_id);
            if ($phone) {
                self::send_sms($phone, sprintf(
                    __('You received %s%s %s commission. Login to your dashboard for details.', 'matrix-mlm'),
                    $currency, number_format($amount, 2), $type_label
                ));
            }
        }
    }

    /**
     * Send deposit confirmation
     */
    public static function send_deposit_notification($user_id, $amount, $status) {
        $user = get_userdata($user_id);
        if (!$user) return;

        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $subject = sprintf(__('[%s] Deposit %s', 'matrix-mlm'), get_bloginfo('name'), ucfirst($status));
        $message = self::get_email_template('deposit', [
            'username' => $user->user_login,
            'amount' => $currency . number_format($amount, 2),
            'status' => ucfirst($status),
            'site_name' => get_bloginfo('name')
        ]);

        self::send_email($user->user_email, $subject, $message);
    }

    /**
     * Send withdrawal notification
     */
    public static function send_withdrawal_notification($user_id, $amount, $status) {
        $user = get_userdata($user_id);
        if (!$user) return;

        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $subject = sprintf(__('[%s] Withdrawal %s', 'matrix-mlm'), get_bloginfo('name'), ucfirst($status));
        $message = self::get_email_template('withdrawal', [
            'username' => $user->user_login,
            'amount' => $currency . number_format($amount, 2),
            'status' => ucfirst($status),
            'site_name' => get_bloginfo('name')
        ]);

        self::send_email($user->user_email, $subject, $message);
    }

    /**
     * Send welcome email after verification
     */
    public static function send_welcome_email($user_id) {
        $user = get_userdata($user_id);
        if (!$user) return;

        $subject = sprintf(__('[%s] Welcome! Your Account is Active', 'matrix-mlm'), get_bloginfo('name'));
        $message = self::get_email_template('welcome', [
            'username' => $user->user_login,
            'dashboard_url' => home_url('/matrix-dashboard'),
            'site_name' => get_bloginfo('name')
        ]);

        self::send_email($user->user_email, $subject, $message);
    }

    /**
     * Send password reset email
     */
    public static function send_password_reset_email($user_id, $reset_url) {
        $user = get_userdata($user_id);
        if (!$user) return;

        $subject = sprintf(__('[%s] Password Reset Request', 'matrix-mlm'), get_bloginfo('name'));
        $message = self::get_email_template('password-reset', [
            'username' => $user->user_login,
            'reset_url' => $reset_url,
            'site_name' => get_bloginfo('name')
        ]);

        self::send_email($user->user_email, $subject, $message);
    }

    /**
     * Send password changed confirmation
     */
    public static function send_password_changed_email($user_id) {
        $user = get_userdata($user_id);
        if (!$user) return;

        $subject = sprintf(__('[%s] Your Password Has Been Changed', 'matrix-mlm'), get_bloginfo('name'));
        $message = self::get_email_template('password-changed', [
            'username' => $user->user_login,
            'site_name' => get_bloginfo('name')
        ]);

        self::send_email($user->user_email, $subject, $message);
    }

    /**
     * Hook into WordPress password reset flow
     * Call this from plugin init to register filters
     */
    public static function register_password_reset_hooks() {
        // Override WordPress default password reset email
        add_filter('retrieve_password_message', [__CLASS__, 'custom_reset_password_message'], 10, 4);
        add_filter('retrieve_password_title', [__CLASS__, 'custom_reset_password_title'], 10, 3);

        // Send confirmation when password is actually changed
        add_action('after_password_reset', [__CLASS__, 'on_password_reset'], 10, 2);
    }

    /**
     * Customize password reset email subject
     */
    public static function custom_reset_password_title($title, $user_login, $user_data) {
        return sprintf(__('[%s] Password Reset Request', 'matrix-mlm'), get_bloginfo('name'));
    }

    /**
     * Customize password reset email body (return HTML)
     */
    public static function custom_reset_password_message($message, $key, $user_login, $user_data) {
        $reset_url = network_site_url("wp-login.php?action=rp&key={$key}&login=" . rawurlencode($user_login), 'login');

        // Set content type to HTML for this email
        add_filter('wp_mail_content_type', [__CLASS__, 'set_html_content_type']);

        $html_message = self::get_email_template('password-reset', [
            'username' => $user_login,
            'reset_url' => $reset_url,
            'site_name' => get_bloginfo('name')
        ]);

        // Remove the filter after use to avoid affecting other emails
        add_action('wp_mail_succeeded', function() {
            remove_filter('wp_mail_content_type', [Matrix_MLM_Notifications::class, 'set_html_content_type']);
        });
        add_action('wp_mail_failed', function() {
            remove_filter('wp_mail_content_type', [Matrix_MLM_Notifications::class, 'set_html_content_type']);
        });

        return $html_message;
    }

    /**
     * Set HTML content type for wp_mail
     */
    public static function set_html_content_type() {
        return 'text/html';
    }

    /**
     * Send confirmation after password is reset
     */
    public static function on_password_reset($user, $new_pass) {
        self::send_password_changed_email($user->ID);
    }

    /**
     * Send transfer received notification
     */
    public static function send_transfer_notification($recipient_id, $sender_id, $amount) {
        $recipient = get_userdata($recipient_id);
        $sender = get_userdata($sender_id);
        if (!$recipient || !$sender) return;

        $currency = get_option('matrix_mlm_currency_symbol', '₦');

        $subject = sprintf(__('[%s] You Received a Transfer!', 'matrix-mlm'), get_bloginfo('name'));
        $message = self::get_email_template('transfer', [
            'username' => $recipient->user_login,
            'sender' => $sender->user_login,
            'amount' => $currency . number_format($amount, 2),
            'site_name' => get_bloginfo('name')
        ]);

        self::send_email($recipient->user_email, $subject, $message);

        // Send SMS if enabled
        if (get_option('matrix_mlm_sms_verification')) {
            $phone = self::get_user_phone($recipient_id);
            if ($phone) {
                self::send_sms($phone, sprintf(
                    __('You received %s%s from %s. Log in to view your balance.', 'matrix-mlm'),
                    $currency, number_format($amount, 2), $sender->user_login
                ));
            }
        }
    }

    /**
     * Send admin notification
     */
    public static function send_admin_notification($type, $message) {
        $admin_email = get_option('admin_email');
        $subject = sprintf(__('[%s] Admin Alert: %s', 'matrix-mlm'), get_bloginfo('name'), ucfirst(str_replace('_', ' ', $type)));

        self::send_email($admin_email, $subject, $message);
    }

    /**
     * Send email using WordPress mail
     */
    private static function send_email($to, $subject, $message) {
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        ];

        wp_mail($to, $subject, $message, $headers);
    }

    /**
     * Send SMS (supports multiple providers)
     */
    public static function send_sms($phone, $message) {
        $sms_provider = get_option('matrix_mlm_sms_provider', 'twilio');
        $sms_api_key = get_option('matrix_mlm_sms_api_key', '');
        $sms_api_secret = get_option('matrix_mlm_sms_api_secret', '');
        $sms_sender_id = get_option('matrix_mlm_sms_sender_id', '');

        if (empty($sms_api_key)) {
            return false;
        }

        switch ($sms_provider) {
            case 'twilio':
                return self::send_twilio_sms($phone, $message, $sms_api_key, $sms_api_secret, $sms_sender_id);
            case 'nexmo':
                return self::send_nexmo_sms($phone, $message, $sms_api_key, $sms_api_secret, $sms_sender_id);
            case 'termii':
                return self::send_termii_sms($phone, $message, $sms_api_key, $sms_sender_id);
            default:
                return false;
        }
    }

    private static function send_twilio_sms($phone, $message, $sid, $token, $from) {
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
        $response = wp_remote_post($url, [
            'headers' => ['Authorization' => 'Basic ' . base64_encode("{$sid}:{$token}")],
            'body' => ['From' => $from, 'To' => $phone, 'Body' => $message]
        ]);
        return !is_wp_error($response);
    }

    private static function send_nexmo_sms($phone, $message, $api_key, $api_secret, $from) {
        $response = wp_remote_post('https://rest.nexmo.com/sms/json', [
            'body' => [
                'api_key' => $api_key,
                'api_secret' => $api_secret,
                'from' => $from,
                'to' => $phone,
                'text' => $message
            ]
        ]);
        return !is_wp_error($response);
    }

    private static function send_termii_sms($phone, $message, $api_key, $sender_id) {
        $response = wp_remote_post('https://api.ng.termii.com/api/sms/send', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'api_key' => $api_key,
                'to' => $phone,
                'from' => $sender_id,
                'sms' => $message,
                'type' => 'plain',
                'channel' => 'generic'
            ])
        ]);
        return !is_wp_error($response);
    }

    /**
     * Get user phone
     */
    private static function get_user_phone($user_id) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT phone FROM {$wpdb->prefix}matrix_user_meta WHERE user_id = %d",
            $user_id
        ));
    }

    /**
     * Get email template
     */
    private static function get_email_template($template, $vars) {
        $template_file = MATRIX_MLM_PLUGIN_DIR . "public/templates/emails/{$template}.php";

        if (file_exists($template_file)) {
            ob_start();
            extract($vars);
            include $template_file;
            return ob_get_clean();
        }

        // Fallback simple template
        $site_name = $vars['site_name'] ?? get_bloginfo('name');
        $content = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">';
        $content .= '<div style="background: linear-gradient(135deg, #4f46e5, #7c3aed); padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">';
        $content .= '<h1 style="color: #fff; margin: 0;">' . esc_html($site_name) . '</h1></div>';
        $content .= '<div style="background: #fff; padding: 30px; border: 1px solid #e5e7eb; border-radius: 0 0 8px 8px;">';

        switch ($template) {
            case 'verification':
                $content .= '<p>' . sprintf(__('Hello %s,', 'matrix-mlm'), $vars['username']) . '</p>';
                $content .= '<p>' . __('Please verify your email address by clicking the button below:', 'matrix-mlm') . '</p>';
                $content .= '<p style="text-align: center;"><a href="' . esc_url($vars['verify_url']) . '" style="background: #4f46e5; color: #fff; padding: 12px 30px; border-radius: 6px; text-decoration: none; display: inline-block;">' . __('Verify Email', 'matrix-mlm') . '</a></p>';
                break;
            case 'commission':
                $content .= '<p>' . sprintf(__('Hello %s,', 'matrix-mlm'), $vars['username']) . '</p>';
                $content .= '<p>' . sprintf(__('You have received a %s commission of %s.', 'matrix-mlm'), $vars['type'], $vars['amount']) . '</p>';
                break;
            case 'deposit':
                $content .= '<p>' . sprintf(__('Hello %s,', 'matrix-mlm'), $vars['username']) . '</p>';
                $content .= '<p>' . sprintf(__('Your deposit of %s has been %s.', 'matrix-mlm'), $vars['amount'], strtolower($vars['status'])) . '</p>';
                break;
            case 'withdrawal':
                $content .= '<p>' . sprintf(__('Hello %s,', 'matrix-mlm'), $vars['username']) . '</p>';
                $content .= '<p>' . sprintf(__('Your withdrawal of %s has been %s.', 'matrix-mlm'), $vars['amount'], strtolower($vars['status'])) . '</p>';
                break;
            case 'transfer':
                $content .= '<p>' . sprintf(__('Hello %s,', 'matrix-mlm'), $vars['username']) . '</p>';
                $content .= '<p>' . sprintf(__('You received a transfer of %s from %s.', 'matrix-mlm'), $vars['amount'], $vars['sender']) . '</p>';
                $content .= '<p>' . __('The funds have been credited to your Matrix wallet.', 'matrix-mlm') . '</p>';
                break;
            case 'welcome':
                $content .= '<p>' . sprintf(__('Hello %s,', 'matrix-mlm'), $vars['username']) . '</p>';
                $content .= '<p>' . sprintf(__('Welcome to %s! Your email has been verified and your account is now active.', 'matrix-mlm'), $vars['site_name']) . '</p>';
                $content .= '<p style="text-align: center;"><a href="' . esc_url($vars['dashboard_url']) . '" style="background: #4f46e5; color: #fff; padding: 12px 30px; border-radius: 6px; text-decoration: none; display: inline-block;">' . __('Go to Dashboard', 'matrix-mlm') . '</a></p>';
                break;
            case 'password-reset':
                $content .= '<p>' . sprintf(__('Hello %s,', 'matrix-mlm'), $vars['username']) . '</p>';
                $content .= '<p>' . __('We received a request to reset your password. Click the button below:', 'matrix-mlm') . '</p>';
                $content .= '<p style="text-align: center;"><a href="' . esc_url($vars['reset_url']) . '" style="background: #dc2626; color: #fff; padding: 12px 30px; border-radius: 6px; text-decoration: none; display: inline-block;">' . __('Reset Password', 'matrix-mlm') . '</a></p>';
                $content .= '<p style="font-size: 12px; color: #6b7280;">' . __('This link expires in 1 hour. If you did not request this, ignore this email.', 'matrix-mlm') . '</p>';
                break;
            case 'password-changed':
                $content .= '<p>' . sprintf(__('Hello %s,', 'matrix-mlm'), $vars['username']) . '</p>';
                $content .= '<p>' . __('Your password has been successfully changed. You can now log in with your new password.', 'matrix-mlm') . '</p>';
                $content .= '<p style="font-size: 12px; color: #dc2626;">' . __('If you did not make this change, please reset your password immediately.', 'matrix-mlm') . '</p>';
                break;
        }

        $content .= '</div></div>';
        return $content;
    }
}
