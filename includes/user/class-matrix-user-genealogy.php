<?php
/**
 * User Genealogy Tree View
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_User_Genealogy {

    /**
     * Per-render context shared between render() and the recursive
     * render_tree_node() / render_lazy_load_script() helpers below.
     *
     * Set once at the top of render() and read from the inner methods
     * so they don't need long parameter lists. Cleared implicitly by
     * the next render() — we don't bother resetting in a destructor
     * because each Matrix_MLM_User_Genealogy instance is built fresh
     * per dashboard tab render in Matrix_MLM_User_Dashboard, never
     * reused across requests.
     *
     * Keys:
     *   - viewer_user_id    int   The actual logged-in member (the
     *                              authoritative identity for the
     *                              "You" badge and AJAX authorisation).
     *   - display_user_id   int   The tree's root user — equals
     *                              viewer_user_id on a normal view, or
     *                              the pivoted member's user id when
     *                              ?pivot_user_id=X is in play.
     *   - plan_id           int   Plan whose tree is being rendered.
     *   - is_pivoted        bool  True when display_user_id !==
     *                              viewer_user_id, i.e. the trail and
     *                              "Back to my tree" affordance need to
     *                              render.
     */
    private $pivot_state = null;

    /**
     * Public setter for the per-render state shared with
     * render_tree_node() / render_children_inner() / render_empty_slot()
     * and the lazy-load AJAX handler.
     *
     * Exists so Matrix_MLM_Core::process_fetch_subtree() can hydrate
     * the same context the page render uses without going through a
     * full render() pass — the AJAX path doesn't have $_GET state and
     * doesn't compute the level-status badges, but it still needs
     * referral_url, plan_id, level_commissions, etc. to emit the
     * "Refer 1 more here →" empty-slot CTAs introduced by the
     * next-goal feature.
     *
     * Validation is intentionally minimal: we trust the AJAX caller
     * to pass a well-shaped array because every reader inside this
     * class uses isset() / sensible defaults when fields are missing.
     * Keeping it permissive lets us extend the state shape later
     * (more fields) without forcing the AJAX side to update lockstep.
     *
     * @param array $state See $pivot_state docblock for the keys.
     * @return void
     */
    public function set_render_state(array $state) {
        $this->pivot_state = $state;
    }

    public function render($user_id) {
        global $wpdb;

        // Get user's active plans/positions
        $positions = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, pl.name as plan_name, pl.width, pl.depth,
                    pl.level_commission, pl.referral_commission
             FROM {$wpdb->prefix}matrix_positions p 
             JOIN {$wpdb->prefix}matrix_plans pl ON p.plan_id = pl.id 
             WHERE p.user_id = %d AND p.status = 'active' 
             ORDER BY p.joined_at DESC",
            $user_id
        ));

        $selected_plan_id = isset($_GET['plan_id']) ? intval($_GET['plan_id']) : 0;
        if (!$selected_plan_id && !empty($positions)) {
            $selected_plan_id = $positions[0]->plan_id;
        }
        ?>
        <h2><?php _e('Genealogy Tree', 'matrix-mlm'); ?></h2>

        <?php if (empty($positions)): ?>
            <div class="matrix-alert matrix-alert-info">
                <?php _e('You have not joined any plan yet. Join a plan to see your genealogy tree.', 'matrix-mlm'); ?>
            </div>
        <?php else: ?>

        <?php if (count($positions) > 1): ?>
        <div class="matrix-genealogy-plan-selector">
            <label><?php _e('Select Plan:', 'matrix-mlm'); ?></label>
            <select id="genealogy-plan-select" onchange="window.location.href='<?php echo home_url('/matrix-dashboard/?tab=genealogy&plan_id='); ?>'+this.value">
                <?php foreach ($positions as $pos): ?>
                <option value="<?php echo $pos->plan_id; ?>" <?php selected($selected_plan_id, $pos->plan_id); ?>>
                    <?php echo esc_html($pos->plan_name . ' (' . $pos->width . 'x' . $pos->depth . ')'); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <?php
        // Get the selected position
        $current_position = null;
        foreach ($positions as $pos) {
            if ($pos->plan_id == $selected_plan_id) {
                $current_position = $pos;
                break;
            }
        }

        if (!$current_position) {
            echo '<div class="matrix-alert matrix-alert-warning">' . __('Position not found for this plan.', 'matrix-mlm') . '</div>';
            return;
        }

        // Resolve the optional ?pivot_user_id=X URL param into a
        // pivoted view — re-rooting the tree on a downline member's
        // position so the viewer can browse the matrix from that
        // member's perspective. Same-user-id and zero are treated as
        // "no pivot, use the viewer's own position" so the URL stays
        // canonical without us 301-redirecting.
        //
        // The check ladder rejects requests we can't safely honour and
        // silently falls back to the viewer's own tree:
        //   1. Pivot user id is integer-y and not the viewer themselves.
        //   2. Pivot user has an active position in this plan.
        //   3. The viewer can see that position — i.e. it's strictly
        //      below them in the same plan's parent_id chain.
        // Step 3 is the auth gate. It uses the same
        // user_can_view_position() helper that the lazy-load AJAX
        // endpoint relies on, so visibility rules can never diverge
        // between the two surfaces.
        $plan_engine          = new Matrix_MLM_Plan_Engine();
        $pivot_user_id_raw    = isset($_GET['pivot_user_id']) ? (int) $_GET['pivot_user_id'] : 0;
        $pivot_position       = null;
        $pivot_position_id    = 0;
        $pivot_breadcrumbs    = [];

        if ($pivot_user_id_raw > 0 && $pivot_user_id_raw !== (int) $user_id) {
            $candidate_position_id = $plan_engine->get_position_id_for_user_in_plan(
                $pivot_user_id_raw,
                $selected_plan_id
            );
            if ($candidate_position_id > 0
                && $plan_engine->user_can_view_position($user_id, $candidate_position_id)) {

                $pivot_position_id = $candidate_position_id;
                $pivot_breadcrumbs = $plan_engine->build_pivot_breadcrumbs(
                    $pivot_position_id,
                    $user_id
                );
                // Pull the row used to drive the stats/level-badge panel
                // on this render. Single roundtrip; the genealogy tab
                // is dashboard-page-cached anyway.
                $pivot_position = $wpdb->get_row($wpdb->prepare(
                    "SELECT p.*, pl.name as plan_name, pl.width, pl.depth,
                            pl.level_commission, pl.referral_commission
                       FROM {$wpdb->prefix}matrix_positions p
                       JOIN {$wpdb->prefix}matrix_plans pl ON p.plan_id = pl.id
                      WHERE p.id = %d",
                    $pivot_position_id
                ));
            }
            // If either step failed, $pivot_position stays null and we
            // fall through to the regular viewer-rooted render below.
        }

        // Display position drives EVERYTHING below: stats panel, level
        // badges, tree root. When pivoted we substitute the pivot user's
        // position; otherwise we keep the viewer's own. This lets the
        // pivoted view feel like "Sarah's view of her own tree" — same
        // stats, same badges, same shape — with the breadcrumb trail and
        // a back-to-my-tree affordance providing the only visual cues
        // that we're someone else's perspective.
        $is_pivoted        = ($pivot_position !== null);
        $display_position  = $is_pivoted ? $pivot_position : $current_position;
        $display_user_id   = (int) $display_position->user_id;

        // Get tree data — initial render is capped at 4 levels to keep
        // the page payload bounded. Levels deeper than this are
        // surfaced via the on-demand "Show more" button on each leaf,
        // which fires the matrix_action=fetch_subtree AJAX endpoint
        // and injects the next 3 levels in place. The constant lives
        // here (rather than as a class-level constant) because it's
        // also the value we pass to render_tree_node so the renderer
        // knows where to draw the expand button — keeping both reads
        // off the same local variable means a future tuning change
        // (e.g. "render 5 levels for first-time users") only needs to
        // touch one line.
        $initial_render_depth = min(4, (int) $display_position->depth);

        // Build the tree rooted at the display user — when pivoted,
        // that's the pivot member; otherwise it's the viewer.
        $tree = $plan_engine->get_matrix_tree($display_user_id, $selected_plan_id, $initial_render_depth);
        $max_members = Matrix_MLM_Plan_Engine::calculate_max_members($display_position->width, $display_position->depth);

        // Per-level fill status for the badge panel below the stats row.
        // We compute this against every level 1..depth (not capped at 4 like
        // the visual tree) because the badge panel is meant to be a
        // glanceable summary of progress across the *entire* matrix —
        // capping at 4 would silently hide the deepest levels of bigger
        // plans (e.g. a 2x10 matrix would lose levels 5–10 from the
        // summary even though they're still trackable here).
        $level_status = $plan_engine->get_level_completion_status(
            $display_position->id,
            $selected_plan_id,
            $display_position->width,
            $display_position->depth
        );
        $levels_completed = 0;
        foreach ($level_status as $row) {
            if (!empty($row['complete'])) {
                $levels_completed++;
            }
        }
        $all_levels_complete = ($levels_completed === intval($display_position->depth));

        // Compute the genealogy "next goal" — the most-actionable
        // incomplete level — so the renderer can surface a goal
        // banner right above the level-badge grid AND highlight
        // empty slots at that level. See
        // Matrix_MLM_Plan_Engine::get_next_level_goal() for the
        // priority logic. Returns null when the matrix is fully
        // complete; the existing Matrix Master banner already
        // covers that congratulatory state.
        $next_goal = $plan_engine->get_next_level_goal($level_status, $display_position);

        // Decode the level-commission map once and stash it on
        // pivot_state so render_children_inner() can quote per-slot
        // earnings on each empty-slot CTA without re-decoding the
        // JSON for every slot in a wide matrix.
        $level_commissions_decoded = [];
        if (!empty($display_position->level_commission)) {
            $tmp = json_decode((string) $display_position->level_commission, true);
            if (is_array($tmp)) {
                foreach ($tmp as $k => $v) {
                    $level_commissions_decoded[(int) $k] = (float) $v;
                }
            }
        }

        // Referral URL belongs to the VIEWER, not the displayed
        // pivot user — when the tree is pivoted onto Sarah's view,
        // an "invite to fill this slot" CTA should still hand out
        // the viewer's invite link, since the viewer is the one who
        // earns the commission. Empty string means "no invite link
        // available" (the user has no matrix_user_meta row, which
        // shouldn't happen post-registration but is defensive).
        $referral_url = '';
        if (class_exists('Matrix_MLM_User')) {
            $maybe = (string) Matrix_MLM_User::get_referral_link((int) $user_id);
            // get_referral_link returns ".../matrix-register/?ref=" with
            // an empty code if the row is missing — guard against
            // shipping a useless URL.
            if ($maybe !== '' && substr($maybe, -4) !== 'ref=') {
                $referral_url = $maybe;
            }
        }

        // Cache the per-render state for the recursive renderer and
        // the lazy-load script to read without long param lists.
        $this->pivot_state = [
            'viewer_user_id'    => (int) $user_id,
            'display_user_id'   => $display_user_id,
            'plan_id'           => (int) $selected_plan_id,
            'is_pivoted'        => $is_pivoted,
            'referral_url'      => $referral_url,
            // Goal level: 0 means "no goal" (Matrix Master state or
            // missing data). Empty slots check this to apply the
            // is-goal-level highlight.
            'goal_level'        => $next_goal ? (int) $next_goal['level'] : 0,
            'level_commissions' => $level_commissions_decoded,
            'currency_symbol'   => get_option('matrix_mlm_currency_symbol', '₦'),
        ];

        // Parse the heat-map view-mode URL params and compute per-
        // node heat scores ahead of the tree render.
        //
        // Two URL params drive the feature:
        //   ?mode=structure|activity   — top-level toggle.
        //                                Default 'structure'.
        //   ?heat_metric=downline|commission|active
        //                              — sub-metric used when
        //                                mode=activity. Default
        //                                'downline'. Pre-baked into
        //                                every node regardless of
        //                                mode so the JS toggle can
        //                                flip metrics without a
        //                                page reload.
        //
        // Both are sanitised against allow-lists in self::HEAT_METRICS
        // and ['structure','activity'] before being trusted — these
        // values flow into HTML attributes and class names, so a
        // permissive read would invite an XSS surface even though
        // the outputs are esc_attr'd downstream.
        //
        // compute_heat_data() runs ALWAYS (even on structure mode)
        // because emitting the data attrs at render-time is what
        // makes the toggle instant on the client. The cost is two
        // batch SQLs whose result sets are bounded by the visible
        // tree's size — see compute_heat_data() for the reasoning.
        $mode_raw    = isset($_GET['mode']) ? sanitize_text_field((string) $_GET['mode']) : 'structure';
        $heat_metric = isset($_GET['heat_metric']) ? sanitize_text_field((string) $_GET['heat_metric']) : 'downline';
        if ($mode_raw !== 'activity') {
            $mode_raw = 'structure';
        }
        if (!in_array($heat_metric, self::HEAT_METRICS, true)) {
            $heat_metric = 'downline';
        }
        $heat_data = $this->compute_heat_data($tree, (int) $user_id, (int) $selected_plan_id);
        $this->pivot_state['heat_data']   = $heat_data;
        $this->pivot_state['heat_metric'] = $heat_metric;
        $this->pivot_state['mode']        = $mode_raw;
        ?>

        <?php if ($is_pivoted): ?>
        <?php $this->render_pivot_breadcrumbs($pivot_breadcrumbs); ?>
        <?php endif; ?>

        <?php $this->render_search_box($selected_plan_id); ?>

        <?php $this->render_share_export_panel($selected_plan_id, $is_pivoted ? (int) $pivot_user_id_raw : 0); ?>

        <?php
        // Print-friendly short circuit: when ?print=1 is set we
        // emit *only* the tree (plus a tiny header strip + a
        // "Print / Save as PDF" button hidden by @media print) and
        // skip everything else — stats grid, level badges, mode
        // toggle, hovercard, etc. — so the resulting page prints
        // cleanly to a single PDF without dashboard chrome.
        //
        // Lives here (after the search box render but before the
        // stat grid) because the stats and tree are the two pieces
        // a prospect cares about; everything else is dashboard
        // ergonomics that don't translate to print. The hidden
        // share-export panel above this point still renders
        // (so the panel's CSS doesn't FOUC into view) but is
        // also hidden via the @media print rules in
        // render_share_export_panel.
        //
        // Mirrors how Matrix_MLM_Share's public route presents the
        // tree: the two surfaces converge on the same minimal
        // print layout so a member exporting their own tree gets
        // the same PDF a prospect would see via a share link.
        $print_mode = isset($_GET['print']) && $_GET['print'] !== '0' && $_GET['print'] !== '';
        if ($print_mode) {
            $this->render_print_mode_view($display_position, $tree, $is_pivoted);
            return;
        }
        ?>

        <div class="matrix-stats-grid" style="margin-bottom: 20px;">
            <div class="matrix-stat-card primary">
                <div class="stat-value"><?php echo $display_position->width . 'x' . $display_position->depth; ?></div>
                <div class="stat-label"><?php _e('Matrix Type', 'matrix-mlm'); ?></div>
            </div>
            <div class="matrix-stat-card success">
                <div class="stat-value"><?php echo number_format($display_position->total_downline); ?></div>
                <div class="stat-label"><?php
                    // "Your Downline" reads weirdly when we're showing
                    // someone else's subtree. Switch to a generic
                    // "Downline" label on pivoted views so the metric
                    // matches the breadcrumb context above the panel.
                    echo $is_pivoted
                        ? esc_html__('Downline', 'matrix-mlm')
                        : esc_html__('Your Downline', 'matrix-mlm');
                ?></div>
            </div>
            <div class="matrix-stat-card info">
                <div class="stat-value"><?php echo number_format($max_members); ?></div>
                <div class="stat-label"><?php _e('Positions to Fill', 'matrix-mlm'); ?></div>
            </div>
            <div class="matrix-stat-card warning">
                <div class="stat-value"><?php echo $max_members > 1 ? round(($display_position->total_downline / ($max_members - 1)) * 100, 1) . '%' : '100%'; ?></div>
                <div class="stat-label"><?php _e('Completion', 'matrix-mlm'); ?></div>
            </div>
        </div>

        <?php $this->render_next_goal_banner($next_goal, $referral_url); ?>

        <?php $this->render_level_badges($level_status, $levels_completed, $all_levels_complete, $display_position, $next_goal); ?>

        <?php $this->render_mode_toggle($mode_raw, $heat_metric); ?>

        <div class="matrix-genealogy-wrapper">
            <div class="matrix-genealogy-tree<?php echo $mode_raw === 'activity' ? ' heatmap-active' : ''; ?>" id="genealogy-tree" data-plan-id="<?php echo (int) $selected_plan_id; ?>" data-plan-depth="<?php echo (int) $display_position->depth; ?>" data-plan-width="<?php echo (int) $display_position->width; ?>" data-root-user-id="<?php echo $display_user_id; ?>" data-active-metric="<?php echo esc_attr($heat_metric); ?>">
                <?php if ($tree): ?>
                    <?php $this->render_tree_node($tree, $display_position->width, true, $display_user_id, $initial_render_depth); ?>
                <?php else: ?>
                    <div class="matrix-alert matrix-alert-info"><?php _e('No tree data available yet.', 'matrix-mlm'); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="matrix-genealogy-legends" data-mode="<?php echo esc_attr($mode_raw); ?>" data-metric="<?php echo esc_attr($heat_metric); ?>">
            <div class="matrix-genealogy-legend matrix-genealogy-legend-structure">
                <span class="legend-item"><span class="legend-dot legend-you"></span> <?php _e('You', 'matrix-mlm'); ?></span>
                <span class="legend-item"><span class="legend-dot legend-direct"></span> <?php _e('Direct Referral', 'matrix-mlm'); ?></span>
                <span class="legend-item"><span class="legend-dot legend-spillover"></span> <?php _e('Spillover', 'matrix-mlm'); ?></span>
                <span class="legend-item"><span class="legend-dot legend-empty"></span> <?php _e('Empty Slot', 'matrix-mlm'); ?></span>
            </div>
            <div class="matrix-genealogy-legend matrix-genealogy-legend-heat" aria-live="polite">
                <span class="legend-heat-title">
                    <span class="legend-heat-title-downline"><?php esc_html_e('Heat by total downline:', 'matrix-mlm'); ?></span>
                    <span class="legend-heat-title-commission"><?php
                        printf(
                            /* translators: %d: window in days */
                            esc_html__('Heat by commissions earned (last %d days):', 'matrix-mlm'),
                            (int) self::HEAT_COMMISSION_WINDOW_DAYS
                        );
                    ?></span>
                    <span class="legend-heat-title-active"><?php esc_html_e('Heat by active member ratio:', 'matrix-mlm'); ?></span>
                </span>
                <span class="legend-item"><span class="legend-dot legend-heat-green"></span>   <?php esc_html_e('Top performer', 'matrix-mlm'); ?></span>
                <span class="legend-item"><span class="legend-dot legend-heat-yellow"></span>  <?php esc_html_e('Mid performer', 'matrix-mlm'); ?></span>
                <span class="legend-item"><span class="legend-dot legend-heat-red"></span>     <?php esc_html_e('Needs attention', 'matrix-mlm'); ?></span>
                <span class="legend-item"><span class="legend-dot legend-heat-cold"></span>    <?php esc_html_e('No data yet', 'matrix-mlm'); ?></span>
            </div>
        </div>

        <?php $this->render_lazy_load_script(); ?>
        <?php $this->render_mode_toggle_script(); ?>
        <?php $this->render_search_script($selected_plan_id); ?>
        <?php $this->render_hovercard($selected_plan_id); ?>
        <?php $this->render_hovercard_script($selected_plan_id); ?>
        <?php $this->render_referral_copy_script(); ?>

        <?php endif; ?>
        <?php
    }

    /**
     * Render the pivot breadcrumb bar above the tree.
     *
     * Only emitted on pivoted views (when ?pivot_user_id=X resolved
     * successfully and the viewer can see X's position). The bar
     * shows the trail from "you" all the way down to the pivoted
     * member, plus an explicit "Back to my tree" exit on the left
     * so the affordance is unambiguous on touch devices where the
     * "You" crumb might otherwise be the only way out.
     *
     * Each crumb except the last is a hyperlink that re-pivots the
     * URL to that crumb's user. The first crumb removes the
     * pivot_user_id param entirely (canonical "your own tree" URL).
     * The last crumb is rendered as plain text — it's the page
     * we're already on, so making it a link would just be a
     * no-op the user could waste a click on.
     *
     * Style is intentionally subtle (light pill, monospaced ›
     * separators) so the breadcrumb bar reads as navigation chrome
     * instead of competing with the tree itself for visual weight.
     */
    private function render_pivot_breadcrumbs($crumbs) {
        if (empty($crumbs)) {
            return;
        }
        $last_index = count($crumbs) - 1;
        ?>
        <div class="matrix-genealogy-pivot-bar" role="navigation" aria-label="<?php esc_attr_e('Genealogy pivot trail', 'matrix-mlm'); ?>">
            <a class="matrix-genealogy-pivot-back" href="<?php echo esc_url($this->pivot_url_for_user(0)); ?>">
                <span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
                <?php esc_html_e('Back to my tree', 'matrix-mlm'); ?>
            </a>
            <ol class="matrix-genealogy-pivot-trail">
                <?php foreach ($crumbs as $i => $crumb):
                    $is_last  = ($i === $last_index);
                    $is_first = ($i === 0);
                    // First crumb is the viewer themselves: render as
                    // "You" so it's instantly recognisable in a long
                    // trail. Middle and last crumbs render the
                    // username verbatim.
                    $label = $is_first
                        ? esc_html__('You', 'matrix-mlm')
                        : esc_html($crumb['username'] !== '' ? $crumb['username'] : ('User #' . $crumb['user_id']));
                ?>
                    <li class="matrix-genealogy-pivot-crumb<?php echo $is_last ? ' is-current' : ''; ?>">
                        <?php if ($is_last): ?>
                            <span aria-current="page"><?php echo $label; ?></span>
                        <?php else: ?>
                            <a href="<?php echo esc_url($this->pivot_url_for_user($is_first ? 0 : (int) $crumb['user_id'])); ?>"><?php echo $label; ?></a>
                        <?php endif; ?>
                        <?php if (!$is_last): ?>
                            <span class="matrix-genealogy-pivot-sep" aria-hidden="true">›</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
        <?php
    }

    /**
     * Build a genealogy URL for pivoting to a specific user.
     *
     * Routes through Matrix_MLM_User_Dashboard::tab_url() so the URL
     * format matches whatever the host is using (pretty
     * /matrix-dashboard/genealogy/ path under permalinks, or query
     * string /matrix-dashboard/?tab=genealogy on plain installs).
     * Then layers plan_id and pivot_user_id on top so the resulting
     * URL preserves the currently-selected plan and pins the pivot
     * to whoever the caller asked for.
     *
     * Passing 0 for $pivot_to_user_id returns the canonical "view
     * your own tree" URL — used by the breadcrumb's first crumb and
     * by the "Back to my tree" affordance to un-pivot.
     *
     * @param int $pivot_to_user_id 0 for self, otherwise a downline
     *                              member's user id.
     * @return string Absolute URL.
     */
    private function pivot_url_for_user($pivot_to_user_id) {
        $base = class_exists('Matrix_MLM_User_Dashboard')
            ? Matrix_MLM_User_Dashboard::tab_url('genealogy')
            : home_url('/matrix-dashboard/?tab=genealogy');

        $args = ['plan_id' => (int) $this->pivot_state['plan_id']];
        if ((int) $pivot_to_user_id > 0
            && (int) $pivot_to_user_id !== (int) $this->pivot_state['viewer_user_id']) {
            $args['pivot_user_id'] = (int) $pivot_to_user_id;
        }
        return add_query_arg($args, $base);
    }

    /**
     * Allowed values for the ?heat_metric= URL param and the metric
     * picker dropdown. Centralised here so render(),
     * compute_heat_data() and the AJAX subtree handler all sanitise
     * against the same allow-list — adding a fourth metric means
     * adding it once to this constant + once to the metric switch in
     * compute_heat_data().
     */
    const HEAT_METRICS = ['downline', 'commission', 'active'];

    /**
     * Window for the "recent commissions" heat metric, in days.
     *
     * 30 days matches the dashboard's other "recent" copy (the
     * level-badges panel quotes a 30-day cumulative figure) so
     * members don't have to context-switch between two timeframes
     * when comparing the heat map against the rest of their
     * dashboard at a glance.
     */
    const HEAT_COMMISSION_WINDOW_DAYS = 30;

    /**
     * Pre-compute heat scores + green/yellow/red buckets for every
     * non-root, non-empty node in the visible tree, for ALL three
     * metrics in one pass.
     *
     * Why all three even when the user only toggled one on:
     *
     *   - Toggling between metrics is the most-common interaction
     *     for this feature (the whole point of the toggle is fast
     *     comparison). Recomputing server-side on every flip would
     *     mean a full page reload per click; instead we bake all
     *     three metrics into data-heat-* attributes once and the
     *     toggle JS is purely a class swap on the wrapper, which
     *     feels instant.
     *   - Cost is bounded: the visible tree caps at 4 levels deep
     *     (~width^4 nodes — 81 for a 3-wide plan, 256 for 4-wide).
     *     The two batch SQLs we do here scale linearly in that node
     *     count and run as `IN (...)` lookups — well within budget
     *     for a dashboard tab that already runs ~6 queries.
     *
     * Score semantics per metric (subtree-aggregated over visible
     * descendants only — see "boundary caveat" below):
     *
     *   - downline:   node->total_downline, read directly off the
     *                 matrix_positions row. This is the only metric
     *                 that's already a true full-tree aggregate
     *                 because total_downline is maintained by the
     *                 plan engine on every insert.
     *   - commission: SUM of paid commission rows where
     *                 user_id=$viewer AND from_user_id IN (subtree's
     *                 visible user_ids) AND created_at within the
     *                 last HEAT_COMMISSION_WINDOW_DAYS days.
     *   - active:     count of subtree members whose
     *                 matrix_user_meta.status='active' divided by
     *                 the subtree size. 1.0 means every visible
     *                 descendant (and the node itself) is active;
     *                 lower values reveal banned/inactive accounts
     *                 inside that branch.
     *
     * Bucketing rules:
     *
     *   - downline / commission: relative thresholds — score / max
     *     ratio across the visible non-root nodes. >= 0.66 green,
     *     >= 0.33 yellow, > 0 red, 0 cold. Relative because the
     *     useful question is "where is this branch hot relative to
     *     the others on screen?", not "is 12 downline objectively a
     *     lot?".
     *   - active: absolute thresholds on the ratio. >= 0.85 green,
     *     >= 0.6 yellow, >= 0 red. Absolute because the operator
     *     wants to know "is this branch unhealthy?" — having every
     *     branch coloured against the worst-banned branch as a
     *     reference would mute the signal.
     *
     * Boundary caveat (commission + active): we aggregate over the
     * VISIBLE rendered subtree, not the full database descendant
     * set. A branch with thousands of descendants below the 4th-
     * level cutoff still scores using only the ~16 nodes the user
     * can see. The total_downline metric is unaffected because it's
     * pre-aggregated on each row. We accept this trade because:
     *
     *   - Calling sum_branch_commissions_for_viewer() per node would
     *     run an O(N) BFS per node, multiplying queries.
     *   - Lazy-loaded subtrees (Show more) get their own self-
     *     contained heat scoring on the AJAX side, so as members
     *     drill in, the deeper branches re-bucket against the new
     *     visible set — which is what they care about at that
     *     moment of inspection.
     *
     * @param array|null $tree           Tree node from
     *                                   Matrix_MLM_Plan_Engine::get_matrix_tree().
     *                                   Null/empty returns an empty
     *                                   map gracefully — caller can
     *                                   still render a no-tree state.
     * @param int        $viewer_user_id WP user id whose commissions
     *                                   we attribute to the tree
     *                                   when scoring the commission
     *                                   metric. Equals the actual
     *                                   logged-in member regardless
     *                                   of whether the tree is
     *                                   pivoted, because the pivot
     *                                   only changes the view, not
     *                                   the earnings owner.
     * @param int        $plan_id        Plan whose subtree we're
     *                                   scoping all three metrics
     *                                   to.
     * @return array<int, array{
     *     downline:    array{score:float, bucket:string, label:string},
     *     commission:  array{score:float, bucket:string, label:string},
     *     active:      array{score:float, bucket:string, label:string}
     * }>  Map keyed by matrix_positions.id. Root position is omitted
     *     (the root card is always the indigo "you" card; tinting it
     *     would be a category error). Empty slots also have no entry.
     */
    public function compute_heat_data($tree, $viewer_user_id, $plan_id) {
        if (empty($tree) || empty($tree['children'])) {
            return [];
        }

        global $wpdb;

        // Pass 1: gather all (position_id, user_id, level) triples in
        // the visible tree, EXCLUDING the root. We need user_ids for
        // the two batch SQLs and position_ids as the eventual map key
        // — render_tree_node() looks heat data up by position id.
        //
        // We also memoise per-node children references so the
        // post-order aggregation pass can walk without re-recursing
        // the original (potentially deep) tree shape.
        $position_user      = []; // position_id => user_id
        $position_children  = []; // position_id => [child_position_id, ...]
        $position_level     = []; // position_id => absolute level
        $all_user_ids       = [];

        $stack = [[$tree, true]]; // [node, is_root]
        while (!empty($stack)) {
            list($node, $is_root) = array_pop($stack);
            $pid = (int) ($node['id'] ?? 0);
            $uid = (int) ($node['user_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }

            $position_children[$pid] = [];
            $position_level[$pid]    = (int) ($node['level'] ?? 0);

            if (!$is_root) {
                $position_user[$pid] = $uid;
                if ($uid > 0) {
                    $all_user_ids[$uid] = true;
                }
            } else {
                // Root's user_id is needed for the active-ratio
                // metric only at descendants — we don't score the
                // root itself, so don't add it to $position_user.
                // But we do still want it in $all_user_ids for the
                // SQL because some descendants' parents resolve
                // there and consistency is cheap.
            }

            $children = !empty($node['children']) ? $node['children'] : [];
            foreach ($children as $child) {
                $cpid = (int) ($child['id'] ?? 0);
                if ($cpid > 0) {
                    $position_children[$pid][] = $cpid;
                    $stack[] = [$child, false];
                }
            }
        }

        // No descendants visible → no heat to compute. The toggle
        // will still render but every node scores 'cold' — the
        // legend's "no data" hint covers this state.
        if (empty($position_user)) {
            return [];
        }

        $user_ids = array_keys($all_user_ids);

        // Pass 2: batch SQL — recent commissions to this viewer,
        // grouped by from_user_id. One round-trip for the whole
        // tree. We deliberately DON'T include cancelled/pending rows
        // (status='paid' filter) because the heat map is meant to
        // reflect realised earnings the member can spend, not
        // pipeline that might never settle.
        $commission_self = []; // user_id => recent paid amount FROM that user
        if (!empty($user_ids) && (int) $viewer_user_id > 0) {
            $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
            $params       = array_map('intval', $user_ids);
            array_unshift($params, (int) $viewer_user_id);
            $params[]     = (int) self::HEAT_COMMISSION_WINDOW_DAYS;

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT from_user_id, COALESCE(SUM(amount), 0) AS total
                   FROM {$wpdb->prefix}matrix_commissions
                  WHERE user_id = %d
                    AND status = 'paid'
                    AND from_user_id IN ($placeholders)
                    AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                  GROUP BY from_user_id",
                $params
            ));
            foreach ($rows as $r) {
                $commission_self[(int) $r->from_user_id] = (float) $r->total;
            }
        }

        // Pass 3: batch SQL — matrix_user_meta.status for every
        // visible user. Missing rows (legacy imports without a
        // matrix_user_meta record) default to 'active' so we don't
        // punish a branch for a data-quality issue the member can't
        // fix from the dashboard.
        $user_status = [];
        if (!empty($user_ids)) {
            $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
            $params       = array_map('intval', $user_ids);
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT user_id, status
                   FROM {$wpdb->prefix}matrix_user_meta
                  WHERE user_id IN ($placeholders)",
                $params
            ));
            foreach ($rows as $r) {
                $user_status[(int) $r->user_id] = (string) $r->status;
            }
        }

        // Pass 4: post-order aggregation. We walk the tree once,
        // bottom-up, accumulating per-position commission and
        // active counters. Iterative to keep deep matrices off the
        // PHP recursion limit — a 9-deep plan would otherwise nest
        // up to 9 frames per branch, which the recursive
        // build_tree_recursive already pushes against.
        $sub_commission = []; // pid => sum of recent commission via subtree
        $sub_active     = []; // pid => count of active members in subtree
        $sub_total      = []; // pid => total members in subtree (incl. self)

        // Reverse-DFS to get post-order: visit children before
        // parents. We stamp each node twice on the stack — once on
        // first encounter (push children) and once for processing
        // after children are done. The 'visited' flag distinguishes.
        $work = [[(int) $tree['id'], false]];
        while (!empty($work)) {
            list($pid, $visited) = array_pop($work);
            if (!$visited) {
                $work[] = [$pid, true];
                foreach ($position_children[$pid] ?? [] as $cpid) {
                    $work[] = [$cpid, false];
                }
                continue;
            }

            // Self contribution: only descendants (non-root) count
            // toward their own "self" stats; the root contributes
            // nothing because we don't tint it.
            $is_root_pid = ($pid === (int) $tree['id']);
            $own_commission = 0.0;
            $own_total      = 0;
            $own_active     = 0;

            if (!$is_root_pid) {
                $uid = (int) ($position_user[$pid] ?? 0);
                if ($uid > 0) {
                    $own_commission = (float) ($commission_self[$uid] ?? 0.0);
                    $own_total      = 1;
                    // Default to active when matrix_user_meta is
                    // missing (see pass 3 docblock for the
                    // rationale).
                    $status = $user_status[$uid] ?? 'active';
                    if ($status === 'active') {
                        $own_active = 1;
                    }
                }
            }

            $agg_commission = $own_commission;
            $agg_total      = $own_total;
            $agg_active     = $own_active;
            foreach ($position_children[$pid] ?? [] as $cpid) {
                $agg_commission += (float) ($sub_commission[$cpid] ?? 0.0);
                $agg_total      += (int)   ($sub_total[$cpid]      ?? 0);
                $agg_active     += (int)   ($sub_active[$cpid]     ?? 0);
            }
            $sub_commission[$pid] = $agg_commission;
            $sub_total[$pid]      = $agg_total;
            $sub_active[$pid]     = $agg_active;
        }

        // Pass 5: bucket assignment. For downline / commission we
        // need the global max across all non-root nodes to compute
        // a relative score; for active we go straight from ratio to
        // bucket without any tree-level normalisation.
        //
        // total_downline lives on the matrix_positions row but we
        // don't have it indexed by position id at this point —
        // re-scan the original tree once to build the map. One
        // bounded pass is cheaper than threading
        // $position_total_downline through every recursion site
        // back in the gathering pass above.
        $max_downline   = 0;
        $max_commission = 0.0;
        $position_downline = []; // pid => total_downline (DB column)
        $stack = [$tree];
        while (!empty($stack)) {
            $node = array_pop($stack);
            $pid  = (int) ($node['id'] ?? 0);
            if ($pid > 0 && $pid !== (int) $tree['id']) {
                $position_downline[$pid] = (int) ($node['total_downline'] ?? 0);
                $max_downline = max($max_downline, $position_downline[$pid]);
            }
            foreach (($node['children'] ?? []) as $child) {
                $stack[] = $child;
            }
        }
        foreach ($sub_commission as $pid => $amount) {
            if ($pid !== (int) $tree['id']) {
                $max_commission = max($max_commission, $amount);
            }
        }

        $heat = [];
        $currency = isset($this->pivot_state['currency_symbol'])
            ? (string) $this->pivot_state['currency_symbol']
            : (string) get_option('matrix_mlm_currency_symbol', '₦');

        foreach ($position_user as $pid => $uid) {
            // Downline bucket (relative)
            $d_score = (int) ($position_downline[$pid] ?? 0);
            $d_bucket = self::bucket_relative($d_score, $max_downline);
            $d_label  = number_format_i18n($d_score);

            // Commission bucket (relative)
            $c_score  = (float) ($sub_commission[$pid] ?? 0.0);
            $c_bucket = self::bucket_relative($c_score, $max_commission);
            // Compact money label so it fits the small node card.
            // No decimals on small amounts; comma thousands separator.
            $c_label  = $currency . number_format_i18n($c_score, $c_score >= 1000 ? 0 : 2);

            // Active-ratio bucket (absolute thresholds)
            $a_total  = (int) ($sub_total[$pid] ?? 0);
            $a_active = (int) ($sub_active[$pid] ?? 0);
            $a_score  = $a_total > 0 ? ($a_active / $a_total) : 0.0;
            if ($a_total === 0) {
                $a_bucket = 'cold';
            } elseif ($a_score >= 0.85) {
                $a_bucket = 'green';
            } elseif ($a_score >= 0.6) {
                $a_bucket = 'yellow';
            } else {
                $a_bucket = 'red';
            }
            $a_label = round($a_score * 100) . '%';

            $heat[$pid] = [
                'downline'   => ['score' => (float) $d_score, 'bucket' => $d_bucket, 'label' => $d_label],
                'commission' => ['score' => $c_score,         'bucket' => $c_bucket, 'label' => $c_label],
                'active'     => ['score' => $a_score,         'bucket' => $a_bucket, 'label' => $a_label],
            ];
        }

        return $heat;
    }

    /**
     * Map a score to a green/yellow/red/cold bucket based on its
     * fraction of the visible tree's max. Relative bucketing is
     * what the user actually wants for downline + commission: "is
     * this branch hot RELATIVE to the others I can see?" rather
     * than against an arbitrary absolute threshold that would mean
     * different things on a 1000-member matrix vs a 10-member one.
     *
     * Threshold choice (0.66 / 0.33 / >0): an even three-way split
     * would put the boundary at 0.5 / 0.25, which empirically gives
     * too many "green" branches on long-tailed matrices (one big
     * branch + many small ones → most of the small ones still land
     * in green because the max is huge). 0.66 / 0.33 produces a
     * crisper visual: the top third are green, the middle third
     * yellow, the bottom (still earning) are red. Members with a
     * single dominant branch see exactly that — the dominant branch
     * green, everyone else red — which is correct for the
     * "where to focus attention" framing.
     *
     * @param float $score Per-node score.
     * @param float $max   Max across visible non-root nodes.
     * @return string One of green|yellow|red|cold.
     */
    private static function bucket_relative($score, $max) {
        if ($max <= 0) {
            // Whole tree has no signal on this metric (e.g. nobody
            // earned any commissions in the last 30 days). Render
            // every node neutral — a screen of all-green would lie
            // about activity that isn't there.
            return $score > 0 ? 'green' : 'cold';
        }
        if ($score <= 0) {
            return 'cold';
        }
        $ratio = $score / $max;
        if ($ratio >= 0.66) return 'green';
        if ($ratio >= 0.33) return 'yellow';
        return 'red';
    }

    /**
     * Render the Structure ↔ Activity mode toggle plus the metric
     * picker for Activity mode.
     *
     * Two-tier UI matching the user's mental model: top-level binary
     * choice (current view vs analytic view) with a secondary
     * picker exposed only when Activity is on. Implemented as a
     * segmented control + a select rather than three flat buttons
     * because the segmented control's "this is a dial, not three
     * unrelated actions" framing reads correctly to first-time users
     * — picking Activity then Commission feels like configuring one
     * thing, not making two unrelated choices.
     *
     * Initial state is hydrated from the URL params parsed in
     * render() so a refreshed page (or a shared link) lands in the
     * same view the member configured. The toggle JS keeps the URL
     * in sync via history.replaceState on every click — no real
     * navigation, so back/forward doesn't trap the user in a long
     * history of toggle clicks.
     *
     * @param string $mode        'structure' or 'activity'.
     * @param string $heat_metric One of HEAT_METRICS.
     */
    private function render_mode_toggle($mode, $heat_metric) {
        $is_activity = ($mode === 'activity');
        ?>
        <div class="matrix-genealogy-mode-toggle" role="group" aria-label="<?php esc_attr_e('Genealogy view mode', 'matrix-mlm'); ?>">
            <div class="genealogy-mode-segmented" role="tablist">
                <button type="button"
                        class="genealogy-mode-btn<?php echo !$is_activity ? ' is-active' : ''; ?>"
                        data-mode="structure"
                        role="tab"
                        aria-selected="<?php echo !$is_activity ? 'true' : 'false'; ?>">
                    <span class="dashicons dashicons-screenoptions" aria-hidden="true"></span>
                    <?php esc_html_e('Structure', 'matrix-mlm'); ?>
                </button>
                <button type="button"
                        class="genealogy-mode-btn<?php echo $is_activity ? ' is-active' : ''; ?>"
                        data-mode="activity"
                        role="tab"
                        aria-selected="<?php echo $is_activity ? 'true' : 'false'; ?>"
                        title="<?php esc_attr_e('Tint each subtree by activity to see where to focus.', 'matrix-mlm'); ?>">
                    <span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
                    <?php esc_html_e('Activity', 'matrix-mlm'); ?>
                </button>
            </div>
            <div class="genealogy-mode-metric-picker"<?php echo $is_activity ? '' : ' hidden'; ?>>
                <label for="genealogy-heat-metric"><?php esc_html_e('Heat by:', 'matrix-mlm'); ?></label>
                <select id="genealogy-heat-metric" class="genealogy-heat-metric-select">
                    <option value="downline" <?php selected($heat_metric, 'downline'); ?>>
                        <?php esc_html_e('Total downline size', 'matrix-mlm'); ?>
                    </option>
                    <option value="commission" <?php selected($heat_metric, 'commission'); ?>>
                        <?php
                        printf(
                            /* translators: %d: window in days */
                            esc_html__('Commissions earned (last %d days)', 'matrix-mlm'),
                            (int) self::HEAT_COMMISSION_WINDOW_DAYS
                        );
                        ?>
                    </option>
                    <option value="active" <?php selected($heat_metric, 'active'); ?>>
                        <?php esc_html_e('Active member ratio', 'matrix-mlm'); ?>
                    </option>
                </select>
            </div>
        </div>
        <?php
    }

    /**
     * Inline JS that wires the mode toggle and metric picker.
     *
     * Behavioural contract:
     *
     *   1. Clicking a Structure/Activity button updates the
     *      .is-active class on the segmented buttons, toggles the
     *      visibility of the metric picker, applies/removes the
     *      .heatmap-active class on #genealogy-tree (the CSS hook
     *      that turns tints on), updates the legends container's
     *      data-mode attr (which CSS uses to show the right legend
     *      variant), and replaces the URL's ?mode= param via
     *      history.replaceState so a refresh preserves the choice
     *      without polluting browser history.
     *   2. Picking a different metric updates the wrapper's
     *      data-active-metric attr (the other half of the CSS heat
     *      selector), rewrites every visible heat pill's text from
     *      its data-pill-{metric} attribute, updates the URL's
     *      ?heat_metric= param, and updates the legend's data-metric
     *      attr so the metric-specific legend label flips with it.
     *   3. After the lazy-load script injects new nodes (which carry
     *      their own data-pill-* / data-heat-* attributes), the
     *      toggle's class state and active-metric attr already
     *      apply to them via CSS — no extra rebinding needed.
     *      Refreshing pill text on the new nodes is handled by the
     *      lazy-load handler firing a 'matrix:subtree-loaded' event
     *      that this script listens for.
     *
     * No AJAX here — the metric picker is purely client-side,
     * because compute_heat_data() ran once at page render and
     * stamped all three buckets into the DOM. Toggling between them
     * is therefore a CSS-state flip plus a textContent rewrite per
     * pill, both O(visible nodes) and instant on any device.
     */
    private function render_mode_toggle_script() {
        ?>
        <script>
        (function() {
            var tree     = document.getElementById('genealogy-tree');
            var toggle   = document.querySelector('.matrix-genealogy-mode-toggle');
            var legends  = document.querySelector('.matrix-genealogy-legends');
            var picker   = toggle ? toggle.querySelector('.genealogy-mode-metric-picker') : null;
            var select   = toggle ? toggle.querySelector('.genealogy-heat-metric-select') : null;
            if (!tree || !toggle) return;

            function refreshPills(metric) {
                // Each pill carries data-pill-downline, data-pill-commission,
                // and data-pill-active. Flipping the metric picker means
                // rewriting textContent on every pill from its matching
                // data-attr. Empty strings just blank the pill, which is
                // what we want for nodes with no data on that metric.
                var pills = tree.querySelectorAll('.tree-node-heat-pill');
                for (var i = 0; i < pills.length; i++) {
                    var pill = pills[i];
                    var label = pill.getAttribute('data-pill-' + metric) || '';
                    pill.textContent = label;
                    if (label === '') {
                        pill.setAttribute('hidden', 'hidden');
                    } else {
                        pill.removeAttribute('hidden');
                    }
                }
            }

            function updateUrl(mode, metric) {
                if (typeof window.history.replaceState !== 'function') return;
                try {
                    var url = new URL(window.location.href);
                    if (mode === 'structure') {
                        url.searchParams.delete('mode');
                        url.searchParams.delete('heat_metric');
                    } else {
                        url.searchParams.set('mode', 'activity');
                        url.searchParams.set('heat_metric', metric);
                    }
                    window.history.replaceState({}, '', url.toString());
                } catch (e) {
                    // Older browsers without URL constructor — silently
                    // skip URL persistence; in-page toggle still works.
                }
            }

            function applyMode(mode, metric) {
                var buttons = toggle.querySelectorAll('.genealogy-mode-btn');
                for (var i = 0; i < buttons.length; i++) {
                    var b = buttons[i];
                    var isActive = (b.getAttribute('data-mode') === mode);
                    b.classList.toggle('is-active', isActive);
                    b.setAttribute('aria-selected', isActive ? 'true' : 'false');
                }
                if (picker) {
                    if (mode === 'activity') picker.removeAttribute('hidden');
                    else                     picker.setAttribute('hidden', 'hidden');
                }
                tree.setAttribute('data-active-metric', metric);
                if (mode === 'activity') {
                    tree.classList.add('heatmap-active');
                } else {
                    tree.classList.remove('heatmap-active');
                }
                if (legends) {
                    legends.setAttribute('data-mode', mode);
                    legends.setAttribute('data-metric', metric);
                }
                refreshPills(metric);
                updateUrl(mode, metric);
            }

            // Wire the segmented buttons.
            toggle.addEventListener('click', function(e) {
                var btn = e.target.closest('.genealogy-mode-btn');
                if (!btn) return;
                var mode   = btn.getAttribute('data-mode') || 'structure';
                var metric = (select && select.value) ? select.value : 'downline';
                applyMode(mode, metric);
            });

            // Wire the metric picker.
            if (select) {
                select.addEventListener('change', function() {
                    applyMode('activity', select.value);
                });
            }

            // Refresh pill text on lazy-loaded subtrees so the new
            // nodes show the right label for the currently-active
            // metric. The lazy-load script dispatches this event
            // after injecting the new .matrix-tree-children block.
            tree.addEventListener('matrix:subtree-loaded', function() {
                var metric = tree.getAttribute('data-active-metric') || 'downline';
                refreshPills(metric);
            });
        })();
        </script>
        <?php
    }

    /**
     * Emit the inline JS that powers the "Show more" expand buttons.
     *
     * Runs once per genealogy view render, attached as a delegated
     * click listener on the body so it picks up buttons that come in
     * via AJAX-injected subtrees too — not just the ones present on
     * first page load. That matters because every level we expand
     * itself contains more "Show more" buttons at its new bottom
     * edge, and binding listeners directly to each button at render
     * time would miss the ones that arrive later.
     *
     * Why inline rather than enqueued: the rest of the genealogy view
     * already collocates its inline CSS and inline scripts with the
     * markup that needs them (see render_level_badges() above and the
     * plan-selector inline onchange handler). A standalone .js file
     * for ~40 lines of behaviour would be more friction than payoff
     * — the dashboard is already authenticated and uncached, so
     * payload weight is not the issue an external file would solve.
     *
     * Spinner uses the existing dashicons-update glyph plus the
     * matching CSS keyframe defined in matrix-dashboard.css. We
     * intentionally don't add error toast UI here: a failed expansion
     * just restores the original button so the user can retry, and
     * surfaces the server's error message via the standard window.alert.
     * That matches how the existing wallet/balance flows handle their
     * AJAX errors elsewhere on the dashboard.
     */
    private function render_lazy_load_script() {
        ?>
        <script>
        (function() {
            var tree = document.getElementById('genealogy-tree');
            if (!tree) return;

            // Delegated listener: catches clicks on buttons that arrive
            // later via AJAX-injected subtrees, not just the ones
            // present at initial render.
            tree.addEventListener('click', function(e) {
                var btn = e.target.closest('.matrix-tree-expand-btn');
                if (!btn) return;
                e.preventDefault();

                var positionId = btn.getAttribute('data-position-id');
                var fromLevel  = btn.getAttribute('data-from-level');
                var wrapper    = btn.closest('.matrix-tree-expand');
                if (!positionId || !fromLevel || !wrapper) return;

                // Forward the tree's current root user id (the
                // pivoted member when ?pivot_user_id=X is in play,
                // otherwise the actual viewer). The server uses it
                // to classify each lazy-loaded node as direct vs
                // spillover relative to the displayed root — without
                // this, expanding inside someone else's tree would
                // colour the loaded subtree against the viewer's own
                // perspective, which is not what the pivot is meant
                // to show.
                var rootUserId = tree.getAttribute('data-root-user-id') || '';

                var originalHtml = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span class="dashicons dashicons-update matrix-tree-spin"></span> '
                              + '<?php echo esc_js(__('Loading…', 'matrix-mlm')); ?>';

                var data = new FormData();
                data.append('action',        'matrix_mlm_action');
                data.append('matrix_action', 'fetch_subtree');
                data.append('nonce',         matrixMLM.nonce);
                data.append('position_id',   positionId);
                data.append('from_level',    fromLevel);
                if (rootUserId) {
                    data.append('root_user_id', rootUserId);
                }

                fetch(matrixMLM.ajaxUrl, {
                    method:      'POST',
                    body:        data,
                    credentials: 'same-origin'
                })
                .then(function(r) { return r.json(); })
                .then(function(j) {
                    if (!j || !j.success) {
                        btn.disabled  = false;
                        btn.innerHTML = originalHtml;
                        var msg = (j && j.data && j.data.message)
                            ? j.data.message
                            : '<?php echo esc_js(__('Could not load deeper levels. Please try again.', 'matrix-mlm')); ?>';
                        alert(msg);
                        return;
                    }
                    // Replace the expand wrapper with the freshly
                    // rendered .matrix-tree-children block. The server
                    // already wraps the response in the right markup
                    // so we don't need to add anything around it.
                    var holder = document.createElement('div');
                    holder.innerHTML = j.data.html.trim();
                    var newChildren = holder.firstElementChild;
                    if (newChildren) {
                        wrapper.replaceWith(newChildren);
                        // Tell the mode-toggle script that fresh
                        // nodes just landed in the DOM. Its handler
                        // re-runs refreshPills() against the
                        // currently-active metric so the new heat
                        // pills show the right label without the
                        // user having to flip the toggle.
                        try {
                            tree.dispatchEvent(new CustomEvent('matrix:subtree-loaded'));
                        } catch (ev) {
                            // Older browsers without CustomEvent
                            // constructor — IE11 / very old Edge.
                            // Skipping the event just leaves the
                            // pills showing whatever the server
                            // pre-filled them with, which is still
                            // correct for the URL-selected metric.
                        }
                    } else {
                        // Defensive: server returned empty markup.
                        // Don't leave the user staring at a stuck
                        // spinner — restore the button and let them
                        // try again.
                        btn.disabled  = false;
                        btn.innerHTML = originalHtml;
                    }
                })
                .catch(function() {
                    btn.disabled  = false;
                    btn.innerHTML = originalHtml;
                    alert('<?php echo esc_js(__('Network error. Please try again.', 'matrix-mlm')); ?>');
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * Render the per-level completion badge panel.
     *
     * One pill per level 1..D for the selected plan. Each pill shows the
     * level number, a "filled / expected" count, and a status hint
     * (Completed vs In Progress). When every level is complete a special
     * "Matrix Master" trophy badge is rendered above the strip — that
     * banner is the visible counterpart to the existing
     * matrix_completion_bonus payout in the plan engine, so members get
     * a recognition surface for the milestone they were already being
     * paid for.
     *
     * Styles are inlined here on purpose: the existing files in this
     * plugin co-locate one-off CSS with the markup it decorates (see
     * class-matrix-user-card.php for the same pattern), and a
     * stand-alone stylesheet for ~30 lines would be more friction than
     * it's worth for an admin who later needs to find and tweak the
     * panel's look. If a future change brings shared "achievement"
     * styling, this block should move into matrix-dashboard.css.
     *
     * @param array $level_status         Output of
     *                                    Matrix_MLM_Plan_Engine::get_level_completion_status().
     * @param int   $levels_completed     How many entries in $level_status are complete.
     * @param bool  $all_levels_complete  Whether every level is filled.
     * @param object $current_position    Row from wp_matrix_positions for context
     *                                    (depth, plan name, etc.).
     * @param array|null $next_goal       Output of
     *                                    Matrix_MLM_Plan_Engine::get_next_level_goal(),
     *                                    used to flag the goal-level pill so the eye
     *                                    is drawn from the next-goal banner straight
     *                                    to the matching badge.
     */
    private function render_level_badges($level_status, $levels_completed, $all_levels_complete, $current_position, $next_goal = null) {
        if (empty($level_status)) {
            return;
        }
        $total_levels = count($level_status);
        $goal_level   = ($next_goal && !empty($next_goal['level'])) ? (int) $next_goal['level'] : 0;
        ?>
        <div class="matrix-level-badges">
            <div class="matrix-level-badges-header">
                <h3><?php _e('Level Completion Badges', 'matrix-mlm'); ?></h3>
                <span class="matrix-level-badges-summary">
                    <?php
                    printf(
                        /* translators: 1: levels completed, 2: total levels */
                        esc_html__('%1$d of %2$d levels completed', 'matrix-mlm'),
                        intval($levels_completed),
                        intval($total_levels)
                    );
                    ?>
                </span>
            </div>

            <?php if ($all_levels_complete): ?>
            <div class="matrix-level-master-banner" role="status">
                <span class="dashicons dashicons-awards" aria-hidden="true"></span>
                <div>
                    <strong><?php _e('Matrix Master', 'matrix-mlm'); ?></strong>
                    <span><?php
                        printf(
                            /* translators: %d: matrix depth */
                            esc_html__('All %d levels of your matrix are fully filled.', 'matrix-mlm'),
                            intval($total_levels)
                        );
                    ?></span>
                </div>
            </div>
            <?php endif; ?>

            <div class="matrix-level-badge-grid">
                <?php foreach ($level_status as $row):
                    $is_complete = !empty($row['complete']);
                    $expected    = max(1, intval($row['expected']));
                    $filled      = intval($row['filled']);
                    $progress    = min(100, round(($filled / $expected) * 100));
                    $pill_class  = $is_complete ? 'is-complete' : ($filled > 0 ? 'is-progress' : 'is-empty');
                    $is_goal     = ($goal_level > 0 && intval($row['level']) === $goal_level);
                    if ($is_goal) {
                        $pill_class .= ' is-goal';
                    }
                ?>
                <div class="matrix-level-badge <?php echo esc_attr($pill_class); ?>"<?php echo $is_goal ? ' aria-current="step"' : ''; ?>>
                    <?php if ($is_goal): ?>
                    <span class="matrix-level-badge-flag" aria-label="<?php esc_attr_e('Next goal', 'matrix-mlm'); ?>" title="<?php esc_attr_e('Your next genealogy goal', 'matrix-mlm'); ?>">
                        <span class="dashicons dashicons-flag" aria-hidden="true"></span>
                    </span>
                    <?php endif; ?>
                    <div class="matrix-level-badge-icon" aria-hidden="true">
                        <?php if ($is_complete): ?>
                            <span class="dashicons dashicons-yes-alt"></span>
                        <?php else: ?>
                            <span class="matrix-level-badge-num">L<?php echo intval($row['level']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="matrix-level-badge-body">
                        <div class="matrix-level-badge-title">
                            <?php printf(esc_html__('Level %d', 'matrix-mlm'), intval($row['level'])); ?>
                        </div>
                        <div class="matrix-level-badge-meta">
                            <?php
                            printf(
                                /* translators: 1: filled positions, 2: expected positions at this level */
                                esc_html__('%1$s / %2$s filled', 'matrix-mlm'),
                                number_format($filled),
                                number_format($expected)
                            );
                            ?>
                        </div>
                        <div class="matrix-level-badge-bar" aria-hidden="true">
                            <span style="width: <?php echo intval($progress); ?>%;"></span>
                        </div>
                        <div class="matrix-level-badge-status">
                            <?php echo $is_complete
                                ? esc_html__('Completed', 'matrix-mlm')
                                : esc_html__('In Progress', 'matrix-mlm'); ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <style>
        .matrix-level-badges { margin: 0 0 24px; }
        .matrix-level-badges-header {
            display: flex; align-items: baseline; justify-content: space-between;
            gap: 12px; margin-bottom: 12px; flex-wrap: wrap;
        }
        .matrix-level-badges-header h3 { margin: 0; font-size: 16px; }
        .matrix-level-badges-summary { font-size: 13px; color: #6b7280; }

        .matrix-level-master-banner {
            display: flex; align-items: center; gap: 14px;
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 1px solid #f59e0b; border-radius: 10px;
            padding: 14px 18px; margin-bottom: 14px; color: #78350f;
        }
        .matrix-level-master-banner .dashicons {
            font-size: 32px; width: 32px; height: 32px; color: #b45309;
        }
        .matrix-level-master-banner strong { display: block; font-size: 15px; }
        .matrix-level-master-banner span:last-child { font-size: 13px; opacity: 0.9; }

        .matrix-level-badge-grid {
            display: grid; gap: 12px;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        }
        .matrix-level-badge {
            display: flex; gap: 12px; align-items: stretch;
            background: #fff; border: 1px solid #e5e7eb; border-radius: 10px;
            padding: 12px 14px; transition: transform .15s ease, box-shadow .15s ease;
        }
        .matrix-level-badge:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 14px -4px rgba(0,0,0,0.08);
        }
        .matrix-level-badge-icon {
            flex: 0 0 40px; width: 40px; height: 40px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 13px;
            background: #f3f4f6; color: #6b7280;
        }
        .matrix-level-badge-icon .dashicons { font-size: 22px; width: 22px; height: 22px; }
        .matrix-level-badge.is-complete { border-color: #10b981; background: #f0fdf4; }
        .matrix-level-badge.is-complete .matrix-level-badge-icon { background: #10b981; color: #fff; }
        .matrix-level-badge.is-progress { border-color: #c4b5fd; background: #f5f3ff; }
        .matrix-level-badge.is-progress .matrix-level-badge-icon { background: #ddd6fe; color: #5b21b6; }
        .matrix-level-badge.is-empty .matrix-level-badge-icon { background: #f3f4f6; color: #9ca3af; }

        .matrix-level-badge-body { flex: 1 1 auto; min-width: 0; }
        .matrix-level-badge-title { font-weight: 600; font-size: 14px; }
        .matrix-level-badge-meta { font-size: 12px; color: #6b7280; margin-top: 2px; }
        .matrix-level-badge-bar {
            height: 4px; border-radius: 2px; background: #e5e7eb;
            margin: 8px 0 6px; overflow: hidden;
        }
        .matrix-level-badge-bar > span {
            display: block; height: 100%; border-radius: 2px;
            background: #8b5cf6; transition: width .3s ease;
        }
        .matrix-level-badge.is-complete .matrix-level-badge-bar > span { background: #10b981; }
        .matrix-level-badge-status {
            font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;
            color: #6b7280; font-weight: 600;
        }
        .matrix-level-badge.is-complete .matrix-level-badge-status { color: #047857; }
        .matrix-level-badge.is-progress .matrix-level-badge-status { color: #6d28d9; }

        /* Goal-level pill: subtle ring + pulse so the eye is drawn
           from the next-goal banner straight to the matching badge.
           Layered ON TOP of the existing is-progress / is-empty
           style so the level's actual progress colour stays visible
           — we're flagging *which* level matters next, not
           overriding the per-level state. */
        .matrix-level-badge.is-goal {
            position: relative;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.15);
            animation: matrix-goal-pulse 2.4s ease-in-out infinite;
        }
        .matrix-level-badge-flag {
            position: absolute;
            top: -10px;
            right: -10px;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: #8b5cf6;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 10px -2px rgba(139, 92, 246, 0.45);
        }
        .matrix-level-badge-flag .dashicons {
            font-size: 14px;
            width: 14px;
            height: 14px;
        }
        @keyframes matrix-goal-pulse {
            0%, 100% { box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.15); }
            50%      { box-shadow: 0 0 0 6px rgba(139, 92, 246, 0.10); }
        }
        @media (prefers-reduced-motion: reduce) {
            .matrix-level-badge.is-goal { animation: none; }
        }
        </style>
        <?php
    }

    /**
     * Render a tree node recursively as HTML.
     *
     * Differentiates three node states by border colour and a small
     * badge: "You" (the tree root, the user viewing their own tree),
     * "Direct" (a member the root personally sponsored, regardless of
     * where they sit in the tree), and "Spillover" (a member sitting in
     * the root's downline because they were placed there structurally,
     * but whose sponsor is someone else — typically a downstream member
     * whose own slot was full when the new sign-up came in).
     *
     * The distinction matters because compensation rules in MLM
     * commonly differ between direct and spillover referrals (referral
     * bonus vs level commission), and members care a lot about which
     * sign-ups they personally drove. Before this change every
     * non-root node looked identical, hiding what is arguably the most
     * important piece of information in the view.
     *
     * Classification rule: a node is "direct" if its sponsor_id equals
     * the tree-root user_id, and "spillover" otherwise. Imported legacy
     * rows where the referrals-backfill phase couldn't resolve a
     * sponsor (sponsor_id IS NULL) fall through to "spillover" — the
     * conservative default, since labelling them "direct" without
     * evidence would let an admin or member double-count direct
     * referrals.
     *
     * @param array $node              Tree node from
     *                                 Matrix_MLM_Plan_Engine::build_tree_recursive().
     * @param int   $width             Plan width (used to render empty slots).
     * @param bool  $is_root           True only for the outermost call.
     * @param int   $root_user_id      WP user id of the tree-root user (the
     *                                 member whose tree we are rendering).
     *                                 Required to classify each descendant
     *                                 as direct vs spillover.
     * @param int   $max_render_depth  Deepest absolute level to render
     *                                 children for. At any node whose
     *                                 level == $max_render_depth and
     *                                 whose total_downline > 0, render a
     *                                 "Show more" expand button instead
     *                                 of children. Click triggers the
     *                                 fetch_subtree AJAX endpoint to
     *                                 lazy-load the next chunk.
     */
    private function render_tree_node($node, $width, $is_root = true, $root_user_id = 0, $max_render_depth = 4) {
        if (!$node) return;

        $node_user_id    = (int) $node['user_id'];
        $is_display_root = ($node_user_id === (int) $root_user_id);

        // Distinguish two slightly different "is this me?" tests:
        //   - $is_display_root: this node is the tree's current root
        //     (the visual "you are here" anchor). Always gets the
        //     primary indigo styling. Never clickable as a pivot.
        //   - $is_actual_viewer: this node belongs to the
        //     authenticated viewer regardless of whether the tree is
        //     pivoted. Drives the "You" pill text — when pivoted,
        //     the displayed root might NOT be the viewer, so we show
        //     a "Viewing" pill instead of "You" on it.
        $viewer_user_id  = isset($this->pivot_state['viewer_user_id'])
            ? (int) $this->pivot_state['viewer_user_id']
            : (int) $root_user_id;
        $is_actual_viewer = ($node_user_id === $viewer_user_id);

        // Classify the node so the right badge + style can apply.
        // Tree root is always "you" — we never label the root as
        // direct/spillover even though formally the root has no sponsor
        // in this tree.
        if ($is_display_root) {
            $node_class = 'matrix-tree-node-you';
            // Pivoted root: label "Viewing" so it's obvious this isn't
            // the viewer's own card. Non-pivoted root keeps the "You"
            // label members already recognise.
            if ($is_actual_viewer) {
                $relationship       = 'you';
                $relationship_label = __('You', 'matrix-mlm');
            } else {
                $relationship       = 'viewing';
                $relationship_label = __('Viewing', 'matrix-mlm');
            }
        } else {
            // sponsor_id can be NULL on rows where the importer couldn't
            // resolve a sponsor (orphan ref_by) — treat those as
            // spillover for safety. See class docblock for rationale.
            $sponsor_id = isset($node['sponsor_id']) ? (int) $node['sponsor_id'] : 0;
            if ($sponsor_id > 0 && $sponsor_id === (int) $root_user_id) {
                $node_class         = 'matrix-tree-node-direct';
                $relationship       = 'direct';
                $relationship_label = __('Direct', 'matrix-mlm');
            } else {
                $node_class         = 'matrix-tree-node-spillover';
                $relationship       = 'spillover';
                $relationship_label = __('Spillover', 'matrix-mlm');
            }
        }

        $node_level    = (int) $node['level'];
        $downline      = (int) $node['total_downline'];
        $has_children  = !empty($node['children']);
        // We render the children block (with its existing children +
        // empty slots) whenever the node is shallower than the render
        // cap OR already has children we received from the server.
        // Once we hit the cap with no children rendered, the node
        // gets an "expand" button instead — provided it has any
        // downline at all to reveal.
        $render_children_block = ($node_level < $max_render_depth) || $has_children;
        $render_expand_button  = !$render_children_block && $downline > 0;

        // The username becomes a pivot link on every active descendant
        // — clicking re-roots the tree on that member. The display
        // root is never clickable (we're already there) and neither
        // are empty slots (no user to pivot to). Imported legacy
        // members with sponsor_id NULL still get a pivot link because
        // the link's auth runs against position id, not sponsor.
        $username        = $node['username'] ?? ('User #' . $node_user_id);
        $pivot_link_href = '';
        if (!$is_display_root && $node_user_id > 0) {
            $pivot_link_href = $this->pivot_url_for_user($node_user_id);
        }

        // Heat-map data for this node (all three metrics pre-baked
        // by compute_heat_data() so the JS toggle is a pure DOM
        // attribute flip — no AJAX, no recompute).
        //
        // The display root is excluded from heat scoring on purpose:
        // it's always rendered as the indigo "you" / "viewing" card,
        // and tinting it would conflict with that fixed identity.
        // For descendants, we emit data-heat-{metric} buckets on
        // the outer node card (CSS uses these to apply tints when
        // the wrapper is in heatmap-active mode) plus a hidden
        // <span class="tree-node-heat-pill"> carrying all three
        // labels — JS shows the right label on toggle, but we also
        // pre-fill the visible textContent with whichever metric
        // matches the currently-active mode so SSR works without
        // JS for the metric that came in via the URL.
        $heat_attrs   = '';
        $pill_attrs   = '';
        $pill_text    = '';
        $pill_hidden  = true;
        $node_heat    = null;
        if (!$is_display_root && isset($this->pivot_state['heat_data'][(int) $node['id']])) {
            $node_heat = $this->pivot_state['heat_data'][(int) $node['id']];
        }
        if ($node_heat) {
            $heat_attrs .= ' data-heat-downline="'   . esc_attr($node_heat['downline']['bucket'])   . '"';
            $heat_attrs .= ' data-heat-commission="' . esc_attr($node_heat['commission']['bucket']) . '"';
            $heat_attrs .= ' data-heat-active="'     . esc_attr($node_heat['active']['bucket'])     . '"';
            $pill_attrs .= ' data-pill-downline="'   . esc_attr($node_heat['downline']['label'])   . '"';
            $pill_attrs .= ' data-pill-commission="' . esc_attr($node_heat['commission']['label']) . '"';
            $pill_attrs .= ' data-pill-active="'     . esc_attr($node_heat['active']['label'])     . '"';

            $current_mode   = isset($this->pivot_state['mode'])        ? (string) $this->pivot_state['mode']        : 'structure';
            $current_metric = isset($this->pivot_state['heat_metric']) ? (string) $this->pivot_state['heat_metric'] : 'downline';
            if (isset($node_heat[$current_metric])) {
                $pill_text   = (string) $node_heat[$current_metric]['label'];
                $pill_hidden = ($current_mode !== 'activity' || $pill_text === '');
            }
        }
        ?>
        <div class="matrix-tree-item">
            <div class="matrix-tree-node <?php echo esc_attr($node_class); ?>" data-relationship="<?php echo esc_attr($relationship); ?>" data-position-id="<?php echo (int) $node['id']; ?>" data-user-id="<?php echo (int) $node_user_id; ?>"<?php echo $heat_attrs; // safe: each value is esc_attr'd above ?>>
                <div class="tree-node-avatar tree-node-info-trigger" role="button" tabindex="0" aria-label="<?php echo esc_attr(sprintf(
                    /* translators: %s: username whose details we'd reveal */
                    __('Show details for %s', 'matrix-mlm'),
                    $username
                )); ?>" aria-haspopup="dialog" aria-expanded="false">
                    <?php echo get_avatar($node_user_id, 36); ?>
                </div>
                <div class="tree-node-info">
                    <div class="tree-node-name-row">
                        <?php if ($pivot_link_href !== ''): ?>
                            <strong><a class="tree-node-pivot-link" href="<?php echo esc_url($pivot_link_href); ?>" title="<?php echo esc_attr(sprintf(
                                /* translators: %s: username we'd pivot to */
                                __('View %s\'s tree', 'matrix-mlm'),
                                $username
                            )); ?>"><?php echo esc_html($username); ?></a></strong>
                        <?php else: ?>
                            <strong><?php echo esc_html($username); ?></strong>
                        <?php endif; ?>
                        <?php if (!$is_display_root): ?>
                        <span class="tree-node-badge tree-node-badge-<?php echo esc_attr($relationship); ?>" title="<?php echo $relationship === 'direct'
                            ? esc_attr__('You personally sponsored this member.', 'matrix-mlm')
                            : esc_attr__('Placed under you via spillover — someone else sponsored them.', 'matrix-mlm'); ?>">
                            <?php echo esc_html($relationship_label); ?>
                        </span>
                        <?php elseif (!$is_actual_viewer): ?>
                        <span class="tree-node-badge tree-node-badge-viewing" title="<?php esc_attr_e('You are viewing this member\'s tree. Click the breadcrumb above to navigate back.', 'matrix-mlm'); ?>">
                            <?php echo esc_html($relationship_label); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <small><?php printf(__('Level %d', 'matrix-mlm'), $node_level); ?> &bull; <?php printf(__('%d downline', 'matrix-mlm'), $downline); ?></small>
                    <?php if ($node_heat): ?>
                    <span class="tree-node-heat-pill"<?php echo $pill_attrs; // safe: esc_attr'd above ?><?php echo $pill_hidden ? ' hidden' : ''; ?>><?php echo esc_html($pill_text); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($render_children_block): ?>
            <div class="matrix-tree-children">
                <?php $this->render_children_inner($node, $width, $root_user_id, $max_render_depth); ?>
            </div>
            <?php elseif ($render_expand_button): ?>
            <div class="matrix-tree-expand">
                <button type="button" class="matrix-tree-expand-btn"
                        data-position-id="<?php echo (int) $node['id']; ?>"
                        data-from-level="<?php echo $node_level; ?>"
                        aria-label="<?php echo esc_attr(sprintf(
                            /* translators: %s: username of the node being expanded */
                            __('Show downline below %s', 'matrix-mlm'),
                            $node['username'] ?? ('User #' . $node['user_id'])
                        )); ?>">
                    <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                    <?php
                    printf(
                        esc_html(_n(
                            'Show %s more member',
                            'Show %s more members',
                            $downline,
                            'matrix-mlm'
                        )),
                        number_format_i18n($downline)
                    );
                    ?>
                </button>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the contents of one .matrix-tree-children block — the
     * children + empty-slot placeholders for a single parent node.
     *
     * Pulled out of render_tree_node() so the AJAX expansion handler
     * can reuse the same code path. When a member clicks "Show more"
     * the server-side fetch_subtree handler builds a full subtree (via
     * Matrix_MLM_Plan_Engine::build_subtree) rooted at the position
     * being expanded, then calls this helper to produce the exact same
     * markup the initial render would have produced if the original
     * page load had asked for those deeper levels. The AJAX response
     * is then injected directly under the expand button as a
     * .matrix-tree-children block, so the resulting DOM is
     * indistinguishable from a deeper initial render.
     *
     * Visibility: public so the AJAX endpoint in Matrix_MLM_Core can
     * call it; intentionally NOT static so it remains the single
     * recursion partner of render_tree_node() (also a method on this
     * class). The two functions call each other and share the same
     * $max_render_depth contract — keeping them as siblings on the
     * same instance makes that relationship the easiest to follow.
     *
     * @see Matrix_MLM_Core::process_fetch_subtree()  AJAX entry point.
     * @see Matrix_MLM_Plan_Engine::build_subtree()    Data fetcher.
     */
    public function render_children_inner($node, $width, $root_user_id, $max_render_depth) {
        $children    = !empty($node['children']) ? $node['children'] : [];
        $child_count = count($children);

        foreach ($children as $child) {
            $this->render_tree_node($child, $width, false, $root_user_id, $max_render_depth);
        }

        // Empty placeholder slots so an underbuilt level reads as a
        // matrix instead of a sparse list. Width minus existing
        // children = how many filler cards to draw. The slot's
        // absolute level is parent.level + 1 — we use that to look
        // up the per-slot commission and to decide whether this
        // particular slot sits on the goal level (in which case it
        // gets the highlighted "is-goal-level" treatment).
        $slot_level = isset($node['level']) ? ((int) $node['level'] + 1) : 0;
        for ($i = 0; $i < $width - $child_count; $i++) {
            $this->render_empty_slot($slot_level);
        }
    }

    /**
     * Render a single empty-slot placeholder, optionally as a
     * "Refer 1 more here →" CTA.
     *
     * Empty slots are the most actionable affordance in the tree —
     * they're literally where the next member goes. Wiring them as a
     * clickable CTA that copies the viewer's invite link turns the
     * tree from a passive read into a goal-oriented dashboard, which
     * is what the per-level "Next goal" banner is also pushing toward.
     *
     * Three visual states, gated by what's available in pivot_state:
     *
     *   - With a referral_url available, the slot becomes a button-
     *     styled card. Click / Enter / Space copies the invite URL
     *     (handled by render_referral_copy_script's delegated JS).
     *     The subtitle reads "Refer 1 more here →" and a `title`
     *     tooltip quotes the level number plus the per-slot
     *     commission when the plan defines one.
     *   - With slot_level === pivot_state.goal_level, the same card
     *     additionally gets the `is-goal-level` modifier — a soft
     *     pulsing ring that ties this empty slot to the matching
     *     "Next goal" banner above the tree, so the eye lands here
     *     first.
     *   - With no referral_url (defensive: matrix_user_meta row
     *     missing), we fall through to the classic "Empty Slot /
     *     Available" card with a plain `title`. Same DOM shape, no
     *     click behavior — keeps the tree visually intact even on
     *     legacy accounts.
     *
     * @param int $slot_level Absolute level of this slot in the tree
     *                        (parent's level + 1). 0 means "unknown",
     *                        which suppresses the level-aware tooltip.
     */
    private function render_empty_slot($slot_level) {
        $referral_url       = isset($this->pivot_state['referral_url'])      ? (string) $this->pivot_state['referral_url']      : '';
        $goal_level         = isset($this->pivot_state['goal_level'])        ? (int)    $this->pivot_state['goal_level']        : 0;
        $level_commissions  = isset($this->pivot_state['level_commissions']) ? (array)  $this->pivot_state['level_commissions'] : [];
        $currency           = isset($this->pivot_state['currency_symbol'])
            ? (string) $this->pivot_state['currency_symbol']
            : (string) get_option('matrix_mlm_currency_symbol', '₦');

        $is_cta   = ($referral_url !== '');
        $is_goal  = ($is_cta && $slot_level > 0 && $slot_level === $goal_level);
        $per_slot = (int) $slot_level > 0 && isset($level_commissions[$slot_level])
            ? (float) $level_commissions[$slot_level]
            : 0.0;

        // Build the subtitle and tooltip copy in one place so the
        // accessible name (aria-label) stays in lockstep with the
        // visible hint.
        if ($is_cta) {
            $subtitle = __('Refer 1 more here →', 'matrix-mlm');
            if ($slot_level > 0 && $per_slot > 0) {
                $tooltip = sprintf(
                    /* translators: 1: level number, 2: per-slot commission (e.g. "₦100.00") */
                    __('Refer 1 more person here at Level %1$d to earn %2$s. Click to copy your invite link.', 'matrix-mlm'),
                    $slot_level,
                    $currency . number_format($per_slot, 2)
                );
            } elseif ($slot_level > 0) {
                $tooltip = sprintf(
                    /* translators: %d: level number */
                    __('Refer 1 more person here at Level %d. Click to copy your invite link.', 'matrix-mlm'),
                    $slot_level
                );
            } else {
                $tooltip = __('Refer 1 more person here. Click to copy your invite link.', 'matrix-mlm');
            }
        } else {
            $subtitle = __('Available', 'matrix-mlm');
            $tooltip  = __('Empty slot — open for a new member.', 'matrix-mlm');
        }

        $classes = 'matrix-tree-node matrix-tree-node-empty';
        if ($is_cta)  { $classes .= ' matrix-tree-node-empty-cta'; }
        if ($is_goal) { $classes .= ' is-goal-level'; }
        ?>
        <div class="matrix-tree-item">
            <?php if ($is_cta): ?>
            <div class="<?php echo esc_attr($classes); ?>"
                 role="button"
                 tabindex="0"
                 data-slot-level="<?php echo (int) $slot_level; ?>"
                 data-referral-url="<?php echo esc_attr($referral_url); ?>"
                 data-action="copy-referral"
                 aria-label="<?php echo esc_attr($tooltip); ?>"
                 title="<?php echo esc_attr($tooltip); ?>">
                <div class="tree-node-avatar tree-node-empty-avatar">
                    <span class="dashicons dashicons-plus-alt2"></span>
                </div>
                <div class="tree-node-info">
                    <strong><?php esc_html_e('Empty Slot', 'matrix-mlm'); ?></strong>
                    <small><?php echo esc_html($subtitle); ?></small>
                </div>
                <span class="matrix-tree-empty-cta-toast" aria-live="polite" hidden><?php
                    esc_html_e('Copied!', 'matrix-mlm');
                ?></span>
            </div>
            <?php else: ?>
            <div class="<?php echo esc_attr($classes); ?>" title="<?php echo esc_attr($tooltip); ?>">
                <div class="tree-node-avatar tree-node-empty-avatar">
                    <span class="dashicons dashicons-plus-alt2"></span>
                </div>
                <div class="tree-node-info">
                    <strong><?php esc_html_e('Empty Slot', 'matrix-mlm'); ?></strong>
                    <small><?php echo esc_html($subtitle); ?></small>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the search box that sits above the genealogy tree.
     *
     * Lets a viewer find a specific downline member by typing their
     * username, email or display name and either pressing Enter or
     * clicking a result, which navigates straight to that member's
     * pivoted view (?pivot_user_id=X). Solves the "I have hundreds
     * of downline members and need to scroll to find Bob" problem
     * without bolting on a separate directory page.
     *
     * Markup contract (the JS in render_search_script() depends on
     * these hooks staying stable):
     *   .matrix-genealogy-search           outer wrapper
     *     input.matrix-genealogy-search-input          the text field
     *     button.matrix-genealogy-search-clear         clear-X (hidden when empty)
     *     ul.matrix-genealogy-search-results           dropdown (hidden by default)
     *
     * Plan id is rendered as a data-* attribute on the wrapper
     * rather than passed via a hidden form field — the value is
     * already in the URL (?plan_id=…) and the JS just needs a way
     * to read it without re-parsing window.location every keystroke.
     *
     * Renders nothing pivoted-specific: the search is intentionally
     * always against the *viewer's* downline, not the pivoted root's
     * downline. That matches what the URL already shows when a
     * member jumps to a result (?pivot_user_id=X is always set
     * relative to the viewer's tree), and avoids a confusing edge
     * case where searching from inside a pivoted view could
     * surface members the viewer can't authorise without first
     * un-pivoting.
     *
     * Inline CSS lives in the same render method so the markup and
     * its style are co-located — the existing render_level_badges()
     * helper above this class uses exactly that pattern. If shared
     * dashboard styling later absorbs these rules they should move
     * into matrix-dashboard.css alongside the existing
     * .matrix-genealogy-* declarations.
     *
     * @param int $plan_id Plan whose downline we're scoping the search to.
     */
    private function render_search_box($plan_id) {
        $plan_id = (int) $plan_id;
        $input_id = 'matrix-genealogy-search-input';
        ?>
        <div class="matrix-genealogy-search" data-plan-id="<?php echo esc_attr($plan_id); ?>">
            <label for="<?php echo esc_attr($input_id); ?>" class="screen-reader-text"><?php
                esc_html_e('Search your downline', 'matrix-mlm');
            ?></label>
            <div class="matrix-genealogy-search-field">
                <span class="dashicons dashicons-search matrix-genealogy-search-icon" aria-hidden="true"></span>
                <input
                    type="search"
                    id="<?php echo esc_attr($input_id); ?>"
                    class="matrix-genealogy-search-input"
                    placeholder="<?php esc_attr_e('Search downline by username or email…', 'matrix-mlm'); ?>"
                    autocomplete="off"
                    spellcheck="false"
                    role="combobox"
                    aria-autocomplete="list"
                    aria-expanded="false"
                    aria-controls="matrix-genealogy-search-results"
                />
                <button
                    type="button"
                    class="matrix-genealogy-search-clear"
                    aria-label="<?php esc_attr_e('Clear search', 'matrix-mlm'); ?>"
                    hidden
                >
                    <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                </button>
            </div>
            <div class="matrix-genealogy-search-status" role="status" aria-live="polite"></div>
            <ul
                id="matrix-genealogy-search-results"
                class="matrix-genealogy-search-results"
                role="listbox"
                hidden
            ></ul>
        </div>

        <style>
        .matrix-genealogy-search {
            position: relative;
            margin-bottom: 18px;
        }
        .matrix-genealogy-search-field {
            position: relative;
            display: flex;
            align-items: center;
        }
        .matrix-genealogy-search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 18px;
            width: 18px;
            height: 18px;
            pointer-events: none;
        }
        .matrix-genealogy-search-input {
            width: 100%;
            box-sizing: border-box;
            padding: 10px 38px 10px 38px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            background: #fff;
            transition: border-color .15s ease, box-shadow .15s ease;
        }
        .matrix-genealogy-search-input:focus {
            outline: none;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.18);
        }
        .matrix-genealogy-search-clear {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: 0;
            padding: 4px;
            cursor: pointer;
            color: #6b7280;
            border-radius: 4px;
        }
        .matrix-genealogy-search-clear:hover { color: #111827; background: #f3f4f6; }
        .matrix-genealogy-search-clear .dashicons { font-size: 18px; width: 18px; height: 18px; }

        .matrix-genealogy-search-status {
            font-size: 12px;
            color: #6b7280;
            margin-top: 6px;
            min-height: 16px;
        }
        .matrix-genealogy-search-status.is-error { color: #b91c1c; }

        .matrix-genealogy-search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            margin: 4px 0 0;
            padding: 4px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 12px 28px -8px rgba(0,0,0,0.14);
            list-style: none;
            max-height: 320px;
            overflow-y: auto;
            z-index: 20;
        }
        .matrix-genealogy-search-result {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            border-radius: 6px;
            cursor: pointer;
            color: inherit;
            text-decoration: none;
        }
        .matrix-genealogy-search-result:hover,
        .matrix-genealogy-search-result.is-active {
            background: #f5f3ff;
            color: #111827;
        }
        .matrix-genealogy-search-result-name {
            font-weight: 600;
            font-size: 14px;
            line-height: 1.2;
        }
        .matrix-genealogy-search-result-meta {
            font-size: 12px;
            color: #6b7280;
            line-height: 1.2;
            margin-top: 2px;
        }
        .matrix-genealogy-search-result-level {
            margin-left: auto;
            flex-shrink: 0;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #6d28d9;
            background: #ede9fe;
            padding: 3px 8px;
            border-radius: 999px;
        }
        .matrix-genealogy-search-empty {
            padding: 10px;
            font-size: 13px;
            color: #6b7280;
            text-align: center;
        }
        </style>
        <?php
    }

    /**
     * Inline JS that powers the genealogy search box's typeahead.
     *
     * Behaviour:
     *   - Debounces the input by 250ms so a typing burst maps to a
     *     single AJAX request, not one per keystroke.
     *   - Sequences requests with a monotonically increasing token
     *     so an out-of-order response from a slow earlier query
     *     can never overwrite a fresher result list. Without this
     *     guard a user typing "a" -> "al" -> "ali" with variable
     *     network latency could end up looking at "a"'s results
     *     under "ali"'s query.
     *   - Caches the last query's results in memory so backspacing
     *     and re-typing the same prefix doesn't re-hit the server.
     *     Cache is cleared on Escape or on Clear so the user can
     *     force a refresh if the downline changed mid-session.
     *   - Keyboard nav: ArrowDown/Up move highlight, Enter
     *     navigates to the highlighted result (or the first if
     *     none is highlighted), Escape closes the dropdown and
     *     restores focus to the input.
     *   - Click outside closes the dropdown but leaves the query
     *     in the box, so the user can re-open it without retyping.
     *
     * Why inline rather than enqueued: identical reasoning to
     * render_lazy_load_script() above — the dashboard view is
     * authenticated and uncached, the script is short and
     * single-purpose, and co-locating it with the markup it
     * decorates is the existing convention in this class. Moving
     * it to matrix-public.js would scatter responsibility for
     * features that only ever run on this one tab.
     *
     * @param int $plan_id Plan id baked into the AJAX payload.
     */
    private function render_search_script($plan_id) {
        $plan_id = (int) $plan_id;
        ?>
        <script>
        (function() {
            var wrapper = document.querySelector('.matrix-genealogy-search');
            if (!wrapper) return;

            var input    = wrapper.querySelector('.matrix-genealogy-search-input');
            var clearBtn = wrapper.querySelector('.matrix-genealogy-search-clear');
            var listEl   = wrapper.querySelector('.matrix-genealogy-search-results');
            var statusEl = wrapper.querySelector('.matrix-genealogy-search-status');
            if (!input || !listEl || !statusEl) return;

            var planId    = parseInt(wrapper.getAttribute('data-plan-id'), 10) || 0;
            var DEBOUNCE  = 250;
            var MIN_CHARS = 2;
            // Monotonically increasing request token — see comment
            // on race-condition handling in the docblock above.
            var requestSeq      = 0;
            var lastRenderedSeq = 0;
            var debounceTimer   = null;
            // Tiny in-memory cache so re-typing the same prefix
            // (which is what users actually do — type, see,
            // backspace, re-type) doesn't hit the server every time.
            var cache = Object.create(null);
            var activeIndex = -1;
            var currentResults = [];

            var STR_NO_RESULTS = '<?php echo esc_js(__('No matches in your downline.', 'matrix-mlm')); ?>';
            var STR_TYPE_MORE  = '<?php echo esc_js(__('Type at least 2 characters to search.', 'matrix-mlm')); ?>';
            var STR_SEARCHING  = '<?php echo esc_js(__('Searching…', 'matrix-mlm')); ?>';
            var STR_ERROR      = '<?php echo esc_js(__('Search failed. Please try again.', 'matrix-mlm')); ?>';
            var STR_LEVEL      = '<?php echo esc_js(__('L%d', 'matrix-mlm')); ?>';

            function setStatus(msg, isError) {
                statusEl.textContent = msg || '';
                if (isError) {
                    statusEl.classList.add('is-error');
                } else {
                    statusEl.classList.remove('is-error');
                }
            }

            function setListOpen(open) {
                if (open) {
                    listEl.hidden = false;
                    input.setAttribute('aria-expanded', 'true');
                } else {
                    listEl.hidden = true;
                    input.setAttribute('aria-expanded', 'false');
                    activeIndex = -1;
                }
            }

            function renderResults(results, query) {
                currentResults = results || [];
                listEl.innerHTML = '';
                activeIndex = -1;

                if (!currentResults.length) {
                    var empty = document.createElement('li');
                    empty.className = 'matrix-genealogy-search-empty';
                    empty.textContent = STR_NO_RESULTS;
                    listEl.appendChild(empty);
                    setListOpen(true);
                    return;
                }

                currentResults.forEach(function(r, i) {
                    var li = document.createElement('li');
                    li.setAttribute('role', 'option');
                    li.setAttribute('id', 'matrix-genealogy-search-result-' + i);

                    var a = document.createElement('a');
                    a.className = 'matrix-genealogy-search-result';
                    a.href = r.pivot_url;
                    a.setAttribute('data-index', String(i));

                    var info = document.createElement('div');
                    info.style.minWidth = '0';
                    info.style.flex = '1 1 auto';

                    var nameEl = document.createElement('div');
                    nameEl.className = 'matrix-genealogy-search-result-name';
                    // Use the username as the primary label since
                    // it's the unique identifier members recognise;
                    // display_name (when distinct) goes in the
                    // sub-line for disambiguation.
                    nameEl.textContent = r.username || ('User #' + r.user_id);

                    var metaEl = document.createElement('div');
                    metaEl.className = 'matrix-genealogy-search-result-meta';
                    var metaBits = [];
                    if (r.display_name && r.display_name !== r.username) {
                        metaBits.push(r.display_name);
                    }
                    if (r.email_masked) {
                        metaBits.push(r.email_masked);
                    }
                    metaEl.textContent = metaBits.join(' · ');

                    info.appendChild(nameEl);
                    if (metaBits.length) info.appendChild(metaEl);

                    var levelEl = document.createElement('span');
                    levelEl.className = 'matrix-genealogy-search-result-level';
                    levelEl.textContent = STR_LEVEL.replace('%d', String(r.level));

                    a.appendChild(info);
                    a.appendChild(levelEl);
                    li.appendChild(a);
                    listEl.appendChild(li);
                });

                setListOpen(true);
            }

            function highlight(index) {
                var items = listEl.querySelectorAll('.matrix-genealogy-search-result');
                if (!items.length) return;
                if (index < 0) index = items.length - 1;
                if (index >= items.length) index = 0;
                items.forEach(function(el, i) {
                    if (i === index) {
                        el.classList.add('is-active');
                        el.scrollIntoView({ block: 'nearest' });
                    } else {
                        el.classList.remove('is-active');
                    }
                });
                activeIndex = index;
                input.setAttribute('aria-activedescendant', 'matrix-genealogy-search-result-' + index);
            }

            function navigateTo(index) {
                if (!currentResults.length) return;
                var target = currentResults[index >= 0 ? index : 0];
                if (target && target.pivot_url) {
                    window.location.href = target.pivot_url;
                }
            }

            function runSearch(query) {
                // Cache-hit fast path: skip both the debounce roundtrip
                // *and* the server hit if we already have results for
                // this exact query. Cache is keyed by the trimmed
                // lowercased query so case-only differences share a
                // slot.
                var key = query.trim().toLowerCase();
                if (cache[key]) {
                    setStatus('');
                    renderResults(cache[key], query);
                    return;
                }

                setStatus(STR_SEARCHING);
                var seq = ++requestSeq;

                var data = new FormData();
                data.append('action',        'matrix_mlm_action');
                data.append('matrix_action', 'search_genealogy');
                data.append('nonce',         matrixMLM.nonce);
                data.append('plan_id',       String(planId));
                data.append('q',             query);

                fetch(matrixMLM.ajaxUrl, {
                    method:      'POST',
                    body:        data,
                    credentials: 'same-origin'
                })
                .then(function(r) { return r.json(); })
                .then(function(j) {
                    // Drop stale responses — only the latest fired
                    // request is allowed to update the dropdown.
                    if (seq < lastRenderedSeq) return;
                    lastRenderedSeq = seq;

                    if (!j || !j.success) {
                        setStatus(STR_ERROR, true);
                        setListOpen(false);
                        return;
                    }
                    var rows = (j.data && j.data.results) || [];
                    cache[key] = rows;
                    setStatus('');
                    renderResults(rows, query);
                })
                .catch(function() {
                    if (seq < lastRenderedSeq) return;
                    lastRenderedSeq = seq;
                    setStatus(STR_ERROR, true);
                    setListOpen(false);
                });
            }

            function onInput() {
                var q = input.value || '';
                clearBtn.hidden = q.length === 0;

                if (debounceTimer) {
                    clearTimeout(debounceTimer);
                    debounceTimer = null;
                }

                if (q.trim().length === 0) {
                    setStatus('');
                    setListOpen(false);
                    currentResults = [];
                    return;
                }
                if (q.trim().length < MIN_CHARS) {
                    setStatus(STR_TYPE_MORE);
                    setListOpen(false);
                    currentResults = [];
                    return;
                }

                debounceTimer = setTimeout(function() {
                    runSearch(q);
                }, DEBOUNCE);
            }

            function clearAll() {
                input.value = '';
                clearBtn.hidden = true;
                setStatus('');
                setListOpen(false);
                currentResults = [];
                // Also nuke the cache so re-typing the same prefix
                // forces a fresh server query — matches user
                // intent when they explicitly hit Clear.
                cache = Object.create(null);
                input.focus();
            }

            input.addEventListener('input', onInput);
            input.addEventListener('focus', function() {
                if (currentResults.length || (input.value.trim().length >= MIN_CHARS && statusEl.textContent)) {
                    setListOpen(true);
                }
            });

            input.addEventListener('keydown', function(e) {
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (listEl.hidden) {
                        // No results to scroll through yet — re-run
                        // the current query so a focused user
                        // pressing ArrowDown doesn't get stuck on
                        // an empty dropdown.
                        if (input.value.trim().length >= MIN_CHARS) onInput();
                        return;
                    }
                    highlight(activeIndex + 1);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (listEl.hidden) return;
                    highlight(activeIndex - 1);
                } else if (e.key === 'Enter') {
                    if (!listEl.hidden && currentResults.length) {
                        e.preventDefault();
                        navigateTo(activeIndex);
                    }
                } else if (e.key === 'Escape') {
                    if (!listEl.hidden) {
                        e.preventDefault();
                        setListOpen(false);
                    } else if (input.value) {
                        clearAll();
                    }
                }
            });

            clearBtn.addEventListener('click', clearAll);

            // Delegated click handler so we don't have to attach a
            // listener to every result row at render time.
            listEl.addEventListener('click', function(e) {
                var a = e.target.closest('.matrix-genealogy-search-result');
                if (!a) return;
                // Let the browser handle modified-click (Ctrl+click,
                // middle-click) so power users can open a result
                // in a new tab. Only intercept the plain click to
                // route through navigateTo() and pick up the
                // highlighted-row semantics.
                if (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return;
                e.preventDefault();
                var idx = parseInt(a.getAttribute('data-index'), 10) || 0;
                navigateTo(idx);
            });

            // Click-away to close the dropdown but keep the query
            // text — same affordance the WP admin search dropdown
            // uses.
            document.addEventListener('click', function(e) {
                if (!wrapper.contains(e.target)) {
                    setListOpen(false);
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * Render the shared hover-card DOM node and its inline CSS.
     *
     * One card per genealogy view, repositioned (and refilled) by JS
     * for whichever node is currently active. Living once at the end
     * of the tree wrapper means we don't ship a card stub per node —
     * a 3x9 plan with hundreds of visible positions would otherwise
     * pay for that markup hundreds of times even though only one card
     * is ever visible at a time.
     *
     * Markup contract (the JS in render_hovercard_script() depends on
     * these hooks staying stable):
     *   .matrix-tree-hovercard               outer pop-up
     *     .matrix-tree-hovercard-arrow       little pointer triangle
     *     .matrix-tree-hovercard-close       dismiss button
     *     .matrix-tree-hovercard-loading     spinner state
     *     .matrix-tree-hovercard-error       error state
     *     .matrix-tree-hovercard-body        success state
     *       .matrix-tree-hovercard-name      full name
     *       .matrix-tree-hovercard-username  @login (small)
     *       .matrix-tree-hovercard-fields    dl with the four metadata rows
     *       .matrix-tree-hovercard-profile   admin-only "View profile" link
     *
     * Position is `fixed` so the card lives in viewport coordinates;
     * the JS computes top/left from the trigger node's getBoundingClientRect()
     * and flips above/below to stay on-screen. Below 768px the JS
     * switches to a centered "mini modal" placement because the
     * mobile layout is an indented vertical tree where there isn't a
     * sensible horizontal anchor.
     *
     * Inline CSS lives next to the markup for the same reason
     * render_level_badges() and render_search_box() do — single-tab
     * dashboard styling that's faster to find when you're editing
     * the panel it decorates than buried in matrix-dashboard.css.
     */
    private function render_hovercard($plan_id) {
        $plan_id = (int) $plan_id;
        ?>
        <div
            id="matrix-tree-hovercard"
            class="matrix-tree-hovercard"
            data-plan-id="<?php echo esc_attr($plan_id); ?>"
            role="dialog"
            aria-modal="false"
            aria-label="<?php esc_attr_e('Member details', 'matrix-mlm'); ?>"
            aria-live="polite"
            hidden
        >
            <span class="matrix-tree-hovercard-arrow" aria-hidden="true"></span>
            <button
                type="button"
                class="matrix-tree-hovercard-close"
                aria-label="<?php esc_attr_e('Close member details', 'matrix-mlm'); ?>"
            >
                <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
            </button>

            <div class="matrix-tree-hovercard-loading" hidden>
                <span class="dashicons dashicons-update matrix-tree-spin" aria-hidden="true"></span>
                <span><?php esc_html_e('Loading…', 'matrix-mlm'); ?></span>
            </div>

            <div class="matrix-tree-hovercard-error" hidden></div>

            <div class="matrix-tree-hovercard-body" hidden>
                <div class="matrix-tree-hovercard-header">
                    <strong class="matrix-tree-hovercard-name"></strong>
                    <span class="matrix-tree-hovercard-username"></span>
                </div>

                <dl class="matrix-tree-hovercard-fields">
                    <dt><?php esc_html_e('Joined', 'matrix-mlm'); ?></dt>
                    <dd class="matrix-tree-hovercard-joined">—</dd>

                    <dt><?php esc_html_e('Sponsor', 'matrix-mlm'); ?></dt>
                    <dd class="matrix-tree-hovercard-sponsor">—</dd>

                    <dt><?php esc_html_e('Plans', 'matrix-mlm'); ?></dt>
                    <dd class="matrix-tree-hovercard-plans">—</dd>

                    <dt><?php esc_html_e('Branch commission', 'matrix-mlm'); ?></dt>
                    <dd class="matrix-tree-hovercard-commission">—</dd>
                </dl>

                <a
                    class="matrix-tree-hovercard-profile"
                    href="#"
                    target="_blank"
                    rel="noopener"
                    hidden
                ><?php esc_html_e('View profile in admin', 'matrix-mlm'); ?> &rarr;</a>
            </div>
        </div>

        <style>
        /* Avatar becomes the touch trigger on mobile (where hover
           doesn't fire reliably). cursor:pointer + a subtle focus
           ring announces the affordance without bolting on a
           dedicated info icon next to the username — keeps the node
           card visually quiet. */
        .tree-node-info-trigger {
            cursor: pointer;
            outline: 0;
            border-radius: 50%;
            transition: box-shadow .15s ease, transform .15s ease;
        }
        .tree-node-info-trigger:hover,
        .tree-node-info-trigger:focus-visible {
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.25);
            transform: translateY(-1px);
        }
        .matrix-tree-node-empty .tree-node-info-trigger { /* defensive: empty slots don't get this class but just in case */
            cursor: default;
            box-shadow: none;
            transform: none;
        }

        .matrix-tree-hovercard {
            position: fixed;
            z-index: 9999;
            width: 320px;
            max-width: calc(100vw - 24px);
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            box-shadow: 0 18px 36px -10px rgba(0, 0, 0, 0.18);
            padding: 14px 16px 12px;
            font-size: 13px;
            color: #111827;
            line-height: 1.4;
        }
        .matrix-tree-hovercard[hidden] { display: none; }

        .matrix-tree-hovercard-arrow {
            position: absolute;
            width: 12px;
            height: 12px;
            background: #fff;
            border-left: 1px solid #e5e7eb;
            border-top: 1px solid #e5e7eb;
            transform: rotate(45deg);
            display: none; /* JS toggles per placement */
        }
        .matrix-tree-hovercard.is-below .matrix-tree-hovercard-arrow {
            display: block;
            top: -7px;
            left: 28px;
            transform: rotate(45deg);
        }
        .matrix-tree-hovercard.is-above .matrix-tree-hovercard-arrow {
            display: block;
            bottom: -7px;
            left: 28px;
            transform: rotate(225deg);
        }

        .matrix-tree-hovercard-close {
            position: absolute;
            top: 6px;
            right: 6px;
            background: transparent;
            border: 0;
            padding: 4px;
            border-radius: 4px;
            cursor: pointer;
            color: #6b7280;
        }
        .matrix-tree-hovercard-close:hover { color: #111827; background: #f3f4f6; }
        .matrix-tree-hovercard-close .dashicons { font-size: 16px; width: 16px; height: 16px; }

        .matrix-tree-hovercard-loading {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 0 0;
            color: #6b7280;
        }
        .matrix-tree-hovercard-loading[hidden] { display: none; }
        .matrix-tree-hovercard-loading .dashicons {
            font-size: 18px;
            width: 18px;
            height: 18px;
            color: #8b5cf6;
        }

        .matrix-tree-hovercard-error {
            color: #b91c1c;
            background: #fef2f2;
            border: 1px solid #fecaca;
            padding: 8px 10px;
            border-radius: 6px;
            margin-top: 4px;
        }
        .matrix-tree-hovercard-error[hidden] { display: none; }

        .matrix-tree-hovercard-body[hidden] { display: none; }

        .matrix-tree-hovercard-header {
            display: flex;
            flex-direction: column;
            gap: 2px;
            margin-bottom: 10px;
            padding-right: 22px; /* leave space for the close X */
        }
        .matrix-tree-hovercard-name {
            font-size: 15px;
            font-weight: 700;
        }
        .matrix-tree-hovercard-username {
            font-size: 12px;
            color: #6b7280;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }

        .matrix-tree-hovercard-fields {
            display: grid;
            grid-template-columns: 110px 1fr;
            gap: 6px 10px;
            margin: 0 0 10px;
        }
        .matrix-tree-hovercard-fields dt {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            color: #6b7280;
            margin: 0;
            padding-top: 1px;
        }
        .matrix-tree-hovercard-fields dd {
            margin: 0;
            font-size: 13px;
            color: #111827;
            word-break: break-word;
        }
        .matrix-tree-hovercard-fields dd em {
            color: #6b7280;
            font-style: normal;
            font-size: 12px;
        }
        .matrix-tree-hovercard-commission {
            font-weight: 600;
            color: #047857; /* same emerald the level-badge "complete" pill uses */
        }
        .matrix-tree-hovercard-commission.is-zero { color: #6b7280; font-weight: 500; }
        .matrix-tree-hovercard-commission.is-capped { color: #b45309; }

        .matrix-tree-hovercard-profile {
            display: inline-block;
            margin-top: 4px;
            font-size: 12px;
            font-weight: 600;
            color: #6d28d9;
            text-decoration: none;
        }
        .matrix-tree-hovercard-profile[hidden] { display: none; }
        .matrix-tree-hovercard-profile:hover { text-decoration: underline; }

        /* Mobile: the indented vertical tree leaves no good horizontal
           anchor, so we promote the card to a centered "mini modal"
           with a translucent backdrop click target. */
        @media (max-width: 767px) {
            .matrix-tree-hovercard.is-mobile {
                left: 50% !important;
                top: 50% !important;
                transform: translate(-50%, -50%);
                width: calc(100vw - 32px);
            }
            .matrix-tree-hovercard.is-mobile .matrix-tree-hovercard-arrow {
                display: none !important;
            }
        }

        /* Backdrop overlay used in mobile modal mode. Created by the
           JS on demand so non-mobile renders never pay the cost. */
        .matrix-tree-hovercard-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(17, 24, 39, 0.35);
            z-index: 9998;
        }
        .matrix-tree-hovercard-backdrop[hidden] { display: none; }
        </style>
        <?php
    }

    /**
     * Inline JS that drives the genealogy hover-card.
     *
     * Single delegated listener on the tree wrapper handles every
     * trigger surface — hovering a node, focus-into a node, clicking
     * (or pressing Enter / Space on) the avatar info-trigger. The
     * card is a single shared DOM node we move and refill rather than
     * one card per visible member, so cost stays O(1) regardless of
     * how many positions are on the page.
     *
     * Behaviour highlights:
     *   - Hover-trigger has a 220ms open delay and a 180ms close
     *     delay so brief mouseovers (e.g. the cursor passing over a
     *     node on the way to clicking the username pivot link) don't
     *     fire AJAX requests or flash a card. The same delays apply
     *     when moving between adjacent nodes — moving from card A's
     *     trigger to card B's trigger swaps the content without a
     *     visible flash if we already have B cached.
     *   - Touch devices (no hover) get a click-to-open path on the
     *     avatar trigger, with a body-level click-away listener for
     *     dismiss. The mobile modal uses a backdrop overlay so a
     *     tap outside reliably closes — without it, taps on the
     *     parts of the page covered by the indented tree would
     *     have to compete with sibling click handlers.
     *   - In-memory cache keyed by position id; backspacing /
     *     re-hovering doesn't re-hit the server. Cache is per page
     *     load — short-lived enough that admin commission updates
     *     during a session won't get stuck on a stale value across
     *     navigations, since the next pageload starts fresh.
     *   - Sequence-token-guarded fetch so a slow earlier request
     *     can't render its result on top of a fresher hover.
     *   - ESC always closes; Tab away from the card body also
     *     closes (so keyboard users don't get stuck inside an
     *     invisible focus trap).
     *
     * Why inline rather than enqueued: same trade-off as
     * render_lazy_load_script() and render_search_script() — the
     * dashboard page is authenticated and uncached, the script is
     * tied 1:1 to the markup it decorates, and a separate .js file
     * would scatter responsibility for one tab's behaviour.
     */
    private function render_hovercard_script($plan_id) {
        $plan_id = (int) $plan_id;
        ?>
        <script>
        (function() {
            var tree = document.getElementById('genealogy-tree');
            var card = document.getElementById('matrix-tree-hovercard');
            if (!tree || !card) return;

            var planId       = parseInt(card.getAttribute('data-plan-id'), 10) || 0;
            var bodyEl       = card.querySelector('.matrix-tree-hovercard-body');
            var loadingEl    = card.querySelector('.matrix-tree-hovercard-loading');
            var errorEl      = card.querySelector('.matrix-tree-hovercard-error');
            var nameEl       = card.querySelector('.matrix-tree-hovercard-name');
            var usernameEl   = card.querySelector('.matrix-tree-hovercard-username');
            var joinedEl     = card.querySelector('.matrix-tree-hovercard-joined');
            var sponsorEl    = card.querySelector('.matrix-tree-hovercard-sponsor');
            var plansEl      = card.querySelector('.matrix-tree-hovercard-plans');
            var commissionEl = card.querySelector('.matrix-tree-hovercard-commission');
            var profileEl    = card.querySelector('.matrix-tree-hovercard-profile');
            var closeBtn     = card.querySelector('.matrix-tree-hovercard-close');

            // Localised label fragments — built once via PHP so we
            // don't repeat translation calls per hover.
            var STR_LOADING_ERR    = '<?php echo esc_js(__('Could not load member details. Please try again.', 'matrix-mlm')); ?>';
            var STR_NETWORK_ERR    = '<?php echo esc_js(__('Network error.', 'matrix-mlm')); ?>';
            var STR_NO_SPONSOR     = '<?php echo esc_js(__('— (root)', 'matrix-mlm')); ?>';
            var STR_NO_PLANS       = '<?php echo esc_js(__('No active plans', 'matrix-mlm')); ?>';
            var STR_NO_DATE        = '<?php echo esc_js(__('Unknown', 'matrix-mlm')); ?>';
            var STR_BRANCH_CAPPED  = '<?php echo esc_js(__('(branch too large to summarize fully)', 'matrix-mlm')); ?>';
            var STR_YOU_TAG        = '<?php echo esc_js(__('(you)', 'matrix-mlm')); ?>';

            var OPEN_DELAY  = 220;
            var CLOSE_DELAY = 180;

            // Capability flag — falls back to "no pointer = touch"
            // for environments where matchMedia(hover) is missing.
            // Mobile uses click only (hover is unreliable there).
            var supportsHover = (window.matchMedia && window.matchMedia('(hover: hover)').matches);

            var openTimer  = null;
            var closeTimer = null;
            var requestSeq = 0;
            var lastRenderedSeq = 0;
            // Cache keyed by position id. Map<positionId, payload|'pending'>
            // We never expire entries on this side — page navigation
            // resets the cache anyway, and the dataset rarely
            // changes within the lifetime of one tab.
            var cache = Object.create(null);
            // Currently displayed position id, used to short-circuit
            // re-renders when the user moves between two halves of
            // the same node card.
            var currentPositionId = 0;
            var currentTrigger    = null;
            var backdropEl        = null;

            function isMobileLayout() {
                return window.innerWidth < 768;
            }

            function clearTimers() {
                if (openTimer)  { clearTimeout(openTimer);  openTimer  = null; }
                if (closeTimer) { clearTimeout(closeTimer); closeTimer = null; }
            }

            function showBackdrop(show) {
                if (!show) {
                    if (backdropEl) {
                        backdropEl.hidden = true;
                    }
                    return;
                }
                if (!backdropEl) {
                    backdropEl = document.createElement('div');
                    backdropEl.className = 'matrix-tree-hovercard-backdrop';
                    backdropEl.addEventListener('click', closeCard);
                    document.body.appendChild(backdropEl);
                }
                backdropEl.hidden = false;
            }

            function setTriggerExpanded(triggerEl, expanded) {
                if (!triggerEl) return;
                // The trigger is the .tree-node-info-trigger div on
                // the avatar. Reflecting open state on the node
                // makes screen readers announce the relationship.
                try {
                    triggerEl.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                } catch (_e) { /* Old IE — ignore. */ }
            }

            function positionCard(triggerEl) {
                // Mobile: centered modal-style placement. The CSS
                // already pins centered; we just toggle the class
                // so the !important rules apply, and bring up the
                // backdrop so taps outside the card dismiss.
                if (isMobileLayout()) {
                    card.classList.add('is-mobile');
                    card.classList.remove('is-below', 'is-above');
                    card.style.left = '';
                    card.style.top  = '';
                    showBackdrop(true);
                    return;
                }

                card.classList.remove('is-mobile');
                showBackdrop(false);

                var rect = triggerEl.getBoundingClientRect();

                // First show off-screen to measure intrinsic size.
                card.style.left = '-9999px';
                card.style.top  = '0px';
                card.classList.add('is-below'); // pre-bias for measurement
                var cw = card.offsetWidth || 320;
                var ch = card.offsetHeight || 220;

                var margin = 8;
                // Default placement: just below the trigger, left
                // edge aligned to the trigger's left.
                var top  = rect.bottom + margin;
                var left = rect.left;
                var placement = 'below';

                // Flip above if no room below.
                if (top + ch > window.innerHeight - margin) {
                    var flippedTop = rect.top - ch - margin;
                    if (flippedTop >= margin) {
                        top = flippedTop;
                        placement = 'above';
                    } else {
                        top = Math.max(margin, window.innerHeight - ch - margin);
                    }
                }

                // Constrain horizontally so the card stays in the
                // viewport with a small gutter.
                if (left + cw > window.innerWidth - margin) {
                    left = Math.max(margin, window.innerWidth - cw - margin);
                }
                if (left < margin) left = margin;

                card.classList.toggle('is-below', placement === 'below');
                card.classList.toggle('is-above', placement === 'above');
                card.style.left = left + 'px';
                card.style.top  = top  + 'px';
            }

            function applyPayload(p) {
                errorEl.hidden   = true;
                loadingEl.hidden = true;
                bodyEl.hidden    = false;

                // Header. Append a "(you)" tag when the hovered node
                // is the viewer themselves so the card can't be
                // misread as someone else's data.
                var displayName = p.full_name || p.username || ('User #' + p.user_id);
                if (p.is_self) displayName = displayName + ' ' + STR_YOU_TAG;
                nameEl.textContent     = displayName;
                usernameEl.textContent = p.username ? '@' + p.username : '';

                // Joined / sponsor / plans / commission.
                joinedEl.textContent  = p.joined  || STR_NO_DATE;
                sponsorEl.textContent = p.sponsor ? p.sponsor : STR_NO_SPONSOR;
                if (p.plans && p.plans.length) {
                    plansEl.textContent = p.plans.join(', ');
                } else {
                    plansEl.textContent = STR_NO_PLANS;
                }

                var amt = (p.commission && p.commission.amount_display) || '';
                var capped = !!(p.commission && p.commission.capped);
                commissionEl.classList.remove('is-zero', 'is-capped');
                if (capped) {
                    // Pre-pend the cap hint as an italic note so
                    // the displayed number reads as a floor, not a
                    // total.
                    commissionEl.innerHTML = '';
                    var amtSpan = document.createElement('span');
                    amtSpan.textContent = amt + ' ';
                    var hint = document.createElement('em');
                    hint.textContent = STR_BRANCH_CAPPED;
                    commissionEl.appendChild(amtSpan);
                    commissionEl.appendChild(hint);
                    commissionEl.classList.add('is-capped');
                } else {
                    commissionEl.textContent = amt;
                    if (p.commission && parseFloat(p.commission.amount) === 0) {
                        commissionEl.classList.add('is-zero');
                    }
                }

                // Admin profile link.
                if (p.profile_url) {
                    profileEl.setAttribute('href', p.profile_url);
                    profileEl.hidden = false;
                } else {
                    profileEl.hidden = true;
                    profileEl.removeAttribute('href');
                }
            }

            function applyError(message) {
                bodyEl.hidden    = true;
                loadingEl.hidden = true;
                errorEl.hidden   = false;
                errorEl.textContent = message || STR_LOADING_ERR;
            }

            function applyLoading() {
                bodyEl.hidden    = true;
                errorEl.hidden   = true;
                loadingEl.hidden = false;
            }

            function fetchAndShow(positionId, triggerEl) {
                currentPositionId = positionId;
                currentTrigger    = triggerEl;

                // Open the card now (so positioning can measure).
                card.hidden = false;
                setTriggerExpanded(triggerEl, true);

                if (cache[positionId] && cache[positionId] !== 'pending') {
                    applyPayload(cache[positionId]);
                    positionCard(triggerEl);
                    return;
                }

                applyLoading();
                positionCard(triggerEl);

                // Bail-out for in-flight duplicate requests against
                // the same id — could happen if the hover delay
                // races with a focus event.
                if (cache[positionId] === 'pending') return;
                cache[positionId] = 'pending';

                var seq = ++requestSeq;

                var data = new FormData();
                data.append('action',        'matrix_mlm_action');
                data.append('matrix_action', 'node_details');
                data.append('nonce',         matrixMLM.nonce);
                data.append('position_id',   String(positionId));

                fetch(matrixMLM.ajaxUrl, {
                    method:      'POST',
                    body:        data,
                    credentials: 'same-origin'
                })
                .then(function(r) { return r.json(); })
                .then(function(j) {
                    // If the user has hovered onto a different node
                    // since this request fired, drop the response
                    // so we don't paint stale data.
                    if (seq < lastRenderedSeq) return;
                    lastRenderedSeq = seq;

                    if (!j || !j.success) {
                        delete cache[positionId];
                        applyError((j && j.data && j.data.message) || STR_LOADING_ERR);
                        return;
                    }
                    var payload = j.data || {};
                    cache[positionId] = payload;
                    // Only render if the user is still hovering this
                    // same node. If they've moved elsewhere we keep
                    // the cached payload around for next time but
                    // don't override the current display.
                    if (currentPositionId === positionId) {
                        applyPayload(payload);
                        positionCard(triggerEl);
                    }
                })
                .catch(function() {
                    if (seq < lastRenderedSeq) return;
                    lastRenderedSeq = seq;
                    delete cache[positionId];
                    applyError(STR_NETWORK_ERR);
                });
            }

            function closeCard() {
                clearTimers();
                card.hidden = true;
                card.classList.remove('is-mobile', 'is-above', 'is-below');
                showBackdrop(false);
                if (currentTrigger) setTriggerExpanded(currentTrigger, false);
                currentPositionId = 0;
                currentTrigger    = null;
            }

            function openFor(triggerEl) {
                var nodeEl = triggerEl.closest('.matrix-tree-node');
                if (!nodeEl) return;
                // Empty slots have neither a position id worth
                // showing nor any commission to summarise — bail.
                if (nodeEl.classList.contains('matrix-tree-node-empty')) return;
                var posId = parseInt(nodeEl.getAttribute('data-position-id'), 10) || 0;
                if (posId <= 0) return;

                // If we're already showing this same position, just
                // reposition (in case of resize / scroll).
                if (currentPositionId === posId && !card.hidden) {
                    positionCard(triggerEl);
                    return;
                }

                fetchAndShow(posId, triggerEl);
            }

            function scheduleOpen(triggerEl) {
                clearTimers();
                openTimer = setTimeout(function() {
                    openTimer = null;
                    openFor(triggerEl);
                }, OPEN_DELAY);
            }

            function scheduleClose() {
                clearTimers();
                closeTimer = setTimeout(function() {
                    closeTimer = null;
                    closeCard();
                }, CLOSE_DELAY);
            }

            // Hover (desktop): bind to mouseenter/mouseleave on each
            // .matrix-tree-node via delegation. We use mouseover /
            // mouseout (which bubble) and check the related target
            // so moves *within* the same node card don't churn the
            // open/close state.
            tree.addEventListener('mouseover', function(e) {
                if (!supportsHover) return;
                var nodeEl = e.target.closest('.matrix-tree-node');
                if (!nodeEl || nodeEl.classList.contains('matrix-tree-node-empty')) return;
                // Treat the avatar as the trigger so the card
                // anchors below the avatar — the visual focal point
                // of the node card.
                var triggerEl = nodeEl.querySelector('.tree-node-info-trigger') || nodeEl;
                scheduleOpen(triggerEl);
            });

            tree.addEventListener('mouseout', function(e) {
                if (!supportsHover) return;
                var nodeEl = e.target.closest('.matrix-tree-node');
                if (!nodeEl) return;
                // Ignore moves that stay inside the same node card.
                if (nodeEl.contains(e.relatedTarget)) return;
                // Don't close if the mouse moved into the card
                // itself — let the user reach buttons / link.
                if (card.contains(e.relatedTarget)) return;
                scheduleClose();
            });

            // Keep the card alive while the user is mousing over
            // the card body itself.
            card.addEventListener('mouseenter', clearTimers);
            card.addEventListener('mouseleave', function(e) {
                if (!supportsHover) return;
                if (e.relatedTarget && e.relatedTarget.closest && e.relatedTarget.closest('.matrix-tree-node')) {
                    return;
                }
                scheduleClose();
            });

            // Touch / click path. Tapping the avatar trigger toggles
            // the card. We listen for click rather than touchstart
            // so the affordance also serves keyboard users
            // activating via Enter/Space.
            tree.addEventListener('click', function(e) {
                var triggerEl = e.target.closest('.tree-node-info-trigger');
                if (!triggerEl) return;
                e.preventDefault();
                e.stopPropagation();

                var nodeEl = triggerEl.closest('.matrix-tree-node');
                if (!nodeEl) return;
                var posId = parseInt(nodeEl.getAttribute('data-position-id'), 10) || 0;

                // Toggle: clicking the trigger of the currently-open
                // card closes it; clicking another opens that one.
                if (!card.hidden && currentPositionId === posId) {
                    closeCard();
                    return;
                }
                openFor(triggerEl);
            });

            // Keyboard activation on the avatar trigger (Space /
            // Enter). The role="button" already announces it as
            // activatable; this binds the handler.
            tree.addEventListener('keydown', function(e) {
                var triggerEl = e.target.closest('.tree-node-info-trigger');
                if (!triggerEl) return;
                if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
                    e.preventDefault();
                    openFor(triggerEl);
                }
            });

            // Close button on the card.
            closeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                closeCard();
            });

            // Esc closes from anywhere — most-expected keyboard
            // affordance for transient UI.
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && !card.hidden) {
                    closeCard();
                    if (currentTrigger && typeof currentTrigger.focus === 'function') {
                        currentTrigger.focus();
                    }
                }
            });

            // Click anywhere outside both the card and any node
            // trigger closes the card. The mobile backdrop already
            // handles this in modal mode; this is the desktop
            // fallback for mouse users who clicked into the page.
            document.addEventListener('click', function(e) {
                if (card.hidden) return;
                if (card.contains(e.target)) return;
                if (e.target.closest && e.target.closest('.tree-node-info-trigger')) return;
                closeCard();
            });

            // Keep position fresh when the page is scrolled or
            // resized while the card is open. Skipped on mobile
            // modal mode (CSS pins it centered).
            var rafId = 0;
            function scheduleReposition() {
                if (rafId) return;
                rafId = window.requestAnimationFrame(function() {
                    rafId = 0;
                    if (card.hidden || !currentTrigger || isMobileLayout()) return;
                    positionCard(currentTrigger);
                });
            }
            window.addEventListener('scroll',  scheduleReposition, true);
            window.addEventListener('resize',  scheduleReposition);
        })();
        </script>
        <?php
    }

    /**
     * Render the "Next goal" banner above the level-badge grid.
     *
     * Single most-prominent affordance on the genealogy view after
     * the breadcrumb / search bar — it tells a member exactly what
     * to do next and what they'll earn for it. Only emitted when the
     * plan engine returns a non-null next-goal (i.e., at least one
     * incomplete level still has slots to fill); when the matrix is
     * fully complete, the existing Matrix Master banner inside
     * render_level_badges() supplies the congratulatory state and
     * this banner stays out of the way.
     *
     * The "Copy invite link" CTA is rendered only when the viewer
     * has a usable referral URL. Empty referral URL means the
     * matrix_user_meta row is missing (legacy import / partial
     * registration) — in that case the banner still surfaces the
     * goal and earnings number, just without a one-click copy
     * affordance, which is better than rendering a button that
     * does nothing.
     *
     * Copy framing rules (so the banner reads naturally for every
     * combination of slot count and commission state):
     *   - 1 vs N+ slots remaining: pluralise "position(s)".
     *   - Plan defines a level commission (commission_per_slot > 0):
     *     append "and earn ₦X.XX". The figure is `slots_remaining ×
     *     commission_per_slot` — a guaranteed minimum, since level
     *     commissions are paid on every fill regardless of whether
     *     the fill is a direct referral or spillover from upline.
     *   - Plan has no commission for that level: drop the earnings
     *     hint entirely and just frame the goal as "complete this
     *     level". Honest framing trumps clickbait — quoting ₦0.00
     *     would just confuse.
     *
     * @param array|null $goal         Output of
     *                                 Matrix_MLM_Plan_Engine::get_next_level_goal().
     * @param string     $referral_url Viewer's invite URL, or '' when
     *                                 unavailable.
     */
    private function render_next_goal_banner($goal, $referral_url) {
        if (!$goal) {
            return;
        }

        $level   = (int)   $goal['level'];
        $slots   = (int)   $goal['slots_remaining'];
        $earning = (float) $goal['total_earnable'];
        if ($slots <= 0 || $level <= 0) {
            return;
        }

        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $earning_label = $currency . number_format($earning, 2);
        $has_earning   = ($earning > 0);

        // Build the localised sentence with bold accents on the
        // numbers a glanceable read should land on. Each branch
        // formats its own translator-friendly string so plural
        // forms and earnings-on/off flow through esc_html__ /
        // _n correctly.
        $level_label = sprintf(
            /* translators: %d: level number */
            esc_html__('Level %d', 'matrix-mlm'),
            $level
        );
        $bold_level = '<strong>' . $level_label . '</strong>';
        $bold_count = '<strong>' . esc_html(
            sprintf(
                /* translators: %s: localised slot count (number_format_i18n) */
                _n(
                    '%s more position',
                    '%s more positions',
                    $slots,
                    'matrix-mlm'
                ),
                number_format_i18n($slots)
            )
        ) . '</strong>';
        $bold_earning = '<strong>' . esc_html($earning_label) . '</strong>';

        if ($has_earning) {
            // Two-line copy: headline calls out the action; the
            // inline earning is the carrot.
            $copy = sprintf(
                /* translators: 1: bold "<N> more positions", 2: bold "Level X", 3: bold currency amount */
                esc_html__('Fill %1$s on %2$s to complete this level and earn %3$s.', 'matrix-mlm'),
                $bold_count,
                $bold_level,
                $bold_earning
            );
        } else {
            $copy = sprintf(
                /* translators: 1: bold "<N> more positions", 2: bold "Level X" */
                esc_html__('Fill %1$s on %2$s to complete this level.', 'matrix-mlm'),
                $bold_count,
                $bold_level
            );
        }
        ?>
        <div class="matrix-next-goal" role="region" aria-label="<?php esc_attr_e('Your next genealogy goal', 'matrix-mlm'); ?>">
            <div class="matrix-next-goal-icon" aria-hidden="true">
                <span class="dashicons dashicons-flag"></span>
            </div>
            <div class="matrix-next-goal-body">
                <strong class="matrix-next-goal-title"><?php esc_html_e('Next goal', 'matrix-mlm'); ?></strong>
                <p class="matrix-next-goal-copy">
                    <?php
                    // The %s tokens above are HTML wrappers we built
                    // ourselves (esc_html__ / esc_html on each user-
                    // facing fragment). Echo unescaped here so the
                    // bolds render — the actual content is already
                    // sanitised piecewise above.
                    echo $copy; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    ?>
                </p>
                <?php if (!empty($goal['is_in_progress'])): ?>
                <p class="matrix-next-goal-hint">
                    <?php
                    printf(
                        /* translators: 1: filled count, 2: expected count */
                        esc_html__('You have %1$s of %2$s slots filled — almost there.', 'matrix-mlm'),
                        '<strong>' . esc_html(number_format_i18n((int) $goal['filled'])) . '</strong>',
                        '<strong>' . esc_html(number_format_i18n((int) $goal['expected'])) . '</strong>'
                    );
                    ?>
                </p>
                <?php endif; ?>
            </div>
            <?php if ($referral_url !== ''): ?>
            <button type="button"
                    class="matrix-next-goal-cta"
                    data-action="copy-referral"
                    data-referral-url="<?php echo esc_attr($referral_url); ?>">
                <span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
                <span class="matrix-next-goal-cta-default"><?php esc_html_e('Copy invite link', 'matrix-mlm'); ?></span>
                <span class="matrix-next-goal-cta-success" hidden><?php esc_html_e('Copied!', 'matrix-mlm'); ?></span>
            </button>
            <?php endif; ?>
        </div>

        <style>
        .matrix-next-goal {
            display: flex;
            align-items: center;
            gap: 14px;
            background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%);
            border: 1px solid #8b5cf6;
            border-radius: 12px;
            padding: 14px 18px;
            margin: 0 0 18px;
            color: #4c1d95;
        }
        .matrix-next-goal-icon {
            flex: 0 0 44px;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: #8b5cf6;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px -2px rgba(139, 92, 246, 0.45);
        }
        .matrix-next-goal-icon .dashicons { font-size: 22px; width: 22px; height: 22px; }
        .matrix-next-goal-body {
            flex: 1 1 auto;
            min-width: 0;
        }
        .matrix-next-goal-title {
            display: block;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #6d28d9;
            margin-bottom: 2px;
        }
        .matrix-next-goal-copy {
            margin: 0;
            font-size: 14px;
            line-height: 1.45;
            color: #4c1d95;
        }
        .matrix-next-goal-copy strong { color: #4c1d95; font-weight: 700; }
        .matrix-next-goal-hint {
            margin: 4px 0 0;
            font-size: 12px;
            color: #6d28d9;
        }
        .matrix-next-goal-cta {
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #8b5cf6;
            color: #fff;
            border: 0;
            padding: 9px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color .15s ease, transform .15s ease;
        }
        .matrix-next-goal-cta:hover { background: #7c3aed; transform: translateY(-1px); }
        .matrix-next-goal-cta:focus-visible {
            outline: none;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.3);
        }
        .matrix-next-goal-cta .dashicons { font-size: 16px; width: 16px; height: 16px; }
        .matrix-next-goal-cta.is-success { background: #10b981; }
        .matrix-next-goal-cta.is-success:hover { background: #059669; }

        /* Empty-slot CTA: the avatar circle inherits its existing
           dashed-pill look from matrix-dashboard.css; we layer a
           cursor + hover tint so it reads as actionable, plus a
           goal-level pulse synced with the level-badge highlight. */
        .matrix-tree-node.matrix-tree-node-empty-cta {
            cursor: pointer;
            position: relative;
            transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease, background-color .15s ease;
        }
        .matrix-tree-node.matrix-tree-node-empty-cta:hover,
        .matrix-tree-node.matrix-tree-node-empty-cta:focus-visible {
            transform: translateY(-1px);
            border-color: #8b5cf6;
            background: #f5f3ff;
            box-shadow: 0 6px 16px -6px rgba(139, 92, 246, 0.35);
            outline: none;
        }
        .matrix-tree-node.matrix-tree-node-empty-cta .tree-node-info small {
            color: #6d28d9;
            font-weight: 600;
        }
        .matrix-tree-node.matrix-tree-node-empty-cta.is-goal-level {
            border-color: #8b5cf6;
            background: #ede9fe;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.18);
            animation: matrix-empty-cta-pulse 2.4s ease-in-out infinite;
        }
        .matrix-tree-node.matrix-tree-node-empty-cta.is-goal-level .tree-node-empty-avatar {
            background: #8b5cf6;
            color: #fff;
        }
        .matrix-tree-node.matrix-tree-node-empty-cta.is-goal-level .tree-node-empty-avatar .dashicons {
            color: #fff;
        }
        @keyframes matrix-empty-cta-pulse {
            0%, 100% { box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.18); }
            50%      { box-shadow: 0 0 0 7px rgba(139, 92, 246, 0.10); }
        }
        @media (prefers-reduced-motion: reduce) {
            .matrix-tree-node.matrix-tree-node-empty-cta.is-goal-level { animation: none; }
        }

        /* Inline "Copied!" toast that floats up from the slot card
           on a successful clipboard write. Lives inside the slot
           markup so positioning stays anchored to the trigger
           regardless of scroll. */
        .matrix-tree-empty-cta-toast {
            position: absolute;
            bottom: 4px;
            right: 8px;
            font-size: 11px;
            font-weight: 700;
            color: #047857;
            background: rgba(255, 255, 255, 0.95);
            padding: 2px 6px;
            border-radius: 4px;
            box-shadow: 0 2px 6px -1px rgba(0, 0, 0, 0.12);
            pointer-events: none;
        }
        .matrix-tree-empty-cta-toast[hidden] { display: none; }

        /* Mobile: indented vertical layout makes the next-goal
           banner stack on the right side awkwardly. Wrap the CTA
           below the body so the banner stays readable and the
           button sits on its own line. */
        @media (max-width: 767px) {
            .matrix-next-goal {
                flex-wrap: wrap;
            }
            .matrix-next-goal-cta {
                width: 100%;
                justify-content: center;
            }
        }
        </style>
        <?php
    }

    /**
     * Inline JS that handles the "Copy invite link" affordance —
     * shared by the next-goal banner button and every empty-slot
     * CTA in the tree. Single delegated listener on the body so
     * both the page-rendered slots and the AJAX-injected lazy-load
     * slots get the behaviour without rebinding.
     *
     * Behaviour:
     *   - Click / Enter / Space on any [data-action="copy-referral"]
     *     element triggers a clipboard write of its data-referral-url
     *     attribute. We use navigator.clipboard.writeText when
     *     available and fall back to the textarea+execCommand trick
     *     for older browsers (Safari < 13.1, IE) so the affordance
     *     still works on the long tail.
     *   - On success the trigger flashes a "Copied!" indicator for
     *     1.6 seconds. The next-goal CTA swaps its label inline; an
     *     empty-slot CTA shows the inline toast pinned inside its
     *     card. Both auto-revert so the UI doesn't get stuck on the
     *     success state.
     *   - On failure (clipboard API blocked, no permissions) we fall
     *     back to opening the URL in a new tab so the user has a
     *     manual path to grab the link from the address bar.
     *   - Empty-slot CTAs ALSO need to play nicely with the
     *     hover-card from PR #170: the hover-card click handler
     *     skips empty slots already (it bails on
     *     matrix-tree-node-empty), so the two affordances can
     *     coexist on the same DOM tree without fighting over events.
     *
     * Why inline rather than enqueued: same single-tab co-location
     * pattern the rest of the genealogy view's JS uses
     * (render_lazy_load_script, render_search_script,
     * render_hovercard_script). Keeps one tab's behaviour beside
     * its markup; matrix-public.js stays a place for cross-page
     * helpers.
     */
    private function render_referral_copy_script() {
        // No referral URL means there's nothing to copy and no
        // CTAs were rendered — skip emitting the script entirely.
        $referral_url = isset($this->pivot_state['referral_url'])
            ? (string) $this->pivot_state['referral_url']
            : '';
        if ($referral_url === '') {
            return;
        }
        ?>
        <script>
        (function() {
            var STR_COPIED = '<?php echo esc_js(__('Copied!', 'matrix-mlm')); ?>';
            var STR_COPY   = '<?php echo esc_js(__('Copy invite link', 'matrix-mlm')); ?>';
            var TOAST_MS   = 1600;

            // Native clipboard with execCommand fallback. Returns a
            // promise so callers can chain success/error UI without
            // caring which path was used.
            function writeToClipboard(text) {
                if (!text) return Promise.reject(new Error('empty'));
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    return navigator.clipboard.writeText(text);
                }
                // Fallback path: temporary off-screen textarea +
                // execCommand. Required for Safari < 13.1 / IE and
                // also for non-secure-context loads.
                return new Promise(function(resolve, reject) {
                    try {
                        var ta = document.createElement('textarea');
                        ta.value = text;
                        ta.style.position = 'fixed';
                        ta.style.top = '-9999px';
                        ta.style.opacity = '0';
                        document.body.appendChild(ta);
                        ta.focus();
                        ta.select();
                        var ok = document.execCommand('copy');
                        document.body.removeChild(ta);
                        if (ok) resolve(); else reject(new Error('execCommand failed'));
                    } catch (err) {
                        reject(err);
                    }
                });
            }

            function flashSuccess(triggerEl) {
                // Two flavours of trigger flash, both auto-revert:
                //
                //   - .matrix-next-goal-cta carries two child spans;
                //     we swap visibility so the button label changes
                //     to "Copied!" inline.
                //   - .matrix-tree-node-empty-cta has a pinned
                //     <span class="matrix-tree-empty-cta-toast">
                //     inside the card; we just unhide it.
                triggerEl.classList.add('is-success');

                var bannerDefault = triggerEl.querySelector('.matrix-next-goal-cta-default');
                var bannerSuccess = triggerEl.querySelector('.matrix-next-goal-cta-success');
                if (bannerDefault && bannerSuccess) {
                    bannerDefault.hidden = true;
                    bannerSuccess.hidden = false;
                }

                var toast = triggerEl.querySelector('.matrix-tree-empty-cta-toast');
                if (toast) toast.hidden = false;

                setTimeout(function() {
                    triggerEl.classList.remove('is-success');
                    if (bannerDefault && bannerSuccess) {
                        bannerDefault.hidden = false;
                        bannerSuccess.hidden = true;
                    }
                    if (toast) toast.hidden = true;
                }, TOAST_MS);
            }

            function handle(triggerEl) {
                var url = triggerEl.getAttribute('data-referral-url') || '';
                if (!url) return;
                writeToClipboard(url)
                    .then(function() { flashSuccess(triggerEl); })
                    .catch(function() {
                        // Last-resort fallback: open in a new tab so
                        // the user can copy from the address bar.
                        // Better than a silent no-op when the
                        // clipboard API is locked down.
                        try { window.open(url, '_blank', 'noopener'); }
                        catch (_e) { /* ignore */ }
                    });
            }

            // Single body-level delegated listener — catches both
            // the always-present next-goal banner CTA and every
            // empty-slot CTA, including the ones AJAX-injected by
            // the lazy-load endpoint into tree branches that
            // weren't on the page at first render.
            document.addEventListener('click', function(e) {
                var triggerEl = e.target.closest && e.target.closest('[data-action="copy-referral"]');
                if (!triggerEl) return;
                e.preventDefault();
                e.stopPropagation();
                handle(triggerEl);
            });
            document.addEventListener('keydown', function(e) {
                if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
                var triggerEl = e.target.closest && e.target.closest('[data-action="copy-referral"]');
                if (!triggerEl) return;
                // Empty-slot CTAs are role="button" tabindex="0" divs
                // — Space on a div would otherwise scroll the page,
                // so swallow the default. The next-goal CTA is a
                // real <button>, where Space would activate anyway,
                // but preventDefault is harmless there.
                e.preventDefault();
                e.stopPropagation();
                handle(triggerEl);
            });
        })();
        </script>
        <?php
    }

    /**
     * Render the "Share & Export" panel above the tree.
     *
     * Two columns of affordances stacked under a single card:
     *
     *   - LEFT: Export controls. PDF opens the same view rendered
     *     with ?print=1 (which short-circuits to a stripped-down
     *     tree-only layout below) and triggers window.print() on
     *     load — same pattern the admin reports/import pages use,
     *     so members get a familiar "your browser's print dialog
     *     is your save-as-PDF dialog" experience. PNG uses
     *     html2canvas, lazy-loaded from a CDN on first click; if
     *     the CDN fails the JS surfaces a graceful "use PDF
     *     instead" message rather than failing silently.
     *   - RIGHT: Share-link controls. A "Create share link" button
     *     mints a token via AJAX and renders it into the list
     *     below; existing tokens (active and revoked) are
     *     rendered server-side from list_tokens_for_user() so
     *     the panel is interactive on first paint, no spinner.
     *
     * Hides on print so a member exporting their tree doesn't
     * get the panel's chrome printed alongside it.
     *
     * @param int $current_plan_id  The plan currently being viewed —
     *                               pre-selects on the share form so a
     *                               member who's already on Plan 3
     *                               doesn't have to pick it again.
     * @param int $pivot_user_id    Non-zero when the operator is
     *                               viewing someone else's branch via
     *                               ?pivot_user_id=X. We pass it
     *                               through to the share form so the
     *                               minted token captures the same
     *                               pivot.
     */
    private function render_share_export_panel($current_plan_id, $pivot_user_id) {
        // Pull existing tokens server-side. Cheap query (indexed on
        // user_id) and avoids a spinner-on-first-paint UX.
        $tokens = class_exists('Matrix_MLM_Share')
            ? Matrix_MLM_Share::list_tokens_for_user((int) $this->pivot_state['viewer_user_id'])
            : [];
        $current_plan_id = (int) $current_plan_id;
        $pivot_user_id   = (int) $pivot_user_id;
        ?>
        <div class="matrix-share-export-panel" id="matrix-share-export-panel">
            <div class="msep-row">
                <div class="msep-col msep-col-export">
                    <h3><?php esc_html_e('Export', 'matrix-mlm'); ?></h3>
                    <p class="msep-help">
                        <?php esc_html_e('Save the visible tree as a PDF or PNG to share with a prospect or upload to a presentation.', 'matrix-mlm'); ?>
                    </p>
                    <div class="msep-actions">
                        <a class="button" id="msep-export-pdf"
                           href="<?php echo esc_url(add_query_arg('print', '1')); ?>"
                           target="_blank"
                           rel="noopener">
                            <?php esc_html_e('Export PDF', 'matrix-mlm'); ?>
                        </a>
                        <button type="button" class="button" id="msep-export-png">
                            <?php esc_html_e('Export PNG', 'matrix-mlm'); ?>
                        </button>
                    </div>
                </div>

                <div class="msep-col msep-col-share">
                    <h3><?php esc_html_e('Share read-only link', 'matrix-mlm'); ?></h3>
                    <p class="msep-help">
                        <?php esc_html_e('Create a public link a prospect can open without logging in. Revoke any time.', 'matrix-mlm'); ?>
                    </p>

                    <form class="msep-form" id="msep-share-form" onsubmit="return false;">
                        <input type="hidden" id="msep-plan-id" value="<?php echo $current_plan_id; ?>">
                        <input type="hidden" id="msep-pivot-user-id" value="<?php echo $pivot_user_id; ?>">

                        <label class="msep-field">
                            <span><?php esc_html_e('Label', 'matrix-mlm'); ?></span>
                            <input type="text" id="msep-label" maxlength="120"
                                   placeholder="<?php esc_attr_e('e.g. For Sarah\'s prospect demo', 'matrix-mlm'); ?>">
                        </label>
                        <label class="msep-field">
                            <span><?php esc_html_e('Expires', 'matrix-mlm'); ?></span>
                            <select id="msep-expiry">
                                <option value="0"><?php esc_html_e('Never', 'matrix-mlm'); ?></option>
                                <option value="1"><?php esc_html_e('1 day', 'matrix-mlm'); ?></option>
                                <option value="7" selected><?php esc_html_e('7 days', 'matrix-mlm'); ?></option>
                                <option value="30"><?php esc_html_e('30 days', 'matrix-mlm'); ?></option>
                                <option value="90"><?php esc_html_e('90 days', 'matrix-mlm'); ?></option>
                                <option value="365"><?php esc_html_e('1 year', 'matrix-mlm'); ?></option>
                            </select>
                        </label>
                        <button type="submit" class="button button-primary" id="msep-create-btn">
                            <?php esc_html_e('Create link', 'matrix-mlm'); ?>
                        </button>
                    </form>

                    <div class="msep-tokens" id="msep-tokens">
                        <?php if (!empty($tokens)): ?>
                            <ul class="msep-token-list">
                                <?php foreach ($tokens as $tk): ?>
                                    <?php $this->render_share_token_row($tk); ?>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="msep-empty"><?php esc_html_e('No share links yet.', 'matrix-mlm'); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div id="msep-toast" class="msep-toast" role="status" aria-live="polite"></div>
        </div>

        <style>
        .matrix-share-export-panel {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px 20px;
            margin: 0 0 18px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            position: relative;
        }
        .matrix-share-export-panel h3 {
            margin: 0 0 6px;
            font-size: 14px;
            color: #111827;
        }
        .matrix-share-export-panel .msep-help {
            margin: 0 0 10px;
            font-size: 12px;
            color: #6b7280;
            line-height: 1.45;
        }
        .matrix-share-export-panel .msep-row {
            display: grid;
            grid-template-columns: 1fr 1.4fr;
            gap: 28px;
        }
        @media (max-width: 768px) {
            .matrix-share-export-panel .msep-row { grid-template-columns: 1fr; }
        }
        .matrix-share-export-panel .msep-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .matrix-share-export-panel .msep-form {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: flex-end;
            margin-bottom: 12px;
        }
        .matrix-share-export-panel .msep-field {
            display: flex;
            flex-direction: column;
            gap: 3px;
            font-size: 11px;
            color: #4b5563;
            flex: 1 1 140px;
        }
        .matrix-share-export-panel .msep-field input[type="text"],
        .matrix-share-export-panel .msep-field select {
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 5px 8px;
            font-size: 13px;
            min-width: 0;
        }
        .matrix-share-export-panel .msep-tokens {
            border-top: 1px dashed #e5e7eb;
            padding-top: 10px;
        }
        .matrix-share-export-panel .msep-empty {
            margin: 0;
            font-size: 12px;
            color: #9ca3af;
            font-style: italic;
        }
        .matrix-share-export-panel .msep-token-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .matrix-share-export-panel .msep-token {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 12px;
            flex-wrap: wrap;
        }
        .matrix-share-export-panel .msep-token.is-revoked,
        .matrix-share-export-panel .msep-token.is-expired {
            opacity: 0.55;
        }
        .matrix-share-export-panel .msep-token-label {
            font-weight: 600;
            color: #111827;
            flex: 1 1 140px;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .matrix-share-export-panel .msep-token-meta {
            color: #6b7280;
            font-size: 11px;
        }
        .matrix-share-export-panel .msep-token-status {
            display: inline-block;
            padding: 1px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .matrix-share-export-panel .msep-status-active   { background: #d1fae5; color: #065f46; }
        .matrix-share-export-panel .msep-status-revoked  { background: #fee2e2; color: #991b1b; }
        .matrix-share-export-panel .msep-status-expired  { background: #f3f4f6; color: #4b5563; }
        .matrix-share-export-panel .msep-token-url {
            flex-basis: 100%;
            margin-top: 2px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 11px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            padding: 4px 8px;
            color: #1f2937;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .matrix-share-export-panel .msep-toast {
            position: absolute;
            top: 8px;
            right: 16px;
            background: #111827;
            color: #fff;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            opacity: 0;
            transform: translateY(-4px);
            transition: opacity 0.15s, transform 0.15s;
            pointer-events: none;
        }
        .matrix-share-export-panel .msep-toast.is-shown {
            opacity: 1;
            transform: translateY(0);
        }
        .matrix-share-export-panel .msep-toast.is-error { background: #b91c1c; }

        @media print {
            .matrix-share-export-panel { display: none !important; }
        }
        </style>

        <?php $this->render_share_export_script(); ?>
        <?php
    }

    /**
     * Render a single token row inside the panel's list. Pulled
     * out so the AJAX "create" path can synthesise the same
     * markup client-side without the server having to round-trip
     * the full HTML — see the `tokenRowHtml()` helper in the
     * inline script.
     */
    private function render_share_token_row($tk) {
        $status_label = ucfirst($tk->status);
        $expiry_text = !empty($tk->expires_at)
            ? esc_html(sprintf(
                /* translators: %s: localized date/time */
                __('expires %s', 'matrix-mlm'),
                date_i18n('M j, Y', strtotime($tk->expires_at))
            ))
            : esc_html__('no expiry', 'matrix-mlm');

        $views_text = sprintf(
            /* translators: %d: number of times the link has been viewed */
            _n('%d view', '%d views', (int) $tk->view_count, 'matrix-mlm'),
            (int) $tk->view_count
        );
        ?>
        <li class="msep-token is-<?php echo esc_attr($tk->status); ?>" data-token-id="<?php echo (int) $tk->id; ?>">
            <span class="msep-token-status msep-status-<?php echo esc_attr($tk->status); ?>"><?php echo esc_html($status_label); ?></span>
            <span class="msep-token-label">
                <?php echo esc_html(!empty($tk->label) ? $tk->label : __('(unlabeled)', 'matrix-mlm')); ?>
            </span>
            <span class="msep-token-meta">
                <?php echo $expiry_text; ?> · <?php echo esc_html($views_text); ?>
            </span>
            <?php if ($tk->status === 'active'): ?>
                <button type="button" class="button button-small msep-copy-btn" data-url="<?php echo esc_attr($tk->url); ?>">
                    <?php esc_html_e('Copy link', 'matrix-mlm'); ?>
                </button>
                <button type="button" class="button button-small msep-revoke-btn">
                    <?php esc_html_e('Revoke', 'matrix-mlm'); ?>
                </button>
            <?php endif; ?>
            <span class="msep-token-url"><?php echo esc_html($tk->url); ?></span>
        </li>
        <?php
    }

    /**
     * Inline JS for the share/export panel.
     *
     * Three responsibilities, all in one closure to keep the
     * surface area small:
     *
     *   - Mint a token via the matrix_create_share_token AJAX
     *     endpoint and prepend the resulting row to the list.
     *   - Revoke an existing token via
     *     matrix_revoke_share_token, with optimistic styling
     *     on the row + a rollback on error.
     *   - PNG export. Lazy-load html2canvas from a CDN on first
     *     click so the dashboard's initial page weight is
     *     unaffected by a feature most members will never use.
     *     Falls back to a "use PDF instead" toast if the CDN
     *     fetch fails (ad-blocker, offline, etc.).
     *
     * jQuery is used only for the AJAX call (matches the rest of
     * the dashboard's AJAX pattern); the dnd handlers and
     * clipboard interactions are vanilla JS so the panel works
     * even on minimal jQuery deferments.
     */
    private function render_share_export_script() {
        ?>
        <script>
        (function() {
            var ajaxUrl = (window.matrixMLM && window.matrixMLM.ajaxUrl) || '';
            var nonce   = (window.matrixMLM && window.matrixMLM.nonce)   || '';

            var panel = document.getElementById('matrix-share-export-panel');
            if (!panel) return;
            var form     = panel.querySelector('#msep-share-form');
            var listEl   = panel.querySelector('#msep-tokens');
            var emptyMsg = listEl ? listEl.querySelector('.msep-empty') : null;
            var toast    = panel.querySelector('#msep-toast');
            var btnPng   = panel.querySelector('#msep-export-png');

            function showToast(msg, isError) {
                if (!toast) return;
                toast.textContent = msg;
                toast.classList.toggle('is-error', !!isError);
                toast.classList.add('is-shown');
                setTimeout(function() { toast.classList.remove('is-shown'); }, 2400);
            }

            // ---------- Token row factory ----------
            // Mirrors the server-side render_share_token_row()
            // markup so freshly-minted tokens look identical to
            // server-rendered ones without a page reload.
            function tokenRowHtml(t) {
                var labelText = t.label ? t.label : '<?php echo esc_js(__('(unlabeled)', 'matrix-mlm')); ?>';
                var expiryText = t.expires_at
                    ? ('<?php echo esc_js(__('expires', 'matrix-mlm')); ?> ' + new Date(t.expires_at.replace(' ', 'T')).toLocaleDateString())
                    : '<?php echo esc_js(__('no expiry', 'matrix-mlm')); ?>';
                var li = document.createElement('li');
                li.className = 'msep-token is-active';
                li.setAttribute('data-token-id', t.id);
                li.innerHTML =
                    '<span class="msep-token-status msep-status-active">Active</span>' +
                    '<span class="msep-token-label"></span>' +
                    '<span class="msep-token-meta"></span>' +
                    '<button type="button" class="button button-small msep-copy-btn"><?php echo esc_js(__('Copy link', 'matrix-mlm')); ?></button>' +
                    '<button type="button" class="button button-small msep-revoke-btn"><?php echo esc_js(__('Revoke', 'matrix-mlm')); ?></button>' +
                    '<span class="msep-token-url"></span>';
                li.querySelector('.msep-token-label').textContent = labelText;
                li.querySelector('.msep-token-meta').textContent  = expiryText + ' · 0 <?php echo esc_js(__('views', 'matrix-mlm')); ?>';
                li.querySelector('.msep-copy-btn').setAttribute('data-url', t.url);
                li.querySelector('.msep-token-url').textContent = t.url;
                return li;
            }

            // ---------- Create token ----------
            if (form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    var btn = panel.querySelector('#msep-create-btn');
                    btn.disabled = true;
                    btn.textContent = '<?php echo esc_js(__('Creating…', 'matrix-mlm')); ?>';

                    jQuery.post(ajaxUrl, {
                        action: 'matrix_create_share_token',
                        nonce: nonce,
                        plan_id:       panel.querySelector('#msep-plan-id').value,
                        pivot_user_id: panel.querySelector('#msep-pivot-user-id').value,
                        expiry_days:   panel.querySelector('#msep-expiry').value,
                        label:         panel.querySelector('#msep-label').value
                    }, function(res) {
                        btn.disabled = false;
                        btn.textContent = '<?php echo esc_js(__('Create link', 'matrix-mlm')); ?>';
                        if (!res || !res.success) {
                            showToast((res && res.data && res.data.message) || '<?php echo esc_js(__('Could not create link.', 'matrix-mlm')); ?>', true);
                            return;
                        }

                        // Insert the new row at the top of the list,
                        // converting the empty-state placeholder to
                        // a real list if this is the first token.
                        var ul = listEl.querySelector('.msep-token-list');
                        if (!ul) {
                            if (emptyMsg) emptyMsg.remove();
                            ul = document.createElement('ul');
                            ul.className = 'msep-token-list';
                            listEl.appendChild(ul);
                        }
                        ul.insertBefore(tokenRowHtml(res.data), ul.firstChild);
                        panel.querySelector('#msep-label').value = '';
                        showToast('<?php echo esc_js(__('Share link created.', 'matrix-mlm')); ?>');
                    }).fail(function() {
                        btn.disabled = false;
                        btn.textContent = '<?php echo esc_js(__('Create link', 'matrix-mlm')); ?>';
                        showToast('<?php echo esc_js(__('Network error.', 'matrix-mlm')); ?>', true);
                    });
                });
            }

            // ---------- Copy / Revoke (delegated) ----------
            if (listEl) {
                listEl.addEventListener('click', function(e) {
                    var copyBtn   = e.target.closest('.msep-copy-btn');
                    var revokeBtn = e.target.closest('.msep-revoke-btn');

                    if (copyBtn) {
                        var url = copyBtn.getAttribute('data-url') || '';
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(url).then(function() {
                                showToast('<?php echo esc_js(__('Link copied to clipboard.', 'matrix-mlm')); ?>');
                            }, function() {
                                showToast('<?php echo esc_js(__('Could not copy. Long-press the URL to copy manually.', 'matrix-mlm')); ?>', true);
                            });
                        } else {
                            // Fallback for older browsers — surface
                            // the URL in a prompt for manual copy.
                            window.prompt('<?php echo esc_js(__('Copy this link:', 'matrix-mlm')); ?>', url);
                        }
                        return;
                    }

                    if (revokeBtn) {
                        var li = revokeBtn.closest('.msep-token');
                        if (!li) return;
                        if (!window.confirm('<?php echo esc_js(__('Revoke this share link? Anyone holding it will lose access immediately.', 'matrix-mlm')); ?>')) {
                            return;
                        }
                        var tokenId = parseInt(li.getAttribute('data-token-id'), 10) || 0;
                        revokeBtn.disabled = true;
                        jQuery.post(ajaxUrl, {
                            action: 'matrix_revoke_share_token',
                            nonce: nonce,
                            token_id: tokenId
                        }, function(res) {
                            if (res && res.success) {
                                // Soft-revoke styling — keep the row
                                // visible (matches the server-side
                                // history list) but greyed out.
                                li.classList.remove('is-active');
                                li.classList.add('is-revoked');
                                var status = li.querySelector('.msep-token-status');
                                if (status) {
                                    status.className = 'msep-token-status msep-status-revoked';
                                    status.textContent = 'Revoked';
                                }
                                var copyB = li.querySelector('.msep-copy-btn');
                                if (copyB) copyB.remove();
                                revokeBtn.remove();
                                showToast('<?php echo esc_js(__('Share link revoked.', 'matrix-mlm')); ?>');
                            } else {
                                revokeBtn.disabled = false;
                                showToast((res && res.data && res.data.message) || '<?php echo esc_js(__('Could not revoke link.', 'matrix-mlm')); ?>', true);
                            }
                        }).fail(function() {
                            revokeBtn.disabled = false;
                            showToast('<?php echo esc_js(__('Network error.', 'matrix-mlm')); ?>', true);
                        });
                    }
                });
            }

            // ---------- PNG export (lazy html2canvas) ----------
            // We don't bundle html2canvas in the plugin because it
            // adds ~200KB to the initial dashboard payload for a
            // feature most members will use rarely. Lazy-loading
            // from a well-known CDN at click time keeps the
            // baseline page weight unchanged.
            //
            // Failure modes covered: CDN blocked (ad-blocker / no
            // network) and html2canvas itself throwing during
            // capture (font/CORS issues). Both surface a "use PDF
            // instead" toast rather than an opaque console error.
            var html2canvasPromise = null;
            function loadHtml2Canvas() {
                if (html2canvasPromise) return html2canvasPromise;
                html2canvasPromise = new Promise(function(resolve, reject) {
                    if (window.html2canvas) { resolve(window.html2canvas); return; }
                    var s = document.createElement('script');
                    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
                    s.async = true;
                    s.onload = function() {
                        if (window.html2canvas) resolve(window.html2canvas);
                        else reject(new Error('html2canvas missing after load'));
                    };
                    s.onerror = function() { reject(new Error('Failed to load html2canvas')); };
                    document.head.appendChild(s);
                });
                return html2canvasPromise;
            }

            if (btnPng) {
                btnPng.addEventListener('click', function() {
                    var tree = document.querySelector('.matrix-genealogy-wrapper');
                    if (!tree) {
                        showToast('<?php echo esc_js(__('No tree to export.', 'matrix-mlm')); ?>', true);
                        return;
                    }
                    var origLabel = btnPng.textContent;
                    btnPng.disabled = true;
                    btnPng.textContent = '<?php echo esc_js(__('Capturing…', 'matrix-mlm')); ?>';

                    loadHtml2Canvas().then(function(h2c) {
                        return h2c(tree, { backgroundColor: '#ffffff', useCORS: true, scale: window.devicePixelRatio > 1 ? 2 : 1 });
                    }).then(function(canvas) {
                        var url = canvas.toDataURL('image/png');
                        var a = document.createElement('a');
                        a.href = url;
                        a.download = 'genealogy-' + new Date().toISOString().slice(0, 10) + '.png';
                        document.body.appendChild(a);
                        a.click();
                        a.remove();
                    }).catch(function() {
                        showToast('<?php echo esc_js(__('PNG export unavailable. Use the PDF export instead.', 'matrix-mlm')); ?>', true);
                    }).then(function() {
                        btnPng.disabled = false;
                        btnPng.textContent = origLabel;
                    });
                });
            }
        })();
        </script>
        <?php
    }

    /**
     * Tree-only print view. Reached by appending ?print=1 to the
     * Genealogy URL — the export button does this in a new tab so
     * the member's regular dashboard view is left intact.
     *
     * Renders just enough chrome for a recognisable PDF (top bar
     * with member identity + plan info, the tree itself, and a
     * Print button hidden via @media print) and reuses the same
     * recursive renderer the regular dashboard view uses so the
     * PDF and dashboard tree shapes are identical.
     *
     * Auto-fires window.print() on load so the export button is a
     * single click → save dialog interaction. The print button at
     * the top is the safety net for browsers that block the
     * automatic print() (Safari can prompt) or for when a member
     * dismisses the print dialog and wants to retry.
     */
    private function render_print_mode_view($display_position, $tree, $is_pivoted) {
        $owner_id = (int) $display_position->user_id;
        $owner = get_userdata($owner_id);
        $owner_name = $owner ? ($owner->display_name !== '' ? $owner->display_name : $owner->user_login) : ('User #' . $owner_id);
        ?>
        <style>
        /* Print-specific styles — scoped to the .matrix-genealogy-print
           wrapper so they can't leak into the rest of the dashboard
           if a host page accidentally renders this with print=1
           inline. The @media print rules complete the picture by
           hiding everything that's not the tree. */
        .matrix-genealogy-print {
            background: #fff;
            padding: 16px 0;
        }
        .matrix-genealogy-print .mgp-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #4f46e5;
            color: #fff;
            padding: 10px 16px;
            border-radius: 6px;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 8px;
        }
        .matrix-genealogy-print .mgp-bar h2 {
            margin: 0;
            font-size: 15px;
            color: #fff;
        }
        .matrix-genealogy-print .mgp-bar .mgp-stats {
            font-size: 12px;
            opacity: 0.92;
        }
        @media print {
            /* Hide every dashboard chrome surface — nav bar, sidebar,
               level-badges (already gone via early return), share
               panel, etc. The dashboard layout is theme-driven so
               we cast a wide net using common WP admin/template
               selectors. */
            body * { visibility: hidden !important; }
            .matrix-genealogy-print, .matrix-genealogy-print * { visibility: visible !important; }
            .matrix-genealogy-print { position: absolute; left: 0; top: 0; width: 100%; padding: 0; }
            .matrix-genealogy-print .no-print { display: none !important; }
            .matrix-genealogy-print .mgp-bar { background: #4f46e5 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        </style>

        <div class="matrix-genealogy-print">
            <div class="mgp-bar">
                <h2>
                    <?php
                    printf(
                        /* translators: 1: member display name, 2: plan name, 3: WxD shape */
                        esc_html__('%1$s — %2$s (%3$s)', 'matrix-mlm'),
                        esc_html($owner_name),
                        esc_html($display_position->plan_name),
                        esc_html((int) $display_position->width . 'x' . (int) $display_position->depth)
                    );
                    ?>
                </h2>
                <div class="mgp-stats">
                    <?php
                    printf(
                        /* translators: 1: downline count, 2: max members, 3: completion percent */
                        esc_html__('Downline %1$s · Capacity %2$s', 'matrix-mlm'),
                        number_format((int) $display_position->total_downline),
                        number_format(Matrix_MLM_Plan_Engine::calculate_max_members(
                            (int) $display_position->width,
                            (int) $display_position->depth
                        ))
                    );
                    ?>
                </div>
                <button type="button" class="no-print"
                        onclick="window.print()"
                        style="background:#fff;color:#4f46e5;border:0;padding:5px 14px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;">
                    <?php esc_html_e('Print / Save as PDF', 'matrix-mlm'); ?>
                </button>
            </div>

            <div class="matrix-genealogy-wrapper">
                <div class="matrix-genealogy-tree" id="genealogy-tree-print">
                    <?php
                    if ($tree) {
                        // Reuse the regular dashboard tree renderer
                        // for visual fidelity. The renderer reads
                        // $this->pivot_state for context, which was
                        // set in render() before we short-circuited
                        // here, so it has everything it needs.
                        $this->render_tree_node(
                            $tree,
                            (int) $display_position->width,
                            true,
                            (int) $display_position->user_id,
                            min(4, (int) $display_position->depth)
                        );
                    } else {
                        echo '<p>' . esc_html__('No tree data available yet.', 'matrix-mlm') . '</p>';
                    }
                    ?>
                </div>
            </div>
        </div>
        <script>
        // Auto-trigger the print dialog so opening the URL is a
        // single-click → save-as-PDF interaction. Wrapped in a
        // setTimeout to give the browser one paint cycle to lay
        // out the tree before snapshotting it.
        window.addEventListener('load', function() {
            setTimeout(function() { window.print(); }, 300);
        });
        </script>
        <?php
    }
}
