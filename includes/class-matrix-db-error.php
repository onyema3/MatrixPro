<?php
/**
 * Database error redaction helper.
 *
 * Stops raw $wpdb->last_error strings from being echoed back into HTTP
 * responses, admin notices, or any other surface a non-developer might
 * see. The full driver error stays in the PHP error log, paired with a
 * short opaque token; UI-side messages quote only the token.
 *
 * Why bother on a plugin that's already admin-gated for most of these
 * call sites:
 *
 *   - Driver error text leaks schema (table names, column names) and
 *     the MySQL/MariaDB error code shape, which collapses one stage of
 *     the kill chain for any attacker who has reached an authenticated
 *     admin context (via M1, M4, phishing, session hijack).
 *   - One AJAX site (Matrix_MLM_Epin::generate) returns the raw error
 *     in its JSON response, which is reachable from any caller with
 *     manage_matrix_mlm — a much wider surface than full WP admin.
 *
 * The token is short on purpose: it goes into a user-facing string and
 * needs to be quotable in a support ticket. Eight characters of
 * mixed-case alphanumeric give ~48 bits of entropy, which is plenty for
 * "match this report against the right log line" — it is not a secret
 * and does not need to be unguessable.
 *
 * @package MatrixMLM
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_DB_Error {

    /**
     * Log a $wpdb error and return a short token to surface in the UI.
     *
     * Caller is expected to use the returned token in the user-visible
     * message in place of $wpdb->last_error, e.g.:
     *
     *     $ref = Matrix_MLM_DB_Error::log_and_token('hospitals.update', $wpdb->last_error);
     *     self::admin_notice('error', sprintf(
     *         __('Could not update hospital. Reference: %s.', 'matrix-mlm'),
     *         $ref
     *     ));
     *
     * The full $wpdb->last_error is written to the PHP error log under
     * the [Matrix MLM DB] tag, alongside the token and the supplied
     * context label, so an operator triaging from a support ticket can
     * grep the log for the token and recover the real error.
     *
     * @param string $context Short identifier of the call site (e.g.
     *                        "hospitals.update", "epin.generate"). Used
     *                        only in the error log; never returned to
     *                        the caller. Keep it stable so log-grepping
     *                        works.
     * @param string $wpdb_error Raw $wpdb->last_error value. Empty
     *                           string is acceptable and will still
     *                           produce a log line and a token —
     *                           callers should not branch on it.
     * @return string 8-character alphanumeric token, safe to embed in a
     *                user-facing string.
     */
    public static function log_and_token($context, $wpdb_error) {
        // wp_generate_password is used here as a portable random-string
        // generator, not as a secret generator. Lowercase / uppercase /
        // digits only — no special characters that might break message
        // formatting downstream.
        $token = wp_generate_password(8, false, false);

        error_log(sprintf(
            '[Matrix MLM DB] %s ref=%s error=%s',
            (string) $context,
            $token,
            (string) $wpdb_error
        ));

        return $token;
    }
}
