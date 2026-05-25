<?php
/**
 * Admin — Member Announcements (broadcast composer)
 *
 * Composer UI for broadcasting an in-app `admin_announcement`
 * notification to every member. The plumbing already exists:
 *
 *   - Matrix_MLM_In_App_Notifications::enqueue_for_many() does
 *     the per-recipient fan-out.
 *   - The 'admin_announcement' type slug is already registered in
 *     the dashboard icon map (megaphone) and in the JS bell
 *     renderer's icon table — so a row written today shows up
 *     correctly without any UI changes elsewhere.
 *
 * What this class adds is the operator-facing form: pick an
 * audience, type a title + body + optional link, REVIEW the
 * recipient count, and confirm. The two-step compose → review →
 * send shape is intentional: a fat-finger blast that hits 10,000
 * member bells is the kind of mistake that's painful to recover
 * from (we'd have to reach in and DELETE notification rows by
 * meta to undo it), so a server-rendered review screen with the
 * exact recipient count gives operators one last "are you sure"
 * before any rows get written.
 *
 * Capability: manage_matrix_settings — the same admin tier that
 * gates Settings, Backup, and Import. Sending a platform-wide
 * blast is a settings-grade operation; we deliberately don't gate
 * on the broader manage_matrix_mlm so that support-tier operators
 * who can read deposits/tickets can't also broadcast.
 *
 * History: each successful send appends a row to the
 * matrix_mlm_announcements_log option (capped at HISTORY_CAP
 * entries, FIFO). Storing in an option rather than a dedicated
 * table keeps the migration surface zero — the log is small
 * (each entry is ~1KB) and the cap means it can't grow without
 * bound. If the volume ever justifies a real table we can
 * migrate without breaking the public surface (this class is
 * the only reader/writer).
 *
 * The notification rows themselves are stored in
 * wp_matrix_notifications by enqueue_for_many() — one row per
 * recipient. That's by design: each user marks their own row
 * read independently, and the existing 90-day read-retention
 * cleanup in Matrix_MLM_In_App_Notifications::cleanup_old_read
 * handles long-term storage without any work here.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Announcements {

    /**
     * Option key for the in-database history log of past sends.
     * Stored as a JSON-encodable PHP array; each entry has:
     *   - id            (string, uniqid('ann_', true) — for table keys)
     *   - sent_at       (mysql datetime, current_time('mysql'))
     *   - sent_by_id    (int)
     *   - sent_by_login (string)
     *   - audience      (string, 'active' | 'all')
     *   - title         (string)
     *   - body          (string)
     *   - link_url      (string)
     *   - recipients    (int — number of rows successfully inserted)
     *   - attempted     (int — total user IDs we tried to enqueue
     *                    against; recipients can be lower if some
     *                    enqueue calls failed at the DB layer)
     */
    const HISTORY_OPTION_KEY = 'matrix_mlm_announcements_log';

    /**
     * Maximum number of history entries kept. Older entries are
     * trimmed FIFO so the option never grows unbounded. 50 is
     * enough for "show me the last few months of broadcasts" on
     * the page below, and well within wp_options' practical size.
     */
    const HISTORY_CAP = 50;

    /**
     * Title length cap. Mirrors the wp_matrix_notifications.title
     * column (varchar 255) so a title that fits the form fits the
     * downstream insert.
     */
    const TITLE_MAX = 255;

    /**
     * Body length cap. The DB column is TEXT (~64KB) but the bell
     * dropdown is a small UI surface; we cap at 2000 chars so an
     * accidentally pasted essay doesn't render as an unreadable
     * wall of text in 10,000 dropdowns.
     */
    const BODY_MAX = 2000;

    /**
     * Top-level entry point. Routes between compose, review, and
     * send sub-views. Form submissions run before any output so
     * notices can render at the top of the resulting page.
     */
    public function render() {
        if (!current_user_can('manage_matrix_settings')) {
            wp_die(__('You do not have permission to send announcements.', 'matrix-mlm'));
        }

        // The plumbing depends on Matrix_MLM_In_App_Notifications
        // being loaded — it always is via the core bootstrap, but
        // a defensive check here turns a fatal "class not found"
        // into a friendly notice if the include order ever drifts.
        if (!class_exists('Matrix_MLM_In_App_Notifications')) {
            ?>
            <div class="wrap matrix-admin-wrap">
                <h1><?php esc_html_e('Announcements', 'matrix-mlm'); ?></h1>
                <div class="notice notice-error">
                    <p><?php esc_html_e('In-app notifications are not initialised yet. Please reload this page in a moment, or contact support if the problem persists.', 'matrix-mlm'); ?></p>
                </div>
            </div>
            <?php
            return;
        }

        // Action routing. We use a `step` param rather than the
        // ?action= convention used by sibling admin pages because
        // the lifecycle here is genuinely a wizard (compose →
        // review → confirm-send), not the CRUD-style add/edit/
        // delete those pages model.
        $step = isset($_POST['step']) ? sanitize_key($_POST['step']) : '';

        if ($step === 'review' && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'matrix_announcement_review')) {
            $payload = $this->collect_payload_from_post();
            $errors  = $this->validate_payload($payload);
            if (!empty($errors)) {
                $this->render_compose($payload, $errors);
                return;
            }
            $this->render_review($payload);
            return;
        }

        if ($step === 'send' && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'matrix_announcement_send')) {
            $payload = $this->collect_payload_from_post();
            $errors  = $this->validate_payload($payload);
            if (!empty($errors)) {
                // Re-validating server-side at the send step
                // catches a tampered review POST that bypasses the
                // form (e.g. an empty title smuggled past the
                // client-side `required` attribute). Send the
                // operator back to the compose form with the
                // errors surfaced so they can fix and re-submit
                // rather than silently dropping the request.
                $this->render_compose($payload, $errors);
                return;
            }
            $result = $this->dispatch($payload);
            $this->render_after_send($result);
            return;
        }

        // Default: blank compose form + history.
        $this->render_compose([], []);
    }

    /* ------------------------------------------------------------------
     * Sub-views
     * ----------------------------------------------------------------*/

    /**
     * Render the compose form and (below it) the recent-sends
     * history table.
     *
     * @param array $prefill Optional sticky values when re-rendering
     *                       after a validation failure.
     * @param array $errors  Field-keyed error messages, also from
     *                       validation-failure re-render.
     */
    private function render_compose(array $prefill, array $errors) {
        $title    = isset($prefill['title'])    ? $prefill['title']    : '';
        $body     = isset($prefill['body'])     ? $prefill['body']     : '';
        $link_url = isset($prefill['link_url']) ? $prefill['link_url'] : '';
        $audience = isset($prefill['audience']) ? $prefill['audience'] : 'active';
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php esc_html_e('Member Announcements', 'matrix-mlm'); ?></h1>
            <p class="description" style="max-width:720px;">
                <?php esc_html_e('Broadcast an in-app notification to every member. Recipients will see the announcement in their dashboard bell icon (with a megaphone glyph) and in their Notifications tab. Email is not sent — for email blasts use Settings → Notifications instead.', 'matrix-mlm'); ?>
            </p>

            <?php if (!empty($errors)): ?>
                <div class="notice notice-error">
                    <p><strong><?php esc_html_e('Please fix the issues below before sending:', 'matrix-mlm'); ?></strong></p>
                    <ul style="margin:6px 0 0 20px;list-style:disc;">
                        <?php foreach ($errors as $msg): ?>
                            <li><?php echo esc_html($msg); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" class="matrix-announcement-form" style="max-width:720px;background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:18px 22px;">
                <?php wp_nonce_field('matrix_announcement_review'); ?>
                <input type="hidden" name="step" value="review">

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="matrix-ann-audience"><?php esc_html_e('Audience', 'matrix-mlm'); ?></label></th>
                            <td>
                                <select id="matrix-ann-audience" name="audience">
                                    <option value="active" <?php selected($audience, 'active'); ?>><?php esc_html_e('Active members only (default)', 'matrix-mlm'); ?></option>
                                    <option value="all" <?php selected($audience, 'all'); ?>><?php esc_html_e('All members (includes inactive / banned)', 'matrix-mlm'); ?></option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('"Active members" matches the same status filter the dashboard uses. Pick "All members" only when the announcement applies to everyone with an account (e.g. scheduled maintenance, terms-of-service update).', 'matrix-mlm'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="matrix-ann-title"><?php esc_html_e('Title', 'matrix-mlm'); ?> <span style="color:#d63638;">*</span></label></th>
                            <td>
                                <input type="text" id="matrix-ann-title" name="title" class="regular-text"
                                       value="<?php echo esc_attr($title); ?>"
                                       maxlength="<?php echo esc_attr(self::TITLE_MAX); ?>" required>
                                <p class="description">
                                    <?php
                                    printf(
                                        /* translators: %d: max title length */
                                        esc_html__('Short headline shown in the bell dropdown. Max %d characters.', 'matrix-mlm'),
                                        self::TITLE_MAX
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="matrix-ann-body"><?php esc_html_e('Message', 'matrix-mlm'); ?> <span style="color:#d63638;">*</span></label></th>
                            <td>
                                <textarea id="matrix-ann-body" name="body" rows="6" class="large-text"
                                          maxlength="<?php echo esc_attr(self::BODY_MAX); ?>" required><?php echo esc_textarea($body); ?></textarea>
                                <p class="description">
                                    <?php
                                    printf(
                                        /* translators: %d: max body length */
                                        esc_html__('Plain text. HTML is stripped before send. Keep it under ~280 characters for the cleanest dropdown render; the absolute max is %d.', 'matrix-mlm'),
                                        self::BODY_MAX
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="matrix-ann-link"><?php esc_html_e('Link URL (optional)', 'matrix-mlm'); ?></label></th>
                            <td>
                                <input type="text" id="matrix-ann-link" name="link_url" class="regular-text"
                                       value="<?php echo esc_attr($link_url); ?>"
                                       placeholder="/matrix-dashboard/?tab=overview">
                                <p class="description">
                                    <?php esc_html_e('Optional. Where the bell-row click navigates to. Must be a same-host URL or a path starting with "/" — off-host links are dropped silently for safety.', 'matrix-mlm'); ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p class="submit" style="margin:0;">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e('Review &amp; Send', 'matrix-mlm'); ?>
                    </button>
                    <span class="description" style="margin-left:10px;">
                        <?php esc_html_e('You will see a recipient count and confirmation step before any notifications are written.', 'matrix-mlm'); ?>
                    </span>
                </p>
            </form>

            <?php $this->render_history(); ?>
        </div>
        <?php
    }

    /**
     * Step 2: review. Show the operator the exact payload that
     * will be sent and the live recipient count, with a Confirm &
     * Send button (separate nonce) and a Back to edit link.
     *
     * The recipient count is computed at review time AND
     * re-computed at send time (the latter as a sanity check —
     * see dispatch()). Doing it twice is intentional: the review
     * count is what the operator agreed to, but if the audience
     * has shifted between review and confirm (e.g. an admin in
     * another tab toggled a user's status) the actual send count
     * may differ. We log both so the audit trail captures any
     * drift.
     */
    private function render_review(array $payload) {
        $user_ids = $this->fetch_target_user_ids($payload['audience']);
        $count    = count($user_ids);
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php esc_html_e('Review Announcement', 'matrix-mlm'); ?></h1>

            <div class="notice notice-warning">
                <p>
                    <?php
                    printf(
                        /* translators: %s: <strong>-wrapped recipient count */
                        wp_kses(
                            __('You are about to send this announcement to %s recipients. This action cannot be undone — recipients will see the notification in their bell as soon as you confirm.', 'matrix-mlm'),
                            ['strong' => []]
                        ),
                        '<strong>' . esc_html(number_format($count)) . '</strong>'
                    );
                    ?>
                </p>
            </div>

            <div style="max-width:720px;background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:18px 22px;">
                <h2 style="margin-top:0;display:flex;align-items:center;gap:8px;">
                    <span class="dashicons dashicons-megaphone" style="color:#4f46e5;"></span>
                    <?php echo esc_html($payload['title']); ?>
                </h2>
                <p style="white-space:pre-wrap;font-size:14px;line-height:1.5;color:#1f2937;">
                    <?php echo esc_html($payload['body']); ?>
                </p>
                <?php if (!empty($payload['link_url'])): ?>
                    <p style="margin-top:8px;">
                        <strong><?php esc_html_e('Click destination:', 'matrix-mlm'); ?></strong>
                        <code><?php echo esc_html($payload['link_url']); ?></code>
                    </p>
                <?php endif; ?>

                <hr>

                <table class="widefat striped" style="margin-top:8px;">
                    <tbody>
                        <tr>
                            <th><?php esc_html_e('Audience', 'matrix-mlm'); ?></th>
                            <td>
                                <?php
                                echo esc_html(
                                    $payload['audience'] === 'all'
                                        ? __('All members (includes inactive / banned)', 'matrix-mlm')
                                        : __('Active members only', 'matrix-mlm')
                                );
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Recipients', 'matrix-mlm'); ?></th>
                            <td><?php echo esc_html(number_format($count)); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Sent by', 'matrix-mlm'); ?></th>
                            <td><?php echo esc_html(wp_get_current_user()->user_login); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <?php if ($count === 0): ?>
                <div class="notice notice-error" style="max-width:720px;">
                    <p>
                        <?php esc_html_e('No recipients matched this audience. Pick a different audience or add active members before sending.', 'matrix-mlm'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <p style="margin-top:16px;display:flex;gap:10px;align-items:center;">
                <?php if ($count > 0): ?>
                <form method="post" style="margin:0;">
                    <?php wp_nonce_field('matrix_announcement_send'); ?>
                    <input type="hidden" name="step"     value="send">
                    <input type="hidden" name="audience" value="<?php echo esc_attr($payload['audience']); ?>">
                    <input type="hidden" name="title"    value="<?php echo esc_attr($payload['title']); ?>">
                    <input type="hidden" name="body"     value="<?php echo esc_attr($payload['body']); ?>">
                    <input type="hidden" name="link_url" value="<?php echo esc_attr($payload['link_url']); ?>">
                    <button type="submit" class="button button-primary"
                            onclick="return confirm('<?php echo esc_js(sprintf(
                                /* translators: %s: recipient count, formatted */
                                __('Send this announcement to %s members? This cannot be undone.', 'matrix-mlm'),
                                number_format($count)
                            )); ?>');">
                        <?php
                        printf(
                            /* translators: %s: recipient count, formatted */
                            esc_html__('Confirm &amp; Send to %s members', 'matrix-mlm'),
                            esc_html(number_format($count))
                        );
                        ?>
                    </button>
                </form>
                <?php endif; ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=matrix-mlm-announcements')); ?>" class="button">
                    <?php esc_html_e('Back to edit', 'matrix-mlm'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Step 3: post-send confirmation. Shown after dispatch() has
     * fanned out the rows. Surfaces the actual recipient count
     * (which may differ slightly from the review count if a user
     * was deleted between review and send), the audit log entry,
     * and a CTA to compose another.
     */
    private function render_after_send(array $result) {
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php esc_html_e('Announcement Sent', 'matrix-mlm'); ?></h1>

            <div class="notice notice-success">
                <p>
                    <?php
                    printf(
                        /* translators: %s: recipient count, formatted */
                        esc_html__('Delivered to %s members.', 'matrix-mlm'),
                        esc_html(number_format($result['recipients']))
                    );
                    ?>
                    <?php if ($result['recipients'] < $result['attempted']): ?>
                        <br>
                        <?php
                        printf(
                            /* translators: 1: failed-insert count, 2: total attempted */
                            esc_html__('Note: %1$d of %2$d enqueue calls failed at the database layer and were skipped. The notifications written so far are durable. Check the WordPress error log for details.', 'matrix-mlm'),
                            (int) ($result['attempted'] - $result['recipients']),
                            (int) $result['attempted']
                        );
                        ?>
                    <?php endif; ?>
                </p>
            </div>

            <p style="margin-top:16px;display:flex;gap:10px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=matrix-mlm-announcements')); ?>" class="button button-primary">
                    <?php esc_html_e('Send another', 'matrix-mlm'); ?>
                </a>
            </p>

            <?php $this->render_history(); ?>
        </div>
        <?php
    }

    /**
     * Render the recent-sends table at the bottom of the compose
     * + after-send screens. Read-only — there's no need to edit
     * or re-send a past announcement (a re-send would be a fresh
     * compose anyway, and editing past audit rows defeats the
     * purpose of the log).
     */
    private function render_history() {
        $history = $this->get_history();
        ?>
        <h2 style="margin-top:32px;"><?php esc_html_e('Recent Announcements', 'matrix-mlm'); ?></h2>
        <?php if (empty($history)): ?>
            <p><em><?php esc_html_e('No announcements have been sent yet.', 'matrix-mlm'); ?></em></p>
            <?php return; ?>
        <?php endif; ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Sent at', 'matrix-mlm'); ?></th>
                    <th><?php esc_html_e('Sent by', 'matrix-mlm'); ?></th>
                    <th><?php esc_html_e('Audience', 'matrix-mlm'); ?></th>
                    <th><?php esc_html_e('Recipients', 'matrix-mlm'); ?></th>
                    <th><?php esc_html_e('Title', 'matrix-mlm'); ?></th>
                    <th><?php esc_html_e('Message', 'matrix-mlm'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $entry): ?>
                    <tr>
                        <td><?php echo esc_html(date('M d, Y H:i', strtotime($entry['sent_at']))); ?></td>
                        <td><?php echo esc_html($entry['sent_by_login']); ?></td>
                        <td>
                            <?php
                            echo esc_html(
                                $entry['audience'] === 'all'
                                    ? __('All members', 'matrix-mlm')
                                    : __('Active only', 'matrix-mlm')
                            );
                            ?>
                        </td>
                        <td><?php echo esc_html(number_format((int) $entry['recipients'])); ?></td>
                        <td><strong><?php echo esc_html($entry['title']); ?></strong></td>
                        <td>
                            <details>
                                <summary><?php esc_html_e('Show', 'matrix-mlm'); ?></summary>
                                <div style="white-space:pre-wrap;margin-top:6px;max-width:600px;">
                                    <?php echo esc_html($entry['body']); ?>
                                </div>
                                <?php if (!empty($entry['link_url'])): ?>
                                    <p style="margin-top:6px;">
                                        <strong><?php esc_html_e('Link:', 'matrix-mlm'); ?></strong>
                                        <code><?php echo esc_html($entry['link_url']); ?></code>
                                    </p>
                                <?php endif; ?>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /* ------------------------------------------------------------------
     * Payload handling
     * ----------------------------------------------------------------*/

    /**
     * Pull the four user-supplied fields off $_POST into a
     * normalised array. Sanitisation here is "shape only"
     * (trim, strip control chars, force the audience enum); the
     * stricter rules (length, presence) live in
     * validate_payload() so the caller can render field-level
     * errors back into the form.
     */
    private function collect_payload_from_post() {
        $title    = isset($_POST['title'])    ? wp_unslash((string) $_POST['title'])    : '';
        $body     = isset($_POST['body'])     ? wp_unslash((string) $_POST['body'])     : '';
        $link_url = isset($_POST['link_url']) ? wp_unslash((string) $_POST['link_url']) : '';
        $audience = isset($_POST['audience']) ? sanitize_key((string) $_POST['audience']) : 'active';

        // Trim and strip tags. We don't run the body through
        // sanitize_textarea_field because that collapses
        // newlines in a way that breaks intentional paragraph
        // breaks; instead we strip HTML tags and let the
        // renderer handle whitespace via white-space:pre-wrap.
        $title    = trim(wp_strip_all_tags($title));
        $body     = trim(wp_strip_all_tags($body));
        $link_url = trim($link_url);

        // Audience enum guard. Anything else collapses to the
        // safer default ('active').
        if (!in_array($audience, ['active', 'all'], true)) {
            $audience = 'active';
        }

        return [
            'title'    => $title,
            'body'     => $body,
            'link_url' => $link_url,
            'audience' => $audience,
        ];
    }

    /**
     * Field-level validation. Returns an array of human-readable
     * error messages; empty array means OK.
     *
     * Link sanitisation is left to
     * Matrix_MLM_In_App_Notifications::sanitize_link() at insert
     * time — that helper enforces the same-host rule and silently
     * drops off-host URLs. We do an upfront warning here only for
     * obviously malformed input so the operator sees it on the
     * compose page rather than discovering at send time that
     * their link was dropped.
     */
    private function validate_payload(array $p) {
        $errors = [];

        if ($p['title'] === '') {
            $errors[] = __('Title is required.', 'matrix-mlm');
        } elseif (mb_strlen($p['title'], 'UTF-8') > self::TITLE_MAX) {
            $errors[] = sprintf(
                /* translators: %d: max title length */
                __('Title is too long (max %d characters).', 'matrix-mlm'),
                self::TITLE_MAX
            );
        }

        if ($p['body'] === '') {
            $errors[] = __('Message is required.', 'matrix-mlm');
        } elseif (mb_strlen($p['body'], 'UTF-8') > self::BODY_MAX) {
            $errors[] = sprintf(
                /* translators: %d: max body length */
                __('Message is too long (max %d characters).', 'matrix-mlm'),
                self::BODY_MAX
            );
        }

        if ($p['link_url'] !== '') {
            // Permit either a root-relative path (starts with "/"
            // but not "//") or a same-host absolute URL. The
            // notifications helper will re-validate at insert
            // time; this is a UX-level check so the operator
            // doesn't get a silently-dropped link.
            $is_relative = (strpos($p['link_url'], '/') === 0 && strpos($p['link_url'], '//') !== 0);
            if (!$is_relative) {
                $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
                $link_host = wp_parse_url($p['link_url'], PHP_URL_HOST);
                if (!$home_host || !$link_host || strcasecmp($home_host, $link_host) !== 0) {
                    $errors[] = __('Link URL must be a same-host URL or a path starting with "/". Off-site links are not allowed in announcements.', 'matrix-mlm');
                }
            }
        }

        return $errors;
    }

    /* ------------------------------------------------------------------
     * Recipient resolution + dispatch
     * ----------------------------------------------------------------*/

    /**
     * Fetch the user IDs the announcement will fan out to, based
     * on the chosen audience. Reads from matrix_user_meta so the
     * count matches the rest of the admin (Reports, Dashboard).
     *
     * @param string $audience 'active' or 'all'
     * @return int[]
     */
    private function fetch_target_user_ids($audience) {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_user_meta';

        if ($audience === 'all') {
            $rows = $wpdb->get_col("SELECT user_id FROM {$table}");
        } else {
            $rows = $wpdb->get_col("SELECT user_id FROM {$table} WHERE status = 'active'");
        }

        $ids = array_map('intval', (array) $rows);
        $ids = array_values(array_filter($ids, function ($v) {
            return $v > 0;
        }));
        return $ids;
    }

    /**
     * Run the actual fan-out and append a history entry. Returns
     * the dispatch result so the after-send view can show
     * recipient + attempted counts.
     *
     * Failure handling: enqueue_for_many() returns the count of
     * successful inserts; failures are already logged by the
     * In_App_Notifications class. We surface the gap (attempted
     * vs recipients) so an operator who sees "Delivered to 9,998
     * of 10,000" knows to check the error log without us
     * duplicating the per-row error text into our own log.
     */
    private function dispatch(array $payload) {
        $user_ids  = $this->fetch_target_user_ids($payload['audience']);
        $attempted = count($user_ids);

        $recipients = 0;
        if ($attempted > 0) {
            // Tag the meta with sender info so a future audit query
            // can answer "who sent this notification" without
            // joining against the option-based history. enqueue()
            // stores meta as JSON; the renderer ignores unknown
            // keys, so this is forward-compatible.
            $current = wp_get_current_user();
            $meta    = [
                'sender_id'    => (int) $current->ID,
                'sender_login' => (string) $current->user_login,
            ];

            $recipients = (int) Matrix_MLM_In_App_Notifications::enqueue_for_many(
                $user_ids,
                'admin_announcement',
                $payload['title'],
                $payload['body'],
                $payload['link_url'],
                $meta
            );
        }

        $entry = [
            'id'            => uniqid('ann_', true),
            'sent_at'       => current_time('mysql'),
            'sent_by_id'    => (int) get_current_user_id(),
            'sent_by_login' => (string) wp_get_current_user()->user_login,
            'audience'      => $payload['audience'],
            'title'         => $payload['title'],
            'body'          => $payload['body'],
            'link_url'      => $payload['link_url'],
            'recipients'    => $recipients,
            'attempted'     => $attempted,
        ];
        $this->append_history($entry);

        return $entry;
    }

    /* ------------------------------------------------------------------
     * History log (option-backed)
     * ----------------------------------------------------------------*/

    /**
     * Read the history log. Newest entry first.
     *
     * Defensive: a stored value that's not an array (corrupted
     * option, or a manual edit) is treated as empty rather than
     * surfaced as a fatal "expected array".
     */
    private function get_history() {
        $log = get_option(self::HISTORY_OPTION_KEY, []);
        if (!is_array($log)) {
            return [];
        }
        // Stored newest-first; trust the order from append_history().
        return $log;
    }

    /**
     * Prepend a new entry and trim to HISTORY_CAP. The cap is
     * applied last so a misconfigured CAP value (smaller than
     * the existing log) shrinks the log on the very next write
     * rather than letting it linger oversized.
     */
    private function append_history(array $entry) {
        $log = $this->get_history();
        array_unshift($log, $entry);
        if (count($log) > self::HISTORY_CAP) {
            $log = array_slice($log, 0, self::HISTORY_CAP);
        }
        update_option(self::HISTORY_OPTION_KEY, $log, false);
    }
}
