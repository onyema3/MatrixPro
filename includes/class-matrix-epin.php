<?php
/**
 * E-Pin Management
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Epin {

    /**
     * Cryptographically secure E-Pin generator (H14).
     *
     * Generates 80 bits of entropy from random_int, encoded into 16
     * characters of a 32-symbol Crockford-derived alphabet (no I, L,
     * O, or U) so operators handing out physical pins can't confuse
     * 1/I, 0/O, or 5/S/U on a printed page. Format is
     * 'EP-XXXXXXXXXXXXXXXX' (19 chars total), which fits the existing
     * varchar(50) pin_code column with headroom and is unambiguously
     * distinct from the legacy 15-char 'EP-XXXXXXXXXXXX' format.
     *
     * Why not the legacy 'EP-' . substr(md5(uniqid(mt_rand())), 0, 12):
     *   - mt_rand is a Mersenne Twister whose internal state is
     *     recoverable from ~624 consecutive outputs. An attacker
     *     who has bought a few pins can in principle predict the
     *     next ones if the operator generates them in a tight loop.
     *   - md5 is a fast hash. Truncated to 12 hex chars (48 bits),
     *     the keyspace is small enough that a network-rate-limited
     *     brute force was the bottleneck, not the entropy itself.
     *   - random_int is the cryptographic primitive PHP exposes for
     *     this; it pulls from /dev/urandom (or the OS equivalent on
     *     Windows) which has no recoverable state.
     *
     * Old pins remain resolvable: redeem() looks up by exact match
     * and the column is varchar so legacy short-form values still
     * match without any migration.
     */
    private static function generate_pin_code() {
        // Crockford-derived alphabet: digits 0-9 plus A-Z minus I, L,
        // O, U. 32 symbols = 5 bits per char, so 16 chars = 80 bits
        // of true entropy. Collisions are negligible at scale: P(any
        // collision) on 1M pins is ~5e-13.
        static $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

        $body = '';
        for ($i = 0; $i < 16; $i++) {
            $body .= $alphabet[random_int(0, 31)];
        }
        return 'EP-' . $body;
    }

    /**
     * Normalise a user-typed pin code so common formatting variants all
     * resolve to the canonical 'EP-XXXX...' form stored in the DB.
     *
     * Handles:
     *   - leading/trailing whitespace and internal spaces
     *   - lowercase input
     *   - hyphen-chunked variants (EP-XXXX-XXXX-XXXX-XXXX)
     *   - missing dash separator after EP (EPXXXX...)
     *
     * Operators commonly distribute pins as 'EP-XXXX-XXXX-XXXX-XXXX'
     * for legibility; without normalisation, copying that into the
     * redeem field produces a no-match against the canonical
     * 'EP-XXXXXXXXXXXXXXXX' stored form.
     */
    private static function normalise_pin_input($input) {
        $code = strtoupper((string) $input);
        $code = preg_replace('/\s+/', '', $code);
        if (preg_match('/^EP-?(.+)$/', $code, $m)) {
            $tail = str_replace('-', '', $m[1]);
            return 'EP-' . $tail;
        }
        return $code;
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
        $error_ref = '';
        for ($i = 0; $i < $quantity; $i++) {
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
                    // Redact the raw driver error from the response. The
                    // full error text is written to the PHP error log
                    // under the returned token; UI-side messages only
                    // quote the token. See Matrix_MLM_DB_Error.
                    $error_ref = Matrix_MLM_DB_Error::log_and_token(
                        'epin.generate.insert',
                        $wpdb->last_error
                    );
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
                    /* translators: %s: opaque support reference for the failed DB write */
                    __('Failed to save E-Pins to database. %s', 'matrix-mlm'),
                    $error_ref
                        ? sprintf(__('(Reference: %s)', 'matrix-mlm'), $error_ref)
                        : ''
                ),
            ];
        }

        return [
            'success'   => true,
            'pins'      => $pins,
            'count'     => count($pins),
            'amount'    => $amount,
            'failed'    => $failed,
            'error_ref' => $failed ? $error_ref : '',
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
     * We claim the pin atomically with a single conditional UPDATE
     * and only credit the wallet if exactly one row was claimed.
     *
     * H14 hardening:
     *   - Per-user rate limit (transient-keyed, 10 attempts per 15 min
     *     by default, both filterable). Cleared on successful redeem so
     *     a legitimate user who mistypes a few times before getting it
     *     right is not punished. Per-user rather than per-IP because
     *     IP keying lets an attacker on a shared NAT lock out
     *     legitimate users; the registration rate limit (H13, separate
     *     PR) bounds an attacker's ability to multiply their per-user
     *     budget by spinning up fresh accounts.
     *   - Uniform 'invalid or unredeemable' message for {not found,
     *     already used, expired, race-lost claim}. Previously distinct
     *     phrasings ('Pin has expired', 'Pin was already redeemed')
     *     leaked an oracle: the attacker learned the pin code was
     *     valid even though they could not redeem it, which is enough
     *     to pivot to a targeted lookup against operator records or
     *     a targeted brute-force in the recently-issued window.
     *     Operator-only diagnostics are preserved via error_log so
     *     legitimate support cases can still be reasoned about.
     *   - Input normalisation handles formatted/uppercased/whitespace
     *     variants of the same code (see normalise_pin_input).
     */
    public function redeem($user_id, $pin_code) {
        global $wpdb;

        // Rate limit gate. Sit in front of the SELECT so the DB is
        // not reachable from the brute-force loop.
        $attempt_key   = 'matrix_epin_redeem_attempts_' . (int) $user_id;
        $attempts      = (int) get_transient($attempt_key);
        $max_attempts  = (int) apply_filters('matrix_mlm_epin_redeem_max_attempts', 10);
        $window_secs   = (int) apply_filters('matrix_mlm_epin_redeem_window_seconds', 15 * MINUTE_IN_SECONDS);

        if ($attempts >= $max_attempts) {
            error_log(sprintf(
                '[Matrix MLM] epin redeem throttled: user_id=%d, attempts=%d/%d',
                $user_id, $attempts, $max_attempts
            ));
            return [
                'success' => false,
                'message' => __('Too many redemption attempts. Please wait a few minutes and try again.', 'matrix-mlm'),
            ];
        }

        $pin_code_canonical = self::normalise_pin_input($pin_code);

        $pin = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_epins WHERE pin_code = %s",
            $pin_code_canonical
        ));

        // Determine whether this attempt is going to succeed before
        // emitting any user-visible signal. All four 'not redeemable'
        // outcomes get the same generic message so the response is
        // not an oracle.
        $not_redeemable_reason = null;
        if (!$pin) {
            $not_redeemable_reason = 'not_found';
        } elseif ($pin->status !== 'unused') {
            $not_redeemable_reason = 'already_used';
        } elseif ($pin->expires_at && strtotime($pin->expires_at) < time()) {
            // Best-effort lazy state update so the row reflects reality.
            // Status flip happens regardless of what we tell the user.
            $wpdb->update(
                $wpdb->prefix . 'matrix_epins',
                ['status' => 'expired'],
                ['id' => $pin->id]
            );
            $not_redeemable_reason = 'expired';
        }

        if ($not_redeemable_reason !== null) {
            $new_attempts = $attempts + 1;
            set_transient($attempt_key, $new_attempts, $window_secs);

            error_log(sprintf(
                '[Matrix MLM] epin redeem rejected: user_id=%d, reason=%s, attempts=%d/%d',
                $user_id, $not_redeemable_reason, $new_attempts, $max_attempts
            ));

            return [
                'success' => false,
                'message' => __('Invalid or unredeemable pin. Please check the code and try again, or contact support.', 'matrix-mlm'),
            ];
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
            // Real DB-layer failure (connection, schema). Surface it so
            // an operator can diagnose; do not consume an attempt slot
            // because this is not the user's fault.
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
            // Race lost (another request claimed it between our SELECT
            // and UPDATE) — same threat model as 'already used', so the
            // generic message and counter increment apply here too.
            $new_attempts = $attempts + 1;
            set_transient($attempt_key, $new_attempts, $window_secs);

            error_log(sprintf(
                '[Matrix MLM] epin redeem rejected: user_id=%d, reason=race_lost, attempts=%d/%d, pin_id=%d',
                $user_id, $new_attempts, $max_attempts, $pin->id
            ));

            return [
                'success' => false,
                'message' => __('Invalid or unredeemable pin. Please check the code and try again, or contact support.', 'matrix-mlm'),
            ];
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

        // Successful redeem clears the attempt budget so a user who
        // mistyped a couple of times before nailing the right pin is
        // not penalised on the next legitimate redemption.
        delete_transient($attempt_key);

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
