<?php
/**
 * User-facing Messaging tab.
 *
 * Render-only: all state changes go through Matrix_MLM_Messaging's AJAX
 * surface. Mirrors the user/Tickets render layout so the visual rhythm of
 * the dashboard stays consistent.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_User_Messaging {

    public function render($user_id) {
        if (!Matrix_MLM_Messaging::is_messaging_enabled()) {
            echo '<div class="matrix-alert matrix-alert-info">' . esc_html__('Messaging is currently disabled by the administrator.', 'matrix-mlm') . '</div>';
            return;
        }
        if ($banned = Matrix_MLM_Messaging::is_user_banned($user_id)) {
            echo '<div class="matrix-alert matrix-alert-danger">' . esc_html__('Your messaging access is suspended.', 'matrix-mlm') . '</div>';
            return;
        }

        // Lazy team-room self-heal (skeleton: cheap idempotent walk).
        Matrix_MLM_Messaging::self_heal_membership($user_id);

        // Initial presence pulse — render() runs on every messages-tab
        // load so this seeds last_seen before any AJAX has fired,
        // which means a user who lands on the page and immediately
        // walks away still counts as "online" for the next two
        // minutes (preventing a false-positive offline email for a
        // message that arrives mid-render).
        Matrix_MLM_Messaging::update_presence($user_id);

        $threads = Matrix_MLM_Messaging::list_threads_for_user($user_id);
        $settings = Matrix_MLM_Messaging::get_settings();

        wp_enqueue_style(
            'matrix-messaging',
            MATRIX_MLM_PLUGIN_URL . 'public/css/matrix-messaging.css',
            ['matrix-mlm-dashboard'],
            MATRIX_MLM_VERSION
        );
        wp_enqueue_script(
            'matrix-messaging',
            MATRIX_MLM_PLUGIN_URL . 'public/js/matrix-messaging.js',
            ['jquery'],
            MATRIX_MLM_VERSION,
            true
        );
        wp_localize_script('matrix-messaging', 'MatrixMessaging', [
            'ajaxUrl'           => admin_url('admin-ajax.php'),
            'nonce'             => wp_create_nonce('matrix_messaging'),
            'uploadNonce'       => wp_create_nonce('media-form'),
            'currentUserId'     => (int) $user_id,
            'pollingIntervalMs' => (int) $settings['polling_interval_ms'],
            'allowAttachments'  => !empty($settings['allow_attachments']),
            // Reaction palette (DB 1.0.21). Mirrors the server's
            // Matrix_MLM_Messaging::ALLOWED_REACTION_EMOJIS so the
            // picker stays in sync if an operator filters the
            // palette via matrix_messaging_allowed_reactions.
            'reactionPalette'   => Matrix_MLM_Messaging::ALLOWED_REACTION_EMOJIS,
            // Typing-beacon cadence (DB 1.0.23). Mirrors the
            // server's TYPING_BEACON_INTERVAL_MS so the
            // throttle window matches; keeping it in localized
            // config lets an operator change one place to
            // re-tune both ends.
            'typingBeaconMs'    => (int) Matrix_MLM_Messaging::TYPING_BEACON_INTERVAL_MS,
            'i18n'              => [
                'new_dm_prompt' => __('Username or referral code:', 'matrix-mlm'),
                'send'          => __('Send', 'matrix-mlm'),
                'reply_placeholder' => __('Write a message...', 'matrix-mlm'),
                'no_threads'    => __('No conversations yet. Start one with the New Message button.', 'matrix-mlm'),
                'select_thread' => __('Select a conversation, or start a new one.', 'matrix-mlm'),
                'search_min_chars'  => __('Type at least 2 characters to search.', 'matrix-mlm'),
                'search_searching'  => __('Searching…', 'matrix-mlm'),
                'search_no_results' => __('No messages match your search.', 'matrix-mlm'),
                'search_result_count' => __('%d match', 'matrix-mlm'),
                'search_result_count_plural' => __('%d matches', 'matrix-mlm'),
                'search_failed'     => __('Search failed. Please try again.', 'matrix-mlm'),
                // Read-receipt labels. Surface next to OWN messages
                // only — the receipt block above the rest of the
                // conversation tells you whether the other side has
                // caught up to your last message.
                'receipt_sent'         => __('Sent', 'matrix-mlm'),
                'receipt_seen'         => __('Seen', 'matrix-mlm'),
                /* translators: %s: human-readable read time, e.g. "3m" or "just now" */
                'receipt_seen_at'      => __('Seen %s', 'matrix-mlm'),
                /* translators: 1: read count, 2: total recipient count */
                'receipt_read_partial' => __('Read by %1$s / %2$s', 'matrix-mlm'),
                'receipt_read_all'     => __('Read', 'matrix-mlm'),
                'receipt_just_now'     => __('just now', 'matrix-mlm'),
                // Sender-side edit / self-delete (DB 1.0.20).
                'edit'             => __('Edit', 'matrix-mlm'),
                'delete'           => __('Delete', 'matrix-mlm'),
                'save'             => __('Save', 'matrix-mlm'),
                'cancel'           => __('Cancel', 'matrix-mlm'),
                'edit_empty'       => __('Message cannot be empty. Use Delete instead.', 'matrix-mlm'),
                'edit_failed'      => __('Edit failed. The 5-minute window may have expired.', 'matrix-mlm'),
                'delete_failed'    => __('Delete failed. The 5-minute window may have expired.', 'matrix-mlm'),
                'confirm_delete'   => __('Delete this message? This cannot be undone.', 'matrix-mlm'),
                'message_deleted'  => __('(message deleted)', 'matrix-mlm'),
                'flag_edited'      => __('(edited)', 'matrix-mlm'),
                'flag_stripped'    => __('stripped', 'matrix-mlm'),
                // Reactions (DB 1.0.21).
                'add_reaction'     => __('Add reaction', 'matrix-mlm'),
                'reaction_add'     => __('Add this reaction', 'matrix-mlm'),
                'reaction_remove'  => __('Remove your reaction', 'matrix-mlm'),
                'attach'           => __('Attach image', 'matrix-mlm'),
                'attach_too_large' => __('File too large. Maximum %s MB.', 'matrix-mlm'),
                'attach_too_many'  => __('Maximum %d attachments per message.', 'matrix-mlm'),
                'upload_failed'    => __('Upload failed. Please try again.', 'matrix-mlm'),
                // Older-message pagination.
                'load_older'       => __('Load older messages', 'matrix-mlm'),
                // Mute / block popovers.
                'thread_options'   => __('Conversation options', 'matrix-mlm'),
                'mute_thread'      => __('Mute conversation', 'matrix-mlm'),
                'mute_for'         => __('Mute for…', 'matrix-mlm'),
                'mute_1h'          => __('1 hour', 'matrix-mlm'),
                'mute_8h'          => __('8 hours', 'matrix-mlm'),
                'mute_24h'         => __('24 hours', 'matrix-mlm'),
                'mute_7d'          => __('1 week', 'matrix-mlm'),
                'mute_30d'         => __('30 days', 'matrix-mlm'),
                'unmute'           => __('Unmute', 'matrix-mlm'),
                'mute_failed'      => __('Could not change mute state.', 'matrix-mlm'),
                'block_user'       => __('Block user', 'matrix-mlm'),
                'unblock_user'     => __('Unblock user', 'matrix-mlm'),
                'confirm_block'    => __('Block this user? They will no longer be able to message you and you will not be able to message them.', 'matrix-mlm'),
                'block_failed'     => __('Could not change block state.', 'matrix-mlm'),
                // Members panel + self-leave (DB 1.0.22).
                'members_button'      => __('Members', 'matrix-mlm'),
                'members_panel_title' => __('Members', 'matrix-mlm'),
                'members_loading'     => __('Loading members…', 'matrix-mlm'),
                'members_load_failed' => __('Could not load members.', 'matrix-mlm'),
                'members_role_owner'  => __('owner', 'matrix-mlm'),
                'members_role_member' => __('member', 'matrix-mlm'),
                'members_you'         => __('you', 'matrix-mlm'),
                /* translators: %d: number of active members in the team room. */
                'members_count'       => __('%d members', 'matrix-mlm'),
                'leave_thread'        => __('Leave thread', 'matrix-mlm'),
                'confirm_leave'       => __('Leave this team room? You will stop receiving messages here. Your sponsor will not be able to add you back automatically — you would have to be re-invited.', 'matrix-mlm'),
                'leave_failed'        => __('Could not leave the thread.', 'matrix-mlm'),
                // Typing indicators (DB 1.0.23 — transient-backed).
                /* translators: %s: display name of the single user typing. */
                'typing_one'          => __('%s is typing…', 'matrix-mlm'),
                /* translators: 1: first display name, 2: second display name. */
                'typing_two'          => __('%1$s and %2$s are typing…', 'matrix-mlm'),
                /* translators: 1: first display name, 2: second display name, 3: third display name. */
                'typing_three'        => __('%1$s, %2$s, and %3$s are typing…', 'matrix-mlm'),
                /* translators: %s: number of users typing (4 or more). */
                'typing_many'         => __('%s people are typing…', 'matrix-mlm'),
            ],
        ]);
        ?>
        <h2><?php esc_html_e('Messages', 'matrix-mlm'); ?></h2>

        <div class="matrix-messaging" data-current-user="<?php echo (int) $user_id; ?>">
            <aside class="matrix-messaging__sidebar">
                <div class="matrix-messaging__sidebar-header">
                    <button type="button" class="matrix-btn matrix-btn-primary matrix-btn-sm" id="matrix-messaging-new-dm">
                        <?php esc_html_e('New Message', 'matrix-mlm'); ?>
                    </button>
                    <div class="matrix-messaging__search">
                        <input
                            type="search"
                            id="matrix-messaging-search-input"
                            class="matrix-messaging__search-input"
                            placeholder="<?php esc_attr_e('Search messages…', 'matrix-mlm'); ?>"
                            autocomplete="off"
                            aria-label="<?php esc_attr_e('Search messages across all conversations', 'matrix-mlm'); ?>">
                        <button
                            type="button"
                            id="matrix-messaging-search-clear"
                            class="matrix-messaging__search-clear"
                            aria-label="<?php esc_attr_e('Clear search', 'matrix-mlm'); ?>"
                            style="display:none;">&times;</button>
                    </div>
                </div>
                <ul class="matrix-messaging__threads" id="matrix-messaging-threads">
                    <?php if (empty($threads)): ?>
                        <li class="matrix-messaging__empty"><?php esc_html_e('No conversations yet.', 'matrix-mlm'); ?></li>
                    <?php else: foreach ($threads as $t): ?>
                        <li class="matrix-messaging__thread" data-thread-id="<?php echo (int) $t->id; ?>" data-type="<?php echo esc_attr($t->type); ?>">
                            <div class="matrix-messaging__thread-label">
                                <?php if ($t->type === 'team_room'): ?>
                                    <span class="dashicons dashicons-groups"></span>
                                <?php else: ?>
                                    <span class="dashicons dashicons-admin-users"></span>
                                <?php endif; ?>
                                <strong><?php echo esc_html($t->display_label); ?></strong>
                            </div>
                            <div class="matrix-messaging__thread-meta">
                                <?php if ((int) $t->unread_count > 0): ?>
                                    <span class="matrix-badge matrix-badge-info"><?php echo (int) $t->unread_count; ?></span>
                                <?php endif; ?>
                                <?php if (!empty($t->muted_until)): ?>
                                    <span class="dashicons dashicons-bell" title="<?php esc_attr_e('Muted', 'matrix-mlm'); ?>"></span>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; endif; ?>
                </ul>
                <ul class="matrix-messaging__search-results" id="matrix-messaging-search-results" style="display:none;" aria-live="polite"></ul>
            </aside>

            <section class="matrix-messaging__pane" id="matrix-messaging-pane">
                <div class="matrix-messaging__placeholder">
                    <?php esc_html_e('Select a conversation, or start a new one.', 'matrix-mlm'); ?>
                </div>
            </section>
        </div>
        <?php
    }
}
