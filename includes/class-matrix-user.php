<?php
/**
 * User Management
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_User {

    /**
     * Get user matrix meta
     */
    public static function get_meta($user_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_user_meta WHERE user_id = %d",
            $user_id
        ));
    }

    /**
     * Get user referral code
     */
    public static function get_referral_code($user_id) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT referral_code FROM {$wpdb->prefix}matrix_user_meta WHERE user_id = %d",
            $user_id
        ));
    }

    /**
     * Generate a referral code that's guaranteed to be unique across
     * matrix_user_meta. Used by registration, the Laravel importer, and
     * the admin "Backfill Referral Codes" tool so all three paths share
     * one algorithm and one uniqueness story.
     *
     * Algorithm matches the historical registration helper:
     *   strtoupper(substr(md5($seed), 0, 8))
     *
     * The seed mixes user_id, microseconds, and a random component so
     * that bulk callers (the importer / backfill loop) don't collide
     * even when invoked in a tight loop within the same second. The
     * outer loop retries on collision against the UNIQUE index on
     * matrix_user_meta.referral_code, falling back to a 12-char code
     * after enough collisions to keep the loop bounded.
     */
    public static function generate_unique_referral_code($user_id = 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_user_meta';

        for ($attempt = 0; $attempt < 50; $attempt++) {
            // After 25 attempts at 8 chars, widen to 12 chars. In practice
            // we never get past the first attempt — the loop is here to
            // make the function provably terminate even on a pathological
            // dataset where the 8-char space is exhausted.
            $length = $attempt < 25 ? 8 : 12;
            $seed   = $user_id . '-' . microtime(true) . '-' . wp_generate_password(12, false, false);
            $code   = strtoupper(substr(md5($seed), 0, $length));

            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE referral_code = %s",
                $code
            ));
            if ($exists === 0) {
                return $code;
            }
        }

        // Should never be reached, but if it is, fall back to a UUID-like
        // suffix so the caller still gets a unique value rather than null.
        return 'REF-' . strtoupper(wp_generate_password(10, false, false));
    }

    /**
     * Get referral link
     */
    public static function get_referral_link($user_id) {
        $code = self::get_referral_code($user_id);
        return home_url('/matrix/?ref=' . $code);
    }

    /**
     * Get direct referrals
     */
    public static function get_referrals($user_id, $limit = 20, $offset = 0) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT um.*, u.user_login, u.user_email, u.user_registered 
             FROM {$wpdb->prefix}matrix_user_meta um 
             LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID 
             WHERE um.referred_by = %d 
             ORDER BY um.created_at DESC 
             LIMIT %d OFFSET %d",
            $user_id, $limit, $offset
        ));
    }

    /**
     * Get referral count
     */
    public static function get_referral_count($user_id) {
        global $wpdb;
        return intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}matrix_user_meta WHERE referred_by = %d",
            $user_id
        )));
    }

    /**
     * Get total referral earnings
     */
    public static function get_referral_earnings($user_id) {
        global $wpdb;
        return floatval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_commissions WHERE user_id = %d AND type = 'referral'",
            $user_id
        )));
    }

    /**
     * Get level commissions
     */
    public static function get_level_commissions($user_id, $limit = 50, $offset = 0) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, u.user_login as from_username, p.name as plan_name 
             FROM {$wpdb->prefix}matrix_commissions c 
             LEFT JOIN {$wpdb->users} u ON c.from_user_id = u.ID 
             LEFT JOIN {$wpdb->prefix}matrix_plans p ON c.plan_id = p.id 
             WHERE c.user_id = %d AND c.type = 'level' 
             ORDER BY c.created_at DESC 
             LIMIT %d OFFSET %d",
            $user_id, $limit, $offset
        ));
    }

    /**
     * Get total level earnings
     */
    public static function get_level_earnings($user_id) {
        global $wpdb;
        return floatval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_commissions WHERE user_id = %d AND type = 'level'",
            $user_id
        )));
    }

    /**
     * Get user active plans
     */
    public static function get_active_plans($user_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, pos.joined_at, pos.total_downline, pos.status as position_status 
             FROM {$wpdb->prefix}matrix_positions pos 
             LEFT JOIN {$wpdb->prefix}matrix_plans p ON pos.plan_id = p.id 
             WHERE pos.user_id = %d AND pos.status = 'active'",
            $user_id
        ));
    }

    /**
     * Ban user
     */
    public static function ban($user_id) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'matrix_user_meta',
            ['status' => 'banned'],
            ['user_id' => $user_id]
        );
    }

    /**
     * Unban user
     */
    public static function unban($user_id) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'matrix_user_meta',
            ['status' => 'active'],
            ['user_id' => $user_id]
        );
    }

    /**
     * Check if user is active
     */
    public static function is_active($user_id) {
        global $wpdb;
        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}matrix_user_meta WHERE user_id = %d",
            $user_id
        ));
        return $status === 'active';
    }

    /**
     * Determine whether a user is currently allowed to move funds out
     * of their Matrix wallet on a given path.
     *
     * Single source of truth for every fund-movement surface the admin
     * wants to gate. Five toggles, evaluated in order:
     *
     *   1. matrix_mlm_withdrawals_enabled (default: 1)
     *      Master kill switch. Set to 0 to pause every fund-movement
     *      flow across the platform — useful during reconciliation,
     *      an ongoing incident with the payment gateway, or a freeze
     *      window before a re-import. Applies to every $path.
     *
     *   2. matrix_mlm_withdraw_require_active_user (default: 1)
     *      When on, only users whose matrix_user_meta.status is
     *      'active' can move funds. Closes the long-standing gap
     *      where a banned user could still hit the wallet-to-wallet
     *      and legacy /withdraw endpoints. Applies to every $path.
     *
     *   3. matrix_mlm_withdraw_required_plans (default: '')
     *      Comma-separated list of plan IDs. When non-empty, the
     *      user must have at least one ACTIVE position on at least
     *      one of the listed plans. Empty means no plan restriction.
     *      Applies to every $path — including peer wallet-to-wallet,
     *      to close the relay loophole where a non-qualifying user
     *      transfers their balance to an accomplice who does qualify
     *      and has them cash out.
     *
     *   4. matrix_mlm_matrix_transfers_enabled (default: 1)
     *      Path-specific toggle. Gates Matrix-wallet-sourced flows:
     *      peer wallet-to-wallet and Matrix → Fintava virtual.
     *      Applies only when $path === 'matrix_transfer'.
     *
     *   5. matrix_mlm_bank_transfers_enabled (default: read legacy
     *      matrix_mlm_fintava_payouts_enabled, then 1).
     *      Path-specific toggle. Gates the Fintava virtual → external
     *      bank flow. Applies only when $path === 'bank_transfer'.
     *      Reads the legacy key as a fallback so installs that haven't
     *      re-saved the Settings → Financial page since this option
     *      moved over from Gateways → Fintava don't lose their
     *      previous setting.
     *
     * The five toggles are intentionally separated:
     *
     *   - 5 (master) is the global red button.
     *   - 3 (active-account) and 2 (plan-tier) are user-eligibility
     *     gates that apply uniformly to every path so they cannot
     *     be circumvented by hopping through a peer.
     *   - 1 (matrix-transfers) and 4 (bank-transfers) are path-
     *     specific so admins can disable just one cash-out side
     *     without freezing the other.
     *
     * Returns an array with two keys:
     *   - allowed (bool): true if the user can move funds, else false.
     *   - reason  (string): empty when allowed, otherwise a user-
     *     facing localized message explaining which gate blocked
     *     the call. Caller is expected to surface this verbatim
     *     via wp_send_json_error.
     *
     * @param int    $user_id WP user id.
     * @param string $path    'matrix_transfer' (peer + Matrix→Fintava-
     *                        virtual) or 'bank_transfer' (Fintava→
     *                        external bank).
     * @return array{allowed: bool, reason: string}
     */
    public static function can_move_funds($user_id, $path) {
        $user_id = intval($user_id);
        if ($user_id <= 0) {
            return [
                'allowed' => false,
                'reason'  => __('You must be logged in to move funds.', 'matrix-mlm'),
            ];
        }

        if (!in_array($path, ['matrix_transfer', 'bank_transfer'], true)) {
            return [
                'allowed' => false,
                'reason'  => __('Unknown transfer type. Please refresh the page and try again.', 'matrix-mlm'),
            ];
        }

        // Gate 5: master kill switch.
        if (!(int) get_option('matrix_mlm_withdrawals_enabled', 1)) {
            return [
                'allowed' => false,
                'reason'  => __('Withdrawals are temporarily disabled by the administrator. Please try again later.', 'matrix-mlm'),
            ];
        }

        // Gate 3: active-account requirement.
        if ((int) get_option('matrix_mlm_withdraw_require_active_user', 1)) {
            if (!self::is_active($user_id)) {
                return [
                    'allowed' => false,
                    'reason'  => __('Your account is not active, so you cannot move funds. Please contact support if you believe this is in error.', 'matrix-mlm'),
                ];
            }
        }

        // Gate 2: required-plan tier. Stored as CSV rather than a
        // serialized array because the admin Settings save handler
        // unconditionally runs every field through update_option
        // without a per-field type map — keeping the value scalar
        // means it survives that path without bespoke handling, and
        // the multi-select UI on the financial tab joins/splits with
        // comma to match.
        //
        // Applied to peer wallet-to-wallet too, to close the relay
        // loophole: without this, a non-qualifying user could send
        // their balance to a qualifying accomplice and have the
        // accomplice cash out on their behalf.
        $required_csv = trim((string) get_option('matrix_mlm_withdraw_required_plans', ''));
        if ($required_csv !== '') {
            $required_ids = array_values(array_filter(array_map('intval', preg_split('/[,\s]+/', $required_csv))));
            if (!empty($required_ids)) {
                global $wpdb;
                $placeholders = implode(',', array_fill(0, count($required_ids), '%d'));
                $params = array_merge([$user_id], $required_ids);
                $has_position = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}matrix_positions
                      WHERE user_id = %d
                        AND status = 'active'
                        AND plan_id IN ($placeholders)",
                    $params
                ));
                if ($has_position === 0) {
                    return [
                        'allowed' => false,
                        'reason'  => __('Withdrawals are currently restricted to specific membership plans, and none of your active plans qualify. Please contact support.', 'matrix-mlm'),
                    ];
                }
            }
        }

        // Gate 1 / Gate 4: path-specific toggles.
        if ($path === 'matrix_transfer') {
            if (!(int) get_option('matrix_mlm_matrix_transfers_enabled', 1)) {
                return [
                    'allowed' => false,
                    'reason'  => __('Matrix wallet transfers are temporarily disabled by the administrator. Please try again later.', 'matrix-mlm'),
                ];
            }
        } else { // bank_transfer
            // Read the new key first; fall back to the legacy
            // matrix_mlm_fintava_payouts_enabled key for installs
            // that haven't re-saved the Settings → Financial page
            // since the toggle moved there from Gateways → Fintava.
            // Both default ON (1).
            $bank_raw = get_option('matrix_mlm_bank_transfers_enabled', null);
            if ($bank_raw === null || $bank_raw === false || $bank_raw === '') {
                $bank_raw = get_option('matrix_mlm_fintava_payouts_enabled', 1);
            }
            if (!(int) $bank_raw) {
                return [
                    'allowed' => false,
                    'reason'  => __('Bank transfers are temporarily disabled by the administrator. Please try again later.', 'matrix-mlm'),
                ];
            }
        }

        return ['allowed' => true, 'reason' => ''];
    }
}
