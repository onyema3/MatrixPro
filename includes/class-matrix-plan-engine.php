<?php
/**
 * Matrix Plan Engine
 * Handles any matrix structure: admin configurable (e.g., 1x2, 2x3, 3x9, 5x10, etc.)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Plan_Engine {

    /**
     * Get the default matrix dimensions from admin settings.
     * Falls back to 2x3 if not configured.
     */
    public static function get_default_matrix() {
        return [
            'width' => intval(get_option('matrix_mlm_default_width', 2)),
            'depth' => intval(get_option('matrix_mlm_default_depth', 3)),
        ];
    }

    /**
     * Calculate total positions in a complete matrix
     */
    public static function calculate_max_members($width, $depth) {
        if ($width <= 0 || $depth <= 0) {
            return 0;
        }
        $total = 0;
        for ($i = 0; $i < $depth; $i++) {
            $total += pow($width, $i);
        }
        return $total;
    }

    /**
     * Calculate positions at a specific level
     */
    public static function calculate_level_positions($width, $level) {
        if ($width <= 0 || $level < 1) {
            return 0;
        }
        return pow($width, $level - 1);
    }

    /**
     * Validate matrix dimensions
     */
    public static function validate_matrix($width, $depth) {
        $errors = [];

        if ($width < 1) {
            $errors[] = __('Matrix width must be at least 1.', 'matrix-mlm');
        }
        if ($width > 20) {
            $errors[] = __('Matrix width cannot exceed 20.', 'matrix-mlm');
        }
        if ($depth < 1) {
            $errors[] = __('Matrix depth must be at least 1.', 'matrix-mlm');
        }
        if ($depth > 20) {
            $errors[] = __('Matrix depth cannot exceed 20.', 'matrix-mlm');
        }

        // Warn about extremely large matrices
        $max_members = self::calculate_max_members($width, $depth);
        if ($max_members > 10000000) {
            $errors[] = sprintf(__('Warning: This matrix configuration would require %s members to complete. Consider using a smaller width or depth.', 'matrix-mlm'), number_format($max_members));
        }

        return $errors;
    }

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

        // Record any level-completion milestones unlocked by this
        // placement and fire the email-side notification for each new
        // milestone. Runs *before* check_matrix_completion() because
        // the deepest level becoming complete should record both a
        // level-D milestone (this method) and the whole-matrix
        // completion bonus (the next call) — they're independent
        // events with independent persistence and independent
        // notification surfaces.
        $this->record_level_completions($placement, $plan);

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
     * Record per-level completion milestones unlocked by a placement,
     * and fire the email-side notification for each newly recorded
     * milestone. Called from join_plan() right after the new position
     * has been inserted and the upline counts/commissions have been
     * paid.
     *
     * "A level is complete" = every one of the W^L positions at depth L
     * below an ancestor is filled by an active member (same definition
     * used by verify_matrix_complete() and the badge panel). When a new
     * member is placed, only one level changes for each ancestor — the
     * level matching the relative depth between that ancestor and the
     * new placement. So for each ancestor walking up the chain we ask
     * exactly one question: "did *this* level you owned just hit its
     * W^L target?".
     *
     * Idempotency is enforced at the storage layer via the
     * (user_id, plan_id, level) UNIQUE key on
     * wp_matrix_level_completions. We INSERT IGNORE and only fire the
     * email when $wpdb->insert returns a positive row count — this
     * means later joiners can't re-trigger the notification once the
     * milestone has been recorded, even if the level remains complete
     * forever.
     *
     * Walks the parent chain (not the sponsor chain), since matrix
     * placement can spill recruits past their direct sponsor and we
     * want to credit the ancestor whose tree actually fills, not the
     * ancestor who recruited the joiner.
     *
     * @param array  $placement Output of self::find_placement() — must
     *                          carry 'parent_id' and 'level' keys; the
     *                          new position's depth in the global tree.
     * @param object $plan      Row from wp_matrix_plans (provides id,
     *                          width, depth, name).
     * @return void
     */
    private function record_level_completions($placement, $plan) {
        global $wpdb;

        $parent_id = isset($placement['parent_id']) ? (int) $placement['parent_id'] : 0;
        $new_level = isset($placement['level'])     ? (int) $placement['level']     : 0;
        if ($parent_id <= 0 || $new_level <= 0 || empty($plan)) {
            // No ancestor (we just inserted the very first position in
            // this plan) or malformed placement — nothing to score.
            return;
        }

        $width = (int) $plan->width;
        $depth = (int) $plan->depth;
        if ($width < 1 || $depth < 1) {
            return;
        }

        $plan_id  = (int) $plan->id;
        $table    = $wpdb->prefix . 'matrix_level_completions';

        // Walk the placement's parent chain (not the sponsor chain —
        // see method docblock). Cap the walk at the plan's depth so
        // that an inconsistent tree (deeper than the plan claims)
        // can't run away with this loop.
        $current_id = $parent_id;
        $hops       = 0;

        while ($current_id && $hops <= $depth) {
            $ancestor = $wpdb->get_row($wpdb->prepare(
                "SELECT id, user_id, parent_id, level
                   FROM {$wpdb->prefix}matrix_positions
                  WHERE id = %d
                    AND plan_id = %d",
                $current_id, $plan_id
            ));
            if (!$ancestor) {
                break;
            }

            // Relative depth of the new placement under this ancestor.
            // Always positive because the new placement is strictly
            // deeper than any ancestor on its parent chain. Stops
            // looking past the plan's configured depth — anything past
            // that isn't a recordable level for this plan.
            $relative_level = $new_level - (int) $ancestor->level;
            if ($relative_level < 1 || $relative_level > $depth) {
                $current_id = $ancestor->parent_id;
                $hops++;
                continue;
            }

            $expected = (int) pow($width, $relative_level);
            $filled   = (int) $this->count_descendants_at_level(
                (int) $ancestor->id, $plan_id, $relative_level
            );

            if ($filled >= $expected) {
                // INSERT IGNORE keeps this idempotent: a subsequent
                // joiner who lands under the same ancestor at the
                // same relative level can't re-create the row.
                $now = current_time('mysql');
                $rows = $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO {$table}
                        (user_id, plan_id, position_id, level, completed_at, email_sent_at)
                     VALUES (%d, %d, %d, %d, %s, %s)",
                    (int) $ancestor->user_id,
                    $plan_id,
                    (int) $ancestor->id,
                    $relative_level,
                    $now,
                    $now
                ));

                if ((int) $rows > 0) {
                    // Fresh milestone — email goes out now. Toast is
                    // surfaced on the user's next dashboard pageload
                    // (see Matrix_MLM_User_Dashboard); we only stamp
                    // email_sent_at here because the email is the only
                    // synchronous channel.
                    //
                    // No "is final" / "Matrix Master" framing here on
                    // purpose: the engine's existing complete_matrix()
                    // already fires its own commission_notification of
                    // type 'matrix_completion' when the whole matrix
                    // is filled, and that notification is the
                    // legitimate "you finished the matrix" message
                    // (it's paired with the wallet credit for the
                    // matrix_completion_bonus). Adding a second
                    // "Matrix Master" hand-wave here would compete
                    // with it. Per-level emails stay scoped to "you
                    // completed level N", and the deepest level just
                    // happens to coincide with the matrix-completion
                    // event for the root — at which point the user
                    // gets two emails (level milestone + completion
                    // bonus), which is the correct behaviour because
                    // they ARE two distinct events.
                    Matrix_MLM_Notifications::send_level_completion_notification(
                        (int) $ancestor->user_id,
                        $plan,
                        $relative_level,
                        $width
                    );
                }
            }

            $current_id = $ancestor->parent_id;
            $hops++;
        }
    }

    /**
     * Count how many active descendants of $position_id sit at exactly
     * $target_level depth below it in $plan_id's tree. Uses the same
     * level-by-level BFS as get_level_completion_status() but stops
     * the moment it has the count it needs — record_level_completions()
     * only needs one level per ancestor, so descending past it would
     * be wasted DB work.
     *
     * Returns 0 for any non-positive target level (nothing to count
     * above the ancestor itself).
     */
    private function count_descendants_at_level($position_id, $plan_id, $target_level) {
        if ($target_level < 1) {
            return 0;
        }
        global $wpdb;
        $frontier = [(int) $position_id];
        for ($L = 1; $L <= $target_level; $L++) {
            if (empty($frontier)) {
                return 0;
            }
            $placeholders = implode(',', array_fill(0, count($frontier), '%d'));
            $params       = array_map('intval', $frontier);
            $params[]     = (int) $plan_id;
            $children = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}matrix_positions
                  WHERE parent_id IN ($placeholders)
                    AND plan_id = %d
                    AND status = 'active'",
                $params
            ));
            if ($L === $target_level) {
                return count($children);
            }
            $frontier = array_map('intval', $children);
        }
        return 0;
    }

    /**
     * Fetch level-completion rows whose toast_seen_at is still NULL
     * for a given user. Used by the dashboard to surface a one-time
     * toast on the user's next pageload after a level is reached.
     * Returns rows enriched with the plan's width/depth/name so the
     * caller doesn't need a second query to render the toast copy.
     *
     * @param int $user_id
     * @param int $limit  Hard ceiling on how many toasts to render in
     *                    one pageload (avoids a wall of 20 toasts on
     *                    a member who just blew through several levels
     *                    while logged out).
     * @return array<int, object>
     */
    public static function get_unread_level_completions($user_id, $limit = 5) {
        global $wpdb;
        $user_id = (int) $user_id;
        $limit   = max(1, min(20, (int) $limit));
        if ($user_id <= 0) {
            return [];
        }
        $table = $wpdb->prefix . 'matrix_level_completions';
        // Defensive: if the table is missing on this install (e.g. a
        // freshly-cloned site whose maybe_upgrade hasn't run yet),
        // bail rather than throw a SQL error that would blank the
        // dashboard.
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME, $table
        ));
        if ($exists <= 0) {
            return [];
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT lc.*, pl.name AS plan_name, pl.width AS plan_width, pl.depth AS plan_depth
               FROM {$table} lc
               LEFT JOIN {$wpdb->prefix}matrix_plans pl ON pl.id = lc.plan_id
              WHERE lc.user_id = %d
                AND lc.toast_seen_at IS NULL
              ORDER BY lc.completed_at ASC
              LIMIT %d",
            $user_id, $limit
        ));
    }

    /**
     * Mark a list of level-completion rows as toast-seen. Stamps
     * toast_seen_at with the current MySQL time so a row can never
     * resurface as an unread toast on the next pageload, even after
     * a session restore. Silently no-ops on an empty id list.
     */
    public static function mark_level_completions_seen(array $ids) {
        global $wpdb;
        $ids = array_filter(array_map('intval', $ids), function ($v) { return $v > 0; });
        if (empty($ids)) {
            return;
        }
        $table        = $wpdb->prefix . 'matrix_level_completions';
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $params       = $ids;
        array_unshift($params, current_time('mysql'));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET toast_seen_at = %s
              WHERE id IN ($placeholders)
                AND toast_seen_at IS NULL",
            $params
        ));
    }

    /**
     * Check if any matrix has been completed (all positions filled)
     */
    private function check_matrix_completion($plan_id, $width, $depth) {
        global $wpdb;

        // Calculate max members for a complete matrix
        $max_members = self::calculate_max_members($width, $depth);

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
     * Compute per-level fill status for the subtree rooted at $position_id.
     *
     * In a forced W×D matrix the canonical capacity at depth L (relative to the
     * subtree root, with the root itself at depth 0) is W^L positions. A level
     * counts as "complete" when every one of those W^L slots is occupied by an
     * active member. This helper returns one entry per level 1..D in ascending
     * order so callers can render a badge per level — the "level completion
     * badge" affordance — without having to walk the tree themselves.
     *
     * Implementation note: a single BFS pass collects the level frontier, then
     * one COUNT(*) per level resolves how many of those frontier nodes have
     * the expected number of children. We count via the parent_id IN (...)
     * predicate so the work scales with the *frontier* size (worst case W^L)
     * rather than with the global table size — and the parent_id index on
     * matrix_positions makes that lookup cheap. Status is intentionally
     * filtered to 'active' to match verify_matrix_complete()'s definition of
     * a "filled" slot; positions marked 'completed' or 'inactive' don't
     * count, which keeps the badge in sync with the completion-bonus logic.
     *
     * The early-exit on the first level that has fewer than W^L positions
     * matters for big matrices: if level 2 isn't full, level 3 cannot be full
     * either, so we mark every deeper level as 'in progress' with zero filled
     * and stop querying. Saves one DB round trip per uncompleted deep level
     * on the common case where the user is still building out the upper
     * levels of their downline.
     *
     * @param int $position_id Subtree root position id (the user's own
     *                         position in the plan).
     * @param int $plan_id     Plan id (used as a sanity filter so a stray
     *                         position that's been re-parented across plans
     *                         can't poison the count).
     * @param int $width       Matrix width — the W in W^L.
     * @param int $depth       Matrix depth — number of levels to enumerate.
     * @return array<int, array{level:int, filled:int, expected:int, complete:bool}>
     *         Indexed 1..depth in ascending level order.
     */
    public function get_level_completion_status($position_id, $plan_id, $width, $depth) {
        global $wpdb;

        $position_id = (int) $position_id;
        $plan_id     = (int) $plan_id;
        $width       = max(1, (int) $width);
        $depth       = max(1, (int) $depth);

        $status = [];
        // The "frontier" is the set of position IDs at the current depth
        // whose children form the next level. We start with the subtree
        // root (depth 0) and descend one level per iteration.
        $frontier = [$position_id];

        for ($level = 1; $level <= $depth; $level++) {
            $expected = (int) pow($width, $level);

            if (empty($frontier)) {
                // Parent level had zero filled slots, so this level can't
                // have any either. Record it as in-progress and skip the
                // DB round trip.
                $status[$level] = [
                    'level'    => $level,
                    'filled'   => 0,
                    'expected' => $expected,
                    'complete' => false,
                ];
                continue;
            }

            // %d-only IN clause: every value comes from $wpdb->get_col on
            // the previous iteration (or the typed $position_id seed), so
            // it's safe to interpolate after intval-ing each element.
            $placeholders = implode(',', array_fill(0, count($frontier), '%d'));
            $params       = array_map('intval', $frontier);
            $params[]     = $plan_id;

            $children = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}matrix_positions
                  WHERE parent_id IN ($placeholders)
                    AND plan_id = %d
                    AND status = 'active'",
                $params
            ));

            $filled            = count($children);
            $status[$level]    = [
                'level'    => $level,
                'filled'   => $filled,
                'expected' => $expected,
                'complete' => ($filled >= $expected),
            ];

            // Descend if and only if this level is fully built. A partially
            // filled level cannot have any complete children below it, so
            // we let the loop's early-exit branch fill the remaining levels
            // with zeroed-out entries.
            $frontier = ($filled >= $expected) ? array_map('intval', $children) : [];
        }

        return $status;
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
     * Resolve a (user_id, plan_id) pair to the matrix_positions.id of
     * that user's active position in that plan.
     *
     * Lightweight lookup pulled out as its own method because both the
     * click-to-pivot URL handler and the lazy-load AJAX authoriser
     * need it: the pivot flow has to translate the user id in
     * ?pivot_user_id=X to a position id before user_can_view_position()
     * can vet the request, and the AJAX endpoint does the same when
     * validating the root_user_id POST parameter.
     *
     * Returns 0 (rather than null) when the user has no active
     * position in the plan, so callers can keep the int contract and
     * use a single comparator. Status is filtered to 'active' to
     * match every other place the genealogy view treats inactive /
     * completed positions as not-on-the-tree.
     *
     * @param int $user_id  WP user id.
     * @param int $plan_id  Plan id.
     * @return int matrix_positions.id, or 0 when no active position.
     */
    public function get_position_id_for_user_in_plan($user_id, $plan_id) {
        global $wpdb;
        $user_id = (int) $user_id;
        $plan_id = (int) $plan_id;
        if ($user_id <= 0 || $plan_id <= 0) {
            return 0;
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}matrix_positions
              WHERE user_id = %d AND plan_id = %d AND status = 'active'
              ORDER BY id ASC LIMIT 1",
            $user_id, $plan_id
        ));
    }

    /**
     * Compute the breadcrumb path from a viewer down to a pivoted
     * descendant in the genealogy tree.
     *
     * Returns an ordered list of [position_id, user_id, username]
     * entries running from the viewer (first element) to the pivot
     * (last element), inclusive on both ends. The genealogy renderer
     * uses this to draw a clickable trail above the tree when a
     * member has navigated into someone else's subtree — each
     * intermediate crumb is a one-click jump back to that ancestor's
     * view, and the first crumb (the viewer themselves) un-pivots the
     * URL entirely.
     *
     * Implementation walks parent_id up from $pivot_position_id and
     * stops as soon as it lands on a position owned by
     * $viewer_user_id. The capped iteration count (30) matches
     * user_can_view_position()'s ceiling so a corrupted parent chain
     * can't spin forever; in practice every legitimate matrix tops
     * out at depth 20 (the validate_matrix() upper bound).
     *
     * Returns an empty array when the pivot is not in the viewer's
     * downline (for example, a stale URL after an admin moved the
     * pivot user into a different sub-tree). Callers should already
     * have authorised via user_can_view_position() before calling
     * this — an empty return is then strictly a "couldn't render
     * crumbs" defensive path, not a fresh access-control gate.
     *
     * @param int $pivot_position_id matrix_positions.id of the user
     *                                being pivoted onto.
     * @param int $viewer_user_id    WP user id of the actual logged-in
     *                                viewer (the root of the trail).
     * @return array<int, array{position_id:int, user_id:int, username:string}>
     */
    public function build_pivot_breadcrumbs($pivot_position_id, $viewer_user_id) {
        global $wpdb;

        $pivot_position_id = (int) $pivot_position_id;
        $viewer_user_id    = (int) $viewer_user_id;
        if ($pivot_position_id <= 0 || $viewer_user_id <= 0) {
            return [];
        }

        $path       = [];
        $current_id = $pivot_position_id;

        for ($hops = 0; $hops < 30; $hops++) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT p.id, p.user_id, p.parent_id, u.user_login
                   FROM {$wpdb->prefix}matrix_positions p
                   LEFT JOIN {$wpdb->users} u ON p.user_id = u.ID
                  WHERE p.id = %d",
                $current_id
            ));
            if (!$row) {
                // A row went missing between our auth check and now
                // (race with admin Move-in-Genealogy or deletion).
                // Bail with whatever crumbs we already collected — at
                // worst the breadcrumb bar is short, never wrong.
                break;
            }

            $path[] = [
                'position_id' => (int) $row->id,
                'user_id'     => (int) $row->user_id,
                'username'    => (string) ($row->user_login ?: ''),
            ];

            // Reached the viewer's own position — that's the top of
            // the trail. Stop here so we don't keep walking past the
            // viewer into someone else's tree (would only happen on
            // a corrupted chain, but defensive).
            if ((int) $row->user_id === $viewer_user_id) {
                break;
            }

            // Hit the global root without finding the viewer. Should
            // not happen if user_can_view_position() said yes, but
            // bail defensively rather than loop forever.
            if (!$row->parent_id) {
                break;
            }

            $current_id = (int) $row->parent_id;
        }

        // Walk produced the path from pivot up to viewer; flip it so
        // crumb[0] is the viewer and crumb[count-1] is the pivot.
        return array_reverse($path);
    }

    /**
     * Build a partial subtree starting from an arbitrary position id.
     *
     * Public wrapper around the recursive builder that the genealogy
     * "lazy-load deeper levels" flow uses on its expand-button AJAX
     * round-trip. Matches build_tree_recursive's return shape exactly so
     * the rendering layer can reuse the same code path for both the
     * initial server-rendered tree and the on-demand expansions.
     *
     * The two parameters that differ from get_matrix_tree() are:
     *
     *   - $start_depth: the absolute level number the requested position
     *     sits at in the user's tree. Threaded through so each node in
     *     the returned subtree carries the correct 'level' value
     *     (members three levels below the tree root see "Level 5", not
     *     "Level 1"). The renderer relies on this to decide where the
     *     next "Show more" button should appear.
     *
     *   - $max_depth: the deepest absolute level to fetch. The chunked
     *     expansion typically asks for $start_depth + 3, capped at the
     *     plan's depth — three more levels per click keeps each network
     *     round trip under the soft "small payload" budget the rest of
     *     the dashboard targets.
     *
     * Returns null when the requested position doesn't exist or
     * $start_depth > $max_depth (degenerate request from a stale UI).
     *
     * @param int $position_id  matrix_positions.id of the subtree root.
     * @param int $plan_id      Plan id (matches the position's plan_id).
     * @param int $start_depth  Absolute level number for the subtree root.
     * @param int $max_depth    Absolute deepest level to recurse to.
     * @return array|null
     */
    public function build_subtree($position_id, $plan_id, $start_depth, $max_depth) {
        $position_id = (int) $position_id;
        $plan_id     = (int) $plan_id;
        $start_depth = max(1, (int) $start_depth);
        $max_depth   = (int) $max_depth;

        if ($position_id <= 0 || $plan_id <= 0 || $max_depth < $start_depth) {
            return null;
        }

        return $this->build_tree_recursive($position_id, $plan_id, $start_depth, $max_depth);
    }

    /**
     * Authorize a viewer to see a particular position's subtree.
     *
     * Returns true when $viewer_user_id either OWNS $position_id directly
     * or sits anywhere on its ancestor chain in the same plan. Used by
     * AJAX endpoints that fetch portions of the genealogy tree to make
     * sure a member can't poke around someone else's downline by
     * guessing a position id.
     *
     * Implementation walks parent_id up from the requested position; at
     * each step it asks "is this position owned by the viewer?" and
     * returns true on the first match. The walk is bounded by a
     * defensive depth ceiling — matrix_positions.parent_id is meant to
     * form a strict tree but a corrupted dataset could in theory loop,
     * and we don't want this helper to spin forever for the sake of
     * one auth check.
     *
     * Why the parent-chain walk is the right contract:
     *   - Matches the natural "is this in my downline?" question.
     *   - Reads only the indexed parent_id column, so it costs O(depth)
     *     queries in the worst case (typically <= 9 for a 3x9 plan).
     *   - Doesn't over-trust matrix_positions.total_downline, which is
     *     a denormalised count that any of the tree-recompute paths
     *     could leave temporarily stale. The walk re-derives ancestry
     *     from the structural truth (parent_id), so an out-of-date
     *     total_downline can't grant or deny access incorrectly.
     *
     * @param int $viewer_user_id  WP user id requesting the view.
     * @param int $position_id     matrix_positions.id whose subtree they want.
     * @return bool
     */
    public function user_can_view_position($viewer_user_id, $position_id) {
        global $wpdb;

        $viewer_user_id = (int) $viewer_user_id;
        $position_id    = (int) $position_id;
        if ($viewer_user_id <= 0 || $position_id <= 0) {
            return false;
        }

        // First touch: does the viewer own the requested position
        // outright? Cheaper than walking up so test it first.
        $self = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}matrix_positions WHERE id = %d",
            $position_id
        ));
        if ((int) $self === $viewer_user_id) {
            return true;
        }

        // Climb. Cap the iteration count at the largest plausible
        // matrix depth (any wp_matrix_plans.depth value is validated to
        // <= 20 in self::validate_matrix(), and bumping the ceiling a
        // little gives the cap room to absorb a future schema change
        // without re-tuning).
        $current_id = $position_id;
        for ($hops = 0; $hops < 30; $hops++) {
            $parent_id = $wpdb->get_var($wpdb->prepare(
                "SELECT parent_id FROM {$wpdb->prefix}matrix_positions WHERE id = %d",
                $current_id
            ));
            if (!$parent_id) {
                return false;
            }

            $parent_user_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->prefix}matrix_positions WHERE id = %d",
                (int) $parent_id
            ));
            if ($parent_user_id === $viewer_user_id) {
                return true;
            }

            $current_id = (int) $parent_id;
        }

        // Safety net: a corrupted parent chain that didn't terminate
        // by our hop ceiling. Refuse the lookup rather than risk
        // leaking visibility.
        return false;
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
            // sponsor_id is who actually *referred* this member, as opposed
            // to parent_id which is where they sit structurally in the
            // tree. The two diverge whenever a member was placed via
            // spillover (someone else sponsored them but they ended up
            // under a different upline because the sponsor's slot was
            // full). The genealogy view uses this to badge each node as
            // "Direct" (sponsor_id matches the tree-root user) vs
            // "Spillover" (sponsor_id is some other member). Cast to int
            // so the JS-side / template strict comparisons against the
            // current user id behave consistently — sponsor_id can be
            // NULL on the root and on legacy imported rows where the
            // backfill couldn't resolve a sponsor.
            'sponsor_id' => $position->sponsor_id !== null ? (int) $position->sponsor_id : null,
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
        $max_members = self::calculate_max_members($plan->width, $plan->depth);

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

    /**
     * Get a human-readable matrix type label
     */
    public static function get_matrix_label($width, $depth) {
        $type = '';
        if ($width == 1) {
            $type = __('Unilevel', 'matrix-mlm');
        } elseif ($width == 2) {
            $type = __('Binary', 'matrix-mlm');
        } elseif ($width == 3) {
            $type = __('Ternary', 'matrix-mlm');
        } else {
            $type = sprintf(__('%d-Wide', 'matrix-mlm'), $width);
        }
        return sprintf('%s (%dx%d)', $type, $width, $depth);
    }
}
