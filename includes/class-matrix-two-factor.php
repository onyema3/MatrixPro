<?php
/**
 * Two-Factor Authentication.
 *
 * Security note: TOTP shared secrets are stored at-rest encrypted
 * via Matrix_MLM_Crypto::encrypt() under the domain-separating
 * context 'matrix_mlm:two_factor'. Legacy plaintext secrets that
 * pre-date the encryption rollout are accepted on read (audit H10
 * lazy-migration) — the next time a user verifies a code or
 * re-runs enrolment, the stored secret is upgraded to the v1
 * envelope.
 *
 * QR codes are generated locally via Matrix_MLM_QR_SVG and returned
 * as inline `data:image/svg+xml;base64,…` URLs, eliminating the
 * previous request to api.qrserver.com which logged every user's
 * otpauth URL (and therefore every user's TOTP secret) on a
 * third-party server (audit H11).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Two_Factor {

    const CRYPTO_CONTEXT = 'matrix_mlm:two_factor';

    /**
     * Enable 2FA for user
     */
    public function enable($user_id) {
        $secret = $this->generate_secret();
        $this->store_secret($user_id, $secret, true);

        $user = get_userdata($user_id);
        $site_name = get_bloginfo('name');
        // The label and issuer are URL-encoded individually so that
        // sites with names containing colons or spaces still produce
        // a valid otpauth:// URI.
        $label   = rawurlencode($site_name . ':' . $user->user_email);
        $issuer  = rawurlencode($site_name);
        $secret_q = rawurlencode($secret);
        $otpauth_url = "otpauth://totp/{$label}?secret={$secret_q}&issuer={$issuer}";

        return [
            'secret'      => $secret,
            'otpauth_url' => $otpauth_url,
            // Inline data-URL — same-origin, no third-party request.
            'qr_url'      => Matrix_MLM_QR_SVG::render_data_url($otpauth_url),
            'message'     => __('2FA enabled successfully. Scan the QR code with your authenticator app, or enter the secret manually.', 'matrix-mlm'),
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
     * Verify 2FA code.
     *
     * Reads the encrypted secret, decrypts it, and runs the standard
     * TOTP window check. If the decrypted value indicates the row
     * was still in legacy plaintext form, the value is opportunistically
     * re-encrypted on success — that's the lazy-migration path
     * referenced in the class docblock.
     */
    public function verify($user_id, $code) {
        global $wpdb;
        $stored = $wpdb->get_var($wpdb->prepare(
            "SELECT two_factor_secret FROM {$wpdb->prefix}matrix_user_meta WHERE user_id = %d",
            $user_id
        ));

        if (!$stored) {
            return false;
        }

        $secret = Matrix_MLM_Crypto::decrypt($stored, self::CRYPTO_CONTEXT);
        if ($secret === null || $secret === '') {
            // Decryption failure — surface as an invalid-code result so
            // the front-end can show "invalid" rather than blowing up.
            // Operator log so it's not silent.
            error_log(sprintf('[Matrix MLM 2FA] Decrypt failure for user_id=%d (corrupt or salt-rotated)', $user_id));
            return false;
        }

        $ok = $this->verify_totp($secret, $code);

        // Lazy migration: if the stored value was still plaintext (no
        // envelope prefix) AND the code verified, upgrade it to an
        // encrypted envelope so the row stops sitting in plaintext.
        if ($ok && strpos((string) $stored, Matrix_MLM_Crypto::ENVELOPE_PREFIX) !== 0) {
            $this->store_secret($user_id, $secret, false);
        }
        return $ok;
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
     * Persist the secret encrypted at rest.
     *
     * @param int    $user_id
     * @param string $secret_plain Base32 secret as displayed to the user.
     * @param bool   $also_enable  Set the two_factor_enabled flag at the
     *                             same time (used during initial enrol;
     *                             not used by the lazy-migration path
     *                             which only touches the secret column).
     */
    private function store_secret($user_id, $secret_plain, $also_enable) {
        global $wpdb;

        $envelope = Matrix_MLM_Crypto::encrypt($secret_plain, self::CRYPTO_CONTEXT);

        $update = ['two_factor_secret' => $envelope];
        if ($also_enable) {
            $update['two_factor_enabled'] = 1;
        }

        $wpdb->update(
            $wpdb->prefix . 'matrix_user_meta',
            $update,
            ['user_id' => $user_id]
        );
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
