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

        // Get tree data (limit to 4 levels for performance)
        $plan_engine = new Matrix_MLM_Plan_Engine();
        $tree = $plan_engine->get_matrix_tree($user_id, $selected_plan_id, min(4, $current_position->depth));
        $max_members = Matrix_MLM_Plan_Engine::calculate_max_members($current_position->width, $current_position->depth);
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

        <div class="matrix-genealogy-wrapper">
            <div class="matrix-genealogy-tree" id="genealogy-tree">
                <?php if ($tree): ?>
                    <?php $this->render_tree_node($tree, $current_position->width); ?>
                <?php else: ?>
                    <div class="matrix-alert matrix-alert-info"><?php _e('No tree data available yet.', 'matrix-mlm'); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="matrix-genealogy-legend">
            <span class="legend-item"><span class="legend-dot legend-you"></span> <?php _e('You', 'matrix-mlm'); ?></span>
            <span class="legend-item"><span class="legend-dot legend-active"></span> <?php _e('Active Member', 'matrix-mlm'); ?></span>
            <span class="legend-item"><span class="legend-dot legend-empty"></span> <?php _e('Empty Slot', 'matrix-mlm'); ?></span>
        </div>

        <?php endif; ?>
        <?php
    }

    /**
     * Render a tree node recursively as HTML
     */
    private function render_tree_node($node, $width, $is_root = true) {
        if (!$node) return;

        $is_current_user = ($node['user_id'] == get_current_user_id());
        $node_class = $is_current_user ? 'matrix-tree-node-you' : 'matrix-tree-node-active';
        ?>
        <div class="matrix-tree-item">
            <div class="matrix-tree-node <?php echo $node_class; ?>">
                <div class="tree-node-avatar">
                    <?php echo get_avatar($node['user_id'], 36); ?>
                </div>
                <div class="tree-node-info">
                    <strong><?php echo esc_html($node['username'] ?? 'User #' . $node['user_id']); ?></strong>
                    <small><?php printf(__('Level %d', 'matrix-mlm'), $node['level']); ?> &bull; <?php printf(__('%d downline', 'matrix-mlm'), $node['total_downline']); ?></small>
                </div>
            </div>
            <?php if (!empty($node['children']) || $node['level'] < 4): ?>
            <div class="matrix-tree-children">
                <?php
                $child_count = count($node['children'] ?? []);
                // Render existing children
                if (!empty($node['children'])) {
                    foreach ($node['children'] as $child) {
                        $this->render_tree_node($child, $width, false);
                    }
                }
                // Render empty slots
                $empty_slots = $width - $child_count;
                for ($i = 0; $i < $empty_slots; $i++) {
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
                ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
