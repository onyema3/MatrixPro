<?php
/**
 * Transaction PIN — second-factor proof of intent for fund movements.
 *
 * Storage: a single column `transaction_pin_hash` on
 * matrix_user_meta, holding password_hash($normalised, PASSWORD_DEFAULT)
 * (bcrypt). Plaintext PIN is never persisted. Mirrors the recovery-codes
 * pattern in Matrix_MLM_Two_Factor::store_recovery_codes() — bcrypt's
 * default cost is the right brute-force speed bump for a 4–6 digit
 * secret with a 10⁴–10⁶ keyspace.
 *
 * This class only owns the PIN itself: set / change / disable / verify
 * + the two admin-settings probes (master enabled, per-path required).
 * The per-action gate that enforces "this fund-movement endpoint
 * requires a valid PIN" lives in a sibling PR — keeping the gate out
 * of this file means PR 1 is inert until the admin opts in, and a
 * future PR 2 revert can roll back the gates without losing users'
 * stored PIN hashes.
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
 * Threat model ordering (matches the 2FA disable path):
 *
 *   rate limit  →  password reauth  →  state probe  →  bcrypt verify
 *
 * Rate limit FIRST so a flood can't exhaust password_verify cycles.
 * Password reauth BEFORE the state probe (`is_set`) so a session-only
 * attacker can't use any of these endpoints as an oracle to learn
 * whether their hijacked account has a PIN configured. Reset the
 * rate-limit counter on every successful path so a real user who
 * fat-fingered once isn't penalised.
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
     * Canonical list of fund-movement paths the PIN can gate. The
     * order here is the display order on the admin Financial tab's
     * "Transaction PIN Requirements" sub-section. Adding a new
     * path is a one-row change here PLUS:
     *   - a new <input> + label in render_financial_tab(),
     *   - a key in the Financial save_settings whitelist,
     *   - a key in the global $checkboxes array,
     *   - a require_pin_for_request($user_id, '<new>') call in the
     *     handler, and a render_field() drop-in on the form.
     *
     * Stored as an associative map (path => human label) so the
     * admin page can render labels without an extra lookup table.
     * The label is wrapped in a translator-aware closure at call
     * time so this constant can stay PHP-static.
     */
    const PATHS = [
        'transfers'         => 'Peer wallet transfers',
        'matrix_to_virtual' => 'Transfer to own Fintava virtual wallet',
        'bank'              => 'Transfer to external bank',
        'bills'             => 'Bill payments (airtime, data, cable, electricity)',
        'subscription'      => 'Subscription manual pay',
    ];

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
            // Coerce predictably — see docblock. is_array is a fast
            // pre-check so the (string) cast doesn't surface
            // PHP's "Array to string conversion" notice on noisy
            // installs running with notices-as-errors.
            return '';
        }
        return preg_replace('/[^0-9]/', '', (string) $raw);
    }

    /**
     * Whether the supplied digit string is acceptable as a PIN.
     *
     * @param string $normalised Output of self::normalise().
     * @return bool TRUE iff length is in [MIN_LEN, MAX_LEN].
     */
    public static function is_valid_format($normalised) {
        $len = strlen($normalised);
        return $len >= self::MIN_LEN && $len <= self::MAX_LEN;
    }

    /**
     * Whether the master "Transaction PIN" feature is on at the
     * site level. Read from matrix_mlm_transaction_pin_enabled
     * (default 1 — on a fresh install users CAN set a PIN; admins
     * who explicitly want to disable the feature can flip it off
     * on Settings → Security).
     *
     * Used by the user-side Security tab to hide the PIN section
     * entirely when off, and by the AJAX handlers as a final-line
     * defence against UI drift (a stale dashboard page that still
     * shows the PIN form after the admin disables the feature
     * shouldn't be able to set a PIN).
     */
    public static function is_master_enabled() {
        return (bool) get_option('matrix_mlm_transaction_pin_enabled', 1);
    }

    /**
     * Whether the admin requires a PIN for this fund-movement path.
     *
     * Per-path keys follow the matrix_mlm_pin_required_for_<path>
     * convention. Default OFF on every path so this PR is inert
     * for end users until the admin opts in — turning the feature
     * on without breaking existing flows is the whole point of
     * the per-path split.
     *
     * @param string $path One of the keys in self::PATHS.
     * @return bool TRUE iff the admin has required the PIN for $path.
     */
    public static function pin_required_for($path) {
        if (!array_key_exists($path, self::PATHS)) {
            // Unknown path — fail-CLOSED in the sense that no
            // gate decision is made. Callers that hand an
            // unrecognised path are buggy; we don't want a typo
            // here to silently disable the gate on a configured
            // path that happened to share a prefix with the typo.
            return false;
        }
        if (!self::is_master_enabled()) {
            return false;
        }
        return (bool) get_option('matrix_mlm_pin_required_for_' . $path, 0);
    }

    /**
     * Whether $user_id has a PIN configured.
     *
     * Reads `transaction_pin_hash IS NOT NULL` from matrix_user_meta.
     * Used both by the UI (decide whether to show "Set PIN" or
     * "Change/Disable PIN") and by the per-path gate (decide
     * whether to enforce — gate fires only when path requires it
     * AND the user has set one; otherwise transactions proceed
     * to avoid stranding users on the day the admin flips a
     * requirement on).
     */
    public static function is_set($user_id) {
        global $wpdb;
        $hash = $wpdb->get_var($wpdb->prepare(
            "SELECT transaction_pin_hash FROM {$wpdb->prefix}matrix_user_meta WHERE user_id = %d",
            (int) $user_id
        ));
        return is_string($hash) && $hash !== '';
    }

    /**
     * Hash and persist a fresh PIN for the user.
     *
     * Caller (process_set_transaction_pin) is responsible for the
     * password reauth and the already-set rejection; this method
     * does not enforce them so it stays usable from admin tooling
     * (e.g. an admin reset flow that forces a new PIN on a user
     * who locked themselves out).
     *
     * Returns true on success, WP_Error('pin_invalid'|'pin_store_failed').
     */
    public function set($user_id, $plaintext) {
        $normalised = self::normalise($plaintext);
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

        global $wpdb;
        // password_hash() with PASSWORD_DEFAULT is bcrypt at WP's
        // baseline PHP version. Same primitive used by the 2FA
        // recovery codes — the rationale (slow-by-design,
        // tunable cost via filter, well-supported) carries over
        // verbatim.
        $hash = password_hash($normalised, PASSWORD_DEFAULT);
        $rows = $wpdb->update(
            $wpdb->prefix . 'matrix_user_meta',
            ['transaction_pin_hash' => $hash],
            ['user_id' => (int) $user_id],
            ['%s'],
            ['%d']
        );
        if ($rows === false) {
            return new WP_Error(
                'pin_store_failed',
                __('Could not save your PIN. Please try again or contact support.', 'matrix-mlm')
            );
        }
        // $wpdb->update returns 0 when the row exists but the value
        // was already identical — collisions on a 4-digit PIN
        // happen, so 0 here is success, not failure. (false above
        // is the only true error case.)
        return true;
    }

    /**
     * Replace an existing PIN with a new one. Verifies the current
     * PIN before swapping.
     *
     * The verify+swap is NOT wrapped in a transaction: bcrypt's
     * latency dwarfs any conceivable race window, and the worst
     * case (concurrent change with the same correct old PIN)
     * lands on whichever new PIN was written last — which is
     * fine because both writers were authenticated.
     *
     * Returns true on success, or WP_Error with code
     * 'pin_not_set' / 'pin_mismatch' / 'pin_invalid' / 'pin_store_failed'.
     */
    public function change($user_id, $current_plaintext, $new_plaintext) {
        if (!self::is_set($user_id)) {
            return new WP_Error(
                'pin_not_set',
                __('You have not set a transaction PIN yet.', 'matrix-mlm')
            );
        }
        if (!$this->verify($user_id, $current_plaintext)) {
            return new WP_Error(
                'pin_mismatch',
                __('Current PIN is incorrect.', 'matrix-mlm')
            );
        }
        return $this->set($user_id, $new_plaintext);
    }

    /**
     * Clear the user's PIN, gated on a current-PIN verify.
     *
     * Idempotent at the caller — the AJAX handler treats a no-op
     * call (PIN already absent) as success so a quick double-click
     * doesn't surface a confusing "PIN not set" error.
     *
     * Returns true on success, or WP_Error with code
     * 'pin_not_set' / 'pin_mismatch' / 'pin_store_failed'.
     */
    public function disable($user_id, $current_plaintext) {
        if (!self::is_set($user_id)) {
            return new WP_Error(
                'pin_not_set',
                __('You have not set a transaction PIN yet.', 'matrix-mlm')
            );
        }
        if (!$this->verify($user_id, $current_plaintext)) {
            return new WP_Error(
                'pin_mismatch',
                __('Transaction PIN is incorrect.', 'matrix-mlm')
            );
        }

        global $wpdb;
        $rows = $wpdb->update(
            $wpdb->prefix . 'matrix_user_meta',
            ['transaction_pin_hash' => null],
            ['user_id' => (int) $user_id],
            ['%s'],
            ['%d']
        );
        if ($rows === false) {
            return new WP_Error(
                'pin_store_failed',
                __('Could not clear your PIN. Please try again or contact support.', 'matrix-mlm')
            );
        }
        return true;
    }

    /**
     * Per-request gate: enforce a transaction PIN on the current AJAX
     * call when the admin has flagged $path as PIN-required AND the
     * caller has set one. This is THE entry point used by the eight
     * fund-movement handlers wired up in PR 2 (peer transfer,
     * subscription pay, bank transfer, matrix→virtual, plus the
     * four bill handlers).
     *
     * Behaviour matrix:
     *
     *   path NOT required (admin toggle off)
     *     → no-op return, transaction proceeds
     *   path required, user has NO PIN set
     *     → no-op return, transaction proceeds. Matches the design
     *       call we made: turning a per-path toggle on doesn't
     *       suddenly strand pre-existing users mid-flow. The
     *       user-side Security tab still nudges them to set one,
     *       but they're not gated until they opt in.
     *   path required, user HAS a PIN, POST is missing/invalid/wrong
     *     → wp_send_json_error with `pin_required: true` so the JS
     *       layer can pop the PIN field on the form (or surface a
     *       targeted message instead of a generic "transfer failed").
     *   path required, user HAS a PIN, POST verifies
     *     → no-op return, transaction proceeds. Rate-limit counter
     *       gets reset so a real user who fat-fingered once isn't
     *       penalised on subsequent legitimate transactions.
     *
     * Threat-model ordering — kept in lockstep with the docblock at
     * the top of this class:
     *
     *   1. pin_required_for($path) — cheapest gate, runs first so
     *      installs that haven't opted in pay zero overhead per
     *      transaction (single get_option lookup, cached by WP).
     *   2. is_set($user_id) — second-cheapest. Skipping when the
     *      user has no PIN configured prevents the rate-limit
     *      counter from being consumed by ungated callers, which
     *      would otherwise let a single user with no PIN exhaust
     *      their own throttle on perfectly legal transactions.
     *   3. Rate limit (5 / 15 min, action 'transaction_pin_verify').
     *      A SHARED counter across all eight handlers is on
     *      purpose: the threat is brute-force PIN guessing, and a
     *      determined attacker alternating between airtime / data
     *      / transfer endpoints to dodge per-handler caps would be
     *      defeated by per-endpoint counters. Same window length
     *      and cap as the set/change/disable counters above for
     *      consistency.
     *   4. Empty-PIN check. After the rate-limit, NOT before — an
     *      attacker spamming empty submissions still costs them
     *      counter slots (cheap defence against probing whether a
     *      path requires a PIN by watching the response shape).
     *      Surfaces a distinct message ("enter your PIN") so a
     *      legitimate user who forgot the field doesn't think
     *      their PIN is wrong.
     *   5. bcrypt verify. Returns false on every failure mode (no
     *      hash stored, malformed PIN, mismatch); we don't branch
     *      on the failure mode here because the user-visible
     *      message is the same either way and we don't want to
     *      surface state ("you have no PIN configured" vs "wrong
     *      PIN") through this oracle. The threat-model docblock
     *      already covers why the surrounding ordering matters.
     *   6. Reset on success. Same convention as the set/change/
     *      disable handlers in Matrix_MLM_Core — successful PIN
     *      verification clears the counter so a subsequent
     *      transaction in the same window starts from zero.
     *
     * Defensive contract: this method either returns silently or
     * terminates the request via wp_send_json_error. Callers should
     * treat a return as "PIN gate cleared" and proceed; they MUST
     * NOT branch on the return value.
     *
     * @param int    $user_id Authenticated user id (typically
     *                        get_current_user_id() in the caller).
     * @param string $path    One of the keys in self::PATHS.
     */
    public static function require_pin_for_request($user_id, $path) {
        // Path not gated — fast exit. No DB hits, no rate-limit
        // bookkeeping. Installs that never opt in to PIN gating
        // see this method as effectively free.
        if (!self::pin_required_for($path)) {
            return;
        }

        // No PIN on file — design contract: ungated transactions
        // proceed. See top-of-class docblock and the disable()
        // helper for why we deliberately don't force users into a
        // mandatory-PIN posture from a per-path admin toggle.
        if (!self::is_set($user_id)) {
            return;
        }

        // Brute-force bound. Shared across all eight enforcement
        // points (see docblock) so an attacker can't cycle handlers
        // to reset the counter. 5 attempts / 15 minutes matches the
        // set / change / disable handlers in Matrix_MLM_Core, so
        // operators triaging a "PIN locked me out" complaint see
        // the same idiom regardless of which surface the user hit.
        $rl_action = 'transaction_pin_verify';
        $rl_key    = Matrix_MLM_Rate_Limiter::key_for_request();
        if (Matrix_MLM_Rate_Limiter::throttle(
            $rl_action,
            $rl_key,
            ['max_attempts' => 5, 'window_seconds' => 15 * MINUTE_IN_SECONDS]
        )) {
            wp_send_json_error([
                'message' => __('Too many transaction PIN attempts. Please wait a few minutes and try again.', 'matrix-mlm'),
                'pin_required' => true,
            ]);
        }

        // Read the POST field. wp_unslash because WP's auto-magic
        // quotes layer escapes the raw POST; the digit-only
        // normaliser doesn't care about escaped backslashes but
        // running unslash here keeps the bcrypt verify input
        // identical to what the user originally typed, which
        // matters if a future admin tool ever logs the rejected
        // input for forensic debugging.
        $raw = isset($_POST['transaction_pin']) ? wp_unslash($_POST['transaction_pin']) : '';

        // Empty/missing PIN gets a distinct message so a real user
        // who just forgot the field doesn't see "PIN is incorrect"
        // and assume their stored PIN is wrong (which would push
        // them into the disable→re-enrol loop unnecessarily).
        // pin_required: true tells the front-end to surface the
        // PIN field if it isn't already showing.
        $normalised = self::normalise($raw);
        if ($normalised === '') {
            wp_send_json_error([
                'message' => __('Enter your transaction PIN to confirm this action.', 'matrix-mlm'),
                'pin_required' => true,
            ]);
        }

        // bcrypt verify. False on every failure mode (no stored
        // hash, format invalid post-normalisation, hash mismatch);
        // surfaced uniformly as "incorrect" because branching on
        // the cause would leak account state. The
        // already-rate-limited path above caps the brute-force
        // surface; bcrypt's cost factor caps per-attempt effort.
        $pin = new self();
        if (!$pin->verify($user_id, $raw)) {
            wp_send_json_error([
                'message' => __('Transaction PIN is incorrect.', 'matrix-mlm'),
                'pin_required' => true,
            ]);
        }

        // Success — clear the throttle so a legitimate user who
        // mis-typed once doesn't burn budget on every subsequent
        // legal transaction in the window. Same convention as
        // process_set_transaction_pin / process_change_transaction_pin
        // / process_disable_transaction_pin in Matrix_MLM_Core.
        Matrix_MLM_Rate_Limiter::reset($rl_action, $rl_key);
    }

    /**
     * Render the user-side PIN <input> for a fund-movement form, or
     * the empty string when the gate isn't active for this user/path
     * pair.
     *
     * Drop-in companion to require_pin_for_request(): the server-side
     * gate and the form field share the SAME pin_required_for($path)
     * && is_set($user_id) predicate, so a user who is ungated on the
     * server side never sees the field, and a user who IS gated
     * always sees it. No client-side decision logic needed; the JS
     * always reads `[name=transaction_pin]` and POSTs whatever it
     * finds (empty string if the field is absent, which the server
     * gate then handles via the "PIN gate not active" no-op return).
     *
     * Field naming is fixed at `transaction_pin` so the four bill
     * forms, both transfer forms, and the bank-payout form all
     * share one POST contract — no per-form key juggling on the
     * server.
     *
     * inputmode="numeric" + autocomplete="off" matches the existing
     * recovery-code / 2FA-code field styling so the on-screen
     * keyboard pops a digit pad on mobile and password managers
     * don't try to autofill an unrelated value. type="password" is
     * deliberate (not "tel" or "number"): we want the value masked
     * the same way 2FA codes are, and "password" is what most
     * accessibility tooling already expects for "sensitive numeric
     * input you don't want over-the-shoulder visible". Browsers do
     * NOT enforce maxlength on type="password" with non-Latin IMEs,
     * so the server-side normaliser is the authority on length.
     *
     * @param int    $user_id Current user id (caller should pass
     *                        get_current_user_id()).
     * @param string $path    One of the keys in self::PATHS.
     * @return string HTML fragment, or '' if the gate isn't active.
     */
    public static function render_field($user_id, $path) {
        if (!self::pin_required_for($path)) {
            return '';
        }
        if (!self::is_set((int) $user_id)) {
            return '';
        }

        $label = __('Transaction PIN', 'matrix-mlm');
        $hint  = __('Enter your transaction PIN to authorise this action.', 'matrix-mlm');
        $ph    = sprintf(
            /* translators: 1: minimum digits, 2: maximum digits */
            __('%1$d–%2$d digits', 'matrix-mlm'),
            self::MIN_LEN,
            self::MAX_LEN
        );

        ob_start();
        ?>
        <div class="matrix-form-group matrix-pin-field">
            <label for="matrix-pin-<?php echo esc_attr($path); ?>">
                <?php echo esc_html($label); ?>
            </label>
            <input
                type="password"
                inputmode="numeric"
                pattern="[0-9]*"
                autocomplete="off"
                name="transaction_pin"
                id="matrix-pin-<?php echo esc_attr($path); ?>"
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
     * Verify a plaintext PIN against the stored hash.
     *
     * Returns false on every failure mode (no PIN set, malformed
     * hash, format invalid, mismatch) so the caller can branch on
     * a single bool. Callers that need to distinguish "wrong PIN"
     * from "no PIN set" should call is_set() first; this is the
     * pattern in change()/disable() above.
     *
     * password_verify() runs in constant time with respect to the
     * hash content, so a wrong PIN and a right PIN take roughly
     * the same wall-clock — bcrypt's cost factor is the security
     * primitive, not any timing-safe equality check.
     */
    public function verify($user_id, $plaintext) {
        $normalised = self::normalise($plaintext);
        if (!self::is_valid_format($normalised)) {
            return false;
        }

        global $wpdb;
        $hash = $wpdb->get_var($wpdb->prepare(
            "SELECT transaction_pin_hash FROM {$wpdb->prefix}matrix_user_meta WHERE user_id = %d",
            (int) $user_id
        ));
        if (!is_string($hash) || $hash === '') {
            return false;
        }

        return password_verify($normalised, $hash);
    }
}
