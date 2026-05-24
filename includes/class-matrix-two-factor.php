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
 *
 * Recovery codes (audit M2): on enrolment, a set of one-shot
 * recovery codes is generated and shown to the user exactly once.
 * Each code is stored as a password_hash() digest in
 * matrix_user_meta.two_factor_recovery_codes (JSON array). When a
 * user submits a recovery code at the login OTP step, verify()
 * scans the hash list with password_verify(), consumes the matched
 * entry on success, and returns true the same way a valid TOTP
 * code would. This gives users a self-service recovery path when
 * they lose their authenticator device, without depending on
 * admin intervention.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Two_Factor {

    const CRYPTO_CONTEXT = 'matrix_mlm:two_factor';

    /**
     * Number of recovery codes generated per enrolment / regeneration.
     * 8 is the de-facto industry default (matches GitHub, GitLab,
     * Google) — enough that a user can absorb several losses without
     * being locked out, few enough that the one-time display fits in
     * a viewport without scrolling.
     */
    const RECOVERY_CODE_COUNT = 8;

    /**
     * Plaintext recovery code format: 10 alphanumerics, dash-grouped
     * as XXXXX-XXXXX. ~50 bits of entropy per code; brute-forcing one
     * would require ~5e14 attempts, far beyond what the login rate
     * limiter (10/user, 50/IP per 15-min) would allow even in a
     * decade. Hyphen included for legibility; stripped before hash
     * comparison so users can paste with or without it.
     */
    const RECOVERY_CODE_LEN = 10;

    /**
     * Enable 2FA for user.
     *
     * Generates a fresh TOTP secret, a fresh set of recovery codes,
     * and persists both. Returns the otpauth URL, an inline QR data
     * URL, and the plaintext recovery codes for one-time display.
     * Plaintext recovery codes are NEVER persisted — only their
     * password_hash() digests are written.
     *
     * Caller (process_enable_2fa) is responsible for the
     * already-enabled and password-reauth gates; this method does
     * not enforce them so it stays reusable from admin tooling.
     */
    public function enable($user_id) {
        $secret = $this->generate_secret();
        $this->store_secret($user_id, $secret, true);

        $plaintext_codes = $this->generate_recovery_codes();
        $this->store_recovery_codes($user_id, $plaintext_codes);

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
            'secret'         => $secret,
            'otpauth_url'    => $otpauth_url,
            // Inline data-URL — same-origin, no third-party request.
            'qr_url'         => Matrix_MLM_QR_SVG::render_data_url($otpauth_url),
            'recovery_codes' => $plaintext_codes,
            'message'        => __('2FA enabled. Scan the QR code with your authenticator app, and save the recovery codes below — they are shown only once and let you sign in if you lose your device.', 'matrix-mlm'),
        ];
    }

    /**
     * Disable 2FA for user.
     *
     * Clears the secret, the enabled flag, and the recovery codes.
     * Caller is responsible for verifying the user's intent (password
     * reauth + a fresh OTP or recovery code) — that gate lives in
     * process_disable_2fa().
     */
    public function disable($user_id) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'matrix_user_meta',
            [
                'two_factor_enabled'        => 0,
                'two_factor_secret'         => null,
                'two_factor_recovery_codes' => null,
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
     *
     * Recovery-code path (audit M2): if the TOTP check fails, the
     * supplied code is normalised (uppercase, hyphens stripped, all
     * non-alphanumerics dropped) and matched against the stored
     * password_hash() digests. A match consumes the digest from the
     * list (single-use) and returns true. The TOTP and recovery
     * paths share the same return shape so the login flow does not
     * need to branch on which one accepted the code.
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
            // Fall through to the recovery-code branch — a user who
            // lost their authenticator AND whose secret cannot be
            // decrypted (post salt-rotation) should still be able to
            // sign in with a recovery code.
            $secret = null;
        }

        if ($secret !== null) {
            $ok = $this->verify_totp($secret, $code, 1, $user_id);
            if ($ok) {
                // Lazy migration: if the stored value was still plaintext
                // (no envelope prefix) AND the code verified, upgrade it
                // to an encrypted envelope so the row stops sitting in
                // plaintext.
                if (strpos((string) $stored, Matrix_MLM_Crypto::ENVELOPE_PREFIX) !== 0) {
                    $this->store_secret($user_id, $secret, false);
                }
                return true;
            }
        }

        // TOTP path didn't accept — try the recovery-code path.
        return $this->consume_recovery_code($user_id, $code);
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
     * Regenerate recovery codes.
     *
     * Used when the user has burned through (or lost) their original
     * codes and wants a fresh batch. Returns the new plaintext codes
     * for one-time display. The previous batch is replaced wholesale
     * — partial-list regeneration would be confusing and is not a
     * pattern any reference implementation supports.
     *
     * Caller (process_regenerate_recovery_codes) is responsible for
     * the password-reauth gate.
     */
    public function regenerate_recovery_codes($user_id) {
        $plaintext_codes = $this->generate_recovery_codes();
        $this->store_recovery_codes($user_id, $plaintext_codes);
        return $plaintext_codes;
    }

    /**
     * Count of unused recovery codes still on the row. Used by the
     * dashboard UI to nudge the user to regenerate when they're
     * running low (≤2 remaining is the typical threshold).
     */
    public function remaining_recovery_codes($user_id) {
        global $wpdb;
        $stored = $wpdb->get_var($wpdb->prepare(
            "SELECT two_factor_recovery_codes FROM {$wpdb->prefix}matrix_user_meta WHERE user_id = %d",
            $user_id
        ));
        if (!$stored) {
            return 0;
        }
        $hashes = json_decode((string) $stored, true);
        return is_array($hashes) ? count($hashes) : 0;
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
     * Generate a fresh batch of recovery codes for one-time display.
     *
     * Each code is RECOVERY_CODE_LEN alphanumerics drawn from a
     * confusables-removed alphabet (no 0/O, 1/I/L, etc.) to reduce
     * transcription errors when the user writes them down. Codes are
     * formatted as XXXXX-XXXXX for legibility.
     */
    private function generate_recovery_codes() {
        // Confusables-removed alphabet: drops 0, O, 1, I, L, U, V.
        // 28 chars → ~4.8 bits per char → ~48 bits per 10-char code.
        $chars = 'ABCDEFGHJKMNPQRSTWXYZ23456789';
        $count = strlen($chars);
        $codes = [];
        for ($n = 0; $n < self::RECOVERY_CODE_COUNT; $n++) {
            $raw = '';
            for ($i = 0; $i < self::RECOVERY_CODE_LEN; $i++) {
                $raw .= $chars[random_int(0, $count - 1)];
            }
            // XXXXX-XXXXX
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
        }
        return $codes;
    }

    /**
     * Hash and persist a batch of plaintext recovery codes.
     *
     * password_hash() with PASSWORD_DEFAULT (currently bcrypt) is the
     * right choice here — these are short user-typeable secrets, the
     * verification path is rare (recovery only), and we want the
     * default bcrypt cost to make any DB-leak brute-force expensive.
     * The codes are normalised (uppercase, hyphens stripped) before
     * hashing so the verify path can tolerate either format.
     */
    private function store_recovery_codes($user_id, array $plaintext_codes) {
        global $wpdb;
        $hashes = [];
        foreach ($plaintext_codes as $code) {
            $normalised = $this->normalise_recovery_code($code);
            if ($normalised === '') {
                continue;
            }
            $hashes[] = password_hash($normalised, PASSWORD_DEFAULT);
        }
        $wpdb->update(
            $wpdb->prefix . 'matrix_user_meta',
            ['two_factor_recovery_codes' => wp_json_encode($hashes)],
            ['user_id' => $user_id]
        );
    }

    /**
     * Strip everything that isn't an alphanumeric and uppercase the
     * rest. Lets users paste codes with or without the dash, with
     * incidental whitespace, in either case.
     */
    private function normalise_recovery_code($code) {
        $code = strtoupper((string) $code);
        return preg_replace('/[^A-Z0-9]/', '', $code);
    }

    /**
     * Try to consume a recovery code on behalf of $user_id.
     *
     * Iterates the full stored hash list in constant time
     * (no early-exit on first match — bcrypt response time would
     * otherwise leak roughly which slot matched), then atomically
     * rewrites the row using a compare-and-set UPDATE that includes
     * the previously-read JSON blob in the WHERE clause. If a
     * concurrent request consumed the same code between this
     * handler's SELECT and UPDATE, the WHERE will not match and
     * $wpdb->update returns 0 — we treat that as "not consumed" and
     * fail closed, so a code is never used twice even under a race.
     *
     * Returns false if the user has no recovery codes set, if no
     * entry matches, or if the CAS lost (concurrent consumer).
     */
    private function consume_recovery_code($user_id, $code) {
        $candidate = $this->normalise_recovery_code($code);
        if ($candidate === '' || strlen($candidate) < 6) {
            return false;
        }

        global $wpdb;
        $stored = $wpdb->get_var($wpdb->prepare(
            "SELECT two_factor_recovery_codes FROM {$wpdb->prefix}matrix_user_meta WHERE user_id = %d",
            $user_id
        ));
        if (!$stored) {
            return false;
        }

        $hashes = json_decode((string) $stored, true);
        if (!is_array($hashes) || empty($hashes)) {
            return false;
        }

        // Constant-time scan: walk the entire list and remember the
        // index of the first match without short-circuiting. bcrypt
        // is intentionally slow, so an early break would let an
        // attacker discriminate which slot matched (~3 bits per
        // attempt across an 8-element list). Low impact given the
        // ~50-bit input space, but trivially fixable here.
        $matched_index = -1;
        foreach ($hashes as $i => $hash) {
            if (is_string($hash) && password_verify($candidate, $hash)) {
                if ($matched_index === -1) {
                    $matched_index = $i;
                }
            }
        }
        if ($matched_index === -1) {
            return false;
        }

        // Single-use: drop the matched entry and CAS-write the new
        // list. The WHERE includes the previously-read blob so a
        // concurrent consumer that already shrank the list cannot
        // race us — exactly one writer wins, exactly one consumes.
        unset($hashes[$matched_index]);
        $new_list = wp_json_encode(array_values($hashes));

        $rows = $wpdb->update(
            $wpdb->prefix . 'matrix_user_meta',
            ['two_factor_recovery_codes' => $new_list],
            [
                'user_id'                   => $user_id,
                'two_factor_recovery_codes' => $stored,
            ]
        );
        // $wpdb->update returns 0 if WHERE matched no rows OR if the
        // matched row's values were already equal to the new values
        // (MySQL "0 affected rows" semantics). The second case can't
        // happen here — we just removed an entry, so the post-image
        // is strictly different from the pre-image — so 0 unambiguously
        // means we lost the race. Treat as not-consumed.
        if ($rows !== 1) {
            return false;
        }
        return true;
    }

    /**
     * Verify TOTP code.
     *
     * Replay protection (audit M1): the window check accepts any of
     * (t-1, t, t+1) for a 30-second period. Without anti-replay, a
     * code observed by an attacker (shoulder-surf, phishing relay,
     * malicious browser extension reading the OTP form) is reusable
     * for up to ~90 seconds. We bound that by recording the highest
     * step ever accepted for this user and refusing any subsequent
     * attempt whose match-step is less than or equal to it. Trade-
     * off: a real user who quick-fingers two distinct flows (e.g.
     * disable + re-enable) within the same 30-second tick has the
     * second one rejected. They wait for the next code, which is
     * the same UX as already-typed codes everywhere TOTP is in use
     * (Google, GitHub, etc., enforce the same one-shot-per-step
     * rule).
     *
     * The high-water mark is stored in a transient with a TTL of
     * (2 * window + 1) * 30 + a small grace, which is the longest
     * any single match-step could possibly still fall inside the
     * acceptance window. After that, the row could be safely
     * forgotten — but the transient TTL is what does the cleanup
     * for us, no GC needed. user_id of 0 (callers that don't have
     * a stable identity, e.g. legacy enrol-time self-test paths)
     * skip the check; in practice every production caller has a
     * user id.
     */
    private function verify_totp($secret, $code, $window = 1, $user_id = 0) {
        $timestamp = (int) floor(time() / 30);
        $padded    = str_pad((string) $code, 6, '0', STR_PAD_LEFT);

        $last_step = ($user_id > 0) ? $this->get_last_totp_step($user_id) : null;

        for ($i = -$window; $i <= $window; $i++) {
            $step       = $timestamp + $i;
            $calculated = $this->calculate_totp($secret, $step);
            if (!hash_equals($calculated, $padded)) {
                continue;
            }
            // Constant-time match found at this step.
            if ($last_step !== null && $step <= $last_step) {
                // Replay: this step (or a later one within the same
                // verification call's window) was already accepted.
                // Don't return false yet — keep walking the window
                // so a fresh higher step in the same call can still
                // win if the legitimate user happens to be on the
                // exact boundary of a tick. We do NOT short-circuit
                // here because (a) all branches do hash_equals work
                // already and (b) refusing-on-first-replay-match
                // would leak via timing whether the replayed step
                // was the negative or positive offset.
                continue;
            }
            // First fresh match in this call wins.
            if ($user_id > 0) {
                $this->set_last_totp_step($user_id, $step, $window);
            }
            return true;
        }

        return false;
    }

    /**
     * Per-user high-water mark for accepted TOTP steps. Returns
     * null if nothing has been recorded yet (or the previous mark
     * has expired and the row dropped). int otherwise.
     */
    private function get_last_totp_step($user_id) {
        $val = get_transient($this->totp_step_transient_key($user_id));
        if ($val === false || $val === null || $val === '') {
            return null;
        }
        return (int) $val;
    }

    /**
     * Persist the high-water mark with a TTL just longer than the
     * full acceptance window plus a small grace so a code that
     * matched at the +window edge cannot be replayed once the
     * mark expires. Window is the same shape verify_totp uses
     * (number of 30-second steps either side of "now").
     */
    private function set_last_totp_step($user_id, $step, $window) {
        $ttl = ((2 * (int) $window) + 1) * 30 + 30; // +30s grace
        set_transient($this->totp_step_transient_key($user_id), (int) $step, $ttl);
    }

    private function totp_step_transient_key($user_id) {
        return 'matrix_2fa_last_step_' . (int) $user_id;
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
