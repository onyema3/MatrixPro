<?php
/**
 * Admin Messaging Moderation.
 *
 * Surfaces three things:
 *   1. Open report queue with one-click "Soft-delete message" and
 *      "Ban sender" actions.
 *   2. Active bans (read/unban from wp_usermeta).
 *   3. Module settings (rate limits, stripping toggle, attachments,
 *      edit/self-delete window, polling cadence, offline-recipient
 *      push delivery) — saved into the same Matrix_MLM_Messaging::SETTINGS_OPTION
 *      blob the runtime reads, with a separate Reset-to-Defaults form
 *      that deletes the option row outright so a future schema
 *      addition can lazily-default a freshly-reset install.
 *
 * The Settings tab fires the matrix_messaging_settings_saved action on
 * both save and reset, with ($new, $old) effective-merged settings
 * arrays, so audit-log / observability subscribers can record diffs
 * without polling the option.
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
                // Capture the previously-effective settings BEFORE we
                // overwrite, so the matrix_messaging_settings_saved
                // hook below can hand observers a precise before/after
                // diff without a separate read.
                $old = Matrix_MLM_Messaging::get_settings();
                $new = [];
                $new['enabled']                     = !empty($_POST['enabled']) ? 1 : 0;
                $new['rate_limit_per_minute']       = max(1, min(1000, (int) ($_POST['rate_limit_per_minute'] ?? $defaults['rate_limit_per_minute'])));
                $new['rate_limit_per_hour']         = max(1, min(100000, (int) ($_POST['rate_limit_per_hour'] ?? $defaults['rate_limit_per_hour'])));
                $new['strip_off_platform_contacts'] = !empty($_POST['strip_off_platform_contacts']) ? 1 : 0;
                $new['allow_attachments']           = !empty($_POST['allow_attachments']) ? 1 : 0;
                // Attachment cap is stored in BYTES (the runtime
                // checks raw byte length), but operators almost
                // always think in MB — typing 5242880 to mean
                // "5 MB" was a documented foot-gun. The form now
                // posts max_attachment_mb (decimal, two-place);
                // we round up to the nearest byte so 5 MB stays
                // exactly 5 MiB. The legacy max_attachment_bytes
                // POST field is still accepted as a fallback for
                // any external automation that was already
                // saving via this form's URL.
                if (isset($_POST['max_attachment_mb']) && $_POST['max_attachment_mb'] !== '') {
                    $mb = (float) $_POST['max_attachment_mb'];
                    if ($mb < 0) {
                        $mb = 0;
                    }
                    if ($mb > 100) {
                        $mb = 100; // 100 MB hard ceiling regardless of what was typed
                    }
                    $new['max_attachment_bytes'] = (int) round($mb * 1024 * 1024);
                } else {
                    $new['max_attachment_bytes'] = max(0, (int) ($_POST['max_attachment_bytes'] ?? $defaults['max_attachment_bytes']));
                }
                $new['team_rooms_auto_create']      = !empty($_POST['team_rooms_auto_create']) ? 1 : 0;
                $new['polling_interval_ms']        = max(2000, min(120000, (int) ($_POST['polling_interval_ms'] ?? $defaults['polling_interval_ms'])));
                // Sender-side edit / self-delete window. Clamped
                // 0..DAY_IN_SECONDS to mirror the runtime ceiling
                // in Matrix_MLM_Messaging::get_edit_window_seconds()
                // — any larger value the operator types here
                // would be silently downgraded by the runtime
                // anyway, so we snap it at the form layer for
                // honest UX. Zero is a legitimate value meaning
                // "no edits or self-deletes ever" (effectively
                // disables the feature without removing the menu).
                $new['edit_window_seconds']         = max(0, min(DAY_IN_SECONDS, (int) ($_POST['edit_window_seconds'] ?? $defaults['edit_window_seconds'])));
                // Push delivery for offline recipients.
                // - presence_window_seconds is clamped to 30..3600s
                //   (anything below 30s would race the bell-poll
                //   cadence; anything above an hour drains the
                //   email path of usefulness).
                // - offline_email_cooldown_seconds is clamped to
                //   0..86400s. Zero = "send for every message"
                //   (loud), one day = "at most one email per
                //   thread per day" (quiet).
                $new['presence_window_seconds']        = max(30, min(3600, (int) ($_POST['presence_window_seconds'] ?? $defaults['presence_window_seconds'])));
                $new['offline_email_enabled']          = !empty($_POST['offline_email_enabled']) ? 1 : 0;
                $new['offline_email_cooldown_seconds'] = max(0, min(86400, (int) ($_POST['offline_email_cooldown_seconds'] ?? $defaults['offline_email_cooldown_seconds'])));
                update_option(Matrix_MLM_Messaging::SETTINGS_OPTION, $new);
                /**
                 * Fires after the messaging settings option has been
                 * persisted from the admin Settings tab. Lets audit
                 * log / observability modules record a precise diff
                 * without polling the option, and is the integration
                 * point a future moderation surface uses to react
                 * to changes in clamp thresholds.
                 *
                 * Both arrays are post-merge with defaults, so
                 * subscribers can compare keys directly without
                 * re-running array_merge themselves.
                 *
                 * @param array $new The settings just persisted.
                 * @param array $old The previously-effective settings.
                 */
                do_action('matrix_messaging_settings_saved', $new, $old);
                add_settings_error('matrix_messaging', 'saved', __('Settings saved.', 'matrix-mlm'), 'updated');
                break;

            case 'reset_settings':
                // Restore defaults by deleting the option row
                // outright. The runtime getter applies defaults
                // lazily over an empty array, so this is the
                // cleanest way to ensure no stale key sneaks
                // through after a future schema addition.
                $old = Matrix_MLM_Messaging::get_settings();
                delete_option(Matrix_MLM_Messaging::SETTINGS_OPTION);
                do_action('matrix_messaging_settings_saved', Matrix_MLM_Messaging::default_settings(), $old);
                add_settings_error('matrix_messaging', 'reset', __('Settings reset to defaults.', 'matrix-mlm'), 'updated');
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
        // Default attachment cap is stored as bytes; render the
        // editor in MB to match how operators think. Two-decimal
        // precision so 5 MB → 5.00, 512 KB → 0.50, 0 → 0.00.
        $max_attachment_mb = round(((int) $s['max_attachment_bytes']) / (1024 * 1024), 2);
        settings_errors('matrix_messaging');
        ?>
        <p class="description" style="margin-top:20px;max-width:780px;">
            <?php esc_html_e('All values below are read live by the messaging runtime and the offline-push pipeline. Changes apply on next request — no cache flush needed. Out-of-range numbers are silently clamped to the documented limits shown beside each field.', 'matrix-mlm'); ?>
        </p>

        <form method="post" style="margin-top:20px;">
            <?php wp_nonce_field('matrix_messaging_admin', '_matrix_messaging_admin_nonce'); ?>
            <input type="hidden" name="matrix_messaging_admin_action" value="save_settings">

            <h2 class="title" style="margin-top:30px;"><?php esc_html_e('Module', 'matrix-mlm'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="matrix-msg-enabled"><?php esc_html_e('Enabled', 'matrix-mlm'); ?></label></th>
                    <td>
                        <label><input type="checkbox" id="matrix-msg-enabled" name="enabled" value="1" <?php checked(!empty($s['enabled'])); ?>> <?php esc_html_e('Allow members to message each other', 'matrix-mlm'); ?></label>
                        <p class="description"><?php esc_html_e('When unchecked, the messaging dashboard is hidden and all send / fetch endpoints respond with a disabled error. Existing threads are preserved.', 'matrix-mlm'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="matrix-msg-team-rooms"><?php esc_html_e('Auto-create team rooms', 'matrix-mlm'); ?></label></th>
                    <td>
                        <label><input type="checkbox" id="matrix-msg-team-rooms" name="team_rooms_auto_create" value="1" <?php checked(!empty($s['team_rooms_auto_create'])); ?>> <?php esc_html_e('Auto-create a team room per sponsor with their direct referrals', 'matrix-mlm'); ?></label>
                        <p class="description"><?php esc_html_e('Rooms are created lazily on first send; toggling this off will not delete existing rooms, only stop new ones from being provisioned.', 'matrix-mlm'); ?></p>
                    </td>
                </tr>
            </table>

            <h2 class="title" style="margin-top:30px;"><?php esc_html_e('Anti-Spam', 'matrix-mlm'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="matrix-msg-rate-min"><?php esc_html_e('Rate limit (per minute)', 'matrix-mlm'); ?></label></th>
                    <td>
                        <input type="number" id="matrix-msg-rate-min" name="rate_limit_per_minute" min="1" max="1000" value="<?php echo (int) $s['rate_limit_per_minute']; ?>">
                        <p class="description"><?php esc_html_e('Maximum messages a single member may send across all threads in any rolling 60-second window. Range 1–1000.', 'matrix-mlm'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="matrix-msg-rate-hr"><?php esc_html_e('Rate limit (per hour)', 'matrix-mlm'); ?></label></th>
                    <td>
                        <input type="number" id="matrix-msg-rate-hr" name="rate_limit_per_hour" min="1" max="100000" value="<?php echo (int) $s['rate_limit_per_hour']; ?>">
                        <p class="description"><?php esc_html_e('Hourly ceiling layered on top of the per-minute limit, to catch slow-burn spam that stays under the per-minute cap. Range 1–100000.', 'matrix-mlm'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Strip off-platform contacts', 'matrix-mlm'); ?></th>
                    <td>
                        <label><input type="checkbox" name="strip_off_platform_contacts" value="1" <?php checked(!empty($s['strip_off_platform_contacts'])); ?>> <?php esc_html_e('Replace emails / phone numbers / external URLs with [contact removed]', 'matrix-mlm'); ?></label>
                        <p class="description"><?php esc_html_e('Applied at send time only — historical messages are not rewritten if the toggle is enabled later. Conservative regex; bare numeric strings are not stripped.', 'matrix-mlm'); ?></p>
                    </td>
                </tr>
            </table>

            <h2 class="title" style="margin-top:30px;"><?php esc_html_e('Attachments', 'matrix-mlm'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Allow attachments', 'matrix-mlm'); ?></th>
                    <td>
                        <label><input type="checkbox" name="allow_attachments" value="1" <?php checked(!empty($s['allow_attachments'])); ?>> <?php esc_html_e('Allow image attachments via signed URLs', 'matrix-mlm'); ?></label>
                        <p class="description"><?php esc_html_e('When unchecked, the upload control is hidden in the sender UI and uploaded files are rejected at the validation layer. Existing attachments stay viewable.', 'matrix-mlm'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="matrix-msg-attach-mb"><?php esc_html_e('Max attachment size', 'matrix-mlm'); ?></label></th>
                    <td>
                        <input type="number" id="matrix-msg-attach-mb" name="max_attachment_mb" min="0" max="100" step="0.1" value="<?php echo esc_attr($max_attachment_mb); ?>"> <?php esc_html_e('MB', 'matrix-mlm'); ?>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: byte count of the current cap, formatted with thousand separators. */
                                esc_html__('Per-attachment ceiling. The runtime checks against the byte value (currently %s bytes). Range 0–100 MB; the typical PHP upload_max_filesize on shared hosting is the practical lower limit.', 'matrix-mlm'),
                                '<code>' . esc_html(number_format_i18n((int) $s['max_attachment_bytes'])) . '</code>'
                            );
                            ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h2 class="title" style="margin-top:30px;"><?php esc_html_e('Edit / Self-Delete Window', 'matrix-mlm'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="matrix-msg-edit-window"><?php esc_html_e('Edit window (seconds)', 'matrix-mlm'); ?></label></th>
                    <td>
                        <input type="number" id="matrix-msg-edit-window" name="edit_window_seconds" min="0" max="<?php echo (int) DAY_IN_SECONDS; ?>" value="<?php echo (int) $s['edit_window_seconds']; ?>">
                        <p class="description">
                            <?php
                            printf(
                                /* translators: 1: default in seconds, 2: hard ceiling in seconds (DAY_IN_SECONDS). */
                                esc_html__('How long after sending a member may edit or self-delete one of their own messages. Same window gates both actions. Default %1$ds, hard ceiling %2$ds (24 hours). Set to 0 to disable edits and self-deletes entirely.', 'matrix-mlm'),
                                (int) Matrix_MLM_Messaging::EDIT_WINDOW_SECONDS_DEFAULT,
                                (int) DAY_IN_SECONDS
                            );
                            ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h2 class="title" style="margin-top:30px;"><?php esc_html_e('Polling', 'matrix-mlm'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="matrix-msg-poll"><?php esc_html_e('Polling interval (ms)', 'matrix-mlm'); ?></label></th>
                    <td>
                        <input type="number" id="matrix-msg-poll" name="polling_interval_ms" min="2000" max="120000" value="<?php echo (int) $s['polling_interval_ms']; ?>">
                        <p class="description"><?php esc_html_e('How often the dashboard JS asks the server for new messages on the open thread. Range 2000–120000 ms (2s–2min). Lower values feel snappier but multiply server load by the active-tab count.', 'matrix-mlm'); ?></p>
                    </td>
                </tr>
            </table>

            <h2 class="title" style="margin-top:30px;"><?php esc_html_e('Push Delivery (Offline Recipients)', 'matrix-mlm'); ?></h2>
            <p class="description" style="max-width:780px;">
                <?php esc_html_e('When a recipient is not currently on the dashboard, push the message via email (and SMS when SMS notifications are enabled). Online recipients always receive an in-app bell badge update on the next poll, regardless of these settings.', 'matrix-mlm'); ?>
            </p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="matrix-msg-presence"><?php esc_html_e('Presence window (seconds)', 'matrix-mlm'); ?></label></th>
                    <td>
                        <input type="number" id="matrix-msg-presence" name="presence_window_seconds" min="30" max="3600" value="<?php echo (int) $s['presence_window_seconds']; ?>">
                        <p class="description"><?php esc_html_e('A user is considered online if they have hit any messaging or notifications endpoint within this window. Range 30–3600s. Default 120 seconds.', 'matrix-mlm'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Email offline recipients', 'matrix-mlm'); ?></th>
                    <td>
                        <label><input type="checkbox" name="offline_email_enabled" value="1" <?php checked(!empty($s['offline_email_enabled'])); ?>> <?php esc_html_e('Send a "new message" email (and SMS, if enabled) when the recipient is not currently online', 'matrix-mlm'); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="matrix-msg-cooldown"><?php esc_html_e('Email cooldown per thread (seconds)', 'matrix-mlm'); ?></label></th>
                    <td>
                        <input type="number" id="matrix-msg-cooldown" name="offline_email_cooldown_seconds" min="0" max="86400" value="<?php echo (int) $s['offline_email_cooldown_seconds']; ?>">
                        <p class="description"><?php esc_html_e('Suppress repeat emails for the same thread within this window so a burst of messages from one sender does not generate one email per message. Range 0–86400s. Set to 0 to email every message. Default 600 seconds (10 minutes).', 'matrix-mlm'); ?></p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary"><?php esc_html_e('Save Settings', 'matrix-mlm'); ?></button>
            </p>
        </form>

        <hr style="margin-top:40px;">

        <h2 class="title"><?php esc_html_e('Reset to Defaults', 'matrix-mlm'); ?></h2>
        <p class="description" style="max-width:780px;">
            <?php esc_html_e('Discards every setting above and restores the documented defaults. Useful when a misconfigured value is preventing the messaging UI from loading and the offending key is no longer obvious. The reset is logged via the matrix_messaging_settings_saved action so audit log subscribers see it.', 'matrix-mlm'); ?>
        </p>
        <form method="post" onsubmit="return confirm('<?php echo esc_js(__('Reset all messaging settings to their documented defaults? This cannot be undone.', 'matrix-mlm')); ?>');">
            <?php wp_nonce_field('matrix_messaging_admin', '_matrix_messaging_admin_nonce'); ?>
            <input type="hidden" name="matrix_messaging_admin_action" value="reset_settings">
            <p>
                <button type="submit" class="button"><?php esc_html_e('Reset to Defaults', 'matrix-mlm'); ?></button>
            </p>
        </form>
        <?php
    }
}
