<?php
/**
 * E-Pin Management
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Epin {

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
            $pin_code = strtoupper('EP-' . substr(md5(uniqid(mt_rand(), true)), 0, 12));

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
     */
    public function redeem($user_id, $pin_code) {
        global $wpdb;

        $pin = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_epins WHERE pin_code = %s AND status = 'unused'",
            $pin_code
        ));

        if (!$pin) {
            return ['success' => false, 'message' => __('Invalid or already used pin', 'matrix-mlm')];
        }

        // Check expiry
        if ($pin->expires_at && strtotime($pin->expires_at) < time()) {
            $wpdb->update($wpdb->prefix . 'matrix_epins', ['status' => 'expired'], ['id' => $pin->id]);
            return ['success' => false, 'message' => __('Pin has expired', 'matrix-mlm')];
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
            // Another request claimed it between SELECT and UPDATE,
            // or the row no longer matches status='unused'.
            return ['success' => false, 'message' => __('Pin was already redeemed', 'matrix-mlm')];
        }

        // Pin is safely claimed. Credit the wallet using the canonical
        // pin code from the database so the wallet `reference` column
        // exactly matches `matrix_epins.pin_code` for joins later.
        $wallet = new Matrix_MLM_Wallet();
        $wallet->credit(
            $user_id,
            $pin->amount,
            'epin_recharge',
            sprintf(__('E-Pin recharge: %s', 'matrix-mlm'), $pin->pin_code),
            $pin->pin_code
        );

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
