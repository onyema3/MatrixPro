<?php
/**
 * Matrix MLM — small per-(user, IP) rate-limit helper.
 *
 * Used by the auth surface (login + registration) to bound brute-force
 * and enumeration. Designed to be reusable for any future endpoint
 * that needs the same shape of throttle (the EPIN redeem path has its
 * own private copy from PR #231 — a future refactor can collapse them
 * onto this helper without changing observable behaviour).
 *
 * Storage:
 *   WordPress transients (DB-backed by default; object-cache backed
 *   when an object cache is registered, which makes the throttle
 *   read/write traffic effectively free on Redis-aware hosts). Per-
 *   user and per-IP counters live under separate keys so a high-
 *   traffic gateway IP can't lock out a single user, and a single
 *   user can't dodge the per-IP ceiling by rotating usernames.
 *
 * What we count:
 *   FAILED attempts only. A successful login should not count toward
 *   the lockout, otherwise a legitimate user who batches a few
 *   actions back-to-back would hit the ceiling. The caller calls
 *   record_failure() on every failed branch and clear() on success.
 *
 * Lockout window:
 *   60 seconds default, filterable per-namespace by the caller. We
 *   don't implement progressive lockouts (5min, 30min, 1hr) here —
 *   that's a more invasive change that would need its own UI for
 *   admin overrides; rolling 60-second windows are sufficient for
 *   the audit's concern (online brute force) and don't punish
 *   legitimate users.
 *
 * Client IP:
 *   REMOTE_ADDR by default — the only header an attacker can't forge
 *   through the WordPress request itself. Filterable via
 *   matrix_mlm_client_ip so ops behind a reverse proxy that strips
 *   and re-injects X-Forwarded-For on trusted hops can opt in.
 *   Trusting XFF on a default install lets an attacker rotate the
 *   counter by spoofing the header, so it's intentionally NOT the
 *   default.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Rate_Limit {

    /**
     * Resolve the current request's client IP.
     */
    public static function get_client_ip() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        return (string) apply_filters('matrix_mlm_client_ip', $ip);
    }

    /**
     * Decide whether the call should proceed.
     *
     * Returns true to proceed, or a WP_Error('rate_limited', ...) the
     * caller wraps in its own response shape. Identical message for
     * every refused branch so the client can't infer which axis
     * tripped (user vs IP vs both).
     *
     * @param string     $namespace    e.g. 'login', 'register'
     * @param string|int $user_key     Per-user/account scope. Usually
     *                                 a lowercased username/email.
     *                                 Pass '' to skip the per-user
     *                                 axis (useful for unauthenticated
     *                                 endpoints like registration).
     * @param int        $max_per_user
     * @param int        $max_per_ip
     * @param int        $window       Seconds. Defaults to 60.
     * @return true|WP_Error
     */
    public static function check($namespace, $user_key, $max_per_user, $max_per_ip, $window = 60) {
        $window = $window > 0 ? (int) $window : 60;
        $ip = self::get_client_ip();

        $i_count = (int) get_transient(self::ip_key($namespace, $ip));
        if ($i_count >= $max_per_ip) {
            return new WP_Error(
                'rate_limited',
                __('Too many attempts. Please wait a minute and try again.', 'matrix-mlm')
            );
        }

        if ($user_key !== '') {
            $u_count = (int) get_transient(self::user_key($namespace, $user_key));
            if ($u_count >= $max_per_user) {
                return new WP_Error(
                    'rate_limited',
                    __('Too many attempts. Please wait a minute and try again.', 'matrix-mlm')
                );
            }
        }

        // Silence unused-arg static analysers.
        unset($window);
        return true;
    }

    /**
     * Record a single failure under the (user, IP) tuple. Called from
     * every refusal branch in the caller.
     */
    public static function record_failure($namespace, $user_key, $window = 60) {
        $window = $window > 0 ? (int) $window : 60;
        $ip = self::get_client_ip();

        $i_key = self::ip_key($namespace, $ip);
        $i_count = (int) get_transient($i_key);
        set_transient($i_key, $i_count + 1, $window);

        if ($user_key !== '') {
            $u_key = self::user_key($namespace, $user_key);
            $u_count = (int) get_transient($u_key);
            set_transient($u_key, $u_count + 1, $window);
        }
    }

    /**
     * Clear both counters for this (user, IP) tuple. Called by the
     * caller on a verified success so a legitimate user who fat-
     * fingered their password a few times before getting it right
     * isn't locked out for the rest of the window. The IP-axis clear
     * is mildly exploitable on shared NATs (one user's success resets
     * the counter for a different attacker on the same IP) — accepted
     * trade-off; the attacker still has to find a real credential
     * pair, and online brute force isn't a viable attack against
     * 80-bit-equivalent credentials over a single shared NAT.
     */
    public static function clear($namespace, $user_key) {
        $ip = self::get_client_ip();
        delete_transient(self::ip_key($namespace, $ip));
        if ($user_key !== '') {
            delete_transient(self::user_key($namespace, $user_key));
        }
    }

    private static function user_key($namespace, $user_key) {
        return 'matrix_rl_' . $namespace . '_u_' . md5((string) $user_key);
    }

    private static function ip_key($namespace, $ip) {
        return 'matrix_rl_' . $namespace . '_ip_' . md5((string) $ip);
    }
}
