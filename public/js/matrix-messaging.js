/* Matrix Messaging — polling-based 1:1 + team room client.
 *
 * Behavior:
 *   - On thread click: GET fetch_thread with after_id=0, render full pane,
 *     start poll timer.
 *   - Poll tick: GET fetch_thread with after_id=lastId, append new
 *     messages only.
 *   - Send: POST send, optimistic UI append on success, fall through to
 *     next poll tick to canonicalize.
 *   - New DM: prompt for username/referral_code, POST open_dm, then load.
 *
 * Anti-double-render: messages keyed by id in a Set; appends ignore ids
 * already rendered. This keeps the optimistic add + poll's authoritative
 * row from rendering twice.
 *
 * Sender-side edit / self-delete (DB 1.0.20 / plugin 2.0.3):
 *   - The fetch_thread response carries editable_until (mysql GMT) per
 *     own-message row plus an edit_window_seconds top-level value.
 *   - The render attaches data-editable-until to each own-message DOM
 *     node. The pencil / trash buttons render inside the bubble; a
 *     low-cadence sweeper hides them once the window expires so a
 *     stale tab can't fire an edit AJAX the server would reject.
 *   - Edit mode swaps the body for an inline textarea + Save/Cancel.
 *     On Save the response carries the canonical post-edit row, which
 *     is rerendered in place.
 *
 * Older-message pagination (DB 1.0.20 / plugin 2.0.3):
 *   - The first fetch_thread page returns up to 50 rows. A "Load older"
 *     pill at the top of the message list calls fetch_older with
 *     before_id = smallest rendered id, prepends the returned rows
 *     (preserving scroll position so the user's reading position
 *     doesn't jump), and hides itself once the server reports
 *     has_more = false.
 *
 * Mute / block UI (DB 1.0.20 / plugin 2.0.3):
 *   - The existing #matrix-messaging-mute button now opens a small
 *     dropdown of preset durations. The choice is mapped to hours and
 *     POSTed to the existing matrix_messaging_mute endpoint.
 *   - DM threads grow a kebab menu with Block / Unblock entries.
 *     Block/unblock state is read from the thread_state on every
 *     fetch_thread tick so a control update from another tab
 *     converges within one poll.
 */

