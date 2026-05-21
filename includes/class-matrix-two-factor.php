<?php
/**
 * Two-Factor Authentication
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Two_Factor {

    /**
     * Enable 2FA for user
     */
    public function enable($user_id) {
        $secret = $this->generate_secret();

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'matrix_user_meta',
            [
                'two_factor_enabled' => 1,
                'two_factor_secret' => $secret
            ],
            ['user_id' => $user_id]
        );

        $user = get_userdata($user_id);
        $site_name = get_bloginfo('name');
        $otpauth_url = "otpauth://totp/{$site_name}:{$user->user_email}?secret={$secret}&issuer={$site_name}";

        return [
            'secret' => $secret,
            'qr_url' => 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($otpauth_url),
            'message' => __('2FA enabled successfully. Scan the QR code with your authenticator app.', 'matrix-mlm')
        ];
    }

    /**
     * Disable 2FA for user
     */
    public function disable($user_id) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'matrix_user_meta',
            [
                'two_factor_enabled' => 0,
                'two_factor_secret' => null
            ],
            ['user_id' => $user_id]
        );
        return true;
    }

    /**
     * Verify 2FA code
     */
    public function verify($user_id, $code) {
        global $wpdb;
        $secret = $wpdb->get_var($wpdb->prepare(
            "SELECT two_factor_secret FROM {$wpdb->prefix}matrix_user_meta WHERE user_id = %d",
            $user_id
        ));

        if (!$secret) {
            return false;
        }

        return $this->verify_totp($secret, $code);
    }

    /**
     * Check if 2FA is enabled for user
     */
    public function is_enabled($user_id) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT two_factor_enabled FROM {$wpdb->prefix}matrix_user_meta WHERE user_id = %d",
            $user_id
        ));
    }

    /**
     * Generate a random secret (Base32 encoded)
     */
    private function generate_secret($length = 16) {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        return $secret;
    }

    /**
     * Verify TOTP code
     */
    private function verify_totp($secret, $code, $window = 1) {
        $timestamp = floor(time() / 30);

        for ($i = -$window; $i <= $window; $i++) {
            $calculated = $this->calculate_totp($secret, $timestamp + $i);
            if (hash_equals($calculated, str_pad($code, 6, '0', STR_PAD_LEFT))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate TOTP value
     */
    private function calculate_totp($secret, $timestamp) {
        $key = $this->base32_decode($secret);
        $time = pack('N*', 0) . pack('N*', $timestamp);
        $hash = hash_hmac('sha1', $time, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $code = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % pow(10, 6);

        return str_pad($code, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Base32 decode
     */
    private function base32_decode($input) {
        $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $input = strtoupper($input);
        $input = rtrim($input, '=');

        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        for ($i = 0; $i < strlen($input); $i++) {
            $val = strpos($map, $input[$i]);
            if ($val === false) continue;

            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }
}
