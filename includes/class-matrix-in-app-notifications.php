<?php
/**
 * In-app notifications.
 *
 * Persistent, dashboard-visible notifications shown in the sidebar
 * bell icon. Pairs with — does not replace — the existing
 * Matrix_MLM_Notifications class:
 *
 *   - Matrix_MLM_Notifications sends emails (and SMS when enabled).
 *     Durable, off-platform, useful when the user isn't logged in.
 *   - Matrix_MLM_In_App_Notifications writes a row into the
 *     wp_matrix_notifications table and surfaces it via the bell
 *     icon dropdown + the dashboard's Notifications tab. Useful
 *     when the user IS on the platform — they see the unread
 *     count update without refreshing, and click through to the
 *     relevant tab.
 *
 * Every site that sends an email today also enqueues an in-app
 * notification for the same event, so the two channels stay in
 * lockstep without duplicating delivery (different transport,
 * same trigger). Both fire from Matrix_MLM_Notifications via
 * a small after-send hook in each public method, which keeps
 * the call sites in plan_engine / core / admin / fintava
 * unchanged.
 *
 * Storage: wp_matrix_notifications. One row per notification.
 * Retention: read rows older than 90 days are pruned by the
 * daily cron (matrix_mlm_daily_cron — see register_hooks()).
 * Unread rows are kept forever so a user who hasn't logged in
 * for three months still sees every commission they earned
 * while away.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_In_App_Notifications {

    /**
     * Number of days a READ notification is kept before the daily
     * cron prunes it. Unread rows are not affected by retention —
     * they stay until the user marks them read or until the row
     * is hard-deleted by an admin.
     */
    const READ_RETENTION_DAYS = 90;

    /**
     * Soft cap on rows returned by get_for_user() in a single
     * call. The bell dropdown only needs the most recent ~20;
     * the full Notifications tab paginates server-side and so
     * never asks for more than this in one query.
     */
    const MAX_FETCH_LIMIT = 100;

    /**
     * Wire the AJAX endpoints, the cleanup cron, and the
     * notification bus that mirrors every Matrix_MLM_Notifications
     * email send into an in-app row.
     *
     * Idempotent: safe to call multiple times. Each add_action
     * call is naturally deduped by WordPress because we always
     * pass the same callable.
     */
    public static function register_hooks() {
        // AJAX endpoints. All three require a logged-in user;
        // there is no public-facing read of someone else's
        // notifications, so wp_ajax_nopriv_* counterparts are
        // intentionally NOT registered.
        add_action('wp_ajax_matrix_in_app_fetch',         [__CLASS__, 'ajax_fetch']);
        add_action('wp_ajax_matrix_in_app_mark_read',     [__CLASS__, 'ajax_mark_read']);
        add_action('wp_ajax_matrix_in_app_mark_all_read', [__CLASS__, 'ajax_mark_all_read']);

        // Daily cleanup of read rows past their retention window.
        // Hooks onto the existing matrix_mlm_daily_cron event
        // (registered in Matrix_MLM_Core::define_hooks) rather
        // than spinning up a second cron, so an operator who has
        // tuned the daily cron schedule (e.g. WP Crontrol) gets
        // the cleanup at the same cadence as everything else.
        add_action('matrix_mlm_daily_cron', [__CLASS__, 'cleanup_old_read']);
    }

    /* ------------------------------------------------------------------
     * Write path
     * ----------------------------------------------------------------*/

    /**
     * Insert one notification row for $user_id.
     *
     * @param int    $user_id  Recipient WP user id. Skipped if <= 0
     *                         so a misconfigured caller can't write
     *                         orphan rows.
     * @param string $type     Short slug, used both for the icon
     *                         lookup in the JS renderer and for
     *                         operator-side analytics. Free-form
     *                         but conventionally one of:
     *                         transfer_received, transfer_sent,
     *                         commission, level_completion,
     *                         deposit, withdrawal, bank_payout,
     *                         bank_payout_failed, bill_payment,
     *                         card_status, epin_redeemed,
     *                         subscription, ticket_reply,
     *                         password_changed, admin_announcement.
     * @param string $title    Short headline. Plain text. Truncated
     *                         to 255 chars on insert.
     * @param string $body     Body copy. Plain text. Stored as TEXT
     *                         (~64 KB ceiling); the renderer wraps
     *                         long bodies but a sane caller stays
     *                         under ~280 chars.
     * @param string $link_url Optional URL the dropdown row should
     *                         link to when clicked. Same-host only —
     *                         off-host URLs are silently dropped to
     *                         protect against a future hook source
     *                         injecting attacker-controlled links.
     * @param array  $extra    Optional metadata (e.g. amount,
     *                         counterpart_user_id) JSON-encoded into
     *                         the meta column. Future-compat: the
     *                         renderer can ignore unknown keys.
     * @return int|false Inserted row id, or false on failure.
     */
    public static function enqueue($user_id, $type, $title, $body, $link_url = '', array $extra = []) {
        global $wpdb;
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return false;
        }

        $type  = self::sanitize_slug($type);
        $title = self::truncate(wp_strip_all_tags((string) $title), 255);
        $body  = (string) wp_strip_all_tags((string) $body);
        $link  = self::sanitize_link($link_url);
        $meta  = !empty($extra) ? wp_json_encode($extra) : null;

        $table = $wpdb->prefix . 'matrix_notifications';
        $data  = [
            'user_id'    => $user_id,
            'type'       => $type !== '' ? $type : 'generic',
            'title'      => $title,
            'body'       => $body,
            'link_url'   => $link,
            'meta'       => $meta,
            'created_at' => current_time('mysql'),
        ];
        $fmt   = ['%d', '%s', '%s', '%s', '%s', '%s', '%s'];

        $ok = $wpdb->insert($table, $data, $fmt);
        if ($ok === false) {
            // Match the existing Matrix_MLM_Notifications failure
            // mode: log + soft-fail. Notifications must NEVER
            // bubble an exception that aborts the underlying
            // wallet/commission/transfer write — they are an
            // observability surface, not a critical-path action.
            if (function_exists('error_log')) {
                error_log(sprintf(
                    '[Matrix MLM] In-app notification enqueue failed for user %d, type %s: %s',
                    $user_id,
                    $type,
                    $wpdb->last_error ?: 'unknown'
                ));
            }
            return false;
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Convenience: enqueue the same notification for many users.
     * Used by admin announcements (broadcast to every active
     * member). Inserts one row per user — keeps the read-state
     * per-user and lets a single user mark theirs read without
     * affecting anyone else's.
     *
     * For very large user lists we batch into chunks of 200 to
     * avoid the single-row insert cost dominating; a future
     * optimization could move to a multi-row INSERT but the
     * volume isn't there yet.
     *
     * @param int[]  $user_ids
     * @param string $type
     * @param string $title
     * @param string $body
     * @param string $link_url
     * @param array  $extra
     * @return int   Number of rows actually inserted.
     */
    public static function enqueue_for_many(array $user_ids, $type, $title, $body, $link_url = '', array $extra = []) {
        $count = 0;
        foreach ($user_ids as $uid) {
            if (self::enqueue($uid, $type, $title, $body, $link_url, $extra)) {
                $count++;
            }
        }
        return $count;
    }

    /* ------------------------------------------------------------------
     * Read path
     * ----------------------------------------------------------------*/

    /**
     * Fetch notifications for $user_id, newest first.
     *
     * @param int  $user_id
     * @param int  $limit       1..MAX_FETCH_LIMIT.
     * @param int  $offset      For paging.
     * @param bool $only_unread When true, restricts to read_at IS NULL.
     * @return array<int, object> Each row exposes id, type, title,
     *                            body, link_url, meta (JSON string),
     *                            read_at (nullable), created_at.
     */
    public static function get_for_user($user_id, $limit = 20, $offset = 0, $only_unread = false) {
        global $wpdb;
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return [];
        }
        $limit  = max(1, min(self::MAX_FETCH_LIMIT, (int) $limit));
        $offset = max(0, (int) $offset);

        $table = $wpdb->prefix . 'matrix_notifications';
        $where = 'user_id = %d';
        if ($only_unread) {
            $where .= ' AND read_at IS NULL';
        }

        $sql = $wpdb->prepare(
            "SELECT id, type, title, body, link_url, meta, read_at, created_at
               FROM {$table}
              WHERE {$where}
              ORDER BY created_at DESC, id DESC
              LIMIT %d OFFSET %d",
            $user_id, $limit, $offset
        );
        return (array) $wpdb->get_results($sql);
    }

    /**
     * Number of unread rows for $user_id. Drives the bell badge.
     */
    public static function unread_count($user_id) {
        global $wpdb;
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return 0;
        }
        $table = $wpdb->prefix . 'matrix_notifications';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND read_at IS NULL",
            $user_id
        ));
    }

    /**
     * Total row count (read + unread) for paging on the
     * Notifications tab.
     */
    public static function total_count($user_id) {
        global $wpdb;
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return 0;
        }
        $table = $wpdb->prefix . 'matrix_notifications';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d",
            $user_id
        ));
    }

    /**
     * Mark specific notification ids as read for $user_id.
     * Filters server-side to (id IN (...) AND user_id = $user_id)
     * so a tampered POST can't mark someone else's notifications
     * read.
     *
     * @return int Number of rows updated.
     */
    public static function mark_read($user_id, array $ids) {
        global $wpdb;
        $user_id = (int) $user_id;
        $ids     = array_values(array_unique(array_filter(array_map('intval', $ids), function ($i) {
            return $i > 0;
        })));
        if ($user_id <= 0 || empty($ids)) {
            return 0;
        }
        $table = $wpdb->prefix . 'matrix_notifications';
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $now = current_time('mysql');
        $sql = $wpdb->prepare(
            "UPDATE {$table}
                SET read_at = %s
              WHERE user_id = %d
                AND read_at IS NULL
                AND id IN ({$placeholders})",
            array_merge([$now, $user_id], $ids)
        );
        return (int) $wpdb->query($sql);
    }

    /**
     * Mark every unread notification for $user_id as read.
     * Used by the dropdown's "Mark all as read" button.
     *
     * @return int Rows updated.
     */
    public static function mark_all_read($user_id) {
        global $wpdb;
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return 0;
        }
        $table = $wpdb->prefix . 'matrix_notifications';
        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET read_at = %s
              WHERE user_id = %d AND read_at IS NULL",
            current_time('mysql'), $user_id
        ));
    }

    /* ------------------------------------------------------------------
     * AJAX endpoints
     * ----------------------------------------------------------------*/

    /**
     * GET /wp-admin/admin-ajax.php?action=matrix_in_app_fetch
     *
     * Returns the most recent notifications for the current user
     * plus the unread badge count. The badge is computed
     * server-side and sent alongside the list so the JS doesn't
     * have to derive it (and so the badge stays accurate even if
     * the dropdown only fetched a subset).
     *
     * Optional POST params:
     *   limit     1..MAX_FETCH_LIMIT, default 20
     *   offset    >= 0, default 0
     *   unread_only  truthy → restrict to read_at IS NULL
     */
    public static function ajax_fetch() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Not signed in.', 'matrix-mlm')], 401);
        }

        $user_id    = get_current_user_id();
        $limit      = isset($_POST['limit'])  ? (int) $_POST['limit']  : 20;
        $offset     = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;
        $only_unread = !empty($_POST['unread_only']);

        // Site-wide presence beacon. The bell-icon poll runs on every
        // dashboard page (not just the messages tab), so this is the
        // signal Matrix_MLM_Messaging::is_online() relies on to gate
        // offline-email dispatch for users who are on the platform
        // but haven't opened the messages tab in this session.
        // Class-exists guarded so a future deinstall of the messaging
        // module wouldn't fatal the bell.
        if (class_exists('Matrix_MLM_Messaging')
            && method_exists('Matrix_MLM_Messaging', 'update_presence')) {
            Matrix_MLM_Messaging::update_presence($user_id);
        }

        $rows = self::get_for_user($user_id, $limit, $offset, $only_unread);
        $unread = self::unread_count($user_id);
        $total  = self::total_count($user_id);

        wp_send_json_success([
            'unread_count' => $unread,
            'total'        => $total,
            'limit'        => $limit,
            'offset'       => $offset,
            'rows'         => array_map([__CLASS__, 'shape_row_for_client'], $rows),
        ]);
    }

    /**
     * POST action=matrix_in_app_mark_read with ids[]=N&ids[]=M.
     * Bulk version is also supported by passing a comma-separated
     * "ids" string for clients that can't post arrays cleanly.
     */
    public static function ajax_mark_read() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Not signed in.', 'matrix-mlm')], 401);
        }

        $user_id = get_current_user_id();
        $raw     = $_POST['ids'] ?? [];
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            $raw = [];
        }
        $ids = array_map('intval', $raw);

        $updated = self::mark_read($user_id, $ids);
        wp_send_json_success([
            'updated'      => $updated,
            'unread_count' => self::unread_count($user_id),
        ]);
    }

    /**
     * POST action=matrix_in_app_mark_all_read.
     */
    public static function ajax_mark_all_read() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Not signed in.', 'matrix-mlm')], 401);
        }

        $user_id = get_current_user_id();
        $updated = self::mark_all_read($user_id);
        wp_send_json_success([
            'updated'      => $updated,
            'unread_count' => self::unread_count($user_id),
        ]);
    }

    /* ------------------------------------------------------------------
     * Cron
     * ----------------------------------------------------------------*/

    /**
     * Daily cleanup of READ notifications older than READ_RETENTION_DAYS.
     * Unread rows are intentionally untouched — see class header.
     *
     * Idempotent and capped at 5,000 rows per run so a one-time
     * backlog cleanup on a large site doesn't stall the cron.
     * Anything beyond the cap is picked up tomorrow.
     */
    public static function cleanup_old_read() {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_notifications';

        // Use a date math expression in SQL rather than computing
        // the cutoff in PHP — keeps the query a single round-trip
        // and is more accurate against the DB's own clock if the
        // app server's clock has drifted.
        $sql = $wpdb->prepare(
            "DELETE FROM {$table}
              WHERE read_at IS NOT NULL
                AND read_at < (NOW() - INTERVAL %d DAY)
              LIMIT 5000",
            self::READ_RETENTION_DAYS
        );
        $deleted = (int) $wpdb->query($sql);
        if ($deleted > 0) {
            update_option('matrix_mlm_in_app_notifications_last_cleanup', [
                'at'      => current_time('mysql'),
                'deleted' => $deleted,
            ]);
        }
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------*/

    /**
     * Restrict a slug to [a-z0-9_], lowercased, max 50 chars. The
     * `type` column is varchar(50); the JS-side icon registry uses
     * the slug as a key so anything that wouldn't survive a
     * round-trip through both becomes 'generic'.
     */
    private static function sanitize_slug($s) {
        $s = strtolower((string) $s);
        $s = preg_replace('/[^a-z0-9_]/', '_', $s);
        $s = preg_replace('/_+/', '_', $s);
        $s = trim($s, '_');
        return substr($s, 0, 50);
    }

    /**
     * UTF-8-safe truncation. Avoids splitting a multi-byte
     * character in half (which produces a "?" replacement when
     * the row is later read).
     */
    private static function truncate($s, $len) {
        if (function_exists('mb_substr')) {
            return mb_substr($s, 0, $len, 'UTF-8');
        }
        return substr($s, 0, $len);
    }

    /**
     * Same-host link guard. Trying to attach an off-host URL to
     * a notification is almost always either a bug (the caller
     * accidentally passed a full external URL) or a future
     * abuse vector (a hook in a third-party plugin pushes a
     * link to attacker-controlled content). Drop silently to
     * empty rather than store something we'd refuse to render.
     */
    private static function sanitize_link($url) {
        $url = (string) $url;
        if ($url === '') {
            return '';
        }
        // Allow root-relative paths.
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
            return esc_url_raw($url);
        }
        $home = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (!$home || !$host || strcasecmp($home, $host) !== 0) {
            return '';
        }
        return esc_url_raw($url);
    }

    /**
     * Shape a DB row for JSON output: parse meta, format
     * created_at, and add a relative-time string ("3 minutes
     * ago") that the JS dropdown displays.
     */
    private static function shape_row_for_client($row) {
        $meta = [];
        if (!empty($row->meta)) {
            $decoded = json_decode($row->meta, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        $created_ts = strtotime($row->created_at . ' UTC');
        $now_ts     = (int) (time());
        return [
            'id'          => (int) $row->id,
            'type'        => (string) $row->type,
            'title'       => (string) $row->title,
            'body'        => (string) $row->body,
            'link_url'    => (string) $row->link_url,
            'meta'        => $meta,
            'is_read'     => !empty($row->read_at),
            'read_at'     => $row->read_at ?: null,
            'created_at'  => (string) $row->created_at,
            'created_iso' => $created_ts ? gmdate('c', $created_ts) : null,
            'time_ago'    => $created_ts
                ? sprintf(
                    /* translators: %s: human-readable time difference, e.g. "3 minutes" */
                    __('%s ago', 'matrix-mlm'),
                    human_time_diff($created_ts, $now_ts)
                )
                : '',
        ];
    }
}