(function ($) {
    'use strict';

    if (typeof window.MatrixMessaging === 'undefined') {
        return;
    }

    var cfg = window.MatrixMessaging;
    var $threadsList = $('#matrix-messaging-threads');
    var $pane = $('#matrix-messaging-pane');
    var $newDmBtn = $('#matrix-messaging-new-dm');
    var $newRoomBtn = $('#matrix-messaging-new-room');
    var $searchInput = $('#matrix-messaging-search-input');
    var $searchClear = $('#matrix-messaging-search-clear');
    var $searchResults = $('#matrix-messaging-search-results');

    var activeThreadId = null;
    var activeThreadType = 'dm';
    var renderedIds = Object.create(null);
    var lastId = 0;
    // Smallest rendered id, used as the before_id anchor for the
    // "Load older" pagination. Initialised to 0 (sentinel for "no
    // older pages possible") and updated each time a message is
    // rendered, taking the minimum.
    var oldestId = 0;
    // Server-driven flag: true while older history is reachable.
    // Set to false when fetch_older returns has_more=false so the
    // affordance stops nagging the user once they've reached the
    // start of the thread.
    var hasMoreOlder = true;
    var olderFetchInFlight = false;
    var pollTimer = null;
    // Edit-window sweeper. Runs at low cadence to expire the
    // edit/delete affordances on already-rendered own messages
    // without firing a network round-trip per tick. Cleared on
    // thread switch.
    var editWindowSweepTimer = null;
    // Server-supplied edit window (seconds). Cached locally so the
    // optimistic editable_until for messages the server hasn't
    // hydrated yet (e.g. a polled-in row in a future protocol
    // mismatch) still has a sensible default.
    var editWindowSeconds = 300;
    // Per-thread chrome state (mute / block) carried over from
    // the most recent fetch_thread response. Drives the bell
    // icon's strikethrough state and the kebab's Block/Unblock
    // label without an extra round-trip.
    var threadState = {
        is_muted: false,
        muted_until: null,
        other_user_id: 0,
        is_blocked: false
    };

    // Search state. searchSeq is incremented on each new query so a
    // late-arriving response from a stale request can't overwrite a
    // newer result set; debounceTimer collapses fast typing into a
    // single request burst.
    var searchSeq = 0;
    var searchDebounceTimer = null;
    var pendingHighlightMessageId = null;

    function api(action, data, method) {
        method = method || 'POST';
        var payload = $.extend({ action: 'matrix_messaging_' + action, nonce: cfg.nonce }, data || {});
        return $.ajax({ url: cfg.ajaxUrl, method: method, data: payload, dataType: 'json' });
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /**
     * Coerce a server mysql GMT string ("2026-05-26 14:32:01") into
     * a JS Date by appending the Z marker — every server-side
     * timestamp in this module is written with current_time('mysql', true),
     * so the UTC interpretation is correct. Returns null on
     * unparseable input rather than NaN-shaped Date so callers can
     * gate without isNaN tests.
     */
    function parseServerDateUtc(s) {
        if (!s) { return null; }
        var d = new Date(String(s).replace(' ', 'T') + 'Z');
        return isNaN(d.getTime()) ? null : d;
    }

    function renderPane(threadLabel, threadType) {
        var attachBtn = cfg.allowAttachments
            ? '<label class="matrix-messaging__attach-btn" title="' + escapeHtml(cfg.i18n.attach || 'Attach image') + '">' +
                  '<input type="file" name="attachment" accept="image/*" multiple style="display:none">' +
                  '<span class="dashicons dashicons-format-image"></span>' +
              '</label>'
            : '';

        // Kebab dropdown is DM-only. Team-room block / unblock
        // doesn't make sense (the membership comes from sponsor
        // graph; blocking a teammate would mute the whole room
        // for that pair across team-room broadcasts and create
        // cross-cutting moderation invariants we explicitly
        // declined to widen the scope to support).
        var kebabHtml = '';
        if (threadType === 'dm') {
            kebabHtml =
                '<div class="matrix-messaging__menu-wrap">' +
                    '<button type="button" class="button button-small matrix-messaging__kebab" id="matrix-messaging-kebab" aria-haspopup="true" aria-expanded="false" title="' + escapeHtml(cfg.i18n.thread_options || 'Conversation options') + '">' +
                        '<span class="dashicons dashicons-ellipsis"></span>' +
                    '</button>' +
                    '<ul class="matrix-messaging__menu" id="matrix-messaging-kebab-menu" role="menu" hidden>' +
                        '<li role="menuitem" class="matrix-messaging__menu-item" data-action="block">' + escapeHtml(cfg.i18n.block_user || 'Block user') + '</li>' +
                        '<li role="menuitem" class="matrix-messaging__menu-item" data-action="unblock" hidden>' + escapeHtml(cfg.i18n.unblock_user || 'Unblock user') + '</li>' +
                    '</ul>' +
                '</div>';
        }

        // Mute popover. Renders as a dropdown anchored to the bell
        // button. Choices are duration presets, mapped to hours
        // server-side (1h/8h/24h/7d/30d). "Unmute" is the explicit
        // 0-hours path.
        var mutePopoverHtml =
            '<div class="matrix-messaging__menu-wrap">' +
                '<button type="button" class="button button-small matrix-messaging__mute-btn" id="matrix-messaging-mute" aria-haspopup="true" aria-expanded="false" title="' + escapeHtml(cfg.i18n.mute_thread || 'Mute conversation') + '">' +
                    '<span class="dashicons dashicons-bell"></span>' +
                '</button>' +
                '<ul class="matrix-messaging__menu" id="matrix-messaging-mute-menu" role="menu" hidden>' +
                    '<li class="matrix-messaging__menu-header">' + escapeHtml(cfg.i18n.mute_for || 'Mute for…') + '</li>' +
                    '<li role="menuitem" class="matrix-messaging__menu-item" data-hours="1">' + escapeHtml(cfg.i18n.mute_1h || '1 hour') + '</li>' +
                    '<li role="menuitem" class="matrix-messaging__menu-item" data-hours="8">' + escapeHtml(cfg.i18n.mute_8h || '8 hours') + '</li>' +
                    '<li role="menuitem" class="matrix-messaging__menu-item" data-hours="24">' + escapeHtml(cfg.i18n.mute_24h || '24 hours') + '</li>' +
                    '<li role="menuitem" class="matrix-messaging__menu-item" data-hours="168">' + escapeHtml(cfg.i18n.mute_7d || '1 week') + '</li>' +
                    '<li role="menuitem" class="matrix-messaging__menu-item" data-hours="720">' + escapeHtml(cfg.i18n.mute_30d || '30 days') + '</li>' +
                    '<li role="menuitem" class="matrix-messaging__menu-item matrix-messaging__menu-item--unmute" data-hours="0" hidden>' + escapeHtml(cfg.i18n.unmute || 'Unmute') + '</li>' +
                '</ul>' +
            '</div>';

        // Members affordance is team-room only. Renders as a
        // separate button (not a kebab entry) because the panel
        // is richer than the existing menu items — it pulls a
        // roster on click and shows a Leave button at the top.
        // Hidden DOM is rendered alongside the trigger; the
        // panel is populated lazily on first open and refreshed
        // each subsequent open so a sponsor / admin add-or-
        // remove from another tab is reflected next time the
        // user opens the panel.
        var membersHtml = '';
        if (threadType === 'team_room') {
            membersHtml =
                '<div class="matrix-messaging__menu-wrap">' +
                    '<button type="button" class="button button-small matrix-messaging__members-btn" id="matrix-messaging-members" aria-haspopup="true" aria-expanded="false" title="' + escapeHtml(cfg.i18n.members_panel_title || 'Members') + '">' +
                        '<span class="dashicons dashicons-groups"></span>' +
                        '<span class="matrix-messaging__members-label">' + escapeHtml(cfg.i18n.members_button || 'Members') + '</span>' +
                    '</button>' +
                    '<div class="matrix-messaging__menu matrix-messaging__members-panel" id="matrix-messaging-members-panel" role="dialog" aria-label="' + escapeHtml(cfg.i18n.members_panel_title || 'Members') + '" hidden>' +
                        '<div class="matrix-messaging__members-panel-header">' +
                            '<strong>' + escapeHtml(cfg.i18n.members_panel_title || 'Members') + '</strong>' +
                            '<button type="button" class="button button-small matrix-messaging__invite-btn" id="matrix-messaging-invite" style="display:none;">' +
                                escapeHtml(cfg.i18n.invite_members || 'Invite members') +
                            '</button>' +
                            '<button type="button" class="button button-small button-link-delete matrix-messaging__leave-btn" id="matrix-messaging-leave">' +
                                escapeHtml(cfg.i18n.leave_thread || 'Leave thread') +
                            '</button>' +
                        '</div>' +
                        '<ul class="matrix-messaging__members-list" id="matrix-messaging-members-list" aria-live="polite">' +
                            '<li class="matrix-messaging__members-empty">' + escapeHtml(cfg.i18n.members_loading || 'Loading members…') + '</li>' +
                        '</ul>' +
                    '</div>' +
                '</div>';
        }

        $pane.html(
            '<div class="matrix-messaging__pane-header">' +
                '<strong>' + escapeHtml(threadLabel) + '</strong>' +
                '<div class="matrix-messaging__pane-controls">' +
                    membersHtml +
                    mutePopoverHtml +
                    kebabHtml +
                '</div>' +
            '</div>' +
            '<div class="matrix-messaging__messages" id="matrix-messaging-messages">' +
                '<button type="button" class="matrix-messaging__load-older" id="matrix-messaging-load-older" hidden>' +
                    escapeHtml(cfg.i18n.load_older || 'Load older messages') +
                '</button>' +
            '</div>' +
            // Typing indicator slot. Lives between the messages
            // list and the composer so it doesn't get scrolled
            // out of view by new incoming messages, and stays
            // visible while the user composes their reply.
            // Hidden by default until applyTypingState flips it
            // via .toggle(); .matrix-messaging__typing-text gets
            // its label rewritten on every poll.
            '<div class="matrix-messaging__typing" id="matrix-messaging-typing" aria-live="polite" hidden>' +
                '<span class="matrix-messaging__typing-dot"></span>' +
                '<span class="matrix-messaging__typing-dot"></span>' +
                '<span class="matrix-messaging__typing-dot"></span>' +
                '<span class="matrix-messaging__typing-text" id="matrix-messaging-typing-text"></span>' +
            '</div>' +
            '<form class="matrix-messaging__composer" id="matrix-messaging-composer" enctype="multipart/form-data">' +
                '<div class="matrix-messaging__composer-preview matrix-messaging__composer-preview--multi" id="matrix-messaging-preview" style="display:none"></div>' +
                '<div class="matrix-messaging__composer-row">' +
                    attachBtn +
                    '<textarea name="body" placeholder="' + escapeHtml(cfg.i18n.reply_placeholder) + '"></textarea>' +
                    '<button type="submit" class="matrix-btn matrix-btn-primary">' + escapeHtml(cfg.i18n.send) + '</button>' +
                '</div>' +
                // Multi-attachment slot (DB 1.0.23). Each successful
                // upload pushes an <input type="hidden" name="attachment_ids[]" value="N">
                // into this container; submit serializes them all.
                // The legacy single attachment_id input is no longer
                // emitted — the server still accepts it as a
                // fallback, but we always go through the array shape.
                '<div class="matrix-messaging__composer-attachments" id="matrix-messaging-attachment-inputs"></div>' +
            '</form>'
        );
    }

    /**
     * Build the inner HTML of a message bubble. Centralised so
     * that re-render after edit / delete uses exactly the same
     * shape as initial render — avoiding a class of "the row
     * looks slightly different after edit" bugs.
     */
    function buildMessageInnerHtml(msg) {
        var deleted = !!(msg.deleted_at || msg.is_deleted);
        var own = parseInt(msg.sender_id, 10) === parseInt(cfg.currentUserId, 10);

        var bodyHtml = deleted
            ? '<em>' + escapeHtml(cfg.i18n.message_deleted || '(message deleted)') + '</em>'
            : escapeHtml(msg.body || '').replace(/\n/g, '<br>');

        var attachHtml = '';
        // Multi-attachment render (DB 1.0.23). Prefer the new
        // attachments[] array; fall back to legacy attachment_url
        // for any cached / stale message row that still uses the
        // single-slot wire shape. The grid auto-sizes from the
        // count via .matrix-messaging__message-attachments--N
        // modifier classes — the CSS does the actual layout.
        if (!deleted) {
            var atts = (msg.attachments && msg.attachments.length) ? msg.attachments : null;
            if (!atts && msg.attachment_url) {
                atts = [{ url: msg.attachment_url, thumb_url: msg.attachment_url, id: msg.attachment_id || 0 }];
            }
            if (atts && atts.length) {
                var count = atts.length;
                var modifier = count >= 4 ? 4 : count;
                var inner = '';
                atts.forEach(function (a) {
                    var fullUrl = a.url || a.thumb_url || '';
                    var thumb   = a.thumb_url || a.url || '';
                    inner +=
                        '<a class="matrix-messaging__message-attachment" href="' + escapeHtml(fullUrl) + '" target="_blank" rel="noopener">' +
                            '<img src="' + escapeHtml(thumb) + '" alt="attachment" loading="lazy">' +
                        '</a>';
                });
                attachHtml =
                    '<div class="matrix-messaging__message-attachments matrix-messaging__message-attachments--' + modifier + '">' +
                        inner +
                    '</div>';
            }
        }

        var strippedFlag = (!deleted && parseInt(msg.body_stripped, 10) === 1)
            ? ' <span class="matrix-messaging__message-stripped-flag">' + escapeHtml(cfg.i18n.flag_stripped || 'stripped') + '</span>'
            : '';
        var editedFlag = (!deleted && (msg.edited_at || parseInt(msg.is_edited, 10) === 1))
            ? ' <span class="matrix-messaging__message-edited-flag">' + escapeHtml(cfg.i18n.flag_edited || '(edited)') + '</span>'
            : '';

        // Edit / delete affordances. Rendered only on own,
        // non-deleted messages whose editable_until is in the
        // future. The editWindowSweep tick later hides them once
        // the timestamp passes; checking here too avoids a brief
        // flash for messages that arrive in a fetch_thread
        // response after their window has already expired (e.g.
        // re-opening a thread with stale messages).
        var actionsHtml = '';
        if (own && !deleted) {
            var until = parseServerDateUtc(msg.editable_until);
            var inWindow = until && until.getTime() > Date.now();
            if (inWindow) {
                actionsHtml =
                    '<div class="matrix-messaging__message-actions">' +
                        '<button type="button" class="matrix-messaging__msg-action" data-msg-action="edit" title="' + escapeHtml(cfg.i18n.edit || 'Edit') + '" aria-label="' + escapeHtml(cfg.i18n.edit || 'Edit') + '">' +
                            '<span class="dashicons dashicons-edit"></span>' +
                        '</button>' +
                        '<button type="button" class="matrix-messaging__msg-action" data-msg-action="delete" title="' + escapeHtml(cfg.i18n.delete || 'Delete') + '" aria-label="' + escapeHtml(cfg.i18n.delete || 'Delete') + '">' +
                            '<span class="dashicons dashicons-trash"></span>' +
                        '</button>' +
                    '</div>';
            }
        }

        return actionsHtml +
            attachHtml +
            '<div class="matrix-messaging__message-body">' + bodyHtml + '</div>' +
            '<div class="matrix-messaging__message-meta">' +
                escapeHtml(msg.created_at || '') + strippedFlag + editedFlag +
            '</div>' +
            // Read-receipt slot, only on own messages. The
            // server populates it on the next fetch_thread tick
            // via the receipts map; we leave it empty initially
            // (no flicker between optimistic add and the first
            // poll's authoritative state).
            (own && !deleted ? '<div class="matrix-messaging__message-receipt" data-receipt-for="' + parseInt(msg.id, 10) + '"></div>' : '') +
            // Reactions slot (DB 1.0.21). Always present on
            // non-deleted rows so applyReactions() can write
            // into it without re-rendering the bubble. Hidden
            // (display:none via empty content) until the next
            // poll tick lands a non-empty map.
            (!deleted ? '<div class="matrix-messaging__message-reactions" data-reactions-for="' + parseInt(msg.id, 10) + '"></div>' : '') +
            // React-button trigger. On hover the bubble surfaces
            // it (CSS); clicking pops the picker via the global
            // delegate further down. Suppressed on deleted rows.
            (!deleted ? '<button type="button" class="matrix-messaging__msg-react-trigger" data-msg-react="' + parseInt(msg.id, 10) + '" title="' + escapeHtml(cfg.i18n.add_reaction || 'Add reaction') + '" aria-label="' + escapeHtml(cfg.i18n.add_reaction || 'Add reaction') + '">+&#x1F642;</button>' : '');
    }

    /**
     * Apply / refresh the wrapper-level classes and data-* on a
     * message bubble. Used by both the initial render path and the
     * post-edit / post-delete in-place rerender so the wrapper
     * state stays in lockstep with the content.
     */
    function applyMessageWrapperState($wrap, msg) {
        var deleted = !!(msg.deleted_at || msg.is_deleted);
        var own = parseInt(msg.sender_id, 10) === parseInt(cfg.currentUserId, 10);
        $wrap.removeClass('is-deleted is-edited is-own');
        if (own) { $wrap.addClass('is-own'); }
        if (deleted) { $wrap.addClass('is-deleted'); }
        if (msg.edited_at || parseInt(msg.is_edited, 10) === 1) { $wrap.addClass('is-edited'); }
        $wrap.attr('data-id', parseInt(msg.id, 10));
        if (msg.editable_until) {
            $wrap.attr('data-editable-until', String(msg.editable_until));
        } else {
            $wrap.removeAttr('data-editable-until');
        }
    }

    function renderMessage($container, msg, prepend) {
        if (renderedIds[msg.id]) { return; }
        renderedIds[msg.id] = true;
        var idNum = parseInt(msg.id, 10);
        if (idNum > lastId) { lastId = idNum; }
        // oldestId tracks the smallest rendered id for the
        // pagination anchor. 0 means "no anchor yet" so any
        // non-zero id is smaller for the purposes of the first
        // assignment.
        if (oldestId === 0 || idNum < oldestId) { oldestId = idNum; }

        var $wrap = $('<div class="matrix-messaging__message"></div>');
        applyMessageWrapperState($wrap, msg);
        $wrap.html(buildMessageInnerHtml(msg));

        if (prepend) {
            // Prepend after the "Load older" button so it stays
            // the first child. find().first().after() would
            // require the button to be present; we anchor on the
            // button explicitly.
            var $btn = $container.find('#matrix-messaging-load-older');
            if ($btn.length) {
                $btn.after($wrap);
            } else {
                $container.prepend($wrap);
            }
        } else {
            $container.append($wrap);
            // Auto-scroll only on append (new bottom-of-feed
            // message); a prepend is a "Load older" path where the
            // user is reading historical context and would lose
            // their place if we yanked the scroll.
            if ($container.length) { $container.scrollTop($container[0].scrollHeight); }
        }
    }

    function loadThread(threadId, label) {
        stopPolling();
        stopEditWindowSweep();
        // Stop any in-flight typing beacon for the previous
        // thread; we do NOT notify the server because the
        // outgoing AJAX races with the navigation away from the
        // thread and the server-side TTL will expire it within
        // one window anyway. A beacon with notify=true would
        // also be wasted since the user is no longer composing
        // there.
        stopTypingBeacon(false);
        activeThreadId = threadId;
        renderedIds = Object.create(null);
        lastId = 0;
        oldestId = 0;
        hasMoreOlder = true;

        // Render with a placeholder thread type — the first
        // fetch_thread response will overwrite it before any
        // chrome that branches on type renders.
        renderPane(label, 'dm');
        var $msgs = $('#matrix-messaging-messages');

        api('fetch_thread', { thread_id: threadId, after_id: 0 }).done(function (resp) {
            if (!resp || !resp.success) { return; }
            var data = resp.data || {};
            activeThreadType = data.thread_type || 'dm';
            if (typeof data.edit_window_seconds !== 'undefined') {
                editWindowSeconds = parseInt(data.edit_window_seconds, 10) || 300;
            }
            // Re-render the chrome with the canonical thread
            // type so the kebab (DM-only) appears / disappears
            // before the user has a chance to click anywhere.
            renderPane(label, activeThreadType);
            $msgs = $('#matrix-messaging-messages');
            // Reset the renderedIds set because we threw the
            // pane away above; the upcoming forEach renders
            // every message fresh.
            renderedIds = Object.create(null);
            lastId = 0;
            oldestId = 0;

            (data.messages || []).forEach(function (m) { renderMessage($msgs, m, false); });
            applyReceipts(data.receipts, activeThreadType);
            applyReactions(data.reactions);
            applyThreadState(data.thread_state);
            applyTypingState(data.typing);
            // hasMoreOlder defaults to true; the first
            // fetch_older click resolves it. If the initial
            // page came back with fewer than 50 rows we know
            // there's no older history at all and can hide the
            // affordance immediately.
            hasMoreOlder = (data.messages || []).length >= 50;
            updateLoadOlderButton();

            startPolling();
            startEditWindowSweep();

            // Scroll to and flash a search-jumped message, if one
            // was queued by handleSearchResultClick. Done after the
            // initial fetch completes so the row exists in the DOM.
            if (pendingHighlightMessageId) {
                var targetId = pendingHighlightMessageId;
                pendingHighlightMessageId = null;
                var $target = $msgs.find('.matrix-messaging__message[data-id="' + targetId + '"]');
                if ($target.length) {
                    $target[0].scrollIntoView({ block: 'center', behavior: 'smooth' });
                    $target.addClass('matrix-messaging__message--flash');
                    setTimeout(function () { $target.removeClass('matrix-messaging__message--flash'); }, 2400);
                }
            }
        });
    }

    function startPolling() {
        stopPolling();
        pollTimer = setInterval(function () {
            if (!activeThreadId || document.hidden) { return; }
            api('fetch_thread', { thread_id: activeThreadId, after_id: lastId }).done(function (resp) {
                if (!resp || !resp.success) { return; }
                var data = resp.data || {};
                if (typeof data.edit_window_seconds !== 'undefined') {
                    editWindowSeconds = parseInt(data.edit_window_seconds, 10) || 300;
                }
                if (data.thread_type) { activeThreadType = data.thread_type; }
                var $msgs = $('#matrix-messaging-messages');
                (data.messages || []).forEach(function (m) { renderMessage($msgs, m, false); });
                applyReceipts(data.receipts, activeThreadType);
                applyReactions(data.reactions);
                applyThreadState(data.thread_state);
                applyTypingState(data.typing);
            });
        }, Math.max(2000, parseInt(cfg.pollingIntervalMs, 10) || 10000));
    }

    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    /**
     * Sweep already-rendered own messages and hide the
     * edit/delete affordances on rows whose editable_until has
     * passed. Runs on a 15-second cadence — fast enough that the
     * user doesn't see a stale Edit button for long after the
     * window expires, slow enough that it's invisible to the
     * profile.
     *
     * Idempotent: the .remove() on the actions block has no
     * effect if it's already gone, and we never re-add the block
     * on a row that's been swept (the next render of that
     * specific row would only happen via a server response that
     * already gates editable_until).
     */
    function startEditWindowSweep() {
        stopEditWindowSweep();
        editWindowSweepTimer = setInterval(function () {
            sweepEditWindowOnce();
        }, 15000);
    }
    function stopEditWindowSweep() {
        if (editWindowSweepTimer) {
            clearInterval(editWindowSweepTimer);
            editWindowSweepTimer = null;
        }
    }
    function sweepEditWindowOnce() {
        var now = Date.now();
        $('.matrix-messaging__message[data-editable-until]').each(function () {
            var $row = $(this);
            var until = parseServerDateUtc($row.attr('data-editable-until'));
            if (until && until.getTime() <= now) {
                $row.find('.matrix-messaging__message-actions').remove();
                $row.removeAttr('data-editable-until');
            }
        });
    }

    /**
     * Render the receipts map onto already-rendered own messages.
     *
     * Called after every fetch_thread response. Receipts are
     * server-computed for the most recent N own messages; missing
     * entries (older messages outside the lookback window) leave
     * the slot empty.
     *
     * Threading model:
     *   - DM (recipient_count == 1): "Sent" if not yet read, "Seen
     *     <human-time>" once the other party has read up to or
     *     past this message.
     *   - team_room: "Read by N / M" while partial, just "Read"
     *     when everyone has caught up. Hidden when read_count is
     *     zero so the chrome stays quiet for a freshly-sent
     *     message in a quiet room.
     *
     * Idempotent: re-running with the same receipts is a no-op
     * (.text() is set to the same string).
     */
    function applyReceipts(receipts, threadType) {
        if (!receipts || typeof receipts !== 'object') { return; }
        var isDm = threadType === 'dm';
        Object.keys(receipts).forEach(function (mid) {
            var info = receipts[mid] || {};
            var $slot = $('.matrix-messaging__message-receipt[data-receipt-for="' + parseInt(mid, 10) + '"]');
            if (!$slot.length) { return; }
            var rc = parseInt(info.read_count, 10) || 0;
            var total = parseInt(info.recipient_count, 10) || 0;
            var label = '';

            if (isDm) {
                // Single recipient. "Sent" pre-read, "Seen" post-read.
                if (rc >= 1) {
                    label = info.last_read_at
                        ? formatString(cfg.i18n.receipt_seen_at || 'Seen %s', formatReadTime(info.last_read_at))
                        : (cfg.i18n.receipt_seen || 'Seen');
                } else if (total > 0) {
                    label = cfg.i18n.receipt_sent || 'Sent';
                }
            } else {
                // Team room. Stay quiet while no one has read it,
                // pluralise once partial, collapse to "Read" once
                // everyone has caught up.
                if (total === 0) {
                    label = '';
                } else if (rc === 0) {
                    label = cfg.i18n.receipt_sent || 'Sent';
                } else if (rc >= total) {
                    label = cfg.i18n.receipt_read_all || 'Read';
                } else {
                    label = formatString(cfg.i18n.receipt_read_partial || 'Read by %1$s / %2$s', rc, total);
                }
            }

            $slot.text(label).toggle(label !== '');
        });
    }

    /**
     * Apply the reactions map (DB 1.0.21) from a fetch_thread
     * response to every message bubble in the recent window.
     *
     * Wire shape: { message_id: { emoji: { count, mine } } }.
     * We render a chip per emoji with its count, and toggle a
     * .is-mine class when the current user is among the
     * reactors so the chip can self-highlight without an extra
     * SELECT. Empty messages get the slot cleared so a removed
     * reaction collapses cleanly.
     */
    function applyReactions(reactions) {
        if (!reactions || typeof reactions !== 'object') { return; }
        // Clear every reactions slot first, so a message whose
        // last reaction just got removed by another tab loses
        // the chips on the next tick. Cheap — at most 50
        // (RECEIPT_LOOKBACK) elements on the page.
        $('.matrix-messaging__message-reactions').empty();

        Object.keys(reactions).forEach(function (mid) {
            var emojis = reactions[mid] || {};
            var $slot  = $('.matrix-messaging__message-reactions[data-reactions-for="' + parseInt(mid, 10) + '"]');
            if (!$slot.length) { return; }
            Object.keys(emojis).forEach(function (e) {
                var info = emojis[e] || {};
                var count = parseInt(info.count, 10) || 0;
                if (count <= 0) { return; }
                var mine = parseInt(info.mine, 10) === 1;
                var $chip = $('<button type="button" class="matrix-messaging__reaction-chip"></button>')
                    .toggleClass('is-mine', mine)
                    .attr('data-msg-id', parseInt(mid, 10))
                    .attr('data-emoji', e)
                    .attr('title', mine
                        ? (cfg.i18n.reaction_remove || 'Remove your reaction')
                        : (cfg.i18n.reaction_add || 'Add this reaction'))
                    .append(document.createTextNode(e + ' ' + count));
                $slot.append($chip);
            });
        });
    }

    /**
     * Apply the thread_state from a fetch_thread response to the
     * mute / block chrome. Server-driven — running on every poll
     * keeps the chrome converged when the same user toggles state
     * from another tab or device.
     */
    function applyThreadState(state) {
        if (!state || typeof state !== 'object') { return; }
        threadState.is_muted      = !!state.is_muted;
        threadState.muted_until   = state.muted_until || null;
        threadState.other_user_id = parseInt(state.other_user_id, 10) || 0;
        threadState.is_blocked    = !!state.is_blocked;

        // Bell button — strikethrough class when active mute.
        $('#matrix-messaging-mute').toggleClass('is-active', threadState.is_muted);
        // Mute menu — show "Unmute" only when currently muted.
        $('#matrix-messaging-mute-menu .matrix-messaging__menu-item--unmute')
            .prop('hidden', !threadState.is_muted);

        // Kebab menu — swap Block/Unblock visibility based on
        // whether THIS user has blocked the OTHER party.
        $('#matrix-messaging-kebab-menu li[data-action="block"]').prop('hidden', threadState.is_blocked);
        $('#matrix-messaging-kebab-menu li[data-action="unblock"]').prop('hidden', !threadState.is_blocked);
    }

    /**
     * Render the typing indicator from the server's typing[]
     * field on every fetch_thread response. Empty array (or
     * missing key) hides the indicator.
     *
     * Wording follows the WhatsApp / Slack pattern:
     *   1 typer  — "Alice is typing…"
     *   2 typers — "Alice and Bob are typing…"
     *   3 typers — "Alice, Bob, and Carol are typing…"
     *   4+       — "4 people are typing…"
     *
     * The cap matches the moderator-bandwidth pattern: surfacing
     * every typer's name in a 50-member team room would be
     * visual noise, so past 3 we collapse to a count. The
     * boundary is locale-fragile (English-only with hardcoded
     * separators) — a future i18n pass will likely thread this
     * through wp_localize_script's typing strings; for now we
     * keep it inline so the feature ships without blocking on
     * a translator round-trip.
     */
    function applyTypingState(typers) {
        var $bar = $('#matrix-messaging-typing');
        if (!$bar.length) { return; }
        if (!typers || !typers.length) {
            $bar.prop('hidden', true);
            $('#matrix-messaging-typing-text').text('');
            return;
        }
        var names = [];
        for (var i = 0; i < typers.length && i < 3; i++) {
            names.push(String(typers[i].display_name || ''));
        }
        var label;
        if (typers.length === 1) {
            label = formatString(cfg.i18n.typing_one || '%s is typing…', names[0]);
        } else if (typers.length === 2) {
            label = formatString(cfg.i18n.typing_two || '%1$s and %2$s are typing…', names[0], names[1]);
        } else if (typers.length === 3) {
            label = (cfg.i18n.typing_three || '%1$s, %2$s, and %3$s are typing…')
                .replace('%1$s', names[0])
                .replace('%2$s', names[1])
                .replace('%3$s', names[2]);
        } else {
            label = formatString(cfg.i18n.typing_many || '%s people are typing…', String(typers.length));
        }
        $('#matrix-messaging-typing-text').text(label);
        $bar.prop('hidden', false);
    }

    /**
     * Show / hide the "Load older" button based on the most
     * recent answer from fetch_older / fetch_thread.
     */
    function updateLoadOlderButton() {
        var $btn = $('#matrix-messaging-load-older');
        if (!$btn.length) { return; }
        // Hide when we know there's no older history; also hide
        // before any messages have rendered (oldestId == 0)
        // because we have no anchor to pass to fetch_older.
        $btn.prop('hidden', !hasMoreOlder || oldestId === 0);
    }

    /**
     * Lightweight printf-ish helper. We can't pull in
     * sprintf-js, and template strings would lose IE11 — keep
     * it ASCII-tier simple.
     */
    function formatString(template, a, b) {
        return String(template)
            .replace('%1$s', a)
            .replace('%2$s', b)
            .replace('%s', a);
    }

    /**
     * Render a server-side UTC datetime string ("2026-05-26 14:32:01")
     * as a short relative form. Falls back to the raw string if the
     * Date constructor doesn't recognise it.
     */
    function formatReadTime(serverDt) {
        if (!serverDt) { return ''; }
        var d = parseServerDateUtc(serverDt);
        if (!d) { return serverDt; }
        var diffSec = Math.max(0, Math.floor((Date.now() - d.getTime()) / 1000));
        if (diffSec < 60) { return cfg.i18n.receipt_just_now || 'just now'; }
        if (diffSec < 3600) { return Math.floor(diffSec / 60) + 'm'; }
        if (diffSec < 86400) { return Math.floor(diffSec / 3600) + 'h'; }
        return d.toLocaleDateString();
    }

    // --- Wire up ---

    $threadsList.on('click', '.matrix-messaging__thread', function () {
        var $li = $(this);
        $threadsList.find('.matrix-messaging__thread').removeClass('is-active');
        $li.addClass('is-active');
        $li.find('.matrix-badge').remove();
        loadThread(parseInt($li.data('thread-id'), 10), $li.find('strong').text());
    });

    // Typing-beacon machinery (DB 1.0.23 — transient-backed).
    //
    // The textarea fires an 'input' event on every keystroke; we
    // throttle that into one beacon every TYPING_BEACON_INTERVAL_MS
    // (advertised by wp_localize_script under cfg.typingBeaconMs,
    // matching the server's TYPING_BEACON_INTERVAL_MS). The
    // beacon stamps a short-TTL transient server-side; peers read
    // it back via ajax_fetch_thread on their next poll. A user
    // who clears the textarea, blurs it, sends, or switches
    // threads triggers a final is_typing=0 to clear immediately
    // — without that, the indicator would linger for one TTL
    // window after the typer walked away.
    var typingBeaconLastSentAt = 0;    // ms timestamp of last sent beacon
    var typingBeaconScheduled  = null; // setTimeout handle for the next pulse
    var typingBeaconActive     = false; // whether server currently thinks we're typing

    function typingBeaconIntervalMs() {
        var v = parseInt(cfg.typingBeaconMs, 10);
        return (v && v >= 1000) ? v : 3000;
    }

    function sendTypingBeacon(isTyping) {
        if (!activeThreadId) { return; }
        api('typing', { thread_id: activeThreadId, is_typing: isTyping ? 1 : 0 });
    }

    function startTypingBeacon() {
        if (!activeThreadId) { return; }
        var now = Date.now();
        // First pulse fires immediately so peers see the
        // indicator within one poll tick rather than waiting a
        // full beacon interval. Subsequent pulses honor the
        // throttle window.
        if (!typingBeaconActive || (now - typingBeaconLastSentAt) >= typingBeaconIntervalMs()) {
            typingBeaconActive = true;
            typingBeaconLastSentAt = now;
            sendTypingBeacon(true);
        }
        // Schedule a tail-end "stop" beacon. Cancels any earlier
        // pending one so the timing converges on the most recent
        // input. If the user keeps typing, this gets rescheduled;
        // if they go quiet, it fires and clears the indicator.
        if (typingBeaconScheduled) { clearTimeout(typingBeaconScheduled); }
        typingBeaconScheduled = setTimeout(function () {
            stopTypingBeacon(true);
        }, typingBeaconIntervalMs() + 500);
    }

    function stopTypingBeacon(notifyServer) {
        if (typingBeaconScheduled) {
            clearTimeout(typingBeaconScheduled);
            typingBeaconScheduled = null;
        }
        if (typingBeaconActive && notifyServer) {
            sendTypingBeacon(false);
        }
        typingBeaconActive = false;
        typingBeaconLastSentAt = 0;
    }

    $pane.on('input', '#matrix-messaging-composer textarea[name="body"]', function () {
        var hasContent = ($(this).val() || '').length > 0;
        if (hasContent) {
            startTypingBeacon();
        } else {
            // Empty textarea = user cleared what they were
            // composing. Drop the beacon immediately so peers
            // don't see "typing…" linger over a blank composer.
            stopTypingBeacon(true);
        }
    });

    $pane.on('blur', '#matrix-messaging-composer textarea[name="body"]', function () {
        // Blurring out of the composer (e.g. clicking elsewhere
        // on the page) ends the typing session. Same clear-
        // immediately rationale as the empty-textarea path.
        stopTypingBeacon(true);
    });

    $pane.on('submit', '#matrix-messaging-composer', function (e) {
        e.preventDefault();
        if (!activeThreadId) { return; }
        // Send fires the leading-edge of "user just stopped
        // typing" because the message is now sent — clear any
        // outstanding beacon and tell the server we're done so
        // peers see the indicator drop in their next poll.
        stopTypingBeacon(true);
        var $form = $(this);
        var body = ($form.find('textarea[name="body"]').val() || '').trim();
        // Collect all attachment ids that finished uploading. The
        // hidden inputs are appended one-per-success in the upload
        // change handler, so reading them here gives us the final
        // ordered list. Pending uploads (still showing the spinner
        // tile) have not landed in the inputs container yet, so a
        // user who clicks Send mid-upload will simply send without
        // them — the right behaviour, because we'd otherwise have
        // to either block the UI or race-on-finish.
        var attachmentIds = $form.find('input[name="attachment_ids[]"]').map(function () {
            return parseInt(this.value, 10) || 0;
        }).get().filter(function (n) { return n > 0; });

        if (!body && !attachmentIds.length) { return; }
        var $btn = $form.find('button[type="submit"]').prop('disabled', true);
        var payload = { thread_id: activeThreadId, body: body };
        // Send the array shape ($_POST['attachment_ids'] = [..ints..]).
        // jQuery serializes arrays under name[]= when the key ends
        // with []; we explicitly use that form for clarity.
        if (attachmentIds.length) {
            payload['attachment_ids[]'] = attachmentIds;
        }
        api('send', payload).done(function (resp) {
            if (resp && resp.success) {
                $form.find('textarea[name="body"]').val('');
                clearAttachmentTiles();
            } else {
                alert((resp && resp.data && resp.data.message) || 'Send failed.');
            }
        }).always(function () { $btn.prop('disabled', false); });
    });

    // --- Sender-side edit / self-delete ---

    /**
     * Enter edit mode for a message bubble: replace the body
     * with a textarea pre-filled with the original text plus
     * Save / Cancel buttons. The bubble's other parts
     * (attachment, meta) stay visible so the user has context.
     *
     * On Save: POST matrix_messaging_edit, replace innerHTML with
     * the canonical row from the response. On Cancel: re-render
     * the bubble from the original message data we cached on
     * the wrapper.
     */
    function enterEditMode($wrap) {
        if ($wrap.hasClass('is-editing')) { return; }
        var $body = $wrap.find('.matrix-messaging__message-body').first();
        if (!$body.length) { return; }
        var originalText = $body.text();
        $wrap.addClass('is-editing');
        $body.html(
            '<div class="matrix-messaging__edit-form">' +
                '<textarea class="matrix-messaging__edit-textarea"></textarea>' +
                '<div class="matrix-messaging__edit-actions">' +
                    '<button type="button" class="matrix-btn matrix-btn-primary matrix-btn-sm" data-edit-action="save">' + escapeHtml(cfg.i18n.save || 'Save') + '</button>' +
                    '<button type="button" class="matrix-btn matrix-btn-sm" data-edit-action="cancel">' + escapeHtml(cfg.i18n.cancel || 'Cancel') + '</button>' +
                '</div>' +
            '</div>'
        );
        var $ta = $body.find('textarea').val(originalText);
        // Save the original on the wrap so cancel can restore it
        // without an additional server round-trip.
        $wrap.data('originalBody', originalText);
        $ta.focus();
    }

    function exitEditMode($wrap, originalText) {
        $wrap.removeClass('is-editing');
        var $body = $wrap.find('.matrix-messaging__message-body').first();
        $body.html(escapeHtml(originalText || $wrap.data('originalBody') || '').replace(/\n/g, '<br>'));
    }

    function rerenderMessage($wrap, msg) {
        applyMessageWrapperState($wrap, msg);
        $wrap.removeClass('is-editing');
        $wrap.html(buildMessageInnerHtml(msg));
    }

    // Edit / delete icon clicks (delegated on the message list).
    $pane.on('click', '.matrix-messaging__msg-action', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $btn = $(this);
        var action = $btn.data('msg-action');
        var $wrap = $btn.closest('.matrix-messaging__message');
        if (!$wrap.length) { return; }
        var messageId = parseInt($wrap.attr('data-id'), 10);
        if (!messageId) { return; }

        if (action === 'edit') {
            enterEditMode($wrap);
        } else if (action === 'delete') {
            if (!window.confirm(cfg.i18n.confirm_delete || 'Delete this message? This cannot be undone.')) {
                return;
            }
            api('delete', { message_id: messageId }).done(function (resp) {
                if (resp && resp.success && resp.data && resp.data.message) {
                    rerenderMessage($wrap, resp.data.message);
                } else {
                    alert((resp && resp.data && resp.data.message) || (cfg.i18n.delete_failed || 'Delete failed.'));
                }
            }).fail(function () {
                alert(cfg.i18n.delete_failed || 'Delete failed.');
            });
        }
    });

    // Save / Cancel inside an active edit form.
    $pane.on('click', '.matrix-messaging__edit-actions [data-edit-action]', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var $wrap = $btn.closest('.matrix-messaging__message');
        if (!$wrap.length) { return; }
        var act = $btn.data('edit-action');
        if (act === 'cancel') {
            exitEditMode($wrap);
            return;
        }
        if (act !== 'save') { return; }
        var messageId = parseInt($wrap.attr('data-id'), 10);
        var newBody = ($wrap.find('.matrix-messaging__edit-textarea').val() || '').trim();
        if (!newBody) {
            alert(cfg.i18n.edit_empty || 'Message cannot be empty. Use Delete instead.');
            return;
        }
        $btn.prop('disabled', true);
        api('edit', { message_id: messageId, body: newBody }).done(function (resp) {
            if (resp && resp.success && resp.data && resp.data.message) {
                rerenderMessage($wrap, resp.data.message);
            } else {
                alert((resp && resp.data && resp.data.message) || (cfg.i18n.edit_failed || 'Edit failed.'));
                $btn.prop('disabled', false);
            }
        }).fail(function () {
            alert(cfg.i18n.edit_failed || 'Edit failed.');
            $btn.prop('disabled', false);
        });
    });

    // Esc inside the edit textarea cancels; Ctrl/Cmd+Enter saves.
    $pane.on('keydown', '.matrix-messaging__edit-textarea', function (e) {
        if (e.key === 'Escape' || e.keyCode === 27) {
            e.preventDefault();
            var $wrap = $(this).closest('.matrix-messaging__message');
            exitEditMode($wrap);
        } else if ((e.ctrlKey || e.metaKey) && (e.key === 'Enter' || e.keyCode === 13)) {
            e.preventDefault();
            $(this).closest('.matrix-messaging__message')
                   .find('[data-edit-action="save"]').trigger('click');
        }
    });

    // --- Older-message pagination ---

    // Reaction picker (DB 1.0.21). Hover-on-bubble surfaces the
    // "+🙂" trigger; clicking it pops a small fixed palette of
    // emoji buttons. The palette mirrors the server's
    // ALLOWED_REACTION_EMOJIS — kept synced via wp_localize_script
    // (cfg.reactionPalette) so a future operator filter that
    // grows the palette server-side propagates to the client
    // without an editor round-trip. We close the picker on
    // outside click / Escape so it never sticks open behind the
    // user's actual conversation.
    var $reactPicker = null;
    function closeReactPicker() {
        if ($reactPicker) {
            $reactPicker.remove();
            $reactPicker = null;
        }
    }
    $pane.on('click', '.matrix-messaging__msg-react-trigger', function (e) {
        e.stopPropagation();
        var msgId = parseInt($(this).attr('data-msg-react'), 10);
        if (!msgId) { return; }
        closeReactPicker();
        var palette = (cfg.reactionPalette && cfg.reactionPalette.length)
            ? cfg.reactionPalette
            : ['\u{1F44D}', '\u2764\uFE0F', '\u{1F602}', '\u{1F62E}', '\u{1F622}', '\u{1F64F}'];
        var $picker = $('<div class="matrix-messaging__react-picker"></div>')
            .attr('data-msg-id', msgId);
        palette.forEach(function (e) {
            $('<button type="button" class="matrix-messaging__react-picker-btn"></button>')
                .text(e)
                .attr('data-emoji', e)
                .appendTo($picker);
        });
        // Position adjacent to the trigger using offset so we don't
        // need to track per-bubble heights.
        var off = $(this).offset();
        $picker.css({ position: 'absolute', top: off.top - 40, left: off.left });
        $('body').append($picker);
        $reactPicker = $picker;
    });
    $(document).on('click', '.matrix-messaging__react-picker-btn', function (e) {
        e.stopPropagation();
        var $picker = $(this).closest('.matrix-messaging__react-picker');
        var msgId   = parseInt($picker.attr('data-msg-id'), 10);
        var emoji   = $(this).attr('data-emoji');
        closeReactPicker();
        if (!msgId || !emoji) { return; }
        api('react', { message_id: msgId, emoji: emoji }).done(function () {
            // Optimistic update is skipped; the next poll tick
            // (default 10s) re-applies the canonical reactions
            // map. Operators who set polling_interval_ms low
            // get near-instant feedback; high-cadence sites get
            // eventual-consistency feedback. Either way, no
            // duplicated state to reconcile if the server
            // result disagrees with our local guess.
        });
    });
    $pane.on('click', '.matrix-messaging__reaction-chip', function (e) {
        e.stopPropagation();
        var msgId = parseInt($(this).attr('data-msg-id'), 10);
        var emoji = $(this).attr('data-emoji');
        if (!msgId || !emoji) { return; }
        api('react', { message_id: msgId, emoji: emoji });
    });
    $(document).on('click.matrixReactPickerOutside', function () { closeReactPicker(); });
    $(document).on('keydown.matrixReactPicker', function (e) {
        if (e.key === 'Escape') { closeReactPicker(); }
    });

    $pane.on('click', '#matrix-messaging-load-older', function () {
        if (!activeThreadId || olderFetchInFlight || !hasMoreOlder || oldestId === 0) { return; }
        olderFetchInFlight = true;
        var $btn = $(this).prop('disabled', true).addClass('is-loading');
        var $msgs = $('#matrix-messaging-messages');

        // Preserve scroll position relative to the bottom so the
        // user's viewport doesn't jump when older rows are
        // prepended above the current top. After the prepend we
        // restore by setting scrollTop to scrollHeight - savedFromBottom.
        var fromBottom = $msgs[0].scrollHeight - $msgs[0].scrollTop;

        api('fetch_older', { thread_id: activeThreadId, before_id: oldestId }).done(function (resp) {
            if (!resp || !resp.success) {
                $btn.prop('disabled', false).removeClass('is-loading');
                olderFetchInFlight = false;
                return;
            }
            var data = resp.data || {};
            var rows = data.messages || [];
            // Server returns the page in ascending id order. To
            // prepend onto the message list we want to insert
            // them top-down (i.e. iterate ascending and prepend
            // each, which yields a final top-down ordering when
            // each prepend goes after the "Load older" button).
            // Equivalent: iterate descending and call prepend
            // (no anchor) — same result, simpler reasoning.
            // Either way, renderMessage's prepend=true path
            // anchors after the button so we keep ascending.
            rows.forEach(function (m) { renderMessage($msgs, m, true); });

            hasMoreOlder = !!data.has_more;
            updateLoadOlderButton();
            $btn.prop('disabled', false).removeClass('is-loading');
            olderFetchInFlight = false;

            // Restore scroll position so the row the user was
            // looking at stays at the same screen offset.
            $msgs[0].scrollTop = $msgs[0].scrollHeight - fromBottom;
        }).fail(function () {
            $btn.prop('disabled', false).removeClass('is-loading');
            olderFetchInFlight = false;
        });
    });

    // --- Mute popover ---

    $pane.on('click', '#matrix-messaging-mute', function (e) {
        e.preventDefault();
        e.stopPropagation();
        toggleMenu('#matrix-messaging-mute-menu', $(this));
    });

    $pane.on('click', '#matrix-messaging-mute-menu .matrix-messaging__menu-item', function () {
        if (!activeThreadId) { return; }
        var hours = parseInt($(this).data('hours'), 10);
        if (isNaN(hours) || hours < 0) { return; }
        closeAllMenus();
        api('mute', { thread_id: activeThreadId, hours: hours }).done(function (resp) {
            if (resp && resp.success) {
                // Optimistic local apply while waiting for the
                // next poll to bring the canonical thread_state.
                threadState.is_muted = hours > 0;
                applyThreadState(threadState);
            } else {
                alert((resp && resp.data && resp.data.message) || (cfg.i18n.mute_failed || 'Could not change mute state.'));
            }
        }).fail(function () {
            alert(cfg.i18n.mute_failed || 'Could not change mute state.');
        });
    });

    // --- Kebab (DM block / unblock) ---

    $pane.on('click', '#matrix-messaging-kebab', function (e) {
        e.preventDefault();
        e.stopPropagation();
        toggleMenu('#matrix-messaging-kebab-menu', $(this));
    });

    $pane.on('click', '#matrix-messaging-kebab-menu .matrix-messaging__menu-item', function () {
        if (!activeThreadId) { return; }
        var action = $(this).data('action');
        var otherId = parseInt(threadState.other_user_id, 10);
        if (!otherId) { return; }
        closeAllMenus();
        if (action === 'block') {
            if (!window.confirm(cfg.i18n.confirm_block || 'Block this user? They will no longer be able to message you and you will not be able to message them.')) {
                return;
            }
            api('block', { user_id: otherId }).done(function (resp) {
                if (resp && resp.success) {
                    threadState.is_blocked = true;
                    applyThreadState(threadState);
                } else {
                    alert((resp && resp.data && resp.data.message) || (cfg.i18n.block_failed || 'Could not block user.'));
                }
            }).fail(function () {
                alert(cfg.i18n.block_failed || 'Could not block user.');
            });
        } else if (action === 'unblock') {
            api('unblock', { user_id: otherId }).done(function (resp) {
                if (resp && resp.success) {
                    threadState.is_blocked = false;
                    applyThreadState(threadState);
                } else {
                    alert((resp && resp.data && resp.data.message) || (cfg.i18n.block_failed || 'Could not unblock user.'));
                }
            }).fail(function () {
                alert(cfg.i18n.block_failed || 'Could not unblock user.');
            });
        }
    });

    // --- Members panel + self-leave (team rooms only) ---

    /**
     * Render the members list inside the open panel. Each row
     * shows display_name, an "(owner)" / "(member)" role tag,
     * and an "(you)" tag for the current user. Owner rows are
     * pinned to the top server-side via the ORDER BY in
     * list_thread_members.
     *
     * For group rooms (DB 1.0.24), the owner sees additional
     * affordances: an "Invite members" button at the top of the
     * panel and a "Remove" button next to each non-owner row.
     * Both are hidden for non-owners and for non-group_room
     * threads. The viewerCanManage flag arrives on the same
     * AJAX response as the member rows, so we don't need a
     * second round-trip.
     */
    function renderMembersList(members, viewerCanManage) {
        var $list = $('#matrix-messaging-members-list');
        if (!$list.length) { return; }
        if (!members || !members.length) {
            $list.html('<li class="matrix-messaging__members-empty">' + escapeHtml(cfg.i18n.members_load_failed || 'Could not load members.') + '</li>');
            return;
        }
        var html = '';
        members.forEach(function (m) {
            var roleLabel = (m.role === 'owner')
                ? (cfg.i18n.members_role_owner || 'owner')
                : (cfg.i18n.members_role_member || 'member');
            var tags = '<span class="matrix-messaging__members-role">' + escapeHtml(roleLabel) + '</span>';
            if (m.is_self) {
                tags += ' <span class="matrix-messaging__members-self">(' + escapeHtml(cfg.i18n.members_you || 'you') + ')</span>';
            }
            // Per-row Remove affordance — owner-only, group_room
            // only, never on the owner row itself, never on the
            // viewer's own row (use Leave for that).
            var removeBtn = '';
            if (viewerCanManage && !m.is_self && m.role !== 'owner') {
                removeBtn =
                    '<button type="button" class="button button-link-delete matrix-messaging__members-remove" ' +
                            'data-user-id="' + (parseInt(m.user_id, 10) || 0) + '" ' +
                            'title="' + escapeHtml(cfg.i18n.remove_member || 'Remove from room') + '">' +
                        '&times;' +
                    '</button>';
            }
            html += '<li class="matrix-messaging__members-row" data-user-id="' + (parseInt(m.user_id, 10) || 0) + '">' +
                        '<span class="matrix-messaging__members-name">' + escapeHtml(m.display_name || '') + '</span> ' +
                        tags +
                        removeBtn +
                    '</li>';
        });
        $list.html(html);
        // Surface the Invite button when we have permission.
        $('#matrix-messaging-invite').toggle(!!viewerCanManage);
    }

    /**
     * Lazy-load the members roster on each panel open. We
     * deliberately re-fetch every time (rather than caching the
     * first response) so a sponsor / admin who added or removed
     * a member from another tab is reflected the next time the
     * panel opens — at the cost of one small AJAX round-trip per
     * open, which is fine for an explicitly-opened popover.
     */
    function loadMembers() {
        if (!activeThreadId) { return; }
        var $list = $('#matrix-messaging-members-list');
        if (!$list.length) { return; }
        $list.html('<li class="matrix-messaging__members-empty">' + escapeHtml(cfg.i18n.members_loading || 'Loading members…') + '</li>');
        api('list_members', { thread_id: activeThreadId }).done(function (resp) {
            if (resp && resp.success && resp.data && resp.data.members) {
                // Owner-only management surfaces only on group rooms.
                // team_room owners (sponsors) explicitly do not get
                // an Invite/Remove UI in v1 — membership in team
                // rooms comes from the sponsor graph, not from
                // explicit invites, and adding manual overrides
                // would create a confusing two-source-of-truth
                // story for membership state.
                var canManage = (resp.data.thread_type === 'group_room' && resp.data.viewer_role === 'owner');
                renderMembersList(resp.data.members, canManage);
            } else {
                $list.html('<li class="matrix-messaging__members-empty">' + escapeHtml(cfg.i18n.members_load_failed || 'Could not load members.') + '</li>');
            }
        }).fail(function () {
            $list.html('<li class="matrix-messaging__members-empty">' + escapeHtml(cfg.i18n.members_load_failed || 'Could not load members.') + '</li>');
        });
    }

    $pane.on('click', '#matrix-messaging-members', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $panel = $('#matrix-messaging-members-panel');
        var willOpen = $panel.prop('hidden');
        toggleMenu('#matrix-messaging-members-panel', $(this));
        if (willOpen) {
            loadMembers();
        }
    });

    // Stop clicks inside the panel from bubbling up to the
    // document-level closeAllMenus handler — the user is
    // interacting with the panel, not dismissing it. The Leave
    // button still gets its own click handler below.
    $pane.on('click', '#matrix-messaging-members-panel', function (e) {
        e.stopPropagation();
    });

    $pane.on('click', '#matrix-messaging-leave', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (!activeThreadId) { return; }
        if (!window.confirm(cfg.i18n.confirm_leave || 'Leave this team room?')) { return; }
        var leftThreadId = activeThreadId;
        api('leave_thread', { thread_id: leftThreadId }).done(function (resp) {
            if (!(resp && resp.success)) {
                alert((resp && resp.data && resp.data.message) || (cfg.i18n.leave_failed || 'Could not leave the thread.'));
                return;
            }
            // Stop polling and tear down the active pane so the
            // next render uses fresh state. Then refresh the
            // sidebar — the server-side list_threads_for_user
            // gates on removed_at IS NULL so the just-left thread
            // naturally drops off.
            closeAllMenus();
            if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
            if (editWindowSweepTimer) { clearInterval(editWindowSweepTimer); editWindowSweepTimer = null; }
            activeThreadId = null;
            activeThreadType = 'dm';
            renderedIds = Object.create(null);
            lastId = 0;
            oldestId = 0;
            hasMoreOlder = true;
            $pane.html('<div class="matrix-messaging__placeholder">' + escapeHtml(cfg.i18n.select_thread || 'Select a conversation, or start a new one.') + '</div>');
            // Optimistically remove the row from the sidebar. The
            // server's list_threads_for_user already gates on
            // removed_at IS NULL, so the next page load won't show
            // it either — this just keeps the visible state in
            // sync without an extra round-trip.
            $threadsList.find('.matrix-messaging__thread[data-thread-id="' + leftThreadId + '"]').remove();
        }).fail(function () {
            alert(cfg.i18n.leave_failed || 'Could not leave the thread.');
        });
    });

    // Invite-members button (group-room owner only). Routed
    // through window.prompt for the same UX-consistency reasons
    // documented on the New Room flow above.
    $pane.on('click', '#matrix-messaging-invite', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (!activeThreadId) { return; }
        var members = window.prompt(cfg.i18n.invite_prompt || 'Usernames or referral codes (comma-separated):');
        if (members === null) { return; }
        members = (members || '').trim();
        if (members === '') { return; }
        api('invite_to_group', { thread_id: activeThreadId, members: members }).done(function (resp) {
            if (resp && resp.success && resp.data) {
                var added   = parseInt(resp.data.added, 10) || 0;
                var skipped = parseInt(resp.data.skipped, 10) || 0;
                var denied  = parseInt(resp.data.denied, 10) || 0;
                var summary = (cfg.i18n.invite_summary || 'Added %1$d. Skipped %2$d already in the room. %3$d previously left and cannot be auto-re-added.')
                    .replace('%1$d', String(added))
                    .replace('%2$d', String(skipped))
                    .replace('%3$d', String(denied));
                alert(summary);
                loadMembers(); // refresh the visible roster
            } else {
                alert((resp && resp.data && resp.data.message) || (cfg.i18n.invite_failed || 'Could not invite members.'));
            }
        }).fail(function () {
            alert(cfg.i18n.invite_failed || 'Could not invite members.');
        });
    });

    // Per-row Remove (X) button. Owner-only at the model layer,
    // and the JS only renders the button for group_room owners,
    // so a non-owner click can only happen via DevTools — the
    // server still refuses it and the user sees a generic
    // remove_failed toast.
    $pane.on('click', '.matrix-messaging__members-remove', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (!activeThreadId) { return; }
        var targetUserId = parseInt($(this).attr('data-user-id'), 10) || 0;
        if (targetUserId <= 0) { return; }
        if (!window.confirm(cfg.i18n.confirm_remove || 'Remove this member from the room?')) { return; }
        api('remove_from_group', { thread_id: activeThreadId, user_id: targetUserId }).done(function (resp) {
            if (resp && resp.success) {
                loadMembers();
            } else {
                alert((resp && resp.data && resp.data.message) || (cfg.i18n.remove_failed || 'Could not remove the member.'));
            }
        }).fail(function () {
            alert(cfg.i18n.remove_failed || 'Could not remove the member.');
        });
    });

    /**
     * Generic open-one-menu / close-the-rest helper. Both menus
     * mount inside the pane so a single document-level click
     * handler can close them when the user clicks anywhere
     * outside.
     */
    function toggleMenu(menuSelector, $anchor) {
        var $menu = $(menuSelector);
        if (!$menu.length) { return; }
        var willOpen = $menu.prop('hidden');
        closeAllMenus();
        if (willOpen) {
            $menu.prop('hidden', false);
            if ($anchor) { $anchor.attr('aria-expanded', 'true'); }
        }
    }
    function closeAllMenus() {
        $('.matrix-messaging__menu').prop('hidden', true);
        $('.matrix-messaging__mute-btn, .matrix-messaging__kebab, .matrix-messaging__members-btn').attr('aria-expanded', 'false');
    }
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.matrix-messaging__menu-wrap').length) {
            closeAllMenus();
        }
    });
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' || e.keyCode === 27) {
            closeAllMenus();
        }
    });

    // --- Attachment upload via async WP media upload (DB 1.0.23 multi) ---
    //
    // Multi-pick is allowed; each picked file uploads independently and
    // appends a thumbnail tile + a hidden attachment_ids[] input to the
    // composer once it succeeds. The hard cap mirrors the server's
    // MAX_ATTACHMENTS_PER_MESSAGE_HARD (8) so a malformed pick can't
    // queue a hundred uploads — we trim before iterating. Per-file
    // size guard still uses the 5 MB ceiling baked into the plugin
    // settings; a client-side validator that knows the server cap
    // would be strictly better but the upload endpoint will refuse
    // anything larger anyway, so this is a soft early-fail for UX.
    var MAX_COMPOSER_ATTACHMENTS = 8;
    var MAX_COMPOSER_BYTES = 5 * 1024 * 1024;

    function appendAttachmentTile(attachmentId, thumbDataUrl) {
        var $strip = $('#matrix-messaging-preview');
        $strip.show();
        var tile = '<div class="matrix-messaging__preview-tile" data-attachment-id="' + (parseInt(attachmentId, 10) || 0) + '">' +
                       '<img src="' + escapeHtml(thumbDataUrl) + '" alt="preview">' +
                       '<button type="button" class="matrix-messaging__preview-remove" aria-label="Remove">&times;</button>' +
                   '</div>';
        $strip.append(tile);
        $('#matrix-messaging-attachment-inputs').append(
            '<input type="hidden" name="attachment_ids[]" value="' + (parseInt(attachmentId, 10) || 0) + '">'
        );
    }

    function clearAttachmentTiles() {
        $('#matrix-messaging-preview').hide().empty();
        $('#matrix-messaging-attachment-inputs').empty();
    }

    function currentAttachmentCount() {
        return $('#matrix-messaging-attachment-inputs input[name="attachment_ids[]"]').length;
    }

    $pane.on('change', '.matrix-messaging__attach-btn input[type="file"]', function () {
        var files = this.files ? Array.prototype.slice.call(this.files) : [];
        if (!files.length) { return; }
        var $input = $(this);

        var slotsLeft = MAX_COMPOSER_ATTACHMENTS - currentAttachmentCount();
        if (slotsLeft <= 0) {
            alert((cfg.i18n.attach_too_many || 'Maximum %d attachments per message.').replace('%d', MAX_COMPOSER_ATTACHMENTS));
            $input.val('');
            return;
        }
        if (files.length > slotsLeft) {
            files = files.slice(0, slotsLeft);
        }

        files.forEach(function (file) {
            if (file.size > MAX_COMPOSER_BYTES) {
                alert((cfg.i18n.attach_too_large || 'File too large. Maximum %s MB.').replace('%s', '5'));
                return;
            }

            // Local-preview thumbnail goes in immediately at a
            // synthetic id slot (data-pending) so the user sees
            // it before the upload finishes. On upload success we
            // resolve the slot to the real attachment id; on
            // failure we tear the slot back out. Per-file slot
            // tracking keeps multiple in-flight uploads from
            // racing each other for the same DOM tile.
            var pendingId = 'pending-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);
            var $strip = $('#matrix-messaging-preview').show();
            var tileHtml = '<div class="matrix-messaging__preview-tile is-uploading" data-pending-id="' + pendingId + '">' +
                               '<img src="" alt="preview">' +
                               '<span class="matrix-messaging__preview-spinner" aria-hidden="true"></span>' +
                               '<button type="button" class="matrix-messaging__preview-remove" aria-label="Cancel">&times;</button>' +
                           '</div>';
            $strip.append(tileHtml);
            var $tile = $strip.children('[data-pending-id="' + pendingId + '"]');

            var reader = new FileReader();
            reader.onload = function (ev) {
                $tile.find('img').attr('src', ev.target.result);
            };
            reader.readAsDataURL(file);

            var fd = new FormData();
            fd.append('action', 'upload-attachment');
            fd.append('_wpnonce', cfg.uploadNonce);
            fd.append('async-upload', file);
            $.ajax({
                url: cfg.ajaxUrl,
                method: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                dataType: 'json'
            }).done(function (resp) {
                if (resp && resp.success && resp.data && resp.data.id) {
                    // Promote the pending tile to a real one.
                    var realId = parseInt(resp.data.id, 10) || 0;
                    $tile.removeClass('is-uploading')
                         .attr('data-attachment-id', realId)
                         .removeAttr('data-pending-id')
                         .find('.matrix-messaging__preview-spinner').remove();
                    $('#matrix-messaging-attachment-inputs').append(
                        '<input type="hidden" name="attachment_ids[]" value="' + realId + '">'
                    );
                } else {
                    alert((resp && resp.data && resp.data.message) || (cfg.i18n.upload_failed || 'Upload failed.'));
                    $tile.remove();
                    if (!$strip.children().length) { $strip.hide(); }
                }
            }).fail(function () {
                alert(cfg.i18n.upload_failed || 'Upload failed. Please try again.');
                $tile.remove();
                if (!$strip.children().length) { $strip.hide(); }
            });
        });

        // Reset the file input so picking the same file again
        // (after a remove) re-fires the change event.
        $input.val('');
    });

    // Remove a single tile (works for both pending and uploaded
    // tiles). For uploaded tiles, drop the matching hidden input.
    $pane.on('click', '.matrix-messaging__preview-remove', function () {
        var $tile = $(this).closest('.matrix-messaging__preview-tile');
        var aid = parseInt($tile.attr('data-attachment-id'), 10) || 0;
        $tile.remove();
        if (aid > 0) {
            $('#matrix-messaging-attachment-inputs input[name="attachment_ids[]"][value="' + aid + '"]').remove();
        }
        var $strip = $('#matrix-messaging-preview');
        if (!$strip.children().length) { $strip.hide(); }
    });

    $newDmBtn.on('click', function () {
        var who = window.prompt(cfg.i18n.new_dm_prompt);
        if (!who) { return; }
        // Heuristic: if it looks like all caps + digits and short -> referral code; else username.
        var payload = /^[A-Z0-9]{3,20}$/.test(who.trim())
            ? { referral_code: who.trim() }
            : { username: who.trim() };
        api('open_dm', payload).done(function (resp) {
            if (resp && resp.success) {
                // Reload to refresh thread list, then open the new thread.
                window.location.href = window.location.pathname + '?tab=messages&open=' + resp.data.thread_id;
            } else {
                alert((resp && resp.data && resp.data.message) || 'Could not start conversation.');
            }
        });
    });

    /**
     * "New Room" button handler — group room creation flow (DB 1.0.24).
     *
     * Two prompts in sequence rather than a custom modal because:
     *   (a) the existing UI tooling in this file is window.prompt-
     *       and window.confirm-based; mixing modals would create
     *       inconsistent affordances mid-feature
     *   (b) the prompts are intentionally user-correctable — typing
     *       Cancel on either step backs out cleanly
     *
     * On success we reload to ?open=<thread_id> rather than wiring
     * up an in-place sidebar mutation. The reload pulls the fresh
     * thread list from the server which already includes the new
     * room, sidesteps any concern about render races between the
     * create response and the next poll, and matches the New DM
     * button's pattern. One extra page load on a relatively rare
     * action is a fair trade for code we know stays correct.
     */
    if ($newRoomBtn.length) {
        $newRoomBtn.on('click', function () {
            var title = window.prompt(cfg.i18n.new_room_label || 'Room name');
            if (title === null) { return; }
            title = (title || '').trim();
            if (title === '') {
                alert(cfg.i18n.new_room_no_title || 'Please give the room a name.');
                return;
            }
            var members = window.prompt(cfg.i18n.new_room_members || 'Members (usernames or referral codes, separated by commas):');
            if (members === null) { return; }
            members = (members || '').trim();
            if (members === '') {
                alert(cfg.i18n.new_room_no_members || 'Add at least one member.');
                return;
            }
            api('create_group_room', { title: title, members: members }).done(function (resp) {
                if (resp && resp.success && resp.data && resp.data.thread_id) {
                    window.location.href = window.location.pathname + '?tab=messages&open=' + resp.data.thread_id;
                } else {
                    alert((resp && resp.data && resp.data.message) || (cfg.i18n.new_room_failed || 'Could not create the room.'));
                }
            }).fail(function () {
                alert(cfg.i18n.new_room_failed || 'Could not create the room.');
            });
        });
    }

    // --- Cross-thread search ---
    //
    // Behavior:
    //   - Debounced 250ms on every keystroke.
    //   - Two-character minimum (server enforces same floor).
    //   - searchSeq stamping discards stale responses that lose
    //     the race after the user has typed something newer.
    //   - Result click loads the matching thread and queues a
    //     highlight on the matched message id, which loadThread
    //     consumes after its initial fetch resolves.
    //
    // While search results are visible we hide the threads list
    // (the same panel real estate hosts both) and surface a clear
    // button so the user can return to the thread list with a
    // single click rather than having to wipe the input by hand.

    function renderSearchPlaceholder(message) {
        $searchResults
            .show()
            .html('<li class="matrix-messaging__search-empty">' + escapeHtml(message) + '</li>');
    }

    function highlightMatch(text, q) {
        // Case-insensitive single-pass highlight. We escape the HTML
        // first, then match against the escaped form using an
        // escaped regex of the query — which keeps the snippet safe
        // against injection while still highlighting the match.
        var escaped = escapeHtml(text || '');
        if (!q) { return escaped; }
        var re;
        try {
            re = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
        } catch (e) {
            return escaped;
        }
        return escaped.replace(re, '<mark class="matrix-messaging__search-mark">$1</mark>');
    }

    function renderSearchResults(query, results) {
        if (!results || results.length === 0) {
            renderSearchPlaceholder(cfg.i18n.search_no_results);
            return;
        }
        var countTpl = results.length === 1
            ? (cfg.i18n.search_result_count || '%d match')
            : (cfg.i18n.search_result_count_plural || '%d matches');
        var header = '<li class="matrix-messaging__search-header">' +
            escapeHtml(countTpl.replace('%d', String(results.length))) +
            '</li>';

        var items = results.map(function (row) {
            var threadLabel = escapeHtml(row.thread_label || '');
            var senderLabel = escapeHtml(row.sender_label || '');
            var snippet = highlightMatch(row.snippet || row.body || '', query);
            var when = escapeHtml(row.created_at || '');
            return '<li class="matrix-messaging__search-result"' +
                ' data-thread-id="' + parseInt(row.thread_id, 10) + '"' +
                ' data-message-id="' + parseInt(row.id, 10) + '"' +
                ' data-thread-label="' + threadLabel + '">' +
                '<div class="matrix-messaging__search-result-head">' +
                    '<strong>' + threadLabel + '</strong>' +
                    '<span class="matrix-messaging__search-result-when">' + when + '</span>' +
                '</div>' +
                '<div class="matrix-messaging__search-result-sender">' + senderLabel + '</div>' +
                '<div class="matrix-messaging__search-result-snippet">' + snippet + '</div>' +
            '</li>';
        }).join('');

        $searchResults.show().html(header + items);
    }

    function showThreadsList() {
        $searchResults.hide().empty();
        $threadsList.show();
    }

    function showSearchPanel() {
        $threadsList.hide();
        $searchResults.show();
    }

    function runSearch(query) {
        var seq = ++searchSeq;
        if (!query || query.length < 2) {
            // Too short — surface guidance instead of firing a
            // request the server would reject anyway.
            showSearchPanel();
            renderSearchPlaceholder(cfg.i18n.search_min_chars);
            return;
        }
        showSearchPanel();
        renderSearchPlaceholder(cfg.i18n.search_searching);

        api('search', { q: query }).done(function (resp) {
            // Drop late-arriving responses that lost the race.
            if (seq !== searchSeq) { return; }
            if (!resp || !resp.success) {
                renderSearchPlaceholder(cfg.i18n.search_failed);
                return;
            }
            renderSearchResults(query, (resp.data && resp.data.results) || []);
        }).fail(function () {
            if (seq !== searchSeq) { return; }
            renderSearchPlaceholder(cfg.i18n.search_failed);
        });
    }

    $searchInput.on('input', function () {
        var v = (this.value || '').trim();
        $searchClear.toggle(v.length > 0);
        if (searchDebounceTimer) { clearTimeout(searchDebounceTimer); }
        if (v === '') {
            searchSeq++; // invalidate any in-flight response
            showThreadsList();
            return;
        }
        searchDebounceTimer = setTimeout(function () { runSearch(v); }, 250);
    });

    // Submit (Enter) collapses the debounce so a deliberate Enter
    // doesn't wait the extra 250ms.
    $searchInput.on('keydown', function (e) {
        if (e.key === 'Enter' || e.keyCode === 13) {
            e.preventDefault();
            if (searchDebounceTimer) { clearTimeout(searchDebounceTimer); }
            var v = (this.value || '').trim();
            if (v.length >= 2) { runSearch(v); }
        } else if (e.key === 'Escape' || e.keyCode === 27) {
            $searchInput.val('');
            $searchClear.hide();
            searchSeq++;
            showThreadsList();
        }
    });

    $searchClear.on('click', function () {
        $searchInput.val('').focus();
        $searchClear.hide();
        searchSeq++;
        showThreadsList();
    });

    $searchResults.on('click', '.matrix-messaging__search-result', function () {
        var $row = $(this);
        var threadId = parseInt($row.data('thread-id'), 10);
        var messageId = parseInt($row.data('message-id'), 10);
        var label = $row.data('thread-label') || '';
        if (!threadId) { return; }

        // Remember which message to scroll to after the thread
        // loads, then route through the same loadThread path that
        // the sidebar click uses so the polling lifecycle and
        // active-state visuals stay consistent. Also hide the
        // search panel so the user lands on the conversation.
        pendingHighlightMessageId = messageId || null;
        showThreadsList();
        $threadsList.find('.matrix-messaging__thread').removeClass('is-active');
        var $li = $threadsList.find('.matrix-messaging__thread[data-thread-id="' + threadId + '"]');
        if ($li.length) {
            $li.addClass('is-active');
            $li.find('.matrix-badge').remove();
        }
        loadThread(threadId, label);
    });

    // Auto-open ?open=<id> after redirect from new DM.
    var urlParams = new URLSearchParams(window.location.search);
    var openId = parseInt(urlParams.get('open'), 10);
    if (openId) {
        var $li = $threadsList.find('.matrix-messaging__thread[data-thread-id="' + openId + '"]');
        if ($li.length) { $li.trigger('click'); }
    }

})(jQuery);
