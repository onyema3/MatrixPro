<?php
/**
 * Visual genealogy admin page with drag-and-drop reassignment.
 *
 * Renders the matrix tree for a selected plan rooted at any
 * operator-chosen user (defaults to the plan's tree root — the
 * position with parent_id IS NULL) using the same visual structure
 * as the member-facing genealogy tab. Each non-root node is
 * HTML5-draggable; dropping a node onto a different node opens a
 * confirmation modal that previews the impact of the move
 * (subtree size travelling, projected downline counts for the old
 * and new parent, plus any blocking guard-rail failures) before
 * invoking the existing matrix_action=move_user_position AJAX
 * endpoint to commit it.
 *
 * Designed as a safer replacement for the typed-username "Move in
 * Genealogy" card on the per-user edit page. The typed form stays
 * around as a fallback (for trees too deep to navigate visually,
 * or when the operator already knows the exact target username
 * and prefers the keyboard path) — see the discoverability link
 * we render at the top of that card pointing here.
 *
 * Markup uses a `mma-tree-*` (Matrix MLM Admin tree) class prefix
 * deliberately separate from the member-facing `matrix-tree-*`
 * classes. The two surfaces have different concerns
 * (admin needs drag handles + preview modal; member view has heat
 * map + pivot trail), so divergence is healthy — sharing the
 * class names would invite cross-surface CSS regressions every
 * time either one tweaks its visuals. Styles are inlined on the
 * page itself so we don't need to ship a separate admin CSS file
 * and worry about cache busting on rollout.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Genealogy {

    /**
     * Initial render depth before "Re-root view here" is offered.
     *
     * Mirrors Matrix_MLM_User_Genealogy's 4-level cap so the admin
     * page payload stays bounded on big plans (a 4-wide matrix at
     * depth 4 is 256 nodes; deeper would start to feel sluggish in
     * the browser even before the drag-handler bookkeeping kicks
     * in). The value is intentionally a constant rather than an
     * option — the lazy-load shape isn't user-tunable on the member
     * side either, and keeping the two surfaces in lockstep means
     * an operator's mental model of "what fits on screen" matches
     * what their members see.
     */
    const INITIAL_DEPTH = 4;

    /**
     * Maximum depth we'll walk when computing subtree sizes for the
     * preview modal. Defends against a corrupted parent_id chain
     * spinning the recursion forever; the legitimate ceiling
     * (validate_matrix() in the plan engine) is 20.
     */
    const PREVIEW_MAX_DEPTH = 50;

    /**
     * Render the admin Genealogy page.
     *
     * URL parameters:
     *   - plan_id      int  Selected plan. Defaults to the first
     *                       active plan when omitted.
     *   - root_user_id int  Tree root for this view. Defaults to
     *                       the plan's structural root (parent_id
     *                       IS NULL). Allows the operator to dive
     *                       into a sub-branch when the full tree
     *                       is too wide to read at the top.
     */
    public function render() {
        global $wpdb;

        // Re-check capability defensively. add_submenu_page already
        // gates this on manage_matrix_users, but a direct call to
        // render() (e.g. from a hook misuse) shouldn't bypass the
        // gate.
        if (!current_user_can('manage_matrix_users')) {
            wp_die(__('You do not have permission to access this page.', 'matrix-mlm'));
        }

        // Resolve plan selection. Pull every active plan once; we
        // need both the dropdown options AND the per-plan width/
        // depth metadata to drive the tree renderer below.
        $plans = $wpdb->get_results(
            "SELECT id, name, width, depth FROM {$wpdb->prefix}matrix_plans
              WHERE status = 'active'
              ORDER BY price ASC, id ASC"
        );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Genealogy (Drag & Drop Move)', 'matrix-mlm') . '</h1>';

        if (empty($plans)) {
            echo '<div class="notice notice-warning"><p>'
                . esc_html__('No active plans yet — create a plan first to view its tree.', 'matrix-mlm')
                . '</p></div>';
            echo '</div>';
            return;
        }

        $selected_plan_id = isset($_GET['plan_id']) ? (int) $_GET['plan_id'] : 0;
        if ($selected_plan_id <= 0) {
            $selected_plan_id = (int) $plans[0]->id;
        }
        $plan = null;
        foreach ($plans as $p) {
            if ((int) $p->id === $selected_plan_id) {
                $plan = $p;
                break;
            }
        }
        if (!$plan) {
            $plan             = $plans[0];
            $selected_plan_id = (int) $plan->id;
        }

        // Resolve the view root. Two-step:
        //   1. If ?root_user_id=X is set and X has an active position
        //      in the selected plan, use that.
        //   2. Otherwise default to the plan's structural root —
        //      parent_id IS NULL. There's normally exactly one such
        //      row per plan; if a legacy import left more than one
        //      orphan we pick the earliest by id (the registration
        //      order).
        $requested_root_user_id = isset($_GET['root_user_id']) ? (int) $_GET['root_user_id'] : 0;
        $root_position          = null;

        if ($requested_root_user_id > 0) {
            $root_position = $wpdb->get_row($wpdb->prepare(
                "SELECT p.*, u.user_login
                   FROM {$wpdb->prefix}matrix_positions p
                   LEFT JOIN {$wpdb->users} u ON p.user_id = u.ID
                  WHERE p.user_id = %d AND p.plan_id = %d AND p.status = 'active'
                  ORDER BY p.id ASC LIMIT 1",
                $requested_root_user_id, $selected_plan_id
            ));
        }
        if (!$root_position) {
            $root_position = $wpdb->get_row($wpdb->prepare(
                "SELECT p.*, u.user_login
                   FROM {$wpdb->prefix}matrix_positions p
                   LEFT JOIN {$wpdb->users} u ON p.user_id = u.ID
                  WHERE p.plan_id = %d AND p.parent_id IS NULL
                  ORDER BY p.id ASC LIMIT 1",
                $selected_plan_id
            ));
        }

        // Selector form — plan + view root. Uses GET so the resulting
        // URL is shareable and bookmarkable; an operator can paste
        // "show me Sarah's branch in plan 3" to a colleague directly.
        $page_url = admin_url('admin.php?page=matrix-mlm-genealogy');
        ?>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin:18px 0 6px;">
            <input type="hidden" name="page" value="matrix-mlm-genealogy">

            <label style="margin-right:8px;font-weight:600;">
                <?php esc_html_e('Plan:', 'matrix-mlm'); ?>
            </label>
            <select name="plan_id" onchange="this.form.submit()">
                <?php foreach ($plans as $p): ?>
                    <option value="<?php echo (int) $p->id; ?>"
                            <?php selected($selected_plan_id, (int) $p->id); ?>>
                        <?php echo esc_html($p->name . ' (' . $p->width . 'x' . $p->depth . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label style="margin:0 8px 0 18px;font-weight:600;">
                <?php esc_html_e('View from user:', 'matrix-mlm'); ?>
            </label>
            <input type="text"
                   name="root_user_id"
                   value="<?php echo $requested_root_user_id > 0 ? (int) $requested_root_user_id : ''; ?>"
                   placeholder="<?php esc_attr_e('user_id (blank = plan root)', 'matrix-mlm'); ?>"
                   style="width:170px;">
            <button type="submit" class="button"><?php esc_html_e('Apply', 'matrix-mlm'); ?></button>

            <?php if ($requested_root_user_id > 0): ?>
                <a class="button" style="margin-left:6px;"
                   href="<?php echo esc_url(add_query_arg(['plan_id' => $selected_plan_id], $page_url)); ?>">
                    <?php esc_html_e('← Back to plan root', 'matrix-mlm'); ?>
                </a>
            <?php endif; ?>
        </form>

        <p class="description" style="margin-bottom:18px;">
            <?php
            esc_html_e(
                'Drag any node onto another node to reassign it (and its entire downline) to the dropped-on user as the new tree parent. A confirmation modal will preview the impact before anything is committed.',
                'matrix-mlm'
            );
            ?>
        </p>

        <?php
        // Print-friendly short circuit. ?print=1 produces a tree-only
        // page for the admin's own PDF export needs (audit trails,
        // capturing tree state before a structural change, sharing a
        // snapshot with a colleague). PNG export uses the same
        // html2canvas-on-demand pattern as the member-side panel.
        $print_mode = isset($_GET['print']) && $_GET['print'] !== '' && $_GET['print'] !== '0';
        ?>

        <div class="mma-tree-export-bar no-print" style="margin:0 0 14px;display:flex;gap:8px;align-items:center;">
            <strong style="font-size:12px;color:#4b5563;">
                <?php esc_html_e('Export this view:', 'matrix-mlm'); ?>
            </strong>
            <a class="button"
               href="<?php echo esc_url(add_query_arg('print', '1')); ?>"
               target="_blank"
               rel="noopener">
                <?php esc_html_e('PDF', 'matrix-mlm'); ?>
            </a>
            <button type="button" class="button" id="mma-tree-export-png">
                <?php esc_html_e('PNG', 'matrix-mlm'); ?>
            </button>
            <span style="color:#6b7280;font-size:11px;">
                <?php esc_html_e('PDF opens a print-friendly view; PNG captures the visible tree as an image.', 'matrix-mlm'); ?>
            </span>
        </div>
        <?php

        if (!$root_position) {
            echo '<div class="notice notice-warning"><p>'
                . esc_html__('No tree root found for this plan yet. Either nobody has joined, or the user you searched for has no active position in this plan.', 'matrix-mlm')
                . '</p></div>';
            echo '</div>';
            return;
        }

        // Build the tree using the same plan engine recursive
        // builder the member-facing genealogy view consumes — keeps
        // node shape (id/user_id/sponsor_id/level/total_downline/
        // children) identical between the two surfaces, so anything
        // we add to a node here naturally inherits whatever the
        // plan engine decides to expose later.
        $plan_engine = new Matrix_MLM_Plan_Engine();
        $tree        = $plan_engine->get_matrix_tree(
            (int) $root_position->user_id,
            $selected_plan_id,
            self::INITIAL_DEPTH
        );

        // Stat strip — mirrors the member-facing one but without
        // commission projections (the operator doesn't need them
        // for a structural-surgery task).
        $max_members  = Matrix_MLM_Plan_Engine::calculate_max_members(
            (int) $plan->width,
            (int) $plan->depth
        );
        $root_downline = (int) $root_position->total_downline;
        ?>
        <div class="mma-tree-stat-strip">
            <div class="mma-tree-stat">
                <span class="mma-tree-stat-label"><?php esc_html_e('Plan shape', 'matrix-mlm'); ?></span>
                <span class="mma-tree-stat-value"><?php echo (int) $plan->width . 'x' . (int) $plan->depth; ?></span>
            </div>
            <div class="mma-tree-stat">
                <span class="mma-tree-stat-label"><?php esc_html_e('Tree root', 'matrix-mlm'); ?></span>
                <span class="mma-tree-stat-value">
                    <?php
                    echo esc_html(
                        $root_position->user_login !== null && $root_position->user_login !== ''
                            ? $root_position->user_login
                            : ('User #' . (int) $root_position->user_id)
                    );
                    ?>
                </span>
            </div>
            <div class="mma-tree-stat">
                <span class="mma-tree-stat-label"><?php esc_html_e('Subtree downline', 'matrix-mlm'); ?></span>
                <span class="mma-tree-stat-value"><?php echo number_format($root_downline); ?></span>
            </div>
            <div class="mma-tree-stat">
                <span class="mma-tree-stat-label"><?php esc_html_e('Plan capacity', 'matrix-mlm'); ?></span>
                <span class="mma-tree-stat-value"><?php echo number_format($max_members); ?></span>
            </div>
        </div>

        <div class="mma-tree-legend">
            <span class="mma-tree-legend-item">
                <span class="mma-tree-legend-dot mma-tree-legend-root"></span>
                <?php esc_html_e('View root (cannot be moved from this view)', 'matrix-mlm'); ?>
            </span>
            <span class="mma-tree-legend-item">
                <span class="mma-tree-legend-dot mma-tree-legend-direct"></span>
                <?php esc_html_e('Direct referral', 'matrix-mlm'); ?>
            </span>
            <span class="mma-tree-legend-item">
                <span class="mma-tree-legend-dot mma-tree-legend-spillover"></span>
                <?php esc_html_e('Spillover', 'matrix-mlm'); ?>
            </span>
            <span class="mma-tree-legend-item">
                <span class="mma-tree-legend-dot mma-tree-legend-empty"></span>
                <?php esc_html_e('Empty slot', 'matrix-mlm'); ?>
            </span>
        </div>

        <div class="mma-tree-wrapper">
            <div class="mma-tree"
                 id="mma-genealogy-tree"
                 data-plan-id="<?php echo (int) $selected_plan_id; ?>"
                 data-plan-width="<?php echo (int) $plan->width; ?>"
                 data-plan-depth="<?php echo (int) $plan->depth; ?>"
                 data-root-position-id="<?php echo (int) $root_position->id; ?>"
                 data-root-user-id="<?php echo (int) $root_position->user_id; ?>">
                <?php
                if ($tree) {
                    $this->render_tree_node(
                        $tree,
                        (int) $plan->width,
                        true,
                        (int) $root_position->user_id,
                        self::INITIAL_DEPTH,
                        $page_url,
                        $selected_plan_id
                    );
                } else {
                    echo '<div class="notice notice-info inline"><p>'
                        . esc_html__('No tree data available for this view.', 'matrix-mlm')
                        . '</p></div>';
                }
                ?>
            </div>
        </div>

        <?php $this->render_inline_styles(); ?>
        <?php $this->render_preview_modal(); ?>
        <?php $this->render_inline_script(); ?>
        <?php $this->render_export_script($print_mode); ?>
        </div><!-- .wrap -->
        <?php
    }

    /**
     * Recursively render a tree node + its subtree.
     *
     * Mirrors Matrix_MLM_User_Genealogy::render_tree_node() in
     * structural shape (item / node / children / empty-slot
     * elements arranged in the same nesting) but emits the
     * `mma-tree-*` class set instead of `matrix-tree-*` so the
     * admin styles, drag handles, and preview-modal hooks live in
     * a clean namespace. We deliberately don't share a renderer
     * with the user-side method because the two surfaces have
     * already diverged on what each node needs to expose
     * (heat-map data attrs vs drag attrs), and trying to thread
     * one renderer through both responsibilities would push the
     * conditionals into every caller.
     *
     * @param array  $node              Node row from
     *                                  Matrix_MLM_Plan_Engine::get_matrix_tree.
     * @param int    $width             Plan width — controls how
     *                                  many empty-slot placeholders
     *                                  to draw under each parent.
     * @param bool   $is_root           True for the very first call;
     *                                  the root is rendered as
     *                                  draggable=false (you can't
     *                                  pick up the view root in
     *                                  this view — re-root if you
     *                                  need to move it).
     * @param int    $root_user_id      User id of the view root —
     *                                  used to compute the
     *                                  Direct/Spillover badge.
     * @param int    $max_render_depth  When current_depth equals
     *                                  this value, leaves with
     *                                  truncated descendants get a
     *                                  "Re-root view here" link
     *                                  instead of expanding inline.
     * @param string $page_url          Base admin URL for the
     *                                  re-root affordance.
     * @param int    $plan_id           Plan id — written into
     *                                  every node's data attrs so
     *                                  the JS dnd handler doesn't
     *                                  have to walk back to the
     *                                  outer wrapper.
     */
    private function render_tree_node(
        $node,
        $width,
        $is_root,
        $root_user_id,
        $max_render_depth,
        $page_url,
        $plan_id
    ) {
        if (!$node) {
            return;
        }

        $position_id    = (int) $node['id'];
        $user_id        = (int) $node['user_id'];
        $username       = isset($node['username']) ? (string) $node['username'] : '';
        $level          = (int) $node['level'];
        $total_downline = (int) ($node['total_downline'] ?? 0);
        $sponsor_id     = isset($node['sponsor_id']) ? (int) $node['sponsor_id'] : 0;
        $children       = isset($node['children']) && is_array($node['children'])
            ? $node['children']
            : [];

        // Direct vs spillover — same rule the member view uses:
        // sponsor_id matches the view root means "this person was
        // referred directly by the root", anything else (or null
        // sponsor) is a spillover placement. The view root
        // itself gets a third bucket so the badge reads as the
        // operator's mental anchor on the tree.
        if ($is_root) {
            $node_class    = 'mma-tree-node-root';
            $relationship  = 'root';
            $rel_label     = __('View root', 'matrix-mlm');
        } elseif ($sponsor_id > 0 && $sponsor_id === (int) $root_user_id) {
            $node_class    = 'mma-tree-node-direct';
            $relationship  = 'direct';
            $rel_label     = __('Direct', 'matrix-mlm');
        } else {
            $node_class    = 'mma-tree-node-spillover';
            $relationship  = 'spillover';
            $rel_label     = __('Spillover', 'matrix-mlm');
        }

        // The view root isn't draggable: moving it would mean
        // moving the whole rendered surface, which would re-root
        // the view to nothing useful. Operators wanting to move
        // the root should re-root the view to that user's parent
        // first, then drag from there. Every other node is
        // draggable.
        $draggable_attr = $is_root ? 'false' : 'true';

        $display_name = $username !== '' ? $username : ('User #' . $user_id);
        ?>
        <div class="mma-tree-item">
            <div class="mma-tree-node <?php echo esc_attr($node_class); ?>"
                 draggable="<?php echo esc_attr($draggable_attr); ?>"
                 data-position-id="<?php echo (int) $position_id; ?>"
                 data-user-id="<?php echo (int) $user_id; ?>"
                 data-username="<?php echo esc_attr($display_name); ?>"
                 data-level="<?php echo (int) $level; ?>"
                 data-downline="<?php echo (int) $total_downline; ?>"
                 data-relationship="<?php echo esc_attr($relationship); ?>"
                 data-is-root="<?php echo $is_root ? '1' : '0'; ?>"
                 data-plan-id="<?php echo (int) $plan_id; ?>">
                <div class="mma-tree-node-name"><?php echo esc_html($display_name); ?></div>
                <div class="mma-tree-node-meta">
                    <span class="mma-tree-rel-badge mma-tree-rel-<?php echo esc_attr($relationship); ?>">
                        <?php echo esc_html($rel_label); ?>
                    </span>
                    <span class="mma-tree-node-stat">
                        <?php
                        printf(
                            /* translators: %d: subtree size */
                            esc_html__('Downline: %d', 'matrix-mlm'),
                            (int) $total_downline
                        );
                        ?>
                    </span>
                </div>
                <?php if (!$is_root): ?>
                    <span class="mma-tree-node-drag-hint" aria-hidden="true">⋮⋮</span>
                <?php endif; ?>
            </div>

            <?php
            // Children container. Two responsibilities:
            //
            //   1. If the node has rendered children, walk them and
            //      render. Any missing slots up to $width get an
            //      empty-slot placeholder so admins can drop nodes
            //      into specific positions (the backend will assign
            //      the next free slot index regardless, but the
            //      visual cue helps operators see where capacity
            //      remains).
            //   2. If the node has zero children at this depth but
            //      the plan width is positive AND we haven't hit
            //      $max_render_depth, we still draw $width empty
            //      slots so the dnd target surface is visible. This
            //      is what makes a totally-empty branch droppable.
            //   3. If we hit $max_render_depth and the node has more
            //      descendants below, surface a "Re-root view here"
            //      link instead of recursing — same lazy-load
            //      heuristic the member view uses, just expressed
            //      as page navigation rather than inline AJAX
            //      because admin tree-surgery sessions usually
            //      pivot between branches anyway.
            $rendered_children = count($children);
            $can_recurse       = ($level < $max_render_depth);
            $has_more_below    = ($total_downline > $rendered_children);
            ?>

            <?php if ($can_recurse): ?>
                <?php if ($rendered_children > 0 || $width > 0): ?>
                <div class="mma-tree-children">
                    <?php
                    // Render existing children, tracking how many
                    // slots we've used so we can pad with empty
                    // slot placeholders up to plan width.
                    foreach ($children as $child) {
                        $this->render_tree_node(
                            $child,
                            $width,
                            false,
                            $root_user_id,
                            $max_render_depth,
                            $page_url,
                            $plan_id
                        );
                    }
                    $remaining_slots = max(0, $width - $rendered_children);
                    for ($i = 0; $i < $remaining_slots; $i++) {
                        $this->render_empty_slot($position_id, $level + 1, $plan_id);
                    }
                    ?>
                </div>
                <?php endif; ?>
            <?php elseif ($has_more_below): ?>
                <div class="mma-tree-truncated">
                    <a class="button button-small"
                       href="<?php
                            echo esc_url(add_query_arg(
                                [
                                    'plan_id'      => (int) $plan_id,
                                    'root_user_id' => (int) $user_id,
                                ],
                                $page_url
                            ));
                       ?>">
                        <?php
                        printf(
                            /* translators: %d: count of descendants below the visible cutoff */
                            esc_html__('Re-root view here (%d below)', 'matrix-mlm'),
                            (int) ($total_downline - $rendered_children)
                        );
                        ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render a placeholder for a still-empty matrix slot.
     *
     * The slot is marked as a drop target (draggable nodes can be
     * dropped onto it) and carries the parent's position id in a
     * data attribute so the dnd handler can address the parent
     * directly. Slots are NOT draggable themselves — there's
     * nothing to pick up.
     *
     * Visually rendered as a dashed outline, mirroring the dashed
     * style the member view uses for empty slots so the two
     * surfaces stay aesthetically consistent.
     */
    private function render_empty_slot($parent_position_id, $level, $plan_id) {
        ?>
        <div class="mma-tree-item mma-tree-item-empty">
            <div class="mma-tree-node mma-tree-node-empty"
                 data-empty-slot="1"
                 data-parent-position-id="<?php echo (int) $parent_position_id; ?>"
                 data-level="<?php echo (int) $level; ?>"
                 data-plan-id="<?php echo (int) $plan_id; ?>">
                <span class="mma-tree-empty-label">
                    <?php esc_html_e('Empty slot', 'matrix-mlm'); ?>
                </span>
            </div>
        </div>
        <?php
    }

    /**
     * Inline CSS for the admin tree.
     *
     * Self-contained on purpose — see the class docblock for why
     * we don't share rules with the member-facing
     * matrix-dashboard.css. All selectors are prefixed with
     * `.mma-tree-` so they can never accidentally collide with WP
     * admin defaults or other pages on the Matrix admin section.
     */
    private function render_inline_styles() {
        ?>
        <style>
        .mma-tree-stat-strip {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin: 8px 0 16px;
        }
        .mma-tree-stat {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 14px;
            min-width: 150px;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .mma-tree-stat-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #6b7280;
        }
        .mma-tree-stat-value {
            font-size: 18px;
            font-weight: 600;
            color: #111827;
        }

        .mma-tree-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin: 0 0 12px;
            padding: 8px 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 12px;
            color: #374151;
        }
        .mma-tree-legend-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .mma-tree-legend-dot {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 1px solid rgba(0,0,0,0.08);
        }
        .mma-tree-legend-root     { background: #4f46e5; }
        .mma-tree-legend-direct   { background: #10b981; }
        .mma-tree-legend-spillover{ background: #f59e0b; }
        .mma-tree-legend-empty    { background: #fff; border-style: dashed; border-color: #cbd5e1; }

        .mma-tree-wrapper {
            overflow: auto;
            padding: 16px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            max-height: 70vh;
        }
        .mma-tree {
            display: flex;
            justify-content: center;
            min-width: fit-content;
        }

        .mma-tree-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            padding: 0 6px;
        }
        .mma-tree-item + .mma-tree-item { /* no-op, kept for future tweaking */ }

        .mma-tree-node {
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 4px;
            background: #fff;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px 12px;
            min-width: 150px;
            max-width: 220px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            cursor: grab;
            transition: transform 0.12s, box-shadow 0.12s, border-color 0.12s;
        }
        .mma-tree-node[draggable="false"] {
            cursor: default;
        }
        .mma-tree-node:hover {
            box-shadow: 0 4px 10px rgba(0,0,0,0.08);
            transform: translateY(-1px);
        }
        .mma-tree-node-root {
            border-color: #4f46e5;
            background: linear-gradient(135deg, #eef2ff, #e0e7ff);
        }
        .mma-tree-node-direct {
            border-color: #10b981;
            background: #ecfdf5;
        }
        .mma-tree-node-spillover {
            border-color: #f59e0b;
            background: #fffbeb;
        }
        .mma-tree-node-empty {
            border-style: dashed;
            border-color: #cbd5e1;
            background: #f9fafb;
            color: #94a3b8;
            font-size: 12px;
            min-width: 120px;
            justify-content: center;
            align-items: center;
            min-height: 38px;
            cursor: default;
        }
        .mma-tree-node-empty .mma-tree-empty-label {
            font-style: italic;
        }

        .mma-tree-node-name {
            font-weight: 600;
            color: #111827;
            font-size: 13px;
            word-break: break-word;
        }
        .mma-tree-node-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 11px;
            color: #4b5563;
        }
        .mma-tree-rel-badge {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .mma-tree-rel-root      { background: #e0e7ff; color: #3730a3; }
        .mma-tree-rel-direct    { background: #d1fae5; color: #065f46; }
        .mma-tree-rel-spillover { background: #fef3c7; color: #92400e; }

        .mma-tree-node-drag-hint {
            position: absolute;
            top: 6px;
            right: 8px;
            color: #cbd5e1;
            font-size: 12px;
            line-height: 1;
            user-select: none;
            pointer-events: none;
        }

        /* Children layout — flex row, with vertical / horizontal
           pseudo-element connectors so the hierarchy reads. Kept
           subtle so the focus remains on the cards themselves. */
        .mma-tree-children {
            display: flex;
            flex-direction: row;
            justify-content: center;
            margin-top: 22px;
            position: relative;
        }
        .mma-tree-children::before {
            content: '';
            position: absolute;
            top: -12px;
            left: 50%;
            width: 1px;
            height: 12px;
            background: #cbd5e1;
        }
        .mma-tree-children > .mma-tree-item::before {
            content: '';
            position: absolute;
            top: -10px;
            left: 50%;
            width: 1px;
            height: 10px;
            background: #cbd5e1;
        }
        .mma-tree-children > .mma-tree-item:not(:only-child)::after {
            content: '';
            position: absolute;
            top: -10px;
            left: 0;
            right: 0;
            height: 1px;
            background: #cbd5e1;
        }
        .mma-tree-children > .mma-tree-item:first-child::after {
            left: 50%;
        }
        .mma-tree-children > .mma-tree-item:last-child::after {
            right: 50%;
        }

        .mma-tree-truncated {
            margin-top: 12px;
            text-align: center;
        }

        /* Drag-and-drop visual states */
        .mma-tree-node.is-dragging {
            opacity: 0.45;
            cursor: grabbing;
        }
        .mma-tree-node.is-drop-valid {
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.18);
        }
        .mma-tree-node.is-drop-invalid {
            border-color: #ef4444;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.18);
            cursor: not-allowed;
        }

        /* Preview modal */
        .mma-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 100050;
        }
        .mma-modal-backdrop.is-open { display: flex; }
        .mma-modal {
            background: #fff;
            border-radius: 10px;
            min-width: 360px;
            max-width: 560px;
            width: 90%;
            max-height: 85vh;
            overflow: auto;
            box-shadow: 0 20px 50px rgba(0,0,0,0.25);
            padding: 0;
        }
        .mma-modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid #e2e8f0;
        }
        .mma-modal-header h2 {
            margin: 0;
            font-size: 16px;
            color: #111827;
        }
        .mma-modal-body {
            padding: 16px 20px;
            font-size: 13px;
            color: #1f2937;
            line-height: 1.55;
        }
        .mma-modal-body .mma-modal-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 6px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .mma-modal-body .mma-modal-row:last-child {
            border-bottom: 0;
        }
        .mma-modal-body .mma-modal-row .label {
            color: #6b7280;
        }
        .mma-modal-body .mma-modal-row .value {
            color: #111827;
            font-weight: 600;
            text-align: right;
        }
        .mma-modal-body .delta-up   { color: #047857; }
        .mma-modal-body .delta-down { color: #b91c1c; }

        .mma-modal-footer {
            padding: 14px 20px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }
        .mma-modal-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 10px 12px;
            border-radius: 6px;
            margin: 0 0 12px;
            display: none;
        }
        .mma-modal-error.is-shown { display: block; }
        .mma-modal-loader {
            color: #6b7280;
            font-style: italic;
            padding: 10px 0;
        }

        /* Print: hide every WP admin chrome surface and the export
           toolbar itself, leave just the tree. The wp-admin selectors
           cover the menu sidebar (#adminmenuwrap), the top admin bar
           (#wpadminbar), screen options (#screen-meta), and the
           page heading we don't want in the PDF. */
        @media print {
            #adminmenuwrap, #adminmenuback, #wpadminbar, #screen-meta,
            #screen-meta-links, .update-nag, .notice, .wrap > h1,
            form[method="get"], .mma-tree-export-bar, .mma-tree-stat-strip,
            .mma-tree-legend, .mma-modal-backdrop, .mma-tree-node-drag-hint,
            .no-print { display: none !important; }
            #wpcontent, #wpbody, #wpbody-content, .wrap {
                margin: 0 !important; padding: 0 !important;
            }
            html.wp-toolbar { padding-top: 0 !important; }
            body.wp-admin { background: #fff !important; }
            .mma-tree-wrapper {
                border: 0 !important; padding: 0 !important;
                max-height: none !important; overflow: visible !important;
            }
            .mma-tree-node-root, .mma-tree-node-direct, .mma-tree-node-spillover {
                /* Force colored backgrounds to print so the legend
                   meaning is preserved on paper / PDF. */
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }
        </style>
        <?php
    }

    /**
     * Hidden modal scaffold + a noscript fallback note.
     *
     * The modal markup ships hidden and is populated by the dnd
     * JS handler when a drop is intercepted. We pre-bake the
     * structure rather than constructing it via JS DOM creation
     * because keeping the markup in PHP makes the i18n strings
     * translatable through the normal __() pipeline — the JS just
     * fills in computed values.
     */
    private function render_preview_modal() {
        ?>
        <noscript>
            <div class="notice notice-error">
                <p>
                    <?php
                    esc_html_e(
                        'The drag-and-drop genealogy editor requires JavaScript. Use the typed-username form on the per-user edit page as a fallback.',
                        'matrix-mlm'
                    );
                    ?>
                </p>
            </div>
        </noscript>

        <div class="mma-modal-backdrop" id="mma-move-modal" role="dialog" aria-modal="true" aria-labelledby="mma-move-modal-title">
            <div class="mma-modal">
                <div class="mma-modal-header">
                    <h2 id="mma-move-modal-title">
                        <?php esc_html_e('Confirm Move', 'matrix-mlm'); ?>
                    </h2>
                </div>
                <div class="mma-modal-body">
                    <div class="mma-modal-error" id="mma-modal-error"></div>
                    <div class="mma-modal-loader" id="mma-modal-loader">
                        <?php esc_html_e('Calculating impact…', 'matrix-mlm'); ?>
                    </div>
                    <div id="mma-modal-content" style="display:none;">
                        <p id="mma-modal-summary" style="margin:0 0 12px;"></p>

                        <div class="mma-modal-row">
                            <span class="label"><?php esc_html_e('Moving', 'matrix-mlm'); ?></span>
                            <span class="value" id="mma-modal-source"></span>
                        </div>
                        <div class="mma-modal-row">
                            <span class="label"><?php esc_html_e('From parent', 'matrix-mlm'); ?></span>
                            <span class="value" id="mma-modal-old-parent"></span>
                        </div>
                        <div class="mma-modal-row">
                            <span class="label"><?php esc_html_e('To parent', 'matrix-mlm'); ?></span>
                            <span class="value" id="mma-modal-new-parent"></span>
                        </div>
                        <div class="mma-modal-row">
                            <span class="label"><?php esc_html_e('Subtree size moving (incl. user)', 'matrix-mlm'); ?></span>
                            <span class="value" id="mma-modal-subtree-size"></span>
                        </div>
                        <div class="mma-modal-row">
                            <span class="label"><?php esc_html_e('Old parent downline', 'matrix-mlm'); ?></span>
                            <span class="value" id="mma-modal-old-delta"></span>
                        </div>
                        <div class="mma-modal-row">
                            <span class="label"><?php esc_html_e('New parent downline', 'matrix-mlm'); ?></span>
                            <span class="value" id="mma-modal-new-delta"></span>
                        </div>
                    </div>
                </div>
                <div class="mma-modal-footer">
                    <button type="button" class="button" id="mma-modal-cancel">
                        <?php esc_html_e('Cancel', 'matrix-mlm'); ?>
                    </button>
                    <button type="button" class="button button-primary" id="mma-modal-confirm" disabled>
                        <?php esc_html_e('Confirm Move', 'matrix-mlm'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Inline JS for drag-and-drop + preview modal + commit hook.
     *
     * Single closure, no jQuery dependency for the core dnd flow —
     * jQuery is used only for the AJAX call to stay consistent
     * with how the rest of the admin module talks to admin-ajax
     * (see class-matrix-admin-users.php). Keeping the dnd handlers
     * in vanilla JS means the page works even on the bare
     * minimum WP admin script set.
     *
     * Drop semantics: dropping node A onto node B requests "make
     * B the new parent of A". Dropping A onto an empty slot
     * requests the same thing addressed at the slot's parent.
     * The backend's existing move_user_position handler picks the
     * next free position index for us — we don't try to address
     * a specific slot index here because the visual slot rendering
     * is purely informational (slots are added in registration
     * order on commit, not slot-index order).
     */
    private function render_inline_script() {
        $ajax_url = esc_js(admin_url('admin-ajax.php'));
        ?>
        <script>
        (function() {
            var ajaxUrl = (window.matrixMLMAdmin && window.matrixMLMAdmin.ajaxUrl) || '<?php echo $ajax_url; ?>';
            var nonce   = (window.matrixMLMAdmin && window.matrixMLMAdmin.nonce)   || '';

            var tree = document.getElementById('mma-genealogy-tree');
            if (!tree) return;

            var modal       = document.getElementById('mma-move-modal');
            var modalLoader = document.getElementById('mma-modal-loader');
            var modalContent= document.getElementById('mma-modal-content');
            var modalError  = document.getElementById('mma-modal-error');
            var btnConfirm  = document.getElementById('mma-modal-confirm');
            var btnCancel   = document.getElementById('mma-modal-cancel');

            // State held on the modal between open and confirm. We
            // read this on Confirm to decide what payload to POST.
            var pendingMove = null;

            // ---------- Drag source tracking ----------
            var dragSrc = null;

            function clearDropMarkers() {
                tree.querySelectorAll('.is-drop-valid, .is-drop-invalid').forEach(function(n) {
                    n.classList.remove('is-drop-valid');
                    n.classList.remove('is-drop-invalid');
                });
            }

            tree.addEventListener('dragstart', function(e) {
                var node = e.target.closest('.mma-tree-node');
                if (!node || node.getAttribute('draggable') !== 'true') {
                    e.preventDefault();
                    return;
                }
                dragSrc = node;
                node.classList.add('is-dragging');
                // dataTransfer needs *something* set or Firefox refuses
                // to fire a drop event. The actual payload travels via
                // the dragSrc closure variable so we don't need a
                // structured serialisation here.
                try {
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', node.getAttribute('data-position-id') || '');
                } catch (err) { /* IE / restricted contexts — ignore */ }
            });

            tree.addEventListener('dragend', function() {
                if (dragSrc) dragSrc.classList.remove('is-dragging');
                dragSrc = null;
                clearDropMarkers();
            });

            // dragover has to preventDefault to mark the element as a
            // valid drop target. We also classify the target up front
            // (valid vs invalid) so the operator gets immediate visual
            // feedback before letting go — saves them a wasted drop +
            // modal cycle for obviously-bad targets.
            tree.addEventListener('dragover', function(e) {
                if (!dragSrc) return;
                var target = e.target.closest('.mma-tree-node');
                if (!target || target === dragSrc) return;

                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';

                // Only re-classify when we cross into a new node — the
                // dragover event fires constantly and we don't want to
                // thrash the DOM. Cheap check: bail when target already
                // has either marker.
                if (target.classList.contains('is-drop-valid') ||
                    target.classList.contains('is-drop-invalid')) {
                    return;
                }
                clearDropMarkers();
                var verdict = classifyDrop(dragSrc, target);
                target.classList.add(verdict.valid ? 'is-drop-valid' : 'is-drop-invalid');
            });

            tree.addEventListener('dragleave', function(e) {
                var target = e.target.closest('.mma-tree-node');
                if (!target) return;
                // Don't clear markers on internal child dragleave — only
                // when we leave the node entirely. We approximate that
                // by checking the relatedTarget is outside the node.
                if (e.relatedTarget && target.contains(e.relatedTarget)) {
                    return;
                }
                target.classList.remove('is-drop-valid');
                target.classList.remove('is-drop-invalid');
            });

            tree.addEventListener('drop', function(e) {
                if (!dragSrc) return;
                var target = e.target.closest('.mma-tree-node');
                if (!target || target === dragSrc) return;

                e.preventDefault();

                var verdict = classifyDrop(dragSrc, target);
                if (!verdict.valid) {
                    // Cheap synchronous bail — better UX than opening
                    // the modal just to show an error inside it.
                    flashInvalidDrop(target, verdict.reason);
                    clearDropMarkers();
                    return;
                }

                // Resolve which node should become the new parent:
                //   - drop on existing node X: X becomes new parent.
                //   - drop on empty slot:      slot's parent becomes
                //                              new parent (the slot
                //                              is just a UI marker).
                var newParentPositionId, newParentUserId, newParentUsername;
                if (target.dataset.emptySlot === '1') {
                    newParentPositionId = parseInt(target.dataset.parentPositionId, 10);
                    // For empty slots we don't have the parent
                    // username locally; surface the position id and
                    // let the preview AJAX fill in the rest.
                    newParentUsername = '#' + newParentPositionId;
                    newParentUserId   = 0;
                } else {
                    newParentPositionId = parseInt(target.dataset.positionId, 10);
                    newParentUserId     = parseInt(target.dataset.userId, 10);
                    newParentUsername   = target.dataset.username || ('#' + newParentPositionId);
                }

                pendingMove = {
                    sourcePositionId: parseInt(dragSrc.dataset.positionId, 10),
                    sourceUserId:     parseInt(dragSrc.dataset.userId, 10),
                    sourceUsername:   dragSrc.dataset.username,
                    newParentPositionId: newParentPositionId,
                    newParentUserId:     newParentUserId,
                    newParentUsername:   newParentUsername,
                    newParentUsernameForCommit: '' // filled in after preview
                };

                openModal();
                fetchPreview(pendingMove);
            });

            // ---------- Local guard rails ----------
            // Server enforces these too (see preview_move_position
            // and the original move_user_position) — running them
            // here just avoids a round-trip on obvious no-ops.
            function classifyDrop(src, target) {
                if (target.dataset.emptySlot === '1') {
                    var parentId = parseInt(target.dataset.parentPositionId, 10);
                    if (parentId === parseInt(src.dataset.positionId, 10)) {
                        return { valid: false, reason: 'cannot drop a node onto its own children' };
                    }
                    return { valid: true };
                }
                if (target === src) {
                    return { valid: false, reason: 'cannot drop a node onto itself' };
                }
                if (target.dataset.userId === src.dataset.userId) {
                    return { valid: false, reason: 'same user' };
                }
                if (isAncestor(src, target)) {
                    return { valid: false, reason: 'cannot move under your own descendant' };
                }
                return { valid: true };
            }

            // Walk DOM ancestors looking for src — if we find it, target
            // sits inside src's subtree. Cheap, since the rendered
            // tree caps at INITIAL_DEPTH levels.
            function isAncestor(src, target) {
                var n = target.parentElement;
                while (n && n !== tree) {
                    if (n.classList && n.classList.contains('mma-tree-item')) {
                        var firstNode = n.querySelector(':scope > .mma-tree-node');
                        if (firstNode === src) return true;
                    }
                    n = n.parentElement;
                }
                return false;
            }

            function flashInvalidDrop(target, reason) {
                target.classList.add('is-drop-invalid');
                setTimeout(function() {
                    target.classList.remove('is-drop-invalid');
                }, 600);
            }

            // ---------- Modal ----------
            function openModal() {
                modalError.classList.remove('is-shown');
                modalError.textContent = '';
                modalContent.style.display = 'none';
                modalLoader.style.display  = 'block';
                btnConfirm.disabled = true;
                modal.classList.add('is-open');
            }

            function closeModal() {
                modal.classList.remove('is-open');
                pendingMove = null;
            }

            btnCancel.addEventListener('click', closeModal);
            modal.addEventListener('click', function(e) {
                if (e.target === modal) closeModal();
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.classList.contains('is-open')) {
                    closeModal();
                }
            });

            function fetchPreview(move) {
                jQuery.post(ajaxUrl, {
                    action: 'matrix_admin_action',
                    nonce: nonce,
                    matrix_action: 'preview_move_position',
                    position_id: move.sourcePositionId,
                    new_parent_position_id: move.newParentPositionId
                }, function(res) {
                    modalLoader.style.display = 'none';
                    if (!res || !res.success) {
                        showModalError((res && res.data && res.data.message) || 'Preview failed.');
                        return;
                    }
                    populateModal(res.data);
                    if (move) {
                        move.newParentUsernameForCommit = res.data.new_parent_username || '';
                    }
                    btnConfirm.disabled = !res.data.safe_to_move;
                    if (!res.data.safe_to_move && res.data.block_reason) {
                        showModalError(res.data.block_reason);
                    }
                }).fail(function() {
                    modalLoader.style.display = 'none';
                    showModalError('Network error while computing preview.');
                });
            }

            function showModalError(msg) {
                modalError.textContent = msg;
                modalError.classList.add('is-shown');
                modalContent.style.display = 'block';
            }

            function populateModal(d) {
                modalContent.style.display = 'block';
                document.getElementById('mma-modal-summary').textContent = d.summary || '';
                document.getElementById('mma-modal-source').textContent     = d.source_username || '';
                document.getElementById('mma-modal-old-parent').textContent = d.old_parent_username || '—';
                document.getElementById('mma-modal-new-parent').textContent = d.new_parent_username || '';
                document.getElementById('mma-modal-subtree-size').textContent = d.subtree_size + '';

                var oldEl = document.getElementById('mma-modal-old-delta');
                if (d.old_parent_username) {
                    oldEl.textContent = d.old_parent_current_downline + ' → ' + d.old_parent_projected_downline;
                    oldEl.classList.remove('delta-up');
                    oldEl.classList.add('delta-down');
                } else {
                    oldEl.textContent = '—';
                }

                var newEl = document.getElementById('mma-modal-new-delta');
                newEl.textContent = d.new_parent_current_downline + ' → ' + d.new_parent_projected_downline;
                newEl.classList.add('delta-up');
                newEl.classList.remove('delta-down');
            }

            btnConfirm.addEventListener('click', function() {
                if (!pendingMove) return;
                btnConfirm.disabled = true;
                btnConfirm.textContent = '<?php echo esc_js(__('Working…', 'matrix-mlm')); ?>';

                jQuery.post(ajaxUrl, {
                    action: 'matrix_admin_action',
                    nonce: nonce,
                    matrix_action: 'move_user_position',
                    position_id: pendingMove.sourcePositionId,
                    new_parent_username: pendingMove.newParentUsernameForCommit
                }, function(res) {
                    if (res && res.success) {
                        // Reload to re-render the tree from authoritative
                        // server state. Tracking deltas locally would
                        // mean re-implementing the recursive level /
                        // total_downline updates the backend already
                        // does — not worth the complexity for an admin
                        // surgery surface that isn't latency-critical.
                        window.location.reload();
                    } else {
                        var msg = (res && res.data && res.data.message) || '<?php echo esc_js(__('Move failed.', 'matrix-mlm')); ?>';
                        showModalError(msg);
                        btnConfirm.disabled = false;
                        btnConfirm.textContent = '<?php echo esc_js(__('Confirm Move', 'matrix-mlm')); ?>';
                    }
                }).fail(function() {
                    showModalError('<?php echo esc_js(__('Network error while moving.', 'matrix-mlm')); ?>');
                    btnConfirm.disabled = false;
                    btnConfirm.textContent = '<?php echo esc_js(__('Confirm Move', 'matrix-mlm')); ?>';
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * Compute the impact of a proposed move.
     *
     * Public + static so the AJAX dispatcher in class-matrix-admin
     * (which lives on a different class) can call us without
     * standing up an instance. Returns a JSON-shape ready for
     * wp_send_json_success(): if validation fails we still return
     * a "safe_to_move=false" payload with a human-readable
     * block_reason, so the modal can render the impact summary
     * AND tell the operator why the Confirm button is disabled,
     * rather than ending in an opaque error.
     *
     * Validation mirrors Matrix_MLM_Admin::move_user_position()
     * exactly:
     *   - both positions exist
     *   - same plan
     *   - target user is not the source user
     *   - target user has an active position in the plan
     *   - target node has free width capacity
     *   - target is not a descendant of source (no cycles)
     *
     * The numerical preview is computed regardless of whether the
     * move would be blocked — operators sometimes want to know
     * "what *would* this move look like if I freed up a slot
     * first?" and surfacing the projected downline counts on
     * blocked previews is one of the small affordances that makes
     * the visual editor more informative than the typed form ever
     * could be.
     *
     * @param int $position_id            Position being moved.
     * @param int $new_parent_position_id Position that should
     *                                     become the new parent.
     * @return array Preview payload.
     */
    public static function build_move_preview($position_id, $new_parent_position_id) {
        global $wpdb;

        $position_id            = (int) $position_id;
        $new_parent_position_id = (int) $new_parent_position_id;

        $payload = [
            'safe_to_move'                  => false,
            'block_reason'                  => '',
            'summary'                       => '',
            'source_username'               => '',
            'old_parent_username'           => '',
            'new_parent_username'           => '',
            'subtree_size'                  => 0,
            'old_parent_current_downline'   => 0,
            'old_parent_projected_downline' => 0,
            'new_parent_current_downline'   => 0,
            'new_parent_projected_downline' => 0,
        ];

        if ($position_id <= 0 || $new_parent_position_id <= 0) {
            $payload['block_reason'] = __('Missing position id.', 'matrix-mlm');
            return $payload;
        }
        if ($position_id === $new_parent_position_id) {
            $payload['block_reason'] = __('Cannot drop a node onto itself.', 'matrix-mlm');
            return $payload;
        }

        $source = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, u.user_login
               FROM {$wpdb->prefix}matrix_positions p
               LEFT JOIN {$wpdb->users} u ON p.user_id = u.ID
              WHERE p.id = %d",
            $position_id
        ));
        if (!$source) {
            $payload['block_reason'] = __('Source position not found.', 'matrix-mlm');
            return $payload;
        }

        $target = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, u.user_login
               FROM {$wpdb->prefix}matrix_positions p
               LEFT JOIN {$wpdb->users} u ON p.user_id = u.ID
              WHERE p.id = %d",
            $new_parent_position_id
        ));
        if (!$target) {
            $payload['block_reason'] = __('Target position not found.', 'matrix-mlm');
            return $payload;
        }

        if ((int) $target->plan_id !== (int) $source->plan_id) {
            $payload['block_reason'] = __('Source and target are on different plans.', 'matrix-mlm');
            return $payload;
        }
        if ($target->status !== 'active') {
            $payload['block_reason'] = __('Target user has no active position in this plan.', 'matrix-mlm');
            return $payload;
        }
        if ((int) $target->user_id === (int) $source->user_id) {
            $payload['block_reason'] = __('Cannot place a user under themselves.', 'matrix-mlm');
            return $payload;
        }

        // Source's existing subtree size — this is exactly the count
        // of descendants that travels with the move. The +1 in the
        // summary is the source itself (which also lands under the
        // new parent).
        $subtree_size = (int) $source->total_downline;

        // Old parent + projected downline. parent_id can be NULL on
        // the matrix root, in which case there's no "old parent" to
        // subtract from — surface that as an empty username so the
        // modal renders "—" and skips the row.
        $old_parent_username           = '';
        $old_parent_current_downline   = 0;
        $old_parent_projected_downline = 0;
        if (!empty($source->parent_id)) {
            $old_parent = $wpdb->get_row($wpdb->prepare(
                "SELECT p.id, p.total_downline, u.user_login
                   FROM {$wpdb->prefix}matrix_positions p
                   LEFT JOIN {$wpdb->users} u ON p.user_id = u.ID
                  WHERE p.id = %d",
                (int) $source->parent_id
            ));
            if ($old_parent) {
                $old_parent_username           = (string) $old_parent->user_login;
                $old_parent_current_downline   = (int) $old_parent->total_downline;
                // Subtract the source AND its subtree from the old
                // parent's count. The chain above old_parent will
                // also drop by the same amount, but the modal only
                // surfaces the immediate parent — operators can pull
                // up the upline in a separate read if they need the
                // ripple breakdown.
                $old_parent_projected_downline = max(
                    0,
                    $old_parent_current_downline - 1 - $subtree_size
                );
            }
        }

        $new_parent_username           = (string) $target->user_login;
        $new_parent_current_downline   = (int) $target->total_downline;
        $new_parent_projected_downline = $new_parent_current_downline + 1 + $subtree_size;

        $payload['source_username']               = (string) $source->user_login;
        $payload['old_parent_username']           = $old_parent_username;
        $payload['new_parent_username']           = $new_parent_username;
        $payload['subtree_size']                  = $subtree_size + 1; // include source itself
        $payload['old_parent_current_downline']   = $old_parent_current_downline;
        $payload['old_parent_projected_downline'] = $old_parent_projected_downline;
        $payload['new_parent_current_downline']   = $new_parent_current_downline;
        $payload['new_parent_projected_downline'] = $new_parent_projected_downline;
        $payload['summary'] = sprintf(
            /* translators: 1: descendants count, 2: source username, 3: target username */
            __('This will move %1$d descendant(s) plus %2$s onto %3$s.', 'matrix-mlm'),
            $subtree_size,
            $payload['source_username'],
            $new_parent_username
        );

        // Cycle check — target must not be in source's subtree. We
        // walk parent_id up from the target until we either hit
        // NULL (top of tree, all good), find source (cycle, block),
        // or burn through the safety cap.
        if (self::is_descendant_of_position(
            (int) $target->id,
            (int) $source->id,
            (int) $source->plan_id
        )) {
            $payload['block_reason'] = __('Cannot move a user under their own descendant — that would create a circular reference.', 'matrix-mlm');
            return $payload;
        }

        // Width capacity check on the target. Counts existing
        // children excluding the source (in case the source already
        // happens to be parked under target — re-anchoring the same
        // child to the same parent is a no-op, not a width violation).
        $plan = $wpdb->get_row($wpdb->prepare(
            "SELECT width FROM {$wpdb->prefix}matrix_plans WHERE id = %d",
            (int) $target->plan_id
        ));
        $width = $plan ? (int) $plan->width : 0;

        $child_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
               FROM {$wpdb->prefix}matrix_positions
              WHERE parent_id = %d AND plan_id = %d AND id != %d",
            (int) $target->id, (int) $target->plan_id, (int) $source->id
        ));
        if ($width > 0 && $child_count >= $width) {
            $payload['block_reason'] = sprintf(
                /* translators: 1: current children, 2: plan width */
                __('Target already has %1$d children (max width: %2$d). Free up a slot first.', 'matrix-mlm'),
                $child_count,
                $width
            );
            return $payload;
        }

        // All checks passed — green-light the commit.
        $payload['safe_to_move'] = true;
        return $payload;
    }

    /**
     * Inline JS for the admin Export buttons (PDF + PNG) plus the
     * auto-print trigger for ?print=1 mode.
     *
     * Mirrors the user-side share/export panel's export logic
     * (lazy-load html2canvas from a CDN on first PNG click; auto
     * fire window.print() in print mode). Lives on the admin page
     * directly because the admin genealogy view doesn't reuse the
     * member-side panel's class — the two surfaces have different
     * top-of-page tooling and we'd rather keep their JS close to
     * the markup that triggers it than DRY-it-up via a shared
     * helper neither team would think to look in.
     *
     * @param bool $print_mode True when ?print=1 is in the URL —
     *                         we then auto-fire window.print() on
     *                         load so the URL is a one-click
     *                         save-as-PDF interaction.
     */
    private function render_export_script($print_mode) {
        ?>
        <script>
        (function() {
            var btn = document.getElementById('mma-tree-export-png');
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

            if (btn) {
                btn.addEventListener('click', function() {
                    var tree = document.querySelector('.mma-tree-wrapper');
                    if (!tree) {
                        alert('<?php echo esc_js(__('No tree to export.', 'matrix-mlm')); ?>');
                        return;
                    }
                    var origLabel = btn.textContent;
                    btn.disabled = true;
                    btn.textContent = '<?php echo esc_js(__('Capturing…', 'matrix-mlm')); ?>';

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
                        alert('<?php echo esc_js(__('PNG export unavailable. Use the PDF export instead.', 'matrix-mlm')); ?>');
                    }).then(function() {
                        btn.disabled = false;
                        btn.textContent = origLabel;
                    });
                });
            }

            <?php if ($print_mode): ?>
            // Auto-fire print dialog so the ?print=1 URL is a
            // single-interaction "save as PDF" flow.
            window.addEventListener('load', function() {
                setTimeout(function() { window.print(); }, 300);
            });
            <?php endif; ?>
        })();
        </script>
        <?php
    }

    /**
     * Walk parent_id up from $check_position_id and return true if
     * we hit $ancestor_position_id along the way.
     *
     * Local copy of the same algorithm Matrix_MLM_Admin uses
     * privately for the same check at commit time. Duplicated
     * (rather than threaded through a shared util) so the preview
     * surface stays self-contained — when commit-time validation
     * picks up a new constraint, the preview class is the obvious
     * single place to mirror it.
     */
    private static function is_descendant_of_position($check_position_id, $ancestor_position_id, $plan_id) {
        global $wpdb;
        $current_id = (int) $check_position_id;
        $depth      = 0;
        while ($current_id > 0 && $depth < self::PREVIEW_MAX_DEPTH) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, parent_id
                   FROM {$wpdb->prefix}matrix_positions
                  WHERE id = %d AND plan_id = %d",
                $current_id, (int) $plan_id
            ));
            if (!$row) {
                return false;
            }
            if ((int) $row->parent_id === (int) $ancestor_position_id) {
                return true;
            }
            $current_id = (int) $row->parent_id;
            $depth++;
        }
        return false;
    }
}
