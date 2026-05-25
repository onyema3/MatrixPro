/**
 * Matrix MLM — In-App Notifications
 *
 * Powers the sidebar bell icon and the dedicated Notifications
 * dashboard tab. Communicates with three AJAX endpoints registered
 * by Matrix_MLM_In_App_Notifications::register_hooks():
 *
 *   - matrix_in_app_fetch        GET unread count + latest rows
 *   - matrix_in_app_mark_read    POST ids[] → mark specific read
 *   - matrix_in_app_mark_all_read POST → mark all unread read
 *
 * Strict-CSP-friendly: this file is loaded with a normal
 * <script src=...> tag (script-src 'self'), no inline JS, no eval,
 * no inline event handlers. All bindings are document-delegated so
 * the script is robust to dashboard tab swaps and cached rendered
 * HTML being re-injected after page load.
 *
 * Polling: every POLL_INTERVAL_MS milliseconds the bell makes a
 * lightweight fetch to refresh the unread badge, and re-renders the
 * dropdown body if it's open. Polling pauses while the tab is
 * hidden (visibilitychange) to avoid waking up a backgrounded tab,
 * and fires immediately when the tab regains focus so a user
 * returning sees a fresh count.
 */
(function () {
    'use strict';

    // -----------------------------------------------------------------
    // Bootstrapping helpers — these are intentionally jQuery-free so
    // the bell does not depend on the dashboard's other inline
    // scripts having executed first. The only runtime dependency is
    // matrixMLM (the localized object emitted by
    // wp_localize_script for ajaxUrl + nonce). If it is missing the
    // bell silently falls back to the server-rendered initial state.
    // -----------------------------------------------------------------

    /** Polling cadence — defaulted to 60s per the project's design
     *  decision (low load, snappy enough). Adjustable from PHP via
     *  the matrixNotifConfig.pollMs localized value if a future
     *  admin-side toggle is added.
     */
    var POLL_INTERVAL_MS = 60000;

    /**
     * Read configuration once at script load. Safe to call before
     * DOMContentLoaded — `window.matrixMLM` is set by
     * wp_localize_script which is part of the script tag's emitted
     * markup. Falls back to sane defaults if the config is missing
     * (which would only happen if an asset optimizer dequeued
     * matrix-mlm-public.js — in that case the bell still renders
     * its server-side state, just without polling).
     */
    function getConfig() {
        var cfg = (typeof window.matrixNotifConfig === 'object' && window.matrixNotifConfig) || {};
        var mm  = (typeof window.matrixMLM === 'object' && window.matrixMLM) || {};
        return {
            ajaxUrl:  cfg.ajaxUrl  || mm.ajaxUrl  || '',
            nonce:    cfg.nonce    || mm.nonce    || '',
            pollMs:   parseInt(cfg.pollMs, 10) || POLL_INTERVAL_MS,
            seeAllUrl: cfg.seeAllUrl || (mm.siteUrl ? (mm.siteUrl.replace(/\/$/, '') + '/matrix-dashboard/notifications/') : '/matrix-dashboard/notifications/'),
            l10n: cfg.l10n || {}
        };
    }

    function t(key, fallback) {
        var l10n = getConfig().l10n;
        return (l10n && typeof l10n[key] === 'string' && l10n[key].length) ? l10n[key] : fallback;
    }

    /**
     * Server-mirror of the icon registry in
     * Matrix_MLM_User_Dashboard::notification_icon_class(). Both
     * surfaces have to render the same icon for the same type slug
     * so polled updates don't visibly disagree with server-rendered
     * rows.
     */
    function iconClassFor(type) {
        var map = {
            transfer_received:            'dashicons-money-alt',
            transfer_sent:                'dashicons-share',
            commission_referral:          'dashicons-groups',
            commission_level:             'dashicons-chart-area',
            commission_matrix_completion: 'dashicons-awards',
            commission:                   'dashicons-chart-area',
            level_completion:             'dashicons-awards',
            deposit:                      'dashicons-download',
            withdrawal_approved:          'dashicons-yes-alt',
            withdrawal_rejected:          'dashicons-no-alt',
            withdrawal_completed:         'dashicons-yes-alt',
            withdrawal:                   'dashicons-bank',
            bank_payout:                  'dashicons-bank',
            bank_payout_failed:           'dashicons-warning',
            bill_payment:                 'dashicons-smartphone',
            bill_payment_airtime:         'dashicons-smartphone',
            bill_payment_data:            'dashicons-smartphone',
            bill_payment_cable:           'dashicons-format-video',
            bill_payment_electricity:     'dashicons-lightbulb',
            bill_refund:                  'dashicons-image-rotate',
            card_status:                  'dashicons-id-alt',
            epin_redeemed:                'dashicons-tickets-alt',
            subscription:                 'dashicons-calendar-alt',
            subscription_deactivation:    'dashicons-warning',
            password_changed:             'dashicons-shield',
            loan_approved:                'dashicons-yes-alt',
            loan_rejected:                'dashicons-no-alt',
            loan:                         'dashicons-money-alt',
            healthcare_approved:          'dashicons-heart',
            healthcare_rejected:          'dashicons-no-alt',
            healthcare:                   'dashicons-heart',
            admin_announcement:           'dashicons-megaphone'
        };
        return map[type] || 'dashicons-info-outline';
    }

    /**
     * Lightweight HTML escaper. We only ever inject server-shaped
     * strings (title, body, type, time_ago, link_url), but every
     * one of those flows through this helper before reaching
     * innerHTML so a future trigger site that forgets to strip
     * HTML can't surface as XSS in the dropdown.
     */
    function esc(s) {
        if (s === null || typeof s === 'undefined') return '';
        return String(s).replace(/[&<>"']/g, function (c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }

    /**
     * Same-host link sanitization. The PHP enqueue helper already
     * drops cross-host URLs, but a future poll might pick up rows
     * that predate that filter — so we re-check here. Defaults to
     * '#' for any link that doesn't pass.
     */
    function safeLink(url) {
        if (!url) return '#';
        try {
            // Allow root-relative paths.
            if (url.charAt(0) === '/' && url.charAt(1) !== '/') {
                return url;
            }
            var parsed = new URL(url, window.location.origin);
            if (parsed.host !== window.location.host) return '#';
            return parsed.href;
        } catch (e) {
            return '#';
        }
    }

    /**
     * Native fetch wrapper. We don't depend on jQuery here so the
     * bell works even when matrix-public.js hasn't loaded (CSP can
     * still allow this file via 'self' even on installs where some
     * inline-script gate is interfering with the legacy code).
     *
     * Sends a urlencoded body so the WordPress AJAX endpoint sees
     * regular $_POST semantics, matching how every other call site
     * in this plugin posts.
     */
    function ajaxPost(action, params) {
        var cfg = getConfig();
        if (!cfg.ajaxUrl || !cfg.nonce) {
            return Promise.reject(new Error('matrixMLM config missing'));
        }
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('nonce',  cfg.nonce);
        if (params && typeof params === 'object') {
            Object.keys(params).forEach(function (k) {
                var v = params[k];
                if (Array.isArray(v)) {
                    v.forEach(function (item) { body.append(k + '[]', String(item)); });
                } else if (v !== undefined && v !== null) {
                    body.set(k, String(v));
                }
            });
        }
        return fetch(cfg.ajaxUrl, {
            method:      'POST',
            credentials: 'same-origin',
            headers:     { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body:        body.toString()
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    }

    // -----------------------------------------------------------------
    // DOM helpers
    // -----------------------------------------------------------------

    function $(sel, root) {
        return (root || document).querySelector(sel);
    }
    function $all(sel, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    }

    /**
     * Update the badge count on the bell button. Hides the badge
     * when count <= 0 so users without unread items don't see a
     * "0" bubble.
     */
    function updateBadge(count) {
        var badge = $('[data-matrix-notif-badge]');
        if (!badge) return;
        var n = Math.max(0, parseInt(count, 10) || 0);
        badge.textContent = n > 99 ? '99+' : String(n);
        if (n > 0) {
            badge.classList.add('is-visible');
            badge.setAttribute('aria-hidden', 'false');
        } else {
            badge.classList.remove('is-visible');
            badge.setAttribute('aria-hidden', 'true');
        }

        // Also update the dropdown's "Mark all as read" disabled
        // state — disabled when there's nothing to mark.
        var markAll = $('[data-matrix-notif-mark-all]');
        if (markAll) {
            markAll.disabled = n === 0;
        }
    }

    /**
     * Replace the dropdown's <ul data-matrix-notif-list> contents
     * with a fresh server-shape rows array. Idempotent — called on
     * every poll and on every manual fetch.
     */
    function renderDropdownList(rows) {
        var list = $('[data-matrix-notif-list]');
        if (!list) return;

        if (!rows || rows.length === 0) {
            list.innerHTML = '<li class="matrix-notif-empty" data-matrix-notif-empty>'
                + esc(t('empty', "No notifications yet. We'll let you know here when something happens."))
                + '</li>';
            return;
        }

        var html = rows.map(function (row) {
            var isRead = !!row.is_read;
            var icon = iconClassFor(row.type);
            var link = safeLink(row.link_url);
            var bodyHtml = '';
            if (row.body) {
                // Trim to ~120 chars in JS (server already trims to
                // 24 words on render; this is belt-and-braces for
                // fresh polled rows whose body might be longer).
                var b = String(row.body);
                if (b.length > 140) b = b.substring(0, 137) + '…';
                bodyHtml = '<div class="matrix-notif-text">' + esc(b) + '</div>';
            }
            return '<li class="matrix-notif-item ' + (isRead ? 'is-read' : 'is-unread') + '"'
                + ' data-matrix-notif-item'
                + ' data-id="' + esc(row.id) + '"'
                + ' data-link="' + esc(link) + '">'
                +   '<span class="matrix-notif-icon dashicons ' + esc(icon) + '" aria-hidden="true"></span>'
                +   '<div class="matrix-notif-body">'
                +     '<div class="matrix-notif-title">' + esc(row.title) + '</div>'
                +     bodyHtml
                +     '<div class="matrix-notif-time">' + esc(row.time_ago || '') + '</div>'
                +   '</div>'
                + (isRead ? '' : '<span class="matrix-notif-dot" aria-label="' + esc(t('unread', 'Unread')) + '"></span>')
                + '</li>';
        }).join('');
        list.innerHTML = html;
    }

    /**
     * Open / close the dropdown. We toggle BOTH the [hidden]
     * attribute AND aria-expanded on the trigger so screen readers
     * announce the state change correctly. Inline display:none is
     * not used — the CSS rule [hidden] { display: none } in the
     * stylesheet handles visibility, and the absence of an inline
     * style means a future theme override doesn't fight us.
     */
    function setOpen(open) {
        var dropdown = $('[data-matrix-notif-dropdown]');
        var trigger  = $('[data-matrix-notif-trigger]');
        if (!dropdown || !trigger) return;
        if (open) {
            dropdown.removeAttribute('hidden');
            trigger.setAttribute('aria-expanded', 'true');
            // Refresh on open so the user sees the freshest rows.
            // A second poll is allowed to fire while the dropdown
            // is open — that's fine, the renderer is idempotent.
            fetchAndRender({ silent: true });
        } else {
            dropdown.setAttribute('hidden', 'hidden');
            trigger.setAttribute('aria-expanded', 'false');
        }
    }

    function isOpen() {
        var dropdown = $('[data-matrix-notif-dropdown]');
        return dropdown && !dropdown.hasAttribute('hidden');
    }

    function toggleOpen() {
        setOpen(!isOpen());
    }

    // -----------------------------------------------------------------
    // Network — fetch / mark-read / mark-all-read
    // -----------------------------------------------------------------

    var lastFetch = { running: false, lastAt: 0 };

    /**
     * Poll the fetch endpoint and re-render the dropdown / badge.
     * `silent` skips the visible "loading" spinner — used on
     * polling cycles where we don't want a flash on every refresh.
     */
    function fetchAndRender(opts) {
        opts = opts || {};
        if (lastFetch.running) return Promise.resolve(null);
        lastFetch.running = true;
        return ajaxPost('matrix_in_app_fetch', { limit: 20 })
            .then(function (resp) {
                lastFetch.running = false;
                lastFetch.lastAt = Date.now();
                if (!resp || !resp.success) return null;
                var data = resp.data || {};
                updateBadge(data.unread_count);
                if (isOpen() || !opts.silent) {
                    renderDropdownList(data.rows || []);
                }
                return data;
            })
            .catch(function () {
                lastFetch.running = false;
            });
    }

    function markRead(ids) {
        if (!ids || !ids.length) return Promise.resolve(null);
        return ajaxPost('matrix_in_app_mark_read', { ids: ids })
            .then(function (resp) {
                if (resp && resp.success && resp.data) {
                    updateBadge(resp.data.unread_count);
                }
                return resp;
            })
            .catch(function () {});
    }

    function markAllRead() {
        return ajaxPost('matrix_in_app_mark_all_read')
            .then(function (resp) {
                if (resp && resp.success && resp.data) {
                    updateBadge(resp.data.unread_count);
                    fetchAndRender({ silent: true });
                }
                return resp;
            })
            .catch(function () {});
    }

    // -----------------------------------------------------------------
    // Event delegation
    // -----------------------------------------------------------------

    function onClick(e) {
        var trigger    = e.target.closest && e.target.closest('[data-matrix-notif-trigger]');
        var item       = e.target.closest && e.target.closest('[data-matrix-notif-item]');
        var markAllBtn = e.target.closest && e.target.closest('[data-matrix-notif-mark-all]');
        var pageItem   = e.target.closest && e.target.closest('[data-matrix-notif-page-mark-read]');
        var pageMarkAll= e.target.closest && e.target.closest('[data-matrix-notif-mark-all-page]');
        var pageLink   = e.target.closest && e.target.closest('[data-matrix-notif-page-link]');

        // Bell button — toggle dropdown.
        if (trigger) {
            e.preventDefault();
            toggleOpen();
            return;
        }

        // "Mark all as read" inside the dropdown.
        if (markAllBtn && !markAllBtn.disabled) {
            e.preventDefault();
            markAllRead();
            // Optimistically dim every visible item.
            $all('[data-matrix-notif-item].is-unread').forEach(function (el) {
                el.classList.remove('is-unread');
                el.classList.add('is-read');
                var dot = el.querySelector('.matrix-notif-dot');
                if (dot && dot.parentNode) dot.parentNode.removeChild(dot);
            });
            return;
        }

        // Notification row in the dropdown — mark read + navigate.
        if (item) {
            // Mid-click / cmd-click semantics: let the browser open
            // the link in a new tab without us hijacking the click.
            // We only fire the mark-read AJAX on a primary-button
            // click without modifier keys.
            if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) {
                return;
            }
            e.preventDefault();
            var id = parseInt(item.getAttribute('data-id'), 10);
            var href = item.getAttribute('data-link') || '';
            // Optimistic UI: flip to read locally before the AJAX
            // round-trip lands. The server's response then keeps
            // the badge count in sync.
            if (item.classList.contains('is-unread')) {
                item.classList.remove('is-unread');
                item.classList.add('is-read');
                var dot = item.querySelector('.matrix-notif-dot');
                if (dot && dot.parentNode) dot.parentNode.removeChild(dot);
                if (id > 0) markRead([id]);
            }
            if (href && href !== '#') {
                window.location.href = href;
            } else {
                setOpen(false);
            }
            return;
        }

        // Notifications-tab "Mark as read" button on a row.
        if (pageItem) {
            e.preventDefault();
            var pageId = parseInt(pageItem.getAttribute('data-id'), 10);
            if (pageId > 0) {
                var li = pageItem.closest('[data-matrix-notif-page-item]');
                if (li) {
                    li.classList.remove('is-unread');
                    li.classList.add('is-read');
                }
                pageItem.parentNode && pageItem.parentNode.removeChild(pageItem);
                markRead([pageId]);
            }
            return;
        }

        // Notifications-tab row link — mark its row read on click
        // through. The link's href takes the user to the relevant
        // dashboard tab, so we don't preventDefault — just enqueue
        // the read AJAX in the background.
        if (pageLink) {
            var lid = parseInt(pageLink.getAttribute('data-id'), 10);
            if (lid > 0) markRead([lid]);
            return;
        }

        // Notifications-tab "Mark all as read".
        if (pageMarkAll) {
            e.preventDefault();
            markAllRead().then(function () {
                $all('[data-matrix-notif-page-item].is-unread').forEach(function (el) {
                    el.classList.remove('is-unread');
                    el.classList.add('is-read');
                    var btn = el.querySelector('[data-matrix-notif-page-mark-read]');
                    if (btn && btn.parentNode) btn.parentNode.removeChild(btn);
                });
                pageMarkAll.style.display = 'none';
            });
            return;
        }

        // Click outside the dropdown closes it. Skip when the click
        // target is the bell trigger (we already handled that
        // above) and when it's inside the dropdown itself.
        if (isOpen()) {
            var bell = $('[data-matrix-notif-bell]');
            if (bell && !bell.contains(e.target)) {
                setOpen(false);
            }
        }
    }

    function onKeydown(e) {
        // Close the dropdown on Escape — accessibility convention
        // for any popover/dialog UI.
        if (e.key === 'Escape' && isOpen()) {
            setOpen(false);
            var trigger = $('[data-matrix-notif-trigger]');
            if (trigger) trigger.focus();
        }
    }

    function onVisibilityChange() {
        if (document.hidden) return;
        // Tab regained focus — refresh immediately (don't wait for
        // the next poll tick) so a user coming back from another
        // tab sees the latest unread count.
        var since = Date.now() - lastFetch.lastAt;
        if (since > 5000) {
            fetchAndRender({ silent: true });
        }
    }

    // -----------------------------------------------------------------
    // Bootstrap
    // -----------------------------------------------------------------

    function init() {
        // Bell isn't on every page — only the dashboard. Bail early
        // on pages where the wrapper is absent so we don't poll
        // pointlessly on every site request.
        if (!$('[data-matrix-notif-bell]')) return;

        // Click delegation. Document-bound so the handlers survive
        // any future DOM swap that re-renders the bell or the
        // notifications page.
        document.addEventListener('click', onClick, false);
        document.addEventListener('keydown', onKeydown, false);
        document.addEventListener('visibilitychange', onVisibilityChange, false);

        // First fresh fetch on load. Server already rendered the
        // initial state, but this catches any rows that landed
        // between the page render and the script being parsed
        // (e.g. a commission credited 200ms after the page started
        // serving). silent:true so we don't redundantly re-render
        // the dropdown body since it's pre-populated.
        fetchAndRender({ silent: true });

        // Polling cycle. setInterval (not setTimeout chain) is the
        // right choice here — we don't need backpressure, and a
        // dropped tick is acceptable on a slow network because the
        // server-side state is the source of truth.
        var cfg = getConfig();
        setInterval(function () {
            if (document.hidden) return;
            fetchAndRender({ silent: true });
        }, Math.max(15000, cfg.pollMs));
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, false);
    } else {
        init();
    }
})();
