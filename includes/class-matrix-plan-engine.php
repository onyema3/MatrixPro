<?php
/**
 * Matrix Plan Engine
 * Handles matrix structures: 2x3, 3x3, 5x5, 4x7, 5x7, 3x9, 2x12
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Plan_Engine {

    /**
     * Supported matrix dimensions [width x depth]
     */
    const SUPPORTED_PLANS = [
        '2x3'  => ['width' => 2, 'depth' => 3],
        '3x3'  => ['width' => 3, 'depth' => 3],
        '5x5'  => ['width' => 5, 'depth' => 5],
        '4x7'  => ['width' => 4, 'depth' => 7],
        '5x7'  => ['width' => 5, 'depth' => 7],
        '3x9'  => ['width' => 3, 'depth' => 9],
        '2x12' => ['width' => 2, 'depth' => 12],
    ];

    /**
     * Join a matrix plan
     */
    public function join_plan($user_id, $plan_id, $use_wallet = true) {
        global $wpdb;

        $plan = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_plans WHERE id = %d AND status = 'active'",
            $plan_id
        ));

        if (!$plan) {
            return ['success' => false, 'message' => __('Plan not found or inactive', 'matrix-mlm')];
        }

        // Check if user already has active position in this plan
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}matrix_positions WHERE user_id = %d AND plan_id = %d AND status = 'active'",
            $user_id, $plan_id
        ));

        if ($existing) {
            return ['success' => false, 'message' => __('You already have an active position in this plan', 'matrix-mlm')];
        }

        // Check balance
        $wallet = new Matrix_MLM_Wallet();
        $balance = $wallet->get_balance($user_id);
        $total_cost = $plan->price + $plan->joining_fee;

        if ($use_wallet && $balance < $total_cost) {
            return ['success' => false, 'message' => __('Insufficient balance. Please deposit funds first.', 'matrix-mlm')];
        }

        // Debit wallet
        if ($use_wallet) {
            $wallet->debit($user_id, $total_cost, 'plan_purchase', sprintf(__('Joined plan: %s', 'matrix-mlm'), $plan->name));
        }

        // Find placement position
        $user_meta = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_user_meta WHERE user_id = %d",
            $user_id
        ));

        $sponsor_id = $user_meta ? $user_meta->referred_by : null;
        $placement = $this->find_placement($plan_id, $plan->width, $sponsor_id);

        // Create position
        $wpdb->insert($wpdb->prefix . 'matrix_positions', [
            'user_id' => $user_id,
            'plan_id' => $plan_id,
            'sponsor_id' => $sponsor_id,
            'parent_id' => $placement['parent_id'],
            'position' => $placement['position'],
            'level' => $placement['level'],
            'status' => 'active'
        ]);

        $position_id = $wpdb->insert_id;

        // Update parent counts
        $this->update_upline_counts($placement['parent_id'], $plan_id);

        // Pay referral commission
        if ($sponsor_id) {
            $this->pay_referral_commission($sponsor_id, $user_id, $plan);
        }

        // Pay level commissions to upline
        $this->pay_level_commissions($user_id, $plan);

        // Check for matrix completion
        $this->check_matrix_completion($plan_id, $plan->width, $plan->depth);

        return [
            'success' => true,
            'message' => sprintf(__('Successfully joined %s plan!', 'matrix-mlm'), $plan->name),
            'position_id' => $position_id
        ];
    }

    /**
     * Find the next available placement in the matrix tree (BFS - left to right, top to bottom)
     */
    private function find_placement($plan_id, $width, $sponsor_id = null) {
        global $wpdb;

        // If sponsor exists, try to place under sponsor's tree first
        if ($sponsor_id) {
            $sponsor_position = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}matrix_positions WHERE user_id = %d AND plan_id = %d AND status = 'active'",
                $sponsor_id, $plan_id
            ));

            if ($sponsor_position) {
                $placement = $this->find_open_spot_bfs($sponsor_position->id, $plan_id, $width);
                if ($placement) {
                    return $placement;
                }
            }
        }

        // Find root position (first position in this plan)
        $root = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_positions WHERE plan_id = %d AND parent_id IS NULL ORDER BY id ASC LIMIT 1",
            $plan_id
        ));

        if (!$root) {
            // This is the first person in the plan (root)
            return [
                'parent_id' => null,
                'position' => 0,
                'level' => 1
            ];
        }

        // BFS from root to find open spot
        $placement = $this->find_open_spot_bfs($root->id, $plan_id, $width);
        if ($placement) {
            return $placement;
        }

        // Fallback: place as new root tree (shouldn't normally happen)
        return [
            'parent_id' => null,
            'position' => 0,
            'level' => 1
        ];
    }

    /**
     * BFS traversal to find first open spot
     */
    private function find_open_spot_bfs($start_id, $plan_id, $width) {
        global $wpdb;

        $queue = [$start_id];

        while (!empty($queue)) {
            $current_id = array_shift($queue);

            // Get children of current node
            $children = $wpdb->get_results($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}matrix_positions WHERE parent_id = %d AND plan_id = %d ORDER BY position ASC",
                $current_id, $plan_id
            ));

            $child_count = count($children);

            // If current node has less than width children, place here
            if ($child_count < $width) {
                $parent = $wpdb->get_row($wpdb->prepare(
                    "SELECT level FROM {$wpdb->prefix}matrix_positions WHERE id = %d",
                    $current_id
                ));

                return [
                    'parent_id' => $current_id,
                    'position' => $child_count,
                    'level' => ($parent->level ?? 0) + 1
                ];
            }

            // Add children to queue
            foreach ($children as $child) {
                $queue[] = $child->id;
            }
        }

        return null;
    }

    /**
     * Update upline member counts
     */
    private function update_upline_counts($parent_id, $plan_id) {
        global $wpdb;

        while ($parent_id) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}matrix_positions SET total_downline = total_downline + 1 WHERE id = %d",
                $parent_id
            ));

            $parent = $wpdb->get_row($wpdb->prepare(
                "SELECT parent_id FROM {$wpdb->prefix}matrix_positions WHERE id = %d",
                $parent_id
            ));

            $parent_id = $parent ? $parent->parent_id : null;
        }
    }

    /**
     * Pay referral commission to direct sponsor
     */
    private function pay_referral_commission($sponsor_id, $from_user_id, $plan) {
        if ($plan->referral_commission <= 0) {
            return;
        }

        $wallet = new Matrix_MLM_Wallet();
        $wallet->credit(
            $sponsor_id,
            $plan->referral_commission,
            'referral_commission',
            sprintf(__('Referral commission from user #%d for plan %s', 'matrix-mlm'), $from_user_id, $plan->name)
        );

        // Record commission
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'matrix_commissions', [
            'user_id' => $sponsor_id,
            'from_user_id' => $from_user_id,
            'plan_id' => $plan->id,
            'type' => 'referral',
            'level' => 0,
            'amount' => $plan->referral_commission,
            'status' => 'paid'
        ]);

        // Send notification
        Matrix_MLM_Notifications::send_commission_notification($sponsor_id, $plan->referral_commission, 'referral');
    }

    /**
     * Pay level commissions to upline members
     */
    private function pay_level_commissions($user_id, $plan) {
        global $wpdb;

        $level_commissions = json_decode($plan->level_commission, true);
        if (empty($level_commissions)) {
            return;
        }

        // Get position of new user
        $position = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_positions WHERE user_id = %d AND plan_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1",
            $user_id, $plan->id
        ));

        if (!$position) {
            return;
        }

        // Traverse up the tree and pay commissions
        $current_parent_id = $position->parent_id;
        $current_level = 1;

        while ($current_parent_id && isset($level_commissions[$current_level])) {
            $parent_position = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}matrix_positions WHERE id = %d",
                $current_parent_id
            ));

            if (!$parent_position) {
                break;
            }

            $commission_amount = floatval($level_commissions[$current_level]);
            if ($commission_amount > 0) {
                $wallet = new Matrix_MLM_Wallet();
                $wallet->credit(
                    $parent_position->user_id,
                    $commission_amount,
                    'level_commission',
                    sprintf(__('Level %d commission from plan %s', 'matrix-mlm'), $current_level, $plan->name)
                );

                $wpdb->insert($wpdb->prefix . 'matrix_commissions', [
                    'user_id' => $parent_position->user_id,
                    'from_user_id' => $user_id,
                    'plan_id' => $plan->id,
                    'type' => 'level',
                    'level' => $current_level,
                    'amount' => $commission_amount,
                    'status' => 'paid'
                ]);

                Matrix_MLM_Notifications::send_commission_notification($parent_position->user_id, $commission_amount, 'level');
            }

            $current_parent_id = $parent_position->parent_id;
            $current_level++;
        }
    }

    /**
     * Check if any matrix has been completed (all positions filled)
     */
    private function check_matrix_completion($plan_id, $width, $depth) {
        global $wpdb;

        // Calculate max members for a complete matrix
        $max_members = 0;
        for ($i = 0; $i < $depth; $i++) {
            $max_members += pow($width, $i);
        }

        // Find positions that might be complete (root positions with enough downline)
        $potential_completions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_positions WHERE plan_id = %d AND status = 'active' AND total_downline >= %d",
            $plan_id, $max_members - 1
        ));

        foreach ($potential_completions as $position) {
            // Verify full completion by checking tree structure
            if ($this->verify_matrix_complete($position->id, $plan_id, $width, $depth)) {
                $this->complete_matrix($position, $plan_id);
            }
        }
    }

    /**
     * Verify if a matrix tree is fully complete
     */
    private function verify_matrix_complete($position_id, $plan_id, $width, $depth) {
        global $wpdb;

        $queue = [['id' => $position_id, 'level' => 1]];
        $total = 1;

        while (!empty($queue)) {
            $current = array_shift($queue);

            if ($current['level'] >= $depth) {
                continue;
            }

            $children = $wpdb->get_results($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}matrix_positions WHERE parent_id = %d AND plan_id = %d AND status = 'active'",
                $current['id'], $plan_id
            ));

            if (count($children) < $width) {
                return false;
            }

            foreach ($children as $child) {
                $queue[] = ['id' => $child->id, 'level' => $current['level'] + 1];
                $total++;
            }
        }

        return true;
    }

    /**
     * Handle matrix completion - pay bonus and re-enter
     */
    private function complete_matrix($position, $plan_id) {
        global $wpdb;

        $plan = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_plans WHERE id = %d",
            $plan_id
        ));

        if (!$plan || $plan->matrix_completion_bonus <= 0) {
            return;
        }

        // Pay completion bonus
        $wallet = new Matrix_MLM_Wallet();
        $wallet->credit(
            $position->user_id,
            $plan->matrix_completion_bonus,
            'matrix_completion',
            sprintf(__('Matrix completion bonus for plan %s', 'matrix-mlm'), $plan->name)
        );

        // Record commission
        $wpdb->insert($wpdb->prefix . 'matrix_commissions', [
            'user_id' => $position->user_id,
            'from_user_id' => $position->user_id,
            'plan_id' => $plan_id,
            'type' => 'matrix_completion',
            'level' => 0,
            'amount' => $plan->matrix_completion_bonus,
            'status' => 'paid'
        ]);

        // Mark position as completed
        $wpdb->update(
            $wpdb->prefix . 'matrix_positions',
            ['status' => 'completed', 'completed_at' => current_time('mysql')],
            ['id' => $position->id]
        );

        // Send notification
        Matrix_MLM_Notifications::send_commission_notification($position->user_id, $plan->matrix_completion_bonus, 'matrix_completion');
    }

    /**
     * Get matrix tree for display
     */
    public function get_matrix_tree($user_id, $plan_id, $max_depth = null) {
        global $wpdb;

        $position = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_positions WHERE user_id = %d AND plan_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1",
            $user_id, $plan_id
        ));

        if (!$position) {
            return null;
        }

        $plan = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_plans WHERE id = %d",
            $plan_id
        ));

        if ($max_depth === null) {
            $max_depth = $plan->depth;
        }

        return $this->build_tree_recursive($position->id, $plan_id, 1, $max_depth);
    }

    /**
     * Build tree recursively
     */
    private function build_tree_recursive($position_id, $plan_id, $current_depth, $max_depth) {
        global $wpdb;

        if ($current_depth > $max_depth) {
            return null;
        }

        $position = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, u.user_login, u.user_email FROM {$wpdb->prefix}matrix_positions p 
             LEFT JOIN {$wpdb->users} u ON p.user_id = u.ID 
             WHERE p.id = %d",
            $position_id
        ));

        if (!$position) {
            return null;
        }

        $children = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}matrix_positions WHERE parent_id = %d AND plan_id = %d ORDER BY position ASC",
            $position_id, $plan_id
        ));

        $node = [
            'id' => $position->id,
            'user_id' => $position->user_id,
            'username' => $position->user_login,
            'level' => $current_depth,
            'total_downline' => $position->total_downline,
            'status' => $position->status,
            'children' => []
        ];

        foreach ($children as $child) {
            $child_node = $this->build_tree_recursive($child->id, $plan_id, $current_depth + 1, $max_depth);
            if ($child_node) {
                $node['children'][] = $child_node;
            }
        }

        return $node;
    }

    /**
     * Get plan statistics
     */
    public function get_plan_stats($plan_id) {
        global $wpdb;

        $plan = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_plans WHERE id = %d",
            $plan_id
        ));

        if (!$plan) {
            return null;
        }

        $total_members = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}matrix_positions WHERE plan_id = %d AND status = 'active'",
            $plan_id
        ));

        $total_completed = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}matrix_positions WHERE plan_id = %d AND status = 'completed'",
            $plan_id
        ));

        $total_commissions = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_commissions WHERE plan_id = %d AND status = 'paid'",
            $plan_id
        ));

        // Max members calculation
        $max_members = 0;
        for ($i = 0; $i < $plan->depth; $i++) {
            $max_members += pow($plan->width, $i);
        }

        return [
            'plan' => $plan,
            'total_members' => $total_members,
            'total_completed' => $total_completed,
            'total_commissions' => $total_commissions,
            'max_members_per_matrix' => $max_members,
            'fill_percentage' => $max_members > 0 ? round(($total_members / $max_members) * 100, 2) : 0,
        ];
    }

    /**
     * Get all plans
     */
    public function get_plans($status = 'active') {
        global $wpdb;

        if ($status === 'all') {
            return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}matrix_plans ORDER BY price ASC");
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_plans WHERE status = %s ORDER BY price ASC",
            $status
        ));
    }
}
