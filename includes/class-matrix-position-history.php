<?php
/**
 * Matrix Position History — append-only audit log of structural
 * changes to {prefix}matrix_positions rows.
 *
 * Powers the "time machine" view in the genealogy tab: by replaying
 * history rows up to a given point in time, the plan engine can
 * reconstruct the tree as it existed on any past date the schema's
 * been live.
 *
 * Design contract
 * ---------------
 *
 *   - Append-only. We NEVER UPDATE or DELETE rows in this table.
 *     Every meaningful change to a position emits a fresh row. The
 *     reconstruction logic in Matrix_MLM_Plan_Engine reads "the
 *     state of position X at time T" by selecting the row with the
 *     greatest effective_at <= T for that position.
 *
 *   - Captures STRUCTURAL changes only — creation, parent moves,
 *     status flips. Deliberately does NOT capture the per-join
 *     ripple of total_downline counter increments on every
 *     ancestor; on a 9-deep matrix that would multiply audit
 *     volume by 9× per join with no payoff to the time-machine
 *     view (which derives ancestor counts from the snapshot
 *     itself, see build_tree_at_snapshot in the plan engine).
 *
 *   - v1 capture sites:
 *       - 'created'     when Matrix_MLM_Plan_Engine::join_plan()
 *                       inserts a new row into matrix_positions
 *       - 'completed'   when ::complete_matrix() flips status to
 *                       'completed'
 *       - 'backfilled'  one-time INSERT...SELECT from
 *                       maybe_upgrade() for every position that
 *                       existed before the audit table did, dated
 *                       at the position's joined_at so the time
 *                       machine works from day one for legacy data
 *
 *   - Deferred to v2 (documented as a known gap in the time-machine
 *     UI banner): admin Move-in-Genealogy events
 *     (Matrix_MLM_Admin::move_user_position, parent_id changes) and
 *     bulk-import position writes (admin-import phases). Those
 *     write paths are documented at their hook points so a future
 *     PR can add a single record_event() call and the time-machine
 *     view starts honouring them automatically.
 *
 * Why this lives in its own class rather than as a static helper on
 * Matrix_MLM_Database: the database class is concerned with schema
 * lifecycle (create/upgrade/repair); history capture is a runtime
 * domain concern. Keeping them separate means the database class
 * stays focused and the history surface is easy to hook from any
 * existing or future capture site without circular requires.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Position_History {

    /**
     * Allowed event types. Tightening the enum here mirrors the
     * MySQL ENUM column on matrix_position_history.event_type and
     * documents what the time-machine reconstruction expects to
     * see — anything outside this list is rejected silently rather
     * than written, because a bad event_type would only be visible
     * if it cratered the time-machine reconstruction at runtime,
     * which is the worst place to discover a typo.
     *
     * Adding a new event type is a coordinated change across this
     * constant, the schema enum in Matrix_MLM_Database::create_tables(),
     * and the reconstruction logic in
     * Matrix_MLM_Plan_Engine::build_tree_at_snapshot().
     */
    const EVENT_TYPES = ['created', 'moved', 'status_changed', 'completed', 'backfilled'];

    /**
     * Record a single history row for a position.
     *
     * Reads the current matrix_positions row by id and snapshots
     * every structural column into matrix_position_history. The
     * SELECT-then-INSERT shape is deliberate: it's the simplest
     * way to keep the audit row's data in lockstep with whatever
     * the live row actually looks like at the moment of capture,
     * including columns the caller may not have known about
     * (e.g. left_count / right_count which we haven't decided
     * to surface in the time machine but might in v2).
     *
     * Idempotency: the caller is responsible for not calling this
     * twice for the same logical event. We don't dedupe on this
     * side because:
     *
     *   - A 'created' event firing twice would only happen on a
     *     replayed insert (which itself would be a bigger bug), so
     *     dedupe here would mask the upstream issue.
     *   - 'backfilled' is gated by the
     *     `matrix_mlm_position_history_backfilled` option flag in
     *     maybe_upgrade(), and uses an idempotent
     *     INSERT...SELECT with NOT EXISTS, so it's already safe
     *     to call multiple times.
     *   - 'moved' / 'status_changed' / 'completed' fire from
     *     specific code paths that themselves only run once per
     *     state transition.
     *
     * @param int        $position_id    matrix_positions.id
     * @param string     $event_type     One of self::EVENT_TYPES
     * @param int|null   $actor_user_id  WP user id who triggered the
     *                                   change (admin, system, the
     *                                   member themselves). NULL when
     *                                   not applicable / unknowable
     *                                   (e.g. the backfill phase has
     *                                   no human actor).
     * @param string     $notes          Free-form audit note. Empty
     *                                   on most events — reserved
     *                                   for things like
     *                                   "moved by admin during
     *                                   dispute resolution".
     * @return bool true on success, false on bad input or DB error.
     */
    public static function record_event($position_id, $event_type, $actor_user_id = null, $notes = '') {
        $position_id = (int) $position_id;
        $event_type  = (string) $event_type;
        if ($position_id <= 0 || !in_array($event_type, self::EVENT_TYPES, true)) {
            return false;
        }

        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, user_id, plan_id, sponsor_id, parent_id,
                    position, level, total_downline, status
               FROM {$wpdb->prefix}matrix_positions
              WHERE id = %d",
            $position_id
        ));
        if (!$row) {
            // Position vanished between the caller's intent and
            // our SELECT — most likely a hard-delete in tests or
            // a race with the activator. Don't fabricate an audit
            // row from incomplete data; the upstream caller has
            // already either wp_send_json_error'd or moved on.
            return false;
        }

        return self::insert_row([
            'position_id'    => (int) $row->id,
            'user_id'        => (int) $row->user_id,
            'plan_id'        => (int) $row->plan_id,
            'sponsor_id'     => $row->sponsor_id !== null ? (int) $row->sponsor_id : null,
            'parent_id'      => $row->parent_id !== null ? (int) $row->parent_id : null,
            'position'       => (int) $row->position,
            'level'          => (int) $row->level,
            'total_downline' => (int) $row->total_downline,
            'status'         => (string) $row->status,
            'effective_at'   => current_time('mysql'),
            'event_type'     => $event_type,
            'actor_user_id'  => $actor_user_id !== null ? (int) $actor_user_id : null,
            'notes'          => (string) $notes,
        ]);
    }

    /**
     * Lower-level insert. Public so the dbDelta backfill in
     * maybe_upgrade() can chain to backfill_existing() without
     * going through the SELECT round-trip — we already have the
     * row data from a single INSERT...SELECT anyway.
     *
     * Caller is responsible for shaping $row to match the
     * matrix_position_history schema. Missing optional columns
     * (sponsor_id, parent_id, actor_user_id, notes) default to
     * NULL / ''.
     *
     * @param array $row Associative array of column => value.
     * @return bool      true on insert, false on $wpdb error.
     */
    public static function insert_row(array $row) {
        global $wpdb;

        $defaults = [
            'sponsor_id'     => null,
            'parent_id'      => null,
            'actor_user_id'  => null,
            'notes'          => '',
            'effective_at'   => current_time('mysql'),
        ];
        $row = array_merge($defaults, $row);

        // Format string for $wpdb->insert — must mirror $row keys
        // in order. We don't pass an explicit format because $wpdb
        // figures out %s vs %d from the column types; the explicit
        // (int) casts above are what guard against type confusion.
        $ok = $wpdb->insert($wpdb->prefix . 'matrix_position_history', $row);
        return $ok !== false;
    }

    /**
     * One-shot backfill helper invoked from
     * Matrix_MLM_Database::maybe_upgrade() the first time the v1.0.11
     * migration runs. Inserts one 'backfilled' history row for every
     * position that exists today, dated at the position's
     * joined_at column, so the time-machine view has a usable
     * baseline for snapshots dated before the audit table was
     * created.
     *
     * Idempotent on two layers:
     *
     *   - The maybe_upgrade() caller gates this behind a
     *     `matrix_mlm_position_history_backfilled` option flag so
     *     a successful run only happens once per install.
     *   - The SQL itself uses NOT EXISTS to skip positions that
     *     already have a 'backfilled' row, so even a rerun (e.g.
     *     if the option flag was deleted manually) wouldn't
     *     produce duplicates.
     *
     * One-statement INSERT...SELECT keeps this fast even on large
     * imports — MySQL handles tens of thousands of rows in a
     * single statement comfortably (well within the
     * max_allowed_packet budget for a row of this size on the
     * default 64MB MySQL setting).
     *
     * @return int Number of rows inserted (0 on a no-op rerun).
     */
    public static function backfill_existing() {
        global $wpdb;

        $positions_table = $wpdb->prefix . 'matrix_positions';
        $history_table   = $wpdb->prefix . 'matrix_position_history';

        // Sanity guard: if either table is missing the SQL below
        // will hard-error. dbDelta should have created the history
        // table before we get here, but being explicit means a
        // future contributor reading this method sees the
        // dependency stated rather than implied.
        $tables_present = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = %s
                AND TABLE_NAME IN (%s, %s)",
            DB_NAME, $positions_table, $history_table
        ));
        if ($tables_present !== 2) {
            return 0;
        }

        // Insert one 'backfilled' history row per existing
        // position, dated at the position's joined_at and
        // capturing every structural column. The NOT EXISTS clause
        // guards against re-running the backfill — if a position
        // already has a 'backfilled' row, skip it.
        //
        // NULL actor_user_id because there's no human actor for a
        // schema-upgrade-triggered backfill; the time-machine UI
        // shows this as "Initial state (backfilled from current
        // data)" rather than attributing it to anyone.
        $sql = "
            INSERT INTO {$history_table}
                (position_id, user_id, plan_id, sponsor_id, parent_id,
                 position, level, total_downline, status,
                 effective_at, event_type, actor_user_id, notes)
            SELECT
                p.id, p.user_id, p.plan_id, p.sponsor_id, p.parent_id,
                p.position, p.level, p.total_downline, p.status,
                COALESCE(p.joined_at, NOW()), 'backfilled', NULL,
                ''
              FROM {$positions_table} p
             WHERE NOT EXISTS (
                 SELECT 1
                   FROM {$history_table} h
                  WHERE h.position_id = p.id
                    AND h.event_type = 'backfilled'
             )
        ";

        $rows = $wpdb->query($sql);
        return $rows === false ? 0 : (int) $rows;
    }

    /**
     * Resolve a calendar date (YYYY-MM-DD) to the SQL DATETIME the
     * reconstruction queries compare effective_at against.
     *
     * The convention in this plugin is: a date snapshot covers the
     * full local day. So "2026-04-15" → "2026-04-15 23:59:59".
     * That's what members expect when they pick "April 15" — they
     * mean "the tree as it stood at the END of April 15", not at
     * 00:00 (which would silently exclude every join that happened
     * on the same day they picked).
     *
     * Bad input falls through to NULL so callers can short-circuit
     * to the live tree without writing date-validation logic at
     * every site.
     *
     * @param string $ymd Calendar date in YYYY-MM-DD.
     * @return string|null SQL DATETIME or null on parse failure.
     */
    public static function snapshot_date_to_datetime($ymd) {
        $ymd = trim((string) $ymd);
        if ($ymd === '') return null;
        // Reject anything that doesn't look like a calendar date.
        // strtotime() is too permissive — it would happily parse
        // "next thursday" and let an attacker probe the audit
        // table with arbitrary expressions. Whitelist the
        // canonical YYYY-MM-DD shape.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return null;

        $ts = strtotime($ymd . ' 23:59:59');
        if ($ts === false) return null;
        return date('Y-m-d H:i:s', $ts);
    }
}
