<?php
/**
 * Database Schema for Matrix MLM
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Database {

    /**
     * The complete set of tables this plugin owns, by short name (without the
     * site's $wpdb->prefix). Used for the schema-status probe in
     * maybe_upgrade(), the admin "Repair Database Schema" tool, and as the
     * single source of truth when adding new tables — append the short name
     * here and add a corresponding dbDelta block in create_tables() (or, for
     * Fintava-extension tables, an entry in the relevant gateway's
     * ensure_tables_exist() / create_table()).
     *
     * Includes the gateway-extension tables (matrix_fintava_payouts,
     * matrix_fintava_wallets, matrix_fintava_cards, matrix_billing_transactions,
     * matrix_subscriptions) on purpose: they are bootstrapped automatically by
     * Matrix_MLM_Core::run() on every pageload, so any of them missing is just
     * as much a schema drift as a core table missing, and the operator-facing
     * Repair tool needs to surface and heal them with the same one click.
     */
    const CRITICAL_TABLES = [
        // Core tables — created by self::create_tables() via dbDelta.
        'matrix_plans',
        'matrix_positions',
        'matrix_wallet',
        'matrix_deposits',
        'matrix_withdrawals',
        'matrix_commissions',
        'matrix_epins',
        'matrix_tickets',
        'matrix_ticket_messages',
        'matrix_gateways',
        'matrix_user_meta',
        'matrix_transfers',
        'matrix_subscribers',
        'matrix_pages',
        'matrix_fintava_webhook_logs',
        // Fintava gateway tables — created by Matrix_MLM_Fintava::ensure_tables_exist()
        // (payouts, wallets) and by the *_Card / *_Billing / Subscription helpers.
        'matrix_fintava_payouts',
        'matrix_fintava_wallets',
        'matrix_fintava_cards',
        'matrix_billing_transactions',
        'matrix_billing_refunds',
        'matrix_subscriptions',
        'matrix_benefits',
        'matrix_cug_requests',
        'matrix_loan_applications',
        'matrix_healthcare_applications',
        'matrix_hospitals',
        // Level-completion ledger — one row per (user_id, plan_id, level)
        // milestone, used both for idempotent email/toast delivery and as
        // a future hook point for per-level bonuses. Created by
        // self::create_tables() at the bottom of the dbDelta sequence
        // (see the matching block there).
        'matrix_level_completions',
        // Public read-only share tokens for the genealogy view —
        // one row per token a member has minted, with revoke
        // tracking and view-count instrumentation. Powers the
        // /genealogy/share/{token}/ public URL so members can
        // demo their tree to a prospect without granting login
        // access. Created by self::create_tables() (see the
        // matching block there).
        'matrix_share_tokens',
        // Append-only audit log of structural changes to
        // matrix_positions rows. Powers the genealogy "time
        // machine" view (date slider that reconstructs past tree
        // state). One row per significant event (create / move /
        // status change), plus a one-time backfill row per        // existing position dated at joined_at so snapshots from
        // before the audit table existed still work. Read by
        // Matrix_MLM_Plan_Engine::build_tree_at_snapshot();
        // written by Matrix_MLM_Position_History::record_event().
        'matrix_position_history',
        // In-app notifications (1.0.15). One row per per-user
        // notification surfaced via the dashboard sidebar bell
        // icon. Pairs with — does not replace — the email/SMS
        // path in Matrix_MLM_Notifications: the notifications
        // class enqueue()s a row here at every email-send site,
        // so the two channels stay locked to the same trigger.
        // Read rows are pruned by the daily cron after 90 days
        // (READ_RETENTION_DAYS in Matrix_MLM_In_App_Notifications);
        // unread rows are kept indefinitely so a user who hasn't
        // logged in for months still sees every commission they
        // earned while away.
        'matrix_notifications',
        // Zebra Wallet /Notify dispute / fraud / chargeback
        // feed (1.0.18). One row per inbound /Notify event from
        // Bibimoney's IPN. Distinct from matrix_notifications
        // (which is the member-facing in-app notifications
        // table) — this one is operator-facing and tracks an
        // actionable lifecycle (received -> acknowledged /
        // actioned / dismissed) rather than read/unread. The
        // related deposit/withdrawal is referenced via
        // (related_type, related_id) so the admin Notifications
        // page can deep-link back to the matrix_deposits or
        // matrix_withdrawals row Bibimoney is disputing.
        'matrix_zebra_notifications',
        // Member-to-member messaging (DB version 1.0.19).
        // Five tables forming the messaging system:
        //   - matrix_message_threads:      thread metadata (dm | team_room)
        //   - matrix_message_participants: N:M users <-> threads, with
        //                                  per-participant read/mute state
        //   - matrix_messages:             the actual message rows
        //   - matrix_message_blocks:       per-user block list (asymmetric
        //                                  storage, symmetric enforcement
        //                                  at model layer — see
        //                                  Matrix_MLM_Messaging::is_blocked_either_way)
        //   - matrix_message_reports:      admin moderation queue rows
        //   - matrix_message_reactions:    emoji reactions on individual
        //                                  messages (DB 1.0.21). Toggleable
        //                                  per (message, user, emoji)
        //                                  via UNIQUE constraint.
        // Created by self::create_tables(); listed here so the schema
        // status probe and the "Repair Database Schema" admin tool
        // surface drift on these tables the same way they do for the
        // tickets/notifications/zebra-notifications surface.
        'matrix_message_threads',
        'matrix_message_participants',
        'matrix_messages',
        'matrix_message_blocks',
        'matrix_message_reports',
        'matrix_message_reactions',
        'matrix_message_attachments',
    ];

    /**
     * Run schema migrations on every load if the stored DB version is older
     * than the constant — OR if any expected table is missing on disk even
     * when the version stamp claims we're up to date. Safe to call
     * repeatedly: dbDelta is idempotent and the gateway helpers all use
     * CREATE TABLE IF NOT EXISTS.
     *
     * Why the missing-table probe matters even when the version stamp is
     * current: a previous activation (or a past maybe_upgrade run) may
     * have written `matrix_mlm_db_version` to wp_options successfully
     * while one of the table CREATE statements silently failed on the
     * same request — this happens in the wild when the DB user lost
     * CREATE privilege between activations, when a DB restore was loaded
     * from a snapshot taken before a table was added, or when dbDelta's
     * fuzzy SQL parser rejects a particular CREATE on certain MySQL
     * collation/strict-mode combinations. The version-stamp short
     * circuit alone would let those installs stay in a broken state
     * indefinitely; the cheap INFORMATION_SCHEMA probe (one query that
     * MySQL caches internally) is the suspenders to its belt and is the
     * specific reason wp_matrix_fintava_webhook_logs went missing on
     * the install that prompted this code to be written.
     */
    public static function maybe_upgrade() {
        $installed = get_option('matrix_mlm_db_version');

        // Fast path — stamp matches and every expected table is on disk.
        if ($installed === MATRIX_MLM_DB_VERSION && self::critical_tables_present()) {
            return;
        }

        // Re-run the schema (dbDelta is idempotent).
        self::create_tables();

        // Defensive ALTER for the e-pins plan_id NOT NULL → NULL change.
        // dbDelta does not always alter NULL constraints reliably across MySQL versions.
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_epins';
        $col = $wpdb->get_row($wpdb->prepare(
            "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'plan_id'",
            DB_NAME, $table
        ));
        if ($col && strtoupper($col->IS_NULLABLE) === 'NO') {
            $wpdb->query("ALTER TABLE {$table} MODIFY plan_id BIGINT(20) UNSIGNED NULL DEFAULT NULL");
        }

        // Defensive ALTERs for the 1.0.7 healthcare-form rewrite.
        // Several columns that used to be NOT NULL are now optional
        // because the new Adult/Dependant form no longer collects
        // them — but dbDelta cannot reliably drop NOT NULL on an
        // already-existing column across MySQL versions, so we
        // fix-up here. Idempotent: the IS_NULLABLE probe skips the
        // ALTER once the column is already nullable.
        $hc_table = $wpdb->prefix . 'matrix_healthcare_applications';
        $hc_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME, $hc_table
        ));
        if ($hc_exists > 0) {
            $relax = [
                // column           => MODIFY clause
                'phone'              => "MODIFY phone VARCHAR(20) NULL DEFAULT NULL",
                'nin'                => "MODIFY nin VARCHAR(20) NULL DEFAULT NULL",
                'marital_status'     => "MODIFY marital_status ENUM('single','married','divorced','widowed') NULL DEFAULT NULL",
                'plan_tier'          => "MODIFY plan_tier ENUM('basic','standard','premium','family') NULL DEFAULT NULL",
                'coverage_type'      => "MODIFY coverage_type ENUM('individual','family') NULL DEFAULT NULL",
                'preferred_hospital' => "MODIFY preferred_hospital VARCHAR(200) NULL DEFAULT NULL",
                'nok_name'           => "MODIFY nok_name VARCHAR(120) NULL DEFAULT NULL",
                'nok_relationship'   => "MODIFY nok_relationship VARCHAR(50) NULL DEFAULT NULL",
                'nok_phone'          => "MODIFY nok_phone VARCHAR(20) NULL DEFAULT NULL",
            ];
            foreach ($relax as $col_name => $modify) {
                $info = $wpdb->get_row($wpdb->prepare(
                    "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                    DB_NAME, $hc_table, $col_name
                ));
                if ($info && strtoupper($info->IS_NULLABLE) === 'NO') {
                    $wpdb->query("ALTER TABLE {$hc_table} {$modify}");
                }
            }
        }

        // Defensive ALTER for the 1.0.9 user-meta status enum extension.
        // The column was originally enum('active','banned','pending') but
        // Matrix_MLM_Subscription::deactivate_unpaid_users() writes
        // 'inactive' on lapsed-payment deactivation, and manual_pay()
        // re-reads that value to flip the user back to 'active' when they
        // catch up. With 'inactive' missing from the enum, MySQL behaves
        // one of two bad ways depending on sql_mode:
        //
        //   - STRICT_TRANS_TABLES (default in MySQL 5.7+): the UPDATE
        //     fails with a "Data truncated" warning and $wpdb->update()
        //     returns false silently, so the user is never actually
        //     deactivated. The admin gets the deactivation email but
        //     the dashboard still loads for the non-paying user.
        //
        //   - non-strict mode: MySQL coerces 'inactive' to '' (empty
        //     string), is_active() correctly reports the user as
        //     inactive, but manual_pay()'s WHERE status = 'inactive'
        //     reactivation clause never matches because the on-disk
        //     value is '', so the user can never self-reactivate.
        //
        // dbDelta does not reliably extend enum values on an existing
        // column across MySQL versions (the parser sometimes treats the
        // enum spec as a no-op when the column already exists), so we
        // probe COLUMN_TYPE directly and ALTER if 'inactive' is absent.
        // Idempotent: the strpos() check skips the ALTER once the value
        // is already part of the enum.
        $um_table = $wpdb->prefix . 'matrix_user_meta';
        $um_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME, $um_table
        ));
        if ($um_exists > 0) {
            $um_status_col = $wpdb->get_row($wpdb->prepare(
                "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'status'",
                DB_NAME, $um_table
            ));
            if ($um_status_col && stripos((string) $um_status_col->COLUMN_TYPE, "'inactive'") === false) {
                $wpdb->query(
                    "ALTER TABLE {$um_table}
                       MODIFY status ENUM('active','banned','pending','inactive')
                              NOT NULL DEFAULT 'active'"
                );
            }
        }

        // 1.0.12: defensive ADD COLUMN for two_factor_recovery_codes.
        // dbDelta will add the column on a fresh CREATE TABLE pass, but
        // on existing installs the column may already be missing because
        // dbDelta's ADD COLUMN parsing is order-sensitive and easily
        // skipped when an unrelated edit further down the spec changes
        // it. Probe INFORMATION_SCHEMA and ALTER if absent. Idempotent:
        // the COUNT(*) check skips the ALTER once the column exists.
        //
        // Stores a JSON array of password_hash()'d recovery codes —
        // longtext rather than varchar to avoid ever truncating a future
        // expansion in code count or hash length.
        if ($um_exists > 0) {
            $rc_col_exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
                    AND COLUMN_NAME = 'two_factor_recovery_codes'",
                DB_NAME, $um_table
            ));
            if ($rc_col_exists === 0) {
                $wpdb->query(
                    "ALTER TABLE {$um_table}
                       ADD COLUMN two_factor_recovery_codes LONGTEXT DEFAULT NULL
                       AFTER two_factor_secret"
                );
            }
        }

        // Defensive ADD COLUMN for transaction_pin_hash. Same lazy-
        // migration pattern as two_factor_recovery_codes above —
        // dbDelta in create_tables() handles a fresh CREATE TABLE,
        // but copy-deploys / partial dbDelta runs / older installs
        // need the explicit ALTER for the column to land. Probe
        // INFORMATION_SCHEMA and skip if present; idempotent across
        // unlimited re-runs.
        //
        // Stores a single password_hash() bcrypt digest of the
        // user's normalised numeric PIN. varchar(255) matches the
        // two_factor_secret column above and gives plenty of room
        // for future hash format upgrades (PASSWORD_DEFAULT
        // currently emits 60 chars, but a future PHP could widen).
        if ($um_exists > 0) {
            $pin_col_exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
                    AND COLUMN_NAME = 'transaction_pin_hash'",
                DB_NAME, $um_table
            ));
            if ($pin_col_exists === 0) {
                $wpdb->query(
                    "ALTER TABLE {$um_table}
                       ADD COLUMN transaction_pin_hash VARCHAR(255) DEFAULT NULL
                       AFTER two_factor_recovery_codes"
                );
            }
        }

        // 1.0.13: Defensive ADD COLUMN for the five PIN-policy
        // columns introduced alongside the transaction-PIN
        // hardening pass (weak-PIN denylist, hard lockout, history
        // / no-reuse, set/used timestamps, forgot-PIN flow). Same
        // lazy-migration idiom as transaction_pin_hash above.
        //
        // 1.0.14 forces a re-run of this same idempotent block.
        // Production-reported schema-drift case: an install that
        // stamped matrix_mlm_db_version='1.0.13' before the lazy
        // ALTERs landed (or where one of the ALTERs failed
        // silently due to a transient permission error) skipped
        // the slow path of maybe_upgrade forever via the fast-
        // path early-return. The result was a partial schema
        // (transaction_pin_hash present, some sibling columns
        // missing) and Matrix_MLM_Transaction_Pin::fetch_meta's
        // six-column SELECT raised "Unknown column" errors
        // every time, making is_set() return false even for
        // users who had just successfully written a hash. The
        // user-visible symptom was the dashboard enrolment
        // banner refusing to dismiss after Set PIN, the Security
        // tab status stuck on "Not set", and gated transactions
        // silently bypassing the gate. Bumping DB_VERSION here
        // forces every existing install to enter the slow path
        // exactly once on next plugin load; the column-existence
        // probes below skip already-added columns and add the
        // missing ones, healing the schema. Paired with the
        // schema-drift fallback in fetch_meta(), the bug is
        // closed both retroactively (existing affected users
        // see correct status the moment the page reloads after
        // the migration runs) and going forward (any future
        // partial-migration scenario degrades to "PIN
        // present-or-not is correct, sibling features
        // gracefully reduced" instead of total feature breakage).
        //
        //   - transaction_pin_set_at        : DATETIME — written
        //         on every set/change, surfaced on the dashboard's
        //         Security tab as "PIN set on …" so users can spot
        //         a rotation they didn't initiate. Also feeds any
        //         future "PIN older than N days, please rotate"
        //         policy without a second migration.
        //
        //   - transaction_pin_last_used_at  : DATETIME — written
        //         on every successful require_pin_for_request gate
        //         pass. Surfaces "Last used 3 minutes ago" so a
        //         user who logs in to a clean session can spot a
        //         rogue authorised transaction.
        //
        //   - transaction_pin_history       : LONGTEXT JSON array
        //         of the prior N bcrypt hashes (oldest first).
        //         change() rejects a candidate that password_verify
        //         matches against any entry, blocking trivial
        //         rotation (1234 → 4321 → 1234 …). LONGTEXT
        //         mirrors the two_factor_recovery_codes column
        //         rationale — expansion-safe, no varchar
        //         truncation surprises.
        //
        //   - transaction_pin_failed_attempts : INT UNSIGNED — a
        //         hard counter of consecutive verify failures
        //         since the last success. Distinct from the
        //         rolling rate-limiter counter: the rate limiter
        //         resets every 15 min, this counter only resets
        //         on a verified-correct PIN. Crossing the
        //         HARD_LOCKOUT_THRESHOLD trips the lockout below.
        //
        //   - transaction_pin_locked_until  : DATETIME — when the
        //         hard counter trips, this is set to NOW() +
        //         HARD_LOCKOUT_HOURS. require_pin_for_request
        //         refuses with a "PIN locked, use Forgot PIN"
        //         message until the timestamp passes; the
        //         forgot-PIN flow clears it atomically with the
        //         hash wipe.
        if ($um_exists > 0) {
            $pin_extra_cols = [
                'transaction_pin_set_at'
                    => "ALTER TABLE {$um_table} ADD COLUMN transaction_pin_set_at DATETIME DEFAULT NULL AFTER transaction_pin_hash",
                'transaction_pin_last_used_at'
                    => "ALTER TABLE {$um_table} ADD COLUMN transaction_pin_last_used_at DATETIME DEFAULT NULL AFTER transaction_pin_set_at",
                'transaction_pin_history'
                    => "ALTER TABLE {$um_table} ADD COLUMN transaction_pin_history LONGTEXT DEFAULT NULL AFTER transaction_pin_last_used_at",
                'transaction_pin_failed_attempts'
                    => "ALTER TABLE {$um_table} ADD COLUMN transaction_pin_failed_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER transaction_pin_history",
                'transaction_pin_locked_until'
                    => "ALTER TABLE {$um_table} ADD COLUMN transaction_pin_locked_until DATETIME DEFAULT NULL AFTER transaction_pin_failed_attempts",
            ];
            foreach ($pin_extra_cols as $col => $alter) {
                $exists = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
                        AND COLUMN_NAME = %s",
                    DB_NAME, $um_table, $col
                ));
                if ($exists === 0) {
                    $wpdb->query($alter);
                }
            }
        }

        // 1.0.11: one-time backfill of matrix_position_history with
        // a 'backfilled' row per existing position, dated at the
        // position's joined_at column. Without this, the genealogy
        // time-machine view would only be able to reconstruct the
        // tree for snapshots dated *after* the audit table came
        // online, which would be a useless feature on day one for
        // any install that already has positions.
        //
        // Gated by an option flag rather than by version stamp so
        // that:
        //   - A future schema bump doesn't accidentally re-run the
        //     backfill (which the SQL itself defends against via
        //     NOT EXISTS, but the flag avoids even probing).
        //   - An operator can manually trigger a re-backfill by
        //     deleting the option, then visiting any admin page —
        //     useful if a partial backfill was interrupted by a
        //     timeout (rare on small installs, possible on the
        //     ~12k-member imports the codebase already supports).
        //
        // The backfill itself is idempotent at the SQL level too,
        // so the option flag is belt-and-braces, not load-bearing.
        if (class_exists('Matrix_MLM_Position_History')
            && (int) get_option('matrix_mlm_position_history_backfilled', 0) !== 1) {
            $rows = Matrix_MLM_Position_History::backfill_existing();
            update_option('matrix_mlm_position_history_backfilled', 1, false);
            update_option('matrix_mlm_position_history_backfill_count', (int) $rows, false);
        }

        // 1.0.16: widen matrix_deposits.status enum to include
        // 'pending_capture' for the Zebra Wallet (Bibimoney)
        // /CaptureOrCancel pre-auth state machine. Pre-auth flow:
        // /PaymentAuth + /Payment with EventType=PRE_AUTH parks
        // the deposit at 'pending_capture' (auth held but not
        // charged); admin then triggers /CaptureOrCancel to
        // either capture (-> 'completed', wallet credited) or
        // cancel (-> 'cancelled', no credit, no refund needed
        // because the customer's wallet was never debited).
        //
        // dbDelta does not reliably alter ENUM definitions on
        // existing tables, so we probe COLUMN_TYPE via
        // INFORMATION_SCHEMA and run the MODIFY only when
        // 'pending_capture' is missing. Idempotent — a fresh
        // install gets the wider enum from the CREATE TABLE
        // above and skips this block; a 1.0.15 install gets the
        // ALTER once and then skips it on every subsequent
        // load. Default 'pending' is preserved so a deposit row
        // INSERTed without an explicit status still lands at
        // 'pending' (matches today's process_deposit() insert).
        $deposits_table = $wpdb->prefix . 'matrix_deposits';
        $deposits_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME, $deposits_table
        ));
        if ($deposits_exists > 0) {
            $deposits_status_type = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
                    AND COLUMN_NAME = 'status'",
                DB_NAME, $deposits_table
            ));
            if ($deposits_status_type !== '' && stripos($deposits_status_type, "'pending_capture'") === false) {
                $wpdb->query(
                    "ALTER TABLE {$deposits_table}
                       MODIFY status ENUM('pending','pending_capture','completed','rejected','cancelled')
                              NOT NULL DEFAULT 'pending'"
                );
            }
        }

        // 1.0.17: extend matrix_withdrawals so Zebra Wallet
        // (Bibimoney) /Remit can plug into the existing admin-
        // approval flow as a vendor->customer payout. Three new
        // columns plus a widened status enum:
        //
        //   gateway          VARCHAR(50) — which payment provider
        //                    actually settles the row when the
        //                    admin clicks Approve. NULL on legacy
        //                    rows means "the historical Fintava-
        //                    credit branch", so today's behaviour
        //                    is preserved exactly. 'zebra'
        //                    dispatches to remit_to_account().
        //                    Indexed because the dispatcher
        //                    branches on this column on every
        //                    approve and the admin Withdrawals
        //                    page filters by it.
        //
        //   transaction_id   VARCHAR(255) — VendorReference we
        //                    generated for the /Remit request,
        //                    stored at lock time so a duplicate
        //                    delivery can be reconciled by ref
        //                    without re-deriving from the row id.
        //                    Same length / shape as
        //                    matrix_deposits.transaction_id.
        //
        //   gateway_response TEXT — JSON envelope with PSPreference,
        //                    raw API response, source ('sync'),
        //                    and the locked-amount snapshot. Used
        //                    by the admin page to surface a
        //                    diagnostic on a failed remit, and by
        //                    a future reconciliation worker that
        //                    needs to re-query Bibimoney.
        //
        //   status enum widened to include 'processing'. Acts as
        //                    a one-row lock between "admin
        //                    clicked approve" and "Bibimoney
        //                    confirmed the credit on the
        //                    customer's wallet". Without it, two
        //                    admins clicking Approve on the same
        //                    row in the same second would each
        //                    win the conditional UPDATE pending
        //                    -> approved AND each fire /Remit,
        //                    debiting the vendor wallet twice
        //                    and crediting the customer twice.
        //                    With 'processing' as the
        //                    intermediate state, only the first
        //                    click wins pending -> processing,
        //                    fires /Remit, then transitions
        //                    processing -> approved on success
        //                    or processing -> pending on
        //                    transient failure (which lets the
        //                    operator retry).
        //
        // dbDelta does not reliably alter ENUM definitions or
        // add columns to existing tables, so we run each piece
        // through INFORMATION_SCHEMA probes. Idempotent: a
        // fresh install gets the wider schema from the CREATE
        // TABLE above and skips this block; an existing install
        // gets each ADD COLUMN / MODIFY exactly once.
        $withdrawals_table = $wpdb->prefix . 'matrix_withdrawals';
        $withdrawals_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME, $withdrawals_table
        ));
        if ($withdrawals_exists > 0) {
            $withdrawal_columns = [
                'gateway' => "ALTER TABLE {$withdrawals_table}
                                ADD COLUMN gateway VARCHAR(50) DEFAULT NULL
                                AFTER method,
                                ADD KEY gateway (gateway)",
                'transaction_id' => "ALTER TABLE {$withdrawals_table}
                                       ADD COLUMN transaction_id VARCHAR(255) DEFAULT NULL
                                       AFTER currency,
                                       ADD KEY transaction_id (transaction_id)",
                'gateway_response' => "ALTER TABLE {$withdrawals_table}
                                         ADD COLUMN gateway_response TEXT DEFAULT NULL
                                         AFTER admin_note",
            ];
            foreach ($withdrawal_columns as $col_name => $alter_sql) {
                $exists = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
                        AND COLUMN_NAME = %s",
                    DB_NAME, $withdrawals_table, $col_name
                ));
                if ($exists === 0) {
                    $wpdb->query($alter_sql);
                }
            }

            $withdrawals_status_type = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
                    AND COLUMN_NAME = 'status'",
                DB_NAME, $withdrawals_table
            ));
            if ($withdrawals_status_type !== ''
                && stripos($withdrawals_status_type, "'processing'") === false) {
                $wpdb->query(
                    "ALTER TABLE {$withdrawals_table}
                       MODIFY status ENUM('pending','processing','approved','rejected','cancelled')
                              NOT NULL DEFAULT 'pending'"
                );
            }
        }

        // 1.0.18 — Zebra Wallet /Notify dispute / fraud /
        // chargeback feed. New table matrix_zebra_notifications.
        // dbDelta in create_tables() handles a fresh CREATE
        // TABLE; this idempotent probe handles upgraded installs
        // whose create_tables() may have run before the schema
        // existed.
        $zebra_notif_table = $wpdb->prefix . 'matrix_zebra_notifications';
        $zebra_notif_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME, $zebra_notif_table
        ));
        if ($zebra_notif_exists === 0) {
            // Use a direct CREATE TABLE IF NOT EXISTS rather than
            // dbDelta because the surrounding code path is the
            // upgrade slow path and we want a deterministic
            // single-statement create. Schema mirrors the dbDelta
            // version in create_tables().
            $charset = $wpdb->get_charset_collate();
            $wpdb->query("CREATE TABLE IF NOT EXISTS {$zebra_notif_table} (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                event_type varchar(50) NOT NULL,
                status_code varchar(20) DEFAULT NULL,
                severity enum('info','warning','critical') NOT NULL DEFAULT 'warning',
                vendor_reference varchar(255) DEFAULT NULL,
                psp_reference varchar(255) DEFAULT NULL,
                related_type enum('deposit','withdrawal','unknown') NOT NULL DEFAULT 'unknown',
                related_id bigint(20) UNSIGNED DEFAULT NULL,
                amount decimal(12,2) DEFAULT NULL,
                currency varchar(10) DEFAULT NULL,
                message text,
                raw_payload longtext,
                state enum('received','acknowledged','actioned','dismissed') NOT NULL DEFAULT 'received',
                handled_by_user_id bigint(20) UNSIGNED DEFAULT NULL,
                handled_at datetime DEFAULT NULL,
                handler_note text,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY state (state),
                KEY event_type (event_type),
                KEY vendor_reference (vendor_reference),
                KEY psp_reference (psp_reference),
                KEY related (related_type, related_id),
                KEY created_at (created_at)
            ) {$charset};");
        }

        // 1.0.20 — sender-side edit support on matrix_messages.
        // Adds edited_at DATETIME so the UI can render an "(edited)"
        // marker next to a message whose body has been changed
        // through Matrix_MLM_Messaging::edit_message(). dbDelta in
        // create_tables() will add the column on a fresh CREATE
        // TABLE pass; the probe here heals upgraded installs whose
        // matrix_messages table already exists. Idempotent: the
        // COLUMN_NAME existence check skips the ALTER once the
        // column is present.
        //
        // No KEY on edited_at — it's a one-way pointer the UI reads
        // alongside the row, never a query predicate. Adding an
        // index would just cost write throughput on every send /
        // edit.
        $messages_table = $wpdb->prefix . 'matrix_messages';
        $messages_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME, $messages_table
        ));
        if ($messages_exists > 0) {
            $edited_col_exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
                    AND COLUMN_NAME = 'edited_at'",
                DB_NAME, $messages_table
            ));
            if ($edited_col_exists === 0) {
                $wpdb->query(
                    "ALTER TABLE {$messages_table}
                       ADD COLUMN edited_at DATETIME DEFAULT NULL
                       AFTER created_at"
                );
            }
        }

        // 1.0.22 — sticky self-leave on matrix_message_participants.
        // New column removed_by BIGINT NULL records WHO ended the
        // membership: the user themselves (sticky — self-heal must
        // not re-add them on the next sponsor-graph walk) versus
        // an admin or sponsor (legacy behaviour — re-add on the
        // next sponsor-graph walk, mirroring how reparenting has
        // always healed membership state).
        //
        // Without this distinction, a downline who clicks "Leave
        // thread" on their sponsor's team room would walk back in
        // on their next dashboard load (self_heal_membership runs
        // unconditionally on every messages-tab render), and the
        // leave UI would feel broken — clicking the button does
        // nothing visible by the time the page next renders.
        //
        // dbDelta would attempt to MODIFY existing rows on every
        // upgrade pass and we don't need that overhead, so we
        // probe the column directly. Idempotent: a fresh install
        // gets removed_by from the wider CREATE TABLE in
        // create_tables(); an existing install gets the ADD
        // COLUMN exactly once and skips it on subsequent loads.
        // No index — removed_by is read alongside the row, never
        // as a query predicate.
        $participants_table = $wpdb->prefix . 'matrix_message_participants';
        $participants_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME, $participants_table
        ));
        if ($participants_exists > 0) {
            $removed_by_col_exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
                    AND COLUMN_NAME = 'removed_by'",
                DB_NAME, $participants_table
            ));
            if ($removed_by_col_exists === 0) {
                $wpdb->query(
                    "ALTER TABLE {$participants_table}
                       ADD COLUMN removed_by BIGINT(20) UNSIGNED DEFAULT NULL
                       AFTER removed_at"
                );
            }
        }

        // 1.0.23 — new matrix_message_attachments table for the
        // multi-attachment surface. Same shape as the dbDelta block
        // in create_tables(); this idempotent probe handles
        // upgraded installs whose create_tables() may have run
        // before the schema existed. Direct CREATE TABLE IF NOT
        // EXISTS rather than dbDelta because we want a
        // deterministic single-statement create when the slow path
        // is the one that lands the schema.
        $message_attachments_table = $wpdb->prefix . 'matrix_message_attachments';
        $message_attachments_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME, $message_attachments_table
        ));
        if ($message_attachments_exists === 0) {
            $charset = $wpdb->get_charset_collate();
            $wpdb->query("CREATE TABLE IF NOT EXISTS {$message_attachments_table} (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                message_id bigint(20) UNSIGNED NOT NULL,
                attachment_id bigint(20) UNSIGNED NOT NULL,
                position tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
                kind varchar(16) NOT NULL DEFAULT 'image',
                duration_ms int(10) UNSIGNED DEFAULT NULL,
                waveform_peaks_json text DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY message_position (message_id, position),
                KEY message_id (message_id),
                KEY attachment_id (attachment_id),
                KEY kind (kind)
            ) {$charset};");
        } else {
            // 1.0.25 — voice-note columns on an existing
            // matrix_message_attachments table. Three idempotent
            // ADD COLUMN probes plus the kind index. Same lazy-
            // migration shape as the e-pin / 2FA / transaction-pin
            // ALTERs above. dbDelta in create_tables() handles a
            // fresh install; this block handles the upgrade path
            // for installs that landed the 1.0.23 shape.
            $voice_columns = [
                'kind' => "ADD COLUMN kind VARCHAR(16) NOT NULL DEFAULT 'image' AFTER position",
                'duration_ms' => "ADD COLUMN duration_ms INT(10) UNSIGNED DEFAULT NULL AFTER kind",
                'waveform_peaks_json' => "ADD COLUMN waveform_peaks_json TEXT DEFAULT NULL AFTER duration_ms",
            ];
            foreach ($voice_columns as $col_name => $add_clause) {
                $col_exists = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
                        AND COLUMN_NAME = %s",
                    DB_NAME, $message_attachments_table, $col_name
                ));
                if ($col_exists === 0) {
                    $wpdb->query("ALTER TABLE {$message_attachments_table} {$add_clause}");
                }
            }
            // Companion KEY on `kind` so the moderation queue
            // and the per-thread voice-only filters in
            // hydrate_message_rows have an index to lean on.
            // INFORMATION_SCHEMA.STATISTICS lists one row per
            // index-column pair; we probe by the index name.
            $kind_index_exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                  WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
                    AND INDEX_NAME = 'kind'",
                DB_NAME, $message_attachments_table
            ));
            if ($kind_index_exists === 0) {
                $wpdb->query("ALTER TABLE {$message_attachments_table} ADD KEY kind (kind)");
            }
        }

        // 1.0.24 — widen matrix_message_threads.type ENUM to include
        // 'group_room' for the user-created multi-party room flow
        // (PR follows the same pattern as the matrix_deposits.status
        // 'pending_capture' widening from 1.0.16). Existing 'dm' and
        // 'team_room' rows are unaffected; the default stays 'dm'.
        //
        // dbDelta does not reliably alter ENUM definitions on
        // existing tables, so we probe COLUMN_TYPE via
        // INFORMATION_SCHEMA and run the MODIFY only when
        // 'group_room' is missing. Idempotent — a fresh install
        // gets the wider enum from the CREATE TABLE in
        // create_tables() and skips this block; a 1.0.23 install
        // gets the ALTER once and then skips it on every
        // subsequent load.
        $threads_table = $wpdb->prefix . 'matrix_message_threads';
        $threads_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME, $threads_table
        ));
        if ($threads_exists > 0) {
            $threads_type_col = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
                    AND COLUMN_NAME = 'type'",
                DB_NAME, $threads_table
            ));
            if ($threads_type_col !== '' && stripos($threads_type_col, "'group_room'") === false) {
                $wpdb->query(
                    "ALTER TABLE {$threads_table}
                       MODIFY type ENUM('dm','team_room','group_room')
                              NOT NULL DEFAULT 'dm'"
                );
            }
        }

        update_option('matrix_mlm_db_version', MATRIX_MLM_DB_VERSION);
        update_option('matrix_mlm_last_schema_sync', current_time('mysql'));
    }

    /**
     * Return per-table existence status for the schema probe, broken into
     * a present/missing pair so callers can branch on whether anything is
     * out of sync without a second pass.
     *
     * @return array{present: string[], missing: string[]} Fully-prefixed
     *     table names so the result is render-ready for the admin UI.
     */
    public static function get_schema_status() {
        global $wpdb;
        $present = [];
        $missing = [];
        foreach (self::CRITICAL_TABLES as $name) {
            $full = $wpdb->prefix . $name;
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $full
            ));
            if ($exists > 0) {
                $present[] = $full;
            } else {
                $missing[] = $full;
            }
        }
        return ['present' => $present, 'missing' => $missing];
    }

    /**
     * Convenience wrapper — true iff every critical table is on disk.
     */
    public static function critical_tables_present() {
        $status = self::get_schema_status();
        return empty($status['missing']);
    }

    /**
     * Force-re-run every schema bootstrap path the activator runs (core
     * dbDelta + every gateway extension's CREATE TABLE IF NOT EXISTS),
     * regardless of the stored version stamp. Returns a structured report
     * so the admin "Repair Database Schema" UI can render exactly which
     * tables existed before, which exist after, what was created on this
     * call, and any DB errors emitted along the way.
     *
     * Idempotent: if everything is already healthy, the report shows zero
     * created tables and zero errors. Safe to expose to admins as a
     * one-click recovery tool.
     */
    public static function repair() {
        $errors = [];
        $before = self::get_schema_status();

        // Core schema. dbDelta absorbs already-existing tables silently.
        self::create_tables();

        // Fintava gateway tables (payouts, wallets, webhook_logs). The helper
        // collects per-table CREATE errors and returns them so we can surface
        // them in the report instead of swallowing them.
        if (class_exists('Matrix_MLM_Fintava')) {
            $fintava_result = Matrix_MLM_Fintava::ensure_tables_exist();
            if (!empty($fintava_result['errors'])) {
                foreach ($fintava_result['errors'] as $err) {
                    $errors[] = 'fintava: ' . $err;
                }
            }
        }

        // Optional extension tables — each helper uses CREATE TABLE IF NOT
        // EXISTS internally, so calling them when the table is already
        // present is a no-op.
        if (class_exists('Matrix_MLM_Fintava_Card')) {
            Matrix_MLM_Fintava_Card::create_table();
        }
        if (class_exists('Matrix_MLM_Fintava_Billing')) {
            Matrix_MLM_Fintava_Billing::create_table();
        }
        if (class_exists('Matrix_MLM_Subscription')) {
            Matrix_MLM_Subscription::create_table();
        }

        update_option('matrix_mlm_db_version', MATRIX_MLM_DB_VERSION);
        update_option('matrix_mlm_last_schema_sync', current_time('mysql'));

        $after   = self::get_schema_status();
        $created = array_values(array_diff($after['present'], $before['present']));

        return [
            'before'  => $before,
            'after'   => $after,
            'created' => $created,
            'errors'  => $errors,
        ];
    }

    /**
     * Create all plugin tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Matrix Plans table
        $table_plans = $wpdb->prefix . 'matrix_plans';
        $sql_plans = "CREATE TABLE $table_plans (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            width int(11) NOT NULL DEFAULT 2,
            depth int(11) NOT NULL DEFAULT 3,
            price decimal(12,2) NOT NULL DEFAULT 0.00,
            joining_fee decimal(12,2) NOT NULL DEFAULT 0.00,
            level_commission text,
            referral_commission decimal(12,2) NOT NULL DEFAULT 0.00,
            matrix_completion_bonus decimal(12,2) NOT NULL DEFAULT 0.00,
            max_members int(11) NOT NULL DEFAULT 0,
            status enum('active','inactive') NOT NULL DEFAULT 'active',
            description text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        dbDelta($sql_plans);

        // User Matrix positions
        $table_positions = $wpdb->prefix . 'matrix_positions';
        $sql_positions = "CREATE TABLE $table_positions (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            plan_id bigint(20) UNSIGNED NOT NULL,
            sponsor_id bigint(20) UNSIGNED DEFAULT NULL,
            parent_id bigint(20) UNSIGNED DEFAULT NULL,
            position int(11) NOT NULL DEFAULT 0,
            level int(11) NOT NULL DEFAULT 1,
            left_count int(11) NOT NULL DEFAULT 0,
            right_count int(11) NOT NULL DEFAULT 0,
            total_downline int(11) NOT NULL DEFAULT 0,
            status enum('active','inactive','completed') NOT NULL DEFAULT 'active',
            joined_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY plan_id (plan_id),
            KEY sponsor_id (sponsor_id),
            KEY parent_id (parent_id)
        ) $charset_collate;";
        dbDelta($sql_positions);

        // Transactions/Wallet
        $table_wallet = $wpdb->prefix . 'matrix_wallet';
        $sql_wallet = "CREATE TABLE $table_wallet (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            amount decimal(12,2) NOT NULL DEFAULT 0.00,
            post_balance decimal(12,2) NOT NULL DEFAULT 0.00,
            type enum('credit','debit') NOT NULL,
            transaction_type varchar(50) NOT NULL,
            description text,
            reference varchar(100) DEFAULT NULL,
            status enum('pending','completed','rejected','cancelled') NOT NULL DEFAULT 'completed',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY type (type),
            KEY transaction_type (transaction_type)
        ) $charset_collate;";
        dbDelta($sql_wallet);

        // Deposits
        $table_deposits = $wpdb->prefix . 'matrix_deposits';
        $sql_deposits = "CREATE TABLE $table_deposits (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            gateway varchar(50) NOT NULL,
            amount decimal(12,2) NOT NULL,
            charge decimal(12,2) NOT NULL DEFAULT 0.00,
            net_amount decimal(12,2) NOT NULL,
            currency varchar(10) NOT NULL DEFAULT 'NGN',
            transaction_id varchar(255) DEFAULT NULL,
            gateway_response text,
            status enum('pending','pending_capture','completed','rejected','cancelled') NOT NULL DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY status (status)
        ) $charset_collate;";
        // Note: 'pending_capture' was added to the enum in 1.0.16
        // for the Zebra Wallet (Bibimoney) /CaptureOrCancel pre-auth
        // state machine. dbDelta does not reliably alter ENUM
        // definitions on tables that already exist, so the
        // matching ALTER lives in maybe_upgrade() (probes
        // COLUMN_TYPE via INFORMATION_SCHEMA and only runs when
        // 'pending_capture' is missing — idempotent).
        dbDelta($sql_deposits);

        // Withdrawals
        $table_withdrawals = $wpdb->prefix . 'matrix_withdrawals';
        $sql_withdrawals = "CREATE TABLE $table_withdrawals (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            method varchar(50) NOT NULL,
            gateway varchar(50) DEFAULT NULL,
            amount decimal(12,2) NOT NULL,
            charge decimal(12,2) NOT NULL DEFAULT 0.00,
            net_amount decimal(12,2) NOT NULL,
            currency varchar(10) NOT NULL DEFAULT 'NGN',
            transaction_id varchar(255) DEFAULT NULL,
            account_details text,
            admin_note text,
            gateway_response text,
            status enum('pending','processing','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY status (status),
            KEY gateway (gateway),
            KEY transaction_id (transaction_id)
        ) $charset_collate;";
        // Note: 'gateway', 'transaction_id', 'gateway_response',
        // and the 'processing' enum value were added in 1.0.17 to
        // wire Zebra Wallet (Bibimoney) /Remit into the existing
        // matrix_withdrawals admin-approval flow as a vendor->
        // customer payout. Legacy rows leave gateway NULL and fall
        // through to the today's-behaviour Fintava-credit branch
        // in approve_withdrawal(); a row with gateway='zebra'
        // dispatches to Matrix_MLM_Zebra::remit_to_account()
        // instead. dbDelta does not reliably alter ENUM
        // definitions or add columns to existing tables across
        // MySQL versions, so the matching idempotent ALTER block
        // lives in maybe_upgrade() (probes COLUMN_TYPE /
        // INFORMATION_SCHEMA.COLUMNS and only runs each piece
        // when missing).
        dbDelta($sql_withdrawals);

        // Commissions
        $table_commissions = $wpdb->prefix . 'matrix_commissions';
        $sql_commissions = "CREATE TABLE $table_commissions (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            from_user_id bigint(20) UNSIGNED NOT NULL,
            plan_id bigint(20) UNSIGNED DEFAULT NULL,
            type enum('referral','level','matrix_completion') NOT NULL,
            level int(11) NOT NULL DEFAULT 0,
            amount decimal(12,2) NOT NULL,
            status enum('pending','paid','cancelled') NOT NULL DEFAULT 'paid',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY from_user_id (from_user_id),
            KEY type (type)
        ) $charset_collate;";
        dbDelta($sql_commissions);

        // E-Pins
        $table_epins = $wpdb->prefix . 'matrix_epins';
        $sql_epins = "CREATE TABLE $table_epins (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            pin_code varchar(50) NOT NULL,
            plan_id bigint(20) UNSIGNED DEFAULT NULL,
            amount decimal(12,2) NOT NULL,
            created_by bigint(20) UNSIGNED NOT NULL,
            used_by bigint(20) UNSIGNED DEFAULT NULL,
            status enum('unused','used','expired') NOT NULL DEFAULT 'unused',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            used_at datetime DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY pin_code (pin_code),
            KEY plan_id (plan_id),
            KEY status (status)
        ) $charset_collate;";
        dbDelta($sql_epins);

        // Support Tickets
        $table_tickets = $wpdb->prefix . 'matrix_tickets';
        $sql_tickets = "CREATE TABLE $table_tickets (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            subject varchar(255) NOT NULL,
            priority enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
            status enum('open','answered','customer_reply','closed') NOT NULL DEFAULT 'open',
            department varchar(100) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY status (status)
        ) $charset_collate;";
        dbDelta($sql_tickets);

        // Ticket Messages
        $table_ticket_messages = $wpdb->prefix . 'matrix_ticket_messages';
        $sql_ticket_messages = "CREATE TABLE $table_ticket_messages (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id bigint(20) UNSIGNED NOT NULL,
            user_id bigint(20) UNSIGNED NOT NULL,
            message text NOT NULL,
            attachments text,
            is_admin tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ticket_id (ticket_id)
        ) $charset_collate;";
        dbDelta($sql_ticket_messages);

        // Payment Gateways
        $table_gateways = $wpdb->prefix . 'matrix_gateways';
        $sql_gateways = "CREATE TABLE $table_gateways (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            slug varchar(50) NOT NULL,
            gateway_parameters text,
            supported_currencies text,
            min_amount decimal(12,2) NOT NULL DEFAULT 0.00,
            max_amount decimal(12,2) NOT NULL DEFAULT 999999.99,
            fixed_charge decimal(12,2) NOT NULL DEFAULT 0.00,
            percent_charge decimal(5,2) NOT NULL DEFAULT 0.00,
            status tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) $charset_collate;";
        dbDelta($sql_gateways);

        // User Meta Extended
        $table_user_meta = $wpdb->prefix . 'matrix_user_meta';
        $sql_user_meta = "CREATE TABLE $table_user_meta (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            balance decimal(12,2) NOT NULL DEFAULT 0.00,
            referral_code varchar(50) DEFAULT NULL,
            referred_by bigint(20) UNSIGNED DEFAULT NULL,
            phone varchar(20) DEFAULT NULL,
            address text,
            city varchar(100) DEFAULT NULL,
            state varchar(100) DEFAULT NULL,
            country varchar(100) DEFAULT NULL,
            zip_code varchar(20) DEFAULT NULL,
            two_factor_enabled tinyint(1) NOT NULL DEFAULT 0,
            two_factor_secret varchar(255) DEFAULT NULL,
            two_factor_recovery_codes longtext DEFAULT NULL,
            transaction_pin_hash varchar(255) DEFAULT NULL,
            transaction_pin_set_at datetime DEFAULT NULL,
            transaction_pin_last_used_at datetime DEFAULT NULL,
            transaction_pin_history longtext DEFAULT NULL,
            transaction_pin_failed_attempts int(10) unsigned NOT NULL DEFAULT 0,
            transaction_pin_locked_until datetime DEFAULT NULL,
            email_verified tinyint(1) NOT NULL DEFAULT 0,
            sms_verified tinyint(1) NOT NULL DEFAULT 0,
            kyc_verified tinyint(1) NOT NULL DEFAULT 0,
            status enum('active','banned','pending','inactive') NOT NULL DEFAULT 'active',
            last_login datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id),
            UNIQUE KEY referral_code (referral_code)
        ) $charset_collate;";
        dbDelta($sql_user_meta);

        // Balance Transfers
        $table_transfers = $wpdb->prefix . 'matrix_transfers';
        $sql_transfers = "CREATE TABLE $table_transfers (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            from_user_id bigint(20) UNSIGNED NOT NULL,
            to_user_id bigint(20) UNSIGNED NOT NULL,
            amount decimal(12,2) NOT NULL,
            charge decimal(12,2) NOT NULL DEFAULT 0.00,
            note text,
            status enum('completed','pending','cancelled') NOT NULL DEFAULT 'completed',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY from_user_id (from_user_id),
            KEY to_user_id (to_user_id)
        ) $charset_collate;";
        dbDelta($sql_transfers);

        // Subscribers
        $table_subscribers = $wpdb->prefix . 'matrix_subscribers';
        $sql_subscribers = "CREATE TABLE $table_subscribers (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            email varchar(255) NOT NULL,
            status tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY email (email)
        ) $charset_collate;";
        dbDelta($sql_subscribers);

        // Fintava Webhook Logs
        $table_webhook_logs = $wpdb->prefix . 'matrix_fintava_webhook_logs';
        $sql_webhook_logs = "CREATE TABLE $table_webhook_logs (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type varchar(100) NOT NULL,
            reference varchar(255) DEFAULT NULL,
            payload longtext,
            signature varchar(255) DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'received',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_type (event_type),
            KEY reference (reference),
            KEY created_at (created_at)
        ) $charset_collate;";
        dbDelta($sql_webhook_logs);

        // Pages/Content
        $table_pages = $wpdb->prefix . 'matrix_pages';
        $sql_pages = "CREATE TABLE $table_pages (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            content longtext,
            type varchar(50) NOT NULL DEFAULT 'page',
            status tinyint(1) NOT NULL DEFAULT 1,
            meta_title varchar(255) DEFAULT NULL,
            meta_description text,
            meta_keywords text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY slug (slug)
        ) $charset_collate;";
        dbDelta($sql_pages);

        // Member Benefits — admin-managed cards rendered on the user
        // dashboard's Benefits tab. Gated to users with at least one
        // active position; see Matrix_MLM_User_Benefits::render() for
        // the entry point and class-matrix-admin-benefits.php for the
        // CRUD UI. The icon column accepts either a Dashicons class
        // (e.g. "dashicons-phone") or a fully-qualified image URL —
        // the renderer detects which based on the leading characters.
        $table_benefits = $wpdb->prefix . 'matrix_benefits';
        $sql_benefits = "CREATE TABLE $table_benefits (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            slug varchar(100) NOT NULL,
            icon varchar(500) DEFAULT NULL,
            short_description text,
            long_description longtext,
            cta_label varchar(100) DEFAULT NULL,
            cta_url varchar(500) DEFAULT NULL,
            display_order int(11) NOT NULL DEFAULT 0,
            status enum('active','inactive') NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY status_order (status, display_order)
        ) $charset_collate;";
        dbDelta($sql_benefits);

        // CUG enrolment requests — submitted by users from the Benefits
        // tab when they click "Apply" on the CUG card. Captures the
        // four fields specified by the operator: first_name, last_name,
        // nin, airtel_number (optional). Status defaults to 'pending'
        // so admins can review and approve/reject from the admin
        // (admin UI for that is intentionally out-of-scope for the
        // initial form rollout — rows are visible via the table).
        //
        // user_id is unique-keyed: one open request per user. On
        // resubmission we UPDATE the existing row instead of inserting
        // a duplicate, which keeps the audit trail simple and prevents
        // a user from spamming the table by clicking submit repeatedly.
        $table_cug = $wpdb->prefix . 'matrix_cug_requests';
        $sql_cug = "CREATE TABLE $table_cug (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            first_name varchar(60) NOT NULL,
            last_name varchar(60) NOT NULL,
            nin varchar(20) NOT NULL,
            airtel_number varchar(20) DEFAULT NULL,
            status enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
            admin_notes text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id),
            KEY status (status)
        ) $charset_collate;";
        dbDelta($sql_cug);

        // Business loan applications — submitted by members from the
        // Benefits tab when they click "Apply for Loan" on the loans
        // card. The schema is wide because the form has four sections
        // (personal / account / project / guarantor) plus six file
        // upload URLs, but it's flat by design: keeping all of it in
        // one row makes admin triage straightforward and avoids the
        // join overhead that a normalised parent/children layout
        // would introduce. Loan amounts and project gross value use
        // DECIMAL(15,2) so they comfortably hold up to ₦9.9 trillion
        // — well above the ₦5,000,000 cap in the operator's T&Cs but
        // future-proof against a corporate loan tier.
        //
        // user_id is unique-keyed for the same reason as the CUG
        // table: one open application per user. Resubmission UPDATEs
        // the existing row, so a member who needs to fix a typo or
        // re-upload a rejected document doesn't end up with a forest
        // of half-completed applications. When admins approve or
        // reject an application, the operator workflow can clear the
        // row (or transition it to 'cancelled') so the user can
        // submit a new one — this is a deliberate trade-off in the
        // initial rollout. If we later want a full loan history per
        // user, we'll relax the UNIQUE constraint and add a
        // status-scoped index instead.
        $table_loans = $wpdb->prefix . 'matrix_loan_applications';
        $sql_loans = "CREATE TABLE $table_loans (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            first_name varchar(60) NOT NULL,
            last_name varchar(60) NOT NULL,
            email varchar(120) NOT NULL,
            phone varchar(20) NOT NULL,
            address_line1 varchar(200) NOT NULL,
            address_line2 varchar(200) DEFAULT NULL,
            city varchar(100) NOT NULL,
            state varchar(100) NOT NULL,
            zip_code varchar(20) DEFAULT NULL,
            country varchar(100) NOT NULL,
            date_of_birth date NOT NULL,
            trade_name varchar(120) DEFAULT NULL,
            bank_name varchar(200) NOT NULL,
            account_number varchar(20) NOT NULL,
            account_name varchar(200) NOT NULL,
            applying_as enum('sole_proprietor','partnership','corporation') NOT NULL,
            business_address_line1 varchar(200) NOT NULL,
            business_address_line2 varchar(200) DEFAULT NULL,
            business_city varchar(100) NOT NULL,
            business_state varchar(100) NOT NULL,
            business_zip varchar(20) DEFAULT NULL,
            business_country varchar(100) NOT NULL,
            bvn varchar(20) NOT NULL,
            nin_file_url varchar(500) DEFAULT NULL,
            utility_bill_url varchar(500) DEFAULT NULL,
            valid_id_url varchar(500) DEFAULT NULL,
            passport_photo_url varchar(500) DEFAULT NULL,
            marketing_material_url varchar(500) DEFAULT NULL,
            project_info_url varchar(500) DEFAULT NULL,
            has_assets_statement tinyint(1) NOT NULL DEFAULT 0,
            previously_financed tinyint(1) NOT NULL DEFAULT 0,
            loan_reason enum('sme','farming','refinancing','other') NOT NULL,
            project_gross_value decimal(15,2) NOT NULL,
            loan_amount decimal(15,2) NOT NULL,
            repayment_plan enum('daily','weekly','monthly') NOT NULL,
            guarantor_first_name varchar(60) NOT NULL,
            guarantor_last_name varchar(60) NOT NULL,
            guarantor_phone varchar(20) NOT NULL,
            guarantor_valid_id_url varchar(500) DEFAULT NULL,
            guarantor_passport_url varchar(500) DEFAULT NULL,
            agreed_terms tinyint(1) NOT NULL DEFAULT 0,
            status enum('pending','under_review','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
            admin_notes text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        dbDelta($sql_loans);

        // Healthcare (HMO) enrolment applications — submitted from
        // the Benefits-tab healthcare card. Mirrors the loan table
        // shape (UNIQUE on user_id for UPSERT-on-resubmit, status
        // enum + index, admin_notes column, both timestamps) but is
        // shorter: no guarantor section, no T&Cs / agreed_terms, no
        // bank account block. Adds a `policy_number` column the
        // admin triage UI can stamp when status flips to 'approved'
        // so the user-facing email can quote the issued policy ID.
        //
        // The form was rewritten in 1.0.7 to a two-branch flow
        // (Adult vs Dependant) so several columns that used to be
        // NOT NULL are now nullable — they're no longer collected
        // from new submissions but are kept on the table so existing
        // pre-1.0.7 rows still render correctly in the admin triage
        // UI. The new columns at the bottom of the definition
        // (applicant_type, whatsapp, parent_*, hospital_state,
        // hospital_id) carry the post-1.0.7 form's payload. See
        // Matrix_MLM_User_Healthcare for the form, and the defensive
        // MODIFY ... NULL ALTERs in self::maybe_upgrade() for why
        // dbDelta alone can't be relied on to relax NOT NULL on an
        // existing column across MySQL versions.
        $table_healthcare = $wpdb->prefix . 'matrix_healthcare_applications';
        $sql_healthcare = "CREATE TABLE $table_healthcare (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            applicant_type enum('adult','dependant') NOT NULL DEFAULT 'adult',
            first_name varchar(60) NOT NULL,
            last_name varchar(60) NOT NULL,
            middle_name varchar(60) DEFAULT NULL,
            email varchar(120) NOT NULL,
            phone varchar(20) DEFAULT NULL,
            whatsapp varchar(20) DEFAULT NULL,
            date_of_birth date NOT NULL,
            gender enum('male','female','other') NOT NULL,
            marital_status enum('single','married','divorced','widowed') DEFAULT NULL,
            address_line1 varchar(200) NOT NULL,
            address_line2 varchar(200) DEFAULT NULL,
            city varchar(100) NOT NULL,
            state varchar(100) NOT NULL,
            zip_code varchar(20) DEFAULT NULL,
            country varchar(100) NOT NULL,
            nin varchar(20) DEFAULT NULL,
            occupation varchar(120) DEFAULT NULL,
            parent_first_name varchar(60) DEFAULT NULL,
            parent_last_name varchar(60) DEFAULT NULL,
            parent_phone varchar(20) DEFAULT NULL,
            parent_whatsapp varchar(20) DEFAULT NULL,
            plan_tier enum('basic','standard','premium','family') DEFAULT NULL,
            coverage_type enum('individual','family') DEFAULT NULL,
            preferred_hospital varchar(200) DEFAULT NULL,
            hospital_id bigint(20) UNSIGNED DEFAULT NULL,
            hospital_state varchar(50) DEFAULT NULL,
            dependants_count tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
            blood_group enum('A+','A-','B+','B-','AB+','AB-','O+','O-','unknown') NOT NULL DEFAULT 'unknown',
            genotype enum('AA','AS','SS','AC','SC','unknown') NOT NULL DEFAULT 'unknown',
            height_cm smallint(5) UNSIGNED DEFAULT NULL,
            weight_kg smallint(5) UNSIGNED DEFAULT NULL,
            pre_existing_conditions text,
            allergies text,
            current_medications text,
            is_smoker tinyint(1) NOT NULL DEFAULT 0,
            is_pregnant tinyint(1) NOT NULL DEFAULT 0,
            nok_name varchar(120) DEFAULT NULL,
            nok_relationship varchar(50) DEFAULT NULL,
            nok_phone varchar(20) DEFAULT NULL,
            passport_photo_url varchar(500) DEFAULT NULL,
            nin_slip_url varchar(500) DEFAULT NULL,
            utility_bill_url varchar(500) DEFAULT NULL,
            medical_history_url varchar(500) DEFAULT NULL,
            status enum('pending','under_review','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
            policy_number varchar(50) DEFAULT NULL,
            admin_notes text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id),
            KEY status (status),
            KEY created_at (created_at),
            KEY hospital_id (hospital_id)
        ) $charset_collate;";
        dbDelta($sql_healthcare);

        // Hospitals — admin-managed list rendered as the "Choice of
        // Hospital" dropdown on the user healthcare form. Each row
        // is tagged with a Nigerian state so the form's Hospital
        // dropdown can filter live as the applicant picks a state.
        // Adding/editing/disabling is handled by
        // Matrix_MLM_Admin_Hospitals; the form-side query lives in
        // Matrix_MLM_User_Healthcare::get_hospitals_grouped().
        $table_hospitals = $wpdb->prefix . 'matrix_hospitals';
        $sql_hospitals = "CREATE TABLE $table_hospitals (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(200) NOT NULL,
            state varchar(50) NOT NULL,
            address varchar(500) DEFAULT NULL,
            notes text,
            display_order int(11) NOT NULL DEFAULT 0,
            status enum('active','inactive') NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY state_status (state, status),
            KEY status_order (status, display_order)
        ) $charset_collate;";
        dbDelta($sql_hospitals);

        // Level Completions ledger — one row per (user_id, plan_id, level)
        // milestone the user has reached, where "reached" means every one
        // of the W^L positions at that depth in the user's downline is
        // filled by an active member. The (user_id, plan_id, level)
        // UNIQUE key is what makes the email + toast notification
        // pipeline idempotent: the engine writes via INSERT IGNORE in
        // Matrix_MLM_Plan_Engine::record_level_completions() and only
        // sends an email when the insert actually persisted a new row,
        // so a member who joins after the milestone is already crossed
        // can't retrigger the notification by joining again.
        //
        // email_sent_at and toast_seen_at are tracked separately because
        // they fire from different surfaces and at different moments:
        // email_sent_at is stamped synchronously the moment the row is
        // inserted (so that's also our "did the email go out?" audit
        // log); toast_seen_at is stamped lazily on the user's next
        // dashboard pageload by Matrix_MLM_User_Dashboard, so a user
        // who never visits the dashboard between the email and reading
        // the email still gets the toast on their next visit.
        //
        // Indexed on (user_id, plan_id) so the dashboard's
        // "fetch unread for current user" query is index-only, and on
        // position_id so admin tooling can join from a position back to
        // its milestone history without a table scan.
        $table_level_completions = $wpdb->prefix . 'matrix_level_completions';
        $sql_level_completions = "CREATE TABLE $table_level_completions (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            plan_id bigint(20) UNSIGNED NOT NULL,
            position_id bigint(20) UNSIGNED NOT NULL,
            level int(11) NOT NULL,
            completed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            email_sent_at datetime DEFAULT NULL,
            toast_seen_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_user_plan_level (user_id, plan_id, level),
            KEY user_plan (user_id, plan_id),
            KEY position_id (position_id)
        ) $charset_collate;";
        dbDelta($sql_level_completions);

        // Genealogy share tokens — public read-only links to a
        // member's tree, used to demo their downline to a prospect
        // without granting login access. One row per minted token;
        // revocation is soft (revoked_at != NULL) rather than a
        // hard delete so the dashboard can show a grayed-out
        // "Revoked on Sept 14" history entry instead of having the
        // row vanish without trace.
        //
        // Tokens are 64-char URL-safe random strings (32 bytes hex
        // from random_bytes()). UNIQUE KEY on token so a duplicate
        // INSERT — which random_bytes(32) makes vanishingly
        // unlikely but not impossible — fails loudly rather than
        // silently overwriting another member's link.
        //
        // expires_at is nullable: a member who picks "no expiry"
        // gets a token that lives until they explicitly revoke it.
        // The lookup path checks `revoked_at IS NULL AND (expires_at
        // IS NULL OR expires_at > NOW())` so both knobs gate access
        // independently.
        //
        // plan_id is nullable so a member can mint a single share
        // link that always points at their currently-most-recent
        // plan; pivot_user_id is nullable so the default share is
        // the member's own tree, with an optional override for
        // sharing a sub-branch.
        //
        // last_viewed_at + view_count are written every time the
        // public route resolves the token successfully, giving the
        // member a basic "is anyone using this link?" signal on the
        // dashboard panel. Not security-critical — the lookup is
        // already bounded by the WHERE clause above; this is purely
        // engagement instrumentation.
        $table_share_tokens = $wpdb->prefix . 'matrix_share_tokens';
        $sql_share_tokens = "CREATE TABLE $table_share_tokens (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            plan_id bigint(20) UNSIGNED DEFAULT NULL,
            pivot_user_id bigint(20) UNSIGNED DEFAULT NULL,
            token varchar(64) NOT NULL,
            label varchar(120) DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            revoked_at datetime DEFAULT NULL,
            last_viewed_at datetime DEFAULT NULL,
            view_count int(10) UNSIGNED NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY token (token),
            KEY user_id (user_id)
        ) $charset_collate;";
        dbDelta($sql_share_tokens);

        // Genealogy time-machine audit log — one append-only row
        // per structural change to a matrix_positions row. The
        // companion class Matrix_MLM_Position_History writes here
        // on every join_plan() / complete_matrix() / admin move
        // (admin move capture is deferred to v2 — documented in
        // that class's docblock). The time-machine view in the
        // genealogy tab reconstructs past tree states by reading
        // the latest history row per position id whose
        // effective_at <= the requested snapshot date.
        //
        // Index choice: (position_id, effective_at) is the primary
        // workhorse for the per-position "state at T" lookup the
        // reconstruction relies on; (plan_id, effective_at) helps
        // the future plan-wide diff/admin-history views without
        // forcing a full-table scan.
        //
        // We do NOT add a UNIQUE constraint on (position_id,
        // effective_at) because two events landing in the same
        // second on the same position are legitimate (a 'created'
        // followed by an immediate admin override would be the
        // canonical example). The primary key is a synthetic id;
        // ordering ties are broken by id ASC in the reader.
        $table_position_history = $wpdb->prefix . 'matrix_position_history';
        $sql_position_history = "CREATE TABLE $table_position_history (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            position_id bigint(20) UNSIGNED NOT NULL,
            user_id bigint(20) UNSIGNED NOT NULL,
            plan_id bigint(20) UNSIGNED NOT NULL,
            sponsor_id bigint(20) UNSIGNED DEFAULT NULL,
            parent_id bigint(20) UNSIGNED DEFAULT NULL,
            position int(11) NOT NULL DEFAULT 0,
            level int(11) NOT NULL DEFAULT 1,
            total_downline int(11) NOT NULL DEFAULT 0,
            status enum('active','inactive','completed') NOT NULL DEFAULT 'active',
            effective_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            event_type enum('created','moved','status_changed','completed','backfilled') NOT NULL DEFAULT 'backfilled',
            actor_user_id bigint(20) UNSIGNED DEFAULT NULL,
            notes text,
            PRIMARY KEY (id),
            KEY position_effective (position_id, effective_at),
            KEY plan_effective (plan_id, effective_at),
            KEY event_type (event_type)
        ) $charset_collate;";
        dbDelta($sql_position_history);

        // In-app notifications (1.0.15) — one row per per-user
        // notification surfaced via the dashboard sidebar bell.
        // Matrix_MLM_In_App_Notifications::enqueue() writes here
        // from every site that today fires a Matrix_MLM_Notifications
        // email send, so the email and in-app channels stay locked
        // to the same trigger.
        //
        // Index choice:
        //   - (user_id, read_at): primary workhorse for the unread
        //     badge query (`WHERE user_id = %d AND read_at IS NULL`)
        //     that runs on every dashboard pageload and on every
        //     60-second poll. read_at being nullable means MySQL's
        //     index covers both "unread" (NULL) and "read" lookups;
        //     the index is also useful for the cleanup cron's
        //     `read_at IS NOT NULL AND read_at < cutoff` predicate.
        //   - (user_id, created_at): drives the dropdown's
        //     "newest 20" ordering and the Notifications-tab
        //     pagination. Composite with user_id rather than just
        //     created_at because every read is user-scoped.
        //
        // No UNIQUE constraint: a user can legitimately receive two
        // notifications of the same type within the same second
        // (two simultaneous commissions from a level wave for
        // example), and dedup is not desired here — each event is
        // its own surfaceable signal.
        //
        // meta is JSON; the renderer parses it client-side and
        // tolerates unknown keys, so future trigger sites can attach
        // additional context (counterpart_user_id, amount, etc.) in
        // the same column without a schema migration.
        $table_notifications = $wpdb->prefix . 'matrix_notifications';
        $sql_notifications = "CREATE TABLE $table_notifications (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            type varchar(50) NOT NULL DEFAULT 'generic',
            title varchar(255) NOT NULL,
            body text,
            link_url varchar(500) DEFAULT NULL,
            meta longtext,
            read_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_unread (user_id, read_at),
            KEY user_created (user_id, created_at)
        ) $charset_collate;";
        dbDelta($sql_notifications);

        // Zebra Wallet /Notify dispute / fraud / chargeback feed
        // (1.0.18). One row per inbound /Notify event from
        // Bibimoney's IPN. The schema deliberately decouples
        // the relationship from a foreign key so a /Notify event
        // referencing a deposit/withdrawal we don't recognise
        // (a probe for the wrong VendorReference, a stale
        // notification arriving after we've cleared the original
        // row, etc.) can still be persisted with related_type='unknown'
        // for operator review rather than dropped on the floor.
        //
        // related_type / related_id is a polymorphic pointer
        // because /Notify events can attach to either side of the
        // money flow:
        //   - DISPUTE / FRAUD on a deposit (matrix_deposits)
        //   - CHARGEBACK / REVERSAL on a withdrawal (matrix_withdrawals)
        // Resolved at IPN-receive time by find_related_record()
        // matching VendorReference and PSPReference against both
        // tables.
        //
        // state lifecycle:
        //   received     -> initial state on IPN receipt
        //   acknowledged -> operator has seen it, no action taken
        //   actioned     -> operator has frozen the related withdrawal
        //   dismissed    -> operator has marked it as a false positive
        //
        // handled_by_user_id / handled_at / handler_note record
        // who responded and how, for audit. raw_payload stores
        // the full JSON envelope so support can replay an event
        // (or a future reconciliation worker can re-derive
        // related_id if find_related_record's heuristics get
        // smarter).
        $table_zebra_notifications = $wpdb->prefix . 'matrix_zebra_notifications';
        $sql_zebra_notifications = "CREATE TABLE $table_zebra_notifications (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type varchar(50) NOT NULL,
            status_code varchar(20) DEFAULT NULL,
            severity enum('info','warning','critical') NOT NULL DEFAULT 'warning',
            vendor_reference varchar(255) DEFAULT NULL,
            psp_reference varchar(255) DEFAULT NULL,
            related_type enum('deposit','withdrawal','unknown') NOT NULL DEFAULT 'unknown',
            related_id bigint(20) UNSIGNED DEFAULT NULL,
            amount decimal(12,2) DEFAULT NULL,
            currency varchar(10) DEFAULT NULL,
            message text,
            raw_payload longtext,
            state enum('received','acknowledged','actioned','dismissed') NOT NULL DEFAULT 'received',
            handled_by_user_id bigint(20) UNSIGNED DEFAULT NULL,
            handled_at datetime DEFAULT NULL,
            handler_note text,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY state (state),
            KEY event_type (event_type),
            KEY vendor_reference (vendor_reference),
            KEY psp_reference (psp_reference),
            KEY related (related_type, related_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        dbDelta($sql_zebra_notifications);

        // ----------------------------------------------------------------
        // Member-to-member messaging (DB version 1.0.19).
        //
        // Five tables, all dbDelta-compatible. Conventions follow the
        // matrix_tickets / matrix_ticket_messages pair already established
        // in this file: prefix-aware table names, $charset_collate suffix,
        // ON UPDATE CURRENT_TIMESTAMP only where the column needs touch
        // tracking, indexes only where the runtime queries need them.
        //
        // Pair semantics for matrix_message_blocks:
        //   UNIQUE (blocker_id, blocked_id) is intentionally directional —
        //   it lets the same pair exist twice (A blocks B AND B blocks A),
        //   and Matrix_MLM_Messaging::is_blocked_either_way() handles the
        //   symmetric refusal at the model layer. A symmetric UNIQUE
        //   would force one side's second block to silently fail, which
        //   would degrade the moderation queue's signal.
        // ----------------------------------------------------------------

        $table_message_threads = $wpdb->prefix . 'matrix_message_threads';
        $sql_message_threads = "CREATE TABLE $table_message_threads (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            type enum('dm','team_room','group_room') NOT NULL DEFAULT 'dm',
            team_owner_id bigint(20) UNSIGNED DEFAULT NULL,
            title varchar(190) DEFAULT NULL,
            status enum('active','archived') NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_message_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY type_owner (type, team_owner_id),
            KEY last_message_at (last_message_at)
        ) $charset_collate;";
        dbDelta($sql_message_threads);

        $table_message_participants = $wpdb->prefix . 'matrix_message_participants';
        // removed_by (DB 1.0.22): records WHO triggered the
        // removal so self-heal can distinguish self-leave (sticky;
        // do not re-add on next page render) from admin / sponsor
        // removal (re-add on the next sponsor-graph walk, matching
        // the existing reparenting story). NULL on legacy rows
        // and on owner / member rows that have never been removed
        // — read together with removed_at, never as a standalone
        // query predicate, so no index is needed.
        $sql_message_participants = "CREATE TABLE $table_message_participants (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            thread_id bigint(20) UNSIGNED NOT NULL,
            user_id bigint(20) UNSIGNED NOT NULL,
            role enum('owner','member') NOT NULL DEFAULT 'member',
            joined_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_read_at datetime DEFAULT NULL,
            muted_until datetime DEFAULT NULL,
            removed_at datetime DEFAULT NULL,
            removed_by bigint(20) UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY thread_user (thread_id, user_id),
            KEY user_id (user_id)
        ) $charset_collate;";
        dbDelta($sql_message_participants);

        $table_messages = $wpdb->prefix . 'matrix_messages';
        $sql_messages = "CREATE TABLE $table_messages (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            thread_id bigint(20) UNSIGNED NOT NULL,
            sender_id bigint(20) UNSIGNED NOT NULL,
            body longtext NOT NULL,
            body_stripped tinyint(1) NOT NULL DEFAULT 0,
            attachment_id bigint(20) UNSIGNED DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            edited_at datetime DEFAULT NULL,
            deleted_at datetime DEFAULT NULL,
            deleted_by bigint(20) UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            KEY thread_id (thread_id, id),
            KEY sender_id (sender_id),
            KEY deleted_at (deleted_at)
        ) $charset_collate;";
        dbDelta($sql_messages);
        dbDelta($sql_messages);

        $table_message_blocks = $wpdb->prefix . 'matrix_message_blocks';
        $sql_message_blocks = "CREATE TABLE $table_message_blocks (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            blocker_id bigint(20) UNSIGNED NOT NULL,
            blocked_id bigint(20) UNSIGNED NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY blocker_blocked (blocker_id, blocked_id),
            KEY blocked_id (blocked_id)
        ) $charset_collate;";
        dbDelta($sql_message_blocks);

        $table_message_reports = $wpdb->prefix . 'matrix_message_reports';
        $sql_message_reports = "CREATE TABLE $table_message_reports (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            message_id bigint(20) UNSIGNED NOT NULL,
            reporter_id bigint(20) UNSIGNED NOT NULL,
            reason varchar(32) NOT NULL DEFAULT 'other',
            note varchar(1000) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at datetime DEFAULT NULL,
            resolved_by bigint(20) UNSIGNED DEFAULT NULL,
            resolution varchar(32) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY message_id (message_id),
            KEY resolved_at (resolved_at)
        ) $charset_collate;";
        dbDelta($sql_message_reports);

        // Message reactions (DB 1.0.21). One row per (message, user,
        // emoji) — the UNIQUE constraint enforces toggle semantics
        // at the storage layer so the model's react_to_message()
        // can do an "INSERT IGNORE then count affected" check
        // instead of a SELECT-then-INSERT race. emoji is varchar(16)
        // because emoji are multi-byte (a thumbs-up is 4 bytes
        // UTF-8, a skin-toned emoji can be 8+ bytes via ZWJ
        // sequence) and we cap at a small UI palette anyway.
        $table_message_reactions = $wpdb->prefix . 'matrix_message_reactions';
        $sql_message_reactions = "CREATE TABLE $table_message_reactions (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            message_id bigint(20) UNSIGNED NOT NULL,
            user_id bigint(20) UNSIGNED NOT NULL,
            emoji varchar(16) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY message_user_emoji (message_id, user_id, emoji),
            KEY message_id (message_id),
            KEY user_id (user_id)
        ) $charset_collate;";
        dbDelta($sql_message_reactions);

        // Message attachments (DB 1.0.23). One row per (message,
        // attachment) pair, allowing multiple attachments per message
        // — the matrix_messages.attachment_id column is preserved as
        // a legacy single-attachment slot so old rows render without
        // a backfill, and new sends populate BOTH the legacy column
        // (with the first attachment) AND this table (with every
        // attachment in display order). Reads prefer this table when
        // any rows exist for the message; otherwise they fall back to
        // the legacy column. Either path produces the same wire
        // shape (an `attachments` array on the message row) so the
        // client doesn't branch on which storage was hit.
        //
        // Position is a small uint encoding display order (0..N-1)
        // so the JS can render thumbnails in the order the sender
        // attached them. UNIQUE(message_id, position) catches
        // accidental duplicate inserts at the storage layer.
        // 1.0.25 — voice-note columns. `kind` discriminates the
        // attachment family ('image' vs 'voice') so render code
        // can pick the right widget without re-reading the WP
        // attachment's mime type per row. `duration_ms` and
        // `waveform_peaks_json` are voice-only metadata and stay
        // NULL on image rows. Voice files are also delivered via
        // Matrix_MLM_Attachment_Signer (not raw /uploads/ URLs),
        // gated by per-thread participant authorisation rather
        // than the manage_matrix_mlm capability the signer
        // started life on. See Matrix_MLM_Messaging::send_message
        // for write-side semantics and ::hydrate_message_rows
        // for read-side.
        $table_message_attachments = $wpdb->prefix . 'matrix_message_attachments';
        $sql_message_attachments = "CREATE TABLE $table_message_attachments (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            message_id bigint(20) UNSIGNED NOT NULL,
            attachment_id bigint(20) UNSIGNED NOT NULL,
            position tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
            kind varchar(16) NOT NULL DEFAULT 'image',
            duration_ms int(10) UNSIGNED DEFAULT NULL,
            waveform_peaks_json text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY message_position (message_id, position),
            KEY message_id (message_id),
            KEY attachment_id (attachment_id),
            KEY kind (kind)
        ) $charset_collate;";
        dbDelta($sql_message_attachments);

        update_option('matrix_mlm_db_version', MATRIX_MLM_DB_VERSION);
    }

    /**
     * Drop all plugin tables
     */
    public static function drop_tables() {
        global $wpdb;
        $tables = [
            'matrix_plans', 'matrix_positions', 'matrix_wallet',
            'matrix_deposits', 'matrix_withdrawals', 'matrix_commissions',
            'matrix_epins', 'matrix_tickets', 'matrix_ticket_messages',
            'matrix_gateways', 'matrix_user_meta', 'matrix_transfers',
            'matrix_subscribers', 'matrix_pages', 'matrix_fintava_webhook_logs',
            'matrix_benefits',
            'matrix_cug_requests',
            'matrix_loan_applications',
            'matrix_healthcare_applications',
            'matrix_hospitals',
            'matrix_level_completions',
            'matrix_share_tokens',
            'matrix_position_history',
            'matrix_notifications',
            'matrix_zebra_notifications',
            // Messaging tables (DB version 1.0.19) — included in the
            // uninstall drop-list so a clean uninstall removes the
            // whole module's footprint, matching the convention used
            // by every other plugin-owned table above.
            'matrix_message_threads',
            'matrix_message_participants',
            'matrix_messages',
            'matrix_message_blocks',
            'matrix_message_reports',
            'matrix_message_reactions',
            'matrix_message_attachments',
        ];

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
        }
    }

    /**
     * Seed default data
     */
    public static function seed_defaults() {
        global $wpdb;

        // Insert default payment gateways
        $gateways_table = $wpdb->prefix . 'matrix_gateways';
        
        $existing = $wpdb->get_var("SELECT COUNT(*) FROM $gateways_table");
        if ($existing == 0) {
            $wpdb->insert($gateways_table, [
                'name' => 'Paystack',
                'slug' => 'paystack',
                'gateway_parameters' => json_encode([
                    'public_key' => '',
                    'secret_key' => '',
                    'webhook_secret' => ''
                ]),
                'supported_currencies' => json_encode(['NGN', 'GHS', 'ZAR', 'USD']),
                'min_amount' => 100.00,
                'max_amount' => 5000000.00,
                'fixed_charge' => 0.00,
                'percent_charge' => 1.50,
                'status' => 1
            ]);

            $wpdb->insert($gateways_table, [
                'name' => 'Flutterwave',
                'slug' => 'flutterwave',
                'gateway_parameters' => json_encode([
                    'public_key' => '',
                    'secret_key' => '',
                    'encryption_key' => '',
                    'webhook_hash' => ''
                ]),
                'supported_currencies' => json_encode(['NGN', 'GHS', 'KES', 'ZAR', 'USD', 'GBP', 'EUR']),
                'min_amount' => 100.00,
                'max_amount' => 10000000.00,
                'fixed_charge' => 0.00,
                'percent_charge' => 1.40,
                'status' => 1
            ]);
        }

        // Seed Fintava Pay default settings (stored in wp_options)
        $fintava_defaults = [
            'matrix_mlm_fintava_secret_key'   => '',
            'matrix_mlm_fintava_webhook_secret' => '',
            'matrix_mlm_fintava_status'       => 0,
            // Default to Live so the API Key field on the admin Gateways page
            // matches a real production endpoint without forcing the admin to
            // edit wp-config.php. Switchable via the Environment dropdown.
            'matrix_mlm_fintava_environment'  => 'live',
        ];

        foreach ($fintava_defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }

        // Insert default plans
        $plans_table = $wpdb->prefix . 'matrix_plans';
        $existing_plans = $wpdb->get_var("SELECT COUNT(*) FROM $plans_table");
        if ($existing_plans == 0) {
            $default_plans = [
                ['name' => 'Starter 2x3', 'width' => 2, 'depth' => 3, 'price' => 5000.00],
                ['name' => 'Basic 3x3', 'width' => 3, 'depth' => 3, 'price' => 10000.00],
                ['name' => 'Standard 5x5', 'width' => 5, 'depth' => 5, 'price' => 25000.00],
                ['name' => 'Pro 4x7', 'width' => 4, 'depth' => 7, 'price' => 50000.00],
                ['name' => 'Premium 5x7', 'width' => 5, 'depth' => 7, 'price' => 75000.00],
                ['name' => 'Elite 3x9', 'width' => 3, 'depth' => 9, 'price' => 100000.00],
                ['name' => 'Ultimate 2x12', 'width' => 2, 'depth' => 12, 'price' => 150000.00],
            ];

            foreach ($default_plans as $plan) {
                $level_commissions = [];
                for ($i = 1; $i <= $plan['depth']; $i++) {
                    $level_commissions[$i] = round($plan['price'] * (0.10 - ($i * 0.005)), 2);
                }
                $wpdb->insert($plans_table, array_merge($plan, [
                    'level_commission' => json_encode($level_commissions),
                    'referral_commission' => $plan['price'] * 0.10,
                    'matrix_completion_bonus' => $plan['price'] * 0.50,
                    'status' => 'active'
                ]));
            }
        }

        // Create default root user for referral system
        self::create_default_user();

        // Seed the two starter benefits — only when the table is
        // empty so we don't clobber rows the operator has already
        // edited or extended through Matrix MLM → Benefits. The
        // copy is intentionally generic and editable; the icons use
        // Dashicons classes so they render without requiring an
        // image upload on first install.
        $benefits_table = $wpdb->prefix . 'matrix_benefits';
        $existing_benefits = (int) $wpdb->get_var("SELECT COUNT(*) FROM $benefits_table");
        if ($existing_benefits === 0) {
            $now = current_time('mysql');
            $wpdb->insert($benefits_table, [
                'title'             => 'CUG',
                'slug'              => 'cug',
                'icon'              => 'dashicons-phone',
                'short_description' => __('Closed User Group calls — talk to fellow members at preferential rates.', 'matrix-mlm'),
                'long_description'  => __('Members enrolled in the Closed User Group benefit get reduced-rate (or free) on-net calls and SMS to other CUG members. Activation details and the supported telco are configured by the administrator.', 'matrix-mlm'),
                'cta_label'         => '',
                'cta_url'           => '',
                'display_order'     => 10,
                'status'            => 'active',
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
            $wpdb->insert($benefits_table, [
                'title'             => 'Health Insurance',
                'slug'              => 'health-insurance',
                'icon'              => 'dashicons-heart',
                'short_description' => __('Comprehensive health cover for you and your immediate family.', 'matrix-mlm'),
                'long_description'  => __('Active members are entitled to enrol in our partner Health Insurance scheme. Coverage includes outpatient consultations, emergency care, and selected inpatient procedures. The administrator will publish the partner provider, eligible plans, and enrolment instructions on this page.', 'matrix-mlm'),
                'cta_label'         => '',
                'cta_url'           => '',
                'display_order'     => 20,
                'status'            => 'active',
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
            // Loans card. Slug 'loans' is what
            // Matrix_MLM_User_Benefits::is_loan_slug() matches against
            // to swap the generic Read more CTA for the Apply for
            // Loan modal. Operators can rename the title freely; they
            // can also rename the slug as long as it stays prefixed
            // 'loan-' (e.g. 'loan-sme', 'loan-farming') and the
            // detection still fires.
            $wpdb->insert($benefits_table, [
                'title'             => 'Loans',
                'slug'              => 'loans',
                'icon'              => 'dashicons-money-alt',
                'short_description' => __('Apply for a business loan with flexible repayment plans tailored to your matrix level.', 'matrix-mlm'),
                'long_description'  => __('Active members in good standing are eligible to apply for a Liberty Hub business loan. Loans range from ₦50,000 to ₦5,000,000 with a 1-12 month tenor and flat interest of 5-6.5% per month. The application captures personal, account, project, and guarantor details — your administrator reviews each submission before disbursement.', 'matrix-mlm'),
                'cta_label'         => '',
                'cta_url'           => '',
                'display_order'     => 30,
                'status'            => 'active',
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
        }

        // Default settings
        $defaults = [
            'matrix_mlm_site_title' => 'Matrix MLM Pro',
            'matrix_mlm_currency' => 'NGN',
            'matrix_mlm_currency_symbol' => '₦',
            'matrix_mlm_min_deposit' => 1000,
            'matrix_mlm_max_deposit' => 5000000,
            'matrix_mlm_min_withdraw' => 1000,
            'matrix_mlm_max_withdraw' => 1000000,
            'matrix_mlm_withdraw_charge_type' => 'percent',
            'matrix_mlm_withdraw_charge' => 5,
            'matrix_mlm_transfer_charge_type' => 'fixed',
            'matrix_mlm_transfer_charge' => 100,
            'matrix_mlm_min_transfer' => 500,
            'matrix_mlm_email_verification' => 1,
            'matrix_mlm_sms_verification' => 0,
            'matrix_mlm_2fa_enabled' => 1,
            'matrix_mlm_registration_enabled' => 1,
            'matrix_mlm_gdpr_enabled' => 1,
            'matrix_mlm_captcha_enabled' => 0,
            'matrix_mlm_captcha_site_key' => '',
            'matrix_mlm_captcha_secret_key' => '',
            'matrix_mlm_livechat_enabled' => 0,
            'matrix_mlm_livechat_code' => '',
            'matrix_mlm_whatsapp_enabled' => 0,
            'matrix_mlm_whatsapp_number' => '',
            'matrix_mlm_whatsapp_message' => '',
            'matrix_mlm_primary_color' => '#4f46e5',
            'matrix_mlm_secondary_color' => '#7c3aed',
        ];

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }

    /**
     * Create a default root user for the referral system.
     *
     * Since referral codes are mandatory for registration, this user serves
     * as the top of the referral tree so the first real users can sign up.
     *
     * The "default root user" is always an existing WordPress administrator,
     * never a freshly synthesised account. Resolution order:
     *
     *   1. The user activating the plugin (get_current_user_id()).
     *   2. The first administrator on the install, if any.
     *   3. Defer. No matrix_user_meta row is created until at least one
     *      administrator exists; this function is invoked on every plugin
     *      boot from Matrix_MLM_Core::run(), so the binding self-heals
     *      automatically the next time the function runs after a real
     *      admin appears.
     *
     * Earlier revisions of this method, on the deferred path, called
     * wp_create_user('matrix_admin', ...) with a generated password that
     * was never surfaced. That created a predictable-username admin
     * account on WP-CLI / headless / cloned installs that no operator
     * had explicitly provisioned, with a password recoverable only via
     * the "Lost your password?" flow against admin_email — making the
     * username a stage-1 enumeration primitive for whoever controlled
     * (or could read) that mailbox. The block is removed; deferring is
     * the safer behaviour, and the caller is idempotent anyway.
     */
    public static function create_default_user() {
        global $wpdb;

        // Check if a default root user already exists
        $default_ref_code = 'MATRIX01';
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}matrix_user_meta WHERE referral_code = %s",
            $default_ref_code
        ));

        if ($existing) {
            return; // Already created
        }

        // Use the current WordPress admin (the user activating the plugin) as the root user
        $admin_id = get_current_user_id();

        // If no user is logged in (unlikely during activation), get the first admin
        if (!$admin_id) {
            $admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC']);
            if (!empty($admins)) {
                $admin_id = $admins[0]->ID;
            }
        }

        // No administrator exists on this install yet. Defer rather than
        // synthesise an account; the next boot after a real admin is
        // provisioned will run through this function again and bind the
        // root referral code to that admin. See the doc comment above
        // for the rationale.
        if (!$admin_id) {
            return;
        }

        // Check if this user already has a matrix_user_meta entry
        $has_meta = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}matrix_user_meta WHERE user_id = %d",
            $admin_id
        ));

        if (!$has_meta) {
            $wpdb->insert($wpdb->prefix . 'matrix_user_meta', [
                'user_id' => $admin_id,
                'referral_code' => $default_ref_code,
                'referred_by' => null,
                'phone' => '',
                'balance' => 0.00,
                'status' => 'active',
                'email_verified' => 1,
            ]);
        }

        // Store the default referral code in options for easy display/reference
        update_option('matrix_mlm_default_referral_code', $default_ref_code);
    }
}
