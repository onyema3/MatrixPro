/**
 * MatrixPro — D3.js genealogy tree view
 * --------------------------------------
 *
 * Progressive enhancement that replaces the classic recursive HTML
 * tree (matrix-genealogy-wrapper) with an SVG tree the member can
 * pan and zoom around. The classic tree stays in the DOM as a
 * fallback — see Matrix_MLM_User_Genealogy::render_d3_view() for the
 * server-side wiring.
 *
 * Why SVG (not HTML/foreignObject and not Canvas):
 *   - Foreignobject + zoom transforms have long-standing bugs in
 *     Safari and older Edge — text gets blurry, badges drift off
 *     the card on zoom-in. Native SVG primitives transform
 *     pixel-perfectly across every browser D3 v7 supports.
 *   - Canvas would scale further (we're talking 5,000+ nodes) but
 *     gives up DOM tooling — accessibility, click targets, copy-
 *     paste of usernames, browser find-in-page. SVG is the right
 *     middle ground for the matrix sizes we care about (typical
 *     fully-built plan is ~340 nodes; outliers cap at a few
 *     thousand).
 *
 * Data flow:
 *
 *   1. Page render embeds the first 4 levels of the tree as JSON in
 *      <script type="application/json" id="matrix-genealogy-d3-data">.
 *      We read that synchronously on DOMContentLoaded.
 *   2. d3.hierarchy + d3.tree() lay out the nodes once. We render
 *      every node + link in one SVG <g>.
 *   3. d3.zoom() on the outer SVG drives a transform on the inner
 *      <g> for pan + zoom.
 *   4. Click an "expand" button (rendered under any leaf with
 *      total_downline > rendered children) → POST to
 *      matrix_action=fetch_subtree_json. Merge the response children
 *      into the local node, re-layout with a 400ms transition.
 *   5. Click a node card → fetch matrix_action=node_details, show
 *      a mini info card with a "View their tree →" pivot link.
 *
 * The module never owns URL state. Pivoting (?pivot_user_id=X), plan
 * selection (?plan_id=N), and the view toggle (?view=classic|d3) are
 * all server-driven via full page navigations. The classic and D3
 * views thus stay strictly in lockstep on which member's tree is
 * being shown.
 */

