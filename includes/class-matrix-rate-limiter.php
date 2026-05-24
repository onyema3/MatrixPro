<?php
/**
 * Generic per-action rate limiter for AJAX handlers (audit M4).
 *
 * Backed by transients (option_name + autoload row), so it inherits
 * whatever object cache is in front of WordPress on a given install
 * (Redis/Memcached when present, the options table otherwise). No new
 * schema, no new dependencies.
 *
 * Used by financial / KYC / PAN endpoints to bound brute-force surfaces:
 *
 *   - BVN bruteforce against ajax_create_virtual_wallet
 *   - PAN bruteforce against ajax_setup_card / ajax_link_card
 *   - account-number enumeration against ajax_resolve_account
 *   - meter-number enumeration against ajax_verify_meter
 *   - free-spamming of bill purchases (airtime/data/cable/electricity)
 *     and bank payouts
 *
 * The helper deliberately mirrors the existing per-username + per-IP
 * pattern in Matrix_MLM_Core::is_login_rate_limited / record_failed_login_attempt
 * (audit H12) and the EPIN redeem counter in Matrix_MLM_Epin::redeem
 * (audit M4 already-rate-limited path) so an operator triaging a
 * throttled-user complaint sees the same idiom no matter which handler
 * they end up in. Caps and windows are filterable so installs on
 * shared NAT (university, mobile carrier) or with bursty legitimate
 * traffic patterns can tune without forking the plugin.
 *
 * Threat model and limits:
 *
 *   - The check + bump is two transient ops (get + set), so two
 *     concurrent requests right at the cap can both pass. Same race
 *     as the existing login limiter; acceptable because real-world
 *     brute-force loops are serial and the cap is the order of
 *     magnitude that matters, not the exact off-by-one.
 *   - Counter is keyed by (action, caller). For authenticated
 *     handlers, caller is the user id; for anonymous, caller is the
 *     SHA1 of REMOTE_ADDR (filterable to support trusted reverse
 *     proxies via matrix_mlm_client_ip — same hook the login limiter
 *     uses). A determined attacker behind a botnet can still rotate
 *     IPs, but that raises the cost of attack from "free" to
 *     "operational".
 *   - The window is rolling: every increment refreshes the TTL. A
 *     brute-force loop that pauses just under the cap can keep the
 *     window open indefinitely; the cap is what bounds the rate,
 *     not the window. Windows are sized for a "few minutes of cool-
 *     down" UX, not for cryptographic correctness.
 *
 * @since 1.0.10
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Rate_Limiter {

    /**
     * Default cap (attempts per window) when caller doesn't override.
     *
     * Chosen high enough to never bother a real user clicking through
     * a form a few times; chosen low enough that a brute-force loop
     * is reduced to one attempt every 30 seconds at scale.
     */
    const DEFAULT_MAX = 30;

    /**
     * Default window length in seconds.
     *
     * 15 minutes matches the existing login + EPIN limiters so the
     * "wait a few minutes and try again" UX message is honest.
     */
    const DEFAULT_WINDOW = 900; // 15 * MINUTE_IN_SECONDS

    /**
     * Atomic check + record. Returns true if the request should be
     * rejected (the counter is already at or above the cap).
     *
     * On a non-rejected call this method bumps the counter by 1 and
     * extends the window. The caller is responsible for emitting the
     * user-visible response — typically wp_send_json_error with the
     * generic "too many attempts" string. Reset is optional: call
     * reset() on a verified-success path if you don't want a few
     * legitimate retries to permanently consume budget.
     *
     * @param string $action  Stable identifier for the AJAX handler
     *                        (e.g. 'fintava_create_virtual_wallet').
     *                        Used both as the transient namespace and
     *                        as the second arg to the filter hooks
     *                        so operators can tune per-handler.
     * @param string $key     Caller identifier — typically 'u<userid>'
     *                        for authenticated handlers, 'ip:<sha1>'
     *                        for anonymous. Use key_for_request() to
     *                        get the conventional value.
     * @param array  $opts    Optional overrides:
     *                          - 'max_attempts'  (int)  default DEFAULT_MAX
     *                          - 'window_seconds' (int) default DEFAULT_WINDOW
     *
     * @return bool true => request must be rejected
     *              false => request may proceed (counter incremented)
     */
    public static function throttle($action, $key, array $opts = []) {
        $action = (string) $action;
        $key    = (string) $key;
        if ($action === '' || $key === '') {
            // Defensive: a missing key would collapse all callers
            // into a single counter, which is worse than no limit.
            // Refuse to gate rather than silently break behavior.
            return false;
        }

        $max = isset($opts['max_attempts']) ? (int) $opts['max_attempts'] : self::DEFAULT_MAX;
        $win = isset($opts['window_seconds']) ? (int) $opts['window_seconds'] : self::DEFAULT_WINDOW;

        /**
         * Filter the cap. Per-action override:
         *
         *   add_filter('matrix_mlm_rate_limit_max', function($max, $action, $key) {
         *       return $action === 'fintava_check_status' ? 240 : $max;
         *   }, 10, 3);
         */
        $max = (int) apply_filters('matrix_mlm_rate_limit_max', $max, $action, $key);

        /**
         * Filter the window length. Same shape as the cap filter.
         */
        $win = (int) apply_filters('matrix_mlm_rate_limit_window', $win, $action, $key);

        // Sanity floors so a misconfiguration (e.g. window=0 from a
        // typo'd filter) doesn't disable the limiter or auto-reset
        // every second.
        if ($max < 1) {
            $max = 1;
        }
        if ($win < MINUTE_IN_SECONDS) {
            $win = MINUTE_IN_SECONDS;
        }

        $tkey  = self::transient_key($action, $key);
        $count = (int) get_transient($tkey);

        if ($count >= $max) {
            error_log(sprintf(
                '[Matrix RateLimit] throttled action=%s key=%s count=%d/%d',
                $action,
                $key,
                $count,
                $max
            ));
            return true;
        }

        // Bump. Rolling window: every legitimate increment extends
        // the TTL by $win, so a user who slowly approaches the cap
        // doesn't get a free reset partway through.
        set_transient($tkey, $count + 1, $win);
        return false;
    }

    /**
     * Clear the counter for (action, key). Call on a fully-successful
     * path so a legitimate user who fat-fingered a couple of times
     * isn't permanently penalised for the rest of the window.
     *
     * Optional. Skipping reset on every-call-counts surfaces (KYC,
     * PAN, BVN) is intentional — even a "successful" lookup there is
     * still part of the enumeration surface we're trying to bound.
     */
    public static function reset($action, $key) {
        $action = (string) $action;
        $key    = (string) $key;
        if ($action === '' || $key === '') {
            return;
        }
        delete_transient(self::transient_key($action, $key));
    }

    /**
     * Standard caller identifier for an AJAX handler.
     *
     * Authenticated calls collapse to the user id, so a single user
     * cannot circumvent their own cap by switching IPs (mobile data
     * to wifi). Anonymous calls fall through to a hashed REMOTE_ADDR.
     *
     * The 'ip:' prefix on anonymous keys is a tiny privacy nudge so a
     * `wp_options` table dump doesn't surface raw IPs even if an
     * operator forgets to flush transients before exporting.
     */
    public static function key_for_request() {
        $uid = get_current_user_id();
        if ($uid > 0) {
            return 'u' . (int) $uid;
        }
        return 'ip:' . sha1(self::client_ip());
    }

    /**
     * Resolve the client IP. REMOTE_ADDR is the only header trusted
     * by default — the same hook the login limiter uses
     * ('matrix_mlm_client_ip') is exposed so an install behind a
     * verified reverse proxy can substitute the real client IP from
     * a trusted forwarded header.
     */
    public static function client_ip() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        return (string) apply_filters('matrix_mlm_client_ip', $ip);
    }

    /**
     * Build the transient name. SHA1 the user-facing parts so
     * (a) we never blow the option_name length budget and
     * (b) the wp_options table doesn't surface raw IPs / user ids in
     *     plain text under unrelated triage.
     */
    private static function transient_key($action, $key) {
        return 'matrix_rl_' . sha1($action . '|' . $key);
    }
}
