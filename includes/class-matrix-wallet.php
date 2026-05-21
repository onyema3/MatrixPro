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
     * Credit user wallet
     */
    public function credit($user_id, $amount, $transaction_type, $description = '', $reference = null) {
        global $wpdb;

        $current_balance = $this->get_balance($user_id);
        $new_balance = $current_balance + $amount;

        // Update balance
        $wpdb->update(
            $wpdb->prefix . 'matrix_user_meta',
            ['balance' => $new_balance],
            ['user_id' => $user_id]
        );

        // Record transaction
        $wpdb->insert($wpdb->prefix . 'matrix_wallet', [
            'user_id' => $user_id,
            'amount' => $amount,
            'post_balance' => $new_balance,
            'type' => 'credit',
            'transaction_type' => $transaction_type,
            'description' => $description,
            'reference' => $reference,
            'status' => 'completed'
        ]);

        return $new_balance;
    }

    /**
     * Debit user wallet
     */
    public function debit($user_id, $amount, $transaction_type, $description = '', $reference = null) {
        global $wpdb;

        $current_balance = $this->get_balance($user_id);
        if ($current_balance < $amount) {
            return false;
        }

        $new_balance = $current_balance - $amount;

        // Update balance
        $wpdb->update(
            $wpdb->prefix . 'matrix_user_meta',
            ['balance' => $new_balance],
            ['user_id' => $user_id]
        );

        // Record transaction
        $wpdb->insert($wpdb->prefix . 'matrix_wallet', [
            'user_id' => $user_id,
            'amount' => $amount,
            'post_balance' => $new_balance,
            'type' => 'debit',
            'transaction_type' => $transaction_type,
            'description' => $description,
            'reference' => $reference,
            'status' => 'completed'
        ]);

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
