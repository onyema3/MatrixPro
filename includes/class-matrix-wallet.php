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
     * persistence step failed.
     *
     * Implementation: a single atomic SQL UPDATE with arithmetic
     * (`SET balance = balance + N`) instead of the previous
     * read-then-`$wpdb->update` pattern. This rewrite addresses three
     * concrete failure modes that surfaced as the "transfer reports
     * success but no money moves" bug on the consolidated Wallet page:
     *
     *   1. TOCTOU race. The previous flow read $current_balance via
     *      a SELECT and then issued a separate UPDATE that wrote
     *      $current_balance ± $amount. A concurrent debit/credit
     *      between those two queries (e.g., a withdrawal handler
     *      firing on the same user, or two transfers landing on the
     *      same recipient at once) would silently overwrite each
     *      other and one of the two amounts would be lost. SQL
     *      arithmetic (`balance + %f`) is computed by the server
     *      against the row at the moment of UPDATE, so the two
     *      writes serialize correctly.
     *
     *   2. Misreading $wpdb->update's return value. wpdb returns the
     *      number of *changed* rows (mysqli affected_rows without
     *      the FOUND_ROWS client flag), not the number of *matched*
     *      rows. With a SET to a precomputed PHP float, edge cases
     *      where the float collapsed back to the same DECIMAL(12,2)
     *      value (post-rounding parity, or 0+0 etc.) reported 0
     *      rows changed — which the previous check (`$updated ===
     *      0`) flagged as failure even though the row existed and
     *      was correct, causing a spurious ROLLBACK from the
     *      transfer envelope. Arithmetic UPDATE never has this
     *      problem: with $amount > 0, the new value always differs
     *      from the old, so 0 rows changed is reliably synonymous
     *      with "no row matched the WHERE clause".
     *
     *   3. PHP float precision. Bookkeeping math should happen in
     *      DECIMAL semantics, not PHP floats. Doing the addition in
     *      SQL preserves the column's full precision.
     *
     * On success, returns the post-credit balance read back from the
     * row (a fresh SELECT after the UPDATE) so the auditor row's
     * post_balance column matches what the user will see on their
     * next dashboard reload.
     */
    public function credit($user_id, $amount, $transaction_type, $description = '', $reference = null) {
        global $wpdb;

        $table = $wpdb->prefix . 'matrix_user_meta';

        // Atomic arithmetic UPDATE. SQL-side `balance + %f` avoids
        // the read-then-write TOCTOU race the previous revision had,
        // and with $amount > 0 the new value always differs from the
        // old so affected_rows == 0 is a reliable "no row matched"
        // signal (rather than the ambiguous "value unchanged" case
        // we used to mis-classify as failure).
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE $table SET balance = balance + %f WHERE user_id = %d",
            $amount, $user_id
        ));
        if ($result === false || $result === 0) {
            return false;
        }

        // Read back the persisted balance so the auditor row records
        // exactly what landed on disk (DECIMAL(12,2) precision, not
        // a PHP-float-rounded value).
        $new_balance = $this->get_balance($user_id);

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
     * could not be persisted. Same atomic-arithmetic-UPDATE rationale
     * as credit() — see that docblock for the three failure modes the
     * rewrite addresses.
     *
     * Additionally, debit folds the balance check into the WHERE
     * clause (`AND balance >= %f`). This means insufficient-balance
     * is no longer a separate read-then-decide step: the UPDATE
     * itself only fires when the row has the funds. With $amount > 0
     * the new value always differs from the old, so affected_rows
     * == 0 reliably means "either no row OR insufficient balance" —
     * either way a legitimate failure for the caller to surface.
     * This also closes the race window where a concurrent debit
     * between the previous read and write could let a user spend
     * more than they had on file.
     */
    public function debit($user_id, $amount, $transaction_type, $description = '', $reference = null) {
        global $wpdb;

        $table = $wpdb->prefix . 'matrix_user_meta';

        // Atomic conditional UPDATE: the balance check is folded into
        // the WHERE clause so a concurrent debit can't slip in
        // between a prior read and our write. $amount > 0 guarantees
        // the value changes when the WHERE matches, so affected_rows
        // == 0 unambiguously means "no row OR insufficient funds" —
        // both are legitimate failure paths the caller should surface
        // (the previous read-then-update pattern was the silent path
        // that produced "transfer reports success but no money moves"
        // when affected_rows came back 0 due to value-unchanged cases
        // the check mis-classified as failure).
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE $table SET balance = balance - %f WHERE user_id = %d AND balance >= %f",
            $amount, $user_id, $amount
        ));
        if ($result === false || $result === 0) {
            return false;
        }

        $new_balance = $this->get_balance($user_id);

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
