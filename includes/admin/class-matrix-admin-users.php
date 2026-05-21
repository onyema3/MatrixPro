<?php
/**
 * Admin Users Management
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Users {

    public function __construct() {
        add_action('admin_init', [$this, 'handle_user_export']);
    }

    public function render() {
        global $wpdb;
        $currency = get_option('matrix_mlm_currency_symbol', '₦');

        if (isset($_GET['action']) && $_GET['action'] === 'view' && isset($_GET['id'])) {
            $this->render_user_detail(intval($_GET['id']));
            return;
        }

        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
        $balance_min = isset($_GET['balance_min']) ? sanitize_text_field($_GET['balance_min']) : '';
        $balance_max = isset($_GET['balance_max']) ? sanitize_text_field($_GET['balance_max']) : '';
        $plan_filter = isset($_GET['plan_id']) ? intval($_GET['plan_id']) : 0;
        $has_referrals = isset($_GET['has_referrals']) ? sanitize_text_field($_GET['has_referrals']) : '';
        $page_num = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($page_num - 1) * $per_page;

        $where = "WHERE 1=1";
        $params = [];

        if ($search) {
            $where .= " AND (u.user_login LIKE %s OR u.user_email LIKE %s OR um.phone LIKE %s OR um.referral_code LIKE %s)";
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        if ($status_filter) {
            $where .= " AND um.status = %s";
            $params[] = $status_filter;
        }
        if ($date_from) {
            $where .= " AND DATE(u.user_registered) >= %s";
            $params[] = $date_from;
        }
        if ($date_to) {
            $where .= " AND DATE(u.user_registered) <= %s";
            $params[] = $date_to;
        }
        if ($balance_min !== '') {
            $where .= " AND um.balance >= %f";
            $params[] = floatval($balance_min);
        }
        if ($balance_max !== '') {
            $where .= " AND um.balance <= %f";
            $params[] = floatval($balance_max);
        }
        if ($plan_filter) {
            $where .= " AND um.user_id IN (SELECT user_id FROM {$wpdb->prefix}matrix_positions WHERE plan_id = %d AND status = 'active')";
            $params[] = $plan_filter;
        }

        $count_params = $params;
        $params[] = $per_page;
        $params[] = $offset;

        $query = "SELECT um.*, u.user_login, u.user_email, u.user_registered FROM {$wpdb->prefix}matrix_user_meta um LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID $where ORDER BY um.created_at DESC LIMIT %d OFFSET %d";
        $users = !empty($params) ? $wpdb->get_results($wpdb->prepare($query, $params)) : $wpdb->get_results($query);

        $count_query = "SELECT COUNT(*) FROM {$wpdb->prefix}matrix_user_meta um LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID $where";
        $total = !empty($count_params) ? $wpdb->get_var($wpdb->prepare($count_query, $count_params)) : $wpdb->get_var($count_query);

        // Get plans for filter dropdown
        $plans = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}matrix_plans ORDER BY name ASC");

        // Build export URL params
        $export_params = array_filter([
            'page' => 'matrix-mlm-users',
            's' => $search,
            'status' => $status_filter,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'balance_min' => $balance_min,
            'balance_max' => $balance_max,
            'plan_id' => $plan_filter,
            'has_referrals' => $has_referrals,
        ]);
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php _e('Manage Users', 'matrix-mlm'); ?> <span style="font-size:14px;color:#666;font-weight:normal;">(<?php echo number_format($total); ?> <?php _e('total', 'matrix-mlm'); ?>)</span></h1>

            <!-- Filters -->
            <div class="matrix-admin-card" style="margin-bottom:20px;padding:16px 20px;">
                <form method="get" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
                    <input type="hidden" name="page" value="matrix-mlm-users">
                    <div>
                        <label style="display:block;font-size:11px;margin-bottom:2px;"><?php _e('Search', 'matrix-mlm'); ?></label>
                        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e('Name, email, phone, code...', 'matrix-mlm'); ?>" style="width:180px;">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;margin-bottom:2px;"><?php _e('Status', 'matrix-mlm'); ?></label>
                        <select name="status">
                            <option value=""><?php _e('All', 'matrix-mlm'); ?></option>
                            <option value="active" <?php selected($status_filter, 'active'); ?>><?php _e('Active', 'matrix-mlm'); ?></option>
                            <option value="banned" <?php selected($status_filter, 'banned'); ?>><?php _e('Banned', 'matrix-mlm'); ?></option>
                            <option value="pending" <?php selected($status_filter, 'pending'); ?>><?php _e('Pending', 'matrix-mlm'); ?></option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;margin-bottom:2px;"><?php _e('Joined From', 'matrix-mlm'); ?></label>
                        <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" style="width:130px;">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;margin-bottom:2px;"><?php _e('Joined To', 'matrix-mlm'); ?></label>
                        <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>" style="width:130px;">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;margin-bottom:2px;"><?php _e('Min Balance', 'matrix-mlm'); ?></label>
                        <input type="number" name="balance_min" value="<?php echo esc_attr($balance_min); ?>" step="0.01" style="width:90px;" placeholder="0">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;margin-bottom:2px;"><?php _e('Max Balance', 'matrix-mlm'); ?></label>
                        <input type="number" name="balance_max" value="<?php echo esc_attr($balance_max); ?>" step="0.01" style="width:90px;" placeholder="∞">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;margin-bottom:2px;"><?php _e('Plan', 'matrix-mlm'); ?></label>
                        <select name="plan_id">
                            <option value=""><?php _e('All Plans', 'matrix-mlm'); ?></option>
                            <?php foreach ($plans as $p): ?>
                            <option value="<?php echo $p->id; ?>" <?php selected($plan_filter, $p->id); ?>><?php echo esc_html($p->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <input type="submit" class="button button-primary" value="<?php _e('Filter', 'matrix-mlm'); ?>">
                        <a href="<?php echo admin_url('admin.php?page=matrix-mlm-users'); ?>" class="button"><?php _e('Reset', 'matrix-mlm'); ?></a>
                    </div>
                </form>
            </div>

            <!-- Export Buttons -->
            <div style="margin-bottom:15px;display:flex;gap:8px;align-items:center;">
                <strong><?php _e('Export:', 'matrix-mlm'); ?></strong>
                <a href="<?php echo wp_nonce_url(add_query_arg(array_merge($export_params, ['export_users' => 'csv']), admin_url('admin.php')), 'matrix_export_users'); ?>" class="button"><span class="dashicons dashicons-media-spreadsheet" style="margin-top:4px;"></span> CSV</a>
                <a href="<?php echo wp_nonce_url(add_query_arg(array_merge($export_params, ['export_users' => 'excel']), admin_url('admin.php')), 'matrix_export_users'); ?>" class="button"><span class="dashicons dashicons-media-document" style="margin-top:4px;"></span> Excel</a>
                <a href="<?php echo wp_nonce_url(add_query_arg(array_merge($export_params, ['export_users' => 'pdf']), admin_url('admin.php')), 'matrix_export_users'); ?>" class="button"><span class="dashicons dashicons-pdf" style="margin-top:4px;"></span> PDF</a>
                <a href="<?php echo wp_nonce_url(add_query_arg(array_merge($export_params, ['export_users' => 'json']), admin_url('admin.php')), 'matrix_export_users'); ?>" class="button"><span class="dashicons dashicons-editor-code" style="margin-top:4px;"></span> JSON</a>
                <span style="margin-left:10px;color:#666;font-size:12px;"><?php printf(__('(%d users match current filters)', 'matrix-mlm'), $total); ?></span>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Username', 'matrix-mlm'); ?></th>
                        <th><?php _e('Email', 'matrix-mlm'); ?></th>
                        <th><?php _e('Phone', 'matrix-mlm'); ?></th>
                        <th><?php _e('Balance', 'matrix-mlm'); ?></th>
                        <th><?php _e('Referral Code', 'matrix-mlm'); ?></th>
                        <th><?php _e('Referrals', 'matrix-mlm'); ?></th>
                        <th><?php _e('Status', 'matrix-mlm'); ?></th>
                        <th><?php _e('Joined', 'matrix-mlm'); ?></th>
                        <th><?php _e('Actions', 'matrix-mlm'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): 
                        $referral_count = Matrix_MLM_User::get_referral_count($user->user_id);
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($user->user_login); ?></strong></td>
                        <td><?php echo esc_html($user->user_email); ?></td>
                        <td><?php echo esc_html($user->phone ?? '-'); ?></td>
                        <td><?php echo $currency . number_format($user->balance, 2); ?></td>
                        <td><code><?php echo esc_html($user->referral_code); ?></code></td>
                        <td><?php echo $referral_count; ?></td>
                        <td><span class="matrix-badge matrix-badge-<?php echo $user->status; ?>"><?php echo ucfirst($user->status); ?></span></td>
                        <td><?php echo date('M d, Y', strtotime($user->user_registered)); ?></td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=matrix-mlm-users&action=view&id=' . $user->user_id); ?>" class="button button-small"><?php _e('View', 'matrix-mlm'); ?></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            $total_pages = ceil($total / $per_page);
            if ($total_pages > 1):
            ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="<?php echo admin_url('admin.php?page=matrix-mlm-users&paged=' . $i); ?>" class="<?php echo $i === $page_num ? 'current' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_user_detail($user_id) {
        global $wpdb;
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $user = get_userdata($user_id);
        $meta = Matrix_MLM_User::get_meta($user_id);
        $wallet = new Matrix_MLM_Wallet();
        $commissions = Matrix_MLM_Commission::get_summary($user_id);
        $active_plans = Matrix_MLM_User::get_active_plans($user_id);
        $referrals = Matrix_MLM_User::get_referrals($user_id, 10);

        if (!$user || !$meta) {
            echo '<div class="notice notice-error"><p>' . __('User not found', 'matrix-mlm') . '</p></div>';
            return;
        }
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1>
                <?php printf(__('User: %s', 'matrix-mlm'), $user->user_login); ?>
                <a href="<?php echo admin_url('admin.php?page=matrix-mlm-users'); ?>" class="page-title-action"><?php _e('Back to Users', 'matrix-mlm'); ?></a>
            </h1>

            <div class="matrix-admin-stats">
                <div class="stat-card stat-primary">
                    <h3><?php echo $currency . number_format($meta->balance, 2); ?></h3>
                    <p><?php _e('Current Balance', 'matrix-mlm'); ?></p>
                </div>
                <div class="stat-card stat-success">
                    <h3><?php echo $currency . number_format($commissions['total'], 2); ?></h3>
                    <p><?php _e('Total Commissions', 'matrix-mlm'); ?></p>
                </div>
                <div class="stat-card stat-info">
                    <h3><?php echo $currency . number_format($commissions['referral'], 2); ?></h3>
                    <p><?php _e('Referral Earnings', 'matrix-mlm'); ?></p>
                </div>
                <div class="stat-card stat-warning">
                    <h3><?php echo Matrix_MLM_User::get_referral_count($user_id); ?></h3>
                    <p><?php _e('Total Referrals', 'matrix-mlm'); ?></p>
                </div>
            </div>

            <div class="matrix-admin-card">
                <h2><?php _e('User Details', 'matrix-mlm'); ?></h2>
                <table class="form-table">
                    <tr><th><?php _e('Referral Code', 'matrix-mlm'); ?></th><td><code><?php echo esc_html($meta->referral_code); ?></code></td></tr>
                    <tr><th><?php _e('2FA', 'matrix-mlm'); ?></th><td><?php echo $meta->two_factor_enabled ? __('Enabled', 'matrix-mlm') : __('Disabled', 'matrix-mlm'); ?></td></tr>
                    <tr><th><?php _e('Email Verified', 'matrix-mlm'); ?></th><td><?php echo $meta->email_verified ? __('Yes', 'matrix-mlm') : __('No', 'matrix-mlm'); ?></td></tr>
                    <tr><th><?php _e('Registered', 'matrix-mlm'); ?></th><td><?php echo date('M d, Y H:i', strtotime($user->user_registered)); ?></td></tr>
                </table>

                <h3><?php _e('Quick Actions', 'matrix-mlm'); ?></h3>
                <div class="matrix-admin-actions">
                    <?php if ($meta->status === 'active'): ?>
                    <button class="button button-secondary" onclick="matrixAdminAction('ban_user', {user_id: <?php echo $user_id; ?>})"><?php _e('Ban User', 'matrix-mlm'); ?></button>
                    <?php else: ?>
                    <button class="button button-primary" onclick="matrixAdminAction('unban_user', {user_id: <?php echo $user_id; ?>})"><?php _e('Unban User', 'matrix-mlm'); ?></button>
                    <?php endif; ?>
                    <button class="button" onclick="matrixAddBalance(<?php echo $user_id; ?>)"><?php _e('Add Balance', 'matrix-mlm'); ?></button>
                    <button class="button" onclick="matrixSubtractBalance(<?php echo $user_id; ?>)"><?php _e('Subtract Balance', 'matrix-mlm'); ?></button>
                </div>
            </div>

            <!-- Admin Edit Profile -->
            <div class="matrix-admin-card">
                <h2><?php _e('Edit Profile', 'matrix-mlm'); ?></h2>
                <p class="description"><?php _e('Update this user\'s profile information.', 'matrix-mlm'); ?></p>
                <table class="form-table" id="matrix-admin-edit-profile">
                    <tr>
                        <th><?php _e('First Name', 'matrix-mlm'); ?></th>
                        <td><input type="text" id="admin_edit_first_name" class="regular-text" value="<?php echo esc_attr(get_user_meta($user_id, 'first_name', true)); ?>"></td>
                    </tr>
                    <tr>
                        <th><?php _e('Last Name', 'matrix-mlm'); ?></th>
                        <td><input type="text" id="admin_edit_last_name" class="regular-text" value="<?php echo esc_attr(get_user_meta($user_id, 'last_name', true)); ?>"></td>
                    </tr>
                    <tr>
                        <th><?php _e('Email', 'matrix-mlm'); ?></th>
                        <td><input type="email" id="admin_edit_email" class="regular-text" value="<?php echo esc_attr($user->user_email); ?>"></td>
                    </tr>
                    <tr>
                        <th><?php _e('Phone', 'matrix-mlm'); ?></th>
                        <td><input type="text" id="admin_edit_phone" class="regular-text" value="<?php echo esc_attr($meta->phone ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th><?php _e('Status', 'matrix-mlm'); ?></th>
                        <td>
                            <select id="admin_edit_status">
                                <option value="active" <?php selected($meta->status, 'active'); ?>><?php _e('Active', 'matrix-mlm'); ?></option>
                                <option value="inactive" <?php selected($meta->status, 'inactive'); ?>><?php _e('Inactive', 'matrix-mlm'); ?></option>
                                <option value="banned" <?php selected($meta->status, 'banned'); ?>><?php _e('Banned', 'matrix-mlm'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Address', 'matrix-mlm'); ?></th>
                        <td><textarea id="admin_edit_address" class="large-text" rows="2"><?php echo esc_textarea($meta->address ?? ''); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><?php _e('City', 'matrix-mlm'); ?></th>
                        <td><input type="text" id="admin_edit_city" class="regular-text" value="<?php echo esc_attr($meta->city ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th><?php _e('State', 'matrix-mlm'); ?></th>
                        <td><input type="text" id="admin_edit_state" class="regular-text" value="<?php echo esc_attr($meta->state ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th><?php _e('Country', 'matrix-mlm'); ?></th>
                        <td><input type="text" id="admin_edit_country" class="regular-text" value="<?php echo esc_attr($meta->country ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th><?php _e('Zip Code', 'matrix-mlm'); ?></th>
                        <td><input type="text" id="admin_edit_zip_code" class="small-text" value="<?php echo esc_attr($meta->zip_code ?? ''); ?>"></td>
                    </tr>
                </table>
                <p>
                    <button class="button button-primary" onclick="matrixAdminUpdateProfile(<?php echo $user_id; ?>)"><?php _e('Save Profile Changes', 'matrix-mlm'); ?></button>
                </p>
            </div>
            <script>
            function matrixAdminUpdateProfile(userId) {
                var data = {
                    action: 'matrix_admin_action',
                    nonce: matrixMLMAdmin.nonce,
                    matrix_action: 'update_user_profile',
                    user_id: userId,
                    first_name: document.getElementById('admin_edit_first_name').value,
                    last_name: document.getElementById('admin_edit_last_name').value,
                    email: document.getElementById('admin_edit_email').value,
                    phone: document.getElementById('admin_edit_phone').value,
                    status: document.getElementById('admin_edit_status').value,
                    address: document.getElementById('admin_edit_address').value,
                    city: document.getElementById('admin_edit_city').value,
                    state: document.getElementById('admin_edit_state').value,
                    country: document.getElementById('admin_edit_country').value,
                    zip_code: document.getElementById('admin_edit_zip_code').value
                };
                jQuery.post(matrixMLMAdmin.ajaxUrl, data, function(res) {
                    alert(res.success ? res.data.message : (res.data.message || 'Error'));
                    if (res.success) location.reload();
                });
            }
            </script>

            <div class="matrix-admin-card">
                <h2><?php _e('Active Plans', 'matrix-mlm'); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th><?php _e('Plan', 'matrix-mlm'); ?></th><th><?php _e('Downline', 'matrix-mlm'); ?></th><th><?php _e('Joined', 'matrix-mlm'); ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($active_plans as $plan): ?>
                        <tr>
                            <td><?php echo esc_html($plan->name); ?> (<?php echo $plan->width . 'x' . $plan->depth; ?>)</td>
                            <td><?php echo $plan->total_downline; ?></td>
                            <td><?php echo date('M d, Y', strtotime($plan->joined_at)); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!empty($active_plans)): ?>
            <!-- Move User in Genealogy -->
            <div class="matrix-admin-card">
                <h2><?php _e('Move in Genealogy', 'matrix-mlm'); ?></h2>
                <p class="description"><?php _e('Reassign this user\'s position in the matrix tree. You can change their tree parent (who they sit under) and/or their sponsor (who referred them).', 'matrix-mlm'); ?></p>

                <?php
                // Get positions with parent info
                $user_positions = $wpdb->get_results($wpdb->prepare(
                    "SELECT p.*, pl.name as plan_name, pl.width, pl.depth,
                            parent_u.user_login as parent_username,
                            sponsor_u.user_login as sponsor_username
                     FROM {$wpdb->prefix}matrix_positions p
                     JOIN {$wpdb->prefix}matrix_plans pl ON p.plan_id = pl.id
                     LEFT JOIN {$wpdb->prefix}matrix_positions pp ON p.parent_id = pp.id
                     LEFT JOIN {$wpdb->users} parent_u ON pp.user_id = parent_u.ID
                     LEFT JOIN {$wpdb->users} sponsor_u ON p.sponsor_id = sponsor_u.ID
                     WHERE p.user_id = %d AND p.status = 'active'
                     ORDER BY p.joined_at DESC",
                    $user_id
                ));
                ?>

                <?php foreach ($user_positions as $pos): ?>
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px 20px;margin-bottom:16px;">
                    <h4 style="margin:0 0 12px;"><?php echo esc_html($pos->plan_name); ?> (<?php echo $pos->width . 'x' . $pos->depth; ?>)</h4>
                    <table class="form-table" style="margin:0;">
                        <tr>
                            <th style="width:140px;padding:8px 0;"><?php _e('Current Parent', 'matrix-mlm'); ?></th>
                            <td style="padding:8px 0;">
                                <strong><?php echo esc_html($pos->parent_username ?? __('Root (No Parent)', 'matrix-mlm')); ?></strong>
                                <span style="color:#6b7280;font-size:12px;"> &mdash; <?php printf(__('Level %d', 'matrix-mlm'), $pos->level); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th style="padding:8px 0;"><?php _e('Current Sponsor', 'matrix-mlm'); ?></th>
                            <td style="padding:8px 0;">
                                <strong><?php echo esc_html($pos->sponsor_username ?? __('None', 'matrix-mlm')); ?></strong>
                            </td>
                        </tr>
                        <tr>
                            <th style="padding:8px 0;"><?php _e('New Parent', 'matrix-mlm'); ?></th>
                            <td style="padding:8px 0;">
                                <input type="text" id="move_parent_<?php echo $pos->id; ?>" class="regular-text" placeholder="<?php _e('Username of new parent (leave empty to keep)', 'matrix-mlm'); ?>">
                                <p class="description" style="margin:4px 0 0;"><?php _e('The user this member will be placed directly under in the tree.', 'matrix-mlm'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th style="padding:8px 0;"><?php _e('New Sponsor', 'matrix-mlm'); ?></th>
                            <td style="padding:8px 0;">
                                <input type="text" id="move_sponsor_<?php echo $pos->id; ?>" class="regular-text" placeholder="<?php _e('Username of new sponsor (leave empty to keep)', 'matrix-mlm'); ?>">
                                <p class="description" style="margin:4px 0 0;"><?php _e('The user who referred/recruited this member.', 'matrix-mlm'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p style="margin:12px 0 0;">
                        <button class="button button-primary" onclick="matrixMoveUserPosition(<?php echo $pos->id; ?>)"><?php _e('Move Position', 'matrix-mlm'); ?></button>
                    </p>
                </div>
                <?php endforeach; ?>
            </div>
            <script>
            function matrixMoveUserPosition(positionId) {
                var newParent = document.getElementById('move_parent_' + positionId).value.trim();
                var newSponsor = document.getElementById('move_sponsor_' + positionId).value.trim();

                if (!newParent && !newSponsor) {
                    alert('<?php _e('Please enter a new parent or sponsor username.', 'matrix-mlm'); ?>');
                    return;
                }

                if (!confirm('<?php _e('Are you sure you want to move this user in the genealogy? This will restructure the tree and recalculate all downline counts.', 'matrix-mlm'); ?>')) {
                    return;
                }

                jQuery.post(matrixMLMAdmin.ajaxUrl, {
                    action: 'matrix_admin_action',
                    nonce: matrixMLMAdmin.nonce,
                    matrix_action: 'move_user_position',
                    position_id: positionId,
                    new_parent_username: newParent,
                    new_sponsor_username: newSponsor
                }, function(res) {
                    alert(res.success ? res.data.message : (res.data.message || 'Error'));
                    if (res.success) location.reload();
                });
            }
            </script>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Handle user export
     */
    public function handle_user_export() {
        if (!isset($_GET['export_users']) || !isset($_GET['page']) || $_GET['page'] !== 'matrix-mlm-users') {
            return;
        }
        if (!wp_verify_nonce($_GET['_wpnonce'], 'matrix_export_users')) {
            return;
        }
        if (!current_user_can('manage_matrix_users')) {
            return;
        }

        $format = sanitize_text_field($_GET['export_users']);
        $data = $this->get_filtered_users_for_export();
        $filename = 'matrix-users-' . date('Y-m-d');

        switch ($format) {
            case 'csv': $this->export_users_csv($data, $filename); break;
            case 'excel': $this->export_users_excel($data, $filename); break;
            case 'pdf': $this->export_users_pdf($data, $filename); break;
            case 'json': $this->export_users_json($data, $filename); break;
        }
        exit;
    }

    private function get_filtered_users_for_export() {
        global $wpdb;

        $where = "WHERE 1=1";
        $params = [];

        $search = sanitize_text_field($_GET['s'] ?? '');
        $status_filter = sanitize_text_field($_GET['status'] ?? '');
        $date_from = sanitize_text_field($_GET['date_from'] ?? '');
        $date_to = sanitize_text_field($_GET['date_to'] ?? '');
        $balance_min = $_GET['balance_min'] ?? '';
        $balance_max = $_GET['balance_max'] ?? '';
        $plan_filter = intval($_GET['plan_id'] ?? 0);

        if ($search) {
            $where .= " AND (u.user_login LIKE %s OR u.user_email LIKE %s OR um.phone LIKE %s OR um.referral_code LIKE %s)";
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        if ($status_filter) { $where .= " AND um.status = %s"; $params[] = $status_filter; }
        if ($date_from) { $where .= " AND DATE(u.user_registered) >= %s"; $params[] = $date_from; }
        if ($date_to) { $where .= " AND DATE(u.user_registered) <= %s"; $params[] = $date_to; }
        if ($balance_min !== '') { $where .= " AND um.balance >= %f"; $params[] = floatval($balance_min); }
        if ($balance_max !== '') { $where .= " AND um.balance <= %f"; $params[] = floatval($balance_max); }
        if ($plan_filter) { $where .= " AND um.user_id IN (SELECT user_id FROM {$wpdb->prefix}matrix_positions WHERE plan_id = %d AND status = 'active')"; $params[] = $plan_filter; }

        $query = "SELECT u.user_login as username, COALESCE(wum1.meta_value, '') as first_name, COALESCE(wum2.meta_value, '') as last_name, u.user_email as email, um.phone, um.balance, um.referral_code, um.referred_by, um.status, um.country, um.state, um.city, u.user_registered as joined FROM {$wpdb->prefix}matrix_user_meta um LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID LEFT JOIN {$wpdb->usermeta} wum1 ON um.user_id = wum1.user_id AND wum1.meta_key = 'first_name' LEFT JOIN {$wpdb->usermeta} wum2 ON um.user_id = wum2.user_id AND wum2.meta_key = 'last_name' $where ORDER BY um.created_at DESC";

        return !empty($params) ? $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A) : $wpdb->get_results($query, ARRAY_A);
    }

    private function export_users_csv($data, $filename) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
        if (!empty($data)) {
            fputcsv($output, array_keys($data[0]));
            foreach ($data as $row) { fputcsv($output, $row); }
        }
        fclose($output);
    }

    private function export_users_excel($data, $filename) {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        echo '<html><head><meta charset="UTF-8"></head><body><table border="1">';
        if (!empty($data)) {
            echo '<tr>';
            foreach (array_keys($data[0]) as $h) { echo '<th style="background:#4f46e5;color:#fff;padding:8px;">' . esc_html(ucwords(str_replace('_', ' ', $h))) . '</th>'; }
            echo '</tr>';
            foreach ($data as $row) {
                echo '<tr>';
                foreach ($row as $cell) { echo '<td style="padding:6px;">' . esc_html($cell) . '</td>'; }
                echo '</tr>';
            }
        }
        echo '</table></body></html>';
    }

    private function export_users_pdf($data, $filename) {
        header('Content-Type: text/html; charset=utf-8');
        $title = get_option('matrix_mlm_site_title', 'Matrix MLM Pro') . ' - Users Report';
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . esc_html($title) . '</title>';
        echo '<style>body{font-family:Arial,sans-serif;margin:20px;font-size:12px;}h1{font-size:18px;color:#4f46e5;}table{width:100%;border-collapse:collapse;margin-top:15px;}th{background:#4f46e5;color:#fff;padding:6px 8px;text-align:left;font-size:11px;}td{padding:5px 8px;border-bottom:1px solid #e5e7eb;font-size:11px;}tr:nth-child(even){background:#f9fafb;}.no-print{margin-bottom:15px;}@media print{.no-print{display:none;}}</style>';
        echo '</head><body>';
        echo '<div class="no-print"><button onclick="window.print()" style="padding:8px 16px;background:#4f46e5;color:#fff;border:none;border-radius:4px;cursor:pointer;">Print / Save as PDF</button></div>';
        echo '<h1>' . esc_html($title) . '</h1>';
        echo '<p style="color:#666;font-size:11px;">Generated: ' . date('F d, Y H:i:s') . ' | Records: ' . count($data) . '</p>';
        if (!empty($data)) {
            echo '<table><thead><tr>';
            foreach (array_keys($data[0]) as $h) { echo '<th>' . esc_html(ucwords(str_replace('_', ' ', $h))) . '</th>'; }
            echo '</tr></thead><tbody>';
            foreach ($data as $row) { echo '<tr>'; foreach ($row as $cell) { echo '<td>' . esc_html($cell) . '</td>'; } echo '</tr>'; }
            echo '</tbody></table>';
        }
        echo '</body></html>';
    }

    private function export_users_json($data, $filename) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.json"');
        echo json_encode(['report' => 'users', 'generated_at' => current_time('mysql'), 'total_records' => count($data), 'filters' => array_filter(['status' => $_GET['status'] ?? '', 'date_from' => $_GET['date_from'] ?? '', 'date_to' => $_GET['date_to'] ?? '', 'search' => $_GET['s'] ?? '']), 'data' => $data], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
