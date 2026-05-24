<?php
/**
 * Per-(user, action) rate limit helper for AJAX handlers.
 *
 * The plugin already had ad-hoc per-user transient counters in two
 * places (login throttle, e-pin redeem throttle) but most AJAX
 * handlers under wp_ajax_matrix_fintava_* and wp_ajax_matrix_mlm_*
 * had no rate limit at all. That left several handlers exposed to
 * brute-force / scraping / farming abuse from any authenticated
 * caller:
 *
 *   - PAN brute-force via the card link/setup/activate handlers
 *     (Fintava's response to /cards/link distinguishes valid vs
 *     invalid card numbers).
 *   - BVN brute-force via the virtual wallet creation handler.
 *   - Free-airtime / free-data farming via the billing handlers.
 *   - Scaled bank-account name lookup via resolve_account.
 *   - Layered abuse against initiate_transfer.
 *
 * This helper is intentionally simple:
 *   - Per-user keying. Per-IP keying lets an attacker on a shared
 *     NAT lock out legitimate users from the same NAT; per-user
 *     puts the budget exactly where it belongs. Account creation
 *     itself is throttled separately (the registration rate limit)
 *     so an attacker cannot simply multiply their per-user budget
 *     by spinning up fresh accounts.
 *   - Transient-backed. Object cache flushes drop the counter,
 *     which is acceptable for a budget measured in minutes/hours;
 *     the alternative (a dedicated table) is overkill for the
 *     current threat model and would itself be a write-amplifier
 *     under load. If the install moves to durable rate limits the
 *     swap-in point is clearly bounded inside this class.
 *   - One canonical enforcement entry point that short-circuits
 *     the request with an HTTP 429 when over budget, so call
 *     sites are one line of code at the top of the handler.
 *   - Filterable budgets per action so operators can tune for
 *     their own traffic shape without touching code.
 *
 * @package MatrixMLM
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Rate_Limiter {

    /**
     * Common transient prefix. Keeps the option-table view tidy
     * and makes it possible to scrub all rate-limit state with a
     * single LIKE if the operator ever needs to.
     */
    const KEY_PREFIX = 'matrix_rl_';

    /**
     * Enforce a per-(user, action) rate limit.
     *
     * Reads the current attempt count from a transient, compares
     * against $max, and either:
     *
     *   - Increments the counter and returns true (request may
     *     proceed), or
     *   - Sends an HTTP 429 wp_send_json_error() and exits the
     *     request without ever returning.
     *
     * Counters are scoped per-user. Anonymous callers (user_id 0
     * or negative) fall back to the client IP, hashed, so an
     * unauthenticated AJAX endpoint can still be throttled
     * coherently — but no AJAX endpoint covered by this PR runs
     * unauthenticated, so the IP path is defence-in-depth only.
     *
     * Budgets are filterable as
     *
     *   matrix_mlm_rate_limit_max_attempts_<action>      (int)
     *   matrix_mlm_rate_limit_window_seconds_<action>    (int)
     *
     * so an operator who sees their traffic shape diverge from
     * the defaults can tune without code changes.
     *
     * @param int|string $user_id_or_ip User ID (int) when known,
     *                                  or any string for anonymous
     *                                  callers — typically the
     *                                  client IP. 0 / negative ints
     *                                  are normalised to 'anon'.
     * @param string     $action        Short, stable action name
     *                                  (e.g. 'fintava_setup_card').
     *                                  Used in the transient key
     *                                  and in the filter names; do
     *                                  not change once shipped or
     *                                  in-flight counters reset.
     * @param int        $max           Default max attempts within
     *                                  the window.
     * @param int        $window_seconds Default rolling window.
     * @return bool True when the request is under budget. Never
     *              returns when over budget — the function exits
     *              via wp_send_json_error().
     */
    public static function enforce($user_id_or_ip, $action, $max, $window_seconds) {
        $action = (string) $action;
        $max    = (int) apply_filters(
            'matrix_mlm_rate_limit_max_attempts_' . $action,
            (int) $max
        );
        $window = (int) apply_filters(
            'matrix_mlm_rate_limit_window_seconds_' . $action,
            (int) $window_seconds
        );

        // Defensive floor — a misconfigured filter that returns 0
        // would otherwise either disable the limit (max=0 means
        // "always over") or extinguish the window (window=0 means
        // "never persists"). Either is worse than the default.
        if ($max <= 0) {
            $max = 1;
        }
        if ($window <= 0) {
            $window = 60;
        }

        $key = self::build_key($user_id_or_ip, $action);

        $attempts = (int) get_transient($key);
        if ($attempts >= $max) {
            error_log(sprintf(
                '[Matrix MLM RL] throttled: action=%s key=%s attempts=%d/%d',
                $action,
                $key,
                $attempts,
                $max
            ));

            // status_header(429) + wp_send_json_error to keep
            // parity with WP's expected JSON error envelope. The
            // 'rate_limited' code lets clients distinguish from
            // generic auth or validation errors.
            wp_send_json_error(
                [
                    'code'        => 'rate_limited',
                    'message'     => __(
                        'Too many requests. Please wait a few minutes before trying again.',
                        'matrix-mlm'
                    ),
                    'retry_after' => $window,
                ],
                429
            );
            // wp_send_json_error calls wp_die(); the return below is
            // unreachable but keeps static analysers happy.
            return false;
        }

        set_transient($key, $attempts + 1, $window);
        return true;
    }

    /**
     * Reset the counter for a user/action pair.
     *
     * Call this after a verified successful action (e.g. a card
     * was actually linked, a transfer actually settled) so a
     * legitimate user who fat-fingers a few times before getting
     * it right is not penalised on subsequent legitimate use.
     *
     * No-op if the counter does not exist.
     *
     * @param int|string $user_id_or_ip Same shape as enforce().
     * @param string     $action        Same shape as enforce().
     * @return void
     */
    public static function clear($user_id_or_ip, $action) {
        delete_transient(self::build_key($user_id_or_ip, (string) $action));
    }

    /**
     * Build the transient key for a user/action pair.
     *
     * Key shape: 'matrix_rl_<action>_u<user_id>' for authenticated
     * callers, 'matrix_rl_<action>_ip<ip-hash>' for anonymous.
     * The IP hash uses a truncated SHA-256 so the option table
     * never stores raw IPs — a small privacy hardening that costs
     * nothing.
     *
     * Final length is constrained by WordPress' 172-char
     * transient name limit (option_name minus the _transient_
     * prefix); the action name plus a 16-char hex suffix stays
     * comfortably under that.
     *
     * @return string
     */
    private static function build_key($user_id_or_ip, $action) {
        if (is_int($user_id_or_ip) && $user_id_or_ip > 0) {
            $suffix = 'u' . $user_id_or_ip;
        } elseif (is_string($user_id_or_ip) && $user_id_or_ip !== '') {
            $suffix = 'ip' . substr(hash('sha256', $user_id_or_ip), 0, 16);
        } else {
            $suffix = 'anon';
        }
        return self::KEY_PREFIX . $action . '_' . $suffix;
    }
}
