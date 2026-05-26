<?php
/**
 * Admin Messaging Moderation.
 *
 * Surfaces three things:
 *   1. Open report queue, grouped by message so a single spam payload
 *      reported by N members renders as one actionable row. Each row
 *      offers a single-radio resolution (Dismiss / Delete / Delete + Ban)
 *      with a duration picker that's only consulted on the ban path.
 *      Resolving a grouped row clears every open report for that
 *      message in one UPDATE so the queue empties cleanly. Filters by
 *      reason and sender, paginated 25 per page.
 *   2. Active bans queue, with banned_at / banned_by columns surfaced
 *      from the BAN_META_AT / BAN_META_BY metadata that
 *      Matrix_MLM_Messaging::ban_user() writes alongside the
 *      until/reason pair. Includes a direct "Add Ban" form for the
 *      out-of-band case where a user was reported via support ticket
 *      or email and there is no in-app report row to resolve.
 *   3. Module settings (rate limits, stripping toggle, attachments,
 *      edit/self-delete window, polling cadence, offline-recipient
 *      push delivery) — saved into the same Matrix_MLM_Messaging::SETTINGS_OPTION
 *      blob the runtime reads, with a separate Reset-to-Defaults form
 *      that deletes the option row outright.
 *
 * Both ban and unban writes route through Matrix_MLM_Messaging::ban_user
 * and unban_user, which fire matrix_messaging_user_banned and
 * matrix_messaging_user_unbanned actions so audit-log subscribers can
 * react without observing wp_usermeta. The Settings tab fires
 * matrix_messaging_settings_saved on save and reset.
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

    /**
     * Translate a duration slug from the admin form into the value
     * Matrix_MLM_Messaging::ban_user() expects in $until.
     *
     * Slugs are kept short for URL-friendliness on the form POST.
     * Anything unrecognised falls through to 'permanent' rather than
     * silently downgrading to a finite value, because a typo'd or
     * tampered slug should fail in the safer direction (longer ban,
     * which an admin can lift in one click) rather than the unsafe
     * direction (shorter ban or none, which a malicious sender could
     * exploit by rotating the slug to "" or "0d").
     */
    private static function ban_duration_to_until($duration) {
        switch ($duration) {
            case '1d':  return gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS);
            case '7d':  return gmdate('Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS);
            case '30d': return gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS);
            case 'permanent':
            default:    return 'permanent';
        }
    }

    /**
     * Allowed ban-duration slugs. Single source of truth for both the
     * resolve form and the direct add-ban form.
     */
    private static function allowed_ban_durations() {
        return ['1d' => '1 day', '7d' => '7 days', '30d' => '30 days', 'permanent' => 'Permanent'];
    }

    /**
     * Pull the last $limit messages in $thread_id whose id is <=
     * $around_message_id, ordered oldest-first for natural reading.
     *
     * Used by the "View thread context" expander in the open report
     * row. Lets a moderator see the conversational lead-up to the
     * reported message — distinguishes "out-of-the-blue spam" from
     * "the recipient just provoked them" in a way the single-row
     * 200-char body excerpt cannot. Capped at 10 messages by
     * default (the operator can always click into the dashboard
     * thread for the full history) so the LIMIT-10 query stays
     * sub-millisecond on the (thread_id, id) covering index even
     * across very long team rooms.
     *
     * Each row gets joined to wp_users for the sender_login so
     * the rendered context doesn't fan into N user lookups in the
     * loop.
     *
     * @param int $thread_id
     * @param int $around_message_id The reported message id; the
     *                               window includes this id and the
     *                               $limit-1 messages immediately
     *                               before it.
     * @param int $limit
     * @return array
     */
    private function fetch_thread_context($thread_id, $around_message_id, $limit = 10) {
        global $wpdb;
        $thread_id         = (int) $thread_id;
        $around_message_id = (int) $around_message_id;
        $limit             = max(1, min(50, (int) $limit));
        if ($thread_id <= 0 || $around_message_id <= 0) {
            return [];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.id, m.body, m.created_at, m.deleted_at, m.sender_id, u.user_login AS sender_login
               FROM {$wpdb->prefix}matrix_messages m
               LEFT JOIN {$wpdb->users} u ON u.ID = m.sender_id
              WHERE m.thread_id = %d
                AND m.id <= %d
              ORDER BY m.id DESC
              LIMIT %d",
            $thread_id, $around_message_id, $limit
        ));
        return array_reverse($rows ?: []);
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
                // Resolution model:
                //   no_action      — dismiss with no side effects
                //   message_deleted — soft-delete the message
                //   user_banned    — soft-delete + ban the sender
                //                    (delete is implied; banning a
                //                    user without removing the
                //                    offending content would leave
                //                    the spam visible to recipients
                //                    who haven't refreshed)
                //
                // Scope: report_id (single report) OR message_id
                // (every open report for that message). Grouped view
                // posts message_id; single view posts report_id. We
                // accept either, prefer report_id when both are
                // present.
                $report_id    = (int) ($_POST['report_id'] ?? 0);
                $message_id   = (int) ($_POST['message_id'] ?? 0);
                $resolution   = self::sanitize_resolution($_POST['resolution'] ?? 'no_action');
                $ban_duration = sanitize_key($_POST['ban_duration'] ?? 'permanent');
                if ($report_id <= 0 && $message_id <= 0) {
                    break;
                }

                // Resolve message_id from report row when caller
                // gave us one but not the other.
                if ($message_id <= 0 && $report_id > 0) {
                    $message_id = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT message_id FROM {$wpdb->prefix}matrix_message_reports WHERE id = %d",
                        $report_id
                    ));
                }
                if ($message_id <= 0) {
                    break;
                }

                self::apply_resolution_to_message($message_id, $resolution, $ban_duration, $report_id ?: null);
                add_settings_error('matrix_messaging', 'resolved', __('Report resolved.', 'matrix-mlm'), 'updated');
                break;

            case 'bulk_resolve_reports':
                // Bulk action from the open-reports table. The
                // open queue is grouped by message_id (see the
                // GROUP BY in render_reports), so bulk selection
                // is naturally over message_ids — one checkbox
                // per visible row, one row per message no matter
                // how many distinct reporters flagged it. We
                // loop the same per-message helper that single
                // resolve uses so the audit shape (delete +
                // optional ban + matrix_messaging_user_banned
                // hook) is identical.
                //
                // Hard cap at the page size (25). The form only
                // ever surfaces 25 checkboxes per page anyway, so
                // a request claiming to resolve 500 in one POST
                // is either a malformed retry or a curl probe —
                // either way clamping is safer than honoring it.
                $resolution   = self::sanitize_resolution($_POST['bulk_resolution'] ?? 'no_action');
                $ban_duration = sanitize_key($_POST['bulk_ban_duration'] ?? 'permanent');
                $raw_ids      = isset($_POST['report_message_ids']) && is_array($_POST['report_message_ids'])
                    ? (array) $_POST['report_message_ids']
                    : [];
                $message_ids  = [];
                foreach ($raw_ids as $rid) {
                    $rid = (int) $rid;
                    if ($rid > 0) {
                        $message_ids[$rid] = $rid; // dedupe
                    }
                }
                $message_ids = array_values($message_ids);
                if (count($message_ids) > 25) {
                    $message_ids = array_slice($message_ids, 0, 25);
                }
                if (empty($message_ids)) {
                    add_settings_error(
                        'matrix_messaging',
                        'bulk_none',
                        __('Select at least one report to resolve.', 'matrix-mlm')
                    );
                    break;
                }

                $count = 0;
                foreach ($message_ids as $mid) {
                    self::apply_resolution_to_message($mid, $resolution, $ban_duration, null);
                    $count++;
                }
                add_settings_error(
                    'matrix_messaging',
                    'bulk_resolved',
                    sprintf(
                        /* translators: %d: number of grouped report rows resolved in one bulk action. */
                        _n('%d report resolved.', '%d reports resolved.', $count, 'matrix-mlm'),
                        (int) $count
                    ),
                    'updated'
                );
                break;

            case 'lift_ban':
                $user_id = (int) ($_POST['user_id'] ?? 0);
                if ($user_id) {
                    Matrix_MLM_Messaging::unban_user($user_id, ['source' => 'admin_ui']);
                    add_settings_error('matrix_messaging', 'unbanned', __('Ban lifted.', 'matrix-mlm'), 'updated');
                }
                break;

            case 'add_ban':
                // Direct ban from the Bans tab — bypass the report
                // queue when an admin already knows who they want
                // to silence (e.g. a user reported via support
                // ticket rather than the in-app report button).
                $user_login = isset($_POST['user_login']) ? sanitize_user(wp_unslash($_POST['user_login'])) : '';
                $duration   = sanitize_key($_POST['ban_duration'] ?? 'permanent');
                $reason_in  = isset($_POST['ban_reason']) ? wp_unslash($_POST['ban_reason']) : '';
                $reason     = mb_substr(sanitize_text_field((string) $reason_in), 0, 255);
                if ($user_login === '') {
                    add_settings_error('matrix_messaging', 'addban_no_login', __('Username is required.', 'matrix-mlm'));
                    break;
                }
                $user = get_user_by('login', $user_login);
                if (!$user) {
                    add_settings_error('matrix_messaging', 'addban_no_user', __('No user found with that username.', 'matrix-mlm'));
                    break;
                }
                $until_value = self::ban_duration_to_until($duration);
                if ($reason === '') {
                    $reason = __('Direct admin ban (no report)', 'matrix-mlm');
                }
                $result = Matrix_MLM_Messaging::ban_user(
                    (int) $user->ID,
                    $until_value,
                    $reason,
                    ['source' => 'admin_ui']
                );
                if (is_wp_error($result)) {
                    add_settings_error('matrix_messaging', 'addban_failed', $result->get_error_message());
                } else {
                    add_settings_error('matrix_messaging', 'addban_ok', __('Ban placed.', 'matrix-mlm'), 'updated');
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

    /**
     * Whitelist a resolution value posted from any of the resolve
     * forms (single or bulk). Anything not on the whitelist falls
     * back to no_action — the safest default because it has no
     * side effects on the message or the sender.
     *
     * Centralised so single-resolve and bulk-resolve cannot
     * silently drift apart on what counts as a valid resolution
     * (a future fourth value, e.g. "shadow_ban", only needs to
     * land here once and both code paths pick it up).
     */
    private static function sanitize_resolution($raw) {
        $val = sanitize_key((string) $raw);
        return in_array($val, ['no_action', 'message_deleted', 'user_banned'], true)
            ? $val
            : 'no_action';
    }

    /**
     * Apply a single message-scoped resolution. Replicates the
     * exact behaviour the per-row "Resolve" button has always
     * had:
     *   - If the message still exists and resolution implies
     *     deletion, soft-delete it (deleted_at + deleted_by).
     *   - If resolution is user_banned and the sender is
     *     known, route through Matrix_MLM_Messaging::ban_user
     *     so the matrix_messaging_user_banned hook fires and
     *     audit subscribers see one ban event per resolved
     *     message — bulk-resolving five messages from one user
     *     is FIVE bans worth of audit signal, which matches
     *     reality (each message was a separate moderator
     *     decision, even if they were grouped on screen).
     *   - Mark every open report for that message resolved.
     *     If $report_id is supplied (single-row legacy path),
     *     resolve ONLY that report row and leave any siblings
     *     open for separate review. Otherwise close them all,
     *     matching the grouped view's mental model where one
     *     "Resolve" click on a row that says "12 reports" had
     *     better clear all 12.
     *
     * Side-effect free if the message has already been hard-
     * deleted by the cleanup cron — the report rows are still
     * marked resolved so the queue can drain rather than
     * accumulating zombie entries forever.
     *
     * @param int      $message_id   Target message id.
     * @param string   $resolution   Pre-sanitised resolution slug.
     * @param string   $ban_duration Duration slug; consulted only
     *                                when $resolution is user_banned.
     * @param int|null $report_id    Optional single-report scope.
     *                                When set, only that report row
     *                                is resolved; otherwise every
     *                                open report for the message is.
     */
    private static function apply_resolution_to_message($message_id, $resolution, $ban_duration, $report_id = null) {
        global $wpdb;
        $message_id = (int) $message_id;
        if ($message_id <= 0) {
            return;
        }

        $msg = $wpdb->get_row($wpdb->prepare(
            "SELECT id, sender_id, deleted_at FROM {$wpdb->prefix}matrix_messages WHERE id = %d",
            $message_id
        ));

        if ($msg && in_array($resolution, ['message_deleted', 'user_banned'], true) && empty($msg->deleted_at)) {
            $wpdb->update(
                $wpdb->prefix . 'matrix_messages',
                ['deleted_at' => current_time('mysql', true), 'deleted_by' => get_current_user_id()],
                ['id' => (int) $msg->id]
            );
        }
        if ($msg && $resolution === 'user_banned' && (int) $msg->sender_id > 0) {
            $until_value = self::ban_duration_to_until($ban_duration);
            $reason = sprintf(
                /* translators: %d: message id */
                __('Admin moderation action on message #%d', 'matrix-mlm'),
                (int) $msg->id
            );
            Matrix_MLM_Messaging::ban_user(
                (int) $msg->sender_id,
                $until_value,
                $reason,
                [
                    'source'     => 'report_resolution',
                    'message_id' => (int) $msg->id,
                    'report_id'  => $report_id ?: null,
                ]
            );
        }

        $now = current_time('mysql', true);
        $by  = (int) get_current_user_id();
        if ($report_id !== null && (int) $report_id > 0) {
            $wpdb->update(
                $wpdb->prefix . 'matrix_message_reports',
                ['resolved_at' => $now, 'resolved_by' => $by, 'resolution' => $resolution],
                ['id' => (int) $report_id]
            );
        } else {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}matrix_message_reports
                    SET resolved_at = %s, resolved_by = %d, resolution = %s
                  WHERE message_id = %d AND resolved_at IS NULL",
                $now,
                $by,
                $resolution,
                $message_id
            ));
        }
    }

    private function render_reports() {
        global $wpdb;

        // Filters from query string. All sanitised before use; the
        // sender filter goes through esc_like() before the LIKE.
        $status   = isset($_GET['status']) && $_GET['status'] === 'resolved' ? 'resolved' : 'open';
        $reason_f = isset($_GET['reason']) ? sanitize_key($_GET['reason']) : '';
        if ($reason_f !== '' && !in_array($reason_f, Matrix_MLM_Messaging::ALLOWED_REPORT_REASONS, true)) {
            $reason_f = '';
        }
        $sender_f = isset($_GET['sender']) ? sanitize_user(wp_unslash($_GET['sender'])) : '';
        $paged    = max(1, (int) ($_GET['paged'] ?? 1));
        $per_page = 25;
        $offset   = ($paged - 1) * $per_page;

        settings_errors('matrix_messaging');

        // Per-status WHERE. resolved_at IS NULL gates use the
        // resolved_at index added in DB 1.0.19.
        $where_parts = [$status === 'resolved' ? 'r.resolved_at IS NOT NULL' : 'r.resolved_at IS NULL'];
        $where_args  = [];
        if ($reason_f !== '') {
            $where_parts[] = 'r.reason = %s';
            $where_args[]  = $reason_f;
        }
        if ($sender_f !== '') {
            $where_parts[] = 'u_s.user_login LIKE %s';
            $where_args[]  = '%' . $wpdb->esc_like($sender_f) . '%';
        }
        $where = implode(' AND ', $where_parts);

        // The OPEN view groups by message_id so a single spam
        // message reported by 12 different members renders as one
        // actionable row instead of twelve. The RESOLVED view stays
        // ungrouped — each resolution is a distinct audit event and
        // an operator scanning history wants to see who reported
        // what, when, and what action was taken.
        if ($status === 'open') {
            $count_sql = "
                SELECT COUNT(*) FROM (
                    SELECT 1
                      FROM {$wpdb->prefix}matrix_message_reports r
                      LEFT JOIN {$wpdb->prefix}matrix_messages m ON m.id = r.message_id
                      LEFT JOIN {$wpdb->users} u_s ON u_s.ID = m.sender_id
                     WHERE $where
                     GROUP BY r.message_id
                ) sub";
            $rows_sql = "
                SELECT m.id   AS message_id,
                       m.body,
                       m.sender_id,
                       m.thread_id,
                       m.deleted_at,
                       COUNT(r.id) AS report_count,
                       MAX(r.created_at) AS last_reported_at,
                       GROUP_CONCAT(DISTINCT r.reason ORDER BY r.reason) AS reasons_csv,
                       u_s.user_login AS sender_login
                  FROM {$wpdb->prefix}matrix_message_reports r
                  LEFT JOIN {$wpdb->prefix}matrix_messages m ON m.id = r.message_id
                  LEFT JOIN {$wpdb->users} u_s ON u_s.ID = m.sender_id
                 WHERE $where
                 GROUP BY r.message_id
                 ORDER BY last_reported_at DESC
                 LIMIT %d OFFSET %d";
        } else {
            $count_sql = "
                SELECT COUNT(*)
                  FROM {$wpdb->prefix}matrix_message_reports r
                  LEFT JOIN {$wpdb->prefix}matrix_messages m ON m.id = r.message_id
                  LEFT JOIN {$wpdb->users} u_s ON u_s.ID = m.sender_id
                 WHERE $where";
            $rows_sql = "
                SELECT r.*, m.body, m.sender_id, m.thread_id, m.deleted_at,
                       u_r.user_login AS reporter_login,
                       u_s.user_login AS sender_login,
                       u_b.user_login AS resolver_login
                  FROM {$wpdb->prefix}matrix_message_reports r
                  LEFT JOIN {$wpdb->prefix}matrix_messages m ON m.id = r.message_id
                  LEFT JOIN {$wpdb->users} u_r ON u_r.ID = r.reporter_id
                  LEFT JOIN {$wpdb->users} u_s ON u_s.ID = m.sender_id
                  LEFT JOIN {$wpdb->users} u_b ON u_b.ID = r.resolved_by
                 WHERE $where
                 ORDER BY r.resolved_at DESC
                 LIMIT %d OFFSET %d";
        }

        // $wpdb->prepare requires at least one placeholder; if the
        // base WHERE has no filter args we call get_var/get_results
        // directly. Both branches still parameterise LIMIT/OFFSET.
        if (!empty($where_args)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- base SQL is static, only $where_args are user input.
            $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $where_args));
            $rows  = $wpdb->get_results($wpdb->prepare($rows_sql, array_merge($where_args, [$per_page, $offset])));
        } else {
            $total = (int) $wpdb->get_var($count_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rows  = $wpdb->get_results($wpdb->prepare($rows_sql, $per_page, $offset));
        }
        $total_pages = max(1, (int) ceil($total / $per_page));

        // Open-report counts per status, for the subsubsub.
        $count_open     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_message_reports WHERE resolved_at IS NULL");
        $count_resolved = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}matrix_message_reports WHERE resolved_at IS NOT NULL");

        $base_url = admin_url('admin.php?page=matrix-mlm-messaging&tab=reports');
        $self_url = add_query_arg(array_filter([
            'status' => $status,
            'reason' => $reason_f ?: null,
            'sender' => $sender_f ?: null,
        ], static function ($v) { return $v !== null && $v !== ''; }), $base_url);
        ?>
        <ul class="subsubsub" style="margin-top:14px;">
            <li><a href="<?php echo esc_url(add_query_arg('status', 'open', $base_url)); ?>"
                   class="<?php echo $status === 'open' ? 'current' : ''; ?>"><?php esc_html_e('Open', 'matrix-mlm'); ?>
                   <span class="count">(<?php echo (int) $count_open; ?>)</span></a> |</li>
            <li><a href="<?php echo esc_url(add_query_arg('status', 'resolved', $base_url)); ?>"
                   class="<?php echo $status === 'resolved' ? 'current' : ''; ?>"><?php esc_html_e('Resolved', 'matrix-mlm'); ?>
                   <span class="count">(<?php echo (int) $count_resolved; ?>)</span></a></li>
        </ul>

        <form method="get" style="clear:both;margin-top:10px;">
            <input type="hidden" name="page" value="matrix-mlm-messaging">
            <input type="hidden" name="tab" value="reports">
            <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>">
            <label style="margin-right:6px;">
                <?php esc_html_e('Reason:', 'matrix-mlm'); ?>
                <select name="reason">
                    <option value=""><?php esc_html_e('Any', 'matrix-mlm'); ?></option>
                    <?php foreach (Matrix_MLM_Messaging::ALLOWED_REPORT_REASONS as $rk): ?>
                        <option value="<?php echo esc_attr($rk); ?>" <?php selected($reason_f, $rk); ?>><?php echo esc_html($rk); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="margin-right:6px;">
                <?php esc_html_e('Sender:', 'matrix-mlm'); ?>
                <input type="search" name="sender" value="<?php echo esc_attr($sender_f); ?>" placeholder="<?php esc_attr_e('username', 'matrix-mlm'); ?>">
            </label>
            <button type="submit" class="button"><?php esc_html_e('Filter', 'matrix-mlm'); ?></button>
            <?php if ($reason_f !== '' || $sender_f !== ''): ?>
                <a class="button-link" href="<?php echo esc_url(add_query_arg('status', $status, $base_url)); ?>"><?php esc_html_e('Clear filters', 'matrix-mlm'); ?></a>
            <?php endif; ?>
        </form>

        <p class="description" style="margin-top:14px;">
            <?php
            if ($status === 'open') {
                printf(
                    /* translators: %s: number of grouped open-report rows on the current page out of total. */
                    esc_html__('Open queue is grouped by message — multiple reports for the same message render as one row. Resolving a row clears every open report for that message. Showing %s.', 'matrix-mlm'),
                    '<strong>' . esc_html(number_format_i18n(count($rows))) . ' / ' . esc_html(number_format_i18n($total)) . '</strong>'
                );
            } else {
                printf(
                    /* translators: %s: number of resolved rows on the current page out of total. */
                    esc_html__('Resolved view shows every individual resolution event. Showing %s.', 'matrix-mlm'),
                    '<strong>' . esc_html(number_format_i18n(count($rows))) . ' / ' . esc_html(number_format_i18n($total)) . '</strong>'
                );
            }
            ?>
        </p>

        <?php if ($status === 'open'): ?>
            <?php
            // Bulk-resolve plumbing.
            //
            // The toolbar lives in its own <form id="matrix-bulk-resolve-form">
            // ABOVE the table; per-row checkboxes use the HTML5
            // form="matrix-bulk-resolve-form" attribute to associate
            // with it without nesting a form inside the table (which
            // would collide with the existing per-row "Resolve"
            // forms). Same nonce action as single-resolve so the
            // CSRF model is unchanged; bulk-specific field names
            // (bulk_resolution / bulk_ban_duration) avoid colliding
            // with the per-row form inputs that share the table.
            //
            // Pagination + filter state is round-tripped through
            // hidden inputs so a bulk action submitted from page 3
            // of "reason=spam" returns the operator to page 3 of
            // "reason=spam" rather than dumping them at page 1 of
            // an unfiltered queue.
            ?>
            <form id="matrix-bulk-resolve-form" method="post" class="matrix-messaging-bulk-toolbar"
                  style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:14px;padding:10px;background:#f6f7f7;border:1px solid #dcdcde;border-bottom:0;">
                <?php wp_nonce_field('matrix_messaging_admin', '_matrix_messaging_admin_nonce'); ?>
                <input type="hidden" name="matrix_messaging_admin_action" value="bulk_resolve_reports">
                <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>">
                <?php if ($reason_f !== ''): ?>
                    <input type="hidden" name="reason" value="<?php echo esc_attr($reason_f); ?>">
                <?php endif; ?>
                <?php if ($sender_f !== ''): ?>
                    <input type="hidden" name="sender" value="<?php echo esc_attr($sender_f); ?>">
                <?php endif; ?>
                <input type="hidden" name="paged" value="<?php echo (int) $paged; ?>">

                <strong style="margin-right:4px;"><?php esc_html_e('Bulk action:', 'matrix-mlm'); ?></strong>
                <fieldset style="margin:0;padding:0;border:0;">
                    <label style="margin-right:8px;"><input type="radio" name="bulk_resolution" value="no_action" checked> <?php esc_html_e('Dismiss', 'matrix-mlm'); ?></label>
                    <label style="margin-right:8px;"><input type="radio" name="bulk_resolution" value="message_deleted"> <?php esc_html_e('Delete', 'matrix-mlm'); ?></label>
                    <label><input type="radio" name="bulk_resolution" value="user_banned"> <?php esc_html_e('Delete + Ban', 'matrix-mlm'); ?></label>
                </fieldset>
                <select name="bulk_ban_duration" title="<?php esc_attr_e('Applied only when "Delete + Ban" is selected', 'matrix-mlm'); ?>">
                    <?php foreach (self::allowed_ban_durations() as $slug => $label): ?>
                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($slug === 'permanent'); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button button-primary"
                        onclick="return confirm(<?php echo wp_json_encode(__('Apply this bulk action to all selected reports? Selecting Delete + Ban will issue one ban per resolved message.', 'matrix-mlm')); ?>);">
                    <?php esc_html_e('Apply to selected', 'matrix-mlm'); ?>
                </button>
                <span class="description" style="flex-basis:100%;margin:0;color:#646970;font-size:12px;">
                    <?php esc_html_e('Tick the rows below to apply this action to multiple reports at once. Each resolved message produces one ban event when "Delete + Ban" is chosen.', 'matrix-mlm'); ?>
                </span>
            </form>

            <table class="wp-list-table widefat fixed striped" style="margin-top:0;">
                <thead><tr>
                    <td class="manage-column column-cb check-column" style="width:2.2em;">
                        <label class="screen-reader-text" for="matrix-bulk-select-all-top"><?php esc_html_e('Select All', 'matrix-mlm'); ?></label>
                        <input id="matrix-bulk-select-all-top" type="checkbox"
                               form="matrix-bulk-resolve-form"
                               onclick="(function(src){var b=document.querySelectorAll('.matrix-bulk-select');for(var i=0;i&lt;b.length;i++){b[i].checked=src.checked;}var o=document.getElementById('matrix-bulk-select-all-bottom');if(o){o.checked=src.checked;}})(this);">
                    </td>
                    <th style="width:90px;"><?php esc_html_e('Reports', 'matrix-mlm'); ?></th>
                    <th><?php esc_html_e('Sender', 'matrix-mlm'); ?></th>
                    <th><?php esc_html_e('Reasons', 'matrix-mlm'); ?></th>
                    <th><?php esc_html_e('Message', 'matrix-mlm'); ?></th>
                    <th style="width:170px;"><?php esc_html_e('Last reported', 'matrix-mlm'); ?></th>
                    <th style="width:380px;"><?php esc_html_e('Action', 'matrix-mlm'); ?></th>
                </tr></thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="7"><?php esc_html_e('No reports.', 'matrix-mlm'); ?></td></tr>
                    <?php else: foreach ($rows as $r):
                        $sender_id = (int) $r->sender_id;
                        $sender_ban = $sender_id > 0 ? Matrix_MLM_Messaging::is_user_banned($sender_id) : false;
                        ?>
                        <tr>
                            <th scope="row" class="check-column" style="width:2.2em;padding-top:14px;">
                                <label class="screen-reader-text" for="matrix-bulk-cb-<?php echo (int) $r->message_id; ?>">
                                    <?php
                                    /* translators: %d: message id of a flagged message in the moderation queue. */
                                    printf(esc_html__('Select report for message #%d', 'matrix-mlm'), (int) $r->message_id);
                                    ?>
                                </label>
                                <input id="matrix-bulk-cb-<?php echo (int) $r->message_id; ?>"
                                       class="matrix-bulk-select"
                                       form="matrix-bulk-resolve-form"
                                       type="checkbox"
                                       name="report_message_ids[]"
                                       value="<?php echo (int) $r->message_id; ?>">
                            </th>
                            <td><span class="matrix-badge matrix-badge-warn" title="<?php esc_attr_e('Distinct reporters for this message', 'matrix-mlm'); ?>"><?php echo (int) $r->report_count; ?>&times;</span></td>
                            <td>
                                <?php echo esc_html($r->sender_login ?: '#' . $sender_id); ?>
                                <?php if ($sender_ban): ?>
                                    <br><span class="matrix-badge matrix-badge-error" style="font-size:11px;"><?php esc_html_e('banned', 'matrix-mlm'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $reasons = array_filter(array_map('trim', explode(',', (string) $r->reasons_csv)));
                                foreach ($reasons as $rs) {
                                    echo '<span class="matrix-badge matrix-badge-info" style="margin-right:4px;">' . esc_html($rs) . '</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <?php if ($r->deleted_at): ?>
                                    <em><?php esc_html_e('(message deleted)', 'matrix-mlm'); ?></em>
                                <?php else: ?>
                                    <?php echo esc_html(mb_substr((string) $r->body, 0, 200)); ?>
                                    <?php if (mb_strlen((string) $r->body) > 200): ?><span aria-hidden="true">…</span><?php endif; ?>
                                <?php endif; ?>
                                <?php
                                // View thread context expander. Closed
                                // by default — the moderator opts into
                                // reading the surrounding messages,
                                // matching the "we look at flagged
                                // content, not at every conversation"
                                // privacy posture. Hidden DOM still
                                // pre-renders, so each row pays one
                                // small indexed query at page-render
                                // time regardless of expansion state;
                                // 25 rows per page × 1 query = ~25 ms
                                // on a healthy server, acceptable.
                                $context_rows = $this->fetch_thread_context((int) $r->thread_id, (int) $r->message_id, 10);
                                if (!empty($context_rows)): ?>
                                    <details style="margin-top:8px;">
                                        <summary style="cursor:pointer;font-size:12px;color:#2271b1;"><?php esc_html_e('View thread context', 'matrix-mlm'); ?></summary>
                                        <div style="margin-top:6px;padding:8px;border:1px solid #dcdcde;background:#f6f7f7;font-size:12px;max-height:320px;overflow:auto;">
                                            <?php foreach ($context_rows as $cm):
                                                $is_focus = ((int) $cm->id === (int) $r->message_id);
                                                $row_style = $is_focus
                                                    ? 'background:#fff3cd;border-left:3px solid #f59e0b;padding:5px 8px;margin-bottom:4px;'
                                                    : 'padding:4px 8px;margin-bottom:4px;border-left:3px solid transparent;';
                                                ?>
                                                <div style="<?php echo esc_attr($row_style); ?>">
                                                    <strong><?php echo esc_html($cm->sender_login ?: '#' . (int) $cm->sender_id); ?></strong>
                                                    <span style="color:#666;margin-left:6px;"><?php echo esc_html((string) $cm->created_at); ?></span>
                                                    <?php if ($is_focus): ?>
                                                        <span style="color:#b45309;font-weight:bold;margin-left:6px;">[<?php esc_html_e('reported', 'matrix-mlm'); ?>]</span>
                                                    <?php endif; ?>
                                                    <div style="margin-top:2px;">
                                                        <?php if ($cm->deleted_at): ?>
                                                            <em><?php esc_html_e('(deleted)', 'matrix-mlm'); ?></em>
                                                        <?php else: ?>
                                                            <?php echo esc_html(mb_substr((string) $cm->body, 0, 300)); ?>
                                                            <?php if (mb_strlen((string) $cm->body) > 300): ?><span aria-hidden="true">…</span><?php endif; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </details>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html((string) $r->last_reported_at); ?></td>
                            <td>
                                <form method="post" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
                                    <?php wp_nonce_field('matrix_messaging_admin', '_matrix_messaging_admin_nonce'); ?>
                                    <input type="hidden" name="matrix_messaging_admin_action" value="resolve_report">
                                    <input type="hidden" name="message_id" value="<?php echo (int) $r->message_id; ?>">
                                    <fieldset style="margin:0;padding:0;border:0;">
                                        <label style="margin-right:6px;"><input type="radio" name="resolution" value="no_action" checked> <?php esc_html_e('Dismiss', 'matrix-mlm'); ?></label>
                                        <label style="margin-right:6px;"><input type="radio" name="resolution" value="message_deleted"> <?php esc_html_e('Delete', 'matrix-mlm'); ?></label>
                                        <label><input type="radio" name="resolution" value="user_banned"> <?php esc_html_e('Delete + Ban', 'matrix-mlm'); ?></label>
                                    </fieldset>
                                    <select name="ban_duration" title="<?php esc_attr_e('Applied only when "Delete + Ban" is selected', 'matrix-mlm'); ?>">
                                        <?php foreach (self::allowed_ban_durations() as $slug => $label): ?>
                                            <option value="<?php echo esc_attr($slug); ?>" <?php selected($slug === 'permanent'); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="button button-primary"><?php esc_html_e('Resolve', 'matrix-mlm'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped" style="margin-top:14px;">
                <thead><tr>
                    <th>#</th>
                    <th><?php esc_html_e('Reporter', 'matrix-mlm'); ?></th>
                    <th><?php esc_html_e('Sender', 'matrix-mlm'); ?></th>
                    <th><?php esc_html_e('Reason', 'matrix-mlm'); ?></th>
                    <th><?php esc_html_e('Resolution', 'matrix-mlm'); ?></th>
                    <th><?php esc_html_e('Resolved by', 'matrix-mlm'); ?></th>
                    <th><?php esc_html_e('Resolved at', 'matrix-mlm'); ?></th>
                </tr></thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="7"><?php esc_html_e('No reports.', 'matrix-mlm'); ?></td></tr>
                    <?php else: foreach ($rows as $r): ?>
                        <tr>
                            <td>#<?php echo (int) $r->id; ?></td>
                            <td><?php echo esc_html($r->reporter_login ?: '—'); ?></td>
                            <td><?php echo esc_html($r->sender_login ?: '—'); ?></td>
                            <td><span class="matrix-badge matrix-badge-info"><?php echo esc_html($r->reason); ?></span></td>
                            <td><span class="matrix-badge matrix-badge-info"><?php echo esc_html($r->resolution ?: '—'); ?></span></td>
                            <td><?php echo esc_html($r->resolver_login ?: '#' . (int) $r->resolved_by); ?></td>
                            <td><?php echo esc_html((string) $r->resolved_at); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($total_pages > 1): ?>
            <div class="tablenav bottom" style="margin-top:14px;">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php
                        printf(
                            /* translators: %s: total formatted count. */
                            esc_html(_n('%s item', '%s items', $total, 'matrix-mlm')),
                            esc_html(number_format_i18n($total))
                        );
                        ?>
                    </span>
                    <span class="pagination-links">
                        <?php
                        $page_links = paginate_links([
                            'base'      => add_query_arg('paged', '%#%', $self_url),
                            'format'    => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total'     => $total_pages,
                            'current'   => $paged,
                            'type'      => 'plain',
                        ]);
                        echo $page_links; // already escaped by paginate_links
                        ?>
                    </span>
                </div>
            </div>
        <?php endif; ?>
        <?php
    }

    private function render_bans() {
        global $wpdb;
        settings_errors('matrix_messaging');

        // Surface every user with a non-empty BAN_META_UNTIL. We
        // also pull BAN_META_AT / BAN_META_BY in the same pass so
        // the rendering loop doesn't fan into N extra queries.
        $banned_rows = $wpdb->get_results($wpdb->prepare("
            SELECT u_until.user_id,
                   u_until.meta_value AS until_val,
                   u_reason.meta_value AS reason_val,
                   u_at.meta_value     AS at_val,
                   u_by.meta_value     AS by_val
              FROM {$wpdb->usermeta} u_until
              LEFT JOIN {$wpdb->usermeta} u_reason ON u_reason.user_id = u_until.user_id AND u_reason.meta_key = %s
              LEFT JOIN {$wpdb->usermeta} u_at     ON u_at.user_id     = u_until.user_id AND u_at.meta_key     = %s
              LEFT JOIN {$wpdb->usermeta} u_by     ON u_by.user_id     = u_until.user_id AND u_by.meta_key     = %s
             WHERE u_until.meta_key = %s
               AND u_until.meta_value <> ''
             ORDER BY u_at.meta_value DESC, u_until.user_id DESC
        ",
            Matrix_MLM_Messaging::BAN_META_REASON,
            Matrix_MLM_Messaging::BAN_META_AT,
            Matrix_MLM_Messaging::BAN_META_BY,
            Matrix_MLM_Messaging::BAN_META_UNTIL
        ));
        ?>

        <h2 class="title" style="margin-top:30px;"><?php esc_html_e('Add Ban', 'matrix-mlm'); ?></h2>
        <p class="description" style="max-width:780px;">
            <?php esc_html_e('Place a messaging ban on a user without going through the report queue. Use this when a user was reported via support ticket, email, or any out-of-band channel where there is no in-app report row to resolve.', 'matrix-mlm'); ?>
        </p>
        <form method="post" style="margin-top:8px;">
            <?php wp_nonce_field('matrix_messaging_admin', '_matrix_messaging_admin_nonce'); ?>
            <input type="hidden" name="matrix_messaging_admin_action" value="add_ban">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="matrix-msg-ban-login"><?php esc_html_e('Username', 'matrix-mlm'); ?></label></th>
                    <td><input type="text" id="matrix-msg-ban-login" name="user_login" class="regular-text" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="matrix-msg-ban-duration"><?php esc_html_e('Duration', 'matrix-mlm'); ?></label></th>
                    <td>
                        <select id="matrix-msg-ban-duration" name="ban_duration">
                            <?php foreach (self::allowed_ban_durations() as $slug => $label): ?>
                                <option value="<?php echo esc_attr($slug); ?>" <?php selected($slug === 'permanent'); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="matrix-msg-ban-reason"><?php esc_html_e('Reason', 'matrix-mlm'); ?></label></th>
                    <td>
                        <input type="text" id="matrix-msg-ban-reason" name="ban_reason" class="regular-text" maxlength="255" placeholder="<?php esc_attr_e('Optional — surfaced on the bans tab', 'matrix-mlm'); ?>">
                    </td>
                </tr>
            </table>
            <p><button type="submit" class="button"><?php esc_html_e('Place Ban', 'matrix-mlm'); ?></button></p>
        </form>

        <h2 class="title" style="margin-top:30px;"><?php esc_html_e('Active Bans', 'matrix-mlm'); ?></h2>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr>
                <th><?php esc_html_e('User', 'matrix-mlm'); ?></th>
                <th><?php esc_html_e('Until', 'matrix-mlm'); ?></th>
                <th><?php esc_html_e('Reason', 'matrix-mlm'); ?></th>
                <th><?php esc_html_e('Banned at', 'matrix-mlm'); ?></th>
                <th><?php esc_html_e('Banned by', 'matrix-mlm'); ?></th>
                <th><?php esc_html_e('Action', 'matrix-mlm'); ?></th>
            </tr></thead>
            <tbody>
                <?php if (empty($banned_rows)): ?>
                    <tr><td colspan="6"><?php esc_html_e('No active bans.', 'matrix-mlm'); ?></td></tr>
                <?php else: foreach ($banned_rows as $row):
                    $u    = get_userdata((int) $row->user_id);
                    $by_u = !empty($row->by_val) ? get_userdata((int) $row->by_val) : null;
                    $is_perm = $row->until_val === 'permanent';
                    ?>
                    <tr>
                        <td><?php echo esc_html($u ? $u->user_login : '#' . (int) $row->user_id); ?></td>
                        <td>
                            <?php if ($is_perm): ?>
                                <span class="matrix-badge matrix-badge-error"><?php esc_html_e('permanent', 'matrix-mlm'); ?></span>
                            <?php else: ?>
                                <code><?php echo esc_html((string) $row->until_val); ?></code>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($row->reason_val ?: '—'); ?></td>
                        <td><?php echo esc_html($row->at_val ?: '—'); ?></td>
                        <td>
                            <?php
                            if ($by_u) {
                                echo esc_html($by_u->user_login);
                            } elseif ((int) $row->by_val > 0) {
                                echo '#' . (int) $row->by_val;
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                        <td>
                            <form method="post" style="display:inline-block;">
                                <?php wp_nonce_field('matrix_messaging_admin', '_matrix_messaging_admin_nonce'); ?>
                                <input type="hidden" name="matrix_messaging_admin_action" value="lift_ban">
                                <input type="hidden" name="user_id" value="<?php echo (int) $row->user_id; ?>">
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
