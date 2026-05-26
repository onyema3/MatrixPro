<?php
/**
 * Admin Messaging Moderation.
 *
 * Surfaces three things:
 *   1. Open report queue with one-click "Soft-delete message" and
 *      "Ban sender" actions.
 *   2. Active bans (read/unban from wp_usermeta).
 *   3. Module settings (rate limits, stripping toggle, etc.) — saved
 *      into the same Matrix_MLM_Messaging::SETTINGS_OPTION blob the
 *      runtime reads.
 *
 * Capability: manage_matrix_messaging — granted to administrator on
 * activation. Falls back to manage_options for super-admins on multisite.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Messaging {

    const CAP = 'manage_matrix_messaging';

    public function render() {
        if (!current_user_can(self::CAP) && !current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'matrix-mlm'));
        }

        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'reports';
        $tab = in_array($tab, ['reports', 'bans', 'settings'], true) ? $tab : 'reports';

        $this->maybe_handle_post();

        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php esc_html_e('Messaging Moderation', 'matrix-mlm'); ?></h1>

            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url(admin_url('admin.php?page=matrix-mlm-messaging&tab=reports')); ?>"
                   class="nav-tab <?php echo $tab === 'reports' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Reports', 'matrix-mlm'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=matrix-mlm-messaging&tab=bans')); ?>"
                   class="nav-tab <?php echo $tab === 'bans' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Bans', 'matrix-mlm'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=matrix-mlm-messaging&tab=settings')); ?>"
                   class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Settings', 'matrix-mlm'); ?>
                </a>
            </h2>

            <?php
            switch ($tab) {
                case 'bans':     $this->render_bans();     break;
                case 'settings': $this->render_settings(); break;
                default:         $this->render_reports();
            }
            ?>
        </div>
        <?php
    }

    private function maybe_handle_post() {
        if (empty($_POST['_matrix_messaging_admin_nonce'])
            || !wp_verify_nonce($_POST['_matrix_messaging_admin_nonce'], 'matrix_messaging_admin')) {
            return;
        }
        if (!current_user_can(self::CAP) && !current_user_can('manage_options')) {
            return;
        }

        global $wpdb;
        $action = sanitize_key($_POST['matrix_messaging_admin_action'] ?? '');

        switch ($action) {
            case 'resolve_report':
                $report_id   = (int) ($_POST['report_id'] ?? 0);
                $resolution  = sanitize_key($_POST['resolution'] ?? 'no_action');
                $delete_msg  = !empty($_POST['delete_message']);
                $ban_sender  = !empty($_POST['ban_sender']);

                $report = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}matrix_message_reports WHERE id = %d",
                    $report_id
                ));
                if (!$report) break;

                if ($delete_msg) {
                    $wpdb->update(
                        $wpdb->prefix . 'matrix_messages',
                        ['deleted_at' => current_time('mysql', true), 'deleted_by' => get_current_user_id()],
                        ['id' => (int) $report->message_id]
                    );
                }
                if ($ban_sender) {
                    $msg = $wpdb->get_row($wpdb->prepare(
                        "SELECT sender_id FROM {$wpdb->prefix}matrix_messages WHERE id = %d",
                        (int) $report->message_id
                    ));
                    if ($msg) {
                        update_user_meta((int) $msg->sender_id, Matrix_MLM_Messaging::BAN_META_UNTIL, 'permanent');
                        update_user_meta((int) $msg->sender_id, Matrix_MLM_Messaging::BAN_META_REASON, 'Admin moderation action on report #' . $report_id);
                    }
                }
                $wpdb->update(
                    $wpdb->prefix . 'matrix_message_reports',
                    [
                        'resolved_at' => current_time('mysql', true),
                        'resolved_by' => get_current_user_id(),
                        'resolution'  => $resolution,
                    ],
                    ['id' => $report_id]
                );
                add_settings_error('matrix_messaging', 'resolved', __('Report resolved.', 'matrix-mlm'), 'updated');
                break;

            case 'lift_ban':
                $user_id = (int) ($_POST['user_id'] ?? 0);
                if ($user_id) {
                    delete_user_meta($user_id, Matrix_MLM_Messaging::BAN_META_UNTIL);
                    delete_user_meta($user_id, Matrix_MLM_Messaging::BAN_META_REASON);
                    add_settings_error('matrix_messaging', 'unbanned', __('Ban lifted.', 'matrix-mlm'), 'updated');
                }
                break;

            case 'save_settings':
                $defaults = Matrix_MLM_Messaging::default_settings();
                $new = [];
                $new['enabled']                     = !empty($_POST['enabled']) ? 1 : 0;
                $new['rate_limit_per_minute']       = max(1, min(1000, (int) ($_POST['rate_limit_per_minute'] ?? $defaults['rate_limit_per_minute'])));
                $new['rate_limit_per_hour']         = max(1, min(100000, (int) ($_POST['rate_limit_per_hour'] ?? $defaults['rate_limit_per_hour'])));
                $new['strip_off_platform_contacts'] = !empty($_POST['strip_off_platform_contacts']) ? 1 : 0;
                $new['allow_attachments']           = !empty($_POST['allow_attachments']) ? 1 : 0;
                $new['max_attachment_bytes']        = max(0, (int) ($_POST['max_attachment_bytes'] ?? $defaults['max_attachment_bytes']));
                $new['team_rooms_auto_create']      = !empty($_POST['team_rooms_auto_create']) ? 1 : 0;
                $new['polling_interval_ms']        = max(2000, min(120000, (int) ($_POST['polling_interval_ms'] ?? $defaults['polling_interval_ms'])));
                update_option(Matrix_MLM_Messaging::SETTINGS_OPTION, $new);
                add_settings_error('matrix_messaging', 'saved', __('Settings saved.', 'matrix-mlm'), 'updated');
                break;
        }
    }

    private function render_reports() {
        global $wpdb;
        $status = isset($_GET['status']) && $_GET['status'] === 'resolved' ? 'resolved' : 'open';
        settings_errors('matrix_messaging');

        $where = $status === 'resolved' ? 'r.resolved_at IS NOT NULL' : 'r.resolved_at IS NULL';
        $rows = $wpdb->get_results("
            SELECT r.*, m.body, m.sender_id, m.thread_id, m.deleted_at,
                   u_r.user_login AS reporter_login,
                   u_s.user_login AS sender_login
              FROM {$wpdb->prefix}matrix_message_reports r
              LEFT JOIN {$wpdb->prefix}matrix_messages m ON m.id = r.message_id
              LEFT JOIN {$wpdb->users} u_r ON u_r.ID = r.reporter_id
              LEFT JOIN {$wpdb->users} u_s ON u_s.ID = m.sender_id
             WHERE $where
             ORDER BY r.created_at DESC
             LIMIT 100
        ");
        ?>
        <ul class="subsubsub">
            <li><a href="<?php echo esc_url(admin_url('admin.php?page=matrix-mlm-messaging&tab=reports&status=open')); ?>"
                   class="<?php echo $status === 'open' ? 'current' : ''; ?>"><?php esc_html_e('Open', 'matrix-mlm'); ?></a> |</li>
            <li><a href="<?php echo esc_url(admin_url('admin.php?page=matrix-mlm-messaging&tab=reports&status=resolved')); ?>"
                   class="<?php echo $status === 'resolved' ? 'current' : ''; ?>"><?php esc_html_e('Resolved', 'matrix-mlm'); ?></a></li>
        </ul>

        <table class="wp-list-table widefat fixed striped" style="margin-top:30px;">
            <thead><tr>
                <th>#</th>
                <th><?php esc_html_e('Reporter', 'matrix-mlm'); ?></th>
                <th><?php esc_html_e('Sender', 'matrix-mlm'); ?></th>
                <th><?php esc_html_e('Reason', 'matrix-mlm'); ?></th>
                <th><?php esc_html_e('Message', 'matrix-mlm'); ?></th>
                <th><?php esc_html_e('Created', 'matrix-mlm'); ?></th>
                <th><?php esc_html_e('Action', 'matrix-mlm'); ?></th>
            </tr></thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="7"><?php esc_html_e('No reports.', 'matrix-mlm'); ?></td></tr>
                <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td>#<?php echo (int) $r->id; ?></td>
                        <td><?php echo esc_html($r->reporter_login ?: '—'); ?></td>
                        <td><?php echo esc_html($r->sender_login ?: '—'); ?></td>
                        <td><?php echo esc_html($r->reason); ?></td>
                        <td>
                            <?php if ($r->deleted_at): ?>
                                <em><?php esc_html_e('(message deleted)', 'matrix-mlm'); ?></em>
                            <?php else: ?>
                                <?php echo esc_html(mb_substr((string) $r->body, 0, 200)); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($r->created_at); ?></td>
                        <td>
                            <?php if ($status === 'open'): ?>
                                <form method="post" style="display:inline-block;">
                                    <?php wp_nonce_field('matrix_messaging_admin', '_matrix_messaging_admin_nonce'); ?>
                                    <input type="hidden" name="matrix_messaging_admin_action" value="resolve_report">
                                    <input type="hidden" name="report_id" value="<?php echo (int) $r->id; ?>">
                                    <select name="resolution">
                                        <option value="no_action"><?php esc_html_e('No action', 'matrix-mlm'); ?></option>
                                        <option value="message_deleted"><?php esc_html_e('Delete message', 'matrix-mlm'); ?></option>
                                        <option value="user_banned"><?php esc_html_e('Ban sender', 'matrix-mlm'); ?></option>
                                    </select>
                                    <label><input type="checkbox" name="delete_message" value="1"> <?php esc_html_e('Delete msg', 'matrix-mlm'); ?></label>
                                    <label><input type="checkbox" name="ban_sender" value="1"> <?php esc_html_e('Ban sender', 'matrix-mlm'); ?></label>
                                    <button type="submit" class="button"><?php esc_html_e('Resolve', 'matrix-mlm'); ?></button>
                                </form>
                            <?php else: ?>
                                <span class="matrix-badge matrix-badge-info"><?php echo esc_html($r->resolution ?: '—'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_bans() {
        global $wpdb;
        settings_errors('matrix_messaging');
        $banned_user_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> ''",
            Matrix_MLM_Messaging::BAN_META_UNTIL
        ));
        ?>
        <table class="wp-list-table widefat fixed striped" style="margin-top:30px;">
            <thead><tr>
                <th><?php esc_html_e('User', 'matrix-mlm'); ?></th>
                <th><?php esc_html_e('Banned until', 'matrix-mlm'); ?></th>
                <th><?php esc_html_e('Reason', 'matrix-mlm'); ?></th>
                <th><?php esc_html_e('Action', 'matrix-mlm'); ?></th>
            </tr></thead>
            <tbody>
                <?php if (empty($banned_user_ids)): ?>
                    <tr><td colspan="4"><?php esc_html_e('No active bans.', 'matrix-mlm'); ?></td></tr>
                <?php else: foreach ($banned_user_ids as $uid): $u = get_userdata($uid); ?>
                    <tr>
                        <td><?php echo esc_html($u ? $u->user_login : '#' . $uid); ?></td>
                        <td><?php echo esc_html(get_user_meta($uid, Matrix_MLM_Messaging::BAN_META_UNTIL, true)); ?></td>
                        <td><?php echo esc_html(get_user_meta($uid, Matrix_MLM_Messaging::BAN_META_REASON, true)); ?></td>
                        <td>
                            <form method="post" style="display:inline-block;">
                                <?php wp_nonce_field('matrix_messaging_admin', '_matrix_messaging_admin_nonce'); ?>
                                <input type="hidden" name="matrix_messaging_admin_action" value="lift_ban">
                                <input type="hidden" name="user_id" value="<?php echo (int) $uid; ?>">
                                <button class="button"><?php esc_html_e('Lift ban', 'matrix-mlm'); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_settings() {
        $s = Matrix_MLM_Messaging::get_settings();
        settings_errors('matrix_messaging');
        ?>
        <form method="post" style="margin-top:30px;">
            <?php wp_nonce_field('matrix_messaging_admin', '_matrix_messaging_admin_nonce'); ?>
            <input type="hidden" name="matrix_messaging_admin_action" value="save_settings">
            <table class="form-table">
                <tr><th><?php esc_html_e('Enabled', 'matrix-mlm'); ?></th>
                    <td><label><input type="checkbox" name="enabled" value="1" <?php checked(!empty($s['enabled'])); ?>> <?php esc_html_e('Allow members to message each other', 'matrix-mlm'); ?></label></td></tr>
                <tr><th><?php esc_html_e('Rate limit (per minute)', 'matrix-mlm'); ?></th>
                    <td><input type="number" name="rate_limit_per_minute" min="1" max="1000" value="<?php echo (int) $s['rate_limit_per_minute']; ?>"></td></tr>
                <tr><th><?php esc_html_e('Rate limit (per hour)', 'matrix-mlm'); ?></th>
                    <td><input type="number" name="rate_limit_per_hour" min="1" max="100000" value="<?php echo (int) $s['rate_limit_per_hour']; ?>"></td></tr>
                <tr><th><?php esc_html_e('Strip off-platform contacts', 'matrix-mlm'); ?></th>
                    <td><label><input type="checkbox" name="strip_off_platform_contacts" value="1" <?php checked(!empty($s['strip_off_platform_contacts'])); ?>> <?php esc_html_e('Replace emails / phone numbers / external URLs with [contact removed]', 'matrix-mlm'); ?></label></td></tr>
                <tr><th><?php esc_html_e('Allow attachments', 'matrix-mlm'); ?></th>
                    <td><label><input type="checkbox" name="allow_attachments" value="1" <?php checked(!empty($s['allow_attachments'])); ?>> <?php esc_html_e('Allow image attachments via signed URLs', 'matrix-mlm'); ?></label></td></tr>
                <tr><th><?php esc_html_e('Max attachment size (bytes)', 'matrix-mlm'); ?></th>
                    <td><input type="number" name="max_attachment_bytes" min="0" value="<?php echo (int) $s['max_attachment_bytes']; ?>"></td></tr>
                <tr><th><?php esc_html_e('Auto-create team rooms', 'matrix-mlm'); ?></th>
                    <td><label><input type="checkbox" name="team_rooms_auto_create" value="1" <?php checked(!empty($s['team_rooms_auto_create'])); ?>> <?php esc_html_e('Auto-create a team room per sponsor with their direct referrals', 'matrix-mlm'); ?></label></td></tr>
                <tr><th><?php esc_html_e('Polling interval (ms)', 'matrix-mlm'); ?></th>
                    <td><input type="number" name="polling_interval_ms" min="2000" max="120000" value="<?php echo (int) $s['polling_interval_ms']; ?>"></td></tr>
            </table>
            <p><button type="submit" class="button button-primary"><?php esc_html_e('Save Settings', 'matrix-mlm'); ?></button></p>
        </form>
        <?php
    }
}
