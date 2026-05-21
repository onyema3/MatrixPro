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
        }

        $content .= '</div></div>';
        return $content;
    }
}
