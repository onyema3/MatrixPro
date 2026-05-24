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

        // Snapshot the freshly-created position into the audit
        // log. Powers the genealogy "time machine" view —
        // without a 'created' event captured at insert time,
        // the time-machine reconstruction wouldn't know whether
        // a member was in the tree at any given snapshot date
        // earlier than this join. Defensive class_exists() guard
        // covers the (vanishingly rare) case where the autoloader
        // hasn't picked up the helper yet — capture skips
        // silently rather than blocking the join.
        if (class_exists('Matrix_MLM_Position_History')) {
            Matrix_MLM_Position_History::record_event(
                $position_id,
                'created',
                $user_id,
                ''
            );
        }

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

        // Snapshot the status flip into the audit log so the
        // time-machine view shows the position as 'active' on
        // dates before completion and 'completed' afterwards. The
        // 'completed' event_type is the more specific cousin of
        // 'status_changed' — kept distinct because the
        // time-machine UI treats it as a milestone (small trophy
        // marker on the timeline rather than a generic state-change
        // tick). See Matrix_MLM_Position_History::EVENT_TYPES.
        if (class_exists('Matrix_MLM_Position_History')) {
            Matrix_MLM_Position_History::record_event(
                $position->id,
                'completed',
                null, // no human actor — completion is system-triggered
                ''
            );
        }

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
     * Search the viewer's downline for members whose username, email
     * or display name matches a free-text query.
     *
     * Drives the genealogy "search & jump-to-user" affordance: a
     * member types a few characters into the search box above the
     * tree, this method returns up to $limit downline matches, and
     * the JS hands the user a pivot URL for whichever result they
     * pick. The search is intentionally scoped to the viewer's
     * downline within a single plan — searching the whole
     * matrix_positions table would either leak visibility ("oh,
     * @bob exists somewhere on the platform") or require a much
     * bigger auth surface than we want to maintain. Same plan, same
     * downline, same auth gate as ?pivot_user_id=X.
     *
     * Algorithm:
     *   1. Resolve the viewer's own position id in the plan. No
     *      position → empty result (you can't have a downline you
     *      aren't part of).
     *   2. SQL LIKE-search wp_matrix_positions JOIN wp_users for
     *      candidates whose user_login, user_email or display_name
     *      contains the query. Restrict to the same plan and
     *      status='active', and exclude the viewer themselves
     *      (you can't pivot to yourself; the breadcrumb's "Back
     *      to my tree" already covers that). Cap candidates at
     *      $candidate_pool to keep worst-case auth-walk cost
     *      bounded — see step 3.
     *   3. For each candidate, run user_can_view_position() to
     *      confirm the candidate is strictly below the viewer in
     *      the parent_id chain. This is the same authoriser
     *      ?pivot_user_id=X uses on the page render and the
     *      lazy-load AJAX endpoint uses on subtree fetches, so
     *      visibility rules can never diverge across the three
     *      surfaces. Each call is O(depth) and depth is capped at
     *      ~20 by validate_matrix(), so the worst-case work here
     *      is candidate_pool * 30 == 1500 trivial PK lookups —
     *      acceptable for a typeahead because the candidate pool
     *      almost always contains <10 rows in practice (free-text
     *      search across active positions for a 3-char query
     *      typically returns a handful).
     *   4. Stop as soon as we have $limit confirmed matches.
     *
     * Why we don't precompute and cache a "viewer's downline ids"
     * set: the matrix is mutable (admins move members, new sign-ups
     * arrive) and we don't have an invalidation hook for it; doing
     * the auth walk per-candidate keeps correctness obvious without
     * a cache layer to reason about. If search latency ever shows
     * up in monitoring we can revisit with a per-request memo.
     *
     * Returns rows in the order the SQL produced them (best-match
     * first when MySQL's collation honours that, otherwise
     * insertion order). Each row carries enough fields for the JS
     * dropdown to render a useful label (username + display name +
     * level) and for the click handler to navigate without a
     * second roundtrip.
     *
     * @param int    $viewer_user_id  WP user id of the searcher.
     * @param int    $plan_id         Plan whose tree we're searching.
     * @param string $query           Raw free-text query (will be
     *                                 normalised here).
     * @param int    $limit           Max confirmed matches to return.
     *                                 Defaults to 10 — the dropdown
     *                                 above the tree won't usefully
     *                                 show more than that without
     *                                 turning into a scroll.
     * @return array<int, array{
     *     position_id:int,
     *     user_id:int,
     *     username:string,
     *     email:string,
     *     display_name:string,
     *     level:int
     * }>
     */
    public function search_downline_users($viewer_user_id, $plan_id, $query, $limit = 10) {
        global $wpdb;

        $viewer_user_id = (int) $viewer_user_id;
        $plan_id        = (int) $plan_id;
        $limit          = max(1, min(20, (int) $limit));
        $query          = is_string($query) ? trim($query) : '';

        // Single-character searches are too noisy to be useful and
        // would force the auth walk to run against effectively the
        // whole position table for each common-letter request.
        // Two characters is the same minimum the plan's WP user
        // search uses elsewhere.
        if ($viewer_user_id <= 0 || $plan_id <= 0 || mb_strlen($query) < 2) {
            return [];
        }

        // Confirm the viewer actually has a position in this plan
        // before we go any further. If they don't, they have no
        // downline to search and any results would be either zero
        // (waste of a query) or — worse — accidentally accept a
        // candidate the auth walk failed to reject, e.g. via a
        // future bug in user_can_view_position. Belt-and-braces.
        $viewer_position_id = $this->get_position_id_for_user_in_plan($viewer_user_id, $plan_id);
        if ($viewer_position_id <= 0) {
            return [];
        }

        // Pull a bounded candidate pool. We over-fetch (5x the
        // requested limit, capped at 50) so that when the auth walk
        // rejects some candidates we still have headroom to fill
        // $limit slots. 50 is the ceiling because each rejected
        // candidate still costs an auth walk, and the search must
        // feel like a typeahead (sub-300ms typical).
        $candidate_pool = min(50, $limit * 5);

        $like = '%' . $wpdb->esc_like($query) . '%';
        $candidates = $wpdb->get_results($wpdb->prepare(
            "SELECT p.id           AS position_id,
                    p.user_id      AS user_id,
                    p.level        AS level,
                    u.user_login   AS user_login,
                    u.user_email   AS user_email,
                    u.display_name AS display_name
               FROM {$wpdb->prefix}matrix_positions p
               JOIN {$wpdb->users} u ON u.ID = p.user_id
              WHERE p.plan_id = %d
                AND p.status  = 'active'
                AND p.user_id <> %d
                AND (
                        u.user_login   LIKE %s
                     OR u.user_email   LIKE %s
                     OR u.display_name LIKE %s
                )
              ORDER BY
                    CASE WHEN u.user_login   = %s THEN 0
                         WHEN u.user_email   = %s THEN 0
                         WHEN u.display_name = %s THEN 0
                         WHEN u.user_login   LIKE %s THEN 1
                         ELSE 2
                    END,
                    p.level ASC,
                    u.user_login ASC
              LIMIT %d",
            $plan_id,
            $viewer_user_id,
            $like, $like, $like,
            // Exact-match boosters: a search for "alice" should rank
            // user_login='alice' above one whose email merely
            // contains 'alice'.
            $query, $query, $query,
            // Prefix-match (starts-with) gets second priority — a
            // search for "ali" should still surface 'alice' before
            // a 'goalie' whose login happens to contain the
            // substring.
            $wpdb->esc_like($query) . '%',
            $candidate_pool
        ));

        if (empty($candidates)) {
            return [];
        }

        $matches = [];
        foreach ($candidates as $row) {
            // Auth gate: same walk-up check the page render and
            // lazy-load endpoints use. Anything not strictly below
            // the viewer in the parent_id chain is silently dropped
            // — the searcher must not learn that another member
            // exists on the platform unless they're already
            // entitled to see them via the matrix.
            if (!$this->user_can_view_position($viewer_user_id, (int) $row->position_id)) {
                continue;
            }

            $matches[] = [
                'position_id'  => (int) $row->position_id,
                'user_id'      => (int) $row->user_id,
                'username'     => (string) $row->user_login,
                'email'        => (string) $row->user_email,
                'display_name' => (string) $row->display_name,
                'level'        => (int) $row->level,
            ];

            if (count($matches) >= $limit) {
                break;
            }
        }

        return $matches;
    }

    /**
     * Sum the commissions paid to $viewer_user_id that originated from
     * any active member sitting inside the subtree rooted at
     * $branch_root_position_id in $plan_id's tree (the root user
     * themselves included). Powers the genealogy hover-card's
     * "branch commission" line so a member can see, at a glance, how
     * much money a particular sub-leader's tree has produced for them.
     *
     * Why this is correct from the viewer's perspective:
     *   - matrix_commissions.user_id is the *recipient* — i.e. the
     *     person who got paid.
     *   - matrix_commissions.from_user_id is the *trigger* — typically
     *     the new joiner whose plan purchase paid the commission to
     *     someone in their upline.
     *   So "commission earned by the viewer because of this branch"
     *   becomes a single SUM over rows where user_id = viewer AND
     *   from_user_id ∈ {everyone in this branch}. status = 'paid'
     *   filters out anything pending or refunded.
     *
     * Implementation:
     *   1. Seed with the branch root's own user_id (commissions paid
     *      to viewer when this very member joined a plan still count
     *      toward "what this branch earned me").
     *   2. BFS down parent_id collecting active descendant user_ids.
     *      Same level-by-level pattern get_level_completion_status()
     *      and count_descendants_at_level() use, so query cost scales
     *      with frontier size and rides the parent_id index.
     *   3. Hard-cap descendant collection at $hard_cap (default 2000)
     *      so a hover on a member with a 30k-position downline can't
     *      stall the AJAX call. When the cap trips we set 'capped' =
     *      true on the return value so the hover-card can render a
     *      "(branch too large to summarize)" hint instead of a number
     *      that silently undercounts.
     *   4. One final SUM(amount) against matrix_commissions with the
     *      collected user_ids in an IN clause.
     *
     * Authorization is the caller's job — every public surface that
     * exposes this information already runs through
     * user_can_view_position() before getting here, and threading auth
     * into this helper would conflate "compute a number" with
     * "decide if you're allowed to see it". Same separation
     * search_downline_users() and build_pivot_breadcrumbs() use.
     *
     * @param int $viewer_user_id           WP user id whose earnings we're counting.
     * @param int $branch_root_position_id  matrix_positions.id at the top of the branch.
     * @param int $plan_id                  Plan id (sanity filter; descend only inside this plan's tree).
     * @param int $hard_cap                 Max descendants (incl. root) to collect.
     * @return array{amount:float, capped:bool, descendants:int}
     */
    public function sum_branch_commissions_for_viewer($viewer_user_id, $branch_root_position_id, $plan_id, $hard_cap = 2000) {
        global $wpdb;

        $viewer_user_id          = (int) $viewer_user_id;
        $branch_root_position_id = (int) $branch_root_position_id;
        $plan_id                 = (int) $plan_id;
        $hard_cap                = max(1, (int) $hard_cap);

        $empty_result = ['amount' => 0.0, 'capped' => false, 'descendants' => 0];
        if ($viewer_user_id <= 0 || $branch_root_position_id <= 0 || $plan_id <= 0) {
            return $empty_result;
        }

        // Seed with the branch root's user_id. We include the root
        // because if this branch's "head" member triggered a
        // commission to the viewer (e.g. their plan-join referral
        // bonus), that money is part of what the branch earned the
        // viewer.
        $root_user_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}matrix_positions
              WHERE id = %d AND plan_id = %d",
            $branch_root_position_id, $plan_id
        ));
        if ($root_user_id <= 0) {
            return $empty_result;
        }

        $user_ids = [$root_user_id];
        $frontier = [$branch_root_position_id];
        $capped   = false;

        while (!empty($frontier)) {
            if (count($user_ids) >= $hard_cap) {
                $capped = true;
                break;
            }

            // %d-only IN clause: every value in $frontier comes from
            // matrix_positions.id which we cast to int below — safe
            // to interpolate after intval-ing each element.
            $placeholders = implode(',', array_fill(0, count($frontier), '%d'));
            $params       = array_map('intval', $frontier);
            $params[]     = $plan_id;

            $children = $wpdb->get_results($wpdb->prepare(
                "SELECT id, user_id
                   FROM {$wpdb->prefix}matrix_positions
                  WHERE parent_id IN ($placeholders)
                    AND plan_id = %d
                    AND status = 'active'",
                $params
            ));

            if (empty($children)) {
                break;
            }

            $next_frontier = [];
            foreach ($children as $child) {
                $user_ids[]      = (int) $child->user_id;
                $next_frontier[] = (int) $child->id;
                if (count($user_ids) >= $hard_cap) {
                    $capped = true;
                    break 2;
                }
            }
            $frontier = $next_frontier;
        }

        // De-dupe — a member could in theory appear twice if they had
        // multiple positions in the same plan (shouldn't happen given
        // join_plan() enforces uniqueness, but cheap defensive step).
        $user_ids = array_values(array_unique($user_ids));
        if ($capped) {
            // Truncate to the cap so the IN clause stays bounded; the
            // returned 'capped' flag tells the caller this is a
            // floor not a true total.
            $user_ids = array_slice($user_ids, 0, $hard_cap);
        }

        if (empty($user_ids)) {
            return ['amount' => 0.0, 'capped' => $capped, 'descendants' => 0];
        }

        $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
        $params       = array_map('intval', $user_ids);
        array_unshift($params, $viewer_user_id);

        $sum = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0)
               FROM {$wpdb->prefix}matrix_commissions
              WHERE user_id = %d
                AND status = 'paid'
                AND from_user_id IN ($placeholders)",
            $params
        ));

        return [
            'amount'      => $sum,
            'capped'      => $capped,
            'descendants' => count($user_ids),
        ];
    }

    /**
     * Per-position commission attribution for the genealogy "income
     * map" overlay. Same recipient/trigger semantics as
     * sum_branch_commissions_for_viewer() but instead of collapsing
     * the branch into a single number, this returns a map keyed by
     * from_user_id so the D3 view can paint a per-node badge showing
     * exactly how much money each downline member triggered for the
     * viewer.
     *
     * Strategy mirrors sum_branch_commissions_for_viewer() so the two
     * helpers stay in lockstep on edge cases (capping, root inclusion,
     * status filter):
     *
     *   1. BFS from $branch_root_position_id within $plan_id to
     *      collect every descendant's user_id. Root included — if
     *      the branch head themself triggered a referral bonus to
     *      the viewer, it belongs on their card too.
     *   2. ONE GROUP BY query against matrix_commissions filtered by
     *      user_id = $viewer AND status = 'paid' AND from_user_id IN
     *      (...). One round-trip regardless of branch size, which is
     *      the whole point of doing this server-side rather than
     *      asking the client to fan out one query per node.
     *
     * Authorization is the caller's job (see the parallel docblock
     * on sum_branch_commissions_for_viewer for why). The AJAX
     * endpoint that exposes this gates with user_can_view_position
     * before calling in.
     *
     * Empty-amount entries are intentionally NOT included in the
     * returned map — a downline member with zero attributed
     * commission is the absence of a key, not a key with a zero
     * value. Saves bytes on the wire (most members in any sizeable
     * downline contribute zero, so a sparse map is meaningfully
     * smaller) and lets the client decide rendering policy
     * (currently: no badge for non-contributors, which makes the
     * income map "read" — your eye finds the green pills naturally).
     *
     * @param int $viewer_user_id           WP user id of the recipient.
     * @param int $branch_root_position_id  matrix_positions.id at top of branch.
     * @param int $plan_id                  Plan id (sanity filter).
     * @param int $hard_cap                 Max descendants to walk (incl. root).
     * @return array{
     *     attribution: array<int, array{amount:float, count:int}>,
     *     total: array{amount:float, count:int, members:int},
     *     capped: bool,
     *     descendants: int
     * }
     */
    public function get_per_user_commission_attribution($viewer_user_id, $branch_root_position_id, $plan_id, $hard_cap = 5000) {
        global $wpdb;

        $viewer_user_id          = (int) $viewer_user_id;
        $branch_root_position_id = (int) $branch_root_position_id;
        $plan_id                 = (int) $plan_id;
        $hard_cap                = max(1, (int) $hard_cap);

        $empty_result = [
            'attribution' => [],
            'total'       => ['amount' => 0.0, 'count' => 0, 'members' => 0],
            'capped'      => false,
            'descendants' => 0,
        ];
        if ($viewer_user_id <= 0 || $branch_root_position_id <= 0 || $plan_id <= 0) {
            return $empty_result;
        }

        // Seed BFS with the branch root's user (see
        // sum_branch_commissions_for_viewer for why the root is
        // included rather than skipped).
        $root_user_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}matrix_positions
              WHERE id = %d AND plan_id = %d",
            $branch_root_position_id, $plan_id
        ));
        if ($root_user_id <= 0) {
            return $empty_result;
        }

        $user_ids = [$root_user_id];
        $frontier = [$branch_root_position_id];
        $capped   = false;

        while (!empty($frontier)) {
            if (count($user_ids) >= $hard_cap) {
                $capped = true;
                break;
            }

            // %d-only IN clause: every value in $frontier comes from
            // matrix_positions.id which we cast to int below — safe
            // to interpolate after intval-ing each element. Same
            // pattern sum_branch_commissions_for_viewer uses.
            $placeholders = implode(',', array_fill(0, count($frontier), '%d'));
            $params       = array_map('intval', $frontier);
            $params[]     = $plan_id;

            $children = $wpdb->get_results($wpdb->prepare(
                "SELECT id, user_id
                   FROM {$wpdb->prefix}matrix_positions
                  WHERE parent_id IN ($placeholders)
                    AND plan_id = %d
                    AND status = 'active'",
                $params
            ));

            if (empty($children)) {
                break;
            }

            $next_frontier = [];
            foreach ($children as $child) {
                $user_ids[]      = (int) $child->user_id;
                $next_frontier[] = (int) $child->id;
                if (count($user_ids) >= $hard_cap) {
                    $capped = true;
                    break 2;
                }
            }
            $frontier = $next_frontier;
        }

        // De-dupe and bound the IN list (see parent helper for the
        // multi-position-same-member edge case this handles).
        $user_ids = array_values(array_unique($user_ids));
        if ($capped) {
            $user_ids = array_slice($user_ids, 0, $hard_cap);
        }
        if (empty($user_ids)) {
            return $empty_result;
        }

        // Single GROUP BY round-trip. matrix_commissions has KEY
        // user_id and KEY from_user_id, so the planner can satisfy
        // (user_id = X AND from_user_id IN (...)) using either index
        // depending on selectivity — both are cheap. Sum_branch uses
        // a flat SUM here; we group instead so the per-node payload
        // is one row per contributor.
        $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
        $params       = array_map('intval', $user_ids);
        array_unshift($params, $viewer_user_id);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT from_user_id, COUNT(*) AS cnt, SUM(amount) AS total
               FROM {$wpdb->prefix}matrix_commissions
              WHERE user_id = %d
                AND status = 'paid'
                AND from_user_id IN ($placeholders)
              GROUP BY from_user_id",
            $params
        ));

        $attribution   = [];
        $total_amount  = 0.0;
        $total_count   = 0;
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $uid = (int) $row->from_user_id;
                if ($uid <= 0) {
                    continue;
                }
                $amount = (float) $row->total;
                $count  = (int) $row->cnt;
                // GROUP BY shouldn't yield a zero-sum row in
                // practice (all amounts in matrix_commissions are
                // positive by schema), but guard anyway — a future
                // adjustment row with a negative amount could net
                // to zero and we'd want to drop it from the
                // sparse map.
                if ($amount <= 0) {
                    continue;
                }
                $attribution[$uid] = [
                    'amount' => $amount,
                    'count'  => $count,
                ];
                $total_amount += $amount;
                $total_count  += $count;
            }
        }

        return [
            'attribution' => $attribution,
            'total'       => [
                'amount'  => $total_amount,
                'count'   => $total_count,
                'members' => count($attribution),
            ],
            'capped'      => $capped,
            'descendants' => count($user_ids),
        ];
    }

    /**
     * Pick the most-actionable incomplete level for a member's matrix
     * and pair it with the per-slot commission so the genealogy
     * "next goal" banner can quote a concrete earnings number.
     *
     * Selection priority — two-pass:
     *
     *   1. Levels that already have at least one filled slot
     *      (filled > 0, complete = false). Among those, pick the one
     *      with the smallest remaining count. These are
     *      psychologically the "closest finish line": the member has
     *      already started building them and a few more recruits
     *      tip them over.
     *
     *   2. Fall back to the shallowest level that's still incomplete
     *      and has slots to fill. Catches the all-empty signup-day
     *      case (every level is at 0/W^L) so the banner still has
     *      something to say.
     *
     * Returns null when every level is already complete — in that
     * state the existing "Matrix Master" banner already supplies the
     * congratulations and rendering both would be redundant.
     *
     * The level commission comes from wp_matrix_plans.level_commission
     * which is a JSON map keyed 1..D. We don't try to also include the
     * plan's referral_commission in the per-slot figure because not
     * every level-1 fill is a direct referral (some are spillover from
     * upline) — quoting referral_commission * remaining would
     * over-promise. The bonus is exposed separately on the returned
     * array so the renderer can mention it as an extra hint on level 1.
     *
     * @param array<int, array{level:int, filled:int, expected:int, complete:bool}> $level_status
     *        Output of self::get_level_completion_status().
     * @param object $plan Row from wp_matrix_plans. Must carry the
     *        level_commission and referral_commission columns; the
     *        genealogy renderer extends its SELECT to include them.
     * @return array{
     *     level:int,
     *     slots_remaining:int,
     *     expected:int,
     *     filled:int,
     *     commission_per_slot:float,
     *     total_earnable:float,
     *     referral_commission:float,
     *     is_in_progress:bool
     * }|null
     */
    public function get_next_level_goal(array $level_status, $plan) {
        if (empty($level_status) || empty($plan)) {
            return null;
        }

        // Decode the plan's level_commission JSON. Map is 1-indexed
        // (level => commission per fill). We normalise to (int =>
        // float) so the renderer can do straight arithmetic without
        // re-casting on each slot.
        $level_commissions = [];
        if (!empty($plan->level_commission)) {
            $decoded = json_decode((string) $plan->level_commission, true);
            if (is_array($decoded)) {
                foreach ($decoded as $k => $v) {
                    $level_commissions[(int) $k] = (float) $v;
                }
            }
        }

        // Two-pass scan over the level status rows. We don't trust
        // the array's outer keys to be 1..D in order (the caller
        // builds it that way today, but the priority logic should
        // hold even if a future caller passes them out of order).
        $best_in_progress           = null;
        $best_in_progress_remaining = PHP_INT_MAX;
        $shallowest_incomplete      = null;
        $shallowest_level           = PHP_INT_MAX;

        foreach ($level_status as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!empty($row['complete'])) {
                continue;
            }
            $remaining = max(0, (int) $row['expected'] - (int) $row['filled']);
            if ($remaining <= 0) {
                continue;
            }

            $row_level = (int) $row['level'];

            if ($row_level < $shallowest_level) {
                $shallowest_level      = $row_level;
                $shallowest_incomplete = $row;
            }

            if ((int) $row['filled'] > 0 && $remaining < $best_in_progress_remaining) {
                $best_in_progress           = $row;
                $best_in_progress_remaining = $remaining;
            }
        }

        $goal_row = $best_in_progress ?: $shallowest_incomplete;
        if (!$goal_row) {
            // Everything is complete or the matrix has no levels —
            // banner shouldn't render.
            return null;
        }

        $level     = (int) $goal_row['level'];
        $remaining = max(0, (int) $goal_row['expected'] - (int) $goal_row['filled']);
        $per_slot  = isset($level_commissions[$level]) ? (float) $level_commissions[$level] : 0.0;
        $referral  = isset($plan->referral_commission) ? (float) $plan->referral_commission : 0.0;

        return [
            'level'                => $level,
            'slots_remaining'      => $remaining,
            'expected'             => (int) $goal_row['expected'],
            'filled'               => (int) $goal_row['filled'],
            'commission_per_slot'  => $per_slot,
            'total_earnable'       => $remaining * $per_slot,
            'referral_commission'  => $referral,
            'is_in_progress'       => ((int) $goal_row['filled'] > 0),
        ];
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
     * Build a tree snapshot as it existed at a given point in time.
     *
     * Powers the genealogy "time machine" view. Returns the same
     * node shape build_tree_recursive() returns, with two
     * snapshot-specific differences:
     *
     *   1. Children are filtered by snapshot — a child only
     *      appears if its joined_at <= $snapshot_at. Members who
     *      hadn't joined yet on the snapshot date are absent.
     *   2. total_downline is RECOMPUTED from the snapshot's
     *      visible descendants rather than read from the live
     *      column. The live column tracks current state, which is
     *      the wrong number for a historical view (it would say
     *      "you had 12 downline on April 15" using today's count
     *      of 47 — the opposite of useful).
     *
     * v1 reconstruction caveat (documented in the time-machine
     * banner UI): we use the LIVE parent_id to walk the tree, not
     * the historical parent_id. The audit table captures
     * parent_id as of each event, but admin Move-in-Genealogy
     * events aren't captured in v1 (see
     * Matrix_MLM_Position_History::EVENT_TYPES — 'moved' is in
     * the enum but no v1 caller emits it). For installs that
     * never use the admin move tool — the common case — this is
     * exact. For installs that do, the snapshot shows members at
     * their CURRENT structural location, not where they actually
     * were on the snapshot date. The follow-up PR documented in
     * the time-machine v2 plan adds 'moved' capture and switches
     * this method to consult the historical parent_id from the
     * latest matrix_position_history row at <= $snapshot_at.
     *
     * @param int    $user_id       WP user id whose tree we're
     *                              reconstructing — i.e. the root
     *                              of the snapshot.
     * @param int    $plan_id       Plan whose tree we're snapshotting.
     * @param string $snapshot_at   SQL DATETIME (YYYY-MM-DD HH:MM:SS).
     *                              Use Matrix_MLM_Position_History::
     *                              snapshot_date_to_datetime() to
     *                              convert from a YYYY-MM-DD calendar
     *                              date the URL param carries.
     * @param int    $max_depth     Same depth cap the live tree uses
     *                              (typically min(4, plan_depth)). The
     *                              snapshot view in v1 doesn't expose
     *                              lazy-expand, so this is the only
     *                              cap a snapshot ever sees.
     * @return array|null           Tree node, or null when the user
     *                              didn't have a position in this plan
     *                              at the requested snapshot date.
     */
    public function build_tree_at_snapshot($user_id, $plan_id, $snapshot_at, $max_depth = 4) {
        global $wpdb;

        $user_id     = (int) $user_id;
        $plan_id     = (int) $plan_id;
        $snapshot_at = (string) $snapshot_at;
        $max_depth   = max(1, (int) $max_depth);

        if ($user_id <= 0 || $plan_id <= 0 || $snapshot_at === '') {
            return null;
        }

        // Find the user's position id IN THIS PLAN as it existed
        // at the snapshot date. Constraint: the position must
        // have been joined on or before the snapshot. We pick the
        // earliest such position because a user can in principle
        // have multiple historical position ids in the same plan
        // (rare — the join_plan() flow blocks duplicate active
        // positions, but legacy imports / completed-then-rejoin
        // flows can produce them) and the earliest one is the
        // canonical "their tree" root for that snapshot date.
        $root_position_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}matrix_positions
              WHERE user_id = %d
                AND plan_id = %d
                AND joined_at <= %s
              ORDER BY joined_at ASC, id ASC
              LIMIT 1",
            $user_id, $plan_id, $snapshot_at
        ));
        if ($root_position_id <= 0) {
            return null;
        }

        return $this->build_tree_at_snapshot_recursive(
            $root_position_id,
            $plan_id,
            $snapshot_at,
            1,
            $max_depth
        );
    }

    /**
     * Recursive partner of build_tree_at_snapshot(). Mirrors the
     * shape of build_tree_recursive() so the rendering layer can
     * consume both with the same code path — the genealogy view
     * doesn't need to know whether the tree it's rendering came
     * from the live engine or the time-machine reconstruction.
     *
     * The two divergences from build_tree_recursive() are
     * narrow and intentional:
     *
     *   - Children query adds an `AND joined_at <= %s` clause so
     *     members who hadn't joined yet on the snapshot date are
     *     filtered out at the SQL level (cheaper than walking
     *     them and discarding in PHP).
     *   - total_downline is computed bottom-up from the visible
     *     subtree, NOT read from the live matrix_positions
     *     column. See the parent method's docblock for why.
     */
    private function build_tree_at_snapshot_recursive($position_id, $plan_id, $snapshot_at, $current_depth, $max_depth) {
        global $wpdb;

        if ($current_depth > $max_depth) {
            return null;
        }

        // Position must itself have existed at the snapshot.
        // Belt-and-braces: the parent's children query already
        // applied the joined_at filter, but a defensive check
        // here means the recursive entry from
        // build_tree_at_snapshot() (which uses the user's own
        // joined_at directly) doesn't need to duplicate the
        // existence test.
        $position = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, u.user_login, u.user_email
               FROM {$wpdb->prefix}matrix_positions p
               LEFT JOIN {$wpdb->users} u ON p.user_id = u.ID
              WHERE p.id = %d
                AND p.joined_at <= %s",
            $position_id, $snapshot_at
        ));
        if (!$position) {
            return null;
        }

        // Children at the snapshot date: rows with parent_id
        // pointing here AND joined_at on or before T. The status
        // filter we use on the live tree (status='active') is
        // intentionally NOT applied here: the snapshot needs to
        // include members whose positions later went 'completed'
        // — they were active on the snapshot date and the
        // member's tree-as-of-T is meant to show that history.
        // For the rare 'inactive' status (which only affects
        // matrix_user_meta in v1, not matrix_positions, but
        // documented for safety) we also include — the snapshot
        // intent is "what was the tree", not "what is the tree
        // today minus inactives".
        $children = $wpdb->get_results($wpdb->prepare(
            "SELECT id
               FROM {$wpdb->prefix}matrix_positions
              WHERE parent_id = %d
                AND plan_id = %d
                AND joined_at <= %s
              ORDER BY position ASC",
            $position_id, $plan_id, $snapshot_at
        ));

        $node = [
            'id'             => (int) $position->id,
            'user_id'        => (int) $position->user_id,
            'sponsor_id'     => $position->sponsor_id !== null ? (int) $position->sponsor_id : null,
            'username'       => (string) $position->user_login,
            'level'          => (int) $current_depth,
            // Placeholder; recomputed from children below so the
            // count reflects the snapshot, not today.
            'total_downline' => 0,
            'status'         => (string) $position->status,
            'children'       => [],
        ];

        $subtree_count = 0;
        foreach ($children as $child) {
            $child_node = $this->build_tree_at_snapshot_recursive(
                (int) $child->id,
                $plan_id,
                $snapshot_at,
                $current_depth + 1,
                $max_depth
            );
            if ($child_node) {
                $node['children'][] = $child_node;
                // 1 (the child itself) + the child's own subtree
                // count. Bottom-up sum without a separate pass.
                $subtree_count += 1 + (int) $child_node['total_downline'];
            }
        }

        $node['total_downline'] = $subtree_count;

        return $node;
    }

    /**
     * Earliest snapshot date a member can pick for their time-
     * machine view, formatted as YYYY-MM-DD. Equals the day they
     * joined the plan — there's no useful tree snapshot from
     * before they had a position.
     *
     * Returns null when the user has no position in the plan, so
     * the caller can fall back to a "you have no tree to time-
     * travel through" empty state instead of rendering an
     * impossible date range.
     *
     * @param int $user_id WP user id.
     * @param int $plan_id Plan id.
     * @return string|null YYYY-MM-DD, or null when no position.
     */
    public function get_snapshot_floor_date($user_id, $plan_id) {
        global $wpdb;

        $user_id = (int) $user_id;
        $plan_id = (int) $plan_id;
        if ($user_id <= 0 || $plan_id <= 0) {
            return null;
        }

        $joined = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(joined_at)
               FROM {$wpdb->prefix}matrix_positions
              WHERE user_id = %d AND plan_id = %d",
            $user_id, $plan_id
        ));

        if (!$joined) return null;
        $ts = strtotime((string) $joined);
        if ($ts === false) return null;
        return date('Y-m-d', $ts);
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
