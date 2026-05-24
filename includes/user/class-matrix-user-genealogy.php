<?php
/**
 * User Genealogy Tree View
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_User_Genealogy {

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
        $initial_render_depth = min(4, (int) $current_position->depth);

        $plan_engine = new Matrix_MLM_Plan_Engine();
        $tree = $plan_engine->get_matrix_tree($user_id, $selected_plan_id, $initial_render_depth);
        $max_members = Matrix_MLM_Plan_Engine::calculate_max_members($current_position->width, $current_position->depth);

        // Per-level fill status for the badge panel below the stats row.
        // We compute this against every level 1..depth (not capped at 4 like
        // the visual tree) because the badge panel is meant to be a
        // glanceable summary of progress across the *entire* matrix —
        // capping at 4 would silently hide the deepest levels of bigger
        // plans (e.g. a 2x10 matrix would lose levels 5–10 from the
        // summary even though they're still trackable here).
        $level_status = $plan_engine->get_level_completion_status(
            $current_position->id,
            $selected_plan_id,
            $current_position->width,
            $current_position->depth
        );
        $levels_completed = 0;
        foreach ($level_status as $row) {
            if (!empty($row['complete'])) {
                $levels_completed++;
            }
        }
        $all_levels_complete = ($levels_completed === intval($current_position->depth));
        ?>

        <div class="matrix-stats-grid" style="margin-bottom: 20px;">
            <div class="matrix-stat-card primary">
                <div class="stat-value"><?php echo $current_position->width . 'x' . $current_position->depth; ?></div>
                <div class="stat-label"><?php _e('Matrix Type', 'matrix-mlm'); ?></div>
            </div>
            <div class="matrix-stat-card success">
                <div class="stat-value"><?php echo number_format($current_position->total_downline); ?></div>
                <div class="stat-label"><?php _e('Your Downline', 'matrix-mlm'); ?></div>
            </div>
            <div class="matrix-stat-card info">
                <div class="stat-value"><?php echo number_format($max_members); ?></div>
                <div class="stat-label"><?php _e('Positions to Fill', 'matrix-mlm'); ?></div>
            </div>
            <div class="matrix-stat-card warning">
                <div class="stat-value"><?php echo $max_members > 1 ? round(($current_position->total_downline / ($max_members - 1)) * 100, 1) . '%' : '100%'; ?></div>
                <div class="stat-label"><?php _e('Completion', 'matrix-mlm'); ?></div>
            </div>
        </div>

        <?php $this->render_level_badges($level_status, $levels_completed, $all_levels_complete, $current_position); ?>

        <div class="matrix-genealogy-wrapper">
            <div class="matrix-genealogy-tree" id="genealogy-tree" data-plan-id="<?php echo (int) $selected_plan_id; ?>" data-plan-depth="<?php echo (int) $current_position->depth; ?>" data-plan-width="<?php echo (int) $current_position->width; ?>">
                <?php if ($tree): ?>
                    <?php $this->render_tree_node($tree, $current_position->width, true, $user_id, $initial_render_depth); ?>
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

        $is_current_user = ($node['user_id'] == $root_user_id);

        // Classify the node so the right badge + style can apply.
        // Tree root is always "you" — we never label the root as
        // direct/spillover even though formally the root has no sponsor
        // in this tree.
        if ($is_current_user) {
            $node_class    = 'matrix-tree-node-you';
            $relationship  = 'you';
            $relationship_label = __('You', 'matrix-mlm');
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
        ?>
        <div class="matrix-tree-item">
            <div class="matrix-tree-node <?php echo esc_attr($node_class); ?>" data-relationship="<?php echo esc_attr($relationship); ?>" data-position-id="<?php echo (int) $node['id']; ?>">
                <div class="tree-node-avatar">
                    <?php echo get_avatar($node['user_id'], 36); ?>
                </div>
                <div class="tree-node-info">
                    <div class="tree-node-name-row">
                        <strong><?php echo esc_html($node['username'] ?? 'User #' . $node['user_id']); ?></strong>
                        <?php if (!$is_current_user): ?>
                        <span class="tree-node-badge tree-node-badge-<?php echo esc_attr($relationship); ?>" title="<?php echo $relationship === 'direct'
                            ? esc_attr__('You personally sponsored this member.', 'matrix-mlm')
                            : esc_attr__('Placed under you via spillover — someone else sponsored them.', 'matrix-mlm'); ?>">
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
