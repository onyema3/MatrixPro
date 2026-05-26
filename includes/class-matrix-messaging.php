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
     *
     * Thread types (DB 1.0.24):
     *   - dm:         1:1 direct message (open model — any active member can DM any other)
     *   - team_room:  auto-created sponsor room, one per sponsor, sponsor is owner
     *   - group_room: user-created multi-party room, creator becomes owner
     *                 and is the only role permitted to invite or remove
     *                 members in v1. Different from team_room because it
     *                 has no sponsor-graph dependency: members are added
     *                 by explicit invite, not by being someone's referral.
     */
    const THREAD_TYPES         = ['dm', 'team_room', 'group_room'];
    const PARTICIPANT_ROLES    = ['owner', 'member'];
    const ALLOWED_REPORT_REASONS = ['spam', 'harassment', 'off_platform', 'scam', 'other'];

    const BAN_META_UNTIL  = 'matrix_messaging_banned_until';   // 'permanent' | 'YYYY-mm-dd HH:ii:ss'
    const BAN_META_REASON = 'matrix_messaging_ban_reason';
    /**
     * Accountability metadata written alongside the ban itself so the
     * Bans admin tab can surface "who banned whom and when" without
     * grepping audit logs. Cleared by unban_user() in lockstep with
     * the ban_until / ban_reason pair so a stale row is impossible.
     *
     * BAN_META_AT  — GMT mysql datetime string at which ban_user()
     *                committed. Distinct from `created_at` on any
     *                originating report row because the same report
     *                can be revisited and a fresh ban placed later.
     * BAN_META_BY  — User id of the admin who triggered the ban.
     *                Zero when the ban was placed by a non-user
     *                context (cron, WP-CLI, automated trigger).
     */
    const BAN_META_AT     = 'matrix_messaging_banned_at';
    const BAN_META_BY     = 'matrix_messaging_banned_by';

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
     * Sender-side edit / self-delete window, in seconds.
     *
     * Default 300s (5 minutes) — long enough to catch a typo a
     * recipient hasn't responded to yet, short enough that nobody
     * can rewrite the historical record after a conversation has
     * advanced. Operators who want to widen or narrow the window
     * can set the same key in matrix_mlm_messaging_settings; the
     * default is also exposed via the matrix_messaging_edit_window_seconds
     * filter so a custom_post_install hook can pin it without a
     * settings round-trip.
     *
     * The same window gates BOTH edit and self-delete: if you
     * can't edit it any more, you can't soft-delete it for the
     * room either. This matches the WhatsApp / Slack pattern of
     * a single "I changed my mind" window rather than two
     * separate timers, and prevents the awkward edge case of a
     * user being able to delete-but-not-edit a stale message
     * (or vice versa) which is harder for a recipient to reason
     * about than a single uniform rule.
     *
     * Soft-delete (not hard) — the row stays in matrix_messages
     * with deleted_at populated, mirroring the moderator-delete
     * shape so the UI's "(message deleted)" placeholder works
     * uniformly. cron_cleanup() hard-deletes after 30 days for
     * both delete origins.
     */
    const EDIT_WINDOW_SECONDS_DEFAULT = 300;

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
    /**
     * Default cap on the number of attachments a single send may
     * carry (DB 1.0.23). Four matches the WhatsApp / Slack
     * convention and keeps the rendered grid tidy (1 = full bubble,
     * 2 = side-by-side, 3-4 = 2x2). Operators can widen via the
     * matrix_messaging_settings.max_attachments_per_message key or
     * the matrix_messaging_max_attachments_per_message filter.
     * Anything beyond 8 is refused at the model layer to keep the
     * thumbnail fanout bounded — bigger payloads are the file-
     * sharing surface, not the chat surface.
     */
    const MAX_ATTACHMENTS_PER_MESSAGE_DEFAULT = 4;
    const MAX_ATTACHMENTS_PER_MESSAGE_HARD    = 8;

    /**
     * Voice-note constants (DB 1.0.25).
     *
     * Voice notes ride on top of the same matrix_message_attachments
     * row that images use, with `kind = 'voice'` discriminating the
     * render path. The intentionally short MIME whitelist below
     * covers what real browsers' MediaRecorder emits — Opus in
     * WebM (Chrome/Firefox/Edge), Opus in Ogg (legacy Firefox),
     * AAC in MP4 (Safari iOS+macOS), and MP3 as a defensive catch
     * for some Android browsers. We deliberately do NOT accept
     * audio/wav: at speech bitrates it's 10x the size of Opus for
     * no perceptual quality gain and would trivially exhaust the
     * byte cap, taking the operator-tunable cap from "useful
     * brake" to "always-tripped error".
     *
     * `voice_allowed_mime()` runs this through wp_get_mime_types()
     * so an operator filter can never widen WP's own upload
     * allow-list (a defensive belt + braces against a typo in a
     * matrix_messaging_voice_allowed_mime hook).
     */
    const VOICE_ALLOWED_MIME = [
        'audio/webm',
        'audio/ogg',
        'audio/mp4',
        'audio/mpeg',
    ];

    /**
     * Default 2 minutes; hard ceiling 5. The duration cap exists
     * to keep voice notes voicemail-shaped (the "I'm running late,
     * call you back" usecase) rather than podcast-shaped. A 30-min
     * "voice note" is a podcast and should ride a different
     * surface. Operators can lower the default via the settings
     * UI; the hard ceiling cannot be raised at runtime, so a
     * misconfigured filter cannot turn the feature into a
     * podcast host.
     */
    const VOICE_MAX_DURATION_SECONDS_DEFAULT = 120;
    const VOICE_MAX_DURATION_SECONDS_HARD    = 300;

    /**
     * Default 2 MB. Large enough for ~10 minutes of speech-bitrate
     * Opus; small enough that a malicious client cannot exhaust
     * the upload cap with a single voice note. Hard ceiling is
     * pinned to the existing per-attachment max_attachment_bytes
     * — voice notes can never be larger than the image cap.
     */
    const VOICE_MAX_BYTES_DEFAULT = 2097152; // 2 * 1024 * 1024

    /**
     * Documented client-side waveform target. The recorder UI in
     * PR 2 will downsample the recorded PCM into this many
     * amplitude buckets and ship them as a JSON array on the new
     * waveform_peaks_json column. Server-side render code does
     * not recompute peaks; it renders whatever the client sent
     * (or a flat bar if the column is NULL).
     *
     * Kept as a class constant so the JS payload can read it
     * via wp_localize_script and the two ends never drift.
     */
    const VOICE_WAVEFORM_PEAKS = 64;

    /**
     * Subdirectory (relative to wp_upload_dir basedir) under
     * which voice attachments live. The leading + trailing slashes
     * are part of the contract — Matrix_MLM_Attachment_Signer's
     * ALLOWED_SUBTREES list expects strings of this exact shape.
     */
    const VOICE_UPLOAD_SUBTREE = '/matrix-messaging-voice/';

    /**
     * Typing-indicator timing (DB 1.0.23 — no schema, transient-backed).
     *
     * TYPING_TTL_SECONDS — how long a typing beacon counts as
     * "still typing" before falling off. Slightly longer than the
     * client beacon interval so a single dropped HTTP request
     * doesn't make the indicator flicker, but short enough that a
     * user who closes their tab mid-compose stops looking like
     * they're typing within a couple of seconds.
     *
     * TYPING_BEACON_INTERVAL_MS — advisory cadence the JS uses to
     * refresh the beacon while the user is actively typing.
     * Surfaced via wp_localize_script so an operator who wants a
     * different cadence can patch one place.
     *
     * Storage is a per-(thread, user) WP transient with a 6-second
     * TTL, so an idle keyboard auto-clears without a cleanup cron
     * and we never accumulate stale rows in the DB. Reads probe
     * one transient per active participant of a thread on every
     * fetch_thread call — N participants worth of cache get()s,
     * which is cheap on object-cached hosts (where transients
     * land in memcached / Redis) and acceptable on the database-
     * fallback path because the same query gets dropped on the
     * 6-second expiration anyway.
     */
    const TYPING_TTL_SECONDS         = 6;
    const TYPING_BEACON_INTERVAL_MS  = 3000;
    const TYPING_TRANSIENT_PREFIX    = 'matrix_msg_typing_';

    /**
     * Group-room caps (DB 1.0.24).
     *
     * MAX_GROUP_ROOMS_PER_USER_DEFAULT — how many group rooms a single
     * user can OWN (be the creator of). Members in someone else's
     * room don't count, only owned rooms. Five matches the WhatsApp
     * "groups you admin" surface and gives operators a small enough
     * blast radius if a single user goes spam-creator.
     *
     * MAX_MEMBERS_PER_GROUP_ROOM_DEFAULT — including the owner. Fifty
     * is the WhatsApp default for non-Business groups and is large
     * enough that almost any legitimate use case fits, while small
     * enough that a notify-everyone broadcast can't fan out to 1000
     * recipients per send.
     *
     * Hard ceilings are documented in get_max_group_rooms_per_user
     * and get_max_members_per_group_room — operators can raise the
     * stored option via the admin settings UI but the runtime still
     * clamps to the hard ceiling. Filters
     * matrix_messaging_max_group_rooms_per_user and
     * matrix_messaging_max_members_per_group_room let code-level
     * callers override at runtime, again clamped to the hard
     * ceiling so a typo can't let through 10000-member rooms.
     */
    const MAX_GROUP_ROOMS_PER_USER_DEFAULT       = 5;
    const MAX_GROUP_ROOMS_PER_USER_HARD          = 25;
    const MAX_MEMBERS_PER_GROUP_ROOM_DEFAULT     = 50;
    const MAX_MEMBERS_PER_GROUP_ROOM_HARD        = 200;

    public static function default_settings() {
        return [
            'enabled'                       => 1,
            'rate_limit_per_minute'         => 20,
            'rate_limit_per_hour'           => 200,
            'strip_off_platform_contacts'   => 1,
            'allow_attachments'             => 1,
            'max_attachment_bytes'          => 5 * 1024 * 1024,
            // Voice notes (DB 1.0.25). See VOICE_* constants above
            // for the rationale on each default and the hard
            // ceilings runtime resolvers clamp to.
            'allow_voice_notes'             => 1,
            'voice_max_duration_seconds'    => self::VOICE_MAX_DURATION_SECONDS_DEFAULT,
            'voice_max_bytes'               => self::VOICE_MAX_BYTES_DEFAULT,
            // Multi-attachment cap (DB 1.0.23). See
            // MAX_ATTACHMENTS_PER_MESSAGE_DEFAULT for rationale.
            'max_attachments_per_message'   => self::MAX_ATTACHMENTS_PER_MESSAGE_DEFAULT,
            // Group-room caps (DB 1.0.24).
            'max_group_rooms_per_user'      => self::MAX_GROUP_ROOMS_PER_USER_DEFAULT,
            'max_members_per_group_room'    => self::MAX_MEMBERS_PER_GROUP_ROOM_DEFAULT,
            'team_rooms_auto_create'        => 1,
            'polling_interval_ms'           => 10000,
            // Sender-side edit / self-delete time window. See
            // EDIT_WINDOW_SECONDS_DEFAULT above.
            'edit_window_seconds'           => self::EDIT_WINDOW_SECONDS_DEFAULT,
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
        add_action('wp_ajax_matrix_messaging_fetch_older',   [__CLASS__, 'ajax_fetch_older']);
        add_action('wp_ajax_matrix_messaging_send',          [__CLASS__, 'ajax_send']);
        add_action('wp_ajax_matrix_messaging_edit',          [__CLASS__, 'ajax_edit']);
        add_action('wp_ajax_matrix_messaging_delete',        [__CLASS__, 'ajax_delete']);
        add_action('wp_ajax_matrix_messaging_open_dm',       [__CLASS__, 'ajax_open_dm']);
        add_action('wp_ajax_matrix_messaging_mark_read',     [__CLASS__, 'ajax_mark_read']);
        add_action('wp_ajax_matrix_messaging_block',         [__CLASS__, 'ajax_block']);
        add_action('wp_ajax_matrix_messaging_unblock',       [__CLASS__, 'ajax_unblock']);
        add_action('wp_ajax_matrix_messaging_mute',          [__CLASS__, 'ajax_mute']);
        add_action('wp_ajax_matrix_messaging_report',        [__CLASS__, 'ajax_report']);
        // Toggle an emoji reaction on a message (DB 1.0.21).
        // POST shape so the nonce / login gate matches every
        // other messaging endpoint.
        add_action('wp_ajax_matrix_messaging_react',         [__CLASS__, 'ajax_react']);
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

        // Team-room membership management (DB 1.0.22).
        // Both endpoints are gated by ajax_guard() and additionally
        // by user_can_view_thread() at the model layer — a member
        // can only list / leave a thread they're already in.
        add_action('wp_ajax_matrix_messaging_list_members',  [__CLASS__, 'ajax_list_members']);
        add_action('wp_ajax_matrix_messaging_leave_thread',  [__CLASS__, 'ajax_leave_thread']);

        // Typing indicator beacon (DB 1.0.23 — transient-backed,
        // no schema). The client pings this on a slow cadence
        // while the user is composing; the server stamps a
        // short-TTL transient that ajax_fetch_thread reads back
        // to populate the typing[] field on the poll response.
        add_action('wp_ajax_matrix_messaging_typing',        [__CLASS__, 'ajax_typing']);

        // Group rooms (DB 1.0.24). Three endpoints behind
        // ajax_guard(); per-action authorization (creator vs
        // owner vs participant) lives at the model layer so the
        // AJAX surface stays a thin pass-through.
        add_action('wp_ajax_matrix_messaging_create_group_room', [__CLASS__, 'ajax_create_group_room']);
        add_action('wp_ajax_matrix_messaging_invite_to_group',   [__CLASS__, 'ajax_invite_to_group']);
        add_action('wp_ajax_matrix_messaging_remove_from_group', [__CLASS__, 'ajax_remove_from_group']);

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
     * Resolve the effective edit / self-delete window in seconds.
     *
     * Reads matrix_mlm_messaging_settings.edit_window_seconds, then
     * runs it through the matrix_messaging_edit_window_seconds
     * filter. Floored at 0 (disable feature) and ceilinged at
     * 24 hours so a misconfigured option can't silently turn into
     * an unlimited rewrite license.
     */
    public static function get_edit_window_seconds() {
        $s = self::get_settings();
        $window = (int) (isset($s['edit_window_seconds'])
            ? $s['edit_window_seconds']
            : self::EDIT_WINDOW_SECONDS_DEFAULT);
        $window = (int) apply_filters('matrix_messaging_edit_window_seconds', $window);
        if ($window < 0) {
            $window = 0;
        }
        if ($window > DAY_IN_SECONDS) {
            $window = DAY_IN_SECONDS;
        }
        return $window;
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
            // Expired — clear all four ban-meta keys in lockstep so
            // the Bans admin tab does not surface stale banned_at /
            // banned_by rows for users whose temp ban has timed out.
            // Done inline rather than via unban_user() because this
            // is a passive expiry, not an explicit moderator action,
            // and we do not want to fire matrix_messaging_user_unbanned
            // on every is_user_banned() call past the expiry second.
            delete_user_meta((int) $user_id, self::BAN_META_UNTIL);
            delete_user_meta((int) $user_id, self::BAN_META_REASON);
            delete_user_meta((int) $user_id, self::BAN_META_AT);
            delete_user_meta((int) $user_id, self::BAN_META_BY);
            return false;
        }
        return $until;
    }

    /**
     * Place a messaging ban on a user. Centralises the four user_meta
     * writes (until / reason / banned_at / banned_by) and emits the
     * matrix_messaging_user_banned action so audit-log and notification
     * subscribers can react without observing wp_usermeta directly.
     *
     * Two valid forms for $until:
     *   - 'permanent'                    — never expires unless lifted
     *   - GMT mysql datetime in future   — expires automatically on next
     *                                      is_user_banned() call past
     *                                      that timestamp
     *
     * Anything else (past timestamp, malformed string, empty) is rejected
     * with a WP_Error and writes nothing — refusing silently is the
     * worst possible outcome here because a misconfigured admin form
     * would result in a phantom "ban placed" toast with no enforcement.
     *
     * @param int    $user_id The member to ban.
     * @param string $until   'permanent' OR a strtotime-parseable GMT
     *                        timestamp in the future.
     * @param string $reason  Free-form reason string, truncated to 255
     *                        chars to fit comfortably in any audit
     *                        export and to bound the row's storage cost.
     * @param array  $context Optional structured context (e.g.
     *                        ['source' => 'report', 'report_id' => 42])
     *                        passed through to the action verbatim so
     *                        subscribers can attribute the ban without
     *                        a back-reference query.
     * @return true|WP_Error
     */
    public static function ban_user($user_id, $until, $reason = '', array $context = []) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return new WP_Error(
                'matrix_messaging_invalid_user',
                __('Invalid user.', 'matrix-mlm')
            );
        }
        $until = (string) $until;
        if ($until !== 'permanent') {
            $expires_ts = strtotime($until . ' UTC');
            if ($expires_ts === false || $expires_ts <= time()) {
                return new WP_Error(
                    'matrix_messaging_invalid_until',
                    __('Ban expiry must be in the future, or "permanent".', 'matrix-mlm')
                );
            }
            // Re-canonicalise to GMT mysql format so a caller passing
            // a slightly different string ("2030-01-02T03:04:05Z",
            // "tomorrow noon", etc.) is normalised in one place.
            $until = gmdate('Y-m-d H:i:s', $expires_ts);
        }
        $reason = mb_substr((string) $reason, 0, 255);

        update_user_meta($user_id, self::BAN_META_UNTIL,  $until);
        update_user_meta($user_id, self::BAN_META_REASON, $reason);
        update_user_meta($user_id, self::BAN_META_AT,     current_time('mysql', true));
        update_user_meta($user_id, self::BAN_META_BY,     (int) get_current_user_id());

        /**
         * Fires after a messaging ban is committed. Lets audit-log /
         * notification / Slack-ping subscribers react without polling
         * wp_usermeta, and lets a downstream report-resolver attribute
         * the ban to a specific moderation row via the $context array.
         *
         * @param int    $user_id Banned member's user id.
         * @param string $until   'permanent' or a GMT mysql datetime string.
         * @param string $reason  Truncated reason string (<= 255 chars).
         * @param array  $context Free-form context handed in by the caller.
         */
        do_action('matrix_messaging_user_banned', $user_id, $until, $reason, $context);

        return true;
    }

    /**
     * Lift a messaging ban. Mirror of ban_user() — clears every
     * meta key the helper writes, in lockstep, so a partial-cleanup
     * race can't leave a stale BAN_META_AT pointing to a user who
     * is no longer banned.
     *
     * Always returns true for nonexistent / never-banned users so
     * a defensive caller can call this idempotently without first
     * checking is_user_banned().
     *
     * @param int   $user_id The member to unban.
     * @param array $context Optional context for the action subscribers.
     * @return true|WP_Error
     */
    public static function unban_user($user_id, array $context = []) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return new WP_Error(
                'matrix_messaging_invalid_user',
                __('Invalid user.', 'matrix-mlm')
            );
        }
        delete_user_meta($user_id, self::BAN_META_UNTIL);
        delete_user_meta($user_id, self::BAN_META_REASON);
        delete_user_meta($user_id, self::BAN_META_AT);
        delete_user_meta($user_id, self::BAN_META_BY);

        /**
         * Fires after a messaging ban is lifted. Counterpart to
         * matrix_messaging_user_banned — same shape minus the
         * (until, reason) pair which no longer apply.
         *
         * @param int   $user_id Unbanned member's user id.
         * @param array $context Free-form context.
         */
        do_action('matrix_messaging_user_unbanned', $user_id, $context);

        return true;
    }

    /**
     * Count of open (unresolved) message reports. Cheap — uses the
     * `resolved_at` index added in DB 1.0.19. Called by the admin
     * menu builder to render the awaiting-mod badge next to the
     * Messaging submenu label, mirroring the WP core comments
     * awaiting-mod count pattern. Wrap-cached at the call site
     * via a short transient when needed; this method itself is
     * uncached so a fresh report shows on the next admin page load.
     *
     * @return int
     */
    public static function count_open_reports() {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}matrix_message_reports WHERE resolved_at IS NULL"
        );
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
     *
     * Sticky self-leave (DB 1.0.22): if the existing row was removed by
     * the user themselves (removed_by == user_id), DO NOT reactivate. A
     * member who clicked "Leave thread" stays out — self-heal would
     * otherwise fight them on every dashboard load. Admin / sponsor
     * removals (where removed_by is null or differs from user_id) still
     * reactivate, preserving the historical reparenting behaviour.
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
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, removed_at, removed_by FROM $participants_t
              WHERE thread_id = %d AND user_id = %d",
            $thread_id, $user_id
        ));
        if ($existing) {
            // Sticky self-leave check first. A user whose removed_by
            // equals their own user_id deliberately walked out; we
            // refuse to re-admit them via the sponsor-graph walk.
            // Returning true (not false) because the function's
            // contract is "ensure the membership state is correct
            // for this pair" — sticky-out is a correct state.
            if (!empty($existing->removed_at) && (int) $existing->removed_by === $user_id) {
                return true;
            }
            // Reactivate if previously removed by anyone else
            // (admin reparenting, sponsor-initiated removal in a
            // future PR, NULL legacy rows).
            if (!empty($existing->removed_at)) {
                $wpdb->update($participants_t,
                    ['removed_at' => null, 'removed_by' => null],
                    ['id' => (int) $existing->id]
                );
            }
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
            'removed_by'   => null,
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
     *
     * Multi-attachment (DB 1.0.23): the canonical input is
     * $attachment_ids (array of WP attachment post ids in display
     * order). Legacy single-attachment callers can still pass an
     * int (or null) to $attachment_ids; it's coerced to a
     * one-element array. The first validated id is also written
     * to matrix_messages.attachment_id so legacy readers keep
     * working without a backfill.
     *
     * Voice notes (DB 1.0.25): $attachment_meta is an optional
     * keyed array shaped [attachment_id => ['peaks' => [...]]]
     * that the recorder UI uses to ship pre-computed waveform
     * peaks. Server-side does NOT trust client-supplied
     * duration; it re-probes via wp_read_audio_metadata. Any
     * unrecognised attachment_meta keys are ignored, so older
     * callers that pass [] (or omit the argument) keep working.
     */
    public static function send_message($thread_id, $sender_id, $body, $attachment_ids = null, $attachment_meta = []) {
        global $wpdb;
        $thread_id  = (int) $thread_id;
        $sender_id  = (int) $sender_id;
        $body       = trim((string) $body);

        // Coerce legacy single-int callers to the array shape so
        // the rest of the function only branches once. NULL stays
        // NULL (= "no attachments"), 0 / "" fall through to NULL,
        // a real int becomes [int].
        if ($attachment_ids === null || $attachment_ids === '' || $attachment_ids === 0 || $attachment_ids === '0') {
            $attachment_ids = [];
        } elseif (!is_array($attachment_ids)) {
            $attachment_ids = [(int) $attachment_ids];
        }

        if (!self::is_messaging_enabled()) {
            return new WP_Error('matrix_messaging_disabled', __('Messaging is currently disabled.', 'matrix-mlm'));
        }
        if ($thread_id <= 0 || $sender_id <= 0) {
            return new WP_Error('matrix_messaging_bad_args', __('Invalid arguments.', 'matrix-mlm'));
        }
        if ($body === '' && empty($attachment_ids)) {
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

        // Per-attachment validation. Each id must be a real WP
        // attachment owned by the sender; mismatches are dropped
        // silently rather than failing the whole send because
        // (a) failing would let an attacker probe attachment ids
        // by error message and (b) the typical mismatch in the
        // wild is a stale composer state, not a malicious one.
        //
        // Cap the array length BEFORE validation so a payload of
        // 1000 ids doesn't fan into 1000 get_post() calls before
        // we refuse.
        //
        // 1.0.25 — voice classification. classify_attachment()
        // returns 'image' or 'voice' for accepted ids and
        // WP_Error otherwise. Image-or-voice acceptance means
        // pre-1.0.25 image-only callers see no behaviour change;
        // voice attachments additionally run the duration probe
        // and the per-attachment relocate-to-private-subtree
        // step. A voice attachment that fails the duration cap
        // is REJECTED (return WP_Error) rather than silently
        // skipped — the recorder UI in PR 2 wants a useful
        // error code to surface to the sender, and a "your
        // 6-minute voice note silently became a 0-attachment
        // text message" UX is worse than a 400.
        $max_attachments = self::get_max_attachments_per_message();
        $validated_attachment_ids = [];
        $validated_attachment_meta = []; // attachment_id => ['kind', 'duration_ms', 'peaks']
        $voice_max_duration_ms = self::get_voice_max_duration_seconds() * 1000;
        $voice_max_bytes       = self::get_voice_max_bytes();
        $voice_enabled         = self::is_voice_enabled();
        if (!empty($attachment_ids) && !empty($settings['allow_attachments'])) {
            // Normalize, dedupe, drop zeroes.
            $normalized = [];
            foreach ($attachment_ids as $aid) {
                $aid = (int) $aid;
                if ($aid > 0 && !in_array($aid, $normalized, true)) {
                    $normalized[] = $aid;
                }
            }
            // Hard cap at MAX_ATTACHMENTS_PER_MESSAGE_HARD even if
            // an operator filter widens the runtime cap above it.
            $cap = min($max_attachments, self::MAX_ATTACHMENTS_PER_MESSAGE_HARD);
            if (count($normalized) > $cap) {
                $normalized = array_slice($normalized, 0, $cap);
            }
            // Optional voice meta (waveform peaks) handed in by
            // the recorder UI. Shape: [attachment_id => peaks[]].
            // Sender-supplied peaks are advisory — the duration
            // is re-probed server-side regardless. Out-of-shape
            // entries are dropped silently.
            $client_voice_meta = isset($attachment_meta) && is_array($attachment_meta)
                ? $attachment_meta
                : [];
            foreach ($normalized as $aid) {
                $att_post = get_post($aid);
                if (!$att_post || $att_post->post_type !== 'attachment') {
                    continue;
                }
                if ((int) $att_post->post_author !== $sender_id) {
                    continue;
                }
                $classification = self::classify_attachment($aid);
                if (is_wp_error($classification)) {
                    // Voice-shaped failure (415) is loud — the
                    // sender explicitly recorded audio, the file
                    // arrived through the upload pipeline, and
                    // we refuse to send it. Image-or-other
                    // failures stay silent for backward compat.
                    if ($classification->get_error_code() === 'matrix_messaging_voice_unsupported_mime') {
                        return $classification;
                    }
                    continue;
                }
                $kind = $classification['kind'];
                $meta = [
                    'kind'        => $kind,
                    'duration_ms' => null,
                    'peaks'       => null,
                ];
                if ($kind === 'voice') {
                    if (!$voice_enabled) {
                        return new WP_Error(
                            'matrix_messaging_voice_disabled',
                            __('Voice notes are currently disabled on this site.', 'matrix-mlm'),
                            ['status' => 403]
                        );
                    }
                    // Byte cap (server-side authority — the JS
                    // pre-flights this too but a hand-crafted
                    // POST has no UI between it and us).
                    $abs = get_attached_file($aid);
                    $bytes = $abs && file_exists($abs) ? (int) filesize($abs) : 0;
                    if ($bytes <= 0 || $bytes > $voice_max_bytes) {
                        // Unlink the file: it is no longer
                        // referenced by any matrix_messages row
                        // and leaving it on disk would leak
                        // member audio for nothing in return.
                        if ($abs && file_exists($abs)) {
                            @unlink($abs);
                        }
                        wp_delete_attachment($aid, true);
                        return new WP_Error(
                            'matrix_messaging_voice_too_large',
                            sprintf(
                                /* translators: %d: byte cap in MB. */
                                __('Voice note too large. Limit is %d MB.', 'matrix-mlm'),
                                (int) ceil($voice_max_bytes / (1024 * 1024))
                            ),
                            ['status' => 413]
                        );
                    }
                    $duration_ms = self::probe_voice_duration_ms($aid);
                    if ($duration_ms <= 0 || $duration_ms > $voice_max_duration_ms) {
                        if ($abs && file_exists($abs)) {
                            @unlink($abs);
                        }
                        wp_delete_attachment($aid, true);
                        return new WP_Error(
                            'matrix_messaging_voice_too_long',
                            sprintf(
                                /* translators: %d: duration cap in seconds. */
                                __('Voice note too long. Limit is %d seconds.', 'matrix-mlm'),
                                (int) self::get_voice_max_duration_seconds()
                            ),
                            ['status' => 413]
                        );
                    }
                    $meta['duration_ms'] = $duration_ms;
                    // Relocate into the private voice-notes
                    // subtree before we point a message row at
                    // it. If relocate fails we refuse the send
                    // rather than letting a voice file land in
                    // the public year/month uploads dir.
                    if (!self::relocate_voice_attachment($aid)) {
                        wp_delete_attachment($aid, true);
                        return new WP_Error(
                            'matrix_messaging_voice_storage_error',
                            __('Could not stage voice note. Please try again.', 'matrix-mlm'),
                            ['status' => 500]
                        );
                    }
                    // Sanitise client-supplied waveform peaks:
                    // numeric array, length-bounded, normalised
                    // to [0..1] floats. Anything malformed is
                    // dropped (renders as a flat bar).
                    if (isset($client_voice_meta[$aid]['peaks'])
                        && is_array($client_voice_meta[$aid]['peaks'])) {
                        $peaks_in = $client_voice_meta[$aid]['peaks'];
                        $peaks_out = [];
                        $cap_peaks = self::VOICE_WAVEFORM_PEAKS;
                        foreach ($peaks_in as $p) {
                            $f = (float) $p;
                            if ($f < 0) { $f = 0.0; }
                            if ($f > 1) { $f = 1.0; }
                            $peaks_out[] = round($f, 4);
                            if (count($peaks_out) >= $cap_peaks) {
                                break;
                            }
                        }
                        if (!empty($peaks_out)) {
                            $meta['peaks'] = $peaks_out;
                        }
                    }
                }
                $validated_attachment_ids[] = $aid;
                $validated_attachment_meta[$aid] = $meta;
            }
        }

        // Body still required if every attachment failed validation.
        // Without this, a malformed multi-upload pass could land an
        // empty-body row in storage.
        if ($body === '' && empty($validated_attachment_ids)) {
            return new WP_Error('matrix_messaging_empty', __('Message body required.', 'matrix-mlm'));
        }

        // Legacy single-attachment column gets the first validated
        // id. NULL when there are no attachments. Reads prefer the
        // new matrix_message_attachments rows when present and
        // fall back to this column otherwise.
        $legacy_attachment_id = !empty($validated_attachment_ids) ? (int) $validated_attachment_ids[0] : null;

        $now = current_time('mysql', true);
        $messages_t    = $wpdb->prefix . 'matrix_messages';
        $threads_t     = $wpdb->prefix . 'matrix_message_threads';
        $attachments_t = $wpdb->prefix . 'matrix_message_attachments';

        $wpdb->insert($messages_t, [
            'thread_id'     => $thread_id,
            'sender_id'     => $sender_id,
            'body'          => $body,
            'body_stripped' => $body_stripped,
            'attachment_id' => $legacy_attachment_id,
            'created_at'    => $now,
            'deleted_at'    => null,
            'deleted_by'    => null,
        ]);
        $message_id = (int) $wpdb->insert_id;
        if (!$message_id) {
            return new WP_Error('matrix_messaging_db_error', __('Could not store message.', 'matrix-mlm'));
        }

        // Persist every validated attachment to the new table —
        // including the first one, which is intentionally also
        // present in the legacy attachment_id column. This keeps
        // hydrate_message_rows on a single code path: read the
        // new table; only fall back to the legacy column for
        // pre-1.0.23 messages that have no rows here.
        //
        // 1.0.25 — kind/duration_ms/waveform_peaks_json carry the
        // voice-note metadata. Image rows leave duration and
        // peaks NULL; voice rows have both.
        foreach ($validated_attachment_ids as $position => $aid) {
            $att_meta = isset($validated_attachment_meta[$aid])
                ? $validated_attachment_meta[$aid]
                : ['kind' => 'image', 'duration_ms' => null, 'peaks' => null];
            $row = [
                'message_id'    => $message_id,
                'attachment_id' => (int) $aid,
                'position'      => (int) $position,
                'kind'          => (string) $att_meta['kind'],
                'duration_ms'   => isset($att_meta['duration_ms']) ? (int) $att_meta['duration_ms'] : null,
                'waveform_peaks_json' => !empty($att_meta['peaks']) ? wp_json_encode($att_meta['peaks']) : null,
                'created_at'    => $now,
            ];
            $wpdb->insert($attachments_t, $row);
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
                $legacy_attachment_id
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

    /**
     * Effective per-message attachment cap. Reads the operator
     * setting, applies a sane floor (1) and ceiling
     * (MAX_ATTACHMENTS_PER_MESSAGE_HARD), then runs the operator
     * filter for callers who want to override at code level
     * without touching the option blob.
     */
    public static function get_max_attachments_per_message() {
        $settings = self::get_settings();
        $val = isset($settings['max_attachments_per_message'])
            ? (int) $settings['max_attachments_per_message']
            : self::MAX_ATTACHMENTS_PER_MESSAGE_DEFAULT;
        if ($val < 1) {
            $val = 1;
        }
        if ($val > self::MAX_ATTACHMENTS_PER_MESSAGE_HARD) {
            $val = self::MAX_ATTACHMENTS_PER_MESSAGE_HARD;
        }
        $filtered = (int) apply_filters('matrix_messaging_max_attachments_per_message', $val);
        if ($filtered < 1) {
            $filtered = 1;
        }
        if ($filtered > self::MAX_ATTACHMENTS_PER_MESSAGE_HARD) {
            $filtered = self::MAX_ATTACHMENTS_PER_MESSAGE_HARD;
        }
        return $filtered;
    }

    // ---------------------------------------------------------------
    // Voice-note resolvers (DB 1.0.25)
    // ---------------------------------------------------------------

    /**
     * Are voice notes enabled on this install?
     *
     * Distinct from `is_messaging_enabled()` — voice can be turned
     * off without disabling text + image messaging. Operators
     * needing a quick-disable during a moderation incident set
     * this without uninstalling anything.
     */
    public static function is_voice_enabled() {
        $s = self::get_settings();
        return !empty($s['allow_voice_notes']);
    }

    /**
     * The audio MIME types we accept for voice notes.
     *
     * Two layers of defence:
     *   1. The static VOICE_ALLOWED_MIME constant is the
     *      operator-visible default (kept in code so it travels
     *      with the plugin, not buried in an option row).
     *   2. The matrix_messaging_voice_allowed_mime filter lets a
     *      regional install accept an extra codec that the
     *      base whitelist doesn't list — but the resolver
     *      intersects with wp_get_mime_types() so a typo'd
     *      filter cannot widen WP's own upload allow-list.
     */
    public static function voice_allowed_mime() {
        $defaults = self::VOICE_ALLOWED_MIME;
        $filtered = apply_filters('matrix_messaging_voice_allowed_mime', $defaults);
        if (!is_array($filtered) || empty($filtered)) {
            $filtered = $defaults;
        }
        // Intersect with WP's own mime allow-list. wp_get_mime_types
        // returns ext-pattern => mime; we project to the unique set
        // of mime values for the membership check.
        $wp_mimes = function_exists('wp_get_mime_types') ? array_values(wp_get_mime_types()) : [];
        $out = [];
        foreach ($filtered as $mime) {
            $mime = strtolower(trim((string) $mime));
            if ($mime === '' || strpos($mime, 'audio/') !== 0) {
                continue;
            }
            if (!empty($wp_mimes) && !in_array($mime, $wp_mimes, true)) {
                // Filter tried to add a mime WP itself does not
                // accept on upload — silently drop it. Belt &
                // braces against a typo'd or hostile add_filter.
                continue;
            }
            if (!in_array($mime, $out, true)) {
                $out[] = $mime;
            }
        }
        // Safety net: if intersection ate every entry (e.g. WP's
        // wp_get_mime_types is filtered to an empty list elsewhere
        // in the install), return the static defaults — the
        // intersection is defence-in-depth, not the only gate.
        return empty($out) ? $defaults : $out;
    }

    /**
     * Effective voice-note duration cap in seconds. Same
     * floor/ceiling shape as get_max_attachments_per_message.
     */
    public static function get_voice_max_duration_seconds() {
        $s = self::get_settings();
        $val = isset($s['voice_max_duration_seconds'])
            ? (int) $s['voice_max_duration_seconds']
            : self::VOICE_MAX_DURATION_SECONDS_DEFAULT;
        if ($val < 5) {
            $val = 5; // Floor — recording for less than 5s is functionally pointless.
        }
        if ($val > self::VOICE_MAX_DURATION_SECONDS_HARD) {
            $val = self::VOICE_MAX_DURATION_SECONDS_HARD;
        }
        $filtered = (int) apply_filters('matrix_messaging_voice_max_duration_seconds', $val);
        if ($filtered < 5) {
            $filtered = 5;
        }
        if ($filtered > self::VOICE_MAX_DURATION_SECONDS_HARD) {
            $filtered = self::VOICE_MAX_DURATION_SECONDS_HARD;
        }
        return $filtered;
    }

    /**
     * Effective voice-note byte cap. Hard ceiling is the existing
     * `max_attachment_bytes` (per-attachment image cap) — voice
     * cannot ever be larger than what a single image upload is
     * allowed to be. This means the global per-attachment knob
     * is the absolute upper bound and the voice knob can only
     * tighten it.
     */
    public static function get_voice_max_bytes() {
        $s = self::get_settings();
        $hard = isset($s['max_attachment_bytes'])
            ? (int) $s['max_attachment_bytes']
            : 5 * 1024 * 1024;
        if ($hard < 1) {
            $hard = 5 * 1024 * 1024;
        }
        $val = isset($s['voice_max_bytes'])
            ? (int) $s['voice_max_bytes']
            : self::VOICE_MAX_BYTES_DEFAULT;
        if ($val < 1) {
            $val = self::VOICE_MAX_BYTES_DEFAULT;
        }
        if ($val > $hard) {
            $val = $hard;
        }
        $filtered = (int) apply_filters('matrix_messaging_voice_max_bytes', $val);
        if ($filtered < 1) {
            $filtered = self::VOICE_MAX_BYTES_DEFAULT;
        }
        if ($filtered > $hard) {
            $filtered = $hard;
        }
        return $filtered;
    }

    /**
     * Classify a WP attachment for messaging purposes.
     *
     * Returns:
     *   ['kind' => 'image'|'voice', 'mime' => '<mime>']      on accept
     *   WP_Error                                              on reject
     *
     * Only callers who want a hard reject on a non-image,
     * non-voice attachment branch on the WP_Error path; the
     * existing send loop in send_message() drops silently for
     * legacy compatibility (an attachment that doesn't classify
     * as image OR voice is just skipped, mirroring the
     * pre-1.0.25 behaviour for unsupported attachments).
     */
    public static function classify_attachment($attachment_id) {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) {
            return new WP_Error(
                'matrix_messaging_attachment_invalid',
                __('Invalid attachment.', 'matrix-mlm')
            );
        }
        $mime = (string) get_post_mime_type($attachment_id);
        if ($mime === '') {
            return new WP_Error(
                'matrix_messaging_attachment_unknown',
                __('Attachment has no detectable type.', 'matrix-mlm')
            );
        }
        $mime = strtolower($mime);
        if (strpos($mime, 'image/') === 0) {
            return ['kind' => 'image', 'mime' => $mime];
        }
        if (in_array($mime, self::voice_allowed_mime(), true)) {
            return ['kind' => 'voice', 'mime' => $mime];
        }
        return new WP_Error(
            'matrix_messaging_voice_unsupported_mime',
            __('Unsupported voice-note format.', 'matrix-mlm'),
            ['status' => 415, 'mime' => $mime]
        );
    }

    /**
     * Probe an audio attachment's duration in milliseconds.
     *
     * Cached in postmeta on first probe so re-renders never re-
     * read the file from disk. Returns 0 on probe failure — the
     * caller treats 0 as "duration unknown, refuse the send"
     * because a duration we cannot measure is a duration we
     * cannot cap.
     */
    public static function probe_voice_duration_ms($attachment_id) {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) {
            return 0;
        }
        $cached = (int) get_post_meta($attachment_id, '_matrix_messaging_voice_duration_ms', true);
        if ($cached > 0) {
            return $cached;
        }
        $path = get_attached_file($attachment_id);
        if (!$path || !file_exists($path)) {
            return 0;
        }
        if (!function_exists('wp_read_audio_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }
        if (!function_exists('wp_read_audio_metadata')) {
            // Some lean WP installs do not load wp-admin/media.php
            // outside the admin context. Falling back to 0 is the
            // safe choice — the caller refuses the send rather
            // than admitting an unmeasurable file.
            return 0;
        }
        $meta = wp_read_audio_metadata($path);
        if (!is_array($meta) || empty($meta['length'])) {
            return 0;
        }
        $ms = (int) round((float) $meta['length'] * 1000);
        if ($ms > 0) {
            update_post_meta($attachment_id, '_matrix_messaging_voice_duration_ms', $ms);
        }
        return $ms;
    }

    /**
     * Move a freshly-uploaded voice attachment into the private
     * voice-notes subtree if it isn't already there. Idempotent.
     *
     * Why move-on-classify instead of upload_dir-on-upload:
     * voice files are uploaded through the standard WP media
     * pipeline (async-upload.php → wp_handle_upload) which
     * doesn't expose a per-call upload_dir filter the way the
     * loan-uploads code path does (loans wrap their own
     * wp_handle_upload call, voice does not). Doing the move
     * here, at message-send time, keeps the model layer
     * authoritative on subtree placement and means a hand-
     * crafted send AJAX with a stray-uploaded audio file lands
     * the file in the right place anyway.
     *
     * The move:
     *   1. Resolves on-disk source path + url from the WP
     *      attachment meta.
     *   2. Computes a destination under
     *      uploads/matrix-messaging-voice/<year>/<month>/.
     *   3. Renames on disk, updates _wp_attached_file, updates
     *      the GUID via wp_update_post.
     *   4. Drops .htaccess + web.config deny rules on first
     *      creation of the subtree (mirrors the loan-uploads
     *      pattern from Matrix_MLM_User_Loan::ensure_upload_guards).
     *
     * Returns true on success or no-op (already in the subtree),
     * false on a fixable failure (e.g. permission denied — the
     * caller rejects the send), so the send path can decide
     * whether to admit the message or 500 on the upload.
     */
    public static function relocate_voice_attachment($attachment_id) {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) {
            return false;
        }
        $source_abs = get_attached_file($attachment_id);
        if (!$source_abs || !file_exists($source_abs)) {
            return false;
        }

        $upload = wp_upload_dir();
        if (!is_array($upload) || empty($upload['basedir']) || empty($upload['baseurl'])) {
            return false;
        }
        $basedir = (string) $upload['basedir'];
        $baseurl = (string) $upload['baseurl'];

        // Already in the subtree? Idempotent no-op. We compare on
        // the relative path so a basedir with a trailing slash or
        // a symlinked uploads dir doesn't trip us up.
        $relative_attached = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
        if ($relative_attached !== ''
            && strpos('/' . ltrim($relative_attached, '/'), self::VOICE_UPLOAD_SUBTREE) === 0) {
            // Still ensure the deny guards exist — first-time
            // operators may have a leftover folder from a
            // pre-PR test that needs the guards backfilled.
            self::ensure_voice_upload_guards(dirname($source_abs));
            return true;
        }

        // Compute target path:
        //   <basedir>/matrix-messaging-voice/<year>/<month>/<filename>
        $year   = date('Y');
        $month  = date('m');
        $sub    = rtrim(self::VOICE_UPLOAD_SUBTREE, '/') . '/' . $year . '/' . $month;
        $dest_dir_abs = $basedir . $sub;
        if (!wp_mkdir_p($dest_dir_abs)) {
            return false;
        }

        $filename     = wp_basename($source_abs);
        $dest_abs     = trailingslashit($dest_dir_abs) . $filename;
        $dest_relative = ltrim($sub . '/' . $filename, '/');

        // Avoid clobbering an existing file at the destination —
        // use wp_unique_filename to disambiguate.
        if (file_exists($dest_abs)) {
            $unique   = wp_unique_filename($dest_dir_abs, $filename);
            $dest_abs = trailingslashit($dest_dir_abs) . $unique;
            $dest_relative = ltrim($sub . '/' . $unique, '/');
        }

        if (!@rename($source_abs, $dest_abs)) {
            // Cross-filesystem rename can fail; fall back to
            // copy+unlink. wp_filesystem would be the textbook
            // move here, but it requires wp_admin/file.php and
            // a credential probe that does not run on a regular
            // member request. Direct PHP filesystem calls match
            // the rest of this plugin's upload path.
            if (!@copy($source_abs, $dest_abs)) {
                return false;
            }
            @unlink($source_abs);
        }

        // Update the attachment meta + guid so wp_get_attachment_url
        // and downstream signed URLs resolve to the new path.
        update_post_meta($attachment_id, '_wp_attached_file', $dest_relative);
        $new_url = trailingslashit($baseurl) . $dest_relative;
        wp_update_post([
            'ID'   => $attachment_id,
            'guid' => $new_url,
        ]);

        // Drop the deny guards on the destination dir on first
        // creation. Idempotent — the helper only writes if the
        // marker is absent.
        self::ensure_voice_upload_guards($dest_dir_abs);

        return true;
    }

    /**
     * Drop deny-direct-access config files in the voice-notes
     * upload subtree on first creation. Mirrors the v2 guards
     * Matrix_MLM_User_Loan plants in /matrix-loan-files/ — the
     * shape and marker are kept identical so an audit can grep
     * the tree for a single sentinel and find every protected
     * subdirectory.
     *
     * On Apache and IIS these files take effect automatically.
     * On Nginx the operator has to splice an equivalent
     * `location ~ ^/wp-content/uploads/matrix-messaging-voice/` deny
     * block into their server config; the README + steering
     * note (PR 4) document this.
     */
    private static function ensure_voice_upload_guards($dir) {
        if (!is_string($dir) || $dir === '' || !is_dir($dir)) {
            return;
        }
        $marker = 'MatrixPro guard v2';

        $htaccess = trailingslashit($dir) . '.htaccess';
        $needs_write = !file_exists($htaccess);
        if (!$needs_write) {
            $existing = @file_get_contents($htaccess);
            if (!is_string($existing) || strpos($existing, $marker) === false) {
                $needs_write = true;
            }
        }
        if ($needs_write) {
            $rules = "# {$marker}\n"
                   . "# Messaging voice notes. Direct HTTP access is denied —\n"
                   . "# member voice files are streamed via the signed-URL\n"
                   . "# endpoint at /wp-json/matrix-mlm/v1/attachment which\n"
                   . "# validates HMAC + expiry + per-thread participation\n"
                   . "# before serving the audio. The deny-PHP-execution\n"
                   . "# rule is kept as defense-in-depth in case a future\n"
                   . "# config change re-enables direct access.\n"
                   . "<FilesMatch \"\\.(php|phtml|php3|php4|php5|php6|php7|phar|pht)$\">\n"
                   . "    Require all denied\n"
                   . "</FilesMatch>\n"
                   . "<IfModule mod_php.c>\n"
                   . "    php_flag engine off\n"
                   . "</IfModule>\n"
                   . "RemoveHandler .php .phtml .phar\n"
                   . "RemoveType .php .phtml .phar\n"
                   . "<IfModule mod_authz_core.c>\n"
                   . "    Require all denied\n"
                   . "</IfModule>\n"
                   . "<IfModule !mod_authz_core.c>\n"
                   . "    Order deny,allow\n"
                   . "    Deny from all\n"
                   . "</IfModule>\n";
            @file_put_contents($htaccess, $rules);
        }

        $webconfig = trailingslashit($dir) . 'web.config';
        $needs_write_wc = !file_exists($webconfig);
        if (!$needs_write_wc) {
            $existing_wc = @file_get_contents($webconfig);
            if (!is_string($existing_wc) || strpos($existing_wc, $marker) === false) {
                $needs_write_wc = true;
            }
        }
        if ($needs_write_wc) {
            $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                 . "<!-- {$marker} -->\n"
                 . "<configuration>\n"
                 . "  <system.webServer>\n"
                 . "    <handlers accessPolicy=\"Read\" />\n"
                 . "    <security>\n"
                 . "      <requestFiltering>\n"
                 . "        <fileExtensions allowUnlisted=\"true\">\n"
                 . "          <add fileExtension=\".php\" allowed=\"false\" />\n"
                 . "          <add fileExtension=\".phtml\" allowed=\"false\" />\n"
                 . "          <add fileExtension=\".phar\" allowed=\"false\" />\n"
                 . "        </fileExtensions>\n"
                 . "      </requestFiltering>\n"
                 . "      <authorization>\n"
                 . "        <remove users=\"*\" roles=\"\" verbs=\"\" />\n"
                 . "        <deny users=\"*\" />\n"
                 . "      </authorization>\n"
                 . "    </security>\n"
                 . "  </system.webServer>\n"
                 . "</configuration>\n";
            @file_put_contents($webconfig, $xml);
        }
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
            "SELECT id, thread_id, sender_id, body, body_stripped, attachment_id, created_at, edited_at, deleted_at
             FROM {$wpdb->prefix}matrix_messages
             WHERE thread_id = %d AND id > %d
             ORDER BY id ASC
             LIMIT %d",
            (int) $thread_id, (int) $after_id, $limit
        ));

        self::hydrate_message_rows($rows);
        return $rows;
    }

    /**
     * Fetch the page of messages in $thread_id whose id is strictly
     * less than $before_id, newest-first inside the page (the JS
     * reverses on render so the natural top-to-bottom ordering of
     * the message column is preserved).
     *
     * Drives "Load older messages" — once the user scrolls to the
     * top of the initial 50-message window the client passes the
     * smallest already-rendered id and we hand back the next 50
     * back in time.
     *
     * before_id == 0 is a special "no anchor" sentinel that returns
     * an empty page; the caller should pass the actual oldest
     * rendered id rather than relying on this.
     */
    public static function get_thread_messages_before($thread_id, $user_id, $before_id, $limit = 50) {
        global $wpdb;
        if (!self::user_can_view_thread($thread_id, $user_id)) {
            return [];
        }
        $before_id = (int) $before_id;
        if ($before_id <= 0) {
            return [];
        }
        $limit = max(1, min(200, (int) $limit));
        // ORDER BY id DESC + LIMIT lets MySQL stop after $limit rows
        // using the (thread_id, id) covering index; a forward-order
        // scan would have to read every older row in the thread to
        // pick the trailing N.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, thread_id, sender_id, body, body_stripped, attachment_id, created_at, edited_at, deleted_at
             FROM {$wpdb->prefix}matrix_messages
             WHERE thread_id = %d AND id < %d
             ORDER BY id DESC
             LIMIT %d",
            (int) $thread_id, $before_id, $limit
        ));

        // Re-order ascending for the wire so the client doesn't have
        // to reverse — keeps the polling and pagination response
        // shapes identical.
        if (!empty($rows)) {
            $rows = array_reverse($rows);
        }
        self::hydrate_message_rows($rows);
        return $rows;
    }

    /**
     * Decorate raw message rows with derived fields the UI needs:
     *
     *   - attachment_url: a signed/sized URL for the inline image,
     *     or null if no attachment / row is soft-deleted.
     *   - is_deleted: 1 if the row has been soft-deleted (by
     *     sender or moderator).
     *   - is_edited: 1 if edited_at is set.
     *   - editable_until: ISO-style mysql GMT timestamp marking
     *     when the sender's edit window ends (created_at + window).
     *     UI uses this to render edit / delete affordances and
     *     to hide them after expiry without a server round-trip.
     *
     * Computed in PHP rather than SQL so the same code path serves
     * both the forward-poll fetch and the back-pagination fetch
     * with one source of truth, and so future changes to the
     * editable_until policy (e.g. role-based windows) only need
     * to touch one helper.
     */
    private static function hydrate_message_rows($rows) {
        if (empty($rows)) {
            return;
        }
        $window = self::get_edit_window_seconds();

        // Multi-attachment hydration (DB 1.0.23). Pull every
        // matrix_message_attachments row whose message_id is in
        // the visible page in one indexed query, then group in
        // PHP. Falls back to the legacy attachment_id column for
        // any pre-1.0.23 message that has no rows in the new
        // table — we explicitly do NOT lazy-migrate on read,
        // because a write-on-read pattern would create surprising
        // contention on a busy thread (every reader racing to
        // backfill the same rows).
        global $wpdb;
        $message_ids = [];
        foreach ($rows as $r) {
            $mid = (int) $r->id;
            if ($mid > 0) {
                $message_ids[$mid] = $mid;
            }
        }
        $attachments_by_message = [];
        if (!empty($message_ids)) {
            $placeholders = implode(',', array_fill(0, count($message_ids), '%d'));
            // 1.0.25 — pull the new voice metadata columns
            // alongside id/position. Older rows have NULL on
            // duration_ms / waveform_peaks_json, kind defaults
            // to 'image' (column NOT NULL DEFAULT 'image'), so
            // legacy reads land in the same shape.
            $att_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT message_id, attachment_id, position, kind, duration_ms, waveform_peaks_json
                   FROM {$wpdb->prefix}matrix_message_attachments
                  WHERE message_id IN ($placeholders)
                  ORDER BY message_id ASC, position ASC",
                array_values($message_ids)
            ));
            foreach ((array) $att_rows as $ar) {
                $mid = (int) $ar->message_id;
                if (!isset($attachments_by_message[$mid])) {
                    $attachments_by_message[$mid] = [];
                }
                $kind = isset($ar->kind) ? (string) $ar->kind : 'image';
                if ($kind !== 'voice') {
                    $kind = 'image';
                }
                $attachments_by_message[$mid][] = [
                    'attachment_id' => (int) $ar->attachment_id,
                    'kind'          => $kind,
                    'duration_ms'   => isset($ar->duration_ms) ? (int) $ar->duration_ms : null,
                    'peaks_json'    => isset($ar->waveform_peaks_json) ? (string) $ar->waveform_peaks_json : null,
                ];
            }
        }

        foreach ($rows as $r) {
            // Build the attachments[] array for this row. Prefer
            // the new-table list when present (covers messages
            // sent on or after DB 1.0.23). Otherwise fall back to
            // the legacy single attachment_id column. Either path
            // produces an ordered list of {id, url, kind, ...}
            // objects so the client doesn't branch on storage
            // version.
            $atts_out = [];
            $att_records = [];
            $mid_int  = (int) $r->id;
            if (!empty($attachments_by_message[$mid_int])) {
                $att_records = $attachments_by_message[$mid_int];
            } elseif (!empty($r->attachment_id)) {
                // Legacy single-column path. Pre-1.0.25 only had
                // image attachments, so we synthesize an image
                // record here. (If somehow a voice attachment
                // ended up referenced via the legacy column,
                // its render falls through to the signer-aware
                // branch below via classify_attachment.)
                $att_records = [[
                    'attachment_id' => (int) $r->attachment_id,
                    'kind'          => 'image',
                    'duration_ms'   => null,
                    'peaks_json'    => null,
                ]];
            }

            if (!empty($att_records) && empty($r->deleted_at)) {
                foreach ($att_records as $rec) {
                    $aid = (int) $rec['attachment_id'];
                    if ($aid <= 0) {
                        continue;
                    }
                    $kind = $rec['kind'] === 'voice' ? 'voice' : 'image';
                    if ($kind === 'voice') {
                        // Voice files are gated behind the
                        // attachment-signer. We never link the
                        // raw /uploads/ URL on the wire — the
                        // moderation surface and the recipient
                        // surface both consume the signed URL.
                        // Empty signer return means the file
                        // does not sit under an allowed subtree
                        // (legacy data only); skip it rather
                        // than fall through to the public URL.
                        $public = wp_get_attachment_url($aid);
                        $signed = '';
                        if ($public && class_exists('Matrix_MLM_Attachment_Signer')) {
                            $signed = Matrix_MLM_Attachment_Signer::sign_url_from_public($public);
                        }
                        if (!$signed || $signed === $public) {
                            // Fall-through to public URL would
                            // leak — refuse to render the
                            // attachment instead. The bubble
                            // will look attachment-less to the
                            // client, which is preferable to
                            // shipping an unsigned URL to a
                            // member's voice file.
                            continue;
                        }
                        $peaks = null;
                        if (!empty($rec['peaks_json'])) {
                            $decoded = json_decode($rec['peaks_json'], true);
                            if (is_array($decoded)) {
                                $peaks = $decoded;
                            }
                        }
                        $atts_out[] = (object) [
                            'id'          => $aid,
                            'kind'        => 'voice',
                            'url'         => $signed,
                            // No thumb for audio — the JS
                            // renders a waveform widget keyed
                            // off `kind`, not off thumb_url.
                            'thumb_url'   => null,
                            'duration_ms' => isset($rec['duration_ms']) ? (int) $rec['duration_ms'] : null,
                            'peaks'       => $peaks,
                        ];
                        continue;
                    }
                    // Image branch — unchanged from 1.0.23.
                    $url = wp_get_attachment_url($aid);
                    if (!$url) {
                        continue;
                    }
                    $thumb = wp_get_attachment_image_url($aid, 'medium');
                    $atts_out[] = (object) [
                        'id'        => $aid,
                        'kind'      => 'image',
                        'url'       => $url,
                        'thumb_url' => $thumb ?: $url,
                    ];
                }
            }
            $r->attachments = $atts_out;

            // Legacy single-attachment field. Kept on the wire so
            // any cached / older client copy still renders the
            // first attachment without code changes; new clients
            // read the attachments[] array and ignore this. Voice-
            // first messages set this to NULL — old clients that
            // would have rendered a thumb shouldn't be linking to
            // a voice file at all.
            $first_image = null;
            foreach ($atts_out as $a) {
                if (isset($a->kind) && $a->kind === 'image' && !empty($a->thumb_url)) {
                    $first_image = $a->thumb_url;
                    break;
                }
            }
            $r->attachment_url = $first_image;
            $r->is_deleted = !empty($r->deleted_at) ? 1 : 0;
            $r->is_edited  = !empty($r->edited_at) ? 1 : 0;

            // editable_until = created_at + window, expressed as a
            // mysql GMT string so the JS comparison stays string-
            // ordered (created_at is also GMT-string-shaped, so
            // monotonic comparisons line up without a parse).
            $r->editable_until = null;
            if ($window > 0 && !empty($r->created_at) && empty($r->deleted_at)) {
                $created_ts = strtotime($r->created_at . ' UTC');
                if ($created_ts !== false) {
                    $r->editable_until = gmdate('Y-m-d H:i:s', $created_ts + $window);
                }
            }
        }
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
    // Sender-side edit / self-delete
    //
    // The sender of a message can edit or soft-delete it within
    // EDIT_WINDOW_SECONDS_DEFAULT (default 300s) of insert. Outside
    // the window the row is frozen — preventing a sender from
    // rewriting historical context after a recipient has read and
    // acted on it. The same window gates both edit and delete; see
    // the rationale on EDIT_WINDOW_SECONDS_DEFAULT for why we use a
    // single timer for both.
    //
    // Soft-delete (not hard) — the row stays in matrix_messages
    // with deleted_at populated, mirroring the moderator-delete
    // shape so the UI's "(message deleted)" placeholder works
    // uniformly. cron_cleanup() hard-deletes after 30 days for
    // both delete origins.
    //
    // Off-platform contact stripping is re-applied on edit using
    // the same settings flag as on send. body_stripped is rewritten
    // (set or cleared) so the moderator UI's "stripped" badge
    // reflects the post-edit body, not a stale flag from the
    // original send.
    // ---------------------------------------------------------------

    /**
     * Edit a message body. Caller must be the original sender; row
     * must be inside the edit window and not soft-deleted.
     *
     * Returns true on success, WP_Error on policy / lookup failure.
     * On success, edited_at is stamped to NOW(); body_stripped is
     * recomputed from the new body.
     */
    public static function edit_message($message_id, $sender_id, $new_body) {
        global $wpdb;
        $message_id = (int) $message_id;
        $sender_id  = (int) $sender_id;
        $new_body   = trim((string) $new_body);

        if (!self::is_messaging_enabled()) {
            return new WP_Error('matrix_messaging_disabled', __('Messaging is currently disabled.', 'matrix-mlm'));
        }
        if ($message_id <= 0 || $sender_id <= 0) {
            return new WP_Error('matrix_messaging_bad_args', __('Invalid arguments.', 'matrix-mlm'));
        }
        if ($new_body === '') {
            // Empty body would leave a row that's text-empty and
            // attachment-empty; that's the soft-delete shape, not
            // an edit. Refuse and let the UI route through delete.
            return new WP_Error('matrix_messaging_empty', __('Message body required.', 'matrix-mlm'));
        }
        if (self::is_user_banned($sender_id)) {
            return new WP_Error('matrix_messaging_banned', __('You are banned from messaging.', 'matrix-mlm'));
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, sender_id, thread_id, attachment_id, body, body_stripped, created_at, deleted_at
               FROM {$wpdb->prefix}matrix_messages
              WHERE id = %d",
            $message_id
        ));
        if (!$row) {
            return new WP_Error('matrix_messaging_not_found', __('Message not found.', 'matrix-mlm'));
        }
        if ((int) $row->sender_id !== $sender_id) {
            return new WP_Error('matrix_messaging_forbidden', __('You can only edit your own messages.', 'matrix-mlm'));
        }
        if (!empty($row->deleted_at)) {
            return new WP_Error('matrix_messaging_deleted', __('Cannot edit a deleted message.', 'matrix-mlm'));
        }

        $window = self::get_edit_window_seconds();
        if ($window <= 0) {
            return new WP_Error('matrix_messaging_edit_disabled', __('Editing is disabled.', 'matrix-mlm'));
        }
        $created_ts = strtotime($row->created_at . ' UTC');
        if ($created_ts === false || (time() - $created_ts) > $window) {
            return new WP_Error('matrix_messaging_edit_expired', __('Edit window has expired.', 'matrix-mlm'));
        }

        // Re-apply off-platform stripping on the new body. Same
        // semantics as send_message() — keep the moderator-visible
        // body_stripped flag in sync with what's actually stored.
        $settings      = self::get_settings();
        $body_stripped = 0;
        if (!empty($settings['strip_off_platform_contacts'])) {
            $stripped = self::strip_off_platform_contacts($new_body);
            if ($stripped !== $new_body) {
                $body_stripped = 1;
                $new_body = $stripped;
            }
        }

        $now = current_time('mysql', true);
        $ok  = $wpdb->update(
            $wpdb->prefix . 'matrix_messages',
            [
                'body'          => $new_body,
                'body_stripped' => $body_stripped,
                'edited_at'     => $now,
            ],
            ['id' => $message_id, 'sender_id' => $sender_id]
        );
        if ($ok === false) {
            return new WP_Error('matrix_messaging_db_error', __('Could not save edit.', 'matrix-mlm'));
        }
        return true;
    }

    /**
     * Sender soft-deletes their own message. Same window check as
     * edit; same soft-delete shape as the admin moderator path so
     * the UI's "(message deleted)" placeholder rendering doesn't
     * branch by origin.
     *
     * deleted_by is set to the sender's own user id, distinguishing
     * a self-delete from a moderator delete (where deleted_by is
     * the admin user id) for forensic / audit purposes.
     */
    public static function sender_delete_message($message_id, $sender_id) {
        global $wpdb;
        $message_id = (int) $message_id;
        $sender_id  = (int) $sender_id;

        if (!self::is_messaging_enabled()) {
            return new WP_Error('matrix_messaging_disabled', __('Messaging is currently disabled.', 'matrix-mlm'));
        }
        if ($message_id <= 0 || $sender_id <= 0) {
            return new WP_Error('matrix_messaging_bad_args', __('Invalid arguments.', 'matrix-mlm'));
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, sender_id, thread_id, created_at, deleted_at
               FROM {$wpdb->prefix}matrix_messages
              WHERE id = %d",
            $message_id
        ));
        if (!$row) {
            return new WP_Error('matrix_messaging_not_found', __('Message not found.', 'matrix-mlm'));
        }
        if ((int) $row->sender_id !== $sender_id) {
            return new WP_Error('matrix_messaging_forbidden', __('You can only delete your own messages.', 'matrix-mlm'));
        }
        if (!empty($row->deleted_at)) {
            // Idempotent: a re-delete is a no-op success rather than
            // a confusing error. Keeps the UI's optimistic remove +
            // server canonicalisation contract simple.
            return true;
        }

        $window = self::get_edit_window_seconds();
        if ($window <= 0) {
            return new WP_Error('matrix_messaging_edit_disabled', __('Self-delete is disabled.', 'matrix-mlm'));
        }
        $created_ts = strtotime($row->created_at . ' UTC');
        if ($created_ts === false || (time() - $created_ts) > $window) {
            return new WP_Error('matrix_messaging_edit_expired', __('Delete window has expired.', 'matrix-mlm'));
        }

        $ok = $wpdb->update(
            $wpdb->prefix . 'matrix_messages',
            [
                'deleted_at' => current_time('mysql', true),
                'deleted_by' => $sender_id,
            ],
            ['id' => $message_id, 'sender_id' => $sender_id]
        );
        if ($ok === false) {
            return new WP_Error('matrix_messaging_db_error', __('Could not delete message.', 'matrix-mlm'));
        }
        return true;
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

    /**
     * Has $blocker_id blocked $blocked_id (one direction)?
     *
     * Distinct from is_blocked_either_way() because the UI needs
     * to know "did THIS user block the other party" to pick
     * between "Block" and "Unblock" labels — symmetric checks
     * would conflate "I blocked them" with "they blocked me",
     * which is the wrong control state to surface.
     */
    public static function is_blocking($blocker_id, $blocked_id) {
        global $wpdb;
        $blocker_id = (int) $blocker_id;
        $blocked_id = (int) $blocked_id;
        if ($blocker_id <= 0 || $blocked_id <= 0) {
            return false;
        }
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}matrix_message_blocks
              WHERE blocker_id = %d AND blocked_id = %d
              LIMIT 1",
            $blocker_id, $blocked_id
        ));
    }

    /**
     * Return the calling user's participant pointer for a thread:
     * muted_until + a derived is_muted flag computed in PHP so the
     * JS doesn't have to parse mysql GMT datetimes against its own
     * (potentially skewed) wall clock.
     *
     * Returns a default ['muted_until' => null, 'is_muted' => false]
     * shape for non-participants so the caller can call this
     * unconditionally.
     */
    public static function get_participant_state($thread_id, $user_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT muted_until FROM {$wpdb->prefix}matrix_message_participants
              WHERE thread_id = %d AND user_id = %d AND removed_at IS NULL
              LIMIT 1",
            (int) $thread_id, (int) $user_id
        ));
        if (!$row) {
            return ['muted_until' => null, 'is_muted' => false];
        }
        $is_muted = false;
        if (!empty($row->muted_until)) {
            $until_ts = strtotime($row->muted_until . ' UTC');
            if ($until_ts !== false && $until_ts > time()) {
                $is_muted = true;
            }
        }
        return [
            'muted_until' => $row->muted_until ?: null,
            'is_muted'    => $is_muted,
        ];
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

    /**
     * Self-leave a thread (DB 1.0.22).
     *
     * Soft-removes the caller from the participants table by stamping
     * removed_at + removed_by. Sets removed_by = user_id specifically
     * so add_user_to_team_room()'s sticky check refuses to walk this
     * user back into the same room on the next sponsor-graph self-heal
     * pass — the membership stays out until either the user is
     * explicitly re-invited (sponsor-remove flow, future PR) or an
     * admin clears the removed_by stamp by reparenting them.
     *
     * Scope: team_room threads only. DM threads are not "leavable" by
     * design — there are exactly two participants and either is free
     * to use Block instead, which is the equivalent silencing tool
     * with stronger semantics (mutual silence, not just one-way exit).
     *
     * Group rooms (DB 1.0.24) are also leavable — same model, same
     * sticky semantics, same owner refusal. The only difference is
     * the owner of a group room can't recover the room without an
     * out-of-band re-invite from a member who's still in (and v1
     * doesn't ship sponsor-style recovery), so leaving a group
     * room you own would be a one-way trip; we refuse it for the
     * same reason team_room owners are refused.
     *
     * Owner protection: the team room's owner cannot leave. Allowing
     * it would orphan the room (sponsor still walks the sponsor
     * graph and re-creates / re-attaches their downline on next
     * self-heal anyway, so the leave would be a UX lie). Sponsors
     * who want a clean break should ask an admin to reparent their
     * downline first; until that flow exists, this is the safe gate.
     *
     * Returns true on success, WP_Error on policy refusal. Idempotent
     * for already-removed rows (returns true without re-stamping —
     * preserves the original removed_at timestamp for the audit
     * trail).
     */
    public static function leave_thread($thread_id, $user_id) {
        global $wpdb;
        $thread_id = (int) $thread_id;
        $user_id   = (int) $user_id;
        if ($thread_id <= 0 || $user_id <= 0) {
            return new WP_Error('matrix_messaging_bad_args', __('Invalid arguments.', 'matrix-mlm'));
        }

        // Pull the participant row + thread type in two cheap reads.
        // Ordering: participant first because most refusals happen
        // on this row (not a participant, already removed). If the
        // participant row exists, the thread row almost certainly
        // does too.
        $participants_t = $wpdb->prefix . 'matrix_message_participants';
        $threads_t      = $wpdb->prefix . 'matrix_message_threads';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, role, removed_at FROM $participants_t
              WHERE thread_id = %d AND user_id = %d",
            $thread_id, $user_id
        ));
        if (!$row) {
            return new WP_Error('matrix_messaging_forbidden', __('You are not a member of this thread.', 'matrix-mlm'));
        }
        if (!empty($row->removed_at)) {
            // Already left — return true so a double-click on the
            // Leave button doesn't surface an error toast on the
            // second click. The first click did the work; the
            // second is a confirming no-op.
            return true;
        }

        $thread_type = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT type FROM $threads_t WHERE id = %d",
            $thread_id
        ));
        if ($thread_type !== 'team_room' && $thread_type !== 'group_room') {
            return new WP_Error(
                'matrix_messaging_not_leavable',
                __('Only team rooms and group rooms can be left. Use Block to silence a direct message.', 'matrix-mlm')
            );
        }
        if ($row->role === 'owner') {
            return new WP_Error(
                'matrix_messaging_owner_cannot_leave',
                __('The room owner cannot leave their own room.', 'matrix-mlm')
            );
        }

        $wpdb->update(
            $participants_t,
            [
                'removed_at' => current_time('mysql', true),
                'removed_by' => $user_id,
            ],
            ['id' => (int) $row->id]
        );

        /**
         * Fires after a member self-leaves a team room. Lets audit-
         * log subscribers record the event without observing the
         * participants table directly. Symmetric with the
         * matrix_messaging_user_banned / unbanned hooks.
         *
         * @param int $thread_id
         * @param int $user_id
         */
        do_action('matrix_messaging_thread_left', $thread_id, $user_id);

        return true;
    }

    /**
     * List the active members of a thread (DB 1.0.22).
     *
     * Returns an array of plain stdClass rows shaped for direct
     * rendering: user_id, display_name (display_name fallback to
     * user_login fallback to '#<id>'), role, joined_at, is_self.
     * Excludes removed members — the panel shows "who's currently
     * here", not "who has ever been here". Soft-removed rows stay
     * in storage for the audit story but never reach the UI.
     *
     * Visibility gate: the viewer must be a non-removed participant
     * themselves. We refuse to leak the membership of a thread to
     * a stranger, even though display names are public on the
     * dashboard — composing a member roster from a thread the
     * viewer doesn't belong to is its own privacy escalation
     * (e.g. mapping a sponsor's full downline by probing thread
     * ids one at a time).
     *
     * Hard cap at 200 rows. Real-world team rooms top out at the
     * sponsor's direct-referral count, which is bounded by the
     * matrix shape (small per node — usually <50 directs across
     * the whole plan), so 200 is comfortably above any plausible
     * legitimate count and the cap is here only to defend
     * against a malformed thread that somehow has thousands of
     * participants.
     */
    public static function list_thread_members($thread_id, $viewer_id) {
        global $wpdb;
        $thread_id = (int) $thread_id;
        $viewer_id = (int) $viewer_id;
        if ($thread_id <= 0 || $viewer_id <= 0) {
            return new WP_Error('matrix_messaging_bad_args', __('Invalid arguments.', 'matrix-mlm'));
        }
        if (!self::user_can_view_thread($thread_id, $viewer_id)) {
            return new WP_Error('matrix_messaging_forbidden', __('You are not a participant of this thread.', 'matrix-mlm'));
        }

        $participants_t = $wpdb->prefix . 'matrix_message_participants';
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT p.user_id, p.role, p.joined_at,
                   u.user_login, u.display_name
              FROM $participants_t p
              LEFT JOIN {$wpdb->users} u ON u.ID = p.user_id
             WHERE p.thread_id = %d
               AND p.removed_at IS NULL
             ORDER BY (p.role = 'owner') DESC, p.joined_at ASC, p.user_id ASC
             LIMIT 200
        ", $thread_id));

        $out = [];
        foreach ((array) $rows as $r) {
            $name = '';
            if (!empty($r->display_name)) {
                $name = (string) $r->display_name;
            } elseif (!empty($r->user_login)) {
                $name = (string) $r->user_login;
            } else {
                $name = '#' . (int) $r->user_id;
            }
            $out[] = (object) [
                'user_id'      => (int) $r->user_id,
                'display_name' => $name,
                'role'         => (string) $r->role,
                'joined_at'    => (string) $r->joined_at,
                'is_self'      => ((int) $r->user_id === $viewer_id),
            ];
        }
        return $out;
    }

    // ---------------------------------------------------------------
    // Typing indicators (transient-backed, no schema)
    //
    // One transient per (thread, user) with TTL TYPING_TTL_SECONDS.
    // The client pings set_typing_state() on a slow cadence while
    // the user is composing; ajax_fetch_thread calls
    // get_typing_users() on every poll to populate the typing[]
    // wire field. A user who closes their tab mid-compose stops
    // looking like they're typing within TYPING_TTL_SECONDS — no
    // cleanup cron, no stored rows, no admin tooling. Reads scale
    // with active-participant count per thread, not with site
    // user count, so even a 50-member team room runs at ~50
    // transient-get()s per poll, dominated entirely by network
    // round-trip not by storage layer cost.
    //
    // This is intentionally a coarse "typing yes / no" signal,
    // not a per-keystroke broadcast — every-keystroke would be
    // both expensive on the network and a privacy concern (typing
    // bursts can reveal more than the eventual sent message).
    // ---------------------------------------------------------------

    /**
     * Stamp or clear a typing transient for the (thread, user) pair.
     *
     * Transient key shape: {prefix}{thread_id}_{user_id}, scoped to
     * the message thread so probing one user's typing state in
     * thread A doesn't leak their typing state in thread B.
     *
     * Stored value is the GMT unix timestamp of the most recent
     * beacon. Reads compare this against TYPING_TTL_SECONDS to
     * decide whether the user still counts as typing — strictly
     * redundant with the transient's own TTL, but keeps the read
     * path tolerant to object caches that don't honor expirations
     * tightly (e.g. some external memcached configs evict on
     * pressure rather than on TTL exactly).
     *
     * Visibility-gated on user_can_view_thread so a stranger
     * cannot inject typing pulses into a thread they don't
     * participate in.
     */
    public static function set_typing_state($thread_id, $user_id, $is_typing) {
        $thread_id = (int) $thread_id;
        $user_id   = (int) $user_id;
        if ($thread_id <= 0 || $user_id <= 0) {
            return false;
        }
        if (!self::user_can_view_thread($thread_id, $user_id)) {
            return false;
        }
        $key = self::TYPING_TRANSIENT_PREFIX . $thread_id . '_' . $user_id;
        if ($is_typing) {
            // Set TTL slightly longer than the documented
            // TYPING_TTL_SECONDS so a clock-skewed reader still
            // sees a fresh row; the read-side filter
            // ($age <= TTL) is the authoritative cutoff.
            set_transient($key, time(), self::TYPING_TTL_SECONDS + 2);
        } else {
            delete_transient($key);
        }
        return true;
    }

    /**
     * Return active participants who are currently typing in a
     * thread, excluding $exclude_user_id (the viewer — you don't
     * want to see "you are typing" alongside your own composer).
     *
     * Walks the participants table with the same
     * removed_at IS NULL filter user_can_view_thread uses, then
     * probes one transient per non-excluded participant. Each row
     * is shaped for direct rendering (display_name fallback chain
     * matches list_thread_members).
     *
     * Hard cap at 16 typers in the response. Real-world rooms top
     * out at a handful of simultaneous typers; the cap is here so
     * a freak edge case (every member of a large team room
     * pulsing at once) doesn't bloat the poll response. Anything
     * beyond is silently truncated — the UI renders "5 people are
     * typing…" past 3 anyway, so the missing names are never
     * surfaced individually.
     */
    public static function get_typing_users($thread_id, $exclude_user_id) {
        global $wpdb;
        $thread_id        = (int) $thread_id;
        $exclude_user_id  = (int) $exclude_user_id;
        if ($thread_id <= 0) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.user_id, u.user_login, u.display_name
               FROM {$wpdb->prefix}matrix_message_participants p
               LEFT JOIN {$wpdb->users} u ON u.ID = p.user_id
              WHERE p.thread_id = %d
                AND p.removed_at IS NULL
                AND p.user_id <> %d
              LIMIT 200",
            $thread_id, $exclude_user_id
        ));

        $now    = time();
        $ttl    = self::TYPING_TTL_SECONDS;
        $typers = [];
        foreach ((array) $rows as $r) {
            $uid = (int) $r->user_id;
            if ($uid <= 0) {
                continue;
            }
            $key = self::TYPING_TRANSIENT_PREFIX . $thread_id . '_' . $uid;
            $stamp = get_transient($key);
            if ($stamp === false) {
                continue;
            }
            $stamp = (int) $stamp;
            if (($now - $stamp) > $ttl) {
                continue;
            }
            $name = !empty($r->display_name)
                ? (string) $r->display_name
                : (!empty($r->user_login) ? (string) $r->user_login : '#' . $uid);
            $typers[] = (object) [
                'user_id'      => $uid,
                'display_name' => $name,
            ];
            if (count($typers) >= 16) {
                break;
            }
        }
        return $typers;
    }

    // ---------------------------------------------------------------
    // Group rooms (DB 1.0.24)
    //
    // User-created multi-party rooms. Distinct from team_rooms in
    // every interesting way:
    //   - Membership comes from explicit invite, not from sponsor-
    //     graph self-heal. team_owner_id on the threads row holds
    //     the creator's user_id (reusing the existing column rather
    //     than adding a sibling) — semantically still "the room's
    //     owner", just no implication of sponsor relationship.
    //   - Title is operator-supplied at create time, not auto-
    //     derived from a display_name. Empty title falls back to
    //     "(Untitled room)" so the sidebar always has something
    //     to render.
    //   - Owner-only invite / remove. Members in v1 can self-leave
    //     (uses the existing leave_thread, widened in this PR) but
    //     can't add or remove others. Co-owner / member-can-invite
    //     are deliberate v1 omissions.
    //   - Owner cannot leave (same gate as team_room owners). The
    //     escape valve is "create a fresh room and abandon the old
    //     one"; v1 ships without explicit room deletion or owner
    //     transfer to keep the policy surface tight.
    //
    // Caps: per-user creation cap (default 5, hard 25) and per-
    // room member cap (default 50, hard 200). Both clamped at the
    // model layer.
    // ---------------------------------------------------------------

    /**
     * Effective per-user group-room creation cap. Reads the option,
     * applies the documented floor (1) and hard ceiling, then runs
     * the operator filter so code-level callers can override at
     * runtime — still clamped to the hard ceiling so a typo can't
     * raise it past what the storage layer can comfortably support.
     */
    public static function get_max_group_rooms_per_user() {
        $settings = self::get_settings();
        $val = isset($settings['max_group_rooms_per_user'])
            ? (int) $settings['max_group_rooms_per_user']
            : self::MAX_GROUP_ROOMS_PER_USER_DEFAULT;
        if ($val < 1) {
            $val = 1;
        }
        if ($val > self::MAX_GROUP_ROOMS_PER_USER_HARD) {
            $val = self::MAX_GROUP_ROOMS_PER_USER_HARD;
        }
        $filtered = (int) apply_filters('matrix_messaging_max_group_rooms_per_user', $val);
        if ($filtered < 1) { $filtered = 1; }
        if ($filtered > self::MAX_GROUP_ROOMS_PER_USER_HARD) {
            $filtered = self::MAX_GROUP_ROOMS_PER_USER_HARD;
        }
        return $filtered;
    }

    /**
     * Effective per-room member cap. Includes the owner. Same
     * clamp pattern as get_max_group_rooms_per_user.
     */
    public static function get_max_members_per_group_room() {
        $settings = self::get_settings();
        $val = isset($settings['max_members_per_group_room'])
            ? (int) $settings['max_members_per_group_room']
            : self::MAX_MEMBERS_PER_GROUP_ROOM_DEFAULT;
        if ($val < 2) {
            // A group room with one member is just a note-to-self;
            // require at least 2 (owner + 1) so the room is
            // semantically a group.
            $val = 2;
        }
        if ($val > self::MAX_MEMBERS_PER_GROUP_ROOM_HARD) {
            $val = self::MAX_MEMBERS_PER_GROUP_ROOM_HARD;
        }
        $filtered = (int) apply_filters('matrix_messaging_max_members_per_group_room', $val);
        if ($filtered < 2) { $filtered = 2; }
        if ($filtered > self::MAX_MEMBERS_PER_GROUP_ROOM_HARD) {
            $filtered = self::MAX_MEMBERS_PER_GROUP_ROOM_HARD;
        }
        return $filtered;
    }

    /**
     * Resolve a list of identifier strings (usernames, referral codes,
     * or numeric ids) to user_ids. Drops anything that doesn't
     * resolve to an active user, dedupes, and excludes the caller
     * themselves (the creator is added separately as owner).
     *
     * Used by both create_group_room (to seed initial membership)
     * and invite_to_group_room (to add later). Centralising means
     * "is X a real messageable user?" has one definition rather
     * than two that drift.
     */
    private static function resolve_member_identifiers($identifiers, $exclude_user_id) {
        global $wpdb;
        $exclude_user_id = (int) $exclude_user_id;
        $resolved = [];
        foreach ((array) $identifiers as $raw) {
            $raw = trim((string) $raw);
            if ($raw === '') {
                continue;
            }
            $uid = 0;
            if (ctype_digit($raw)) {
                $uid = (int) $raw;
            } elseif (preg_match('/^[A-Z0-9]{3,20}$/', $raw)) {
                // Looks like a referral code (existing convention
                // in ajax_open_dm). Try referral code lookup first,
                // fall back to username if no match.
                $uid = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT user_id FROM {$wpdb->prefix}matrix_user_meta WHERE referral_code = %s",
                    $raw
                ));
                if ($uid <= 0) {
                    $u = get_user_by('login', sanitize_user($raw));
                    $uid = $u ? (int) $u->ID : 0;
                }
            } else {
                $u = get_user_by('login', sanitize_user($raw));
                $uid = $u ? (int) $u->ID : 0;
            }
            if ($uid <= 0 || $uid === $exclude_user_id) {
                continue;
            }
            // get_userdata is the cheapest "does this user exist
            // at all" probe; deleted users return false even if
            // they once owned the username/referral code. We do
            // not run a banned-user check here because banning
            // controls send-side behaviour, not invite-side: the
            // banned user's send is what gets blocked, not their
            // ability to receive.
            $u = get_userdata($uid);
            if (!$u) {
                continue;
            }
            $resolved[$uid] = $uid;
        }
        return array_values($resolved);
    }

    /**
     * Create a group room. Owner is $creator_id, initial members are
     * any users in $member_identifiers that resolve to active
     * accounts. Title is sanitized + capped at 190 chars (the
     * column width) with an empty fallback.
     *
     * Returns the new thread_id, or WP_Error on policy refusal:
     *   - matrix_messaging_disabled    : module switched off
     *   - matrix_messaging_banned      : caller is banned from messaging
     *   - matrix_messaging_quota       : caller already owns the cap-many group rooms
     *   - matrix_messaging_too_many    : member list exceeds room cap
     *   - matrix_messaging_no_members  : zero members resolved (room would be lonely)
     *   - matrix_messaging_db_error    : storage failure
     *
     * Side effects: inserts one threads row + N+1 participants
     * rows (creator as owner, validated invitees as members),
     * then fires matrix_messaging_group_room_created so audit-
     * log subscribers see it.
     */
    public static function create_group_room($creator_id, $title, $member_identifiers) {
        global $wpdb;
        $creator_id = (int) $creator_id;

        if (!self::is_messaging_enabled()) {
            return new WP_Error('matrix_messaging_disabled', __('Messaging is currently disabled.', 'matrix-mlm'));
        }
        if ($creator_id <= 0) {
            return new WP_Error('matrix_messaging_bad_args', __('Invalid arguments.', 'matrix-mlm'));
        }
        if (self::is_user_banned($creator_id)) {
            return new WP_Error('matrix_messaging_banned', __('You are banned from messaging.', 'matrix-mlm'));
        }

        // Per-creator cap: count active group rooms this user
        // currently owns (owner role, room not archived). The
        // count is bounded by MAX_GROUP_ROOMS_PER_USER_HARD so a
        // misconfigured filter can't inflate this query past
        // O(25).
        $threads_t      = $wpdb->prefix . 'matrix_message_threads';
        $participants_t = $wpdb->prefix . 'matrix_message_participants';
        $current_owned  = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
               FROM $threads_t t
               INNER JOIN $participants_t p
                       ON p.thread_id = t.id
                      AND p.user_id   = %d
                      AND p.role      = 'owner'
                      AND p.removed_at IS NULL
              WHERE t.type   = 'group_room'
                AND t.status = 'active'",
            $creator_id
        ));
        $cap_rooms = self::get_max_group_rooms_per_user();
        if ($current_owned >= $cap_rooms) {
            return new WP_Error(
                'matrix_messaging_quota',
                sprintf(
                    /* translators: %d: maximum number of group rooms a user may own. */
                    __('You can own at most %d group rooms. Leave or archive an existing one before creating another.', 'matrix-mlm'),
                    $cap_rooms
                )
            );
        }

        // Resolve invitees. Exclude the creator (added below as
        // owner; if they listed themselves in the member array we
        // silently dedupe rather than reject).
        $invitee_ids = self::resolve_member_identifiers($member_identifiers, $creator_id);

        // Member cap is total members (including the creator).
        // We refuse the creation rather than truncating because
        // a silent truncation is the kind of foot-gun that
        // surfaces only when the user notices "wait, where's
        // Alice?" three days later.
        $cap_members = self::get_max_members_per_group_room();
        $total_members = count($invitee_ids) + 1; // +1 for owner
        if ($total_members > $cap_members) {
            return new WP_Error(
                'matrix_messaging_too_many',
                sprintf(
                    /* translators: %d: maximum number of members per group room. */
                    __('Group rooms support at most %d members (including the creator). Trim your invite list and try again.', 'matrix-mlm'),
                    $cap_members
                )
            );
        }
        if (count($invitee_ids) < 1) {
            return new WP_Error(
                'matrix_messaging_no_members',
                __('Invite at least one other user when creating a group room.', 'matrix-mlm')
            );
        }

        // Sanitise title. Empty / whitespace-only falls back to a
        // generic placeholder so the sidebar always has something
        // to render. mb_substr at 190 chars matches the column
        // width and protects against multibyte truncation.
        $title = trim((string) $title);
        if ($title === '') {
            $title = __('(Untitled room)', 'matrix-mlm');
        }
        $title = mb_substr(sanitize_text_field($title), 0, 190);

        $now = current_time('mysql', true);
        $wpdb->insert($threads_t, [
            'type'             => 'group_room',
            'team_owner_id'    => $creator_id,
            'title'            => $title,
            'status'           => 'active',
            'created_at'       => $now,
            'last_message_at'  => $now,
        ]);
        $thread_id = (int) $wpdb->insert_id;
        if (!$thread_id) {
            return new WP_Error('matrix_messaging_db_error', __('Could not create room.', 'matrix-mlm'));
        }

        // Owner row first so partial inserts (e.g. a deadlock on
        // the participants table) leave the room with at least
        // its creator. The UNIQUE(thread_id, user_id) constraint
        // makes the inserts safely re-runnable if a retry path
        // ever lands them again.
        $wpdb->insert($participants_t, [
            'thread_id'    => $thread_id,
            'user_id'      => $creator_id,
            'role'         => 'owner',
            'joined_at'    => $now,
            'last_read_at' => null,
            'muted_until'  => null,
            'removed_at'   => null,
            'removed_by'   => null,
        ]);
        foreach ($invitee_ids as $uid) {
            $wpdb->insert($participants_t, [
                'thread_id'    => $thread_id,
                'user_id'      => $uid,
                'role'         => 'member',
                'joined_at'    => $now,
                'last_read_at' => null,
                'muted_until'  => null,
                'removed_at'   => null,
                'removed_by'   => null,
            ]);
        }

        /**
         * Fires after a group room has been created and seeded
         * with its initial membership. Lets audit log / observability
         * modules record the event without observing the threads /
         * participants tables directly.
         *
         * @param int   $thread_id   New group room thread id.
         * @param int   $creator_id  User id of the room creator (now owner).
         * @param array $invitee_ids Validated initial member ids.
         */
        do_action('matrix_messaging_group_room_created', $thread_id, $creator_id, $invitee_ids);

        return $thread_id;
    }

    /**
     * Add members to an existing group room. Owner-only in v1.
     *
     * Each invitee is validated through resolve_member_identifiers
     * (see create_group_room) and gated by the same per-room
     * member cap. Already-active members are silently skipped (no
     * error — the user wanted them in the room and they already
     * are). Self-removed members (sticky removed_by == self) are
     * NOT auto-reactivated by an owner-issued invite; that policy
     * came in with PR #364 and we honour it here. Owner-removed
     * members ARE reactivated, mirroring the team_room reparent
     * story.
     *
     * Returns ['added' => N, 'skipped' => N, 'denied' => N] on
     * success, WP_Error on policy refusal at the room level.
     */
    public static function invite_to_group_room($thread_id, $owner_id, $invitee_identifiers) {
        global $wpdb;
        $thread_id = (int) $thread_id;
        $owner_id  = (int) $owner_id;
        if ($thread_id <= 0 || $owner_id <= 0) {
            return new WP_Error('matrix_messaging_bad_args', __('Invalid arguments.', 'matrix-mlm'));
        }

        $threads_t      = $wpdb->prefix . 'matrix_message_threads';
        $participants_t = $wpdb->prefix . 'matrix_message_participants';

        $thread = $wpdb->get_row($wpdb->prepare(
            "SELECT id, type, status FROM $threads_t WHERE id = %d",
            $thread_id
        ));
        if (!$thread || $thread->type !== 'group_room' || $thread->status !== 'active') {
            return new WP_Error('matrix_messaging_not_found', __('Group room not found or archived.', 'matrix-mlm'));
        }

        // Owner-only gate. We deliberately don't accept "any
        // current member" as the invite role: opening the invite
        // surface to the whole membership multiplies the spam
        // attack surface (one compromised member can fan out the
        // room arbitrarily) for very little user-facing
        // benefit. Owners can promote a co-owner manually by
        // reading the participant ids out of the database in v1
        // — there's no UI for it yet, but the model layer
        // already accepts role='owner' so a future PR has a
        // clean ramp.
        $caller_role = $wpdb->get_var($wpdb->prepare(
            "SELECT role FROM $participants_t
              WHERE thread_id = %d AND user_id = %d AND removed_at IS NULL",
            $thread_id, $owner_id
        ));
        if ($caller_role !== 'owner') {
            return new WP_Error('matrix_messaging_forbidden', __('Only the room owner can invite new members.', 'matrix-mlm'));
        }

        $resolved = self::resolve_member_identifiers($invitee_identifiers, $owner_id);
        if (empty($resolved)) {
            return new WP_Error('matrix_messaging_no_members', __('No valid users to invite.', 'matrix-mlm'));
        }

        // Member-cap check happens against the post-add count.
        // We probe the current active membership once and refuse
        // the whole call if applying it would exceed the cap —
        // partial application would leave the owner uncertain
        // about who landed and who didn't.
        $active_now = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $participants_t
              WHERE thread_id = %d AND removed_at IS NULL",
            $thread_id
        ));
        $cap_members = self::get_max_members_per_group_room();
        if ($active_now + count($resolved) > $cap_members) {
            return new WP_Error(
                'matrix_messaging_too_many',
                sprintf(
                    /* translators: %d: per-room member cap. */
                    __('Adding these members would exceed the %d-member cap for this room.', 'matrix-mlm'),
                    $cap_members
                )
            );
        }

        $now = current_time('mysql', true);
        $added = 0; $skipped = 0; $denied = 0;
        foreach ($resolved as $uid) {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, removed_at, removed_by FROM $participants_t
                  WHERE thread_id = %d AND user_id = %d",
                $thread_id, $uid
            ));
            if ($existing && empty($existing->removed_at)) {
                $skipped++;
                continue;
            }
            if ($existing && (int) $existing->removed_by === (int) $uid) {
                // Sticky self-leave (PR #364 policy). Owner re-
                // invite is refused for this user; the user
                // would have to ask to re-join out-of-band, and
                // a follow-up PR can ship a "request to rejoin"
                // surface if the use case shows up. Counted as
                // denied so the UI can tell the owner.
                $denied++;
                continue;
            }
            if ($existing) {
                // Owner-removed (or admin-removed); reactivate.
                $wpdb->update($participants_t,
                    ['removed_at' => null, 'removed_by' => null],
                    ['id' => (int) $existing->id]
                );
                $added++;
            } else {
                $wpdb->insert($participants_t, [
                    'thread_id'    => $thread_id,
                    'user_id'      => $uid,
                    'role'         => 'member',
                    'joined_at'    => $now,
                    'last_read_at' => null,
                    'muted_until'  => null,
                    'removed_at'   => null,
                    'removed_by'   => null,
                ]);
                $added++;
            }
        }

        do_action('matrix_messaging_group_room_invited', $thread_id, $owner_id, $resolved, $added);

        return [
            'added'   => $added,
            'skipped' => $skipped,
            'denied'  => $denied,
        ];
    }

    /**
     * Remove a member from a group room (owner-only in v1). Soft-
     * removes by stamping removed_at + removed_by = $owner_id, so
     * the row stays in storage for the audit trail and the
     * sticky-self-leave check can distinguish this from a self-
     * exit on a future re-invite probe.
     *
     * Refuses three policy cases:
     *   - caller isn't owner
     *   - target isn't an active member
     *   - target is the owner (owner can't be removed; v1 doesn't
     *     ship owner-transfer)
     *
     * Returns true on success, WP_Error otherwise.
     */
    public static function remove_from_group_room($thread_id, $owner_id, $target_user_id) {
        global $wpdb;
        $thread_id      = (int) $thread_id;
        $owner_id       = (int) $owner_id;
        $target_user_id = (int) $target_user_id;
        if ($thread_id <= 0 || $owner_id <= 0 || $target_user_id <= 0) {
            return new WP_Error('matrix_messaging_bad_args', __('Invalid arguments.', 'matrix-mlm'));
        }
        if ($owner_id === $target_user_id) {
            return new WP_Error('matrix_messaging_forbidden', __('Use Leave to exit a room you own (not supported in this version).', 'matrix-mlm'));
        }

        $threads_t      = $wpdb->prefix . 'matrix_message_threads';
        $participants_t = $wpdb->prefix . 'matrix_message_participants';

        $thread = $wpdb->get_row($wpdb->prepare(
            "SELECT id, type, status FROM $threads_t WHERE id = %d",
            $thread_id
        ));
        if (!$thread || $thread->type !== 'group_room' || $thread->status !== 'active') {
            return new WP_Error('matrix_messaging_not_found', __('Group room not found or archived.', 'matrix-mlm'));
        }

        $caller_role = $wpdb->get_var($wpdb->prepare(
            "SELECT role FROM $participants_t
              WHERE thread_id = %d AND user_id = %d AND removed_at IS NULL",
            $thread_id, $owner_id
        ));
        if ($caller_role !== 'owner') {
            return new WP_Error('matrix_messaging_forbidden', __('Only the room owner can remove members.', 'matrix-mlm'));
        }

        $target = $wpdb->get_row($wpdb->prepare(
            "SELECT id, role, removed_at FROM $participants_t
              WHERE thread_id = %d AND user_id = %d",
            $thread_id, $target_user_id
        ));
        if (!$target || !empty($target->removed_at)) {
            return new WP_Error('matrix_messaging_not_member', __('That user is not currently a member of this room.', 'matrix-mlm'));
        }
        if ($target->role === 'owner') {
            // Defence in depth — should already be caught by the
            // $owner_id === $target_user_id check above, since
            // there's only one owner role per room, but the
            // explicit refusal makes the policy obvious.
            return new WP_Error('matrix_messaging_forbidden', __('The room owner cannot be removed.', 'matrix-mlm'));
        }

        $wpdb->update(
            $participants_t,
            [
                'removed_at' => current_time('mysql', true),
                'removed_by' => $owner_id,
            ],
            ['id' => (int) $target->id]
        );

        do_action('matrix_messaging_group_room_member_removed', $thread_id, $owner_id, $target_user_id);

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

    // ---------------------------------------------------------------
    // Emoji reactions on individual messages (DB 1.0.21)
    //
    // One row in matrix_message_reactions per (message, user, emoji).
    // The UNIQUE constraint enforces toggle semantics at the storage
    // layer so react_to_message() can do a delete-then-insert without
    // a SELECT-and-race window.
    //
    // Reaction palette is intentionally tiny — six common emojis
    // (see ALLOWED_REACTION_EMOJIS) — to dodge the "should we ship a
    // 1500-emoji picker" question for a v1 feature, and to bound
    // moderator overhead (no need for a filter rule to ban an
    // offensive ZWJ-sequence emoji that smuggled past the picker).
    // Operators can grow the palette by filtering matrix_messaging_allowed_reactions.
    // ---------------------------------------------------------------

    /**
     * Whitelisted emoji palette. Kept ASCII-friendly in the source by
     * using the actual emoji literals — modern PHP with mbstring
     * handles them fine, and reading "👍" in code is more obvious
     * than reading "\u{1F44D}".
     */
    const ALLOWED_REACTION_EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

    /**
     * Toggle a reaction. If the (message, user, emoji) row already
     * exists, delete it; otherwise insert it.
     *
     * Returns ['action' => 'added'|'removed', 'count' => N] on
     * success so the caller can update its UI in one round-trip
     * without a follow-up GET. Returns WP_Error on policy failure.
     */
    public static function react_to_message($message_id, $user_id, $emoji) {
        global $wpdb;
        $message_id = (int) $message_id;
        $user_id    = (int) $user_id;
        $emoji      = (string) $emoji;

        if ($message_id <= 0 || $user_id <= 0) {
            return new WP_Error('matrix_messaging_bad_args', __('Invalid arguments.', 'matrix-mlm'));
        }

        // Whitelist + filter for operator extension. The filter
        // runs only when the default whitelist would refuse the
        // emoji, so the common case (matches ALLOWED) stays a
        // single in_array lookup.
        $allowed = self::ALLOWED_REACTION_EMOJIS;
        if (!in_array($emoji, $allowed, true)) {
            $allowed = (array) apply_filters('matrix_messaging_allowed_reactions', $allowed);
            if (!in_array($emoji, $allowed, true)) {
                return new WP_Error('matrix_messaging_invalid_emoji', __('Reaction not allowed.', 'matrix-mlm'));
            }
        }

        // Visibility gate: reacting to a message implies seeing it.
        // Cheap check via thread participation.
        $thread_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT thread_id FROM {$wpdb->prefix}matrix_messages WHERE id = %d AND deleted_at IS NULL",
            $message_id
        ));
        if (!$thread_id || !self::user_can_view_thread($thread_id, $user_id)) {
            return new WP_Error('matrix_messaging_forbidden', __('Cannot react to this message.', 'matrix-mlm'));
        }

        $reactions_t = $wpdb->prefix . 'matrix_message_reactions';

        // Toggle. INSERT IGNORE means "add if absent"; the affected
        // rows count tells us whether we added or hit the unique
        // constraint. If we added, return; if not, delete (the
        // emoji was already there for this user — the click means
        // remove).
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $reactions_t (message_id, user_id, emoji, created_at)
             VALUES (%d, %d, %s, %s)",
            $message_id, $user_id, $emoji, current_time('mysql', true)
        ));

        if ($inserted === 1) {
            $action = 'added';
        } else {
            $wpdb->delete($reactions_t, [
                'message_id' => $message_id,
                'user_id'    => $user_id,
                'emoji'      => $emoji,
            ], ['%d', '%d', '%s']);
            $action = 'removed';
        }

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $reactions_t WHERE message_id = %d AND emoji = %s",
            $message_id, $emoji
        ));

        return ['action' => $action, 'emoji' => $emoji, 'count' => $count];
    }

    /**
     * Compute reactions for the recent message window of a thread.
     *
     * Returns a map keyed by message_id whose values are
     * [emoji => ['count' => N, 'mine' => 0|1]] so the JS can
     * render in one pass. Bounded by RECEIPT_LOOKBACK to keep
     * the response from ballooning on long threads — older
     * messages drop their reactions UI from view (the rows
     * themselves stay; this is purely a render-cost cap).
     *
     * Two queries: (a) recent message ids in the thread, (b)
     * reactions joined to those ids. PHP-side group-by keeps
     * the response shape compact for the wire.
     */
    public static function compute_thread_reactions($thread_id, $user_id) {
        global $wpdb;
        $thread_id = (int) $thread_id;
        $user_id   = (int) $user_id;
        if ($thread_id <= 0 || $user_id <= 0) {
            return new \stdClass(); // empty object so JS sees {}, not []
        }

        $messages_t  = $wpdb->prefix . 'matrix_messages';
        $reactions_t = $wpdb->prefix . 'matrix_message_reactions';

        // Same lookback window as receipts so the two maps
        // describe the same set of messages — keeps the
        // client's per-message rendering loop simple.
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $messages_t
              WHERE thread_id = %d AND deleted_at IS NULL
              ORDER BY id DESC
              LIMIT %d",
            $thread_id, self::RECEIPT_LOOKBACK
        ));
        if (empty($ids)) {
            return new \stdClass();
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $args         = array_map('intval', $ids);
        $args[]       = $user_id;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT message_id, emoji, user_id
               FROM $reactions_t
              WHERE message_id IN ($placeholders)",
            array_map('intval', $ids)
        ));

        $out = [];
        foreach ($rows as $r) {
            $mid = (int) $r->message_id;
            if (!isset($out[$mid])) {
                $out[$mid] = [];
            }
            if (!isset($out[$mid][$r->emoji])) {
                $out[$mid][$r->emoji] = ['count' => 0, 'mine' => 0];
            }
            $out[$mid][$r->emoji]['count']++;
            if ((int) $r->user_id === $user_id) {
                $out[$mid][$r->emoji]['mine'] = 1;
            }
        }
        // Cast to objects so empty stays {} not [] on the wire.
        $obj = new \stdClass();
        foreach ($out as $mid => $emojis) {
            $emoji_obj = new \stdClass();
            foreach ($emojis as $e => $payload) {
                $emoji_obj->$e = $payload;
            }
            $obj->$mid = $emoji_obj;
        }
        return $obj;
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

        // Hydrate the thread-level state the UI needs to render
        // the chrome controls (mute/block surfaces, edit-window
        // settings) without a second round-trip per pane open.
        // Returned on every poll tick so a user who mutes /
        // blocks / unblocks from another tab sees the chrome
        // converge within one poll without a manual refresh.
        $participant_state = self::get_participant_state($thread_id, $uid);
        $other_user_id     = $thread_type === 'dm' ? self::get_dm_other_party($thread_id, $uid) : 0;
        $is_blocked        = $other_user_id ? (bool) self::is_blocking($uid, $other_user_id) : false;

        wp_send_json_success([
            'messages'    => $rows,
            'thread_type' => $thread_type,
            'receipts'    => self::compute_thread_read_receipts($thread_id, $uid),
            // Reactions map (DB 1.0.21). Same lookback window as
            // receipts so the JS can iterate one render loop and
            // apply both. Empty object {} when no reactions exist
            // anywhere in the recent window — keeps the wire shape
            // stable.
            'reactions'   => self::compute_thread_reactions($thread_id, $uid),
            // Edit-window seconds the client uses to hide
            // edit/delete affordances after the in-memory clock
            // ticks past created_at + window. Pulled from the
            // server (rather than baked into wp_localize_script)
            // so a settings change by the operator propagates
            // within one poll without a page reload.
            'edit_window_seconds' => self::get_edit_window_seconds(),
            // Per-thread chrome state for the active viewer.
            'thread_state' => [
                'muted_until'   => $participant_state['muted_until'],
                'is_muted'      => $participant_state['is_muted'],
                'other_user_id' => $other_user_id,
                'is_blocked'    => $is_blocked,
            ],
            // Currently-typing peers in this thread (excluding
            // self). Ephemeral wire field — populated from
            // short-TTL transients, not from any stored row.
            // Empty array when nobody is typing; stable shape so
            // the JS doesn't branch on presence/absence.
            'typing' => self::get_typing_users($thread_id, $uid),
        ]);
    }

    /**
     * GET/POST action=matrix_messaging_fetch_older with
     * thread_id + before_id. Returns up to 50 messages older
     * than before_id, oldest-first inside the page (matching the
     * forward-poll wire shape so the JS doesn't need a second
     * code path).
     */
    public static function ajax_fetch_older() {
        $uid = self::ajax_guard();
        $thread_id = (int) ($_REQUEST['thread_id'] ?? 0);
        $before_id = (int) ($_REQUEST['before_id'] ?? 0);
        if (!self::user_can_view_thread($thread_id, $uid)) {
            wp_send_json_error(['message' => __('Forbidden', 'matrix-mlm')], 403);
        }
        $rows = self::get_thread_messages_before($thread_id, $uid, $before_id);
        // has_more = whether the returned page hit the limit; if
        // so there's almost certainly more history to fetch. The
        // client uses this to hide its "Load older" affordance
        // once the user has reached the start of the thread.
        $has_more = count($rows) >= 50;
        wp_send_json_success([
            'messages' => $rows,
            'has_more' => $has_more,
        ]);
    }

    public static function ajax_send() {
        $uid = self::ajax_guard();
        $thread_id = (int) ($_POST['thread_id'] ?? 0);
        $body      = (string) ($_POST['body'] ?? '');

        // Multi-attachment intake (DB 1.0.23). Prefer the new
        // attachment_ids[] shape; fall back to the legacy single
        // attachment_id field for any client that hasn't been
        // updated yet (and for the ajax_edit / dispatch tests
        // that still compose with the old key). Sanitize at the
        // edge so the model only ever sees clean ints.
        $attachment_ids = null;
        if (isset($_POST['attachment_ids']) && is_array($_POST['attachment_ids'])) {
            $attachment_ids = array_map('intval', $_POST['attachment_ids']);
        } elseif (isset($_POST['attachment_id'])) {
            $aid = (int) $_POST['attachment_id'];
            $attachment_ids = $aid > 0 ? [$aid] : [];
        }

        // Voice-note metadata (DB 1.0.25). Optional. Shape:
        //   attachment_meta[<attachment_id>][peaks][] = float
        // Sanitised on the model side; here we just normalise
        // the wp_unslash-and-array-of-arrays layout that PHP
        // produces from the multipart POST body. Anything
        // out-of-shape gets dropped silently (model defaults to
        // a flat-bar render when peaks are missing).
        $attachment_meta = [];
        if (isset($_POST['attachment_meta']) && is_array($_POST['attachment_meta'])) {
            foreach ($_POST['attachment_meta'] as $aid => $meta) {
                $aid_int = (int) $aid;
                if ($aid_int <= 0 || !is_array($meta)) {
                    continue;
                }
                $entry = [];
                if (isset($meta['peaks']) && is_array($meta['peaks'])) {
                    $entry['peaks'] = array_map('floatval', $meta['peaks']);
                }
                if (!empty($entry)) {
                    $attachment_meta[$aid_int] = $entry;
                }
            }
        }

        $result = self::send_message($thread_id, $uid, $body, $attachment_ids, $attachment_meta);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['message_id' => $result]);
    }

    /**
     * POST action=matrix_messaging_edit with message_id + body.
     *
     * On success returns the freshly-hydrated message row so the
     * JS can canonicalise its in-memory copy in one round-trip
     * (rather than waiting for the next poll to overwrite the
     * optimistic edit). Hydrate the row through the same helper
     * the fetch path uses so the client's render keeps the same
     * shape regardless of which endpoint surfaced the row.
     */
    public static function ajax_edit() {
        global $wpdb;
        $uid = self::ajax_guard();
        $message_id = (int) ($_POST['message_id'] ?? 0);
        $body       = (string) ($_POST['body'] ?? '');
        $result = self::edit_message($message_id, $uid, $body);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, thread_id, sender_id, body, body_stripped, attachment_id, created_at, edited_at, deleted_at
               FROM {$wpdb->prefix}matrix_messages WHERE id = %d",
            $message_id
        ));
        $rows = [$row];
        self::hydrate_message_rows($rows);
        wp_send_json_success(['message' => $rows[0]]);
    }

    /**
     * POST action=matrix_messaging_delete with message_id.
     *
     * Sender-only soft-delete. Returns the post-delete row so the
     * UI can replace its optimistic remove with the canonical
     * "(message deleted)" placeholder shape. Idempotent — a
     * second delete on the same row succeeds silently.
     */
    public static function ajax_delete() {
        global $wpdb;
        $uid = self::ajax_guard();
        $message_id = (int) ($_POST['message_id'] ?? 0);
        $result = self::sender_delete_message($message_id, $uid);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, thread_id, sender_id, body, body_stripped, attachment_id, created_at, edited_at, deleted_at
               FROM {$wpdb->prefix}matrix_messages WHERE id = %d",
            $message_id
        ));
        $rows = [$row];
        self::hydrate_message_rows($rows);
        wp_send_json_success(['message' => $rows[0]]);
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
     * POST action=matrix_messaging_react with message_id + emoji.
     *
     * Toggles the reaction. Returns the new count for that emoji
     * on the message and whether the action added or removed —
     * lets the JS canonicalise its in-memory copy in one round-
     * trip instead of waiting for the next poll.
     */
    public static function ajax_react() {
        $uid = self::ajax_guard();
        $message_id = (int) ($_POST['message_id'] ?? 0);
        $emoji      = isset($_POST['emoji']) ? wp_unslash((string) $_POST['emoji']) : '';
        $result = self::react_to_message($message_id, $uid, $emoji);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success($result);
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

    /**
     * POST action=matrix_messaging_list_members with thread_id.
     *
     * Returns the active member roster for a thread the caller can
     * see. Drives the team-room "Members" panel; DM threads can also
     * call it (it'll simply return the two participants), but the
     * UI doesn't surface the panel for DMs.
     */
    public static function ajax_list_members() {
        $uid = self::ajax_guard();
        $thread_id = (int) ($_REQUEST['thread_id'] ?? 0);
        $rows = self::list_thread_members($thread_id, $uid);
        if (is_wp_error($rows)) {
            wp_send_json_error(['message' => $rows->get_error_message()], 403);
        }
        // Hydrate the panel's chrome state in the same response so
        // the JS can decide whether to render owner-only Invite /
        // Remove affordances without a second round-trip. Both
        // values are derived from the rows we already have, so
        // this is an in-PHP filter pass with no extra DB hit.
        $thread = self::get_thread($thread_id);
        $thread_type = $thread && isset($thread->type) ? (string) $thread->type : '';
        $viewer_role = '';
        foreach ($rows as $r) {
            if (!empty($r->is_self)) {
                $viewer_role = (string) $r->role;
                break;
            }
        }
        wp_send_json_success([
            'members'     => $rows,
            'thread_type' => $thread_type,
            'viewer_role' => $viewer_role,
        ]);
    }

    /**
     * POST action=matrix_messaging_leave_thread with thread_id.
     *
     * Self-leave only — the model refuses owner / non-team-room
     * threads. On success the next list_threads_for_user response
     * will omit the thread because the SQL gates on
     * removed_at IS NULL, so the JS doesn't need to mutate the
     * sidebar by hand: a refresh pulls the new state.
     */
    public static function ajax_leave_thread() {
        $uid = self::ajax_guard();
        $thread_id = (int) ($_POST['thread_id'] ?? 0);
        $result = self::leave_thread($thread_id, $uid);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['thread_id' => $thread_id]);
    }

    /**
     * POST action=matrix_messaging_typing with thread_id + is_typing
     * flag. Stamps or clears a transient that ajax_fetch_thread
     * reads back to populate the typing[] field on the next poll.
     *
     * No-op (returns success) when set_typing_state refuses for
     * policy reasons (not a participant, bad ids) — the
     * indicator is best-effort UX, not an authorization-bearing
     * surface. The visibility gate inside set_typing_state still
     * prevents a stranger from injecting fake typing pulses
     * into a thread they don't belong to.
     */
    public static function ajax_typing() {
        $uid = self::ajax_guard();
        $thread_id  = (int) ($_POST['thread_id'] ?? 0);
        $is_typing  = !empty($_POST['is_typing']) && $_POST['is_typing'] !== '0';
        self::set_typing_state($thread_id, $uid, $is_typing);
        wp_send_json_success(['ts' => time()]);
    }

    /**
     * POST action=matrix_messaging_create_group_room.
     *   - title           : string (sanitised, capped at 190 chars)
     *   - members         : string of comma / newline separated
     *                       identifiers (usernames, referral codes,
     *                       or numeric user ids), OR an array of
     *                       the same.
     *
     * Both intake shapes converge on the same array of identifiers
     * so admin tooling that wants to POST a clean array doesn't
     * need to round-trip through a string.
     */
    public static function ajax_create_group_room() {
        $uid = self::ajax_guard();
        $title    = isset($_POST['title']) ? wp_unslash((string) $_POST['title']) : '';
        $raw_mems = $_POST['members'] ?? '';
        $identifiers = [];
        if (is_array($raw_mems)) {
            foreach ($raw_mems as $m) {
                $identifiers[] = wp_unslash((string) $m);
            }
        } else {
            // CSV / newline / whitespace-separated. Splits on any
            // run of comma / whitespace so the user can paste
            // "alice, bob carol" or "alice\nbob\ncarol" without
            // worrying about exact delimiter choice.
            $tokens = preg_split('/[,\s]+/', (string) $raw_mems) ?: [];
            foreach ($tokens as $t) {
                if ($t !== '') {
                    $identifiers[] = $t;
                }
            }
        }

        $result = self::create_group_room($uid, $title, $identifiers);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()]);
        }
        wp_send_json_success(['thread_id' => $result]);
    }

    /**
     * POST action=matrix_messaging_invite_to_group with thread_id +
     * members (same intake shape as ajax_create_group_room). Owner-
     * only at the model layer.
     */
    public static function ajax_invite_to_group() {
        $uid = self::ajax_guard();
        $thread_id = (int) ($_POST['thread_id'] ?? 0);
        $raw_mems  = $_POST['members'] ?? '';
        $identifiers = [];
        if (is_array($raw_mems)) {
            foreach ($raw_mems as $m) {
                $identifiers[] = wp_unslash((string) $m);
            }
        } else {
            $tokens = preg_split('/[,\s]+/', (string) $raw_mems) ?: [];
            foreach ($tokens as $t) {
                if ($t !== '') {
                    $identifiers[] = $t;
                }
            }
        }
        $result = self::invite_to_group_room($thread_id, $uid, $identifiers);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()]);
        }
        wp_send_json_success($result);
    }

    /**
     * POST action=matrix_messaging_remove_from_group with thread_id +
     * user_id. Owner-only at the model layer.
     */
    public static function ajax_remove_from_group() {
        $uid = self::ajax_guard();
        $thread_id      = (int) ($_POST['thread_id'] ?? 0);
        $target_user_id = (int) ($_POST['user_id'] ?? 0);
        $result = self::remove_from_group_room($thread_id, $uid, $target_user_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()]);
        }
        wp_send_json_success(['thread_id' => $thread_id, 'user_id' => $target_user_id]);
    }
}
