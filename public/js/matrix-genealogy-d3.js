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

        // ---- Per-position commission attribution overlay state ----
        //
        // The "income map" toggle in the toolbar lazy-fetches a
        // sparse map of {user_id -> {amount, amount_display, count}}
        // covering the entire authorised subtree (not just the
        // currently-rendered nodes), so newly lazy-loaded nodes
        // can find themselves in the map without an extra
        // round-trip. Lifecycle:
        //
        //   - active=false, loaded=false: button shows "Show",
        //     no badges visible, no fetch in flight.
        //   - active=true, loading=true: clicked once, fetch
        //     in flight. We render the toggle as pressed
        //     immediately so the click feels responsive; the
        //     summary chip shows a "Loading…" placeholder until
        //     the response lands.
        //   - active=true, loaded=true: badges render, summary
        //     chip shows totals.
        //   - active=false, loaded=true: clicking again hides
        //     badges WITHOUT throwing away the cached map — a
        //     re-toggle is then instant.
        //
        // We never invalidate the map on poll or expand because:
        //   - polled new nodes carry _isNew until the pulse
        //     clears; they have no commission data yet (joined
        //     seconds ago), so a missing map entry is correct.
        //   - lazy-expanded nodes are already covered by the
        //     server-side BFS that built the map, so a lookup
        //     by user_id just works.
        //
        // The downside is a stale map after a long page session
        // where new commissions land. We accept that — the
        // member can refresh the page or toggle off+on to
        // re-fetch. Optimising for this is a future-day concern.
        var commissionOverlay = {
            active:    false,
            loading:   false,
            loaded:    false,
            byUserId:  Object.create(null),
            total:     null,
            currency:  (window.matrixMLM && window.matrixMLM.currency) || '',
            capped:    false
        };
        // Cached <div> that lives in the canvas corner and shows
        // the rolled-up "you've earned X from N members" line
        // when the overlay is active. Created on demand the
        // first time the overlay turns on; reused across
        // toggles. Hidden via display:none rather than
        // remove()d so its position calc stays cheap.
        var commissionSummaryEl = null;

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

        // ---- Connector path generator ----
        // Defined BEFORE the first layoutAndRender() call below
        // because that call ends up wiring linkPath into the
        // d3 selection's .attr('d', linkPath) — and linkPath in
        // turn dereferences linkGen via closure. linkGen is
        // declared with `var`, so its NAME is hoisted to the top
        // of mount() but its ASSIGNMENT runs in source order.
        // Keeping the declaration further down (its previous
        // home) made linkGen `undefined` at the moment linkPath
        // first ran, throwing "linkGen is not a function" and
        // tripping the mount-failed catch block, which then
        // failed over to classic. Hoisting the var by hand
        // avoids the scope-vs-init mismatch entirely.
        //
        // Curved parent → child connectors read more naturally
        // than straight lines on a hierarchy view; we use
        // d3.linkVertical with the standard {x, y} accessors.
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

        // ---- First render ----
        layoutAndRender(/* animate = */ false, /* fit = */ true);

        // ---- Toolbar wiring ----
        wireToolbar();

        canvas.setAttribute('data-mounted', '1');

        // ---- Real-time polling ----
        // Start the new-referral poll loop after first paint so the
        // initial render isn't competing for resources with the
        // first AJAX round-trip. The loop is self-rescheduling and
        // shuts itself down implicitly when the page navigates
        // away (the closure references die with the canvas).
        //
        // Skipped in snapshot mode — a historical view shouldn't
        // tick forward in real time. The whole point of the time
        // machine is showing the tree as it was on a frozen date,
        // and grafting new arrivals into that view would be either
        // misleading (members appearing on a date they weren't
        // there) or pointless (we'd have to filter them out by
        // joined_at against the snapshot date and end up with a
        // no-op anyway).
        if (!bootstrap.snapshot_mode) {
            startPolling();
        }

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
                    var classes = [
                        'mtx-node',
                        'mtx-node-' + (d.data.relationship || 'spillover'),
                        d.data.user_id ? 'mtx-node-clickable' : 'mtx-node-empty'
                    ];
                    // Polling-grafted nodes carry a transient _isNew
                    // flag; tagging them with `mtx-node-new` triggers
                    // the CSS pulse keyframe defined in
                    // matrix-dashboard.css. The flag is cleared by
                    // schedulePulseClear() after PULSE_DURATION_MS so
                    // a later re-render doesn't re-pulse the same
                    // node.
                    if (d.data._isNew) classes.push('mtx-node-new');
                    return classes.join(' ');
                })
                .attr('transform', function (d) { return 'translate(' + d.x + ',' + d.y + ')'; })
                .style('opacity', 0)
                .on('click', onNodeClick)
                .on('mouseenter', onNodeMouseEnter)
                .on('mouseleave', onNodeMouseLeave)
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
                renderCommissionBadgeIfNeeded(d3.select(this), d);
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

            // Refresh meta line text on every redraw so the
            // polling-driven total_downline updates ("3 downline"
            // → "4 downline") tick visibly on the parent cards
            // when a new descendant is grafted in. Without this,
            // the meta would stay frozen at whatever the bootstrap
            // emitted — defeating the whole "watch your tree
            // grow live" intent.
            nodeEnter.merge(nodeSel)
                .select('text.mtx-node-meta')
                .text(function (d) {
                    if (!d.data.user_id) return '';
                    var parts = [];
                    if (d.data.level) {
                        parts.push((i18n.level || 'Level') + ' ' + d.data.level);
                    }
                    parts.push(d.data.total_downline + ' ' + (i18n.downline || 'downline'));
                    return parts.join(' \u00b7 ');
                });

            // Refresh expand badges on existing nodes — important
            // because a node we previously rendered an expand badge
            // on might have just received its children, so the
            // badge needs to vanish.
            nodeSel.each(function (d) {
                renderExpandBadgeIfNeeded(d3.select(this), d);
                renderCommissionBadgeIfNeeded(d3.select(this), d);
            });

            if (fit) {
                fitToScreen(/* animate = */ false);
            }
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
            //   4. NOT in snapshot mode — there's no
            //      snapshot-aware fetch_subtree endpoint in v1, so
            //      a "Show more" click would silently fail. The
            //      snapshot tree is rendered up to plan depth so
            //      this is mostly a defensive check; it kicks in
            //      only when total_downline (recomputed from the
            //      snapshot subtree in build_tree_at_snapshot) is
            //      somehow > rendered children, which shouldn't
            //      happen but we guard anyway.
            var existing = nodeG.select('.mtx-expand-badge');
            var renderedChildren = (d.data.children && d.data.children.length) || 0;
            var hiddenDownline   = (d.data.total_downline || 0) - renderedChildren;
            var shouldShow = d.data.user_id
                && hiddenDownline > 0
                && (!d.data.children || d.data.children.length === 0)
                && !bootstrap.snapshot_mode;

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
        // Per-position commission attribution overlay — the "income
        // map".
        //
        // Renders a small green pill at the top-right of any node
        // whose user_id appears in commissionOverlay.byUserId with a
        // positive amount. Hidden when commissionOverlay.active is
        // false (the badge is removed entirely; CSS-driven hiding
        // would leave layout space allocated). Empty slots and the
        // viewer's own root card are skipped — empty slots have no
        // member to attribute, and the root by definition is the
        // viewer (a member can't earn commission *from themselves*).
        // ==============================================================
        function renderCommissionBadgeIfNeeded(nodeG, d) {
            var existing = nodeG.select('.mtx-commission-overlay');
            var userId   = d.data.user_id;

            // Bail conditions — same skipping policy as the docblock
            // above. Note we DO render on spillover/direct alike;
            // the income-map question is "did this person trigger
            // any commission for me", which is independent of how
            // they got into the tree.
            var shouldShow = commissionOverlay.active
                && commissionOverlay.loaded
                && userId
                && d.data.relationship !== 'you';

            var entry = shouldShow
                ? commissionOverlay.byUserId[userId]
                : null;

            // Non-contributors are explicitly NOT badged — the
            // absence of a badge is itself the signal in an
            // income-map view. A green pill on every node would
            // dilute the readability of the contributors.
            if (!shouldShow || !entry || !(entry.amount > 0)) {
                if (!existing.empty()) existing.remove();
                return;
            }

            // Re-render path: the amount could have changed if the
            // map was re-fetched after this node was first drawn
            // (rare today, but cheap to support). If a badge
            // already exists, just update its text.
            if (!existing.empty()) {
                existing.select('text.mtx-commission-overlay-amount')
                    .text(entry.amount_display || '');
                return;
            }

            // Position: top-right corner of the card body, sticking
            // out a touch (-4px on x, -6px on y from the corner)
            // so the badge reads as an applied price tag rather
            // than something baked into the card. Keeps the rect
            // inside the card visually clean for username + meta.
            var badge = nodeG.append('g')
                .attr('class', 'mtx-commission-overlay')
                .attr('transform', 'translate(' + (NODE_WIDTH / 2 - 4) + ', ' + (-NODE_HEIGHT / 2 - 6) + ')')
                .attr('aria-hidden', 'true'); // decorative — actual
                // value is in the title element below for SR users

            // Tooltip line. Browsers render <title> as a hover
            // tooltip on SVG and screen readers expose it as the
            // accessible name when aria-hidden is reset. We keep
            // the badge group aria-hidden because the income map
            // information is supplementary to the node's primary
            // identity; an SR user who wants attribution can use
            // the existing click-to-details flow.
            var tipTpl = (entry.count === 1)
                ? (i18n.commission_overlay_node_tip_one  || '%1$s earned from %2$s (%3$d payout)')
                : (i18n.commission_overlay_node_tip_many || '%1$s earned from %2$s (%3$d payouts)');
            badge.append('title')
                .text(tipTpl
                    .replace('%1$s', String(entry.amount_display || ''))
                    .replace('%2$s', String(d.data.username || ''))
                    .replace('%3$d', String(entry.count || 0))
                );

            // Pill geometry: width sized to text length, capped at
            // a max so a wildly long currency string doesn't blow
            // the card layout. The text is right-anchored so the
            // pill always hugs the card's top-right edge no matter
            // how long the amount is.
            var label   = String(entry.amount_display || '');
            var pillW   = Math.min(96, Math.max(46, 12 + label.length * 6.5));
            var pillH   = 18;

            badge.append('rect')
                .attr('class', 'mtx-commission-overlay-pill')
                .attr('x', -pillW)
                .attr('y', -pillH / 2)
                .attr('width', pillW)
                .attr('height', pillH)
                .attr('rx', pillH / 2)
                .attr('ry', pillH / 2);

            badge.append('text')
                .attr('class', 'mtx-commission-overlay-amount')
                .attr('text-anchor', 'end')
                .attr('x', -8)
                .attr('y', 4)
                .text(label);
        }

        // ==============================================================
        // Toggle the income-map overlay. Triggered by the toolbar
        // button's data-action="commission-overlay" handler. Three
        // possible transitions:
        //
        //   - off → loading → on  (first activation; fetches map)
        //   - on  → off            (cached map preserved, instant)
        //   - off → on             (no fetch, cached map reused)
        //
        // The button's aria-pressed and the canvas's
        // data-show-commissions are kept in sync so CSS can react
        // to either signal — useful for downstream extensions
        // (e.g. graying out the heatmap toggle while the income
        // map is on so members don't pile two overlays on top of
        // each other).
        // ==============================================================
        function toggleCommissionOverlay(btn) {
            // Fast path: deactivate. Keep the cache around so a
            // re-toggle is instant.
            if (commissionOverlay.active) {
                commissionOverlay.active = false;
                btn.setAttribute('aria-pressed', 'false');
                btn.setAttribute('title', i18n.commission_overlay_show || 'Show income map');
                canvas.removeAttribute('data-show-commissions');
                updateCommissionSummary();
                // Re-render to drop the badges. Animate=false
                // because we want immediate feedback on toggle —
                // a 400ms fade on a button click feels laggy.
                layoutAndRender(false, false);
                return;
            }

            // Activation path. Two sub-cases:
            //   - already loaded → just show.
            //   - not yet loaded → fetch, then show on success.
            commissionOverlay.active = true;
            btn.setAttribute('aria-pressed', 'true');
            btn.setAttribute('title', i18n.commission_overlay_hide || 'Hide income map');
            canvas.setAttribute('data-show-commissions', '1');

            if (commissionOverlay.loaded) {
                updateCommissionSummary();
                layoutAndRender(false, false);
                return;
            }

            // First-time fetch. Show the loading line in the
            // summary chip while the request is in flight; the
            // badges themselves don't appear until the response
            // lands and we re-render.
            commissionOverlay.loading = true;
            updateCommissionSummary();
            fetchCommissionAttribution(function (err) {
                commissionOverlay.loading = false;
                if (err) {
                    // Treat fetch failure as if the user toggled
                    // off — flip the button back, drop the
                    // canvas flag, surface the message via the
                    // existing flash UI. Keep loaded=false so a
                    // retry click triggers a new fetch.
                    commissionOverlay.active = false;
                    btn.setAttribute('aria-pressed', 'false');
                    btn.setAttribute('title', i18n.commission_overlay_show || 'Show income map');
                    canvas.removeAttribute('data-show-commissions');
                    updateCommissionSummary();
                    flashError(i18n.commission_overlay_error || 'Could not load commission attribution.');
                    return;
                }
                commissionOverlay.loaded = true;
                updateCommissionSummary();
                layoutAndRender(false, false);
            });
        }

        // ==============================================================
        // Fetch the per-position attribution map. Single round-trip
        // — the server walks the entire authorised subtree and
        // returns a sparse {user_id -> {amount, amount_display,
        // count}} so subsequent lazy-expanded subtrees find their
        // entries already loaded.
        //
        // Callback receives (err) — null on success.
        // ==============================================================
        function fetchCommissionAttribution(cb) {
            var endpoint = (window.matrixMLM && window.matrixMLM.ajaxUrl) || '';
            var nonce    = (window.matrixMLM && window.matrixMLM.nonce) || '';
            // Root position id lives on the bootstrap tree's root
            // node — that's the position the JSON endpoint will
            // traverse from. user_can_view_position() on the
            // server gates against the viewer's own ancestor
            // chain, so we don't need to pre-validate here.
            var rootPositionId = (bootstrap.tree && bootstrap.tree.id) || 0;
            if (!endpoint || !rootPositionId) {
                cb(new Error('missing endpoint or root'));
                return;
            }

            var body = new FormData();
            body.append('action', 'matrix_mlm_action');
            body.append('matrix_action', 'get_commission_attribution');
            body.append('nonce', nonce);
            body.append('position_id', String(rootPositionId));

            fetch(endpoint, {
                method: 'POST',
                body: body,
                credentials: 'same-origin'
            })
            .then(function (r) { return r.json(); })
            .then(function (response) {
                if (!response || !response.success || !response.data) {
                    cb(new Error('bad response'));
                    return;
                }
                var data = response.data;
                // The server keys attribution by user_id (numeric)
                // but JSON object keys are strings, so we copy
                // into a numeric-keyed dict for fast lookup
                // during render. Object.create(null) keeps the
                // prototype chain clean — important because some
                // members have user_ids that collide with
                // built-in Object members (e.g. user_id 1
                // matching __proto__'s neighbours on toString).
                var byUserId = Object.create(null);
                if (data.attribution && typeof data.attribution === 'object') {
                    for (var k in data.attribution) {
                        if (Object.prototype.hasOwnProperty.call(data.attribution, k)) {
                            var uid = Number(k);
                            if (uid > 0) {
                                byUserId[uid] = data.attribution[k];
                            }
                        }
                    }
                }
                commissionOverlay.byUserId = byUserId;
                commissionOverlay.total    = data.total || null;
                commissionOverlay.currency = data.currency || commissionOverlay.currency;
                commissionOverlay.capped   = !!data.capped;
                cb(null);
            })
            .catch(function (err) {
                if (window.console && console.warn) {
                    console.warn('[matrix-genealogy-d3] commission attribution fetch failed', err);
                }
                cb(err || new Error('network'));
            });
        }

        // ==============================================================
        // Maintain the corner summary chip. Created lazily on first
        // activation and reused across toggles (display:none when
        // hidden so we don't re-pay layout cost on every toggle).
        // ==============================================================
        function updateCommissionSummary() {
            // Lazy create.
            if (!commissionSummaryEl) {
                commissionSummaryEl = document.createElement('div');
                commissionSummaryEl.className = 'matrix-genealogy-d3-commission-summary';
                commissionSummaryEl.setAttribute('aria-live', 'polite');
                canvas.appendChild(commissionSummaryEl);
            }

            if (!commissionOverlay.active) {
                commissionSummaryEl.style.display = 'none';
                commissionSummaryEl.textContent = '';
                return;
            }

            commissionSummaryEl.style.display = '';

            if (commissionOverlay.loading) {
                commissionSummaryEl.textContent = i18n.commission_overlay_loading || 'Loading income map…';
                return;
            }

            var total   = commissionOverlay.total || { amount: 0, amount_display: '', members: 0 };
            var members = total.members || 0;
            var line;
            if (members === 0) {
                line = i18n.commission_overlay_summary_zero || 'No paid commissions attributed to this tree yet';
            } else {
                var tpl = (members === 1)
                    ? (i18n.commission_overlay_summary_one  || 'You\'ve earned %1$s from %2$d member in this tree')
                    : (i18n.commission_overlay_summary_many || 'You\'ve earned %1$s from %2$d members in this tree');
                line = tpl
                    .replace('%1$s', String(total.amount_display || ''))
                    .replace('%2$d', String(members));
            }
            // Append the cap warning when the server told us it
            // truncated the descendant walk. Members with
            // smaller trees never see this text — capped is
            // server-side authoritative.
            if (commissionOverlay.capped) {
                var cappedNote = i18n.commission_overlay_capped || '';
                if (cappedNote) line = line + ' — ' + cappedNote;
            }
            commissionSummaryEl.textContent = line;
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

            // Snapshot mode: suppress the click-to-details popover.
            // The popover queries node_details which returns
            // CURRENT member data (joined date, current sponsor,
            // current commission running total) — showing those
            // figures alongside a historical tree card would be
            // actively misleading rather than just unhelpful.
            // Members who want member details navigate back to
            // the live tree first, which the snapshot banner's
            // "Back to live tree" button makes one click away.
            if (bootstrap.snapshot_mode) return;

            // Click on a node that's already open (typically because
            // hover opened it a moment ago) toggles the popover
            // closed — preserves the original "click is sticky"
            // mental model, while letting hover act as the cheap
            // peek.
            clearHoverTimers();
            if (openDetails && openDetails.positionId === d.data.id) {
                closeDetails();
                return;
            }

            // All other cases: open / replace. The shared helper
            // handles the spawn + AJAX + render flow so the click
            // and hover paths can't drift out of sync.
            openDetailsForNode(d);
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

            // Keep the panel open while the cursor is over it —
            // otherwise mouseleave on the source node fires a
            // close timer that would yank the panel out from under
            // a member trying to read it or click the
            // "View their tree →" link inside. Re-arm the close
            // timer on panel-leave so the bridge from one node to
            // another still works the same way.
            panel.addEventListener('mouseenter', clearHoverTimers);
            panel.addEventListener('mouseleave', function () {
                if (!supportsHoverD3) return;
                clearHoverTimers();
                hoverCloseTimer = setTimeout(function () {
                    hoverCloseTimer = null;
                    closeDetails();
                }, HOVER_CLOSE_DELAY_MS);
            });

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

        // ==============================================================
        // Hover-to-open details — desktop only.
        //
        // The classic recursive-HTML view above this canvas wires the
        // shared #matrix-tree-hovercard element to its own
        // .matrix-tree-node listeners, so members on the classic
        // toggle have always seen member info on hover. The D3 view
        // never had that affordance — only click-to-open. The
        // unified UX expectation is "hover anywhere on a member
        // card → see who they are", so we mirror that here for the
        // SVG nodes.
        //
        // We deliberately reuse the existing in-canvas details panel
        // (spawnDetailsPanel/fillDetailsPanel) rather than the page-
        // level hovercard the classic view uses, for two reasons:
        //
        //   1. The in-canvas panel is positioned in canvas-relative
        //      pixel space against an SVG <g>'s bounding rect — it
        //      already knows how to anchor to a D3 node. The page-
        //      level hovercard uses fixed viewport coords against a
        //      DOM trigger element, which doesn't translate cleanly
        //      to SVG.
        //   2. The two panel styles already differ by view (the
        //      click path was using the in-canvas one), so members
        //      see one consistent style per view, not a flicker
        //      between two when they alternate hover and click.
        //
        // Hover is gated on matchMedia(hover: hover) — touch devices
        // get the click-only path. Same gate the classic-view
        // hovercard uses, so the two views' UX stays consistent on
        // a tablet (tap to open, never hover-flash).
        //
        // Snapshot mode skips hover entirely for the same reason
        // click does: the details payload is current-state and
        // would mislead members reading historical structure.
        // ==============================================================
        var hoverOpenTimer  = null;
        var hoverCloseTimer = null;
        var supportsHoverD3 = !!(window.matchMedia && window.matchMedia('(hover: hover)').matches);
        var HOVER_OPEN_DELAY_MS  = 220;
        var HOVER_CLOSE_DELAY_MS = 180;

        function clearHoverTimers() {
            if (hoverOpenTimer)  { clearTimeout(hoverOpenTimer);  hoverOpenTimer  = null; }
            if (hoverCloseTimer) { clearTimeout(hoverCloseTimer); hoverCloseTimer = null; }
        }

        function onNodeMouseEnter(event, d) {
            if (!supportsHoverD3) return;
            if (!d.data.user_id) return;          // empty slot — nothing to show
            if (bootstrap.snapshot_mode) return;  // see docblock above
            // Already showing this exact node? Just keep it open
            // (clear any pending close from a microsecond-earlier
            // mouseleave on this same node — happens on the seam
            // between the SVG <g>'s child elements).
            if (openDetails && openDetails.positionId === d.data.id) {
                clearHoverTimers();
                return;
            }
            // Schedule open. Replaces any pending open/close from
            // a previous node so moving between adjacent cards
            // swaps content cleanly without flicker.
            clearHoverTimers();
            hoverOpenTimer = setTimeout(function () {
                hoverOpenTimer = null;
                openDetailsForNode(d);
            }, HOVER_OPEN_DELAY_MS);
        }

        function onNodeMouseLeave(event, d) {
            if (!supportsHoverD3) return;
            // If the mouse moved INTO the open details panel,
            // panel.mouseenter (wired in spawnDetailsPanel) will
            // cancel the close before the timeout fires. Same
            // shape the classic-view hovercard uses; lets members
            // mouse over to click the "View their tree →" link.
            clearHoverTimers();
            hoverCloseTimer = setTimeout(function () {
                hoverCloseTimer = null;
                closeDetails();
            }, HOVER_CLOSE_DELAY_MS);
        }

        // Open + populate the details panel for a given d3-hierarchy
        // node. Shared between click (synchronous, immediate) and
        // hover (delayed via the timers above). Returns early
        // without re-rendering when the same node is already open
        // — avoids flashing the loading spinner on a re-trigger.
        function openDetailsForNode(d) {
            if (!d || !d.data || !d.data.user_id) return;
            if (bootstrap.snapshot_mode) return;
            if (openDetails && openDetails.positionId === d.data.id) return;

            // Replace any currently-open panel (different node).
            closeDetails();

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
                // Member moved on before the response landed —
                // drop it. The new node's own request will paint
                // its own panel.
                if (!openDetails || openDetails.positionId !== d.data.id) return;
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
                    case 'commission-overlay':
                        // Lazy-fetch the attribution map on the
                        // first activation, then just flip the
                        // visibility flag on subsequent toggles.
                        // The button itself is the source of
                        // truth for aria-pressed; toggleCommission
                        // Overlay() updates it.
                        toggleCommissionOverlay(btn);
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

        // ==============================================================
        // Real-time polling — fetch_new_descendants every ~45s.
        //
        // Detail: the server returns the full set of descendants that
        // joined since the last poll's `server_time`, scoped to the
        // currently-displayed root and gated by the same
        // user_can_view_position auth check the rest of the genealogy
        // endpoints use. We graft each new node into the local
        // `dataRoot` keyed by parent_id, refresh ancestor
        // total_downline counts, and re-run layoutAndRender(true,
        // false). The CSS pulse keyframe (`.mtx-node-new`, defined in
        // matrix-dashboard.css) handles the visual celebration.
        //
        // Lifecycle guards:
        //   - Pause when document.visibilityState === 'hidden' so a
        //     buried tab doesn't keep hammering admin-ajax for nothing.
        //   - Pause when navigator.onLine === false to avoid futile
        //     fetches that would just throw.
        //   - Single in-flight guard: never two polls overlapping.
        //   - On visibility/online restore: fire one immediate poll so
        //     a member returning to the tab gets caught up without
        //     waiting for the next interval.
        //
        // The `since` value we send is always the previous response's
        // server_time, never a client clock — keeps the contract free
        // of clock-skew ambiguity. The server caps `since` to the last
        // 24 hours regardless.
        // ==============================================================
        var POLL_INTERVAL_MS    = 45000;          // 45s — middle of the
                                                  // 30–60s "feels live"
                                                  // band without
                                                  // hammering the server.
        var POLL_REQUEST_TIMEOUT_MS = 15000;
        var PULSE_DURATION_MS   = 4000;           // matches the CSS
                                                  // keyframe duration
                                                  // (1.4s × 2 cycles +
                                                  // a small grace).

        var pollTimer       = null;
        var pollInFlight    = false;
        var lastServerTime  = 0;
        var documentVisible = !document.hidden;

        function startPolling() {
            // Defensive: if the bootstrap is missing the fields we
            // need (root position id, plan id), there's nothing to
            // poll against. Fail open — the static tree still
            // works, members just won't get live updates.
            if (!bootstrap || !bootstrap.tree || !bootstrap.tree.id || !bootstrap.plan_id) {
                return;
            }

            // Seed `since` to ~60s before now so the first poll
            // catches anyone who joined in the gap between the
            // server-side page render and the first poll firing.
            // Subsequent polls overwrite this with the response's
            // server_time, which is the authoritative tick.
            lastServerTime = Math.floor(Date.now() / 1000) - 60;

            document.addEventListener('visibilitychange', onVisibilityChange);
            window.addEventListener('online', onConnectivityRestored);

            pollTimer = setTimeout(pollTick, POLL_INTERVAL_MS);
        }

        function pollTick() {
            pollTimer = null;

            // Skip-but-reschedule when conditions aren't right. We
            // don't drop the loop entirely on a single skip because
            // the conditions can flip mid-interval (member
            // unhides the tab) and we want the next interval to
            // re-evaluate naturally.
            if (!documentVisible
                || (typeof navigator !== 'undefined' && navigator.onLine === false)
                || pollInFlight) {
                pollTimer = setTimeout(pollTick, POLL_INTERVAL_MS);
                return;
            }

            pollInFlight = true;
            pollNewDescendants()
                .catch(function (err) {
                    // Silent retry — a transient network blip
                    // shouldn't surface a toast to the member.
                    // The error flash is reserved for explicit
                    // failures that block functionality
                    // (lazy-expand failures, etc.). Polling
                    // failures degrade gracefully into "you'll
                    // see it on next reload".
                    if (window.console && console.warn) {
                        console.warn('[matrix-genealogy-d3] poll failed', err);
                    }
                })
                .then(function () {
                    pollInFlight = false;
                    pollTimer = setTimeout(pollTick, POLL_INTERVAL_MS);
                });
        }

        function pollNewDescendants() {
            var endpoint = (window.matrixMLM && window.matrixMLM.ajaxUrl) || '';
            var nonce    = (window.matrixMLM && window.matrixMLM.nonce) || '';
            if (!endpoint || !nonce) return Promise.resolve();

            var body = new FormData();
            body.append('action', 'matrix_mlm_action');
            body.append('matrix_action', 'fetch_new_descendants');
            body.append('nonce', nonce);
            body.append('plan_id',     String(bootstrap.plan_id));
            body.append('position_id', String(bootstrap.tree.id));
            body.append('since',       String(lastServerTime));

            // AbortController-based timeout so a slow-network
            // poll doesn't pile up across intervals. Older
            // browsers without AbortController just skip the
            // timeout — fetch's own implicit timeout will catch
            // pathological cases.
            var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            var timeoutId = null;
            if (ctrl) {
                timeoutId = setTimeout(function () { ctrl.abort(); }, POLL_REQUEST_TIMEOUT_MS);
            }

            return fetch(endpoint, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                signal: ctrl ? ctrl.signal : undefined
            })
            .then(function (r) {
                if (timeoutId) clearTimeout(timeoutId);
                return r.json();
            })
            .then(function (response) {
                if (!response || !response.success || !response.data) return;
                applyPollResponse(response.data);
            });
        }

        function applyPollResponse(data) {
            // Advance the polling cursor BEFORE any work — even if
            // the graft logic throws, the next poll should still
            // pick up where this one was meant to leave off, not
            // re-fetch the same window. The server already capped
            // server_time at request time, so this can't drift
            // forward.
            if (typeof data.server_time === 'number' && data.server_time > 0) {
                lastServerTime = data.server_time;
            }

            var newNodes      = Array.isArray(data.new_nodes) ? data.new_nodes : [];
            var parentUpdates = Array.isArray(data.updated_parents) ? data.updated_parents : [];

            if (!newNodes.length && !parentUpdates.length) return;

            // Build a fast id→node map over the live tree. Cheaper
            // than running selectAll().filter() for every graft on
            // a wide matrix.
            var nodeIndex = indexNodesById(dataRoot);

            // Apply parent updates first — even when no new visible
            // nodes are returned (e.g. the descendant joined under
            // an unexpanded branch), the ancestor counts still tick
            // up and the meta-line refresh in layoutAndRender will
            // surface the change.
            parentUpdates.forEach(function (p) {
                var n = nodeIndex[p.id];
                if (n) {
                    var fresh = Number(p.total_downline);
                    if (!isNaN(fresh)) n.total_downline = fresh;
                }
            });

            var insertedCount = 0;
            var insertedRows  = [];
            newNodes.forEach(function (raw) {
                var id = Number(raw.id) || 0;
                if (id <= 0) return;

                if (nodeIndex[id]) {
                    // Race: this node already arrived via a
                    // lazy-expand that fired between the server
                    // building the response and the client
                    // applying it. Treat the poll's data as the
                    // freshest source for total_downline only —
                    // never overwrite the local children array
                    // (we may have additional descendants loaded
                    // that this poll doesn't include).
                    var existing = nodeIndex[id];
                    var fresh    = Number(raw.total_downline);
                    if (!isNaN(fresh)) existing.total_downline = fresh;
                    return;
                }

                var parentId = raw.parent_id ? Number(raw.parent_id) : 0;
                var parent   = parentId > 0 ? nodeIndex[parentId] : null;
                if (!parent) {
                    // Parent isn't on screen — sits below the
                    // currently-rendered depth (e.g. a deep
                    // spillover landed under a branch we haven't
                    // expanded). The ancestor parent_updates we
                    // already applied will surface the bumped
                    // count on the closest visible ancestor; we
                    // skip the per-node graft to avoid creating
                    // an orphan.
                    return;
                }

                if (!Array.isArray(parent.children)) parent.children = [];

                var grafted = {
                    id:             id,
                    user_id:        Number(raw.user_id || 0),
                    sponsor_id:     raw.sponsor_id ? Number(raw.sponsor_id) : null,
                    username:       String(raw.username || ('User #' + (raw.user_id || 0))),
                    level:          Number(raw.level || 0),
                    total_downline: Number(raw.total_downline || 0),
                    status:         String(raw.status || ''),
                    relationship:   String(raw.relationship || 'spillover'),
                    children:       [],
                    _isNew:         true
                };
                parent.children.push(grafted);
                nodeIndex[id] = grafted;
                insertedCount++;
                insertedRows.push(raw);
            });

            if (insertedCount > 0 || parentUpdates.length > 0) {
                // Animate the relayout when there's a visible new
                // node — the smooth shift makes the pulse feel like
                // a natural arrival rather than a snap. For
                // count-only updates (parents bumped but no new
                // visible node), skip the transition: the meta line
                // text just updates in place, and animating
                // identical positions would be a confusing no-op.
                layoutAndRender(insertedCount > 0, false);
            }

            if (insertedCount > 0) {
                announceNewMembers(insertedCount, insertedRows);
                schedulePulseClear();
            }
        }

        // Announce N new members via a success-flavoured toast pinned
        // at the top of the canvas. Mirrors the error toast's lifetime
        // (~4.5s) so the two feel like the same affordance with
        // different semantics.
        function announceNewMembers(count, rows) {
            // Reuse the same DOM slot as the error flash so the
            // newest message always wins — no stacking.
            var existing = canvas.querySelector('.matrix-genealogy-d3-flash');
            if (existing) existing.parentNode.removeChild(existing);

            var template;
            var msg;
            if (count === 1) {
                template = i18n.new_referral_one || '%s just joined your tree!';
                msg = template.replace(/%s/g, (rows[0] && rows[0].username) ? rows[0].username : 'A new member');
            } else {
                template = i18n.new_referral_many || '%d new referrals just joined your tree!';
                msg = template.replace(/%d/g, String(count));
            }

            var el = document.createElement('div');
            el.className = 'matrix-genealogy-d3-flash matrix-genealogy-d3-flash--success';
            // role=status + aria-live=polite means screen readers
            // will announce the toast without interrupting whatever
            // the member is reading — same dopamine for AT users.
            el.setAttribute('role', 'status');
            el.setAttribute('aria-live', 'polite');
            el.textContent = msg;
            canvas.appendChild(el);

            setTimeout(function () {
                if (el.parentNode) el.parentNode.removeChild(el);
            }, 4500);
        }

        // Clear `_isNew` flags after the pulse animation completes
        // and synchronise the SVG class list. Without this step a
        // future re-render (lazy expand, resize, another poll) would
        // re-pulse the same nodes, which would feel chaotic.
        function schedulePulseClear() {
            setTimeout(function () {
                walkData(dataRoot, function (n) {
                    if (n && n._isNew) delete n._isNew;
                });
                nodesLayer.selectAll('g.mtx-node.mtx-node-new')
                    .classed('mtx-node-new', false);
            }, PULSE_DURATION_MS);
        }

        function walkData(node, fn) {
            if (!node) return;
            fn(node);
            if (node.children && node.children.length) {
                for (var i = 0; i < node.children.length; i++) {
                    walkData(node.children[i], fn);
                }
            }
        }

        function indexNodesById(root) {
            var idx = Object.create(null);
            walkData(root, function (n) {
                if (n && n.id) idx[n.id] = n;
            });
            return idx;
        }

        function onVisibilityChange() {
            documentVisible = !document.hidden;
            if (documentVisible && !pollTimer && !pollInFlight) {
                // Fire immediately so a member returning from
                // another tab gets the "your tree grew while you
                // were gone" hit without a 45s wait.
                pollTimer = setTimeout(pollTick, 0);
            }
        }

        function onConnectivityRestored() {
            if (documentVisible && !pollTimer && !pollInFlight) {
                pollTimer = setTimeout(pollTick, 0);
            }
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
