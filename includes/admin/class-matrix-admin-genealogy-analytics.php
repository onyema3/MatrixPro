<?php
/**
 * Genealogy Analytics admin page.
 *
 * Aggregate, company-wide views over the matrix tree that the
 * per-user Genealogy editor (class-matrix-admin-genealogy.php) does
 * not surface. The drag-and-drop editor answers "where does this
 * one user sit, and where do I need to move them?"; this page
 * answers the orthogonal operational questions:
 *
 *   - Which branches are orphaned (rows whose parent_id no longer
 *     resolves to a live position)?
 *   - Which levels are stuck — i.e. ≥90% full company-wide AND
 *     have not seen a single new placement in 180+ days?
 *   - Who are the highest-velocity sponsors over the last 30 / 90
 *     days, so the operator can spot rising stars (and nudge slow
 *     movers)?
 *   - Which subtrees have gone dormant — total_downline > 0 but
 *     not a single descendant joined in 90+ days?
 *   - What does the weekly registration cadence look like over
 *     the last 12 weeks?
 *
 * Everything is derived from columns that already exist:
 *   matrix_positions.{user_id, sponsor_id, parent_id, level,
 *                     total_downline, status, joined_at}
 *   matrix_user_meta.{status, created_at, last_login}
 *   matrix_plans.{name, width, depth}
 *
 * No new schema, no new indexes, no new background jobs. The
 * heaviest query (dormant-subtree detection) walks the parent_id
 * chain UP from recent joiners — bounded by plan depth (≤ 20
 * iterations per Matrix_MLM_Plan_Engine::validate_matrix), so even
 * a six-figure positions table degrades gracefully.
 *
 * Styling: re-uses .matrix-admin-stats / .stat-card / .matrix-
 * admin-card / .matrix-badge from admin/css/matrix-admin.css
 * (already enqueued on every Matrix admin screen) so the page
 * looks at home next to the other admin surfaces. Page-specific
 * extras (the bar chart, level-fill progress bars, severity
 * colouring) are inlined under the `mma-analytics-*` class
 * prefix — same separation strategy the genealogy editor uses
 * with its `mma-tree-*` namespace.
 *
 * Capability gate: manage_matrix_mlm. This is a read-only insight
 * surface, so it sits at the same gate as the main Dashboard
 * rather than the more restrictive manage_matrix_users that the
 * genealogy editor uses. An operator who can view the Dashboard
 * stat cards can view these slices of the same data.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Genealogy_Analytics {

    /**
     * Threshold (percent fill of theoretical capacity) above which
     * a level is a candidate for "stuck". Picked at 90% because
     * anything below that may simply be normal partial fill —
     * 90%+ means the level is structurally near-saturated, which
     * is the precondition for the second half of the test
     * (no recent growth) to be meaningful.
     */
    const STUCK_FILL_PERCENT = 90;

    /**
     * Days without a single new placement at a given level for
     * that level to be flagged "stuck". Six months is the
     * operator's lived definition: the brief explicitly calls
     * out "level 3 has been 90% full for 6 months". Tunable
     * via the matrix_mlm_stuck_level_days option for installs
     * that want a different cadence.
     */
    const STUCK_NO_GROWTH_DAYS = 180;

    /**
     * A subtree is dormant when its root has total_downline ≥ 1
     * but no descendant has joined in the last DORMANT_DAYS
     * days. 90 days mirrors the brief verbatim and is also the
     * threshold below which "we should reach out to that branch"
     * starts to feel actionable rather than panicky.
     */
    const DORMANT_DAYS = 90;

    /**
     * Minimum subtree size before we'll call a branch dormant.
     * Filters out tiny single-person stubs that look dormant
     * trivially because their owner just hasn't recruited
     * anyone yet — those are a different problem (new-member
     * onboarding) and would drown out the real signal of large
     * branches that have stopped growing. Five descendants is
     * the smallest size where a halt in growth is worth the
     * operator's attention.
     */
    const DORMANT_MIN_DOWNLINE = 5;

    /**
     * Hard cap on rows displayed in any of the long lists
     * (orphans, dormant subtrees, top sponsors). Keeps the
     * rendered page bounded on big installs; the operator can
     * always export the full list via an extension later.
     */
    const LIST_LIMIT = 50;

    /**
     * Maximum parent-chain walk iterations when expanding the
     * "ancestors with recent activity" set for dormant
     * detection. Matches Matrix_MLM_Plan_Engine::validate_matrix's
     * 20-level cap on plan depth, with 5 extra rounds of slack
     * in case a corrupted parent_id chain pushes us past the
     * legitimate ceiling. Iterations are O(distinct parent_ids
     * still on the frontier) per round, so the loop terminates
     * naturally well before the cap on healthy data.
     */
    const ANCESTRY_MAX_DEPTH = 25;

    /**
     * Render the analytics dashboard.
     *
     * URL parameters:
     *   - plan_id  int   Restrict the entire dashboard to a single
     *                    plan. Defaults to "all plans" when omitted
     *                    or 0.
     *   - sponsor_window int  30 or 90 — the lookback window for
     *                    the highest-velocity sponsors table.
     *                    Defaults to 30 (the briefer window
     *                    surfaces sharper recent signal).
     */
    public function render() {
        // Defensive capability re-check. add_submenu_page already
        // gates the menu link, but a direct call to render() must
        // not bypass the gate.
        if (!current_user_can('manage_matrix_mlm')) {
            wp_die(__('You do not have permission to access this page.', 'matrix-mlm'));
        }

        global $wpdb;

        // Plan filter. We pull every plan (active + inactive) for
        // the dropdown — analytics is forensic, so an operator
        // looking at a wound-down plan should still be able to
        // inspect its tree state. The "All plans" option is
        // synthesised at id=0.
        $plans = $wpdb->get_results(
            "SELECT id, name, width, depth, status
               FROM {$wpdb->prefix}matrix_plans
              ORDER BY status ASC, price ASC, id ASC"
        );

        $selected_plan_id = isset($_GET['plan_id']) ? max(0, (int) $_GET['plan_id']) : 0;

        // The plan_id filter is threaded into every query as a
        // pre-built WHERE fragment so each branch below stays a
        // single SELECT instead of a duplicated active/all
        // copy-paste pair.
        $plan_filter_sql      = '';
        $plan_filter_sql_pref = ''; // version with explicit table alias `p.`
        $plan_filter_args     = [];
        if ($selected_plan_id > 0) {
            $plan_filter_sql      = ' AND plan_id = %d';
            $plan_filter_sql_pref = ' AND p.plan_id = %d';
            $plan_filter_args     = [$selected_plan_id];
        }

        $sponsor_window = isset($_GET['sponsor_window']) ? (int) $_GET['sponsor_window'] : 30;
        if (!in_array($sponsor_window, [30, 90, 365], true)) {
            $sponsor_window = 30;
        }

        // Compute every dataset up front. They're each individually
        // cheap, and rendering them in one pass keeps the page's
        // information architecture front-and-centre instead of
        // interleaved with PHP I/O.
        $headline       = $this->compute_headline_stats($selected_plan_id, $plan_filter_sql, $plan_filter_args);
        $weekly_growth  = $this->compute_weekly_growth($selected_plan_id, $plan_filter_sql, $plan_filter_args);
        $level_fill     = $this->compute_level_fill($plans, $selected_plan_id);
        $top_sponsors   = $this->compute_top_sponsors($selected_plan_id, $plan_filter_sql_pref, $plan_filter_args, $sponsor_window);
        $orphans        = $this->compute_orphan_branches($selected_plan_id, $plan_filter_sql_pref, $plan_filter_args);
        $dormant        = $this->compute_dormant_subtrees($selected_plan_id, $plan_filter_sql, $plan_filter_args);

        // Done with computation; render.
        $page_url = admin_url('admin.php?page=matrix-mlm-genealogy-analytics');
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1 class="matrix-admin-title">
                <?php esc_html_e('Genealogy Analytics', 'matrix-mlm'); ?>
            </h1>

            <p class="description" style="max-width:780px;margin:-10px 0 18px;">
                <?php
                esc_html_e(
                    'Company-wide views over the matrix tree the per-user editor does not surface — orphan branches, stuck levels, fastest-moving sponsors, and dormant subtrees. All figures are computed live from the existing positions and user-meta tables.',
                    'matrix-mlm'
                );
                ?>
            </p>

            <?php $this->render_filter_bar($plans, $selected_plan_id, $sponsor_window, $page_url); ?>

            <?php $this->render_headline_cards($headline); ?>

            <?php $this->render_weekly_growth_chart($weekly_growth); ?>

            <?php $this->render_level_fill_table($level_fill); ?>

            <?php $this->render_top_sponsors_table($top_sponsors, $sponsor_window); ?>

            <?php $this->render_orphans_table($orphans); ?>

            <?php $this->render_dormant_table($dormant); ?>

            <?php $this->render_inline_styles(); ?>
        </div>
        <?php
    }

    /* -----------------------------------------------------------
     * Computation helpers — each returns a plain PHP array shaped
     * for its renderer; all SQL placeholders are passed through
     * $wpdb->prepare with explicit type tokens.
     * --------------------------------------------------------- */

    /**
     * Headline counters: total positions, new in 30 / 90 days,
     * inactive count, distinct sponsors active in 30 days.
     *
     * Single SQL pass with conditional aggregates so we hit the
     * positions table once regardless of how many cards we draw.
     */
    private function compute_headline_stats($plan_id, $plan_filter_sql, $plan_filter_args) {
        global $wpdb;

        $sql = "SELECT
                    COUNT(*)                                                 AS total_positions,
                    SUM(CASE WHEN status = 'active'   THEN 1 ELSE 0 END)     AS active_positions,
                    SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END)     AS inactive_positions,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END)    AS completed_positions,
                    SUM(CASE WHEN joined_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS new_30,
                    SUM(CASE WHEN joined_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN 1 ELSE 0 END) AS new_90,
                    COUNT(DISTINCT CASE
                        WHEN sponsor_id IS NOT NULL
                         AND joined_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                        THEN sponsor_id END)                                 AS active_sponsors_30
                  FROM {$wpdb->prefix}matrix_positions
                 WHERE 1 = 1" . $plan_filter_sql;

        $row = empty($plan_filter_args)
            ? $wpdb->get_row($sql, ARRAY_A)
            : $wpdb->get_row($wpdb->prepare($sql, $plan_filter_args), ARRAY_A);

        if (!$row) {
            $row = [];
        }

        // Cast everything to int — SUM() returns NULL when the
        // table is empty, which would render as "0" anyway but
        // breaks the percentage maths a few cards down.
        return [
            'total_positions'     => (int) ($row['total_positions']     ?? 0),
            'active_positions'    => (int) ($row['active_positions']    ?? 0),
            'inactive_positions'  => (int) ($row['inactive_positions']  ?? 0),
            'completed_positions' => (int) ($row['completed_positions'] ?? 0),
            'new_30'              => (int) ($row['new_30']              ?? 0),
            'new_90'              => (int) ($row['new_90']              ?? 0),
            'active_sponsors_30'  => (int) ($row['active_sponsors_30']  ?? 0),
        ];
    }

    /**
     * Weekly registration histogram for the last 12 ISO weeks
     * (89 days back, rounded out to the start of the calendar
     * week so the leftmost bar is a complete bucket). Returns an
     * ordered array of [label, count] pairs, oldest week first.
     *
     * We compute the bucket key in PHP rather than relying on
     * MySQL's WEEK() function because WEEK() defaults vary by
     * server config (mode 0 vs mode 3) and the rounding-by-day
     * approach is portable across MySQL/MariaDB versions.
     */
    private function compute_weekly_growth($plan_id, $plan_filter_sql, $plan_filter_args) {
        global $wpdb;

        // Build empty buckets first so weeks with zero
        // registrations still render as a baseline-height bar
        // rather than disappearing entirely (a missing bar would
        // mislead the operator into thinking we lost data).
        $buckets = [];
        for ($i = 11; $i >= 0; $i--) {
            $week_start = strtotime('monday this week') - ($i * 7 * 86400);
            $key        = date('Y-m-d', $week_start);
            $buckets[$key] = [
                'label' => date('M j', $week_start),
                'count' => 0,
            ];
        }

        // Pull the join-date histogram in one query.
        $sql = "SELECT DATE_FORMAT(joined_at, '%Y-%m-%d') AS d
                  FROM {$wpdb->prefix}matrix_positions
                 WHERE joined_at >= DATE_SUB(NOW(), INTERVAL 12 WEEK)" . $plan_filter_sql;

        $rows = empty($plan_filter_args)
            ? $wpdb->get_col($sql)
            : $wpdb->get_col($wpdb->prepare($sql, $plan_filter_args));

        foreach ($rows as $d) {
            // Snap each join date to the Monday of its ISO week.
            $week_start = strtotime('monday this week', strtotime($d));
            $key        = date('Y-m-d', $week_start);
            if (isset($buckets[$key])) {
                $buckets[$key]['count']++;
            }
        }

        return array_values($buckets);
    }

    /**
     * Per-(plan, level) fill statistics: count of positions at
     * the level, theoretical capacity (width^level), days since
     * the most recent placement at that level, and a "stuck"
     * flag when fill ≥ STUCK_FILL_PERCENT% AND days_since_growth
     * ≥ STUCK_NO_GROWTH_DAYS.
     *
     * Walks every plan we were given so the operator can pivot
     * between plans visually; the plan_id filter on the outer
     * page just hides the rows belonging to other plans rather
     * than re-running the query (the query is bounded by
     * plans × max_depth, which is tiny).
     */
    private function compute_level_fill($plans, $selected_plan_id) {
        global $wpdb;

        if (empty($plans)) {
            return [];
        }

        // One round-trip per plan — each query returns at most
        // ~20 rows (depth cap) so total work is bounded.
        $rows = [];
        $stuck_no_growth_days = (int) apply_filters(
            'matrix_mlm_stuck_level_days',
            self::STUCK_NO_GROWTH_DAYS
        );
        $stuck_fill_percent   = (int) apply_filters(
            'matrix_mlm_stuck_level_fill_percent',
            self::STUCK_FILL_PERCENT
        );

        foreach ($plans as $plan) {
            if ($selected_plan_id > 0 && (int) $plan->id !== $selected_plan_id) {
                continue;
            }

            $plan_id = (int) $plan->id;
            $width   = max(1, (int) $plan->width);
            $depth   = max(1, (int) $plan->depth);

            $level_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT level,
                        COUNT(*)                                                       AS positions,
                        MAX(joined_at)                                                 AS last_join,
                        DATEDIFF(NOW(), MAX(joined_at))                                AS days_since
                   FROM {$wpdb->prefix}matrix_positions
                  WHERE plan_id = %d AND status = 'active'
                  GROUP BY level
                  ORDER BY level ASC",
                $plan_id
            ));

            $by_level = [];
            foreach ($level_rows as $lr) {
                $by_level[(int) $lr->level] = $lr;
            }

            // Render a row per level 1..depth so empty levels
            // still appear (they're informative — "level 5 has
            // zero placements" is itself a finding).
            for ($level = 1; $level <= $depth; $level++) {
                $lr        = $by_level[$level] ?? null;
                $positions = $lr ? (int) $lr->positions : 0;
                $capacity  = (int) pow($width, $level);
                $fill_pct  = $capacity > 0 ? round(($positions / $capacity) * 100, 1) : 0.0;
                $days      = $lr && $lr->last_join ? (int) $lr->days_since : null;

                $is_stuck = (
                    $fill_pct >= $stuck_fill_percent
                    && $days !== null
                    && $days >= $stuck_no_growth_days
                );

                $rows[] = [
                    'plan_id'    => $plan_id,
                    'plan_name'  => $plan->name,
                    'level'      => $level,
                    'positions'  => $positions,
                    'capacity'   => $capacity,
                    'fill_pct'   => $fill_pct,
                    'days_since' => $days,
                    'last_join'  => $lr->last_join ?? null,
                    'is_stuck'   => $is_stuck,
                ];
            }
        }

        return $rows;
    }

    /**
     * Top sponsors by direct-placement count over the chosen
     * lookback window. We use sponsor_id (not parent_id) because
     * the brief asks about *who is recruiting* — sponsor_id is
     * the recruiter regardless of where the matrix engine
     * decided to slot the new member.
     */
    private function compute_top_sponsors($plan_id, $plan_filter_sql_pref, $plan_filter_args, $window_days) {
        global $wpdb;

        $sql = "SELECT
                    p.sponsor_id,
                    u.user_login,
                    u.display_name,
                    COUNT(*)            AS placements,
                    MIN(p.joined_at)    AS earliest,
                    MAX(p.joined_at)    AS latest
                  FROM {$wpdb->prefix}matrix_positions p
                  LEFT JOIN {$wpdb->users} u ON u.ID = p.sponsor_id
                 WHERE p.sponsor_id IS NOT NULL
                   AND p.joined_at >= DATE_SUB(NOW(), INTERVAL %d DAY)"
                   . $plan_filter_sql_pref . "
                 GROUP BY p.sponsor_id, u.user_login, u.display_name
                 ORDER BY placements DESC, latest DESC
                 LIMIT %d";

        $args = array_merge([$window_days], $plan_filter_args, [self::LIST_LIMIT]);

        return (array) $wpdb->get_results($wpdb->prepare($sql, $args));
    }

    /**
     * Orphan-branch detector. A position is orphaned if:
     *   (a) parent_id IS NOT NULL but no row in matrix_positions
     *       has that id (parent was deleted manually); OR
     *   (b) parent_id resolves to a row whose status is not
     *       'active' (parent was banned / soft-deleted but the
     *       child wasn't reparented).
     *
     * Both indicate a structural break that the per-user editor
     * cannot easily surface (it only shows one tree at a time)
     * but matters operationally because commission calculation
     * walks parent_id chains and an inactive parent silently
     * truncates the upline.
     *
     * Returned shape includes the *child*'s details (this is the
     * row that's adrift) plus the parent_id and a string
     * describing the break, so the table can render a single
     * actionable row per orphan.
     */
    private function compute_orphan_branches($plan_id, $plan_filter_sql_pref, $plan_filter_args) {
        global $wpdb;

        // Single self-LEFT-JOIN. WHERE clause picks rows where
        // either the join missed (NULL on parent.id) OR matched
        // an inactive parent.
        $sql = "SELECT
                    p.id            AS position_id,
                    p.user_id,
                    p.plan_id,
                    p.parent_id,
                    p.level,
                    p.total_downline,
                    p.status,
                    p.joined_at,
                    u.user_login,
                    pl.name         AS plan_name,
                    parent.id       AS parent_exists_id,
                    parent.status   AS parent_status,
                    parent_user.user_login AS parent_user_login
                  FROM {$wpdb->prefix}matrix_positions p
                  LEFT JOIN {$wpdb->prefix}matrix_positions parent
                         ON parent.id = p.parent_id
                  LEFT JOIN {$wpdb->users} u           ON u.ID = p.user_id
                  LEFT JOIN {$wpdb->users} parent_user ON parent_user.ID = parent.user_id
                  LEFT JOIN {$wpdb->prefix}matrix_plans pl ON pl.id = p.plan_id
                 WHERE p.parent_id IS NOT NULL
                   AND (parent.id IS NULL OR parent.status <> 'active')"
                   . $plan_filter_sql_pref . "
                 ORDER BY p.total_downline DESC, p.joined_at DESC
                 LIMIT %d";

        $args = array_merge($plan_filter_args, [self::LIST_LIMIT]);

        $rows = empty($args)
            ? $wpdb->get_results($sql)
            : $wpdb->get_results($wpdb->prepare($sql, $args));

        // Annotate each row with the human-readable break reason.
        foreach ($rows as $r) {
            if ($r->parent_exists_id === null) {
                $r->reason = __('Parent row missing', 'matrix-mlm');
            } elseif ($r->parent_status !== 'active') {
                $r->reason = sprintf(
                    /* translators: %s: parent position status (banned/inactive/etc.) */
                    __('Parent %s', 'matrix-mlm'),
                    (string) $r->parent_status
                );
            } else {
                // Should not be reachable given the WHERE clause
                // but kept as a safety net so the renderer can't
                // spit out a blank cell.
                $r->reason = __('Parent inactive', 'matrix-mlm');
            }
        }

        return $rows;
    }

    /**
     * Dormant-subtree detector. We want every position p where
     * p.total_downline ≥ DORMANT_MIN_DOWNLINE AND no descendant
     * of p (at any depth) has joined within DORMANT_DAYS.
     *
     * Rather than recursing DOWN from each candidate (quadratic
     * on big trees), we invert the problem and walk UP from
     * recent joiners:
     *
     *   1. Seed the "recently active" set with the parent_id of
     *      every position that joined in the last DORMANT_DAYS.
     *   2. Iteratively expand the set by collecting the
     *      parent_id of every position id we've already
     *      collected, until no new ids come back.
     *   3. Any position whose id is NOT in that set has zero
     *      recent descendants — by definition a dormant subtree
     *      root, modulo the size threshold.
     *
     * Bounded by ANCESTRY_MAX_DEPTH iterations and by the count
     * of distinct parent_ids on the frontier each round, which
     * shrinks rapidly toward the matrix root. On a 10k-positions
     * install with normal recruitment cadence the loop typically
     * terminates in ≤ depth iterations.
     */
    private function compute_dormant_subtrees($plan_id, $plan_filter_sql, $plan_filter_args) {
        global $wpdb;

        // Step 1: seed set — every parent_id that has at least
        // one direct child joined in the last DORMANT_DAYS days.
        // We deliberately collect parent_id (not the joiner's
        // own id) because a brand-new joiner doesn't qualify
        // anyone as dormant — the joiner's *parent* is the
        // smallest ancestor with proven recent activity, so the
        // walk-up should start there.
        $dormant_days = (int) apply_filters('matrix_mlm_dormant_days', self::DORMANT_DAYS);

        $seed_sql = "SELECT DISTINCT parent_id
                       FROM {$wpdb->prefix}matrix_positions
                      WHERE parent_id IS NOT NULL
                        AND joined_at >= DATE_SUB(NOW(), INTERVAL %d DAY)"
                      . $plan_filter_sql;

        $seed_args = array_merge([$dormant_days], $plan_filter_args);
        $seed      = $wpdb->get_col($wpdb->prepare($seed_sql, $seed_args));
        $seed      = array_values(array_unique(array_map('intval', array_filter($seed))));

        $active_ids = $seed;
        $frontier   = $seed;

        // Step 2: walk up the chain.
        for ($i = 0; $i < self::ANCESTRY_MAX_DEPTH; $i++) {
            if (empty($frontier)) {
                break;
            }

            // Chunk the frontier so we don't blow past
            // max_allowed_packet on truly massive intermediate
            // sets. 1000 ids per round is well under the default
            // 16MB packet cap and keeps the IN() clause readable
            // in the slow query log if anyone goes hunting.
            $next = [];
            foreach (array_chunk($frontier, 1000) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
                $sql_step = "SELECT DISTINCT parent_id
                               FROM {$wpdb->prefix}matrix_positions
                              WHERE id IN ({$placeholders})
                                AND parent_id IS NOT NULL";
                $rows = $wpdb->get_col($wpdb->prepare($sql_step, $chunk));
                foreach ($rows as $r) {
                    $r = (int) $r;
                    if ($r > 0) {
                        $next[$r] = true;
                    }
                }
            }

            // Drop ids we've already accepted — that's where the
            // walk converges and the loop is allowed to exit.
            $next = array_diff(array_keys($next), $active_ids);
            if (empty($next)) {
                break;
            }
            $active_ids = array_merge($active_ids, $next);
            $frontier   = array_values($next);
        }

        // Step 3: select dormant candidates. We pull the largest
        // subtrees first because that's the operator's high-
        // value signal — a 200-person dead branch deserves
        // attention before a 6-person one.
        $min_downline = (int) apply_filters(
            'matrix_mlm_dormant_min_downline',
            self::DORMANT_MIN_DOWNLINE
        );

        $where = "p.total_downline >= %d AND p.status = 'active'";
        $args  = [$min_downline];

        if (!empty($active_ids)) {
            // Slice the NOT-IN list so the prepared statement
            // stays under MySQL's max placeholders (~65k). On
            // a healthy install this list is in the thousands;
            // on a tiny install it might be under a hundred.
            // Either way, splitting the negation into multiple
            // ANDs is the only safe transform that preserves
            // semantics across an arbitrary list size.
            foreach (array_chunk($active_ids, 1000) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
                $where       .= " AND p.id NOT IN ({$placeholders})";
                $args         = array_merge($args, $chunk);
            }
        }

        if ($plan_id > 0) {
            $where .= " AND p.plan_id = %d";
            $args[] = $plan_id;
        }

        $sql = "SELECT
                    p.id            AS position_id,
                    p.user_id,
                    p.plan_id,
                    p.level,
                    p.total_downline,
                    p.joined_at,
                    DATEDIFF(NOW(), p.joined_at) AS days_since_joined,
                    u.user_login,
                    u.display_name,
                    pl.name         AS plan_name
                  FROM {$wpdb->prefix}matrix_positions p
                  LEFT JOIN {$wpdb->users} u                ON u.ID = p.user_id
                  LEFT JOIN {$wpdb->prefix}matrix_plans pl  ON pl.id = p.plan_id
                 WHERE {$where}
                 ORDER BY p.total_downline DESC, p.joined_at ASC
                 LIMIT %d";

        $args[] = self::LIST_LIMIT;

        return (array) $wpdb->get_results($wpdb->prepare($sql, $args));
    }

    /* -----------------------------------------------------------
     * Renderers — split out so the top-level render() reads as a
     * page outline and so individual sections can be unit-tested
     * by invoking them with a fixture array.
     * --------------------------------------------------------- */

    /**
     * Plan + sponsor-window selector strip at the top of the page.
     * GET form, so the resulting URL is shareable and bookmarkable.
     */
    private function render_filter_bar($plans, $selected_plan_id, $sponsor_window, $page_url) {
        ?>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="mma-analytics-filter-bar">
            <input type="hidden" name="page" value="matrix-mlm-genealogy-analytics">

            <label class="mma-analytics-filter-label">
                <?php esc_html_e('Plan:', 'matrix-mlm'); ?>
                <select name="plan_id" onchange="this.form.submit()">
                    <option value="0" <?php selected($selected_plan_id, 0); ?>>
                        <?php esc_html_e('All plans', 'matrix-mlm'); ?>
                    </option>
                    <?php foreach ($plans as $p): ?>
                        <option value="<?php echo (int) $p->id; ?>" <?php selected($selected_plan_id, (int) $p->id); ?>>
                            <?php
                            echo esc_html(sprintf(
                                '%s (%dx%d)%s',
                                $p->name,
                                (int) $p->width,
                                (int) $p->depth,
                                $p->status !== 'active' ? ' — ' . $p->status : ''
                            ));
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="mma-analytics-filter-label">
                <?php esc_html_e('Sponsor window:', 'matrix-mlm'); ?>
                <select name="sponsor_window" onchange="this.form.submit()">
                    <option value="30"  <?php selected($sponsor_window, 30); ?>><?php esc_html_e('30 days', 'matrix-mlm'); ?></option>
                    <option value="90"  <?php selected($sponsor_window, 90); ?>><?php esc_html_e('90 days', 'matrix-mlm'); ?></option>
                    <option value="365" <?php selected($sponsor_window, 365); ?>><?php esc_html_e('1 year', 'matrix-mlm'); ?></option>
                </select>
            </label>

            <noscript>
                <button type="submit" class="button">
                    <?php esc_html_e('Apply', 'matrix-mlm'); ?>
                </button>
            </noscript>
        </form>
        <?php
    }

    /**
     * Six headline cards. Re-uses the existing .stat-card classes
     * from admin/css/matrix-admin.css so we inherit the dashboard
     * look-and-feel for free.
     */
    private function render_headline_cards($s) {
        $total = max(1, $s['total_positions']); // guard divide-by-zero
        $inactive_pct = round(($s['inactive_positions'] / $total) * 100, 1);
        ?>
        <div class="matrix-admin-stats">
            <div class="stat-card stat-primary">
                <div class="stat-icon"><span class="dashicons dashicons-networking"></span></div>
                <div class="stat-content">
                    <h3><?php echo number_format($s['total_positions']); ?></h3>
                    <p><?php esc_html_e('Total positions', 'matrix-mlm'); ?></p>
                </div>
            </div>
            <div class="stat-card stat-success">
                <div class="stat-icon"><span class="dashicons dashicons-yes-alt"></span></div>
                <div class="stat-content">
                    <h3><?php echo number_format($s['active_positions']); ?></h3>
                    <p><?php esc_html_e('Active', 'matrix-mlm'); ?></p>
                </div>
            </div>
            <div class="stat-card stat-info">
                <div class="stat-icon"><span class="dashicons dashicons-chart-line"></span></div>
                <div class="stat-content">
                    <h3><?php echo number_format($s['new_30']); ?></h3>
                    <p><?php esc_html_e('New (30 days)', 'matrix-mlm'); ?></p>
                </div>
            </div>
            <div class="stat-card stat-purple">
                <div class="stat-icon"><span class="dashicons dashicons-chart-bar"></span></div>
                <div class="stat-content">
                    <h3><?php echo number_format($s['new_90']); ?></h3>
                    <p><?php esc_html_e('New (90 days)', 'matrix-mlm'); ?></p>
                </div>
            </div>
            <div class="stat-card stat-warning">
                <div class="stat-icon"><span class="dashicons dashicons-businessperson"></span></div>
                <div class="stat-content">
                    <h3><?php echo number_format($s['active_sponsors_30']); ?></h3>
                    <p><?php esc_html_e('Active sponsors (30d)', 'matrix-mlm'); ?></p>
                </div>
            </div>
            <div class="stat-card stat-danger">
                <div class="stat-icon"><span class="dashicons dashicons-warning"></span></div>
                <div class="stat-content">
                    <h3><?php echo number_format($s['inactive_positions']); ?> <span class="mma-analytics-pct">(<?php echo number_format($inactive_pct, 1); ?>%)</span></h3>
                    <p><?php esc_html_e('Inactive positions', 'matrix-mlm'); ?></p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Inline 12-week growth bar chart. Pure CSS bars so we can
     * avoid pulling in a charting library on the admin side
     * (the genealogy editor already has html2canvas+d3 and we'd
     * rather not pile more bytes onto the admin head bundle for
     * a six-axis-tick visualisation).
     */
    private function render_weekly_growth_chart($weekly) {
        $max = 0;
        foreach ($weekly as $w) {
            if ($w['count'] > $max) {
                $max = $w['count'];
            }
        }
        // Defensive floor — an empty period would otherwise
        // produce a divide-by-zero in the bar-height fraction.
        $max = max(1, $max);
        ?>
        <div class="matrix-admin-card">
            <h2><?php esc_html_e('Registrations — last 12 weeks', 'matrix-mlm'); ?></h2>
            <div class="mma-analytics-chart">
                <?php foreach ($weekly as $w):
                    $h = ($w['count'] / $max) * 100;
                    // Floor on visible height: below 2% the bar
                    // disappears against the chart background and
                    // looks like missing data.
                    $h = $w['count'] > 0 ? max(2, $h) : 0;
                ?>
                    <div class="mma-analytics-chart-bar-wrap" title="<?php
                        echo esc_attr(sprintf(
                            /* translators: 1: number of registrations 2: week starting */
                            __('%1$d registrations — week of %2$s', 'matrix-mlm'),
                            $w['count'],
                            $w['label']
                        ));
                    ?>">
                        <div class="mma-analytics-chart-count"><?php echo number_format($w['count']); ?></div>
                        <div class="mma-analytics-chart-bar" style="height:<?php echo esc_attr($h); ?>%;"></div>
                        <div class="mma-analytics-chart-label"><?php echo esc_html($w['label']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Per-(plan, level) fill table with the stuck-level highlight.
     * Stuck rows are flagged with a red left border AND a "Stuck"
     * badge so the operator scanning quickly can spot them
     * without parsing the percentage column.
     */
    private function render_level_fill_table($rows) {
        ?>
        <div class="matrix-admin-card">
            <h2>
                <?php esc_html_e('Level fill & stuck levels', 'matrix-mlm'); ?>
                <span class="mma-analytics-help">
                    <?php
                    printf(
                        /* translators: 1: fill threshold 2: days threshold */
                        esc_html__('Stuck = %1$d%% full and no new placement in %2$d+ days', 'matrix-mlm'),
                        (int) self::STUCK_FILL_PERCENT,
                        (int) self::STUCK_NO_GROWTH_DAYS
                    );
                    ?>
                </span>
            </h2>

            <?php if (empty($rows)): ?>
                <p><?php esc_html_e('No level data for the selected filter.', 'matrix-mlm'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Plan', 'matrix-mlm'); ?></th>
                            <th><?php esc_html_e('Level', 'matrix-mlm'); ?></th>
                            <th><?php esc_html_e('Positions', 'matrix-mlm'); ?></th>
                            <th><?php esc_html_e('Capacity', 'matrix-mlm'); ?></th>
                            <th style="width:280px;"><?php esc_html_e('Fill', 'matrix-mlm'); ?></th>
                            <th><?php esc_html_e('Last placement', 'matrix-mlm'); ?></th>
                            <th><?php esc_html_e('Status', 'matrix-mlm'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r):
                            $row_class = $r['is_stuck'] ? 'mma-analytics-row-stuck' : '';
                        ?>
                            <tr class="<?php echo esc_attr($row_class); ?>">
                                <td><?php echo esc_html($r['plan_name']); ?></td>
                                <td><?php echo (int) $r['level']; ?></td>
                                <td><?php echo number_format($r['positions']); ?></td>
                                <td><?php echo number_format($r['capacity']); ?></td>
                                <td>
                                    <div class="mma-analytics-bar-track">
                                        <div class="mma-analytics-bar-fill <?php echo $r['is_stuck'] ? 'mma-analytics-bar-fill-stuck' : ''; ?>"
                                             style="width:<?php echo esc_attr(min(100, (float) $r['fill_pct'])); ?>%;"></div>
                                    </div>
                                    <span class="mma-analytics-bar-pct">
                                        <?php echo number_format((float) $r['fill_pct'], 1); ?>%
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    if ($r['last_join']) {
                                        echo esc_html(date('M j, Y', strtotime($r['last_join'])));
                                        echo ' <span class="mma-analytics-muted">(';
                                        echo (int) $r['days_since'];
                                        echo 'd)</span>';
                                    } else {
                                        echo '<span class="mma-analytics-muted">—</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if ($r['is_stuck']): ?>
                                        <span class="matrix-badge matrix-badge-rejected">
                                            <?php esc_html_e('Stuck', 'matrix-mlm'); ?>
                                        </span>
                                    <?php elseif ((float) $r['fill_pct'] >= 100.0): ?>
                                        <span class="matrix-badge matrix-badge-completed">
                                            <?php esc_html_e('Full', 'matrix-mlm'); ?>
                                        </span>
                                    <?php elseif ($r['positions'] === 0): ?>
                                        <span class="matrix-badge matrix-badge-closed">
                                            <?php esc_html_e('Empty', 'matrix-mlm'); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="matrix-badge matrix-badge-active">
                                            <?php esc_html_e('Healthy', 'matrix-mlm'); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Top sponsors leaderboard, ranked by placements within the
     * chosen lookback window. Username links to the Genealogy
     * editor focused on that sponsor so an operator can dive
     * straight from "Jane is up 18 placements this month" into
     * the visual tree to see *where* those placements landed.
     */
    private function render_top_sponsors_table($rows, $window_days) {
        ?>
        <div class="matrix-admin-card">
            <h2>
                <?php
                printf(
                    /* translators: %d: window length in days */
                    esc_html__('Highest-velocity sponsors (last %d days)', 'matrix-mlm'),
                    (int) $window_days
                );
                ?>
            </h2>

            <?php if (empty($rows)): ?>
                <p><?php esc_html_e('No sponsor activity in the selected window.', 'matrix-mlm'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:60px;">#</th>
                            <th><?php esc_html_e('Sponsor', 'matrix-mlm'); ?></th>
                            <th><?php esc_html_e('New placements', 'matrix-mlm'); ?></th>
                            <th><?php esc_html_e('First in window', 'matrix-mlm'); ?></th>
                            <th><?php esc_html_e('Most recent', 'matrix-mlm'); ?></th>
                            <th><?php esc_html_e('View tree', 'matrix-mlm'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $i => $r): ?>
                            <tr>
                                <td><?php echo (int) $i + 1; ?></td>
                                <td>
                                    <strong><?php echo esc_html($r->user_login ?: ('User #' . (int) $r->sponsor_id)); ?></strong>
                                    <?php if (!empty($r->display_name) && $r->display_name !== $r->user_login): ?>
                                        <span class="mma-analytics-muted">(<?php echo esc_html($r->display_name); ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?php echo number_format((int) $r->placements); ?></strong></td>
                                <td><?php echo $r->earliest ? esc_html(date('M j, Y', strtotime($r->earliest))) : '—'; ?></td>
                                <td><?php echo $r->latest   ? esc_html(date('M j, Y', strtotime($r->latest)))   : '—'; ?></td>
                                <td>
                                    <a class="button button-small"
                                       href="<?php echo esc_url(admin_url('admin.php?page=matrix-mlm-genealogy&root_user_id=' . (int) $r->sponsor_id)); ?>">
                                        <?php esc_html_e('Open', 'matrix-mlm'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Orphan branches table.
     */
    private function render_orphans_table($rows) {
        ?>
        <div class="matrix-admin-card">
            <h2>
                <?php esc_html_e('Orphan branches', 'matrix-mlm'); ?>
                <span class="mma-analytics-help">
                    <?php esc_html_e('Positions whose parent_id no longer resolves to an active row', 'matrix-mlm'); ?>
                </span>
            </h2>

            <?php if (empty($rows)): ?>
                <p style="color:#065f46;">
                    <span class="dashicons dashicons-yes-alt" style="color:#10b981;"></span>
                    <?php esc_html_e('No orphan branches detected.', 'matrix-mlm'); ?>
                </p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Position', 'matrix-mlm'); ?></th>
                            <th><?php esc_html_e('User', 'matrix-mlm'); ?></th>
                            <th><?php esc_html_e('Plan', 'matrix-mlm'); ?></th>
                            <th><?php esc_html_e('Level', 'matrix-mlm'); ?></th>
                            <th><?php esc_html_e('Subtree', 'matrix-mlm'); ?></th>
                            <th><?php esc_html_e('Parent #', 'matrix-mlm'); ?></th>
                            <th><?php esc_html_e('Reason', 'matrix-mlm'); ?></th>
                            <th><?php esc_html_e('Action', 'matrix-mlm'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td>#<?php echo (int) $r->position_id; ?></td>
                                <td>
                                    <strong><?php echo esc_html($r->user_login ?: ('User #' . (int) $r->user_id)); ?></strong>
                                </td>
                                <td><?php echo esc_html($r->plan_name ?: ('#' . (int) $r->plan_id)); ?></td>
                                <td><?php echo (int) $r->level; ?></td>
                                <td><?php echo number_format((int) $r->total_downline); ?></td>
                                <td>
                                    #<?php echo (int) $r->parent_id; ?>
                                    <?php if (!empty($r->parent_user_login)): ?>
                                        <span class="mma-analytics-muted">(<?php echo esc_html($r->parent_user_login); ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="matrix-badge matrix-badge-rejected">
                                        <?php echo esc_html($r->reason); ?>
                                    </span>
                                </td>
                                <td>
                                    <a class="button button-small"
                                       href="<?php echo esc_url(admin_url('admin.php?page=matrix-mlm-genealogy&plan_id=' . (int) $r->plan_id . '&root_user_id=' . (int) $r->user_id)); ?>">
                                        <?php esc_html_e('Reassign', 'matrix-mlm'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description" style="margin-top:10px;">
                    <?php
                    printf(
                        /* translators: %d: list cap */
                        esc_html__('Showing up to %d entries, ordered by subtree size descending.', 'matrix-mlm'),
                        (int) self::LIST_LIMIT
                    );
                    ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Dormant subtrees table.
     */
    private function render_dormant_table($rows) {
        ?>
        <div class="matrix-admin-card">
            <h2>
                <?php esc_html_e('Dormant subtrees', 'matrix-mlm'); ?>
                <span class="mma-analytics-help">
                    <?php
                    printf(
                        /* translators: 1: dormant-day threshold 2: minimum subtree size */
                        esc_html__('Subtrees of %2$d+ members with no new descendant in %1$d+ days', 'matrix-mlm'),
                        (int) self::DORMANT_DAYS,
                        (int) self::DORMANT_MIN_DOWNLINE
                    );
                    ?>
                </span>
            </h2>

            <?php if (empty($rows)): ?>
                <p style="color:#065f46;">
                    <span class="dashicons dashicons-yes-alt" style="color:#10b981;"></span>
                    <?php esc_html_e('Every qualifying subtree has produced at least one new placement in the recent window. Healthy growth across the tree.', 'matrix-mlm'); ?>
                </p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Branch root', 'matrix-mlm'); ?></th>
                            <th><?php esc_html_e('Plan', 'matrix-mlm'); ?></th>
                            <th><?php esc_html_e('Level', 'matrix-mlm'); ?></th>
                            <th><?php esc_html_e('Subtree size', 'matrix-mlm'); ?></th>
                            <th><?php esc_html_e('Root joined', 'matrix-mlm'); ?></th>
                            <th><?php esc_html_e('View tree', 'matrix-mlm'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($r->user_login ?: ('User #' . (int) $r->user_id)); ?></strong>
                                    <?php if (!empty($r->display_name) && $r->display_name !== $r->user_login): ?>
                                        <span class="mma-analytics-muted">(<?php echo esc_html($r->display_name); ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($r->plan_name ?: ('#' . (int) $r->plan_id)); ?></td>
                                <td><?php echo (int) $r->level; ?></td>
                                <td><strong><?php echo number_format((int) $r->total_downline); ?></strong></td>
                                <td>
                                    <?php echo $r->joined_at ? esc_html(date('M j, Y', strtotime($r->joined_at))) : '—'; ?>
                                    <?php if ($r->days_since_joined !== null): ?>
                                        <span class="mma-analytics-muted">(<?php echo (int) $r->days_since_joined; ?>d ago)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a class="button button-small"
                                       href="<?php echo esc_url(admin_url('admin.php?page=matrix-mlm-genealogy&plan_id=' . (int) $r->plan_id . '&root_user_id=' . (int) $r->user_id)); ?>">
                                        <?php esc_html_e('Inspect', 'matrix-mlm'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description" style="margin-top:10px;">
                    <?php
                    printf(
                        /* translators: %d: list cap */
                        esc_html__('Showing the %d largest dormant subtrees. Drill into any of them in the Genealogy editor to see who lives there.', 'matrix-mlm'),
                        (int) self::LIST_LIMIT
                    );
                    ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Page-specific styles. All selectors live under the
     * `mma-analytics-` prefix so they can never collide with the
     * existing genealogy editor's `mma-tree-` rules or with
     * other pages on the Matrix admin section. We don't ship
     * these as a separate CSS file — the page is rendered in
     * one place, so co-locating the styles keeps the diff small
     * and avoids a cache-bust dance on rollout.
     */
    private function render_inline_styles() {
        ?>
        <style>
        .mma-analytics-filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: center;
            background: #fff;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            margin-bottom: 18px;
        }
        .mma-analytics-filter-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            color: #374151;
        }
        .mma-analytics-filter-label select {
            min-width: 160px;
        }

        .mma-analytics-pct {
            font-size: 13px;
            color: #6b7280;
            font-weight: 400;
        }

        .matrix-admin-card h2 .mma-analytics-help {
            font-size: 12px;
            font-weight: 400;
            color: #6b7280;
            margin-left: 10px;
        }

        /* Bar chart */
        .mma-analytics-chart {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 8px;
            align-items: end;
            height: 200px;
            padding: 12px 4px 0;
        }
        .mma-analytics-chart-bar-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-end;
            height: 100%;
            position: relative;
        }
        .mma-analytics-chart-count {
            font-size: 11px;
            color: #4b5563;
            margin-bottom: 4px;
            font-variant-numeric: tabular-nums;
        }
        .mma-analytics-chart-bar {
            width: 80%;
            background: linear-gradient(180deg, #4f46e5 0%, #6366f1 100%);
            border-radius: 4px 4px 0 0;
            min-height: 0;
            transition: height 0.3s ease;
        }
        .mma-analytics-chart-bar-wrap:hover .mma-analytics-chart-bar {
            background: linear-gradient(180deg, #3730a3 0%, #4f46e5 100%);
        }
        .mma-analytics-chart-label {
            font-size: 10px;
            color: #6b7280;
            margin-top: 6px;
            white-space: nowrap;
        }

        /* Level fill bars */
        .mma-analytics-bar-track {
            display: inline-block;
            width: 200px;
            height: 10px;
            background: #f3f4f6;
            border-radius: 5px;
            overflow: hidden;
            vertical-align: middle;
        }
        .mma-analytics-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #10b981 0%, #34d399 100%);
            border-radius: 5px;
            transition: width 0.3s ease;
        }
        .mma-analytics-bar-fill-stuck {
            background: linear-gradient(90deg, #ef4444 0%, #f87171 100%);
        }
        .mma-analytics-bar-pct {
            display: inline-block;
            margin-left: 8px;
            font-variant-numeric: tabular-nums;
            color: #374151;
            font-weight: 600;
            font-size: 13px;
            vertical-align: middle;
        }
        .mma-analytics-row-stuck td:first-child {
            border-left: 4px solid #ef4444;
        }
        .mma-analytics-row-stuck {
            background: #fef2f2 !important;
        }

        .mma-analytics-muted {
            color: #9ca3af;
            font-size: 12px;
        }

        @media (max-width: 900px) {
            .mma-analytics-chart {
                grid-template-columns: repeat(6, 1fr);
                grid-auto-rows: 100px;
                height: auto;
            }
        }
        </style>
        <?php
    }
}
