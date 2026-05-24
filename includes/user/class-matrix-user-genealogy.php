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

    public function render($user_id) {
        global $wpdb;

        // Get user's active plans/positions
        $positions = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, pl.name as plan_name, pl.width, pl.depth 
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
                    "SELECT p.*, pl.name as plan_name, pl.width, pl.depth
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

        // Cache the per-render state for the recursive renderer and
        // the lazy-load script to read without long param lists.
        $this->pivot_state = [
            'viewer_user_id'  => (int) $user_id,
            'display_user_id' => $display_user_id,
            'plan_id'         => (int) $selected_plan_id,
            'is_pivoted'      => $is_pivoted,
        ];

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
        ?>

        <?php if ($is_pivoted): ?>
        <?php $this->render_pivot_breadcrumbs($pivot_breadcrumbs); ?>
        <?php endif; ?>

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

        <?php $this->render_level_badges($level_status, $levels_completed, $all_levels_complete, $display_position); ?>

        <div class="matrix-genealogy-wrapper">
            <div class="matrix-genealogy-tree" id="genealogy-tree" data-plan-id="<?php echo (int) $selected_plan_id; ?>" data-plan-depth="<?php echo (int) $display_position->depth; ?>" data-plan-width="<?php echo (int) $display_position->width; ?>" data-root-user-id="<?php echo $display_user_id; ?>">
                <?php if ($tree): ?>
                    <?php $this->render_tree_node($tree, $display_position->width, true, $display_user_id, $initial_render_depth); ?>
                <?php else: ?>
                    <div class="matrix-alert matrix-alert-info"><?php _e('No tree data available yet.', 'matrix-mlm'); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="matrix-genealogy-legend">
            <span class="legend-item"><span class="legend-dot legend-you"></span> <?php _e('You', 'matrix-mlm'); ?></span>
            <span class="legend-item"><span class="legend-dot legend-direct"></span> <?php _e('Direct Referral', 'matrix-mlm'); ?></span>
            <span class="legend-item"><span class="legend-dot legend-spillover"></span> <?php _e('Spillover', 'matrix-mlm'); ?></span>
            <span class="legend-item"><span class="legend-dot legend-empty"></span> <?php _e('Empty Slot', 'matrix-mlm'); ?></span>
        </div>

        <?php $this->render_lazy_load_script(); ?>

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
     */
    private function render_level_badges($level_status, $levels_completed, $all_levels_complete, $current_position) {
        if (empty($level_status)) {
            return;
        }
        $total_levels = count($level_status);
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
                ?>
                <div class="matrix-level-badge <?php echo esc_attr($pill_class); ?>">
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
        ?>
        <div class="matrix-tree-item">
            <div class="matrix-tree-node <?php echo esc_attr($node_class); ?>" data-relationship="<?php echo esc_attr($relationship); ?>" data-position-id="<?php echo (int) $node['id']; ?>">
                <div class="tree-node-avatar">
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
        // children = how many filler cards to draw.
        for ($i = 0; $i < $width - $child_count; $i++) {
            ?>
            <div class="matrix-tree-item">
                <div class="matrix-tree-node matrix-tree-node-empty">
                    <div class="tree-node-avatar tree-node-empty-avatar">
                        <span class="dashicons dashicons-plus-alt2"></span>
                    </div>
                    <div class="tree-node-info">
                        <strong><?php _e('Empty Slot', 'matrix-mlm'); ?></strong>
                        <small><?php _e('Available', 'matrix-mlm'); ?></small>
                    </div>
                </div>
            </div>
            <?php
        }
    }
}
