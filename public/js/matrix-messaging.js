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

    var activeThreadId = null;
    var renderedIds = Object.create(null);
    var lastId = 0;
    var pollTimer = null;

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

    function renderPane(threadLabel) {
        $pane.html(
            '<div class="matrix-messaging__pane-header">' +
                '<strong>' + escapeHtml(threadLabel) + '</strong>' +
                '<button type="button" class="button button-small" id="matrix-messaging-mute"><span class="dashicons dashicons-bell"></span></button>' +
            '</div>' +
            '<div class="matrix-messaging__messages" id="matrix-messaging-messages"></div>' +
            '<form class="matrix-messaging__composer" id="matrix-messaging-composer">' +
                '<textarea name="body" placeholder="' + escapeHtml(cfg.i18n.reply_placeholder) + '" required></textarea>' +
                '<button type="submit" class="matrix-btn matrix-btn-primary">' + escapeHtml(cfg.i18n.send) + '</button>' +
            '</form>'
        );
    }

    function renderMessage($container, msg) {
        if (renderedIds[msg.id]) { return; }
        renderedIds[msg.id] = true;
        if (parseInt(msg.id, 10) > lastId) { lastId = parseInt(msg.id, 10); }

        var own = parseInt(msg.sender_id, 10) === parseInt(cfg.currentUserId, 10);
        var deleted = !!msg.deleted_at;
        var classes = ['matrix-messaging__message'];
        if (own) classes.push('is-own');
        if (deleted) classes.push('is-deleted');

        var bodyHtml = deleted
            ? '<em>(message deleted by moderator)</em>'
            : escapeHtml(msg.body || '').replace(/\n/g, '<br>');

        var strippedFlag = (!deleted && parseInt(msg.body_stripped, 10) === 1)
            ? ' <span class="matrix-messaging__message-stripped-flag">' + 'stripped' + '</span>'
            : '';

        $container.append(
            '<div class="' + classes.join(' ') + '" data-id="' + parseInt(msg.id, 10) + '">' +
                '<div class="matrix-messaging__message-body">' + bodyHtml + '</div>' +
                '<div class="matrix-messaging__message-meta">' +
                    escapeHtml(msg.created_at || '') + strippedFlag +
                '</div>' +
            '</div>'
        );

        var $msgs = $('#matrix-messaging-messages');
        if ($msgs.length) { $msgs.scrollTop($msgs[0].scrollHeight); }
    }

    function loadThread(threadId, label) {
        stopPolling();
        activeThreadId = threadId;
        renderedIds = Object.create(null);
        lastId = 0;

        renderPane(label);
        var $msgs = $('#matrix-messaging-messages');

        api('fetch_thread', { thread_id: threadId, after_id: 0 }).done(function (resp) {
            if (!resp || !resp.success) { return; }
            (resp.data.messages || []).forEach(function (m) { renderMessage($msgs, m); });
            startPolling();
        });
    }

    function startPolling() {
        stopPolling();
        pollTimer = setInterval(function () {
            if (!activeThreadId || document.hidden) { return; }
            api('fetch_thread', { thread_id: activeThreadId, after_id: lastId }).done(function (resp) {
                if (!resp || !resp.success) { return; }
                var $msgs = $('#matrix-messaging-messages');
                (resp.data.messages || []).forEach(function (m) { renderMessage($msgs, m); });
            });
        }, Math.max(2000, parseInt(cfg.pollingIntervalMs, 10) || 10000));
    }

    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    // --- Wire up ---

    $threadsList.on('click', '.matrix-messaging__thread', function () {
        var $li = $(this);
        $threadsList.find('.matrix-messaging__thread').removeClass('is-active');
        $li.addClass('is-active');
        $li.find('.matrix-badge').remove();
        loadThread(parseInt($li.data('thread-id'), 10), $li.find('strong').text());
    });

    $pane.on('submit', '#matrix-messaging-composer', function (e) {
        e.preventDefault();
        if (!activeThreadId) { return; }
        var $form = $(this);
        var body = ($form.find('textarea[name="body"]').val() || '').trim();
        if (!body) { return; }
        var $btn = $form.find('button[type="submit"]').prop('disabled', true);
        api('send', { thread_id: activeThreadId, body: body }).done(function (resp) {
            if (resp && resp.success) {
                $form.find('textarea[name="body"]').val('');
                // Next poll tick will canonicalize.
            } else {
                alert((resp && resp.data && resp.data.message) || 'Send failed.');
            }
        }).always(function () { $btn.prop('disabled', false); });
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

    // Auto-open ?open=<id> after redirect from new DM.
    var urlParams = new URLSearchParams(window.location.search);
    var openId = parseInt(urlParams.get('open'), 10);
    if (openId) {
        var $li = $threadsList.find('.matrix-messaging__thread[data-thread-id="' + openId + '"]');
        if ($li.length) { $li.trigger('click'); }
    }

})(jQuery);
