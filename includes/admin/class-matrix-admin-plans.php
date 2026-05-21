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
                            <input type="number" name="width" min="2" max="10" value="<?php echo esc_attr($plan->width ?? 2); ?>" required>
                            <p class="description"><?php _e('Number of direct legs per member (e.g., 2 for binary, 3 for ternary)', 'matrix-mlm'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Matrix Depth', 'matrix-mlm'); ?></th>
                        <td>
                            <input type="number" name="depth" min="2" max="15" value="<?php echo esc_attr($plan->depth ?? 3); ?>" required>
                            <p class="description"><?php _e('Number of levels deep the matrix goes', 'matrix-mlm'); ?></p>
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
        </div>
        <?php
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
