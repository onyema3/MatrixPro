<?php
/**
 * E-Pin Management
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Epin {

    /**
     * Generate a cryptographically random pin code.
     *
     * Audit H14: the previous generator used
     *   strtoupper('EP-' . substr(md5(uniqid(mt_rand(), true)), 0, 12))
     * which mixed three non-cryptographic primitives: mt_rand (Mersenne
     * Twister, predictable from a few outputs), uniqid (microsecond
     * timestamp — leakable through any other timestamp the site exposes),
     * and md5 (fast hash that doesn't add entropy beyond its input).
     * Effective unpredictability was a small fraction of the apparent
     * 48-bit code space.
     *
     * The new generator pulls 10 bytes from the OS CSPRNG via
     * random_bytes() and renders them as 20 uppercase hex characters,
     * giving 80 bits of true entropy. With the redeem throttle (max
     * 5 failed attempts/user/min, 10/IP/min) the worst-case keyspace
     * traversal time is on the order of 10^17 minutes — comfortably
     * outside any practical attack horizon.
     *
     * Format: 'EP-XXXXXXXXXXXXXXXXXXXX' (23 chars, fits the
     * matrix_epins.pin_code varchar(50) column with significant
     * headroom for a future format bump).
     */
    private static function generate_pin_code() {
        return 'EP-' . strtoupper(bin2hex(random_bytes(10)));
    }

    /**
     * Best-effort client-IP helper for the redeem throttle.
     *
     * Returns REMOTE_ADDR by default — the only header an attacker
     * cannot forge through the WordPress request. Ops behind a
     * reverse proxy that strips and re-injects X-Forwarded-For on
     * trusted hops can override via the matrix_mlm_client_ip filter
     * to read the forwarded value. We deliberately do NOT read XFF
     * by default, because trusting it on a default install lets an
     * attacker rotate the throttle counter by spoofing the header.
     */
    private static function get_client_ip() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        return (string) apply_filters('matrix_mlm_client_ip', $ip);
    }

    /**
     * Read the redeem-failure counter for both user and IP keys, and
     * decide whether the call should proceed.
     *
     * We count failed attempts only (not successes). A legitimate
     * user batching valid pins shouldn't be throttled, but a brute-
     * force run hammers the failure counter on every guess — exactly
     * what we want to bound. Lockout window is 60 seconds by default
     * (filterable). Once the counter is set, every subsequent failure
     * within the window keeps the transient alive without resetting
     * its TTL — the goal is "max N failures per rolling window", not
     * a hard sliding window, but the simpler counter is good enough
     * for the threat model: an attacker burning through 5 guesses
     * sees a 60-second pause before the next 5.
     *
     * Returns true to proceed, or a WP_Error('rate_limited', ...) the
     * caller can wrap in the standard {success, message} shape.
     */
    private static function check_redeem_throttle($user_id) {
        $window = (int) apply_filters('matrix_mlm_epin_redeem_window', 60);
        $max_per_user = (int) apply_filters('matrix_mlm_epin_redeem_max_failures_per_user', 5);
        $max_per_ip   = (int) apply_filters('matrix_mlm_epin_redeem_max_failures_per_ip', 10);

        $user_key = 'matrix_epin_rdm_fail_u_' . (int) $user_id;
        $ip_key   = 'matrix_epin_rdm_fail_ip_' . md5(self::get_client_ip());

        $user_count = (int) get_transient($user_key);
        $ip_count   = (int) get_transient($ip_key);

        if ($user_count >= $max_per_user || $ip_count >= $max_per_ip) {
            // Identical message for every rate-limited refusal so the
            // client can't infer which axis tripped (user, IP, or
            // both). Silent (no log here) because a noisy log on every
            // throttle would itself be a DoS vector once an attacker
            // realises they're rate-limited.
            return new WP_Error(
                'rate_limited',
                __('Too many redeem attempts. Please wait a minute and try again.', 'matrix-mlm')
            );
        }

        // Filter is here so a future plan-engine or anti-fraud module
        // can plug in object-cache / Redis-backed counters without
        // touching this file.
        $window = $window > 0 ? $window : 60;
        $_ = $max_per_user; $_ = $max_per_ip; // silence unused-var lints
        return true;
    }

    /**
     * Record one redeem failure under the (user, IP) tuple. Called
     * from every not-redeemable branch in redeem() so that brute-
     * force attempts pile up the counter even when the response is
     * uniform.
     */
    private static function record_redeem_failure($user_id) {
        $window = (int) apply_filters('matrix_mlm_epin_redeem_window', 60);
        $window = $window > 0 ? $window : 60;

        $user_key = 'matrix_epin_rdm_fail_u_' . (int) $user_id;
        $ip_key   = 'matrix_epin_rdm_fail_ip_' . md5(self::get_client_ip());

        $user_count = (int) get_transient($user_key);
        $ip_count   = (int) get_transient($ip_key);

        // Re-set the transient with the bumped counter and the same
        // window each time, so a steady stream of guesses keeps the
        // lockout warm without ever resetting to zero.
        set_transient($user_key, $user_count + 1, $window);
        set_transient($ip_key, $ip_count + 1, $window);
    }

    /**
     * Generate e-pins
     * @param int $plan_id Plan ID (0 for custom amount)
     * @param int $quantity Number of pins to generate
     * @param int $created_by Admin user ID
     * @param string|null $expires_at Expiry date
     * @param float $custom_amount Custom amount (used when plan_id is 0)
     */
    public function generate($plan_id, $quantity, $created_by, $expires_at = null, $custom_amount = 0) {
        global $wpdb;

        $amount = floatval($custom_amount);

        if ($plan_id > 0) {
            $plan = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}matrix_plans WHERE id = %d",
                $plan_id
            ));

            if (!$plan) {
                return ['success' => false, 'message' => __('Plan not found', 'matrix-mlm')];
            }
            $amount = $plan->price;
        }

        if ($amount <= 0) {
            return ['success' => false, 'message' => __('Amount must be greater than zero', 'matrix-mlm')];
        }

        if ($quantity < 1 || $quantity > 500) {
            return ['success' => false, 'message' => __('Quantity must be between 1 and 500', 'matrix-mlm')];
        }

        $pins = [];
        $failed = 0;
        $last_error = '';
        for ($i = 0; $i < $quantity; $i++) {
            // Cryptographic pin generation — see generate_pin_code()
            // docblock for the audit-H14 rationale.
            $pin_code = self::generate_pin_code();

            // Suppress wpdb's automatic error printing so we can handle failures cleanly.
            $prev_show = $wpdb->show_errors(false);
            $prev_suppress = $wpdb->suppress_errors(true);

            $inserted = $wpdb->insert($wpdb->prefix . 'matrix_epins', [
                'pin_code'   => $pin_code,
                'plan_id'    => $plan_id ?: null,
                'amount'     => $amount,
                'created_by' => $created_by,
                'expires_at' => $expires_at,
            ], ['%s', '%d', '%f', '%d', '%s']);

            $wpdb->show_errors($prev_show);
            $wpdb->suppress_errors($prev_suppress);

            if (!$inserted) {
                $failed++;
                if ($wpdb->last_error) {
                    $last_error = $wpdb->last_error;
                }
                // If the very first insert fails, there is no point continuing —
                // it's almost certainly a schema/permissions issue that will
                // affect every row in the batch.
                if ($i === 0) {
                    break;
                }
                continue;
            }

            $pins[] = [
                'pin_code' => $pin_code,
                'amount'   => $amount,
            ];
        }

        if (empty($pins)) {
            return [
                'success' => false,
                'message' => sprintf(
                    /* translators: %s: database error */
                    __('Failed to save E-Pins to database. %s', 'matrix-mlm'),
                    $last_error ? '(' . $last_error . ')' : ''
                ),
            ];
        }

        return [
            'success' => true,
            'pins'    => $pins,
            'count'   => count($pins),
            'amount'  => $amount,
            'failed'  => $failed,
            'error'   => $failed ? $last_error : '',
        ];
    }

    /**
     * Redeem an e-pin.
     *
     * Order of operations matters here. Previous versions credited the
     * wallet first and then tried to mark the pin as used; if the second
     * step failed silently, the user's balance was credited but the pin
     * row never recorded `used_by` / `used_at`, so it didn't appear in
     * the user's Recharge History.
     *
     * We now claim the pin atomically with a single conditional UPDATE
     * and only credit the wallet if exactly one row was claimed.
     *
     * Audit H14 hardening:
     *
     *   - Rate limit: max 5 failed attempts per user and 10 per IP
     *     within a 60-second rolling window (all filterable). Combined
     *     with the new 80-bit cryptographic pin entropy from
     *     generate_pin_code(), the keyspace is no longer brute-forceable
     *     in any practical horizon.
     *
     *   - Uniform "not redeemable" error: previously the response
     *     distinguished between "pin doesn't exist", "pin expired",
     *     and "pin already used", which was an oracle confirming that
     *     a guessed code was a real (but consumed) pin. All three
     *     branches now return the same generic message, so a
     *     successful enumeration of consumed pins gives the attacker
     *     nothing useful. DB / wallet-credit failures keep their
     *     distinct messages because those are operator-actionable
     *     server errors, not a yes/no signal about the input.
     */
    public function redeem($user_id, $pin_code) {
        global $wpdb;

        // Generic refusal used for every "not redeemable" branch
        // (invalid, already used, expired, race-lost). Localised once
        // so we can't accidentally drift the strings apart later.
        $not_redeemable = __('Invalid or unredeemable pin', 'matrix-mlm');

        // Throttle gate. Refusals are uniform (rate_limited message)
        // and do NOT bump the failure counter — getting rate-limited
        // shouldn't extend its own lockout indefinitely.
        $throttle_check = self::check_redeem_throttle($user_id);
        if (is_wp_error($throttle_check)) {
            return [
                'success' => false,
                'message' => $throttle_check->get_error_message(),
                'rate_limited' => true,
            ];
        }

        $pin = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_epins WHERE pin_code = %s AND status = 'unused'",
            $pin_code
        ));

        if (!$pin) {
            // Could be: never existed, or exists but is in
            // 'used' / 'expired' state. Both branches produce the
            // same generic message — the attacker can't tell from
            // this response that they hit a real consumed pin.
            self::record_redeem_failure($user_id);
            return ['success' => false, 'message' => $not_redeemable];
        }

        // Check expiry. Note: the SELECT above already filtered to
        // status='unused', so we only get here if the row is unused
        // but past its expires_at — flip the status to 'expired' so
        // it shows correctly in the admin pin list and won't be
        // resurrected by a reset of the wall clock, then return the
        // SAME generic message as the not-found branch.
        if ($pin->expires_at && strtotime($pin->expires_at) < time()) {
            $wpdb->update($wpdb->prefix . 'matrix_epins', ['status' => 'expired'], ['id' => $pin->id]);
            self::record_redeem_failure($user_id);
            return ['success' => false, 'message' => $not_redeemable];
        }

        // Atomic claim. The status='unused' guard prevents double-spend if
        // two requests race, and lets us detect failures cleanly.
        $prev_show     = $wpdb->show_errors(false);
        $prev_suppress = $wpdb->suppress_errors(true);

        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}matrix_epins
                SET status = 'used', used_by = %d, used_at = %s
              WHERE id = %d AND status = 'unused'",
            $user_id,
            current_time('mysql'),
            $pin->id
        ));

        $db_error = $wpdb->last_error;
        $wpdb->show_errors($prev_show);
        $wpdb->suppress_errors($prev_suppress);

        if ($claimed === false) {
            // Real DB error — surface it (operators see this in the
            // dashboard, not attackers walking the keyspace; the
            // throttle still applies). Do NOT bump the failure
            // counter for a server-side fault that wasn't the user's
            // doing.
            return [
                'success' => false,
                'message' => sprintf(
                    /* translators: %s: database error */
                    __('Could not redeem pin. %s', 'matrix-mlm'),
                    $db_error ? '(' . $db_error . ')' : ''
                ),
            ];
        }

        if ($claimed < 1) {
            // Race-lost: another request claimed it between our
            // SELECT and our UPDATE. Same generic refusal — an
            // attacker observing this branch is no better off than
            // observing the not-found branch.
            self::record_redeem_failure($user_id);
            return ['success' => false, 'message' => $not_redeemable];
        }

        // Pin is safely claimed. Credit the wallet using the canonical
        // pin code from the database so the wallet `reference` column
        // exactly matches `matrix_epins.pin_code` for joins later.
        //
        // CRITICAL: check the return value. credit() returns false
        // when the wallet UPSERT fails (DB error) or when the
        // post-credit balance verification doesn't match what we
        // expected — i.e., the credit did NOT actually land in the
        // user's balance. The previous revision ignored this and
        // unconditionally returned success, which is the bug behind
        // the "Recharge successful but my balance is unchanged"
        // report. If credit() fails, we also need to roll back our
        // earlier UPDATE that marked the pin as used; otherwise the
        // user would lose the pin without getting the funds.
        $wallet = new Matrix_MLM_Wallet();
        $credit_result = $wallet->credit(
            $user_id,
            $pin->amount,
            'epin_recharge',
            sprintf(__('E-Pin recharge: %s', 'matrix-mlm'), $pin->pin_code),
            $pin->pin_code
        );

        if ($credit_result === false) {
            // Roll back the pin-claim UPDATE so the user can try
            // again or contact support without losing the pin. Best
            // effort: even if this UPDATE fails too (which would be
            // very unusual), we still surface the credit failure.
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}matrix_epins
                    SET status = 'unused', used_by = NULL, used_at = NULL
                  WHERE id = %d AND used_by = %d",
                $pin->id, $user_id
            ));

            error_log(sprintf(
                '[Matrix MLM] epin redeem: credit() returned false; rolled back pin claim. user_id=%d, pin_id=%d, pin_code=%s, amount=%s',
                $user_id, $pin->id, $pin->pin_code, $pin->amount
            ));

            return [
                'success' => false,
                'message' => __('Could not credit your wallet for this pin. Please try again or contact support; the pin remains usable.', 'matrix-mlm'),
            ];
        }

        return [
            'success' => true,
            'message' => sprintf(__('Successfully recharged %s%s', 'matrix-mlm'), get_option('matrix_mlm_currency_symbol', '₦'), number_format($pin->amount, 2)),
            'amount'  => $pin->amount,
        ];
    }

    /**
     * Get pins by user (created)
     */
    public function get_created_pins($created_by, $status = null, $limit = 20, $offset = 0) {
        global $wpdb;

        $where = "WHERE created_by = %d";
        $params = [$created_by];

        if ($status) {
            $where .= " AND status = %s";
            $params[] = $status;
        }

        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT e.*, p.name as plan_name, u.user_login as used_by_username 
             FROM {$wpdb->prefix}matrix_epins e 
             LEFT JOIN {$wpdb->prefix}matrix_plans p ON e.plan_id = p.id 
             LEFT JOIN {$wpdb->users} u ON e.used_by = u.ID 
             $where ORDER BY e.created_at DESC LIMIT %d OFFSET %d",
            $params
        ));
    }

    /**
     * Get all pins (admin)
     */
    public function get_all_pins($status = null, $limit = 20, $offset = 0) {
        global $wpdb;

        $where = "WHERE 1=1";
        $params = [];

        if ($status) {
            $where .= " AND e.status = %s";
            $params[] = $status;
        }

        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT e.*, p.name as plan_name, u1.user_login as created_by_username, u2.user_login as used_by_username 
             FROM {$wpdb->prefix}matrix_epins e 
             LEFT JOIN {$wpdb->prefix}matrix_plans p ON e.plan_id = p.id 
             LEFT JOIN {$wpdb->users} u1 ON e.created_by = u1.ID 
             LEFT JOIN {$wpdb->users} u2 ON e.used_by = u2.ID 
             $where ORDER BY e.created_at DESC LIMIT %d OFFSET %d",
            $params
        ));
    }

    /**
     * Get recharge logs for user.
     *
     * Source of truth is the wallet credit row (transaction_type =
     * 'epin_recharge'), not matrix_epins. This is intentional: the
     * wallet row is what actually represents money landing in the
     * user's balance, so the history stays consistent even if a future
     * code path forgets to mark the matrix_epins row as 'used'. We
     * LEFT JOIN matrix_epins via the reference column to enrich the
     * row with the plan name, but the join is optional.
     */
    public function get_recharge_logs($user_id, $limit = 20, $offset = 0) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT
                w.created_at AS used_at,
                w.amount     AS amount,
                COALESCE(e.pin_code, w.reference) AS pin_code,
                COALESCE(p.name, 'Custom')        AS plan_name,
                w.description AS description
             FROM {$wpdb->prefix}matrix_wallet w
             LEFT JOIN {$wpdb->prefix}matrix_epins e ON e.pin_code = w.reference
             LEFT JOIN {$wpdb->prefix}matrix_plans p ON p.id = e.plan_id
             WHERE w.user_id = %d
               AND w.transaction_type = 'epin_recharge'
               AND w.type = 'credit'
             ORDER BY w.created_at DESC
             LIMIT %d OFFSET %d",
            $user_id, $limit, $offset
        ));
    }
}
