<?php
/**
 * Wallet Management
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Wallet {

    /**
     * Get user balance
     */
    public function get_balance($user_id) {
        global $wpdb;
        $balance = $wpdb->get_var($wpdb->prepare(
            "SELECT balance FROM {$wpdb->prefix}matrix_user_meta WHERE user_id = %d",
            $user_id
        ));
        return floatval($balance ?? 0);
    }

    /**
     * Credit user wallet.
     *
     * Returns the new balance on success, or `false` if the underlying
     * persistence step failed. Two failure modes are covered explicitly:
     *
     *   1. The matrix_user_meta UPDATE returned false (SQL error) or 0
     *      (no row matched — i.e. the user has no matrix_user_meta row
     *      yet). In both cases the displayed balance hasn't actually
     *      persisted, so it would be wrong to log the credit row or
     *      tell the caller "all done".
     *
     *   2. The matrix_wallet INSERT returned false. The displayed
     *      balance has updated but the audit-trail row is missing, so
     *      the caller still has a recovery decision to make.
     *
     * The previous revision ignored both return values and always
     * returned the new balance, which is what allowed the legacy
     * process_transfer() flow to silently no-op on a missing
     * matrix_user_meta row but still report "Transfer completed
     * successfully" to the user. The wallet-to-wallet bug report on
     * the consolidated Wallet page (sender saw the success alert and
     * a page-reload, but their Matrix balance and transaction history
     * were unchanged) traces directly back to here.
     */
    public function credit($user_id, $amount, $transaction_type, $description = '', $reference = null) {
        global $wpdb;

        $current_balance = $this->get_balance($user_id);
        $new_balance = $current_balance + $amount;

        // Update balance. $wpdb->update returns:
        //   - integer (rows affected) on success — typically 1, but 0
        //     means the WHERE clause matched nothing (e.g. missing
        //     matrix_user_meta row).
        //   - false on SQL error.
        // Both 0 and false mean the balance hasn't actually persisted.
        $updated = $wpdb->update(
            $wpdb->prefix . 'matrix_user_meta',
            ['balance' => $new_balance],
            ['user_id' => $user_id]
        );
        if ($updated === false || $updated === 0) {
            return false;
        }

        // Record transaction
        $logged = $wpdb->insert($wpdb->prefix . 'matrix_wallet', [
            'user_id' => $user_id,
            'amount' => $amount,
            'post_balance' => $new_balance,
            'type' => 'credit',
            'transaction_type' => $transaction_type,
            'description' => $description,
            'reference' => $reference,
            'status' => 'completed'
        ]);
        if ($logged === false) {
            return false;
        }

        return $new_balance;
    }

    /**
     * Debit user wallet.
     *
     * Returns the new balance on success, or `false` if the operation
     * could not be persisted. Same return-value contract as credit() —
     * see that docblock for the rationale and failure modes covered.
     */
    public function debit($user_id, $amount, $transaction_type, $description = '', $reference = null) {
        global $wpdb;

        $current_balance = $this->get_balance($user_id);
        if ($current_balance < $amount) {
            return false;
        }

        $new_balance = $current_balance - $amount;

        // Update balance. See credit() for why we treat both false and
        // 0 as failure here — a 0 rows-affected response means the
        // matrix_user_meta row didn't exist, so the new balance never
        // actually persisted and we mustn't pretend otherwise.
        $updated = $wpdb->update(
            $wpdb->prefix . 'matrix_user_meta',
            ['balance' => $new_balance],
            ['user_id' => $user_id]
        );
        if ($updated === false || $updated === 0) {
            return false;
        }

        // Record transaction
        $logged = $wpdb->insert($wpdb->prefix . 'matrix_wallet', [
            'user_id' => $user_id,
            'amount' => $amount,
            'post_balance' => $new_balance,
            'type' => 'debit',
            'transaction_type' => $transaction_type,
            'description' => $description,
            'reference' => $reference,
            'status' => 'completed'
        ]);
        if ($logged === false) {
            return false;
        }

        return $new_balance;
    }

    /**
     * Get transaction history
     */
    public function get_transactions($user_id, $limit = 20, $offset = 0, $type = null) {
        global $wpdb;

        $where = "WHERE user_id = %d";
        $params = [$user_id];

        if ($type) {
            $where .= " AND type = %s";
            $params[] = $type;
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_wallet $where ORDER BY created_at DESC LIMIT %d OFFSET %d",
            array_merge($params, [$limit, $offset])
        ));

        return $results;
    }

    /**
     * Get total transactions count
     */
    public function get_transactions_count($user_id, $type = null) {
        global $wpdb;

        if ($type) {
            return $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}matrix_wallet WHERE user_id = %d AND type = %s",
                $user_id, $type
            ));
        }

        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}matrix_wallet WHERE user_id = %d",
            $user_id
        ));
    }

    /**
     * Get total earnings
     */
    public function get_total_earnings($user_id) {
        global $wpdb;
        return floatval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_wallet WHERE user_id = %d AND type = 'credit'",
            $user_id
        )));
    }

    /**
     * Get total withdrawals
     */
    public function get_total_withdrawals($user_id) {
        global $wpdb;
        return floatval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_withdrawals WHERE user_id = %d AND status = 'approved'",
            $user_id
        )));
    }
}