(function () {
    'use strict';

    // ------------------------------------------------------------------
    // Module-level constants. Sizes are deliberately not tied to CSS
    // because SVG layout has to be fully numeric — we couldn't read
    // computed CSS for the rect width in time for the d3.tree() call.
    // ------------------------------------------------------------------
    var NODE_WIDTH      = 180;
    var NODE_HEIGHT     = 64;
    var SIBLING_GAP     = 24;
    var LEVEL_GAP       = 110;
    var MIN_SCALE       = 0.1;
    var MAX_SCALE       = 4;
    var TRANSITION_MS   = 400;
    var EXPAND_CHUNK    = 3;   // matches process_fetch_subtree_json's $expand_levels.
                               // Used only for the SHOW-MORE button label maths;
                               // the server is still authoritative on the actual chunk.

    // ------------------------------------------------------------------
    // Module entry point. Wired below to DOMContentLoaded.
    // ------------------------------------------------------------------
    function init() {
        var canvas = document.getElementById('matrix-genealogy-d3-canvas');
        if (!canvas) return;

        // Member opted out via ?view=classic — leave the canvas
        // hidden (CSS handles the actual hiding from data-active-view)
        // and don't even parse the bootstrap blob. This is the
        // cheapest possible no-op; we want the toggle to feel like
        // it skips D3 entirely rather than mounting it and
        // immediately tearing it down.
        if (canvas.getAttribute('data-active-view') === 'classic') {
            return;
        }

        var bootstrap = readBootstrap();
        if (!bootstrap || !bootstrap.tree) {
            // No tree data to render — leave the loading shimmer
            // up momentarily then unhide the classic fallback. We
            // fall back rather than show "no data" twice because
            // the classic view already renders its own empty-state
            // alert and we don't want to compete.
            failOver(canvas, 'No bootstrap data');
            return;
        }

        // d3 is loaded as a separate <script> above this one. If it
        // didn't load (CDN blocked / vendored file 404 / CSP), bail
        // gracefully back to the classic view rather than throwing
        // a ReferenceError that would leave the page half-broken.
        if (typeof window.d3 === 'undefined') {
            failOver(canvas, 'd3 global missing');
            return;
        }

        try {
            mount(canvas, bootstrap);
        } catch (err) {
            // Anything we didn't anticipate — log to console for
            // ourselves but show the classic tree to the user.
            // Crashing the SVG would be worse than degrading.
            if (window.console && console.error) {
                console.error('[matrix-genealogy-d3] mount failed', err);
            }
            failOver(canvas, 'mount threw');
        }
    }

    /**
     * Read the application/json bootstrap blob into a JS object.
     * Returns null when the blob is missing or malformed (which
     * triggers fail-over to the classic view, not a hard error).
     */
    function readBootstrap() {
        var blob = document.getElementById('matrix-genealogy-d3-data');
        if (!blob) return null;
        var raw = blob.textContent || blob.innerText || '';
        if (!raw) return null;
        try {
            return JSON.parse(raw);
        } catch (err) {
            if (window.console && console.warn) {
                console.warn('[matrix-genealogy-d3] bootstrap parse failed', err);
            }
            return null;
        }
    }

    /**
     * Hand the page back to the classic HTML tree.
     *
     * Called when D3 can't or shouldn't run — bootstrap missing, d3
     * library not loaded, or mount() threw. We delete the D3 canvas
     * (so its loading shimmer doesn't sit there forever) and clear
     * the data-active-view attr on the classic wrapper so the CSS
     * re-shows it. The classic wrapper is already in the DOM since
     * the server renders both views unconditionally.
     */
    function failOver(canvas, reason) {
        canvas.setAttribute('data-mounted', 'failed');
        canvas.setAttribute('data-fail-reason', reason || 'unknown');
        canvas.style.display = 'none';
        var wrapper = document.querySelector('.matrix-genealogy-wrapper[data-view-role="classic"]');
        if (wrapper) {
            wrapper.setAttribute('data-active-view', 'classic');
            wrapper.removeAttribute('aria-hidden');
        }
    }

    // ==================================================================
    // Mount: the main render loop. State is captured in closure rather
    // than living on `window` so multiple instances on the same page
    // (which shouldn't happen but might during a future split-pane
    // feature) wouldn't clobber each other.
    // ==================================================================
    function mount(canvas, bootstrap) {
        var d3 = window.d3;
        var i18n = bootstrap.i18n || {};

        // Flip the classic wrapper out of the way — we're committed
        // to rendering D3 from here on. Done synchronously *before*
        // the heavy SVG build so members on slow CPUs don't briefly
        // see both views overlapping.
        var classicWrapper = document.querySelector('.matrix-genealogy-wrapper[data-view-role="classic"]');
        if (classicWrapper) {
            classicWrapper.setAttribute('data-active-view', 'd3');
            classicWrapper.setAttribute('aria-hidden', 'true');
        }

        // Strip the loading shimmer; we own the canvas now.
        var loading = canvas.querySelector('.matrix-genealogy-d3-loading');
        if (loading) loading.parentNode.removeChild(loading);

        // ---- SVG scaffold ----
        var svg = d3.select(canvas)
            .append('svg')
            .attr('class', 'matrix-genealogy-d3-svg')
            .attr('role', 'img')
            .attr('aria-label', i18n.no_data || 'Genealogy tree');

        // Single inner <g> that gets transformed by d3.zoom. Putting
        // every node + link inside it lets us pan/zoom in O(1) DOM
        // ops regardless of how many nodes are visible.
        var viewport = svg.append('g').attr('class', 'matrix-genealogy-d3-viewport');

        // Layers within the viewport. Drawing links first means
        // node cards always sit on top of their connectors — looks
        // cleaner when a card is mid-zoom and partially overlaps
        // its parent's link.
        var linksLayer = viewport.append('g').attr('class', 'mtx-links-layer');
        var nodesLayer = viewport.append('g').attr('class', 'mtx-nodes-layer');

        // ---- Hierarchy + state ----
        // We mutate the underlying data tree (`bootstrap.tree`) in
        // place when expanding subtrees, then re-derive the
        // d3.hierarchy on each redraw. Re-deriving is cheap (linear
        // in node count) and keeps every render's hierarchy in sync
        // with the data — no stale references.
        var dataRoot = bootstrap.tree;

        // Track positions currently being expanded so a member
        // double-clicking the same expand button doesn't fire two
        // requests. Map<positionId, true>.
        var pendingLoads = Object.create(null);

        // Track the currently-open details card so opening another
        // closes it cleanly (CSS doesn't have first-class
        // accordion semantics for SVG, so we manage it manually).
        var openDetails = null;

        // d3.zoom behavior. Stored on the closure so the toolbar
        // buttons can call zoomBehavior.scaleBy() / .transform()
        // for programmatic zoom in/out/fit/reset.
        var zoomBehavior = d3.zoom()
            .scaleExtent([MIN_SCALE, MAX_SCALE])
            .filter(function (event) {
                // Default filter rejects right-clicks. Also reject
                // scroll-wheel zoom when the cursor is over a card
                // — members trying to read a long username
                // shouldn't accidentally zoom the whole tree on
                // a trackpad.
                if (event.type === 'wheel' && event.target.closest && event.target.closest('.mtx-node-clickable')) {
                    // Allow zoom only with Ctrl/Cmd held — that's
                    // the convention browsers themselves use to
                    // disambiguate page-zoom from in-content scroll.
                    return event.ctrlKey || event.metaKey;
                }
                return !event.button;
            })
            .on('zoom', function (event) {
                viewport.attr('transform', event.transform);
            });

        svg.call(zoomBehavior);

        // ---- First render ----
        layoutAndRender(/* animate = */ false, /* fit = */ true);

        // ---- Toolbar wiring ----
        wireToolbar();

        canvas.setAttribute('data-mounted', '1');

        // ==============================================================
        // Layout + render.
        // ==============================================================
        function layoutAndRender(animate, fit) {
            // d3.hierarchy from the mutable data root. We pass
            // children as the accessor so empty arrays are still
            // counted (which preserves the placement of expand
            // buttons under "no rendered children but downline > 0"
            // nodes — they show up as leaves with d.data.total_downline
            // > 0 and our render function handles that case).
            var hierarchy = d3.hierarchy(dataRoot, function (d) { return d.children || []; });

            // d3.tree with nodeSize gives us a layout that scales
            // naturally with the tree size — every sibling pair gets
            // a fixed gap, so a wide matrix grows wide rather than
            // squishing into a fixed canvas. This is the key
            // difference from .size(), which would force everything
            // into a finite rect and become unreadable past ~30
            // siblings.
            var layout = d3.tree().nodeSize([NODE_WIDTH + SIBLING_GAP, LEVEL_GAP]);
            layout(hierarchy);

            var nodes = hierarchy.descendants();
            var links = hierarchy.links();

            // ---- Links (connector paths) ----
            var linkSel = linksLayer.selectAll('path.mtx-link')
                .data(links, function (d) { return d.target.data.id; });

            linkSel.exit()
                .transition().duration(animate ? TRANSITION_MS : 0)
                .style('opacity', 0)
                .remove();

            var linkEnter = linkSel.enter()
                .append('path')
                .attr('class', 'mtx-link')
                .style('opacity', 0);

            linkEnter.merge(linkSel)
                .transition().duration(animate ? TRANSITION_MS : 0)
                .style('opacity', 1)
                .attr('d', linkPath);

            // ---- Nodes ----
            var nodeSel = nodesLayer.selectAll('g.mtx-node')
                .data(nodes, function (d) { return d.data.id; });

            nodeSel.exit()
                .transition().duration(animate ? TRANSITION_MS : 0)
                .style('opacity', 0)
                .remove();

            var nodeEnter = nodeSel.enter()
                .append('g')
                .attr('class', function (d) {
                    return [
                        'mtx-node',
                        'mtx-node-' + (d.data.relationship || 'spillover'),
                        d.data.user_id ? 'mtx-node-clickable' : 'mtx-node-empty'
                    ].join(' ');
                })
                .attr('transform', function (d) { return 'translate(' + d.x + ',' + d.y + ')'; })
                .style('opacity', 0)
                .on('click', onNodeClick)
                .on('keydown', function (event, d) {
                    // Enter / Space activate as click — required for
                    // keyboard a11y on focusable SVG elements. (We
                    // make each node focusable below via tabindex=0.)
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        onNodeClick(event, d);
                    }
                });

            // Card body — the rounded rect every node renders.
            nodeEnter.append('rect')
                .attr('class', 'mtx-node-body')
                .attr('x', -NODE_WIDTH / 2)
                .attr('y', -NODE_HEIGHT / 2)
                .attr('width', NODE_WIDTH)
                .attr('height', NODE_HEIGHT)
                .attr('rx', 10)
                .attr('ry', 10);

            // Avatar circle — colored disk with the username's
            // initial. We deliberately don't load real Gravatar
            // images here because (a) the bootstrap JSON doesn't
            // ship emails (that's a server-side privacy decision)
            // and (b) on a 200-node tree we'd be firing 200 image
            // requests on first paint, undermining the whole
            // "fast and light" goal of this view. A future PR can
            // add lazy-loaded avatars on hover.
            nodeEnter.append('circle')
                .attr('class', 'mtx-node-avatar')
                .attr('cx', -NODE_WIDTH / 2 + 24)
                .attr('cy', 0)
                .attr('r', 16);

            nodeEnter.append('text')
                .attr('class', 'mtx-node-initial')
                .attr('x', -NODE_WIDTH / 2 + 24)
                .attr('y', 5)
                .attr('text-anchor', 'middle')
                .text(function (d) {
                    var u = d.data.username || '';
                    return u ? u.charAt(0).toUpperCase() : '?';
                });

            // Username — truncated to fit the card. SVG <text>
            // doesn't auto-truncate so we cap the rendered string
            // length and append … manually.
            nodeEnter.append('text')
                .attr('class', 'mtx-node-name')
                .attr('x', -NODE_WIDTH / 2 + 50)
                .attr('y', -4)
                .text(function (d) {
                    if (!d.data.user_id) return i18n.empty_slot || 'Empty';
                    return truncate(d.data.username || '', 16);
                });

            // Meta line — Level X · N downline. For the root we
            // skip the level (it's always level 1 from its own
            // perspective) and just show the downline count.
            nodeEnter.append('text')
                .attr('class', 'mtx-node-meta')
                .attr('x', -NODE_WIDTH / 2 + 50)
                .attr('y', 14)
                .text(function (d) {
                    if (!d.data.user_id) {
                        return ''; // empty slot — meta line stays blank
                    }
                    var parts = [];
                    if (d.data.level) {
                        parts.push((i18n.level || 'Level') + ' ' + d.data.level);
                    }
                    parts.push(d.data.total_downline + ' ' + (i18n.downline || 'downline'));
                    return parts.join(' \u00b7 ');
                });

            // "Show more" expand badge for nodes with hidden downline.
            // We render this on enter() AND check on every redraw
            // whether it should still be visible (children loaded ⇒
            // remove it).
            nodeEnter.each(function (d) {
                renderExpandBadgeIfNeeded(d3.select(this), d);
            });

            // Make every clickable node focusable for keyboard nav.
            // Empty slots stay non-focusable — there's nothing to
            // do with them yet from this view.
            nodeEnter.attr('tabindex', function (d) {
                return d.data.user_id ? 0 : -1;
            });

            // Update existing nodes (positions may have shifted
            // because a sibling above was just expanded).
            nodeEnter.merge(nodeSel)
                .transition().duration(animate ? TRANSITION_MS : 0)
                .style('opacity', 1)
                .attr('transform', function (d) { return 'translate(' + d.x + ',' + d.y + ')'; });

            // Refresh expand badges on existing nodes — important
            // because a node we previously rendered an expand badge
            // on might have just received its children, so the
            // badge needs to vanish.
            nodeSel.each(function (d) {
                renderExpandBadgeIfNeeded(d3.select(this), d);
            });

            if (fit) {
                fitToScreen(/* animate = */ false);
            }
        }

        // SVG path generator for parent → child connector lines.
        // Curved paths read more naturally than straight lines on a
        // hierarchy view; we use d3.linkVertical with the standard
        // {x, y} accessors.
        var linkGen = d3.linkVertical()
            .x(function (d) { return d.x; })
            .y(function (d) { return d.y; });

        function linkPath(d) {
            // Adjust source y so the line emerges from the bottom
            // edge of the parent card, and target y so it lands on
            // the top edge of the child card — otherwise the curve
            // visually overlaps the cards.
            var src = { x: d.source.x, y: d.source.y + NODE_HEIGHT / 2 };
            var tgt = { x: d.target.x, y: d.target.y - NODE_HEIGHT / 2 };
            return linkGen({ source: src, target: tgt });
        }

        // ---- Expand badge ("Show more") ----
        function renderExpandBadgeIfNeeded(nodeG, d) {
            // Conditions for showing it:
            //   1. Node has a real user (empty slots can't expand).
            //   2. total_downline > number of rendered children.
            //   3. The node is currently a leaf in our hierarchy
            //      (children empty / undefined). If we already
            //      loaded children for it, it's no longer the
            //      bottom — show no badge.
            var existing = nodeG.select('.mtx-expand-badge');
            var renderedChildren = (d.data.children && d.data.children.length) || 0;
            var hiddenDownline   = (d.data.total_downline || 0) - renderedChildren;
            var shouldShow = d.data.user_id
                && hiddenDownline > 0
                && (!d.data.children || d.data.children.length === 0);

            if (!shouldShow) {
                existing.remove();
                return;
            }
            if (!existing.empty()) return; // already rendered

            var badge = nodeG.append('g')
                .attr('class', 'mtx-expand-badge')
                .attr('transform', 'translate(0, ' + (NODE_HEIGHT / 2 + 14) + ')')
                .attr('role', 'button')
                .attr('tabindex', 0)
                .attr('aria-label', (i18n.show_more || 'Show more') + ' (' + d.data.total_downline + ')')
                .on('click', function (event) {
                    event.stopPropagation(); // don't trigger node-card click
                    onExpand(d);
                })
                .on('keydown', function (event) {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        event.stopPropagation();
                        onExpand(d);
                    }
                });

            badge.append('rect')
                .attr('x', -36).attr('y', -11)
                .attr('width', 72).attr('height', 22)
                .attr('rx', 11).attr('ry', 11);

            badge.append('text')
                .attr('text-anchor', 'middle')
                .attr('y', 4)
                .text('+' + formatCount(d.data.total_downline));
        }

        // ==============================================================
        // Lazy expand — POST to fetch_subtree_json, merge response
        // children into the local hierarchy, re-layout.
        // ==============================================================
        function onExpand(node) {
            var positionId = node.data.id;
            if (!positionId || pendingLoads[positionId]) return;
            pendingLoads[positionId] = true;

            // Optimistic UI: dim the badge while in flight so the
            // member sees their click registered. We don't replace
            // the text with a spinner because SVG <animateTransform>
            // for the spin wouldn't auto-clean up if the request
            // throws — opacity is simpler and degrades the same.
            var badgeSel = nodesLayer.selectAll('g.mtx-node')
                .filter(function (d) { return d.data.id === positionId; })
                .select('.mtx-expand-badge');
            badgeSel.style('opacity', 0.4);

            var endpoint = (window.matrixMLM && window.matrixMLM.ajaxUrl) || '';
            var nonce    = (window.matrixMLM && window.matrixMLM.nonce) || '';
            if (!endpoint) {
                // matrixMLM never localised — page is missing the
                // public asset bundle. There's nothing we can do
                // from here; revert the badge state and bail.
                badgeSel.style('opacity', 1);
                delete pendingLoads[positionId];
                return;
            }

            var body = new FormData();
            body.append('action', 'matrix_mlm_action');
            body.append('matrix_action', 'fetch_subtree_json');
            body.append('nonce', nonce);
            body.append('position_id', String(positionId));
            body.append('from_level', String(node.data.level || 1));

            fetch(endpoint, {
                method: 'POST',
                body: body,
                credentials: 'same-origin'
            })
            .then(function (r) { return r.json(); })
            .then(function (response) {
                delete pendingLoads[positionId];
                if (!response || !response.success || !response.data || !response.data.tree) {
                    badgeSel.style('opacity', 1);
                    var msg = (response && response.data && response.data.message)
                        ? response.data.message
                        : (i18n.load_error || 'Could not load deeper levels.');
                    flashError(msg);
                    return;
                }
                // Merge: the response's tree is the SAME node we
                // expanded, now with its `children` populated. We
                // graft just the children into the local data —
                // overwriting the node itself would also overwrite
                // the parent reference d3.hierarchy walks via.
                var fresh = response.data.tree;
                node.data.children = sanitiseChildren(fresh.children || [], node.data.user_id);

                // Re-layout with animation so the expansion feels
                // like a natural reveal rather than a snap.
                layoutAndRender(true, false);
            })
            .catch(function (err) {
                delete pendingLoads[positionId];
                badgeSel.style('opacity', 1);
                if (window.console && console.warn) {
                    console.warn('[matrix-genealogy-d3] expand failed', err);
                }
                flashError(i18n.load_error || 'Could not load deeper levels.');
            });
        }

        // Server returns nodes WITHOUT the pre-computed
        // `relationship` field that the bootstrap blob carries
        // (the server-side prepare_tree_for_d3 only runs on the
        // initial render path — the lazy-load endpoint deliberately
        // returns raw build_subtree() shape). Compute relationship
        // here from sponsor_id ↔ root_user_id, matching the
        // server's logic exactly so newly-expanded subtrees
        // colour-classify identically to the bootstrap render.
        function sanitiseChildren(children, parentUserId) {
            if (!Array.isArray(children)) return [];
            var rootUserId = bootstrap.root_user_id;
            return children.map(function (raw) {
                return walkAndAnnotate(raw, rootUserId);
            }).filter(function (n) { return !!n; });
        }

        function walkAndAnnotate(raw, rootUserId) {
            if (!raw || typeof raw !== 'object' || !raw.id) return null;
            var rel;
            if (raw.user_id === rootUserId) {
                rel = 'you';
            } else if (raw.sponsor_id && raw.sponsor_id === rootUserId) {
                rel = 'direct';
            } else {
                rel = 'spillover';
            }
            return {
                id:             Number(raw.id),
                user_id:        Number(raw.user_id || 0),
                sponsor_id:     raw.sponsor_id ? Number(raw.sponsor_id) : null,
                username:       String(raw.username || ('User #' + (raw.user_id || 0))),
                level:          Number(raw.level || 0),
                total_downline: Number(raw.total_downline || 0),
                status:         String(raw.status || ''),
                relationship:   rel,
                children: (raw.children || []).map(function (c) { return walkAndAnnotate(c, rootUserId); }).filter(Boolean)
            };
        }

        // ==============================================================
        // Node click → details mini-card.
        //
        // Fetches matrix_action=node_details and renders a small
        // floating panel near the clicked card. The panel includes a
        // "View their tree →" link that drives the existing pivot
        // URL (full page reload, server-side).
        // ==============================================================
        function onNodeClick(event, d) {
            event.stopPropagation();
            if (!d.data.user_id) return; // empty slots — nothing to show

            // If the same node is already showing details, treat the
            // second click as "close" — feels right for a popover.
            if (openDetails && openDetails.positionId === d.data.id) {
                closeDetails();
                return;
            }
            closeDetails();

            // Spawn the details DOM panel BEFORE the AJAX call so
            // the member gets immediate feedback. The panel shows a
            // loading state, then fills in once the response lands.
            var panel = spawnDetailsPanel(d);
            openDetails = { positionId: d.data.id, panel: panel };

            var endpoint = (window.matrixMLM && window.matrixMLM.ajaxUrl) || '';
            var nonce    = (window.matrixMLM && window.matrixMLM.nonce) || '';
            if (!endpoint) {
                renderDetailsError(panel, 'Cannot load details (offline).');
                return;
            }

            var body = new FormData();
            body.append('action', 'matrix_mlm_action');
            body.append('matrix_action', 'node_details');
            body.append('nonce', nonce);
            body.append('position_id', String(d.data.id));

            fetch(endpoint, {
                method: 'POST',
                body: body,
                credentials: 'same-origin'
            })
            .then(function (r) { return r.json(); })
            .then(function (response) {
                if (!openDetails || openDetails.positionId !== d.data.id) {
                    // Member clicked another node before this one
                    // came back — drop the response, the new card
                    // is already showing.
                    return;
                }
                if (!response || !response.success || !response.data) {
                    var msg = (response && response.data && response.data.message)
                        ? response.data.message
                        : 'Could not load details.';
                    renderDetailsError(panel, msg);
                    return;
                }
                fillDetailsPanel(panel, response.data, d);
            })
            .catch(function () {
                if (openDetails && openDetails.positionId === d.data.id) {
                    renderDetailsError(panel, 'Network error.');
                }
            });
        }

        function spawnDetailsPanel(d) {
            var panel = document.createElement('div');
            panel.className = 'matrix-genealogy-d3-details';
            panel.setAttribute('role', 'dialog');
            panel.setAttribute('aria-modal', 'false');
            panel.innerHTML = '<button type="button" class="mtx-details-close" aria-label="Close">&times;</button>'
                + '<div class="mtx-details-body">'
                + '<div class="mtx-details-loading">' + escapeHtml(i18n.loading || 'Loading…') + '</div>'
                + '</div>';

            // Position panel near the clicked node. We anchor in
            // canvas-relative pixel space, NOT viewport-relative,
            // because the canvas itself sits inside the document
            // flow. Using getBoundingClientRect() on the SVG node's
            // <g> gives us screen coords; we convert to canvas-
            // relative by subtracting the canvas's own top/left.
            var anchor = locateNodeRect(d);
            if (anchor) {
                panel.style.left = (anchor.x + NODE_WIDTH / 2 + 16) + 'px';
                panel.style.top  = (anchor.y - NODE_HEIGHT / 2) + 'px';
            }

            canvas.appendChild(panel);
            panel.querySelector('.mtx-details-close').addEventListener('click', closeDetails);
            return panel;
        }

        function fillDetailsPanel(panel, data, d) {
            var body = panel.querySelector('.mtx-details-body');
            if (!body) return;

            var pivotUrl = buildPivotUrl(data.user_id);
            var fullName = escapeHtml(data.full_name || data.username || '');
            var username = escapeHtml(data.username || '');
            var joined   = escapeHtml(data.joined || '');
            var sponsor  = escapeHtml(data.sponsor || '');
            var commission = (data.commission && data.commission.amount_display) || '';
            var plansLine = (data.plans && data.plans.length)
                ? data.plans.map(escapeHtml).join(', ')
                : '';

            var html = ''
                + '<div class="mtx-details-header">'
                +     '<div class="mtx-details-name">' + fullName + '</div>'
                +     (username && username !== fullName ? '<div class="mtx-details-username">@' + username + '</div>' : '')
                + '</div>'
                + '<dl class="mtx-details-list">'
                +   (joined   ? '<dt>Joined</dt><dd>' + joined + '</dd>' : '')
                +   (data.level ? '<dt>Level</dt><dd>' + Number(data.level) + '</dd>' : '')
                +   (sponsor  ? '<dt>Sponsor</dt><dd>' + sponsor + '</dd>' : '')
                +   (plansLine ? '<dt>Plans</dt><dd>' + plansLine + '</dd>' : '')
                +   (commission ? '<dt>Branch earnings</dt><dd>' + escapeHtml(commission) + '</dd>' : '')
                + '</dl>';

            // Pivot CTA — always render except for the current view
            // root (clicking it would re-root onto itself, which is
            // a no-op). data.is_self covers the viewer's own card.
            if (!d.parent || d.parent !== null) {
                if (d.data.user_id !== bootstrap.root_user_id) {
                    html += '<a class="mtx-details-pivot" href="' + escapeAttr(pivotUrl) + '">'
                          + escapeHtml(format(i18n.view_member || "View %s's tree", data.username || ''))
                          + '</a>';
                }
            }

            body.innerHTML = html;
        }

        function renderDetailsError(panel, msg) {
            var body = panel.querySelector('.mtx-details-body');
            if (!body) return;
            body.innerHTML = '<div class="mtx-details-error">' + escapeHtml(msg) + '</div>';
        }

        function closeDetails() {
            if (openDetails && openDetails.panel && openDetails.panel.parentNode) {
                openDetails.panel.parentNode.removeChild(openDetails.panel);
            }
            openDetails = null;
        }

        // ESC closes; click outside the panel and outside any node
        // also closes. We attach these once at mount.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeDetails();
        });
        canvas.addEventListener('click', function (e) {
            if (!openDetails) return;
            // Click was on the panel itself — leave it alone.
            if (openDetails.panel && openDetails.panel.contains(e.target)) return;
            // Click was on an SVG node — onNodeClick will manage
            // its own state via the same closeDetails() it calls.
            if (e.target.closest && e.target.closest('g.mtx-node')) return;
            closeDetails();
        });

        // ==============================================================
        // Toolbar wiring — reads the data-action attrs on the
        // server-rendered toolbar and binds zoom/fit/reset handlers.
        // ==============================================================
        function wireToolbar() {
            var toolbar = document.querySelector('.matrix-genealogy-toolbar');
            if (!toolbar) return;
            toolbar.addEventListener('click', function (e) {
                var btn = e.target.closest('button[data-action]');
                if (!btn) return;
                var action = btn.getAttribute('data-action');
                switch (action) {
                    case 'zoom-in':
                        svg.transition().duration(200).call(zoomBehavior.scaleBy, 1.4);
                        break;
                    case 'zoom-out':
                        svg.transition().duration(200).call(zoomBehavior.scaleBy, 1 / 1.4);
                        break;
                    case 'fit-screen':
                        fitToScreen(true);
                        break;
                    case 'reset-view':
                        svg.transition().duration(300)
                            .call(zoomBehavior.transform, d3.zoomIdentity);
                        break;
                }
            });
        }

        // ==============================================================
        // Fit-to-screen: compute bounding box of all nodes, scale +
        // translate the viewport so the entire tree is visible with
        // a small margin. Used on first render and when the
        // toolbar's "Fit" button is pressed.
        // ==============================================================
        function fitToScreen(animate) {
            // Defer one frame so the browser has measured the canvas
            // (rare but possible for the canvas to still be 0×0 if
            // we're called inside the same tick the SVG was
            // appended).
            requestAnimationFrame(function () {
                var hierarchy = d3.hierarchy(dataRoot, function (d) { return d.children || []; });
                var layout = d3.tree().nodeSize([NODE_WIDTH + SIBLING_GAP, LEVEL_GAP]);
                layout(hierarchy);
                var nodes = hierarchy.descendants();
                if (!nodes.length) return;

                var minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
                nodes.forEach(function (n) {
                    if (n.x - NODE_WIDTH / 2 < minX) minX = n.x - NODE_WIDTH / 2;
                    if (n.x + NODE_WIDTH / 2 > maxX) maxX = n.x + NODE_WIDTH / 2;
                    if (n.y - NODE_HEIGHT / 2 < minY) minY = n.y - NODE_HEIGHT / 2;
                    if (n.y + NODE_HEIGHT / 2 > maxY) maxY = n.y + NODE_HEIGHT / 2;
                });

                var pad = 40;
                var bboxW = (maxX - minX) + pad * 2;
                var bboxH = (maxY - minY) + pad * 2;

                var canvasRect = canvas.getBoundingClientRect();
                if (canvasRect.width === 0 || canvasRect.height === 0) return;

                // Set SVG to canvas size on each fit so it grows
                // with the container on resize.
                svg.attr('width', canvasRect.width).attr('height', canvasRect.height);

                var scale = Math.min(canvasRect.width / bboxW, canvasRect.height / bboxH, 1);
                if (scale < MIN_SCALE) scale = MIN_SCALE;

                // Center the bounding box within the canvas.
                var cx = (minX + maxX) / 2;
                var cy = (minY + maxY) / 2;
                var tx = canvasRect.width / 2 - cx * scale;
                var ty = canvasRect.height / 2 - cy * scale;

                var t = d3.zoomIdentity.translate(tx, ty).scale(scale);
                if (animate) {
                    svg.transition().duration(TRANSITION_MS).call(zoomBehavior.transform, t);
                } else {
                    svg.call(zoomBehavior.transform, t);
                }
            });
        }

        // ==============================================================
        // Helpers.
        // ==============================================================
        function locateNodeRect(d) {
            // Find the SVG group for this node and compute its
            // canvas-relative pixel position so we can place the
            // details panel next to it. d3.zoom's transform matters
            // here — d.x/d.y are layout coords, NOT screen coords.
            var sel = nodesLayer.selectAll('g.mtx-node')
                .filter(function (n) { return n.data.id === d.data.id; });
            if (sel.empty()) return null;
            var rect = sel.node().getBoundingClientRect();
            var canvasRect = canvas.getBoundingClientRect();
            return {
                x: rect.left - canvasRect.left + rect.width / 2,
                y: rect.top  - canvasRect.top  + rect.height / 2
            };
        }

        function buildPivotUrl(userId) {
            // Reuse the current URL, drop the d3-vs-classic flag (we
            // want pivots to honour the member's view preference,
            // and ?view= is preserved as-is), and replace
            // pivot_user_id. We can't use add_query_arg() in JS
            // without a port, so do this with URLSearchParams.
            try {
                var url = new URL(window.location.href);
                url.searchParams.set('pivot_user_id', String(userId));
                return url.toString();
            } catch (e) {
                // Older browsers without URL constructor — fall
                // back to a string concat. Good enough; this is
                // only the pivot CTA target.
                var sep = window.location.search ? '&' : '?';
                return window.location.pathname + window.location.search
                     + sep + 'pivot_user_id=' + encodeURIComponent(String(userId));
            }
        }

        function flashError(msg) {
            // Lightweight toast at the top of the canvas. Clears
            // itself after 3s. Intentionally simpler than the
            // referral-copy toast — we don't show flashes often.
            var existing = canvas.querySelector('.matrix-genealogy-d3-flash');
            if (existing) existing.remove();
            var el = document.createElement('div');
            el.className = 'matrix-genealogy-d3-flash';
            el.textContent = msg;
            canvas.appendChild(el);
            setTimeout(function () {
                if (el.parentNode) el.parentNode.removeChild(el);
            }, 3000);
        }

        function truncate(s, max) {
            s = String(s || '');
            if (s.length <= max) return s;
            return s.slice(0, max - 1) + '\u2026';
        }

        function formatCount(n) {
            n = Number(n) || 0;
            if (n >= 1000) return (n / 1000).toFixed(1).replace(/\.0$/, '') + 'k';
            return String(n);
        }

        function format(template, value) {
            return String(template).replace(/%s/g, value);
        }

        function escapeHtml(s) {
            return String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function escapeAttr(s) {
            return escapeHtml(s);
        }

        // Re-fit on window resize so the tree stays usable when
        // the dashboard pane is resized (e.g. on rotate). Debounced
        // to one fit per 200ms.
        var resizeTimer;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () { fitToScreen(true); }, 200);
        });
    }

    // ------------------------------------------------------------------
    // Bootstrap on DOMContentLoaded. We don't use defer/async on the
    // <script> tag because the wp_enqueue_script setup the plugin uses
    // already footer-loads everything; by the time this file runs, the
    // DOM is parsed up to its insertion point, and the canvas is well
    // above it. A plain DOMContentLoaded listener is the safest
    // catch-all in case the script ever moves.
    // ------------------------------------------------------------------
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
