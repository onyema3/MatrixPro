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
        var attachBtn = cfg.allowAttachments
            ? '<label class="matrix-messaging__attach-btn" title="Attach image">' +
                  '<input type="file" name="attachment" accept="image/*" style="display:none">' +
                  '<span class="dashicons dashicons-format-image"></span>' +
              '</label>'
            : '';

        $pane.html(
            '<div class="matrix-messaging__pane-header">' +
                '<strong>' + escapeHtml(threadLabel) + '</strong>' +
                '<button type="button" class="button button-small" id="matrix-messaging-mute"><span class="dashicons dashicons-bell"></span></button>' +
            '</div>' +
            '<div class="matrix-messaging__messages" id="matrix-messaging-messages"></div>' +
            '<form class="matrix-messaging__composer" id="matrix-messaging-composer" enctype="multipart/form-data">' +
                '<div class="matrix-messaging__composer-preview" id="matrix-messaging-preview" style="display:none">' +
                    '<img src="" alt="preview">' +
                    '<button type="button" class="matrix-messaging__preview-remove">&times;</button>' +
                '</div>' +
                '<div class="matrix-messaging__composer-row">' +
                    attachBtn +
                    '<textarea name="body" placeholder="' + escapeHtml(cfg.i18n.reply_placeholder) + '"></textarea>' +
                    '<button type="submit" class="matrix-btn matrix-btn-primary">' + escapeHtml(cfg.i18n.send) + '</button>' +
                '</div>' +
                '<input type="hidden" name="attachment_id" value="">' +
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

        // Render attached image if present and not deleted.
        var attachHtml = '';
        if (!deleted && msg.attachment_url) {
            attachHtml = '<div class="matrix-messaging__message-attachment">' +
                '<a href="' + escapeHtml(msg.attachment_url) + '" target="_blank" rel="noopener">' +
                '<img src="' + escapeHtml(msg.attachment_url) + '" alt="attachment" loading="lazy">' +
                '</a></div>';
        }

        var strippedFlag = (!deleted && parseInt(msg.body_stripped, 10) === 1)
            ? ' <span class="matrix-messaging__message-stripped-flag">' + 'stripped' + '</span>'
            : '';

        $container.append(
            '<div class="' + classes.join(' ') + '" data-id="' + parseInt(msg.id, 10) + '">' +
                attachHtml +
                '<div class="matrix-messaging__message-body">' + bodyHtml + '</div>' +
                '<div class="matrix-messaging__message-meta">' +
                    escapeHtml(msg.created_at || '') + strippedFlag +
                '</div>' +
                // Read-receipt slot, only on own messages. The
                // server populates it on the next fetch_thread tick
                // via the receipts map; we leave it empty initially
                // (no flicker between optimistic add and the first
                // poll's authoritative state).
                (own && !deleted ? '<div class="matrix-messaging__message-receipt" data-receipt-for="' + parseInt(msg.id, 10) + '"></div>' : '') +
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
            applyReceipts(resp.data.receipts, resp.data.thread_type);
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
                applyReceipts(resp.data.receipts, resp.data.thread_type);
            });
        }, Math.max(2000, parseInt(cfg.pollingIntervalMs, 10) || 10000));
    }

    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
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
        // Server returns mysql GMT format; coerce by appending Z so
        // browsers interpret as UTC. Avoids a 1-minute "in the future"
        // glitch on clients with a mildly-skewed clock.
        var iso = String(serverDt).replace(' ', 'T') + 'Z';
        var d = new Date(iso);
        if (isNaN(d.getTime())) { return serverDt; }
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

    $pane.on('submit', '#matrix-messaging-composer', function (e) {
        e.preventDefault();
        if (!activeThreadId) { return; }
        var $form = $(this);
        var body = ($form.find('textarea[name="body"]').val() || '').trim();
        var attachmentId = $form.find('input[name="attachment_id"]').val() || '';
        if (!body && !attachmentId) { return; }
        var $btn = $form.find('button[type="submit"]').prop('disabled', true);
        api('send', { thread_id: activeThreadId, body: body, attachment_id: attachmentId }).done(function (resp) {
            if (resp && resp.success) {
                $form.find('textarea[name="body"]').val('');
                $form.find('input[name="attachment_id"]').val('');
                $('#matrix-messaging-preview').hide().find('img').attr('src', '');
            } else {
                alert((resp && resp.data && resp.data.message) || 'Send failed.');
            }
        }).always(function () { $btn.prop('disabled', false); });
    });

    // --- Attachment upload via async WP media upload ---
    $pane.on('change', '.matrix-messaging__attach-btn input[type="file"]', function () {
        var file = this.files && this.files[0];
        if (!file) { return; }
        var $input = $(this);
        // Client-side size guard (matches server's max_attachment_bytes default 5 MB)
        if (file.size > 5 * 1024 * 1024) {
            alert('File too large. Maximum 5 MB.');
            $input.val('');
            return;
        }
        // Show local preview immediately
        var reader = new FileReader();
        reader.onload = function (ev) {
            $('#matrix-messaging-preview').show().find('img').attr('src', ev.target.result);
        };
        reader.readAsDataURL(file);

        // Upload via WP's async-upload.php (same mechanism wp.media uses)
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
                $('#matrix-messaging-composer').find('input[name="attachment_id"]').val(resp.data.id);
            } else {
                alert((resp && resp.data && resp.data.message) || 'Upload failed.');
                $('#matrix-messaging-preview').hide().find('img').attr('src', '');
            }
        }).fail(function () {
            alert('Upload failed. Please try again.');
            $('#matrix-messaging-preview').hide().find('img').attr('src', '');
        });
        $input.val('');
    });

    // Remove attachment preview
    $pane.on('click', '.matrix-messaging__preview-remove', function () {
        $('#matrix-messaging-preview').hide().find('img').attr('src', '');
        $('#matrix-messaging-composer').find('input[name="attachment_id"]').val('');
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
