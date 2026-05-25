<?php
/**
 * Admin Plans Management
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Plans {

    public function render() {
        global $wpdb;
        $currency = get_option('matrix_mlm_currency_symbol', '₦');

        // Handle form submissions
        if (isset($_POST['save_plan']) && wp_verify_nonce($_POST['_wpnonce'], 'matrix_save_plan')) {
            $this->save_plan();
        }

        // Handle delete
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id']) && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'matrix_delete_plan')) {
            $this->delete_plan(intval($_GET['id']));
        }

        // Handle force delete (cascades through plan-scoped tables;
        // intended for retiring import-remnant or test plans whose
        // positions are not real downlines worth preserving).
        if (isset($_GET['action']) && $_GET['action'] === 'force_delete' && isset($_GET['id']) && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'matrix_force_delete_plan')) {
            $this->force_delete_plan(intval($_GET['id']));
        }

        if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
            $this->render_edit_form(intval($_GET['id']));
            return;
        }

        if (isset($_GET['action']) && $_GET['action'] === 'add') {
            $this->render_add_form();
            return;
        }

        $plans = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}matrix_plans ORDER BY price ASC");
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1>
                <?php _e('Manage Plans', 'matrix-mlm'); ?>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-plans&action=add'); ?>" class="page-title-action"><?php _e('Add New Plan', 'matrix-mlm'); ?></a>
            </h1>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Name', 'matrix-mlm'); ?></th>
                        <th><?php _e('Matrix', 'matrix-mlm'); ?></th>
                        <th><?php _e('Price', 'matrix-mlm'); ?></th>
                        <th><?php _e('Referral Commission', 'matrix-mlm'); ?></th>
                        <th><?php _e('Completion Bonus', 'matrix-mlm'); ?></th>
                        <th><?php _e('Members', 'matrix-mlm'); ?></th>
                        <th><?php _e('Status', 'matrix-mlm'); ?></th>
                        <th><?php _e('Actions', 'matrix-mlm'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plans as $plan): 
                        $members = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$wpdb->prefix}matrix_positions WHERE plan_id = %d AND status = 'active'", $plan->id
                        ));
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($plan->name); ?></strong></td>
                        <td><?php echo $plan->width . ' x ' . $plan->depth; ?></td>
                        <td><?php echo $currency . number_format($plan->price, 2); ?></td>
                        <td><?php echo $currency . number_format($plan->referral_commission, 2); ?></td>
                        <td><?php echo $currency . number_format($plan->matrix_completion_bonus, 2); ?></td>
                        <td><?php echo number_format($members); ?></td>
                        <td><span class="matrix-badge matrix-badge-<?php echo $plan->status; ?>"><?php echo ucfirst($plan->status); ?></span></td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=matrix-mlm-plans&action=edit&id=' . $plan->id); ?>" class="button button-small"><?php _e('Edit', 'matrix-mlm'); ?></a>
                            <?php if ($members == 0): ?>
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=matrix-mlm-plans&action=delete&id=' . $plan->id), 'matrix_delete_plan'); ?>" class="button button-small" style="color:#dc2626;" onclick="return confirm('<?php _e('Are you sure you want to delete this plan? This cannot be undone.', 'matrix-mlm'); ?>')"><?php _e('Delete', 'matrix-mlm'); ?></a>
                            <?php else: ?>
                            <span class="description" style="font-size:11px;"><?php _e('Has members', 'matrix-mlm'); ?></span>
                            <?php if (current_user_can('manage_options')): ?>
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=matrix-mlm-plans&action=force_delete&id=' . $plan->id), 'matrix_force_delete_plan'); ?>"
                               class="button button-small"
                               style="color:#dc2626; border-color:#dc2626; margin-left:6px;"
                               onclick="return confirm('<?php echo esc_js(sprintf(__('FORCE DELETE plan \"%1$s\"?\n\nThis will permanently:\n  - Remove all %2$d active member position(s) for this plan\n  - Delete related genealogy history, level-completion ledger, and share tokens\n  - Anonymize commission and e-pin history (plan_id set to NULL, rows preserved for audit)\n  - Delete the plan row itself\n\nThis CANNOT be undone. Type the plan name to confirm on the next prompt.', 'matrix-mlm'), $plan->name, $members)); ?>') && prompt('<?php echo esc_js(sprintf(__('Type the plan name exactly to confirm: %s', 'matrix-mlm'), $plan->name)); ?>') === '<?php echo esc_js($plan->name); ?>'"><?php _e('Force Delete', 'matrix-mlm'); ?></a>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_add_form() {
        $this->render_plan_form(null);
    }

    private function render_edit_form($id) {
        global $wpdb;
        $plan = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}matrix_plans WHERE id = %d", $id));
        if (!$plan) {
            echo '<div class="notice notice-error"><p>' . __('Plan not found', 'matrix-mlm') . '</p></div>';
            return;
        }
        $this->render_plan_form($plan);
    }

    private function render_plan_form($plan) {
        $is_edit = $plan !== null;
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $level_commissions = $is_edit ? json_decode($plan->level_commission, true) : [];
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php echo $is_edit ? __('Edit Plan', 'matrix-mlm') : __('Add New Plan', 'matrix-mlm'); ?></h1>

            <form method="post" class="matrix-admin-card">
                <?php wp_nonce_field('matrix_save_plan'); ?>
                <?php if ($is_edit): ?>
                <input type="hidden" name="plan_id" value="<?php echo $plan->id; ?>">
                <?php endif; ?>

                <table class="form-table">
                    <tr>
                        <th><?php _e('Plan Name', 'matrix-mlm'); ?></th>
                        <td><input type="text" name="name" class="regular-text" value="<?php echo esc_attr($plan->name ?? ''); ?>" required></td>
                    </tr>
                    <tr>
                        <th><?php _e('Matrix Width', 'matrix-mlm'); ?></th>
                        <td>
                            <input type="number" name="width" id="matrix_width" min="1" max="20" value="<?php echo esc_attr($plan->width ?? get_option('matrix_mlm_default_width', 2)); ?>" required>
                            <p class="description"><?php _e('Number of direct legs per member (1 = Unilevel, 2 = Binary, 3 = Ternary, etc.)', 'matrix-mlm'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Matrix Depth', 'matrix-mlm'); ?></th>
                        <td>
                            <input type="number" name="depth" id="matrix_depth" min="1" max="20" value="<?php echo esc_attr($plan->depth ?? get_option('matrix_mlm_default_depth', 3)); ?>" required>
                            <p class="description"><?php _e('Number of levels deep the matrix goes', 'matrix-mlm'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Matrix Summary', 'matrix-mlm'); ?></th>
                        <td>
                            <div id="matrix-summary" style="background: #f0f6ff; border: 1px solid #b3d4fc; border-radius: 6px; padding: 12px 16px;">
                                <strong id="matrix-type-label"></strong><br>
                                <span id="matrix-capacity-info"></span>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Price', 'matrix-mlm'); ?> (<?php echo $currency; ?>)</th>
                        <td><input type="number" name="price" step="0.01" min="0" value="<?php echo esc_attr($plan->price ?? 0); ?>" required></td>
                    </tr>
                    <tr>
                        <th><?php _e('Joining Fee', 'matrix-mlm'); ?> (<?php echo $currency; ?>)</th>
                        <td><input type="number" name="joining_fee" step="0.01" min="0" value="<?php echo esc_attr($plan->joining_fee ?? 0); ?>"></td>
                    </tr>
                    <tr>
                        <th><?php _e('Referral Commission', 'matrix-mlm'); ?> (<?php echo $currency; ?>)</th>
                        <td><input type="number" name="referral_commission" step="0.01" min="0" value="<?php echo esc_attr($plan->referral_commission ?? 0); ?>"></td>
                    </tr>
                    <tr>
                        <th><?php _e('Matrix Completion Bonus', 'matrix-mlm'); ?> (<?php echo $currency; ?>)</th>
                        <td><input type="number" name="matrix_completion_bonus" step="0.01" min="0" value="<?php echo esc_attr($plan->matrix_completion_bonus ?? 0); ?>"></td>
                    </tr>
                    <tr>
                        <th><?php _e('Level Commissions', 'matrix-mlm'); ?></th>
                        <td>
                            <div id="level-commissions">
                                <?php 
                                $depth = $plan->depth ?? 3;
                                for ($i = 1; $i <= $depth; $i++): ?>
                                <div class="level-commission-row" style="margin-bottom: 5px;">
                                    <label>Level <?php echo $i; ?>: </label>
                                    <input type="number" name="level_commission[<?php echo $i; ?>]" step="0.01" min="0" 
                                           value="<?php echo esc_attr($level_commissions[$i] ?? 0); ?>" style="width: 120px;">
                                    <?php echo $currency; ?>
                                </div>
                                <?php endfor; ?>
                            </div>
                            <p class="description"><?php _e('Commission paid to upline members at each level', 'matrix-mlm'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Description', 'matrix-mlm'); ?></th>
                        <td><textarea name="description" rows="4" class="large-text"><?php echo esc_textarea($plan->description ?? ''); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><?php _e('Status', 'matrix-mlm'); ?></th>
                        <td>
                            <select name="status">
                                <option value="active" <?php selected($plan->status ?? 'active', 'active'); ?>><?php _e('Active', 'matrix-mlm'); ?></option>
                                <option value="inactive" <?php selected($plan->status ?? '', 'inactive'); ?>><?php _e('Inactive', 'matrix-mlm'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="save_plan" class="button button-primary" value="<?php _e('Save Plan', 'matrix-mlm'); ?>">
                    <a href="<?php echo admin_url('admin.php?page=matrix-mlm-plans'); ?>" class="button"><?php _e('Cancel', 'matrix-mlm'); ?></a>
                </p>
            </form>

            <script>
            (function() {
                var widthInput = document.getElementById('matrix_width');
                var depthInput = document.getElementById('matrix_depth');
                var typeLabel = document.getElementById('matrix-type-label');
                var capacityInfo = document.getElementById('matrix-capacity-info');
                var levelContainer = document.getElementById('level-commissions');

                function calculateMaxMembers(width, depth) {
                    var total = 0;
                    for (var i = 0; i < depth; i++) {
                        total += Math.pow(width, i);
                    }
                    return total;
                }

                function getMatrixTypeLabel(width) {
                    if (width == 1) return '<?php _e('Unilevel', 'matrix-mlm'); ?>';
                    if (width == 2) return '<?php _e('Binary', 'matrix-mlm'); ?>';
                    if (width == 3) return '<?php _e('Ternary', 'matrix-mlm'); ?>';
                    return width + '-<?php _e('Wide', 'matrix-mlm'); ?>';
                }

                function updateMatrixSummary() {
                    var width = parseInt(widthInput.value) || 2;
                    var depth = parseInt(depthInput.value) || 3;
                    var maxMembers = calculateMaxMembers(width, depth);
                    var label = getMatrixTypeLabel(width) + ' (' + width + 'x' + depth + ')';

                    typeLabel.textContent = label;
                    capacityInfo.innerHTML = '<?php _e('Total positions to fill:', 'matrix-mlm'); ?> <strong>' + maxMembers.toLocaleString() + ' <?php _e('members', 'matrix-mlm'); ?></strong>';

                    if (maxMembers > 10000000) {
                        capacityInfo.innerHTML += '<br><span style="color: #dc2626;"><?php _e('⚠ Very large matrix — may be impractical to complete.', 'matrix-mlm'); ?></span>';
                    }
                }

                function updateLevelCommissions() {
                    var depth = parseInt(depthInput.value) || 3;
                    var existingValues = {};

                    // Preserve existing values
                    var inputs = levelContainer.querySelectorAll('input[type="number"]');
                    inputs.forEach(function(input) {
                        var match = input.name.match(/level_commission\[(\d+)\]/);
                        if (match) {
                            existingValues[match[1]] = input.value;
                        }
                    });

                    // Rebuild level commission fields
                    var html = '';
                    for (var i = 1; i <= depth; i++) {
                        var val = existingValues[i] || '0';
                        html += '<div class="level-commission-row" style="margin-bottom: 5px;">';
                        html += '<label><?php _e('Level', 'matrix-mlm'); ?> ' + i + ': </label>';
                        html += '<input type="number" name="level_commission[' + i + ']" step="0.01" min="0" value="' + val + '" style="width: 120px;">';
                        html += ' <?php echo esc_js(get_option('matrix_mlm_currency_symbol', '₦')); ?>';
                        html += '</div>';
                    }
                    levelContainer.innerHTML = html;
                }

                widthInput.addEventListener('change', updateMatrixSummary);
                widthInput.addEventListener('input', updateMatrixSummary);
                depthInput.addEventListener('change', function() {
                    updateMatrixSummary();
                    updateLevelCommissions();
                });
                depthInput.addEventListener('input', function() {
                    updateMatrixSummary();
                    updateLevelCommissions();
                });

                // Initial calculation
                updateMatrixSummary();
            })();
            </script>
        </div>
        <?php
    }

    private function delete_plan($plan_id) {
        global $wpdb;

        // Check if plan has active members
        $members = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}matrix_positions WHERE plan_id = %d AND status = 'active'",
            $plan_id
        ));

        if ($members > 0) {
            echo '<div class="notice notice-error"><p>' . __('Cannot delete a plan that has active members. Set it to inactive instead.', 'matrix-mlm') . '</p></div>';
            return;
        }

        // Check plan exists
        $plan = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}matrix_plans WHERE id = %d", $plan_id));
        if (!$plan) {
            echo '<div class="notice notice-error"><p>' . __('Plan not found.', 'matrix-mlm') . '</p></div>';
            return;
        }

        // Delete any inactive/completed positions for this plan
        $wpdb->delete($wpdb->prefix . 'matrix_positions', ['plan_id' => $plan_id]);

        // Delete the plan
        $wpdb->delete($wpdb->prefix . 'matrix_plans', ['id' => $plan_id]);

        echo '<div class="notice notice-success"><p>' . sprintf(__('Plan "%s" has been deleted.', 'matrix-mlm'), esc_html($plan->name)) . '</p></div>';
    }

    /**
     * Force-delete a plan including its active member positions and
     * all dependent plan-scoped rows.
     *
     * Use case: retiring plans that were created by the Laravel
     * import or by testing, whose `matrix_positions` rows are not
     * real downlines worth preserving. The conservative
     * delete_plan() blocks this; this method bypasses that block
     * after a manage_options + nonce + name-typed-confirmation
     * gauntlet on the caller side.
     *
     * Cleanup strategy per table:
     *   - Plan-scoped rows where plan_id is NOT NULL are DELETEd
     *     (matrix_positions, matrix_position_history,
     *     matrix_level_completions, matrix_share_tokens). Share
     *     tokens have a nullable plan_id but are semantically
     *     plan-specific (a token resolves to a plan's tree), so
     *     they're deleted rather than orphaned.
     *   - Audit-trail rows where plan_id is NULLABLE are kept and
     *     anonymized (matrix_commissions, matrix_epins). The
     *     reports/listing pages already LEFT JOIN against
     *     matrix_plans, so the plan column will read as "-" or
     *     "Custom" for these historical rows post-cleanup.
     *
     * The sequence is wrapped in a SQL transaction. On InnoDB this
     * gives all-or-nothing semantics; on MyISAM the ROLLBACK is a
     * no-op and partial cleanup is recoverable by re-running this
     * action (each step is idempotent against an already-cleaned
     * plan).
     */
    private function force_delete_plan($plan_id) {
        global $wpdb;

        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>' . __('You do not have permission to perform this action.', 'matrix-mlm') . '</p></div>';
            return;
        }

        $plan = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}matrix_plans WHERE id = %d", $plan_id));
        if (!$plan) {
            echo '<div class="notice notice-error"><p>' . __('Plan not found.', 'matrix-mlm') . '</p></div>';
            return;
        }

        $counts = [
            'positions'         => 0,
            'position_history'  => 0,
            'level_completions' => 0,
            'share_tokens'      => 0,
            'commissions'       => 0,
            'epins'             => 0,
        ];

        $wpdb->query('START TRANSACTION');
        $error = null;

        // Delete plan-bound rows (plan_id NOT NULL columns + share tokens).
        $delete_targets = [
            'positions'         => 'matrix_positions',
            'position_history'  => 'matrix_position_history',
            'level_completions' => 'matrix_level_completions',
            'share_tokens'      => 'matrix_share_tokens',
        ];
        foreach ($delete_targets as $key => $table) {
            // Some installs may pre-date a given table (matrix_position_history,
            // matrix_level_completions, matrix_share_tokens were added later).
            // Skip silently if the table doesn't exist; the activator's normal
            // create_tables() pass would have created it on next plugin load.
            $full = $wpdb->prefix . $table;
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full));
            if (!$exists) {
                continue;
            }
            $r = $wpdb->delete($full, ['plan_id' => $plan_id]);
            if ($r === false) {
                $error = $table . ': ' . $wpdb->last_error;
                break;
            }
            $counts[$key] = (int) $r;
        }

        // Anonymize audit-trail rows (plan_id NULLABLE).
        if (!$error) {
            $anonymize_targets = [
                'commissions' => 'matrix_commissions',
                'epins'       => 'matrix_epins',
            ];
            foreach ($anonymize_targets as $key => $table) {
                $full = $wpdb->prefix . $table;
                $r = $wpdb->update($full, ['plan_id' => null], ['plan_id' => $plan_id]);
                if ($r === false) {
                    $error = $table . ': ' . $wpdb->last_error;
                    break;
                }
                $counts[$key] = (int) $r;
            }
        }

        // Finally drop the plan row itself.
        if (!$error) {
            $r = $wpdb->delete($wpdb->prefix . 'matrix_plans', ['id' => $plan_id]);
            if ($r === false) {
                $error = 'matrix_plans: ' . $wpdb->last_error;
            }
        }

        if ($error) {
            $wpdb->query('ROLLBACK');
            echo '<div class="notice notice-error"><p>' . sprintf(
                __('Force delete failed for plan "%1$s". Database error: %2$s', 'matrix-mlm'),
                esc_html($plan->name),
                esc_html($error)
            ) . '</p></div>';
            return;
        }

        $wpdb->query('COMMIT');

        echo '<div class="notice notice-success"><p>' . sprintf(
            __('Plan "%1$s" force-deleted. Removed: %2$d position(s), %3$d position-history row(s), %4$d level-completion row(s), %5$d share token(s). Anonymized: %6$d commission row(s), %7$d e-pin row(s).', 'matrix-mlm'),
            esc_html($plan->name),
            $counts['positions'],
            $counts['position_history'],
            $counts['level_completions'],
            $counts['share_tokens'],
            $counts['commissions'],
            $counts['epins']
        ) . '</p></div>';
    }

    private function save_plan() {
        global $wpdb;

        $data = [
            'name' => sanitize_text_field($_POST['name']),
            'width' => intval($_POST['width']),
            'depth' => intval($_POST['depth']),
            'price' => floatval($_POST['price']),
            'joining_fee' => floatval($_POST['joining_fee'] ?? 0),
            'referral_commission' => floatval($_POST['referral_commission'] ?? 0),
            'matrix_completion_bonus' => floatval($_POST['matrix_completion_bonus'] ?? 0),
            'level_commission' => json_encode($_POST['level_commission'] ?? []),
            'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'status' => sanitize_text_field($_POST['status']),
        ];

        if (isset($_POST['plan_id'])) {
            $wpdb->update($wpdb->prefix . 'matrix_plans', $data, ['id' => intval($_POST['plan_id'])]);
            echo '<div class="notice notice-success"><p>' . __('Plan updated successfully!', 'matrix-mlm') . '</p></div>';
        } else {
            $wpdb->insert($wpdb->prefix . 'matrix_plans', $data);
            echo '<div class="notice notice-success"><p>' . __('Plan created successfully!', 'matrix-mlm') . '</p></div>';
        }
    }
}
