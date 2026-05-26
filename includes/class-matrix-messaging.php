<?php
/**
 * Member-to-Member Messaging — model + AJAX handlers.
 *
 * Scope (locked at design time, do not silently widen):
 *   - 1:1 direct messages: any active member can DM any other active member
 *     (open model). Recipient lookup is by username or referral_code.
 *   - Team rooms: one auto-created group thread per sponsor; the sponsor is
 *     `owner`, every direct referral is a `member`. Self-healed lazily on
 *     first messages-tab access (cheaper than a one-shot migration over a
 *     potentially huge wp_users table).
 *   - Polling-based delivery (no WebSocket layer). The browser polls
 *     `matrix_messaging_fetch_thread` with `?after_id=<last_seen_id>` so
 *     each tick returns only the delta, keeping the request body small
 *     enough to ride on shared WP hosting without exploding wp-cron load.
 *   - Text + image attachments via the existing signed-URL infrastructure
 *     in Matrix_MLM_Attachment_Signer. `attachment_id` is a WP post ID
 *     (i.e. a row in wp_posts of type 'attachment'); the signer mints a
 *     short-lived REST URL when the message is rendered. We never link
 *     /uploads/ public paths directly.
 *
 * Moderation surface (all operator-tunable):
 *   - Per-call rate limit via Matrix_MLM_Rate_Limiter (matrix_mlm_messaging_settings.rate_limit_per_minute).
 *   - Off-platform contact stripping (emails / phone numbers / external URLs)
 *     replaced with [contact removed] before insert. The original body is
 *     not retained — operators who need full forensic recall of stripped
 *     content can disable stripping via settings instead.
 *   - User-initiated mute (per-thread) and block (per-counterparty).
 *   - Admin moderation: report → queue → soft-delete message and/or ban
 *     sender. Ban is stored in wp_usermeta so it follows the user across
 *     this and any future plugin tables without an extra join.
 *
 * Class is fully static. We do not need per-instance state and the static
 * surface matches the In_App_Notifications / Attachment_Signer style used
 * elsewhere in this plugin.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Messaging {

    const SETTINGS_OPTION = 'matrix_mlm_messaging_settings';

    /**
     * Coerced enums. Whitelisted at the model layer as a defense-in-depth
     * gate so a future caller that forgets to validate cannot land an
     * attacker-controlled string into a column that gets reflected on
     * every reviewer's moderation page.
     */
    const THREAD_TYPES         = ['dm', 'team_room'];
    const PARTICIPANT_ROLES    = ['owner', 'member'];
    const ALLOWED_REPORT_REASONS = ['spam', 'harassment', 'off_platform', 'scam', 'other'];

    const BAN_META_UNTIL  = 'matrix_messaging_banned_until';   // 'permanent' | 'YYYY-mm-dd HH:ii:ss'
    const BAN_META_REASON = 'matrix_messaging_ban_reason';

    /**
     * Presence + offline-push metadata keys.
     *
     * PRESENCE_META is a UNIX timestamp last-seen pulse, refreshed on
     * every messaging AJAX guard hit AND on every bell-icon poll
     * (Matrix_MLM_In_App_Notifications::ajax_fetch — see that class).
     * is_online() compares (now - last_seen) against the
     * presence_window_seconds setting.
     *
     * OFFLINE_EMAIL_META_PREFIX is the cooldown timestamp for the
     * per-(recipient, thread) email-coalescing window — prevents a
     * burst of six rapid-fire messages on one thread from generating
     * six separate inbox pings to an offline recipient. Stored as a
     * separate user_meta row per thread so a recipient receiving
     * messages on threads A and B in parallel still gets two emails
     * (one per thread) rather than one mute on both.
     *
     * NEW_MESSAGE_NOTIF_TYPE is the slug used in the bell-icon row's
     * `type` column so the JS-side icon registry can render it with
     * a chat-bubble glyph distinct from commission / withdrawal /
     * etc. Conventional [a-z0-9_] form per Matrix_MLM_In_App_Notifications::sanitize_slug.
     */
    const PRESENCE_META             = 'matrix_messaging_last_seen';
    const OFFLINE_EMAIL_META_PREFIX = 'matrix_messaging_offline_email_at_';
    const NEW_MESSAGE_NOTIF_TYPE    = 'message_received';

    /**
     * Default settings. Stored as a single option row to mirror the way
     * Flutterwave / Fintava settings are persisted, so the admin UI can
     * round-trip the whole blob in one save.
     *
     * Push delivery keys (presence_window_seconds, offline_email_enabled,
     * offline_email_cooldown_seconds) drive the offline-recipient fanout
     * in dispatch_post_send_notifications(). Defaults are tuned to:
     *
     *   - 120s presence window: long enough to ride out a single
     *     network glitch on a slow connection between bell polls (the
     *     bell defaults to ~30s polling, so two missed beats still
     *     keeps a real user "online"); short enough that an inactive
     *     user reliably falls through to the email path within a
     *     couple of minutes of leaving the dashboard.
     *
     *   - 600s (10min) email cooldown: aggressive enough to stop a
     *     spam-style burst from generating dozens of inbox pings;
     *     long enough that a genuine "are you there?" follow-up
     *     ten minutes later does re-trigger the email.
     */
    public static function default_settings() {
        return [
            'enabled'                       => 1,
            'rate_limit_per_minute'         => 20,
            'rate_limit_per_hour'           => 200,
            'strip_off_platform_contacts'   => 1,
            'allow_attachments'             => 1,
            'max_attachment_bytes'          => 5 * 1024 * 1024,
            'team_rooms_auto_create'        => 1,
            'polling_interval_ms'           => 10000,
            // Push delivery for offline recipients (DB 1.0.20 / plugin 2.0.3).
            'presence_window_seconds'       => 120,
            'offline_email_enabled'         => 1,
            'offline_email_cooldown_seconds'=> 600,
        ];
    }

    public static function get_settings() {
        $stored = get_option(self::SETTINGS_OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        return array_merge(self::default_settings(), $stored);
    }

    /**
     * Idempotent — safe to call multiple times. Each add_action registers
     * once per (hook, callback) tuple in WP, so re-entry is harmless.
     */
    public static function register_hooks() {
        // AJAX. All endpoints are logged-in only; messaging is never a
        // surface a public visitor can reach, so wp_ajax_nopriv_* are
        // intentionally unregistered (handler returns 401 on dispatch).
        add_action('wp_ajax_matrix_messaging_list_threads',  [__CLASS__, 'ajax_list_threads']);
        add_action('wp_ajax_matrix_messaging_fetch_thread',  [__CLASS__, 'ajax_fetch_thread']);
        add_action('wp_ajax_matrix_messaging_send',          [__CLASS__, 'ajax_send']);
        add_action('wp_ajax_matrix_messaging_open_dm',       [__CLASS__, 'ajax_open_dm']);
        add_action('wp_ajax_matrix_messaging_mark_read',     [__CLASS__, 'ajax_mark_read']);
        add_action('wp_ajax_matrix_messaging_block',         [__CLASS__, 'ajax_block']);
        add_action('wp_ajax_matrix_messaging_unblock',       [__CLASS__, 'ajax_unblock']);
        add_action('wp_ajax_matrix_messaging_mute',          [__CLASS__, 'ajax_mute']);
        add_action('wp_ajax_matrix_messaging_report',        [__CLASS__, 'ajax_report']);
        // Cross-thread search across the messages a user can see.
        // Kept POST-shaped (via ajax_guard) so the nonce / login
        // gates apply identically to every other messaging
        // endpoint — search has no side effects so a GET would
        // have been semantically fine but keeping POST means one
        // consistent CSRF model.
        add_action('wp_ajax_matrix_messaging_search',        [__CLASS__, 'ajax_search']);
        // Lightweight presence beacon. The dashboard ticks this
        // on a slow cadence so the server has a fresh last-seen
        // pulse to gate offline-email dispatch on for users who
        // are on the dashboard but NOT in the messages tab. A tab
        // actively chatting doesn't need this beacon at all —
        // every other messaging AJAX endpoint already touches the
        // same user_meta via ajax_guard().
        add_action('wp_ajax_matrix_messaging_presence',      [__CLASS__, 'ajax_presence']);

        // Self-healing team-room membership: when a new user is created,
        // ensure their sponsor's team room exists and add the new user as
        // a member. Runs on user_register (WP core) AND on a custom hook
        // matrix_user_sponsor_changed (fired by importer / admin tools)
        // so reparenting an existing user also updates membership.
        add_action('user_register',                  [__CLASS__, 'on_user_register']);
        add_action('matrix_user_sponsor_changed',    [__CLASS__, 'on_sponsor_changed'], 10, 3);

        // Cron: nightly cleanup of soft-deleted messages older than 30
        // days, plus expired mute reset. Mirrors the in-app notifications
        // cron schedule — same daily event, separate callback.
        add_action('matrix_mlm_daily_cron',          [__CLASS__, 'cron_cleanup']);
    }

    // ---------------------------------------------------------------
    // Core data ops
    // ---------------------------------------------------------------

    public static function is_messaging_enabled() {
        $s = self::get_settings();
        return !empty($s['enabled']);
    }

    /**
     * Returns 'permanent' | DateTime-string | false. Caller treats truthy
     * as banned; comparing against now() handles temporary bans.
     */
    public static function is_user_banned($user_id) {
        $until = get_user_meta((int) $user_id, self::BAN_META_UNTIL, true);
        if (!$until) {
            return false;
        }
        if ($until === 'permanent') {
            return 'permanent';
        }
        // String datetime. Compare in UTC to avoid wp_timezone_string drift
        // between cron callbacks (which run in UTC) and admin UI (site tz).
        $expires_ts = strtotime($until . ' UTC');
        if ($expires_ts === false || $expires_ts <= time()) {
            // Expired — clear the meta so the gate stops triggering.
            delete_user_meta((int) $user_id, self::BAN_META_UNTIL);
            delete_user_meta((int) $user_id, self::BAN_META_REASON);
            return false;
        }
        return $until;
    }

    /**
     * Find an existing 1:1 DM between two users, or create one. Result is
     * the thread row id (int).
     *
     * Order-independent: get_or_create_dm_thread(A, B) and (B, A) return
     * the same id. We enforce this at the lookup query (sorted user_ids)
     * rather than via a UNIQUE constraint, because a UNIQUE on a derived
     * sorted pair would require a generated column that dbDelta won't
     * cleanly emit on older MySQL.
     */
    public static function get_or_create_dm_thread($user_a, $user_b) {
        global $wpdb;
        $user_a = (int) $user_a;
        $user_b = (int) $user_b;
        if ($user_a <= 0 || $user_b <= 0 || $user_a === $user_b) {
            return new WP_Error('matrix_messaging_invalid_pair', __('Invalid user pair.', 'matrix-mlm'));
        }

        $threads_t      = $wpdb->prefix . 'matrix_message_threads';
        $participants_t = $wpdb->prefix . 'matrix_message_participants';

        // Look for an existing dm thread that has BOTH users as
        // (non-removed) participants and exactly two participants.
        $existing = $wpdb->get_var($wpdb->prepare("
            SELECT t.id FROM $threads_t t
              INNER JOIN $participants_t p1 ON p1.thread_id = t.id AND p1.user_id = %d AND p1.removed_at IS NULL
              INNER JOIN $participants_t p2 ON p2.thread_id = t.id AND p2.user_id = %d AND p2.removed_at IS NULL
            WHERE t.type = 'dm'
              AND (SELECT COUNT(*) FROM $participants_t pX WHERE pX.thread_id = t.id AND pX.removed_at IS NULL) = 2
            LIMIT 1
        ", $user_a, $user_b));

        if ($existing) {
            return (int) $existing;
        }

        $now = current_time('mysql', true);
        $wpdb->insert($threads_t, [
            'type'             => 'dm',
            'team_owner_id'    => null,
            'title'            => null,
            'status'           => 'active',
            'created_at'       => $now,
            'last_message_at'  => $now,
        ]);
        $thread_id = (int) $wpdb->insert_id;
        if (!$thread_id) {
            return new WP_Error('matrix_messaging_db_error', __('Could not create thread.', 'matrix-mlm'));
        }

        foreach ([$user_a, $user_b] as $uid) {
            $wpdb->insert($participants_t, [
                'thread_id'      => $thread_id,
                'user_id'        => $uid,
                'role'           => 'member',
                'joined_at'      => $now,
                'last_read_at'   => null,
                'muted_until'    => null,
                'removed_at'     => null,
            ]);
        }
        return $thread_id;
    }

    /**
     * Ensure the team room for a sponsor exists, and that the sponsor is
     * its owner. Returns thread id, or null if sponsor has no
     * matrix_user_meta row yet (genuine pre-bootstrap state — caller
     * should treat as "no team room", not as an error).
     */
    public static function ensure_team_room_for_sponsor($sponsor_id) {
        global $wpdb;
        $sponsor_id = (int) $sponsor_id;
        if ($sponsor_id <= 0) {
            return null;
        }

        $threads_t      = $wpdb->prefix . 'matrix_message_threads';
        $participants_t = $wpdb->prefix . 'matrix_message_participants';

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $threads_t WHERE type = 'team_room' AND team_owner_id = %d LIMIT 1",
            $sponsor_id
        ));
        if ($existing) {
            return (int) $existing;
        }

        $now = current_time('mysql', true);
        $sponsor_user = get_userdata($sponsor_id);
        $title = $sponsor_user
            ? sprintf(__('%s — Team Room', 'matrix-mlm'), $sponsor_user->display_name ?: $sponsor_user->user_login)
            : __('Team Room', 'matrix-mlm');

        $wpdb->insert($threads_t, [
            'type'             => 'team_room',
            'team_owner_id'    => $sponsor_id,
            'title'            => $title,
            'status'           => 'active',
            'created_at'       => $now,
            'last_message_at'  => $now,
        ]);
        $thread_id = (int) $wpdb->insert_id;
        if (!$thread_id) {
            return null;
        }
        $wpdb->insert($participants_t, [
            'thread_id'    => $thread_id,
            'user_id'      => $sponsor_id,
            'role'         => 'owner',
            'joined_at'    => $now,
            'last_read_at' => null,
            'muted_until'  => null,
            'removed_at'   => null,
        ]);
        return $thread_id;
    }

    /**
     * Add a referral as a `member` of their sponsor's team room. Idempotent
     * — re-running for the same (sponsor, user) pair is a no-op (UNIQUE on
     * thread_id+user_id).
     */
    public static function add_user_to_team_room($user_id, $sponsor_id) {
        global $wpdb;
        $user_id    = (int) $user_id;
        $sponsor_id = (int) $sponsor_id;
        if ($user_id <= 0 || $sponsor_id <= 0 || $user_id === $sponsor_id) {
            return false;
        }
        $thread_id = self::ensure_team_room_for_sponsor($sponsor_id);
        if (!$thread_id) {
            return false;
        }
        $participants_t = $wpdb->prefix . 'matrix_message_participants';
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $participants_t WHERE thread_id = %d AND user_id = %d",
            $thread_id, $user_id
        ));
        if ($existing) {
            // Reactivate if previously removed (admin reparenting).
            $wpdb->update($participants_t,
                ['removed_at' => null],
                ['id' => (int) $existing]
            );
            return true;
        }
        $wpdb->insert($participants_t, [
            'thread_id'    => $thread_id,
            'user_id'      => $user_id,
            'role'         => 'member',
            'joined_at'    => current_time('mysql', true),
            'last_read_at' => null,
            'muted_until'  => null,
            'removed_at'   => null,
        ]);
        return true;
    }

    /**
     * Lookup a user's sponsor user_id from matrix_user_meta. Returns int|null.
     */
    private static function get_sponsor_id($user_id) {
        global $wpdb;
        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT referred_by FROM {$wpdb->prefix}matrix_user_meta WHERE user_id = %d",
            (int) $user_id
        ));
        return $row ? (int) $row : null;
    }

    /**
     * Self-heal: ensure the calling user is a participant of every team
     * room they should be in (their sponsor's, plus their own as owner if
     * they have at least one referral). Called lazily from the user
     * messaging tab so installs that upgraded into this feature don't
     * need a one-shot migration.
     */
    public static function self_heal_membership($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return;
        }
        $settings = self::get_settings();
        if (empty($settings['team_rooms_auto_create'])) {
            return;
        }

        // As a downline: ensure I'm in my sponsor's team room.
        $sponsor_id = self::get_sponsor_id($user_id);
        if ($sponsor_id) {
            self::add_user_to_team_room($user_id, $sponsor_id);
        }

        // As a sponsor: ensure my own team room exists if I have referrals.
        global $wpdb;
        $has_referrals = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}matrix_user_meta WHERE referred_by = %d LIMIT 1",
            $user_id
        ));
        if ($has_referrals) {
            self::ensure_team_room_for_sponsor($user_id);
            // Add any direct referrals not yet present (e.g. legacy data).
            $referrals = $wpdb->get_col($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->prefix}matrix_user_meta WHERE referred_by = %d",
                $user_id
            ));
            foreach ((array) $referrals as $rid) {
                self::add_user_to_team_room((int) $rid, $user_id);
            }
        }
    }

    /**
     * Insert a message into a thread. Performs:
     *   1. Ban check on sender.
     *   2. Participant check (must be active, non-removed participant).
     *   3. For DM threads, block check in BOTH directions.
     *   4. Rate-limit check.
     *   5. Off-platform contact stripping (if enabled).
     *
     * Returns the inserted message row id, or WP_Error.
     */
    public static function send_message($thread_id, $sender_id, $body, $attachment_id = null) {
        global $wpdb;
        $thread_id  = (int) $thread_id;
        $sender_id  = (int) $sender_id;
        $body       = trim((string) $body);

        if (!self::is_messaging_enabled()) {
            return new WP_Error('matrix_messaging_disabled', __('Messaging is currently disabled.', 'matrix-mlm'));
        }
        if ($thread_id <= 0 || $sender_id <= 0) {
            return new WP_Error('matrix_messaging_bad_args', __('Invalid arguments.', 'matrix-mlm'));
        }
        if ($body === '' && !$attachment_id) {
            return new WP_Error('matrix_messaging_empty', __('Message body required.', 'matrix-mlm'));
        }
        if (self::is_user_banned($sender_id)) {
            return new WP_Error('matrix_messaging_banned', __('You are banned from messaging.', 'matrix-mlm'));
        }
        if (!self::user_can_view_thread($thread_id, $sender_id)) {
            return new WP_Error('matrix_messaging_forbidden', __('You are not a participant of this thread.', 'matrix-mlm'));
        }

        $thread = self::get_thread($thread_id);
        if (!$thread || $thread->status !== 'active') {
            return new WP_Error('matrix_messaging_archived', __('Thread is not active.', 'matrix-mlm'));
        }

        // DM block check: both directions. We refuse to deliver if either
        // party has blocked the other, so blocking is symmetric in effect.
        if ($thread->type === 'dm') {
            $other = self::get_dm_other_party($thread_id, $sender_id);
            if ($other && self::is_blocked_either_way($sender_id, $other)) {
                return new WP_Error('matrix_messaging_blocked', __('Cannot send: one of you has blocked the other.', 'matrix-mlm'));
            }
        }

        // Rate limit. Reuse the existing limiter so admin tooling
        // (Repair Database, etc.) can introspect it the same way.
        $settings = self::get_settings();
        $per_min  = max(1, (int) $settings['rate_limit_per_minute']);
        if (class_exists('Matrix_MLM_Rate_Limiter')) {
            // Minute window
            $ok = Matrix_MLM_Rate_Limiter::check('messaging_send_min:' . $sender_id, $per_min, 60);
            if (!$ok) {
                return new WP_Error('matrix_messaging_rate', __('Slow down — too many messages this minute.', 'matrix-mlm'));
            }
        }

        // Off-platform stripping. We persist a 1/0 flag so admins can tell
        // moderated rows from clean rows in the queue without re-running
        // regexes.
        $body_stripped = 0;
        if (!empty($settings['strip_off_platform_contacts'])) {
            $stripped = self::strip_off_platform_contacts($body);
            if ($stripped !== $body) {
                $body_stripped = 1;
                $body = $stripped;
            }
        }

        $attachment_id = $attachment_id ? (int) $attachment_id : null;
        if ($attachment_id) {
            // Attachment must be a real WP attachment owned by the sender.
            // Anything else gets dropped silently — failing the whole send
            // would let an attacker probe attachment ids by error message.
            $att_post = get_post($attachment_id);
            if (!$att_post || $att_post->post_type !== 'attachment' || (int) $att_post->post_author !== $sender_id) {
                $attachment_id = null;
            } elseif (empty($settings['allow_attachments'])) {
                $attachment_id = null;
            }
        }

        $now = current_time('mysql', true);
        $messages_t = $wpdb->prefix . 'matrix_messages';
        $threads_t  = $wpdb->prefix . 'matrix_message_threads';

        $wpdb->insert($messages_t, [
            'thread_id'     => $thread_id,
            'sender_id'     => $sender_id,
            'body'          => $body,
            'body_stripped' => $body_stripped,
            'attachment_id' => $attachment_id,
            'created_at'    => $now,
            'deleted_at'    => null,
            'deleted_by'    => null,
        ]);
        $message_id = (int) $wpdb->insert_id;
        if (!$message_id) {
            return new WP_Error('matrix_messaging_db_error', __('Could not store message.', 'matrix-mlm'));
        }

        $wpdb->update($threads_t, ['last_message_at' => $now], ['id' => $thread_id]);

        // Mark sender as having read up to and including their own message
        // — saves a round-trip on the next fetch.
        self::mark_thread_read($thread_id, $sender_id);

        // Push delivery fanout (DB 1.0.20 / plugin 2.0.3).
        //
        // Runs AFTER the row + last_message_at update have committed,
        // so a recipient bell-poll arriving in the same tick already
        // sees the row. Soft-fails: any error inside the dispatch is
        // logged and swallowed so a wp_mail or in-app insert failure
        // never aborts the underlying message insert (which the
        // sender's UI has already optimistically rendered).
        try {
            self::dispatch_post_send_notifications(
                $thread,
                $sender_id,
                $message_id,
                $body,
                $attachment_id
            );
        } catch (Exception $e) {
            if (function_exists('error_log')) {
                error_log(sprintf(
                    '[Matrix MLM] Messaging dispatch failed for message %d: %s',
                    $message_id,
                    $e->getMessage()
                ));
            }
        }

        return $message_id;
    }

    public static function get_thread($thread_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_message_threads WHERE id = %d",
            (int) $thread_id
        ));
    }

    public static function user_can_view_thread($thread_id, $user_id) {
        global $wpdb;
        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}matrix_message_participants
             WHERE thread_id = %d AND user_id = %d AND removed_at IS NULL",
            (int) $thread_id, (int) $user_id
        ));
        return !empty($row);
    }

    public static function get_dm_other_party($thread_id, $user_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}matrix_message_participants
             WHERE thread_id = %d AND user_id != %d AND removed_at IS NULL
             LIMIT 1",
            (int) $thread_id, (int) $user_id
        ));
    }

    /**
     * Fetch messages in a thread newer than $after_id. Used by the polling
     * loop — `after_id = 0` returns the initial page, subsequent calls
     * pass the last seen id.
     */
    public static function get_thread_messages($thread_id, $user_id, $after_id = 0, $limit = 50) {
        global $wpdb;
        if (!self::user_can_view_thread($thread_id, $user_id)) {
            return [];
        }
        $limit = max(1, min(200, (int) $limit));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, thread_id, sender_id, body, body_stripped, attachment_id, created_at, deleted_at
             FROM {$wpdb->prefix}matrix_messages
             WHERE thread_id = %d AND id > %d
             ORDER BY id ASC
             LIMIT %d",
            (int) $thread_id, (int) $after_id, $limit
        ));

        // Hydrate attachment_url so the JS can render inline images
        // without an extra round-trip per message.
        foreach ($rows as $r) {
            $r->attachment_url = null;
            if (!empty($r->attachment_id) && empty($r->deleted_at)) {
                $url = wp_get_attachment_url((int) $r->attachment_id);
                if ($url) {
                    // Use a medium-size thumbnail for inline display
                    $thumb = wp_get_attachment_image_url((int) $r->attachment_id, 'medium');
                    $r->attachment_url = $thumb ?: $url;
                }
            }
        }
        return $rows;
    }

    /**
     * List threads visible to a user with last-message preview + unread
     * count. Drives the left rail of the messages tab.
     */
    public static function list_threads_for_user($user_id, $limit = 50) {
        global $wpdb;
        $user_id = (int) $user_id;
        $limit   = max(1, min(200, (int) $limit));

        $threads_t      = $wpdb->prefix . 'matrix_message_threads';
        $participants_t = $wpdb->prefix . 'matrix_message_participants';
        $messages_t     = $wpdb->prefix . 'matrix_messages';

        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT t.id, t.type, t.team_owner_id, t.title, t.status, t.last_message_at,
                   p.last_read_at, p.muted_until,
                   (SELECT COUNT(*) FROM $messages_t m
                      WHERE m.thread_id = t.id
                        AND m.deleted_at IS NULL
                        AND (p.last_read_at IS NULL OR m.created_at > p.last_read_at)
                        AND m.sender_id != %d
                   ) AS unread_count
              FROM $threads_t t
              INNER JOIN $participants_t p ON p.thread_id = t.id AND p.user_id = %d AND p.removed_at IS NULL
             WHERE t.status = 'active'
             ORDER BY t.last_message_at DESC
             LIMIT %d
        ", $user_id, $user_id, $limit));

        // Annotate DM threads with the other party's display name so the
        // UI doesn't have to do an extra round-trip per row.
        foreach ($rows as $r) {
            if ($r->type === 'dm') {
                $other = self::get_dm_other_party($r->id, $user_id);
                $u = $other ? get_userdata($other) : null;
                $r->display_label = $u ? ($u->display_name ?: $u->user_login) : __('(unknown user)', 'matrix-mlm');
                $r->other_user_id = $other;
            } else {
                $r->display_label = $r->title ?: __('Team Room', 'matrix-mlm');
                $r->other_user_id = null;
            }
        }
        return $rows;
    }

    public static function mark_thread_read($thread_id, $user_id) {
        global $wpdb;
        $thread_id = (int) $thread_id;
        $user_id   = (int) $user_id;
        $wpdb->update(
            $wpdb->prefix . 'matrix_message_participants',
            ['last_read_at' => current_time('mysql', true)],
            ['thread_id' => $thread_id, 'user_id' => $user_id]
        );

        // Also clear the bell-icon entries the messaging dispatch
        // enqueued for this thread so the badge reflects the user's
        // actual unread state. Without this, a recipient who opens
        // a thread and reads it still sees the "new message" bell
        // badge until they manually clear it — which created the
        // exact "messages don't disappear from the bell" complaint
        // the dispatch fanout was trying to solve in the first
        // place. mark_thread_messages_read filters by
        // type='message_received' so unrelated bell rows for this
        // user (commissions, deposits, etc.) are unaffected.
        //
        // class_exists / method_exists guarded so older deployments
        // that don't have the in-app notifications module loaded —
        // or future minor versions that drop the helper — fall
        // through cleanly. mark_thread_read is on the read path;
        // a soft-fail here is the right call, the participant
        // pointer update has already committed.
        if (class_exists('Matrix_MLM_In_App_Notifications')
            && method_exists('Matrix_MLM_In_App_Notifications', 'mark_thread_messages_read')) {
            Matrix_MLM_In_App_Notifications::mark_thread_messages_read($user_id, $thread_id);
        }
    }

    // ---------------------------------------------------------------
    // Block / mute / report
    // ---------------------------------------------------------------

    public static function block_user($blocker_id, $blocked_id) {
        global $wpdb;
        $blocker_id = (int) $blocker_id;
        $blocked_id = (int) $blocked_id;
        if ($blocker_id <= 0 || $blocked_id <= 0 || $blocker_id === $blocked_id) {
            return false;
        }
        // INSERT IGNORE via SUBSTITUTE: try insert, swallow UNIQUE collision.
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}matrix_message_blocks (blocker_id, blocked_id, created_at)
             VALUES (%d, %d, %s)",
            $blocker_id, $blocked_id, current_time('mysql', true)
        ));
        return true;
    }

    public static function unblock_user($blocker_id, $blocked_id) {
        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'matrix_message_blocks',
            ['blocker_id' => (int) $blocker_id, 'blocked_id' => (int) $blocked_id]
        );
        return true;
    }

    public static function is_blocked_either_way($a, $b) {
        global $wpdb;
        $a = (int) $a; $b = (int) $b;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}matrix_message_blocks
              WHERE (blocker_id = %d AND blocked_id = %d)
                 OR (blocker_id = %d AND blocked_id = %d)
              LIMIT 1",
            $a, $b, $b, $a
        ));
    }

    public static function mute_thread($thread_id, $user_id, $until_ts) {
        global $wpdb;
        $until = $until_ts ? gmdate('Y-m-d H:i:s', (int) $until_ts) : null;
        $wpdb->update(
            $wpdb->prefix . 'matrix_message_participants',
            ['muted_until' => $until],
            ['thread_id' => (int) $thread_id, 'user_id' => (int) $user_id]
        );
        return true;
    }

    public static function report_message($message_id, $reporter_id, $reason, $note = '') {
        global $wpdb;
        if (!in_array($reason, self::ALLOWED_REPORT_REASONS, true)) {
            $reason = 'other';
        }
        // The reporter must actually be a participant of the message's thread.
        $message_id  = (int) $message_id;
        $reporter_id = (int) $reporter_id;
        $thread_id = $wpdb->get_var($wpdb->prepare(
            "SELECT thread_id FROM {$wpdb->prefix}matrix_messages WHERE id = %d",
            $message_id
        ));
        if (!$thread_id || !self::user_can_view_thread($thread_id, $reporter_id)) {
            return new WP_Error('matrix_messaging_forbidden', __('Cannot report this message.', 'matrix-mlm'));
        }

        $wpdb->insert($wpdb->prefix . 'matrix_message_reports', [
            'message_id'      => $message_id,
            'reporter_id'     => $reporter_id,
            'reason'          => $reason,
            'note'            => mb_substr((string) $note, 0, 1000),
            'created_at'      => current_time('mysql', true),
            'resolved_at'     => null,
            'resolved_by'     => null,
            'resolution'      => null,
        ]);
        return (int) $wpdb->insert_id;
    }

    /**
     * Off-platform contact stripping. Replaces email addresses, phone
     * numbers, and external URLs with [contact removed]. Conservative
     * enough to keep most legitimate prose intact (we don't strip bare
     * digit runs unless they're at least 8 long with phone-shaped
     * separators).
     *
     * Public so admin can preview the strip behaviour from settings.
     */
    public static function strip_off_platform_contacts($body) {
        $patterns = [
            // Emails
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
            // External URLs (http/https/www.)
            '/\b(?:https?:\/\/|www\.)[^\s<>]+/i',
            // Phone-shaped: optional + or 00, then 8+ digits with spaces / dashes / dots / parens.
            '/(?:\+|00)?(?:[\d][\s\-().]?){7,}\d/',
        ];
        return preg_replace($patterns, '[contact removed]', $body);
    }

    // ---------------------------------------------------------------
    // Hook callbacks
    // ---------------------------------------------------------------

    public static function on_user_register($user_id) {
        // matrix_user_meta is not yet populated at user_register time on
        // the standard registration path (the matrix_user_meta INSERT
        // fires later, in process_registration). Defer on a later hook
        // if we can't see a sponsor yet.
        $sponsor_id = self::get_sponsor_id($user_id);
        if ($sponsor_id) {
            self::add_user_to_team_room($user_id, $sponsor_id);
        }
    }

    public static function on_sponsor_changed($user_id, $old_sponsor_id, $new_sponsor_id) {
        global $wpdb;
        // Soft-remove from old sponsor's team room.
        if ($old_sponsor_id) {
            $old_thread = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}matrix_message_threads
                  WHERE type = 'team_room' AND team_owner_id = %d",
                (int) $old_sponsor_id
            ));
            if ($old_thread) {
                $wpdb->update(
                    $wpdb->prefix . 'matrix_message_participants',
                    ['removed_at' => current_time('mysql', true)],
                    ['thread_id' => (int) $old_thread, 'user_id' => (int) $user_id]
                );
            }
        }
        if ($new_sponsor_id) {
            self::add_user_to_team_room($user_id, $new_sponsor_id);
        }
    }

    public static function cron_cleanup() {
        global $wpdb;
        // Hard-delete soft-deleted messages older than 30 days. Reports
        // tied to those messages stay (foreign key is loose) so the
        // moderation history survives the GC pass.
        $cutoff = gmdate('Y-m-d H:i:s', time() - (30 * DAY_IN_SECONDS));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}matrix_messages
              WHERE deleted_at IS NOT NULL AND deleted_at < %s",
            $cutoff
        ));
        // Clear expired mutes.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}matrix_message_participants
                SET muted_until = NULL
              WHERE muted_until IS NOT NULL AND muted_until < %s",
            current_time('mysql', true)
        ));
    }

    // ---------------------------------------------------------------
    // Presence + offline push fanout (DB 1.0.20 / plugin 2.0.3)
    //
    // The recipient-side delivery question is "is the user reachable
    // through the bell channel right now, or do we need to fall back
    // to email?". We answer it with a presence pulse:
    //
    //   - PRESENCE_META is touched on every messaging AJAX guard, on
    //     every bell-icon poll (Matrix_MLM_In_App_Notifications::ajax_fetch),
    //     on Matrix_MLM_User_Messaging::render(), and on the dedicated
    //     ajax_presence beacon below.
    //
    //   - is_online() compares (now - last_seen) against the
    //     presence_window_seconds setting. Default 120s comfortably
    //     covers the bell's ~30s poll cadence with a couple of
    //     missed beats; tunable if an operator runs the bell on a
    //     much slower schedule.
    //
    // dispatch_post_send_notifications() is the fanout entry point
    // called from send_message() after the row commits. It does THREE
    // things per recipient:
    //
    //   1. Always enqueue an in-app row (drives the bell badge bump
    //      on the recipient's next poll — solves the original "only
    //      shows up on next dashboard load" report for ALL recipients,
    //      online or offline).
    //
    //   2. If recipient is OFFLINE and not on cooldown for this
    //      thread, dispatch email (and SMS if enabled) via
    //      Matrix_MLM_Notifications::send_message_notification — the
    //      "push" channel for users who aren't currently on platform.
    //
    //   3. Update the per-(recipient, thread) cooldown so a six-message
    //      burst from the same sender doesn't generate six emails.
    //
    // Filterable (matrix_messaging_should_offline_email) so an
    // operator can plug in a more aggressive batching policy without
    // editing this file.
    // ---------------------------------------------------------------

    /**
     * Refresh the calling user's last-seen presence pulse.
     *
     * Idempotent. Stored as a unix timestamp (not mysql datetime) to
     * keep the comparison in is_online() a single integer subtraction
     * — datetime parsing on every recipient in a fanout would dominate
     * the cost on a wide team-room broadcast.
     */
    public static function update_presence($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return;
        }
        update_user_meta($user_id, self::PRESENCE_META, time());
    }

    /**
     * Is $user_id currently considered "on platform"?
     *
     * Returns false if there's no presence pulse on record (user has
     * not been seen since this feature shipped) — that's the safe
     * default: an unknown user gets the email so we don't silently
     * swallow notifications during the rollout window before every
     * member's first AJAX request lands.
     */
    public static function is_online($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return false;
        }
        $last = (int) get_user_meta($user_id, self::PRESENCE_META, true);
        if ($last <= 0) {
            return false;
        }
        $settings = self::get_settings();
        $window   = max(30, (int) $settings['presence_window_seconds']);
        return (time() - $last) < $window;
    }

    /**
     * Build the short preview used as the in-app body and the email
     * body. Caps at 280 chars (UTF-8-safe) — long enough to be
     * recognisable in an inbox, short enough to never blow past
     * the in-app `body` column or generate an unscrollable bell row.
     */
    private static function build_preview($body, $has_attachment) {
        $body = trim((string) $body);
        if ($body === '') {
            return $has_attachment ? __('(image attachment)', 'matrix-mlm') : '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($body, 'UTF-8') > 280) {
                return mb_substr($body, 0, 277, 'UTF-8') . '…';
            }
            return $body;
        }
        return strlen($body) > 280 ? substr($body, 0, 277) . '…' : $body;
    }

    /**
     * Resolve the human-readable label for a thread. For DMs, it's
     * the OTHER party's display name from the recipient's POV (so
     * "Alice → Bob" thread shows as "Alice" in Bob's inbox); for
     * team rooms, it's the saved title.
     */
    private static function thread_label_for_recipient($thread, $recipient_id) {
        if (isset($thread->type) && $thread->type === 'dm') {
            $other = self::get_dm_other_party((int) $thread->id, (int) $recipient_id);
            $u = $other ? get_userdata($other) : null;
            if ($u) {
                return $u->display_name ?: $u->user_login;
            }
            return __('(unknown user)', 'matrix-mlm');
        }
        return !empty($thread->title) ? (string) $thread->title : __('Team Room', 'matrix-mlm');
    }

    /**
     * Fanout for a freshly-inserted message. Invoked by
     * send_message() right after the threads.last_message_at update
     * lands so a recipient who polls the bell during the same tick
     * sees the unread badge bump immediately rather than waiting for
     * the next thread-list refresh.
     *
     * Soft-fail: notification delivery MUST NOT bubble an exception
     * that aborts the underlying message insert (which has already
     * committed). Any failure to enqueue an in-app row or send an
     * email is logged via WordPress's normal channels and the send
     * still reports success to the sender.
     *
     * @param object $thread        Row from matrix_message_threads (id, type, title, ...)
     * @param int    $sender_id     Author of the just-inserted message.
     * @param int    $message_id    Inserted row id (matrix_messages.id).
     * @param string $body          Already-stripped, already-stored body.
     * @param int    $attachment_id Attachment id (or null).
     * @return void
     */
    private static function dispatch_post_send_notifications($thread, $sender_id, $message_id, $body, $attachment_id) {
        global $wpdb;
        if (!is_object($thread) || empty($thread->id)) {
            return;
        }
        $thread_id = (int) $thread->id;
        $sender_id = (int) $sender_id;

        // Active, non-removed participants other than the sender.
        // Mute is checked per-recipient further down rather than
        // baked into this query so we can still write the bell row
        // for muted users (mute = "don't ping me", not "hide it").
        $participants = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, muted_until
               FROM {$wpdb->prefix}matrix_message_participants
              WHERE thread_id = %d
                AND user_id != %d
                AND removed_at IS NULL",
            $thread_id, $sender_id
        ));
        if (empty($participants)) {
            return;
        }

        $sender_user  = get_userdata($sender_id);
        $sender_label = $sender_user ? ($sender_user->display_name ?: $sender_user->user_login) : __('A member', 'matrix-mlm');
        $is_dm        = isset($thread->type) && $thread->type === 'dm';
        $preview      = self::build_preview($body, !empty($attachment_id));

        // Same-host link only — Matrix_MLM_In_App_Notifications drops
        // off-host links silently, so the dashboard-relative form is
        // the safe shape here regardless of how home_url() is
        // configured.
        $link_url = '/matrix-dashboard/messages/?open=' . $thread_id;

        $settings              = self::get_settings();
        $offline_email_enabled = !empty($settings['offline_email_enabled']);
        $cooldown              = max(0, (int) $settings['offline_email_cooldown_seconds']);
        $now_ts                = time();

        foreach ($participants as $p) {
            $recipient_id = (int) $p->user_id;
            if ($recipient_id <= 0) {
                continue;
            }

            $thread_label = self::thread_label_for_recipient($thread, $recipient_id);
            $title = $is_dm
                ? sprintf(
                    /* translators: %s: sender display name */
                    __('New message from %s', 'matrix-mlm'),
                    $sender_label
                )
                : sprintf(
                    /* translators: %s: thread/team-room name */
                    __('New message in %s', 'matrix-mlm'),
                    $thread_label
                );

            // 1. In-app bell row — for EVERY recipient, online or offline.
            //    This is the piece that fixes the "messages surface only
            //    on next dashboard load + polling" complaint: the bell
            //    poll runs everywhere on the dashboard, so a user with
            //    the dashboard open in another tab sees the badge bump
            //    within one bell tick instead of having to navigate to
            //    the messages page.
            if (class_exists('Matrix_MLM_In_App_Notifications')) {
                Matrix_MLM_In_App_Notifications::enqueue(
                    $recipient_id,
                    self::NEW_MESSAGE_NOTIF_TYPE,
                    $title,
                    $preview,
                    $link_url,
                    [
                        'thread_id'   => $thread_id,
                        'message_id'  => (int) $message_id,
                        'sender_id'   => $sender_id,
                        'thread_type' => isset($thread->type) ? (string) $thread->type : 'dm',
                    ]
                );
            }

            // 2. Email / SMS push — only if recipient is OFFLINE.
            //    Skip silently for muted threads, banned users, and
            //    blocked DM counterparties; those are intentional
            //    recipient-side opt-outs.
            if (!$offline_email_enabled) {
                continue;
            }
            if (!empty($p->muted_until)) {
                $mute_ts = strtotime((string) $p->muted_until . ' UTC');
                if ($mute_ts && $mute_ts > $now_ts) {
                    continue;
                }
            }
            if (self::is_user_banned($recipient_id)) {
                continue;
            }
            if ($is_dm && self::is_blocked_either_way($sender_id, $recipient_id)) {
                continue;
            }
            if (self::is_online($recipient_id)) {
                continue;
            }

            // Per-(recipient, thread) cooldown to coalesce bursts.
            $cd_key = self::OFFLINE_EMAIL_META_PREFIX . $thread_id;
            $last_email_at = (int) get_user_meta($recipient_id, $cd_key, true);
            if ($cooldown > 0 && $last_email_at > 0 && ($now_ts - $last_email_at) < $cooldown) {
                continue;
            }

            /**
             * Filter: matrix_messaging_should_offline_email
             *
             * Last gate before dispatch. Return false to suppress
             * the email for this recipient (e.g. an operator who
             * wants to disable push for a specific user role).
             */
            $should = apply_filters(
                'matrix_messaging_should_offline_email',
                true,
                $recipient_id,
                $sender_id,
                $thread,
                $message_id
            );
            if (!$should) {
                continue;
            }

            if (class_exists('Matrix_MLM_Notifications')
                && method_exists('Matrix_MLM_Notifications', 'send_message_notification')) {
                $sent = Matrix_MLM_Notifications::send_message_notification(
                    $recipient_id,
                    $sender_id,
                    $thread,
                    $preview,
                    !empty($attachment_id)
                );
                if ($sent) {
                    update_user_meta($recipient_id, $cd_key, $now_ts);
                }
            }
        }
    }

    // ---------------------------------------------------------------
    // Cross-thread message search (DB 1.0.20 / plugin 2.0.3)
    //
    // Filters to threads the calling user is a participant of (the
    // INNER JOIN against matrix_message_participants is what makes
    // this safe — there's no way to surface a message from a thread
    // the user isn't in), excludes soft-deleted rows, and runs a
    // LIKE %q% against body. We deliberately do NOT use FULLTEXT:
    //
    //   - dbDelta has long-standing issues emitting FULLTEXT against
    //     InnoDB on older MySQL versions still in our supported range.
    //
    //   - The volume per user is tiny (a typical member has thousands
    //     of messages, not millions); a covering index on
    //     (thread_id, id) plus the participants filter narrows the
    //     scan enough that LIKE is fast.
    //
    //   - LIKE works identically across MySQL / MariaDB / Aurora
    //     without a CREATE FULLTEXT migration that operators have
    //     to opt into.
    // ---------------------------------------------------------------

    /**
     * Search messages visible to $user_id whose body matches $query.
     *
     * @param int    $user_id Caller — defines the visibility scope.
     * @param string $query   2..100 chars; shorter queries return [].
     * @param int    $limit   1..100, default 50.
     * @return array Each row: id, thread_id, sender_id, body, created_at,
     *               type, team_owner_id, title (raw thread fields), plus
     *               sender_label, thread_label, snippet (computed
     *               server-side so the JS doesn't need locale-aware
     *               substring math).
     */
    public static function search_messages($user_id, $query, $limit = 50) {
        global $wpdb;
        $user_id = (int) $user_id;
        $query   = trim((string) $query);
        if ($user_id <= 0 || $query === '') {
            return [];
        }
        $qlen = function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query);
        if ($qlen < 2 || $qlen > 100) {
            return [];
        }
        $limit = max(1, min(100, (int) $limit));
        $like  = '%' . $wpdb->esc_like($query) . '%';

        $messages_t     = $wpdb->prefix . 'matrix_messages';
        $threads_t      = $wpdb->prefix . 'matrix_message_threads';
        $participants_t = $wpdb->prefix . 'matrix_message_participants';

        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT m.id, m.thread_id, m.sender_id, m.body, m.created_at,
                   t.type, t.team_owner_id, t.title
              FROM $messages_t m
              INNER JOIN $threads_t t ON t.id = m.thread_id
              INNER JOIN $participants_t p ON p.thread_id = t.id AND p.user_id = %d AND p.removed_at IS NULL
             WHERE m.deleted_at IS NULL
               AND t.status = 'active'
               AND m.body LIKE %s
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT %d
        ", $user_id, $like, $limit));

        if (empty($rows)) {
            return [];
        }

        // Hydrate sender + thread labels and a query-centred snippet.
        // We do this in PHP after the SQL because thread_label is
        // recipient-relative for DMs (the OTHER party's name) and
        // can't be computed in the same SELECT without a self-join
        // that would break the readability of the visibility check.
        foreach ($rows as $r) {
            $sender = get_userdata((int) $r->sender_id);
            $r->sender_label = $sender
                ? ($sender->display_name ?: $sender->user_login)
                : __('(unknown)', 'matrix-mlm');
            $r->thread_label = self::thread_label_for_recipient($r, $user_id);
            $r->snippet      = self::build_search_snippet((string) $r->body, $query, 200);
        }
        return $rows;
    }

    /**
     * Return ~$window characters of $body centred on the first
     * occurrence of $query. Adds a leading "…" when we clip the
     * left edge and a trailing "…" when we clip the right edge so
     * the user sees that the result is a snippet rather than the
     * full message.
     *
     * UTF-8-safe via mb_* with a degraded ASCII fallback for hosts
     * without ext/mbstring.
     */
    private static function build_search_snippet($body, $query, $window = 200) {
        $body  = (string) $body;
        $query = (string) $query;
        if ($body === '') {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            $len = mb_strlen($body, 'UTF-8');
            if ($len <= $window) {
                return $body;
            }
            $pos = function_exists('mb_stripos')
                ? mb_stripos($body, $query, 0, 'UTF-8')
                : stripos($body, $query);
            if ($pos === false) {
                return mb_substr($body, 0, $window, 'UTF-8') . '…';
            }
            $start = max(0, (int) $pos - (int) ($window / 4));
            $snip  = mb_substr($body, $start, $window, 'UTF-8');
            if ($start > 0) {
                $snip = '…' . $snip;
            }
            if (($start + $window) < $len) {
                $snip .= '…';
            }
            return $snip;
        }
        if (strlen($body) <= $window) {
            return $body;
        }
        $pos = stripos($body, $query);
        if ($pos === false) {
            return substr($body, 0, $window) . '…';
        }
        $start = max(0, (int) $pos - (int) ($window / 4));
        $snip  = substr($body, $start, $window);
        if ($start > 0) {
            $snip = '…' . $snip;
        }
        if (($start + $window) < strlen($body)) {
            $snip .= '…';
        }
        return $snip;
    }

    // ---------------------------------------------------------------
    // Read receipts
    //
    // Computes "who has read which of $user_id's recent messages in
    // $thread_id" and returns a compact map keyed by message id.
    // Returned on every ajax_fetch_thread tick so receipt state on
    // already-rendered messages updates asynchronously: when B reads
    // a message A sent earlier, A's next poll picks up the new
    // read_count without A having to refresh.
    //
    // Why not embed receipts inside each message row in
    // get_thread_messages()? Polling tends to return delta-only on
    // tick (id > after_id), so by the time B reads message #42 a
    // delta-only response wouldn't surface the receipt change at
    // all — message #42 isn't in the delta. A standalone receipts
    // map covers the recent own messages in full on every tick
    // regardless of message delta state.
    //
    // Cap (RECEIPT_LOOKBACK) is the most-recent N own messages.
    // Older own messages are presumed long-since read by everyone
    // and the receipt UX cares about "did my recent message get
    // seen?" not "what was the read state of message I sent two
    // weeks ago." Keeping the cap small bounds the response size
    // and the recipient-list query at two queries regardless of
    // thread length.
    //
    // Self is excluded from the recipient list — the user's own
    // last_read_at is not relevant to their own outgoing receipt
    // (and including self would always read true since send_message
    // calls mark_thread_read on the sender, inflating read_count).
    // ---------------------------------------------------------------

    /**
     * How many of the user's most recent own messages to include in
     * the receipts map. 50 is a generous upper bound: the initial
     * fetch_thread response returns at most 50 messages of which a
     * fraction will be the user's own.
     */
    const RECEIPT_LOOKBACK = 50;

    /**
     * Compute read receipts for $user_id's recent own messages in
     * $thread_id.
     *
     * @param int $thread_id
     * @param int $user_id   The viewer — only their own messages get
     *                       receipts. Receipts on someone else's
     *                       messages would just leak read state of
     *                       the other party and aren't useful UX.
     * @return array<int, array{read_count:int, recipient_count:int, last_read_at:?string}>
     *         Map: message_id => {read_count, recipient_count, last_read_at}.
     *         Empty array when the user has no own messages in the
     *         thread (or the thread is invisible to them — checked
     *         by the caller).
     */
    public static function compute_thread_read_receipts($thread_id, $user_id) {
        global $wpdb;
        $thread_id = (int) $thread_id;
        $user_id   = (int) $user_id;
        if ($thread_id <= 0 || $user_id <= 0) {
            return [];
        }

        $messages_t     = $wpdb->prefix . 'matrix_messages';
        $participants_t = $wpdb->prefix . 'matrix_message_participants';

        // 1) Pull the lookback window of own messages, oldest-first
        //    inside the window so the JS can apply receipts in
        //    natural order.
        $own = $wpdb->get_results($wpdb->prepare("
            SELECT id, created_at
              FROM $messages_t
             WHERE thread_id = %d
               AND sender_id = %d
               AND deleted_at IS NULL
             ORDER BY id DESC
             LIMIT %d
        ", $thread_id, $user_id, self::RECEIPT_LOOKBACK));

        if (empty($own)) {
            return [];
        }

        // 2) Pull every OTHER active participant's last_read_at on
        //    this thread. Bounded by the participant count, which
        //    for DMs is 1 and for team rooms is sponsor + direct
        //    referrals — comfortably small for an in-memory walk.
        $others = $wpdb->get_results($wpdb->prepare("
            SELECT user_id, last_read_at
              FROM $participants_t
             WHERE thread_id = %d
               AND user_id != %d
               AND removed_at IS NULL
        ", $thread_id, $user_id));

        $recipient_count = count($others);

        $receipts = [];
        foreach ($own as $msg) {
            // GMT comparison: matrix_messages.created_at and
            // matrix_message_participants.last_read_at are both
            // written via current_time('mysql', true), so a string
            // comparison is correctness-equivalent to a datetime
            // parse and orders-of-magnitude cheaper inside a
            // foreach loop.
            $read_count = 0;
            $latest_read_at = null;
            foreach ($others as $p) {
                if (!empty($p->last_read_at) && $p->last_read_at >= $msg->created_at) {
                    $read_count++;
                    if ($latest_read_at === null || $p->last_read_at > $latest_read_at) {
                        $latest_read_at = $p->last_read_at;
                    }
                }
            }
            $receipts[(int) $msg->id] = [
                'read_count'      => $read_count,
                'recipient_count' => $recipient_count,
                'last_read_at'    => $latest_read_at,
            ];
        }
        return $receipts;
    }

    // ---------------------------------------------------------------
    // AJAX handlers — every endpoint is logged-in only.
    // ---------------------------------------------------------------

    private static function ajax_guard() {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Login required', 'matrix-mlm')], 401);
        }
        check_ajax_referer('matrix_messaging', 'nonce');
        $uid = get_current_user_id();
        // Touch presence on every authenticated messaging hit. This
        // is what gates offline-email dispatch — a tab actively
        // chatting keeps last_seen fresh and so never receives an
        // email for messages they're already reading.
        self::update_presence($uid);
        return $uid;
    }

    public static function ajax_list_threads() {
        $uid = self::ajax_guard();
        self::self_heal_membership($uid);
        $rows = self::list_threads_for_user($uid);
        wp_send_json_success(['threads' => $rows]);
    }

    public static function ajax_fetch_thread() {
        $uid = self::ajax_guard();
        $thread_id = (int) ($_REQUEST['thread_id'] ?? 0);
        $after_id  = (int) ($_REQUEST['after_id']  ?? 0);
        if (!self::user_can_view_thread($thread_id, $uid)) {
            wp_send_json_error(['message' => __('Forbidden', 'matrix-mlm')], 403);
        }
        $rows = self::get_thread_messages($thread_id, $uid, $after_id);
        // Mark read on initial page only (after_id == 0). Subsequent
        // delta fetches don't update read pointer until the user
        // explicitly scrolls — avoids losing the unread badge while a
        // background tab polls.
        if ($after_id === 0) {
            self::mark_thread_read($thread_id, $uid);
        }

        // Read receipts. Returned on EVERY fetch (not just the
        // initial page) because read state on already-rendered
        // messages changes asynchronously: A renders message #42
        // at t0, B opens the thread at t1, A's next poll tick at
        // t2 should reflect the new "Seen" state. The receipts
        // map carries that delta. Capped to the most recent
        // own-side messages so a long thread doesn't bloat the
        // poll response.
        $thread = self::get_thread($thread_id);
        $thread_type = $thread && isset($thread->type) ? (string) $thread->type : 'dm';

        wp_send_json_success([
            'messages'    => $rows,
            'thread_type' => $thread_type,
            'receipts'    => self::compute_thread_read_receipts($thread_id, $uid),
        ]);
    }

    public static function ajax_send() {
        $uid = self::ajax_guard();
        $thread_id     = (int) ($_POST['thread_id'] ?? 0);
        $body          = (string) ($_POST['body'] ?? '');
        $attachment_id = isset($_POST['attachment_id']) ? (int) $_POST['attachment_id'] : null;

        $result = self::send_message($thread_id, $uid, $body, $attachment_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['message_id' => $result]);
    }

    public static function ajax_open_dm() {
        $uid = self::ajax_guard();
        // Recipient resolution: by user_id, username, or referral_code.
        $target_id = 0;
        if (!empty($_POST['user_id'])) {
            $target_id = (int) $_POST['user_id'];
        } elseif (!empty($_POST['username'])) {
            $u = get_user_by('login', sanitize_user(wp_unslash($_POST['username'])));
            $target_id = $u ? (int) $u->ID : 0;
        } elseif (!empty($_POST['referral_code'])) {
            global $wpdb;
            $code = sanitize_text_field(wp_unslash($_POST['referral_code']));
            $target_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->prefix}matrix_user_meta WHERE referral_code = %s",
                $code
            ));
        }
        if ($target_id <= 0 || $target_id === $uid) {
            wp_send_json_error(['message' => __('Recipient not found.', 'matrix-mlm')]);
        }
        if (self::is_user_banned($uid)) {
            wp_send_json_error(['message' => __('You are banned from messaging.', 'matrix-mlm')]);
        }
        $result = self::get_or_create_dm_thread($uid, $target_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['thread_id' => $result]);
    }

    public static function ajax_mark_read() {
        $uid = self::ajax_guard();
        $thread_id = (int) ($_POST['thread_id'] ?? 0);
        if (self::user_can_view_thread($thread_id, $uid)) {
            self::mark_thread_read($thread_id, $uid);
        }
        wp_send_json_success();
    }

    public static function ajax_block() {
        $uid = self::ajax_guard();
        $target = (int) ($_POST['user_id'] ?? 0);
        self::block_user($uid, $target);
        wp_send_json_success();
    }

    public static function ajax_unblock() {
        $uid = self::ajax_guard();
        $target = (int) ($_POST['user_id'] ?? 0);
        self::unblock_user($uid, $target);
        wp_send_json_success();
    }

    public static function ajax_mute() {
        $uid = self::ajax_guard();
        $thread_id = (int) ($_POST['thread_id'] ?? 0);
        // Mute window: hours from now, capped at 30d.
        $hours = max(0, min(720, (int) ($_POST['hours'] ?? 24)));
        $until = $hours > 0 ? time() + ($hours * 3600) : 0;
        if (self::user_can_view_thread($thread_id, $uid)) {
            self::mute_thread($thread_id, $uid, $until);
        }
        wp_send_json_success();
    }

    public static function ajax_report() {
        $uid = self::ajax_guard();
        $message_id = (int) ($_POST['message_id'] ?? 0);
        $reason     = sanitize_text_field($_POST['reason'] ?? 'other');
        $note       = sanitize_textarea_field($_POST['note'] ?? '');
        $r = self::report_message($message_id, $uid, $reason, $note);
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message()]);
        }
        wp_send_json_success(['report_id' => $r]);
    }

    /**
     * POST action=matrix_messaging_search with q=<query>.
     *
     * Returns up to 50 matching messages across every thread the
     * caller is a participant of, newest first. Each result row is
     * shaped for direct rendering — sender label, recipient-relative
     * thread label, and a query-centred snippet are all already
     * computed server-side.
     */
    public static function ajax_search() {
        $uid = self::ajax_guard();
        $q = isset($_REQUEST['q']) ? wp_unslash((string) $_REQUEST['q']) : '';
        $q = sanitize_text_field($q);
        $rows = self::search_messages($uid, $q, 50);
        wp_send_json_success([
            'query'   => $q,
            'count'   => count($rows),
            'results' => $rows,
        ]);
    }

    /**
     * POST action=matrix_messaging_presence.
     *
     * Lightweight beacon for users who are on the dashboard but NOT
     * in the messages tab. The bell-icon poll already touches
     * presence via Matrix_MLM_In_App_Notifications::ajax_fetch, so
     * this is mostly redundant on a normal dashboard load — kept
     * as a dedicated endpoint so a tab without the bell mounted
     * (e.g. a custom shortcode-only deployment) can still
     * advertise itself online.
     */
    public static function ajax_presence() {
        $uid = self::ajax_guard();
        // ajax_guard already updated presence; nothing else to do.
        wp_send_json_success(['ts' => time(), 'user_id' => $uid]);
    }
}
