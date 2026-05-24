<?php
/**
 * Matrix MLM — symmetric encryption helper for at-rest secrets.
 *
 * Used by the Two-Factor module (TOTP shared secrets) and is
 * intentionally generic so other future at-rest secrets (BVN,
 * external API keys, anything else PII-grade) can pile onto the
 * same envelope and key-derivation strategy without each one
 * inventing its own.
 *
 * Algorithm:
 *   - Primary: libsodium's secretbox (XSalsa20-Poly1305) — AEAD
 *     by construction, available on PHP 7.2+ as a core extension.
 *   - Fallback: openssl with AES-256-GCM. Keeps the same envelope
 *     shape so a future migration off openssl back to sodium is
 *     transparent — every ciphertext carries an algorithm tag.
 *
 * Envelope format (base64-encoded for char-safe storage):
 *
 *     v1:<algo>:<base64(nonce)>:<base64(ciphertext+tag)>
 *
 * The plaintext column on disk is varchar(255) which fits any
 * reasonable secret + envelope easily (a 16-byte secret expands
 * to ~120 chars after base64).
 *
 * Key derivation:
 *   The site-level key is derived once from the value of
 *   wp_salt('auth') with a domain-separating context string so the
 *   same WordPress install can use the helper for unrelated
 *   purposes (e.g. BVN encryption later) without those ciphertexts
 *   ever being interchangeable. Operators can override the source
 *   key by defining the constant MATRIX_MLM_ENCRYPTION_KEY in
 *   wp-config.php — useful when migrating between environments
 *   (so secrets re-encrypted by the new install can still decrypt
 *   data exported from the old one).
 *
 * Backwards compatibility:
 *   decrypt() recognizes a missing 'v1:' prefix as a legacy
 *   plaintext value and returns it unchanged. This lets the
 *   Two-Factor module ship the encryption helper in one PR and
 *   lazy-migrate existing plaintext secrets on next verify or
 *   next regen, without forcing a hard cut-over that would lock
 *   out users mid-rollout.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Crypto {

    const ENVELOPE_PREFIX = 'v1:';
    const ALGO_SODIUM     = 'sx';   // sodium secretbox
    const ALGO_OPENSSL    = 'gcm';  // openssl AES-256-GCM

    /**
     * Encrypt a string for at-rest storage.
     *
     * Returns the envelope-encoded ciphertext on success.
     * On a key-derivation failure (extreme edge case — site has no
     * AUTH_KEY at all and no MATRIX_MLM_ENCRYPTION_KEY override),
     * returns the input unchanged so the caller's storage path
     * still succeeds. This is preferable to throwing because the
     * Two-Factor enable flow shouldn't fatal out on a misconfigured
     * site — the operator will fix the salts and lazy migration
     * will encrypt on next verify.
     *
     * @param string $plaintext  Value to encrypt (e.g. a Base32 secret).
     * @param string $context    Domain-separating string. Different
     *                           contexts produce different keys, so
     *                           a TOTP secret cannot be decrypted
     *                           into a BVN slot or vice versa.
     * @return string envelope-encoded ciphertext, or $plaintext on
     *                key-derivation failure.
     */
    public static function encrypt($plaintext, $context = 'matrix_mlm:default') {
        if ($plaintext === null || $plaintext === '') {
            return $plaintext;
        }

        $key = self::derive_key($context);
        if ($key === false) {
            return $plaintext;
        }

        // Prefer sodium when available — AEAD by construction, no
        // mode-of-operation pitfalls.
        if (function_exists('sodium_crypto_secretbox')) {
            try {
                $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $ct = sodium_crypto_secretbox($plaintext, $nonce, $key);
                return self::ENVELOPE_PREFIX . self::ALGO_SODIUM . ':'
                     . self::b64u_encode($nonce) . ':'
                     . self::b64u_encode($ct);
            } catch (\Exception $e) {
                // Fall through to openssl.
            }
        }

        if (function_exists('openssl_encrypt')) {
            $iv = random_bytes(12); // GCM 96-bit IV
            $tag = '';
            $ct = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
            if ($ct !== false) {
                return self::ENVELOPE_PREFIX . self::ALGO_OPENSSL . ':'
                     . self::b64u_encode($iv) . ':'
                     . self::b64u_encode($ct . $tag);
            }
        }

        // No crypto extension available. Return plaintext as a last
        // resort — the operator's PHP build is missing both sodium
        // and openssl, which is a deployment problem we can't fix
        // here. Loud log so it's visible in monitoring.
        error_log('[Matrix MLM Crypto] Neither sodium nor openssl available; storing secret in plaintext.');
        return $plaintext;
    }

    /**
     * Decrypt a value previously produced by encrypt().
     *
     * Backwards compatibility: a value without the v1: envelope
     * prefix is treated as a legacy plaintext and returned as-is.
     * This keeps the rollout safe — pre-encryption secrets in the
     * database continue to work, and the Two-Factor module can
     * lazy-migrate them on next verify.
     *
     * On a real decryption failure (tampered ciphertext, wrong key
     * after a salt rotation, etc.) returns null so the caller can
     * surface a clean "secret unreadable, please re-enrol" path
     * instead of crashing.
     *
     * @return string|null Plaintext on success, $stored on legacy
     *                     plaintext, null on failure.
     */
    public static function decrypt($stored, $context = 'matrix_mlm:default') {
        if ($stored === null || $stored === '') {
            return $stored;
        }

        // Legacy plaintext — the column existed before this helper.
        if (strpos($stored, self::ENVELOPE_PREFIX) !== 0) {
            return $stored;
        }

        $parts = explode(':', $stored, 4);
        if (count($parts) !== 4) {
            return null;
        }
        // $parts[0] is the prefix without colons ('v1') because explode
        // already consumed the first colon as a separator. The four
        // tokens are: 'v1', '<algo>', '<b64-nonce>', '<b64-ciphertext>'.
        list(, $algo, $nonce_b64, $ct_b64) = $parts;
        $nonce = self::b64u_decode($nonce_b64);
        $ct    = self::b64u_decode($ct_b64);
        if ($nonce === false || $ct === false) {
            return null;
        }

        $key = self::derive_key($context);
        if ($key === false) {
            return null;
        }

        if ($algo === self::ALGO_SODIUM && function_exists('sodium_crypto_secretbox_open')) {
            try {
                $pt = sodium_crypto_secretbox_open($ct, $nonce, $key);
                return $pt === false ? null : $pt;
            } catch (\Exception $e) {
                return null;
            }
        }

        if ($algo === self::ALGO_OPENSSL && function_exists('openssl_decrypt')) {
            // Tag is the last 16 bytes; ciphertext is everything before.
            if (strlen($ct) < 16) { return null; }
            $tag = substr($ct, -16);
            $body = substr($ct, 0, -16);
            $pt = openssl_decrypt($body, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '');
            return $pt === false ? null : $pt;
        }

        return null;
    }

    /**
     * Re-derive the per-context key on every call. Cheap (one HKDF /
     * one HMAC) and side-stepping a static cache keeps the function
     * stateless, which makes it safe for fork/threaded deployments
     * (php-fpm pools, Roadrunner) where a long-lived static could
     * cross requests under unusual SAPI configurations.
     */
    private static function derive_key($context) {
        if (defined('MATRIX_MLM_ENCRYPTION_KEY') && MATRIX_MLM_ENCRYPTION_KEY) {
            $material = (string) MATRIX_MLM_ENCRYPTION_KEY;
        } else {
            $material = wp_salt('auth');
        }
        if ($material === '' || $material === 'put your unique phrase here') {
            return false;
        }
        // HMAC-SHA256 with the context as key, the material as data —
        // this is HKDF-Extract with a fixed salt, and gives us 32
        // bytes (sufficient for both XSalsa20 and AES-256).
        return hash_hmac('sha256', $material, $context, true);
    }

    /**
     * URL-safe base64 (no padding) for compactness in the envelope.
     */
    private static function b64u_encode($raw) {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
    private static function b64u_decode($s) {
        $s = strtr($s, '-_', '+/');
        $padding = strlen($s) % 4;
        if ($padding) { $s .= str_repeat('=', 4 - $padding); }
        $raw = base64_decode($s, true);
        return $raw === false ? false : $raw;
    }
}
