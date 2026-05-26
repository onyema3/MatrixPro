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
    var $searchInput = $('#matrix-messaging-search-input');
    var $searchClear = $('#matrix-messaging-search-clear');
    var $searchResults = $('#matrix-messaging-search-results');

    var activeThreadId = null;
    var renderedIds = Object.create(null);
    var lastId = 0;
    var pollTimer = null;

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
