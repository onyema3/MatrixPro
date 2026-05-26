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
     * Implementation: a single atomic UPSERT on matrix_user_meta —
     * `INSERT (user_id, balance) VALUES (?, ?)
     *  ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance)`.
     *
     * The UPSERT pattern matters because credit() is called from many
     * places (epin redeem, deposit gateway callbacks, transfer-in,
     * commission payout, withdrawal refunds, admin manual credit,
     * Fintava webhook deposits, etc.) and several of those code paths
     * target users who may not yet have a matrix_user_meta row.
     * Anyone created via wp_create_user() outside the plugin's own
     * registration flow won't have one — admin-created accounts,
     * imports from another platform, members migrated from
     * WooCommerce, users seeded by a staging-DB clone that didn't
     * carry the meta table, etc. The previous read-then-update
     * pattern silently failed for those users (UPDATE matched zero
     * rows, credit() returned false, but several callers — epin
     * redeem in particular — didn't check the return value and
     * reported success). The "Recharge successful but my balance is
     * unchanged" report and the "Transfer successful but neither
     * sender nor recipient debited/credited" report both trace back
     * to credit/debit silently no-op'ing on missing rows.
     *
     * With UPSERT, the first credit auto-creates the row at the
     * credited amount, and subsequent credits increment it.
     * MySQL serializes concurrent UPSERTs against the same user_id
     * via the UNIQUE KEY (user_id) lock, so two credits racing on a
     * brand-new user can't both INSERT and lose one update — the
     * second one falls through to the ON DUPLICATE KEY branch and
     * adds correctly.
     *
     * Why not factor "create row if missing" into a separate helper
     * called before the UPDATE? Because that exact race condition
     * (two callers both deciding to INSERT, second one hits the
     * UNIQUE constraint) is what we'd be trying to avoid, and
     * solving it requires either application-level locking or a
     * single atomic statement. UPSERT is the single atomic
     * statement.
     *
     * Why not auto-create on debit too? Because debit on a missing
     * row should fail. You can't take money from a user who has no
     * balance row.
     *
     * After the UPSERT, the function reads the post-credit balance
     * back from the row (a fresh SELECT) and writes the
     * matrix_wallet auditor row at that exact value, so the
     * recharge/transfer history matches what the user will see on
     * their next dashboard reload.
     */
    public function credit($user_id, $amount, $transaction_type, $description = '', $reference = null) {
        global $wpdb;

        $table = $wpdb->prefix . 'matrix_user_meta';

        // Atomicity model (audit L4):
        //
        // The balance UPSERT and the post-balance read are wrapped in
        // a short InnoDB transaction so the auditor row's
        // post_balance reflects exactly the value persisted by THIS
        // call, not some later state mutated by a concurrent
        // credit/debit between our UPDATE and our read. The UPSERT
        // takes an exclusive row lock on (user_id) which is held
        // until COMMIT, so the SELECT inside the same transaction
        // sees our own write (read-your-writes) and no other
        // connection can interleave.
        //
        // The audit-row INSERT is intentionally OUTSIDE the
        // transaction — it preserves the existing semantics where a
        // failed audit insert returns false but does not roll back
        // the balance change (the caller's docblock contract: "if we
        // can't write the matrix_wallet row, we don't have a way to
        // reconcile this movement later" — same callers, same
        // behavior, just an atomic read for the value we record).
        //
        // Constraint: no caller in this plugin wraps credit() in its
        // own START TRANSACTION (verified at the time of writing).
        // If a future caller does, our START TRANSACTION here will
        // implicitly commit theirs in MySQL — at which point the
        // safer fix is for that caller to switch to a SAVEPOINT
        // around the credit() call.

        $wpdb->query('START TRANSACTION');

        // Atomic UPSERT. INSERT a new row at the credit amount if the
        // user has no matrix_user_meta row yet; otherwise add the
        // amount to the existing balance. INSERT...ON DUPLICATE KEY
        // UPDATE returns:
        //   - 1 if a new row was INSERTed
        //   - 2 if an existing row was UPDATEd (value changed)
        //   - 0 if an existing row was UPDATEd to the same value
        //     (impossible here because $amount > 0 always changes
        //     the balance)
        //   - false on SQL error
        // Anything >= 1 is success; only false signals a real
        // failure. The previous revision treated 0 as failure too,
        // which is wrong for INSERT...ON DUPLICATE KEY UPDATE.
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO $table (user_id, balance)
                  VALUES (%d, %f)
             ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance)",
            $user_id, $amount
        ));
        if ($result === false) {
            $wpdb->query('ROLLBACK');
            error_log(sprintf(
                '[Matrix MLM] credit() UPSERT failed: user_id=%d, amount=%s, last_error=%s',
                $user_id, $amount, $wpdb->last_error
            ));
            return false;
        }

        // Read back the persisted balance INSIDE the transaction.
        // The UPSERT above holds an X-lock on this row until COMMIT,
        // so the SELECT sees our own write and no concurrent
        // credit/debit can interleave.
        $new_balance_raw = $wpdb->get_var($wpdb->prepare(
            "SELECT balance FROM $table WHERE user_id = %d",
            $user_id
        ));

        $committed = $wpdb->query('COMMIT');
        if ($committed === false) {
            // COMMIT failures are rare (lock timeout, connection
            // interruption, etc.) and MySQL rolls the transaction
            // back automatically, so treat this as a balance-update
            // failure and surface it to the caller.
            error_log(sprintf(
                '[Matrix MLM] credit() COMMIT failed: user_id=%d, amount=%s, last_error=%s',
                $user_id, $amount, $wpdb->last_error
            ));
            return false;
        }

        $new_balance = (float) $new_balance_raw;

        // Auditor row. Treated as required: if we can't write the
        // matrix_wallet row, we don't have a way to reconcile this
        // movement later. The caller is expected to roll back the
        // surrounding transaction (or, for callers without a
        // transaction, alert support).
        //
        // transaction_type is coerced to '' on null because the column
        // is varchar(50) NOT NULL. Every current caller passes a
        // literal string ('plan_purchase', 'transfer_out', etc.), so
        // this is preventive: a future caller that forgot to populate
        // it would otherwise blow up the auditor INSERT here, the same
        // shape of bug that #129 fixed for matrix_fintava_payouts. See
        // the systemic guard in matrix-mlm.php (#130) for the reason
        // we still want this defensive coercion despite that guard
        // already preventing the JSON-corruption symptom.
        $logged = $wpdb->insert($wpdb->prefix . 'matrix_wallet', [
            'user_id' => $user_id,
            'amount' => $amount,
            'post_balance' => $new_balance,
            'type' => 'credit',
            'transaction_type' => $transaction_type ?? '',
            'description' => $description,
            'reference' => $reference,
            'status' => 'completed'
        ]);
        if ($logged === false) {
            error_log(sprintf(
                '[Matrix MLM] credit() auditor INSERT failed: user_id=%d, amount=%s, last_error=%s',
                $user_id, $amount, $wpdb->last_error
            ));
            return false;
        }

        return $new_balance;
    }

    /**
     * Debit user wallet.
     *
     * Returns the new balance on success, or `false` if the operation
     * could not be persisted. Unlike credit(), debit does NOT
     * auto-create a matrix_user_meta row — you can't take money from
     * a user who has no balance row, so a missing row is a real
     * failure that the caller must surface (the SQL UPDATE simply
     * matches no rows, affected_rows == 0, and we return false).
     *
     * Implementation: a single atomic conditional UPDATE
     * (`SET balance = balance - N WHERE user_id = ? AND balance >= N`).
     * The balance check is folded into the WHERE clause, so a
     * concurrent debit between any prior read and our write can't
     * let a user spend more than they had on file. With $amount > 0
     * the new value always differs from the old, so affected_rows
     * == 0 reliably means "no row OR insufficient balance" — both
     * legitimate failure paths.
     */
    public function debit($user_id, $amount, $transaction_type, $description = '', $reference = null) {
        global $wpdb;

        $table = $wpdb->prefix . 'matrix_user_meta';

        // Wrap the conditional UPDATE and the post-balance read in a
        // single InnoDB transaction so the auditor row's post_balance
        // reflects the exact value persisted by THIS call. See the
        // matching block in credit() for the full atomicity model
        // (audit L4); same constraints apply (no caller wraps debit()
        // in its own transaction at the time of writing).
        $wpdb->query('START TRANSACTION');

        // Atomic conditional UPDATE. The `AND balance >= %f` clause
        // means insufficient-balance is a single-statement decision,
        // not a separate read-then-decide step that a concurrent
        // debit could race past.
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE $table SET balance = balance - %f WHERE user_id = %d AND balance >= %f",
            $amount, $user_id, $amount
        ));
        if ($result === false) {
            $wpdb->query('ROLLBACK');
            error_log(sprintf(
                '[Matrix MLM] debit() UPDATE failed: user_id=%d, amount=%s, last_error=%s',
                $user_id, $amount, $wpdb->last_error
            ));
            return false;
        }
        if ($result === 0) {
            // Either no matrix_user_meta row for this user, or the
            // balance was insufficient. Either way the debit didn't
            // land, and the caller must surface the failure.
            $wpdb->query('ROLLBACK');
            error_log(sprintf(
                '[Matrix MLM] debit() affected zero rows (no row OR insufficient balance): user_id=%d, amount=%s',
                $user_id, $amount
            ));
            return false;
        }

        // Read back the persisted balance INSIDE the transaction.
        // The UPDATE above holds an X-lock on this row until COMMIT,
        // so the SELECT sees our own write and no concurrent
        // credit/debit can interleave.
        $new_balance_raw = $wpdb->get_var($wpdb->prepare(
            "SELECT balance FROM $table WHERE user_id = %d",
            $user_id
        ));

        $committed = $wpdb->query('COMMIT');
        if ($committed === false) {
            // COMMIT failures are rare (lock timeout, connection
            // interruption, etc.) and MySQL rolls the transaction
            // back automatically, so treat this as a balance-update
            // failure and surface it to the caller.
            error_log(sprintf(
                '[Matrix MLM] debit() COMMIT failed: user_id=%d, amount=%s, last_error=%s',
                $user_id, $amount, $wpdb->last_error
            ));
            return false;
        }

        $new_balance = (float) $new_balance_raw;

        // Auditor row for the debit. transaction_type coerced to ''
        // on null for the same reason as the credit() insert above —
        // see that comment for the full rationale.
        $logged = $wpdb->insert($wpdb->prefix . 'matrix_wallet', [
            'user_id' => $user_id,
            'amount' => $amount,
            'post_balance' => $new_balance,
            'type' => 'debit',
            'transaction_type' => $transaction_type ?? '',
            'description' => $description,
            'reference' => $reference,
            'status' => 'completed'
        ]);
        if ($logged === false) {
            error_log(sprintf(
                '[Matrix MLM] debit() auditor INSERT failed: user_id=%d, amount=%s, last_error=%s',
                $user_id, $amount, $wpdb->last_error
            ));
            return false;
        }

        return $new_balance;
    }

    /**
     * Append a ledger row to matrix_wallet WITHOUT touching the
     * matrix_user_meta balance.
     *
     * Use this for movements that happen on a wallet OTHER than the
     * Matrix internal wallet — the user's Fintava virtual wallet,
     * external bank payouts sourced from that virtual wallet, bill
     * purchases / refunds whose actual cash leg is a Fintava
     * wallet-to-wallet transfer, etc. These movements never touch
     * matrix_user_meta.balance, so calling credit()/debit() on them
     * would mis-mutate the Matrix wallet AND mis-stamp post_balance
     * against the wrong wallet. record_ledger() is the inverse of
     * that mistake: write the audit row, leave the Matrix balance
     * alone.
     *
     * The existing render_transaction_history() pane on the user
     * Wallet page reads from matrix_wallet only, so every call
     * site that wants its movement to appear in the user's unified
     * Transaction History must either go through credit()/debit()
     * (Matrix-wallet movement) or through record_ledger()
     * (non-Matrix-wallet movement). Both produce a row of the same
     * shape, so the UI render code stays unchanged.
     *
     * post_balance is REQUIRED and reflects the post-operation
     * balance of WHICHEVER wallet was affected. The caller is the
     * authoritative source for that value (e.g. read it back from
     * the Fintava virtual-wallet balance endpoint after the
     * wallet-to-wallet transfer landed). When the upstream balance
     * read fails for transient reasons, the caller can pass 0.0 —
     * the row still records the movement, and the "Post Balance"
     * column on the history page renders 0.00 for that row only.
     * That degraded display is intentionally preferred over
     * dropping the row entirely, because a missing audit entry is
     * a far worse failure mode than a single zero-stamped row.
     *
     * Status defaults to 'completed' — the only supported state
     * for this helper. Pending/rejected/cancelled rows still go
     * through the gateway-specific tables (matrix_deposits,
     * matrix_withdrawals, matrix_billing_transactions) where they
     * carry their own state machine.
     *
     * Returns TRUE on success, FALSE on insert failure (logged).
     * Callers should treat a FALSE return as advisory: the actual
     * money movement already landed, so we do NOT roll it back —
     * we just lose the audit row. The error_log line is the trail
     * ops uses to reconcile.
     *
     * @param int    $user_id
     * @param float  $amount           Positive amount of the movement.
     * @param string $type             'credit' or 'debit'.
     * @param string $transaction_type Free-text tag persisted to the
     *                                  varchar(50) column. Use a stable
     *                                  identifier so admin filters /
     *                                  reports can group by it (e.g.
     *                                  'bank_transfer', 'bill_airtime',
     *                                  'bill_admin_refund_virtual').
     * @param string $description      Human-readable line for the
     *                                  Description column. Should
     *                                  identify the affected wallet
     *                                  (e.g. "Bank transfer to
     *                                  GTBank 0123… (from Virtual
     *                                  Wallet)") so the unified
     *                                  history reads naturally
     *                                  alongside Matrix-wallet rows.
     * @param float  $post_balance     Post-operation balance of the
     *                                  affected wallet (NOT the Matrix
     *                                  wallet, unless that's what the
     *                                  movement was on).
     * @param string $reference        Optional gateway reference
     *                                  (UUID, Fintava transfer id,
     *                                  client_reference, etc.).
     * @param string $status           One of the matrix_wallet enum
     *                                  values; defaults to 'completed'.
     * @return bool
     */
    public function record_ledger($user_id, $amount, $type, $transaction_type, $description = '', $post_balance = 0.0, $reference = null, $status = 'completed') {
        global $wpdb;

        if ($type !== 'credit' && $type !== 'debit') {
            error_log(sprintf(
                '[Matrix MLM] record_ledger() rejected unknown type=%s user_id=%d amount=%s tx_type=%s',
                $type, $user_id, $amount, $transaction_type
            ));
            return false;
        }

        $logged = $wpdb->insert($wpdb->prefix . 'matrix_wallet', [
            'user_id'          => (int) $user_id,
            'amount'           => (float) $amount,
            'post_balance'     => (float) $post_balance,
            'type'             => $type,
            'transaction_type' => (string) ($transaction_type ?? ''),
            'description'      => (string) $description,
            'reference'        => $reference,
            'status'           => (string) $status,
        ]);
        if ($logged === false) {
            error_log(sprintf(
                '[Matrix MLM] record_ledger() INSERT failed: user_id=%d type=%s tx_type=%s amount=%s last_error=%s',
                $user_id, $type, $transaction_type, $amount, $wpdb->last_error
            ));
            return false;
        }
        return true;
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
     * Get a unified transaction timeline for the user.
     *
     * matrix_wallet is already the canonical ledger for every
     * movement that touches the Matrix balance — plan purchases,
     * deposits credited from gateway callbacks, admin-approved
     * withdrawals, user-to-user transfers, commissions, e-pin
     * redemptions, admin manual adjustments, bill payments,
     * Matrix→Virtual transfers, etc. All of those go through
     * Matrix_MLM_Wallet::credit() / ::debit() which always writes
     * an auditor row here, so get_transactions() above already
     * surfaces them.
     *
     * The one class of money movement that does NOT touch
     * matrix_wallet is the Fintava bank-payout flow ("Transfer to
     * Bank"): those debit the user's Fintava virtual wallet
     * directly and never transit the Matrix balance, so they live
     * only in matrix_fintava_payouts. Without this method the
     * wallet page's Transaction History would silently omit every
     * outbound bank transfer the user has ever made — which is
     * exactly the gap the unified-history requirement closes.
     *
     * Strategy: SELECT $limit rows from each source by created_at
     * DESC, normalize them to a common stdClass shape with a
     * `source` discriminator ('matrix' | 'bank') and a uniform
     * (created_at, type, amount, post_balance, description,
     * status) projection, merge in PHP, re-sort by created_at
     * DESC, and trim to $limit. Over-fetching from each side and
     * trimming after the merge is correct because the union of the
     * top-N from each source is a superset of the global top-N.
     *
     * post_balance is nullable on the unified row: bank-payout
     * rows don't have a Matrix-side post-balance (the post-balance
     * lives on the Fintava virtual wallet, which the user can see
     * in the Virtual Account header card above) so we return null
     * and the renderer shows a dash rather than a misleading zero.
     *
     * The matrix_fintava_payouts table is created lazily by
     * Matrix_MLM_Fintava::ensure_tables_exist() on plugin activation
     * with the Fintava gateway configured. We probe for its
     * existence via INFORMATION_SCHEMA before SELECTing so this
     * method also works on installs where Fintava has never been
     * enabled and the table simply doesn't exist (otherwise the
     * SELECT would emit a "table doesn't exist" warning into the
     * wallet page render).
     */
    public function get_unified_transactions($user_id, $limit = 50, $offset = 0) {
        global $wpdb;

        $offset = max(0, (int) $offset);
        $limit  = max(1, (int) $limit);

        // matrix_fintava_payouts is created lazily by the Fintava
        // gateway. Probe its existence so a Fintava-disabled install
        // doesn't blow up the SELECT with a "table doesn't exist"
        // warning.
        $payouts_table = $wpdb->prefix . 'matrix_fintava_payouts';
        $table_exists = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM information_schema.tables
             WHERE table_schema = %s AND table_name = %s LIMIT 1",
            DB_NAME, $payouts_table
        ));

        if (!$table_exists) {
            // Single-source fallback. Page directly off matrix_wallet.
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT created_at, type, amount, post_balance, description,
                        status, transaction_type, reference,
                        NULL AS bank_name, NULL AS account_number,
                        NULL AS narration, NULL AS failure_reason
                 FROM {$wpdb->prefix}matrix_wallet
                 WHERE user_id = %d
                 ORDER BY created_at DESC
                 LIMIT %d OFFSET %d",
                $user_id, $limit, $offset
            ));
            foreach ($rows as $r) {
                $r->source = 'matrix';
            }
            return $rows;
        }

        // Both sources present. UNION ALL into a single derived
        // table, then ORDER + LIMIT/OFFSET on the union — this is
        // the correct strategy for paginating across two
        // heterogeneous sources. A naive per-source LIMIT+OFFSET
        // followed by a PHP merge gets pages 2+ wrong: the global
        // top-N rows aren't the top-N from each source, so paging
        // would skip arbitrary rows whenever one source had a
        // dense run of timestamps.
        //
        // Each branch projects the same column list; bank-payout
        // rows fill the matrix-only slots (post_balance,
        // description, transaction_type) with NULL/sentinels and
        // carry the bank-only metadata (bank_name, account_number,
        // narration, failure_reason) so the renderer can build the
        // masked "Transfer to Bank — Bank ****1234" description
        // from the row data without a follow-up query.
        //
        // The matrix_fintava_payouts table is shared by two flows
        // with opposite accounting semantics on the virtual-wallet
        // side:
        //
        //   1. External bank payout (Fintava virtual → external
        //      Nigerian bank via /bank/credit). Funds LEAVE the
        //      virtual wallet → DEBIT, amount = total_debit
        //      (amount + Fintava charge), source = 'bank',
        //      description prefix "Transfer to Bank — ...".
        //
        //   2. Matrix → Virtual wallet transfer (Matrix balance →
        //      user's OWN Fintava virtual wallet via
        //      /transaction/wallet-to-wallet). The Matrix-side
        //      debit is already recorded in matrix_wallet; the
        //      payouts row is the credit leg landing in the
        //      virtual wallet → CREDIT, amount = `amount` (the
        //      net that actually arrives, NOT total_debit which
        //      double-counts the Matrix-side charge), source =
        //      'virtual', description prefix "Credit from Matrix
        //      wallet — ...".
        //
        // Discriminator: a payout row whose `account_number`
        // matches the user's own matrix_fintava_wallets row IS a
        // Matrix→Virtual credit leg; everything else is an
        // external bank payout. NUBANs are unique per wallet and
        // external bank payouts route to a destination at a
        // DIFFERENT bank, so this match is reliable. The LEFT
        // JOIN handles the (rare) case where the user has a
        // payout row but their wallet was deleted — the join
        // yields NULL, the CASE evaluates to FALSE, and the row
        // falls back to the existing "external bank" treatment.
        $wallets_table = $wpdb->prefix . 'matrix_fintava_wallets';
        $sql = "
            SELECT * FROM (
                (SELECT
                    created_at,
                    type,
                    amount,
                    post_balance,
                    description,
                    status,
                    transaction_type,
                    reference,
                    NULL AS bank_name,
                    NULL AS account_number,
                    NULL AS narration,
                    NULL AS failure_reason,
                    'matrix' AS source
                 FROM {$wpdb->prefix}matrix_wallet
                 WHERE user_id = %d)
                UNION ALL
                (SELECT
                    p.created_at,
                    CASE WHEN w.account_number IS NOT NULL
                              AND p.account_number = w.account_number
                         THEN 'credit' ELSE 'debit' END AS type,
                    CASE WHEN w.account_number IS NOT NULL
                              AND p.account_number = w.account_number
                         THEN p.amount ELSE p.total_debit END AS amount,
                    NULL AS post_balance,
                    NULL AS description,
                    p.status,
                    CASE WHEN w.account_number IS NOT NULL
                              AND p.account_number = w.account_number
                         THEN 'matrix_to_virtual_credit'
                         ELSE 'bank_transfer' END AS transaction_type,
                    p.reference,
                    p.bank_name,
                    p.account_number,
                    p.narration,
                    p.failure_reason,
                    CASE WHEN w.account_number IS NOT NULL
                              AND p.account_number = w.account_number
                         THEN 'virtual' ELSE 'bank' END AS source
                 FROM $payouts_table p
                 LEFT JOIN $wallets_table w ON w.user_id = p.user_id
                 WHERE p.user_id = %d)
            ) AS unified
            ORDER BY created_at DESC
            LIMIT %d OFFSET %d
        ";
        $rows = $wpdb->get_results($wpdb->prepare(
            $sql, $user_id, $user_id, $limit, $offset
        ));

        // Build the human-readable description for payout-table
        // rows from the per-row metadata. Account numbers are
        // masked to last-4 because the unified history lives
        // behind a single-factor session and we don't want the
        // full NUBAN appearing in plain text alongside arbitrary
        // wallet activity.
        //
        // The two flows produce different prefixes so the user
        // can tell at a glance whether money LEFT the platform
        // (external bank payout) or merely moved between their
        // own Matrix and Fintava wallets (Matrix→Virtual credit
        // leg). Same masking + narration suffix + failure-reason
        // suffix logic for both.
        foreach ($rows as $r) {
            if ($r->source === 'bank' || $r->source === 'virtual') {
                $acct = (string) $r->account_number;
                $masked = strlen($acct) >= 4
                    ? '****' . substr($acct, -4)
                    : $acct;
                if ($r->source === 'virtual') {
                    $desc = sprintf(
                        /* translators: 1: virtual wallet bank name (typically "Fintava"), 2: masked account number */
                        __('Credit from Matrix wallet — %1$s %2$s', 'matrix-mlm'),
                        $r->bank_name,
                        $masked
                    );
                } else {
                    $desc = sprintf(
                        /* translators: 1: bank name, 2: masked account number */
                        __('Transfer to Bank — %1$s %2$s', 'matrix-mlm'),
                        $r->bank_name,
                        $masked
                    );
                }
                if (!empty($r->narration)) {
                    $desc .= ' — ' . $r->narration;
                }
                if ($r->status === 'failed' && !empty($r->failure_reason)) {
                    // failure_reason is the verbose audit string
                    // make_request() emits ("API Error (HTTP 500)
                    // calling /bank/credit: An unexpected error
                    // occurred [body=…] [sent_keys=…]"). The audit
                    // shape is correct for the column, but the
                    // unified wallet history is a member-facing
                    // surface — render the friendly upstream
                    // sentence here. The verbose form is still on
                    // the underlying matrix_fintava_payouts row for
                    // operator triage on the admin payout retry
                    // page.
                    $desc .= ' (' . Matrix_MLM_Fintava::friendly_failure_reason(
                        $r->failure_reason,
                        __('transfer failed', 'matrix-mlm')
                    ) . ')';
                }
                $r->description = $desc;
            }
        }

        return $rows;
    }

    /**
     * Total count across the same two sources get_unified_transactions()
     * pages over (matrix_wallet + matrix_fintava_payouts). The wallet
     * page's pagination chrome reads this to compute total_pages =
     * ceil(count / per_page).
     *
     * Two cheap COUNT(*) calls — both keyed on indexed user_id — are
     * simpler and faster than a SELECT COUNT(*) over the unified
     * derived table. Fintava-disabled installs gracefully return just
     * the matrix_wallet count via the same INFORMATION_SCHEMA probe
     * get_unified_transactions() uses.
     */
    public function get_unified_transactions_count($user_id) {
        global $wpdb;

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}matrix_wallet WHERE user_id = %d",
            $user_id
        ));

        $payouts_table = $wpdb->prefix . 'matrix_fintava_payouts';
        $table_exists = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM information_schema.tables
             WHERE table_schema = %s AND table_name = %s LIMIT 1",
            DB_NAME, $payouts_table
        ));
        if ($table_exists) {
            $count += (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $payouts_table WHERE user_id = %d",
                $user_id
            ));
        }

        return $count;
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
