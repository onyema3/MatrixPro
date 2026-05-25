<?php
/**
 * Transaction PIN — second-factor proof of intent for fund movements.
 *
 * Storage: a single bcrypt hash on matrix_user_meta plus five sibling
 * policy columns added in DB version 1.0.13 (set_at, last_used_at,
 * history, failed_attempts, locked_until). Plaintext PIN is never
 * persisted. Hash format mirrors the recovery-codes pattern in
 * Matrix_MLM_Two_Factor::store_recovery_codes() — bcrypt's default
 * cost is the right brute-force speed bump for a 4–6 digit secret
 * with a 10⁴–10⁶ keyspace.
 *
 * What this class owns:
 *   - PIN lifecycle: set / change / disable / forgot
 *   - Acceptability policy: length, weak-PIN denylist, structural
 *     bans (all-same / sequential), no reuse against personal
 *     attributes (username, phone last-4, email local-part)
 *   - History-based no-reuse against the last HISTORY_LENGTH PINs
 *   - Rolling rate-limit (5/15min, shared with set/change/disable
 *     handlers in Matrix_MLM_Core)
 *   - Hard lockout (HARD_LOCKOUT_THRESHOLD consecutive fails →
 *     PIN locked for HARD_LOCKOUT_HOURS, recoverable via the
 *     password-gated forgot() path)
 *   - Per-IP secondary throttle to bound distributed brute-force
 *     across many sessions
 *   - Per-event audit log via error_log (matches the rate
 *     limiter's idiom — no new infrastructure)
 *   - Per-event email notification (set / change / disable /
 *     locked / forgot — silent rotation is the threat the gate
 *     exists to detect)
 *
 * Five canonical paths (used both as $path arg keys and as the suffix
 * of the matrix_mlm_pin_required_for_<path> option):
 *
 *   - 'transfers'         — peer Wallet→Wallet (process_transfer)
 *   - 'matrix_to_virtual' — Matrix → user's own Fintava virtual
 *   - 'bank'              — Fintava virtual → external bank
 *   - 'bills'             — airtime / data / cable / electricity
 *   - 'subscription'      — manual subscription pay
 *
 * Third-party gateways can extend the path map via the filter
 * matrix_mlm_transaction_pin_paths — see paths().
 *
 * Threat model ordering for a gated transaction (mirrors the 2FA
 * disable path, extended for the new lockout/IP layers):
 *
 *   gate predicate (cheap)
 *     → hard-lockout check (cheap, single col read)
 *     → rolling per-user rate limit
 *     → per-IP secondary rate limit
 *     → empty-PIN check
 *     → bcrypt verify (uses the hash already fetched)
 *     → on failure: bump persistent counter, maybe trigger lockout
 *     → on success: clear counters, update last_used_at, rehash if
 *       PASSWORD_DEFAULT cost has moved
 *
 * @package MatrixMLM
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Transaction_Pin {

    /**
     * Minimum / maximum digit count.
     *
     * 4 is the ATM-PIN floor most users are familiar with; 6 is the
     * upper bound that fits comfortably on a single tap target on
     * mobile keypads and matches the format of the existing Fintava
     * card PIN. The normaliser strips non-digits before measuring,
     * so a user who pastes "1234-5678" gets it auto-cleaned to
     * "12345678" (which then fails the MAX gate, surfacing a clear
     * "PIN must be 4–6 digits" error rather than silently accepting
     * the first 6 chars).
     */
    const MIN_LEN = 4;
    const MAX_LEN = 6;

    /**
     * Centralised rate-limit knobs. Used by the four core handlers
     * (set / change / disable / forgot) AND by the per-request
     * gate so an operator triaging a "PIN locked me out" complaint
     * sees the same idiom regardless of which surface the user hit.
     *
     * VERIFY_MAX_ATTEMPTS / VERIFY_WINDOW_SECONDS — the rolling
     * limiter shared across all eight fund-movement handlers and
     * the lifecycle handlers. 5 / 15 min was the existing magic
     * number duplicated four times in core.php; promoted to a
     * constant so a single edit propagates everywhere.
     */
    const VERIFY_MAX_ATTEMPTS  = 5;
    const VERIFY_WINDOW_SECONDS = 900; // 15 * MINUTE_IN_SECONDS

    /**
     * Hard-lockout knobs (threat-model item #2).
     *
     * The rolling rate-limit caps requests-per-window but a
     * patient attacker who pauses just under the cap can keep
     * the window alive forever. The hard counter is persistent
     * (transaction_pin_failed_attempts column), only resets on
     * a verified-correct PIN, and trips a lockout once it
     * crosses the threshold. Mirrors common ATM behaviour:
     * "10 wrong PINs and your card is captured for 24h".
     */
    const HARD_LOCKOUT_THRESHOLD = 10;
    const HARD_LOCKOUT_HOURS     = 24;

    /**
     * Per-IP secondary throttle (threat-model item #4).
     *
     * The per-user limiter keys on user id, so an attacker with
     * session cookies for many accounts (e.g. via XSS) can try
     * one PIN per user without ever tripping a per-user counter.
     * The per-IP counter caps total verify attempts from a
     * single IP regardless of which user account is targeted.
     * Tuned higher than the per-user cap so a busy household /
     * shared-NAT install doesn't false-positive.
     */
    const PER_IP_MAX_ATTEMPTS  = 30;
    const PER_IP_WINDOW_SECONDS = 900; // 15 * MINUTE_IN_SECONDS

    /**
     * History depth for the no-reuse policy (threat-model item #16).
     *
     * change() rejects a candidate that password_verify()s against
     * any of the last HISTORY_LENGTH stored hashes. 5 mirrors the
     * default of most banking systems and keeps the JSON column
     * small (bcrypt hashes are 60 chars each → ≤ 360 bytes per row).
     */
    const HISTORY_LENGTH = 5;

    /**
     * Top-N common-PIN denylist (threat-model item #1).
     *
     * Sourced from the public DataGenetics study of 3.4M leaked
     * PINs and supplemented with structural variants the
     * structural-checker below would already catch (defensive
     * duplication: a denylist hit is unambiguous in error
     * messaging, the structural checks are intentionally generic).
     *
     * Filterable via matrix_mlm_transaction_pin_denylist so an
     * operator can ship additional bans (e.g. the site's
     * postcode, the founding year) without forking the plugin.
     */
    const COMMON_PINS = [
        // 4-digit
        '0000','1111','2222','3333','4444','5555','6666','7777','8888','9999',
        '1234','4321','1212','2121','1010','0101','1313','6969','7000',
        '1004','2580','0852','5683','1122','2233','3344','4455','5566','6677',
        // 5-digit
        '12345','54321','11111','22222','33333','44444','55555','66666','77777','88888','99999','00000',
        // 6-digit
        '123456','654321','111111','222222','333333','444444','555555','666666',
        '777777','888888','999999','000000','121212','112233','123123','100000','111000',
    ];

    /**
     * Canonical list of fund-movement paths the PIN can gate. The
     * order here is the display order on the admin Financial tab's
     * "Transaction PIN Requirements" sub-section. Extending the map
     * is a one-row change here PLUS:
     *   - a new <input> + label in render_financial_tab(),
     *   - a key in the Financial save_settings whitelist,
     *   - a key in the global $checkboxes array,
     *   - a require_pin_for_request($user_id, '<new>') call in the
     *     handler, and a render_field() drop-in on the form.
     *
     * Stored as an associative map (path => human label) so the
     * admin page can render labels without an extra lookup table.
     * Third-party gateways add entries via the filter wrapper
     * paths() rather than touching this constant directly.
     */
    const PATHS = [
        'transfers'         => 'Peer wallet transfers',
        'matrix_to_virtual' => 'Transfer to own Fintava virtual wallet',
        'bank'              => 'Transfer to external bank',
        'bills'             => 'Bill payments (airtime, data, cable, electricity)',
        'subscription'      => 'Subscription manual pay',
    ];

    /**
     * Filter-aware accessor for PATHS (threat-model item #18).
     *
     * Third-party gateway plugins can register an additional path
     * key without forking by hooking matrix_mlm_transaction_pin_paths.
     * Every internal lookup (pin_required_for, render_field, etc.)
     * funnels through here so an extension is recognised
     * everywhere at once.
     *
     * Defensive: forces the result back to an associative array
     * (path => label) so a buggy filter that returns a non-array
     * doesn't crash render_field downstream.
     *
     * @return array<string,string>
     */
    public static function paths() {
        $paths = apply_filters('matrix_mlm_transaction_pin_paths', self::PATHS);
        if (!is_array($paths)) {
            return self::PATHS;
        }
        return $paths;
    }

    /**
     * Filter-aware accessor for the weak-PIN denylist
     * (threat-model item #1).
     */
    public static function denylist() {
        $list = apply_filters('matrix_mlm_transaction_pin_denylist', self::COMMON_PINS);
        if (!is_array($list)) {
            return self::COMMON_PINS;
        }
        // Normalise every entry through the same digit-strip the
        // input goes through, so an operator who adds " 12-34 " to
        // the filter still actually blocks "1234".
        $clean = [];
        foreach ($list as $entry) {
            $n = self::normalise($entry);
            if ($n !== '') {
                $clean[] = $n;
            }
        }
        return $clean;
    }

    /**
     * Filter-aware bcrypt cost factor (threat-model item #14).
     *
     * The original docblock claimed "tunable cost via filter" but
     * no filter existed; this is the missing hook. Defaults to
     * PASSWORD_BCRYPT_DEFAULT_COST (10) for parity with the
     * recovery-codes path. Hosts running on stronger hardware
     * can bump this to 12 without forking.
     *
     * @return array Options array for password_hash().
     */
    private static function bcrypt_options() {
        $cost = (int) apply_filters(
            'matrix_mlm_transaction_pin_bcrypt_cost',
            defined('PASSWORD_BCRYPT_DEFAULT_COST') ? PASSWORD_BCRYPT_DEFAULT_COST : 10
        );
        // Sanity: bcrypt's valid range is 4-31. Out-of-range values
        // make password_hash throw a warning + return false.
        if ($cost < 4)  { $cost = 4;  }
        if ($cost > 13) { $cost = 13; }
        return ['cost' => $cost];
    }

    /**
     * Strip everything that isn't a digit and return the result.
     *
     * Defensive against three classes of input bug:
     *   - Stray whitespace / dashes from copy-paste — auto-cleaned.
     *   - Non-numeric chars (e.g. a user typing letters) — stripped
     *     so the length check below catches it as "too short" with
     *     a user-actionable message.
     *   - Array payloads (`pin[]=1234`) — `(string)` on an array
     *     coerces to "Array", which the digit filter strips to "",
     *     which then fails the MIN gate. Closes the array-attack
     *     vector that the bill-payment hardening flagged.
     *
     * @param mixed $raw POST value (typically string but coerced).
     * @return string Digits-only, or '' if input was empty/junk.
     */
    public static function normalise($raw) {
        if (is_array($raw)) {
            return '';
        }
        return preg_replace('/[^0-9]/', '', (string) $raw);
    }

    /**
     * Whether the supplied digit string passes the length gate.
     *
     * Format-only (length); see is_acceptable_pin() for the
     * weak-PIN / structural / personal-attribute checks.
     */
    public static function is_valid_format($normalised) {
        $len = strlen($normalised);
        return $len >= self::MIN_LEN && $len <= self::MAX_LEN;
    }

    /**
     * Composite acceptability check (threat-model item #1).
     *
     * Returns true on accept, WP_Error on reject. The error code
     * tells the caller which rule fired so the UI can surface a
     * specific message (and so the audit log can categorise
     * "user picked a weak PIN" vs "user picked their phone last-4").
     *
     * Order is cheap-rule-first so most rejections short-circuit
     * before the user-attribute lookup hits the DB.
     *
     *   1. Length (re-checked here so callers can rely on this
     *      single helper without also calling is_valid_format).
     *   2. Top-N common denylist + filter additions.
     *   3. All-same digits ('0000', '11111', '999999').
     *   4. Strict ascending / descending runs ('1234', '987654').
     *   5. Personal-attribute match: numeric prefix of user_login,
     *      digits-only of phone column, numeric prefix of email
     *      local-part. Skipped if $user_id is 0 so the helper is
     *      usable from contexts where the user isn't yet known
     *      (admin tooling, registration-time validation).
     *
     * @return true|WP_Error
     */
    public static function is_acceptable_pin($normalised, $user_id = 0) {
        if (!self::is_valid_format($normalised)) {
            return new WP_Error(
                'pin_invalid',
                sprintf(
                    /* translators: 1: minimum digits, 2: maximum digits */
                    __('Transaction PIN must be %1$d to %2$d digits.', 'matrix-mlm'),
                    self::MIN_LEN,
                    self::MAX_LEN
                )
            );
        }
        if (in_array($normalised, self::denylist(), true)) {
            return new WP_Error(
                'pin_too_common',
                __('That PIN is too easy to guess. Pick something less common.', 'matrix-mlm')
            );
        }
        if (self::is_all_same_digit($normalised)) {
            return new WP_Error(
                'pin_too_common',
                __('Your PIN cannot be all the same digit.', 'matrix-mlm')
            );
        }
        if (self::is_strict_sequential($normalised)) {
            return new WP_Error(
                'pin_too_common',
                __('Your PIN cannot be a simple sequence like 1234 or 4321.', 'matrix-mlm')
            );
        }
        if ($user_id > 0 && self::matches_user_attribute($normalised, (int) $user_id)) {
            return new WP_Error(
                'pin_too_personal',
                __('Your PIN cannot match your username, phone digits, or email.', 'matrix-mlm')
            );
        }
        return true;
    }

    /**
     * Helper: '0000', '1111', '999999' style.
     */
    private static function is_all_same_digit($n) {
        return strlen($n) > 0 && preg_match('/^(\d)\1+$/', $n) === 1;
    }

    /**
     * Helper: strict +1 or -1 runs of any length.
     *
     * Catches '1234', '01234', '987654', '6543', '0123'. Does NOT
     * catch '1357' (step 2) or wrap-around '9012' — both are rare
     * enough in real-world common-PIN frequency tables that the
     * top-N denylist covers them better than a generic rule.
     */
    private static function is_strict_sequential($n) {
        $len = strlen($n);
        if ($len < 2) {
            return false;
        }
        $up = true; $down = true;
        for ($i = 1; $i < $len; $i++) {
            $diff = (int) $n[$i] - (int) $n[$i - 1];
            if ($diff !== 1)  { $up = false; }
            if ($diff !== -1) { $down = false; }
        }
        return $up || $down;
    }

    /**
     * Helper: PIN equal to a digit-extracted user attribute.
     *
     * Three sources, mirroring how a typical attacker would build
     * a personalised guess list: the username, the phone column
     * on matrix_user_meta (digits only), and the numeric prefix
     * of the email local-part (so 'jane1234@…' contributes '1234').
     */
    private static function matches_user_attribute($n, $user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }
        // Username — only the digits-only suffix/prefix matters
        // for an exact-match check against a 4-6 digit PIN.
        $login_digits = self::normalise($user->user_login);
        if ($login_digits !== '' && $login_digits === $n) {
            return true;
        }
        // Email local-part numeric — '1234@example.com' → '1234'.
        $email = (string) $user->user_email;
        $at = strpos($email, '@');
        if ($at !== false) {
            $local = substr($email, 0, $at);
            if (self::normalise($local) === $n) {
                return true;
            }
        }
        // Phone — digits-only of matrix_user_meta.phone. Match on
        // exact equality OR last-N suffix (trailing 4-6 digits)
        // because phone numbers are typically longer than a PIN
        // but a last-4 match is the actual brute-force vector.
        global $wpdb;
        $phone = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT phone FROM {$wpdb->prefix}matrix_user_meta WHERE user_id = %d",
            $user_id
        ));
        $phone_digits = self::normalise($phone);
        if ($phone_digits !== '') {
            if ($phone_digits === $n) {
                return true;
            }
            $tail = strlen($n);
            if (strlen($phone_digits) >= $tail
                && substr($phone_digits, -$tail) === $n) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the master "Transaction PIN" feature is on at the
     * site level. Read from matrix_mlm_transaction_pin_enabled
     * (default 1).
     */
    public static function is_master_enabled() {
        return (bool) get_option('matrix_mlm_transaction_pin_enabled', 1);
    }

    /**
     * Whether the admin requires a PIN for this fund-movement path.
     *
     * Per-path keys follow the matrix_mlm_pin_required_for_<path>
     * convention. Default OFF on every path. Unknown paths
     * fail-CLOSED in the sense that no gate decision is made.
     */
    public static function pin_required_for($path) {
        if (!array_key_exists($path, self::paths())) {
            return false;
        }
        if (!self::is_master_enabled()) {
            return false;
        }
        return (bool) get_option('matrix_mlm_pin_required_for_' . $path, 0);
    }

    /**
     * Single source of truth for "should this user be challenged on
     * this path?" (threat-model item #10).
     *
     * render_field() and require_pin_for_request() previously
     * duplicated the predicate; if one drifted from the other
     * the form would prompt for a PIN the server didn't enforce
     * (or vice versa). Funnel both through here so the lockstep
     * is mechanical rather than convention.
     *
     * Returns TRUE only when the path is admin-required AND the
     * user has a PIN set. The third state — admin-required but
     * user has not enrolled — is handled by requires_enrolment()
     * (block + prompt) and consulted by both callers ahead of
     * should_gate(), so the form / server pairing stays in
     * lockstep across all three branches:
     *
     *   - admin not requiring → no field, no enforcement
     *   - admin requiring, no PIN → enrolment callout, pin_not_set
     *   - admin requiring, PIN set → input field, verify gate
     */
    public static function should_gate($user_id, $path) {
        return self::pin_required_for($path) && self::is_set((int) $user_id);
    }

    /**
     * Whether the user must enrol before they can transact on this
     * path. Returns TRUE when the admin requires a PIN for the path
     * AND the user has not set one. The matching server gate
     * verify_request() returns a 'pin_not_set' WP_Error in this
     * branch and render_field() returns the enrolment callout
     * (rather than the input or empty string).
     *
     * Originally PR #269 chose to fail-OPEN here — users without
     * a PIN transacted ungated so admins could enable enforcement
     * without locking out un-enrolled accounts. The intended UX
     * follow-up (prompt the user to enrol on first gated attempt)
     * was deferred and the failure mode it created — users
     * silently bypassing the gate forever because they never got
     * around to setting a PIN — was reported in production.
     * Mandatory enrolment closes that loop: admin-enabled paths
     * are now actually enforced, but the caller surfaces a clear
     * "set a PIN first" callout rather than a confusing generic
     * error, and the dashboard surfaces a one-time banner the
     * first time a user lands on a session with at least one
     * gated path and no PIN configured (see
     * any_path_requires_enrolment).
     */
    public static function requires_enrolment($user_id, $path) {
        return self::pin_required_for($path) && !self::is_set((int) $user_id);
    }

    /**
     * Whether the user must enrol on at least one currently-gated
     * path. Drives the dashboard-wide enrolment banner — distinct
     * from requires_enrolment($user_id, $path) which is per-path
     * and consulted by the form / gate pair.
     *
     * Loops over self::paths() (filter-aware, picks up extension
     * paths as well) and returns true on the first match so a
     * site with five gated paths still costs only one is_set()
     * lookup in the worst case.
     */
    public static function any_path_requires_enrolment($user_id) {
        $user_id = (int) $user_id;
        if (!self::is_master_enabled()) {
            return false;
        }
        // Cheap precheck — if the user already has a PIN set,
        // no path can possibly require enrolment for them.
        if (self::is_set($user_id)) {
            return false;
        }
        foreach (array_keys(self::paths()) as $path) {
            if ((bool) get_option('matrix_mlm_pin_required_for_' . $path, 0)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether $user_id has a PIN configured.
     *
     * Reads `transaction_pin_hash IS NOT NULL` from matrix_user_meta.
     * Used both by the UI (decide whether to show "Set PIN" or
     * "Change/Disable PIN") and by the gate to decide whether to
     * enforce.
     */
    public static function is_set($user_id) {
        $meta = self::fetch_meta((int) $user_id);
        return $meta !== null && is_string($meta->transaction_pin_hash) && $meta->transaction_pin_hash !== '';
    }

    /**
     * Whether the user is currently in a hard-lockout window
     * (threat-model item #2). Read by the gate AND by the UI so
     * the dashboard can surface a "PIN locked, use Forgot PIN"
     * banner instead of leaving the user to discover the lockout
     * mid-transaction.
     */
    public static function is_locked($user_id) {
        $meta = self::fetch_meta((int) $user_id);
        if ($meta === null || empty($meta->transaction_pin_locked_until)) {
            return false;
        }
        return strtotime($meta->transaction_pin_locked_until) > time();
    }

    /**
     * Lockout details for UI surfacing. Returns an array with the
     * unlock timestamp (or null) and a human-readable countdown.
     */
    public static function lockout_info($user_id) {
        $meta = self::fetch_meta((int) $user_id);
        if ($meta === null || empty($meta->transaction_pin_locked_until)) {
            return ['locked' => false, 'unlock_at' => null];
        }
        $unlock = strtotime($meta->transaction_pin_locked_until);
        return [
            'locked'    => $unlock > time(),
            'unlock_at' => $unlock,
        ];
    }

    /**
     * When the PIN was last (re-)set. Read by the dashboard to
     * surface "PIN set on …".
     */
    public static function set_at($user_id) {
        $meta = self::fetch_meta((int) $user_id);
        if ($meta === null || empty($meta->transaction_pin_set_at)) {
            return null;
        }
        return $meta->transaction_pin_set_at;
    }

    /**
     * When the PIN was last successfully verified. Read by the
     * dashboard to surface "Last used …" so a user can spot a
     * rogue authorised transaction at a glance.
     */
    public static function last_used_at($user_id) {
        $meta = self::fetch_meta((int) $user_id);
        if ($meta === null || empty($meta->transaction_pin_last_used_at)) {
            return null;
        }
        return $meta->transaction_pin_last_used_at;
    }

    /**
     * Hash and persist a fresh PIN for the user.
     *
     * Static (threat-model item #11) — the class holds no state.
     * Acceptability gate runs server-side regardless of caller
     * context; the caller (process_set_transaction_pin) still does
     * the password reauth + already-set rejection above this.
     *
     * Threat-model item #6: $wpdb->update is replaced with a
     * row-existence guard so a user with no matrix_user_meta row
     * (created outside Matrix_MLM_Core::register_user) gets a
     * clear 'pin_no_meta_row' error instead of silent success.
     *
     * Returns true on success, WP_Error on reject.
     */
    public static function set($user_id, $plaintext) {
        $user_id = (int) $user_id;
        $normalised = self::normalise($plaintext);

        $accept = self::is_acceptable_pin($normalised, $user_id);
        if (is_wp_error($accept)) {
            self::log_event($user_id, 'set_rejected', $accept->get_error_code());
            return $accept;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'matrix_user_meta';

        // Row-existence guard. UPDATE on a missing row returns 0,
        // which the legacy code conflated with "value identical,
        // success". We want a clear error on the missing-row path.
        $row_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE user_id = %d",
            $user_id
        ));
        if ($row_exists === 0) {
            self::log_event($user_id, 'set_failed', 'no_meta_row');
            return new WP_Error(
                'pin_no_meta_row',
                __('Could not save your PIN — your account profile is incomplete. Please contact support.', 'matrix-mlm')
            );
        }

        $hash = password_hash($normalised, PASSWORD_DEFAULT, self::bcrypt_options());
        $now  = current_time('mysql');

        $rows = $wpdb->update(
            $table,
            [
                'transaction_pin_hash'             => $hash,
                'transaction_pin_set_at'           => $now,
                'transaction_pin_failed_attempts'  => 0,
                'transaction_pin_locked_until'     => null,
            ],
            ['user_id' => $user_id],
            ['%s', '%s', '%d', '%s'],
            ['%d']
        );
        if ($rows === false) {
            self::log_event($user_id, 'set_failed', 'db_error');
            return new WP_Error(
                'pin_store_failed',
                __('Could not save your PIN. Please try again or contact support.', 'matrix-mlm')
            );
        }

        // Persist into history AFTER the live write succeeds — a
        // rejected write should not pollute history.
        self::push_history($user_id, $hash);

        self::log_event($user_id, 'set', 'ok');
        self::notify($user_id, 'set');
        return true;
    }

    /**
     * Replace an existing PIN with a new one.
     *
     * Adds (threat-model item #8) "new == current" rejection and
     * (item #16) history-based no-reuse against the last
     * HISTORY_LENGTH bcrypt hashes.
     */
    public static function change($user_id, $current_plaintext, $new_plaintext) {
        $user_id = (int) $user_id;
        if (!self::is_set($user_id)) {
            return new WP_Error(
                'pin_not_set',
                __('You have not set a transaction PIN yet.', 'matrix-mlm')
            );
        }
        if (!self::verify($user_id, $current_plaintext)) {
            // record_failure was already called inside verify();
            // we don't double-bump here.
            return new WP_Error(
                'pin_mismatch',
                __('Current PIN is incorrect.', 'matrix-mlm')
            );
        }

        $current_n = self::normalise($current_plaintext);
        $new_n     = self::normalise($new_plaintext);

        if ($new_n !== '' && $current_n === $new_n) {
            return new WP_Error(
                'pin_unchanged',
                __('Your new PIN must be different from your current PIN.', 'matrix-mlm')
            );
        }

        // Acceptability check up front so we don't accidentally
        // record a "no reuse" violation for a structurally
        // invalid candidate.
        $accept = self::is_acceptable_pin($new_n, $user_id);
        if (is_wp_error($accept)) {
            return $accept;
        }

        if (self::matches_history($user_id, $new_n)) {
            return new WP_Error(
                'pin_reused',
                sprintf(
                    /* translators: %d: number of historical PINs the policy bans */
                    __('You cannot reuse one of your last %d transaction PINs.', 'matrix-mlm'),
                    (int) self::HISTORY_LENGTH
                )
            );
        }

        $result = self::set($user_id, $new_plaintext);
        if (!is_wp_error($result)) {
            self::log_event($user_id, 'change', 'ok');
            self::notify($user_id, 'change');
        }
        return $result;
    }

    /**
     * Clear the user's PIN, gated on a current-PIN verify.
     */
    public static function disable($user_id, $current_plaintext) {
        $user_id = (int) $user_id;
        if (!self::is_set($user_id)) {
            return new WP_Error(
                'pin_not_set',
                __('You have not set a transaction PIN yet.', 'matrix-mlm')
            );
        }
        if (!self::verify($user_id, $current_plaintext)) {
            return new WP_Error(
                'pin_mismatch',
                __('Transaction PIN is incorrect.', 'matrix-mlm')
            );
        }

        if (!self::wipe_pin($user_id)) {
            return new WP_Error(
                'pin_store_failed',
                __('Could not clear your PIN. Please try again or contact support.', 'matrix-mlm')
            );
        }

        self::log_event($user_id, 'disable', 'ok');
        self::notify($user_id, 'disable');
        return true;
    }

    /**
     * Self-service "Forgot PIN" recovery (threat-model item #17).
     *
     * Caller (process_forgot_transaction_pin) is responsible for
     * the password reauth — this method assumes the password has
     * already been verified and proceeds to wipe the hash, the
     * lock, and the failed-attempt counter atomically. The user
     * is then free to set a new PIN through the normal Set flow.
     *
     * Distinct from disable() because the user has, by definition,
     * forgotten their current PIN — disable() requires it.
     */
    public static function forgot($user_id) {
        $user_id = (int) $user_id;
        if (!self::is_set($user_id) && !self::is_locked($user_id)) {
            // Idempotent — there's nothing to forget. Don't
            // surface this as an error to the user; the UI
            // shouldn't expose the no-op.
            return true;
        }
        if (!self::wipe_pin($user_id)) {
            return new WP_Error(
                'pin_store_failed',
                __('Could not reset your PIN. Please try again or contact support.', 'matrix-mlm')
            );
        }
        self::log_event($user_id, 'forgot', 'ok');
        self::notify($user_id, 'forgot');
        return true;
    }

    /**
     * Internal: wipe hash + lock + counter atomically. Used by
     * disable() and forgot().
     *
     * Note: history is intentionally NOT cleared. A user who
     * disables and re-enables their PIN should still be barred
     * from reusing one of their last N digits — the audit trail
     * lives across enable/disable cycles.
     */
    private static function wipe_pin($user_id) {
        global $wpdb;
        $rows = $wpdb->update(
            $wpdb->prefix . 'matrix_user_meta',
            [
                'transaction_pin_hash'            => null,
                'transaction_pin_failed_attempts' => 0,
                'transaction_pin_locked_until'    => null,
            ],
            ['user_id' => (int) $user_id],
            ['%s', '%d', '%s'],
            ['%d']
        );
        return $rows !== false;
    }

    /**
     * Per-request gate (the entry point used by the eight
     * fund-movement handlers). Thin wrapper that translates a
     * verify_request() error into wp_send_json_error so the
     * existing handlers don't change.
     *
     * Defensive contract: this method either returns silently
     * (gate cleared) or terminates the request via
     * wp_send_json_error.
     */
    public static function require_pin_for_request($user_id, $path) {
        $result = self::verify_request((int) $user_id, $path);
        if ($result === true) {
            return;
        }
        // verify_request returns a WP_Error with an extra
        // 'http_response' data slot for non-default response
        // shapes (e.g. lockout includes unlock_at).
        $payload = ['pin_required' => true, 'message' => $result->get_error_message()];
        $extra   = $result->get_error_data();
        if (is_array($extra)) {
            $payload = array_merge($payload, $extra);
        }
        wp_send_json_error($payload);
    }

    /**
     * Pure variant of require_pin_for_request (threat-model
     * item #12). Returns true on gate-cleared, WP_Error otherwise.
     *
     * Lets REST endpoints, WP-CLI, and cron jobs reuse the gate
     * without inheriting the AJAX-terminating contract of
     * require_pin_for_request.
     */
    public static function verify_request($user_id, $path) {
        $user_id = (int) $user_id;

        // Admin doesn't require PIN for this path → gate inert,
        // transaction proceeds.
        if (!self::pin_required_for($path)) {
            return true;
        }

        // Admin requires PIN but user hasn't enrolled. Block + prompt
        // (was: silently let through; reported as the "I just did a
        // wallet transfer without being asked for a PIN" bug).
        // Surfaces 'pin_not_set' so the caller (require_pin_for_request)
        // can include a security_url in the JSON payload, and the
        // form-side render_field returns the matching enrolment
        // callout rather than the input — both ends of the pair are
        // consulted before should_gate() so they stay in lockstep
        // across all three branches.
        if (!self::is_set($user_id)) {
            self::log_event($user_id, 'verify_blocked', 'pin_not_set');
            return new WP_Error(
                'pin_not_set',
                __('You must set a transaction PIN before you can use this feature. Visit your Security settings to set one.', 'matrix-mlm'),
                [
                    'pin_not_set' => true,
                    'security_url' => home_url('/matrix-dashboard/security/'),
                ]
            );
        }

        // (2) Hard lockout. Cheap (single row read shared with the
        // verify path below); fires before any rate-limit
        // bookkeeping so a locked user can't even consume slots.
        if (self::is_locked($user_id)) {
            $info = self::lockout_info($user_id);
            return new WP_Error(
                'pin_locked',
                __('Transaction PIN is locked due to too many failed attempts. Use "Forgot PIN" on your Security tab to reset it.', 'matrix-mlm'),
                ['locked' => true, 'unlock_at' => $info['unlock_at']]
            );
        }

        // (Existing) Rolling per-user rate limit. Shared counter
        // across all eight enforcement points so an attacker
        // can't cycle handlers to refresh the window.
        $rl_action = 'transaction_pin_verify';
        $rl_key    = Matrix_MLM_Rate_Limiter::key_for_request();
        if (Matrix_MLM_Rate_Limiter::throttle(
            $rl_action,
            $rl_key,
            ['max_attempts' => self::VERIFY_MAX_ATTEMPTS, 'window_seconds' => self::VERIFY_WINDOW_SECONDS]
        )) {
            self::log_event($user_id, 'verify_throttled', 'per_user');
            return new WP_Error(
                'pin_throttled',
                __('Too many transaction PIN attempts. Please wait a few minutes and try again.', 'matrix-mlm')
            );
        }

        // (4) Per-IP secondary throttle. Caps total verify attempts
        // from a single IP regardless of user, so an attacker with
        // many session cookies can't try one PIN per user.
        // Skipped for authenticated callers when REMOTE_ADDR is
        // empty (CLI / cron) — those callers are already trusted.
        $ip = Matrix_MLM_Rate_Limiter::client_ip();
        if ($ip !== '') {
            $ip_key = 'ip:' . sha1($ip);
            if (Matrix_MLM_Rate_Limiter::throttle(
                $rl_action . '_ip',
                $ip_key,
                ['max_attempts' => self::PER_IP_MAX_ATTEMPTS, 'window_seconds' => self::PER_IP_WINDOW_SECONDS]
            )) {
                self::log_event($user_id, 'verify_throttled', 'per_ip');
                return new WP_Error(
                    'pin_throttled',
                    __('Too many transaction PIN attempts from this network. Please wait a few minutes and try again.', 'matrix-mlm')
                );
            }
        }

        $raw = isset($_POST['transaction_pin']) ? wp_unslash($_POST['transaction_pin']) : '';
        $normalised = self::normalise($raw);
        if ($normalised === '') {
            return new WP_Error(
                'pin_required',
                __('Enter your transaction PIN to confirm this action.', 'matrix-mlm')
            );
        }

        if (!self::verify($user_id, $raw)) {
            // verify() already incremented the persistent counter
            // and may have triggered a lockout. Surface a generic
            // "incorrect" message so we don't leak whether the
            // miss was format / hash / counter-related.
            // If the failure tipped us over the threshold, surface
            // the lockout now so the caller can update the UI.
            if (self::is_locked($user_id)) {
                $info = self::lockout_info($user_id);
                return new WP_Error(
                    'pin_locked',
                    __('Too many wrong PINs. Your transaction PIN has been locked. Use "Forgot PIN" on your Security tab to reset it.', 'matrix-mlm'),
                    ['locked' => true, 'unlock_at' => $info['unlock_at']]
                );
            }
            return new WP_Error(
                'pin_mismatch',
                __('Transaction PIN is incorrect.', 'matrix-mlm')
            );
        }

        // Success — clear the rolling throttle so a real user
        // who fat-fingered once doesn't burn budget on every
        // subsequent legal transaction in the window. Persistent
        // counter / last_used_at are written inside verify() on
        // the success path.
        Matrix_MLM_Rate_Limiter::reset($rl_action, $rl_key);
        return true;
    }

    /**
     * Render the user-side PIN <input> for a fund-movement form, or
     * the empty string when the gate isn't active for this user/path
     * pair, or an enrolment callout when the path is admin-required
     * but the user hasn't set a PIN yet.
     *
     * (Item #9) Optional $instance suffix lets the same path render
     * twice on one page (airtime + data on the bills page) without
     * minting duplicate HTML ids. Defaults to a trivial counter so
     * existing single-render callers don't have to pass anything.
     *
     * (Item #10) Funnels through pin_required_for() / is_set() in
     * the same order as verify_request() so prompt and enforcement
     * stay locked together across the three-branch fork:
     *   - admin not requiring → ''   (no field rendered)
     *   - admin requiring, no PIN → render_enrolment_callout()
     *   - admin requiring, PIN set → the actual <input> field
     *
     * @return string HTML fragment, or '' if the gate isn't active.
     */
    public static function render_field($user_id, $path, $instance = '') {
        // Admin doesn't require PIN for this path → no field, no
        // callout. Same predicate gate-side returns true (skip),
        // so prompt and enforcement stay in lockstep.
        if (!self::pin_required_for($path)) {
            return '';
        }

        $user_id = (int) $user_id;

        // Admin requires PIN but user hasn't enrolled. Show the
        // enrolment callout instead of the input. The caller's
        // submit button is left clickable (the call site owns it)
        // so the user is free to try anyway — the server-side gate
        // returns pin_not_set with a security_url in the JSON
        // payload, and existing per-form alert(message) handlers
        // will surface that copy. The callout's own CTA is the
        // primary path though.
        if (!self::is_set($user_id)) {
            return self::render_enrolment_callout($path);
        }

        static $counter = 0;
        $counter++;
        $suffix = $instance !== '' ? '-' . sanitize_html_class($instance) : '-' . $counter;

        $label = __('Transaction PIN', 'matrix-mlm');
        $hint  = __('Enter your transaction PIN to authorise this action.', 'matrix-mlm');
        $ph    = sprintf(
            /* translators: 1: minimum digits, 2: maximum digits */
            __('%1$d–%2$d digits', 'matrix-mlm'),
            self::MIN_LEN,
            self::MAX_LEN
        );
        $id = 'matrix-pin-' . esc_attr($path) . esc_attr($suffix);

        ob_start();
        ?>
        <div class="matrix-form-group matrix-pin-field">
            <label for="<?php echo $id; ?>">
                <?php echo esc_html($label); ?>
            </label>
            <input
                type="password"
                inputmode="numeric"
                pattern="[0-9]*"
                autocomplete="off"
                name="transaction_pin"
                id="<?php echo $id; ?>"
                minlength="<?php echo (int) self::MIN_LEN; ?>"
                maxlength="<?php echo (int) self::MAX_LEN; ?>"
                required
                placeholder="<?php echo esc_attr($ph); ?>">
            <small class="description"><?php echo esc_html($hint); ?></small>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Enrolment callout rendered in place of the PIN <input> when
     * the path is admin-required but the user has not set a PIN.
     *
     * The callout's primary CTA links to the dashboard's Security
     * tab where the user can complete enrolment via the existing
     * Set PIN flow (matrixTogglePinForm('set')). The link uses the
     * tab_url() helper indirectly via home_url() so it survives
     * the pretty-URL / plain-permalink fork the dashboard already
     * defends against.
     *
     * Why an inline alert instead of disabling the form's submit
     * button? The submit button is owned by the call site (each
     * form is rendered by a different file: user-wallet,
     * bank-payout, billing, etc.), and reaching across that
     * boundary to disable it would require either a JS handle the
     * call sites would have to standardise on, or a server-side
     * rewrite of the button HTML the helper currently doesn't
     * touch. The callout instead leaves the submit clickable and
     * leans on the existing per-form alert(message) handlers to
     * surface the server-side pin_not_set message if the user
     * tries anyway. The CTA is the primary path; the submit-then-
     * see-error flow is the safety net.
     *
     * Path-aware copy via $path for future refinement (e.g. a
     * different message for bills vs. peer transfers); current
     * release uses generic copy because the gate is binary.
     *
     * @param string $path Path key for which the callout is being
     *                     rendered. Currently unused in copy but
     *                     reserved for path-specific messaging.
     * @return string HTML fragment.
     */
    private static function render_enrolment_callout($path) {
        // $path reserved for future per-path messaging; suppress
        // unused-arg lint by referencing it in a comment-equivalent
        // no-op (assigning to a discard variable is the idiom WP
        // uses elsewhere for the same purpose).
        $path = (string) $path;

        $security_url = home_url('/matrix-dashboard/security/');
        $title        = __('Set a transaction PIN to continue', 'matrix-mlm');
        $body         = __('A transaction PIN is required to authorise this action. You haven\'t set one yet — click the button below to set a 4 to 6 digit PIN on your Security tab. This is a one-time setup; future transactions will simply prompt you for the PIN.', 'matrix-mlm');
        $cta          = __('Set Transaction PIN', 'matrix-mlm');

        ob_start();
        ?>
        <div class="matrix-form-group matrix-pin-enrolment-callout">
            <div class="matrix-alert matrix-alert-warning">
                <strong><?php echo esc_html($title); ?></strong>
                <p style="margin:8px 0 12px 0;">
                    <?php echo esc_html($body); ?>
                </p>
                <a href="<?php echo esc_url($security_url); ?>" class="matrix-btn matrix-btn-primary">
                    <?php echo esc_html($cta); ?>
                </a>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Verify a plaintext PIN against the stored hash.
     *
     * Threat-model item #5: re-hashes on success when
     * password_needs_rehash reports the cost has moved.
     *
     * Threat-model item #7: pulls the meta row once and reuses
     * it for both the hash check and the lockout / counter
     * bookkeeping below — no second SELECT.
     *
     * Threat-model item #2: bumps the persistent counter on
     * failure and trips a lockout if it crosses the threshold.
     */
    public static function verify($user_id, $plaintext) {
        $user_id = (int) $user_id;
        $normalised = self::normalise($plaintext);
        if (!self::is_valid_format($normalised)) {
            self::record_failure($user_id);
            return false;
        }

        $meta = self::fetch_meta($user_id);
        if ($meta === null
            || !is_string($meta->transaction_pin_hash)
            || $meta->transaction_pin_hash === '') {
            self::record_failure($user_id);
            return false;
        }

        if (!password_verify($normalised, $meta->transaction_pin_hash)) {
            self::record_failure($user_id);
            return false;
        }

        // (5) password_needs_rehash + re-store. Idiomatic
        // upgrade path for when an operator bumps the bcrypt
        // cost filter or PHP's PASSWORD_DEFAULT moves.
        $opts = self::bcrypt_options();
        if (password_needs_rehash($meta->transaction_pin_hash, PASSWORD_DEFAULT, $opts)) {
            $new_hash = password_hash($normalised, PASSWORD_DEFAULT, $opts);
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'matrix_user_meta',
                ['transaction_pin_hash' => $new_hash],
                ['user_id' => $user_id],
                ['%s'],
                ['%d']
            );
            // Push the upgraded hash into history so future
            // change() calls correctly reject reuse against
            // the upgraded representation as well.
            self::push_history($user_id, $new_hash);
        }

        self::record_success($user_id);
        return true;
    }

    /**
     * Internal: read the meta row's PIN-related columns once
     * (threat-model item #7).
     *
     * Returned as an object with the column names so callers can
     * dot-access. Returns null if the user has no meta row at all.
     */
    private static function fetch_meta($user_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                transaction_pin_hash,
                transaction_pin_set_at,
                transaction_pin_last_used_at,
                transaction_pin_history,
                transaction_pin_failed_attempts,
                transaction_pin_locked_until
             FROM {$wpdb->prefix}matrix_user_meta
             WHERE user_id = %d",
            (int) $user_id
        ));
        return $row ?: null;
    }

    /**
     * Internal: bump the persistent failed-attempts counter and
     * trip a lockout if we cross the threshold.
     */
    private static function record_failure($user_id) {
        $user_id = (int) $user_id;
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_user_meta';

        // Atomic-ish increment. Two concurrent failures can both
        // read N and write N+1 — same race the rate limiter
        // documents — but the threshold is order-of-magnitude
        // bound, not exact. Acceptable for a brute-force counter.
        $wpdb->query($wpdb->prepare(
            "UPDATE $table
                SET transaction_pin_failed_attempts = transaction_pin_failed_attempts + 1
              WHERE user_id = %d",
            $user_id
        ));

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT transaction_pin_failed_attempts FROM $table WHERE user_id = %d",
            $user_id
        ));

        if ($count >= self::HARD_LOCKOUT_THRESHOLD) {
            $until = date('Y-m-d H:i:s', time() + (self::HARD_LOCKOUT_HOURS * HOUR_IN_SECONDS));
            $wpdb->update(
                $table,
                ['transaction_pin_locked_until' => $until],
                ['user_id' => $user_id],
                ['%s'],
                ['%d']
            );
            self::log_event($user_id, 'locked', 'threshold:' . $count);
            self::notify($user_id, 'locked', ['unlock_at' => $until]);
        }
    }

    /**
     * Internal: clear the persistent counter, refresh
     * last_used_at on a successful verify.
     */
    private static function record_success($user_id) {
        $user_id = (int) $user_id;
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'matrix_user_meta',
            [
                'transaction_pin_failed_attempts' => 0,
                'transaction_pin_locked_until'    => null,
                'transaction_pin_last_used_at'    => current_time('mysql'),
            ],
            ['user_id' => $user_id],
            ['%d', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * Internal: append the current hash onto the JSON history
     * column, trim to HISTORY_LENGTH (oldest dropped first).
     */
    private static function push_history($user_id, $hash) {
        $user_id = (int) $user_id;
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT transaction_pin_history FROM {$wpdb->prefix}matrix_user_meta WHERE user_id = %d",
            $user_id
        ));
        $list = [];
        if (is_string($existing) && $existing !== '') {
            $decoded = json_decode($existing, true);
            if (is_array($decoded)) {
                $list = $decoded;
            }
        }
        $list[] = $hash;
        if (count($list) > self::HISTORY_LENGTH) {
            $list = array_slice($list, -self::HISTORY_LENGTH);
        }
        $wpdb->update(
            $wpdb->prefix . 'matrix_user_meta',
            ['transaction_pin_history' => wp_json_encode($list)],
            ['user_id' => $user_id],
            ['%s'],
            ['%d']
        );
    }

    /**
     * Internal: does $candidate match any stored historical hash?
     */
    private static function matches_history($user_id, $candidate_normalised) {
        if ($candidate_normalised === '') {
            return false;
        }
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT transaction_pin_history FROM {$wpdb->prefix}matrix_user_meta WHERE user_id = %d",
            (int) $user_id
        ));
        if (!is_string($existing) || $existing === '') {
            return false;
        }
        $list = json_decode($existing, true);
        if (!is_array($list)) {
            return false;
        }
        foreach ($list as $hash) {
            if (is_string($hash) && password_verify($candidate_normalised, $hash)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Internal: structured event log (threat-model item #3).
     *
     * Uses error_log with a stable [Matrix PIN] prefix matching
     * the rate limiter's [Matrix RateLimit] convention so an
     * operator grepping the error log can pull the full PIN
     * lifecycle for a user without touching new infrastructure.
     */
    private static function log_event($user_id, $action, $detail = '') {
        $ip = Matrix_MLM_Rate_Limiter::client_ip();
        error_log(sprintf(
            '[Matrix PIN] user=%d action=%s detail=%s ip=%s',
            (int) $user_id,
            $action,
            $detail,
            $ip !== '' ? sha1($ip) : '-'
        ));
    }

    /**
     * Internal: send a per-event email (threat-model item #3).
     *
     * Plain-text rationale: a stolen-session attacker who rotates
     * the PIN should not do so silently. Inline message bodies
     * (no new template files) so this PR doesn't churn the
     * email-template surface; future PRs can add HTML templates
     * without touching the call sites.
     */
    private static function notify($user_id, $event, array $context = []) {
        $user = get_userdata((int) $user_id);
        if (!$user) {
            return;
        }
        $site = get_bloginfo('name');
        $time = current_time('mysql');
        $ip   = Matrix_MLM_Rate_Limiter::client_ip();
        $ua   = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

        switch ($event) {
            case 'set':
                $subject = sprintf(__('[%s] Transaction PIN set on your account', 'matrix-mlm'), $site);
                $line    = __('You set a transaction PIN on your account.', 'matrix-mlm');
                break;
            case 'change':
                $subject = sprintf(__('[%s] Transaction PIN changed', 'matrix-mlm'), $site);
                $line    = __('Your transaction PIN was just changed.', 'matrix-mlm');
                break;
            case 'disable':
                $subject = sprintf(__('[%s] Transaction PIN disabled', 'matrix-mlm'), $site);
                $line    = __('Your transaction PIN was just disabled. Fund-movement actions configured to require it will fall through ungated until you set a new PIN.', 'matrix-mlm');
                break;
            case 'forgot':
                $subject = sprintf(__('[%s] Transaction PIN reset via Forgot PIN', 'matrix-mlm'), $site);
                $line    = __('Your transaction PIN was reset via the Forgot PIN flow. You will need to set a new PIN before fund-movement actions configured to require one will succeed.', 'matrix-mlm');
                break;
            case 'locked':
                $subject = sprintf(__('[%s] Transaction PIN locked', 'matrix-mlm'), $site);
                $line    = __('Your transaction PIN has been locked after too many wrong attempts. Use "Forgot PIN" on the Security tab to reset it.', 'matrix-mlm');
                break;
            default:
                return;
        }

        $body  = $line . "\n\n";
        $body .= sprintf(__('Time: %s', 'matrix-mlm'), $time) . "\n";
        if ($ip !== '') {
            $body .= sprintf(__('IP: %s', 'matrix-mlm'), $ip) . "\n";
        }
        if ($ua !== '') {
            $body .= sprintf(__('Browser: %s', 'matrix-mlm'), $ua) . "\n";
        }
        if (!empty($context['unlock_at'])) {
            $body .= sprintf(__('Locked until: %s', 'matrix-mlm'), $context['unlock_at']) . "\n";
        }
        $body .= "\n" . __('If this was not you, change your account password immediately and contact support.', 'matrix-mlm');

        wp_mail($user->user_email, $subject, $body);
    }
}
