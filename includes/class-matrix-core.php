<?php
/**
 * Core Plugin Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Core {

    protected $admin;
    protected $user_dashboard;

    public function __construct() {
        $this->define_hooks();
    }

    public function run() {
        // The post-upgrade page-cache purge that used to live here was
        // moved to maybe_purge_caches_after_upgrade(), hooked on
        // `init` priority 99 — see define_hooks() below. Firing it
        // from run() (which executes on plugins_loaded priority 10)
        // was too early: LiteSpeed Cache's litespeed_purge_all
        // handler internally calls __('...', 'litespeed-cache'),
        // which forces the LiteSpeed textdomain to load before
        // WordPress's standard init-priority-1 textdomain bootstrap.
        // WP 6.7 raises a _doing_it_wrong notice for "translation
        // loading triggered too early"; that notice echoes into the
        // response stream, which then makes every subsequent
        // header() call fail with "headers already sent" — including
        // the wp-admin/admin-header.php redirects that fire on the
        // very first page load after upgrade. End-user symptom: a
        // wall of PHP notices on top of every admin page, and the
        // login redirect chain broken until the textdomain notice
        // stops emitting. Deferring to init priority 99 lets every
        // page-cache plugin's textdomain load normally on init
        // priority 1 first, so the purge handler can translate
        // its own strings without triggering the early-load
        // detector.

        // Self-healing schema upgrade: run any pending migrations on load
        // so admins don't have to deactivate/reactivate after a plugin update.
        if (class_exists('Matrix_MLM_Database')) {
            Matrix_MLM_Database::maybe_upgrade();
        }

        // Self-healing seed: ensure default gateways exist on every load.
        // The admin gateways page also calls this when rendering, so it works
        // regardless of which page the user visits first.
        if (class_exists('Matrix_MLM_Admin_Gateways')) {
            Matrix_MLM_Admin_Gateways::ensure_default_gateways();
        }

        // Self-healing seed: ensure Fintava tables exist on every load.
        if (class_exists('Matrix_MLM_Fintava')) {
            Matrix_MLM_Fintava::ensure_tables_exist();
        }

        // Ensure default root user exists for the referral system
        if (class_exists('Matrix_MLM_Database')) {
            Matrix_MLM_Database::create_default_user();
        }

        // Initialize components
        if (is_admin()) {
            $this->admin = new Matrix_MLM_Admin();
        }
        $this->user_dashboard = new Matrix_MLM_User_Dashboard();

        // Initialize Fintava gateway (registers AJAX hooks)
        new Matrix_MLM_Fintava();

        // Initialize Fintava Card (registers card AJAX hooks)
        new Matrix_MLM_Fintava_Card();

        // Initialize Fintava Billing (registers billing AJAX hooks)
        new Matrix_MLM_Fintava_Billing();

        // Initialize CUG (Closed User Group) handler — its
        // constructor registers the wp_ajax_matrix_submit_cug
        // endpoint that the Benefits-tab CUG card form posts to.
        new Matrix_MLM_User_CUG();

        // Initialize Loan handler — its constructor registers the
        // wp_ajax_matrix_submit_loan endpoint that the Benefits-tab
        // loans card application form posts to.
        new Matrix_MLM_User_Loan();

        // Initialize Healthcare handler — its constructor registers
        // the wp_ajax_matrix_submit_healthcare endpoint that the
        // Benefits-tab healthcare card application form posts to.
        new Matrix_MLM_User_Healthcare();

        // Register in-app notification hooks (1.0.15). Wires the
        // three AJAX endpoints (fetch, mark_read, mark_all_read)
        // that drive the dashboard sidebar bell, plus the daily
        // cron cleanup of read rows older than 90 days. Idempotent
        // — safe to call once per pageload alongside the other
        // run-time bootstraps above.
        Matrix_MLM_In_App_Notifications::register_hooks();

        // Register the Zebra Wallet user-facing payout AJAX hook.
        // Static so we don't pay the cost of constructing the
        // gateway just to register a single endpoint — the
        // instance gets built lazily inside the handler. Mirrors
        // the deposit / OTP flow's lazy-construct pattern in
        // Matrix_MLM_Core::process_zebra_complete_otp.
        if (class_exists('Matrix_MLM_Zebra')) {
            Matrix_MLM_Zebra::register_user_payout_hooks();
        }

        // Initialize monthly subscription
        new Matrix_MLM_Subscription();

        // Register password reset email hooks
        Matrix_MLM_Notifications::register_password_reset_hooks();
    }

    private function define_hooks() {
        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', [$this, 'enqueue_public_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // Livechat surfaces (custom embed code + WhatsApp click-to-
        // chat button). Both gated by the master livechat toggle on
        // Settings -> Livechat AND each surface's own per-surface
        // toggle. Pre-existing behaviour stored matrix_mlm_livechat_code
        // but never actually rendered it on the public site (no hook
        // was registered) — this single hook now wires the existing
        // custom-code surface as a side effect of wiring the new
        // WhatsApp surface, so installs that already had embed code
        // saved start rendering it without any further admin action.
        add_action('wp_footer', [$this, 'render_livechat_surfaces']);

        // Register shortcodes
        add_action('init', [$this, 'register_shortcodes']);

        // AJAX handlers
        add_action('wp_ajax_matrix_mlm_action', [$this, 'handle_ajax']);
        add_action('wp_ajax_nopriv_matrix_mlm_action', [$this, 'handle_public_ajax']);

        // REST API
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Cron events
        add_action('matrix_mlm_daily_cron', [$this, 'daily_cron']);

        // Schedule cron if not scheduled
        if (!wp_next_scheduled('matrix_mlm_daily_cron')) {
            wp_schedule_event(time(), 'daily', 'matrix_mlm_daily_cron');
        }

        // Custom rewrite rules
        add_action('init', [$this, 'add_rewrite_rules']);
        add_filter('query_vars', [$this, 'add_query_vars']);

        // Email verification handler
        add_action('init', [$this, 'handle_email_verification']);

        // Redirect users to the Matrix login page after they log out.
        //
        // The dashboard's logout link already passes a redirect_to
        // value via wp_logout_url(), but logouts can also originate
        // from the WP admin bar, an expired-session redirect to
        // wp-login.php?action=logout, or any third-party plugin
        // that calls wp_logout(). Registering this filter ensures
        // every logout path lands on /matrix-login so users never
        // get dropped onto the default wp-login.php?loggedout=true
        // screen, which doesn't match the plugin's branded UI.
        add_filter('logout_redirect', [$this, 'filter_logout_redirect'], 10, 3);

        // Hide the WordPress admin bar on the front-end for everyone
        // except admins. The black "Howdy, <user>" bar is part of the
        // WP core chrome; for an MLM platform it leaks the underlying
        // CMS surface to members who shouldn't see WP-flavoured links
        // (Edit Profile, dashboard shortcuts, the WP logo menu) and
        // it visually clashes with the branded header above the
        // /matrix-dashboard shortcode. The filter runs early enough
        // that wp_head() never emits the bar's CSS for filtered users
        // and the bar's <div id="wpadminbar"> never reaches the DOM,
        // so this is purely a server-side suppression — no FOUC and
        // no CSS override needed. Admins (capability manage_options)
        // keep the bar so they can still jump back into wp-admin from
        // any front-end page.
        add_filter('show_admin_bar', [$this, 'filter_show_admin_bar']);

        // Suppress HTTP-level caching of any page that hosts the
        // [matrix_dashboard] shortcode. Reported symptom: after a
        // successful Wallet→Wallet transfer or e-pin recharge the
        // user sees a flash of the old balance until they manually
        // clear the app cache. Root cause is that the dashboard
        // page is served from an HTTP-level cache (browser cache,
        // server-side full-page cache, CDN edge cache) so the
        // post-transaction location.reload() in the inline JS gets
        // the stale HTML back even though the database is correct.
        //
        // Calling nocache_headers() here adds the standard
        // no-store/no-cache/must-revalidate header trio early in
        // the request lifecycle (before the shortcode emits any
        // output and therefore before the headers are flushed),
        // which all standards-compliant caches honour. We gate it
        // on actually being on a dashboard-bearing page so we
        // don't poison cacheability of the marketing/landing pages
        // — `wp` fires after the main query is set up, so $post
        // and has_shortcode() are usable at that point.
        add_action('wp', [$this, 'nocache_dashboard_pages']);

        // One-time stale-cache purge after a plugin upgrade.
        //
        // Hooked on `init` priority 99 (rather than firing inline
        // during plugins_loaded, where it lived in the original
        // PR #327) for one specific reason: LiteSpeed Cache's
        // `litespeed_purge_all` handler internally translates a few
        // of its own status strings via __('…', 'litespeed-cache').
        // WordPress 6.7 raises a `_doing_it_wrong` notice if a
        // textdomain loads before `init`, and the notice text
        // emits into the response stream — which then breaks the
        // first header() call with "headers already sent",
        // including the wp-admin/admin-header.php redirects that
        // fire on the first page load after upgrade. Deferring
        // until after init priority 1 (where WP loads textdomains
        // by default) means LiteSpeed's translation lookups
        // happen at a moment WP considers safe.
        //
        // Priority 99 (rather than 1, 5, or 10) is deliberate so
        // that any other plugin hooked on init that might want to
        // be running before our purge fires has already run — the
        // purge action handlers in LSCWP / WP Rocket / W3TC walk
        // their own state machines and we want those settled before
        // we fan out.
        add_action('init', [$this, 'maybe_purge_caches_after_upgrade'], 99);
    }

    public function enqueue_public_assets() {
        wp_enqueue_style(
            'matrix-mlm-public',
            MATRIX_MLM_PLUGIN_URL . 'public/css/matrix-public.css',
            [],
            MATRIX_MLM_VERSION
        );

        wp_enqueue_style(
            'matrix-mlm-dashboard',
            MATRIX_MLM_PLUGIN_URL . 'public/css/matrix-dashboard.css',
            [],
            MATRIX_MLM_VERSION
        );

        // Enqueue in the HEAD (not the footer). Pre-fix this used
        // $in_footer=true, which — combined with the 'jquery' dep —
        // caused WordPress to push jQuery into the footer too. The
        // dashboard then emits inline <script> blocks in the body
        // (wallet, verve card, bills payment, benefits, virtual
        // wallet, bank payout, pay-subscription) that reference
        // jQuery synchronously at parse time. On a fresh pageload
        // jQuery hadn't downloaded yet when those bodies parsed,
        // so the inline IIFEs threw ReferenceError, none of the
        // click/submit handlers bound, and clicks silently did
        // nothing. A manual refresh masked it: jQuery was now in
        // browser cache, arrived in time on the second parse, and
        // every handler bound — exactly the user-reported
        // "buttons don't work until I refresh" symptom across
        // Wallet, Verve Card, Bill Payments, and Benefits.
        //
        // Loading in the head pushes jQuery into the head as well
        // (because of the dep), so every inline body <script> now
        // has jQuery available at parse time. The existing
        // whenJQueryReady polling guards in those inline scripts
        // stay as defence-in-depth for installs whose theme or
        // performance plugin (Astra, GeneratePress, OceanWP,
        // WP Rocket, SG Optimizer, FlyingPress, Perfmatters,
        // Cloudflare Rocket Loader, etc.) re-defers jQuery to
        // the footer despite this enqueue.
        //
        // Cost: jQuery (~33 KB gzipped) and matrix-public.js move
        // from the footer to the head, which adds a few hundred
        // milliseconds to first paint on cold loads. Acceptable
        // tradeoff for correctness — the dashboard pages weren't
        // functional without it.
        wp_enqueue_script(
            'matrix-mlm-public',
            MATRIX_MLM_PLUGIN_URL . 'public/js/matrix-public.js',
            ['jquery'],
            MATRIX_MLM_VERSION,
            false
        );

        wp_localize_script('matrix-mlm-public', 'matrixMLM', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('matrix-mlm/v1/'),
            'nonce' => wp_create_nonce('matrix_mlm_nonce'),
            'currency' => get_option('matrix_mlm_currency_symbol', '₦'),
            'siteUrl' => home_url(),
            'paystackKey' => (new Matrix_MLM_Paystack())->get_public_key(),
            'flutterwaveKey' => (new Matrix_MLM_Flutterwave())->get_public_key(),
            'userEmail' => is_user_logged_in() ? wp_get_current_user()->user_email : '',
            'userName' => is_user_logged_in() ? wp_get_current_user()->display_name : '',
        ]);

        // In-app notifications stylesheet + script (1.0.15). The
        // script is loaded only on the dashboard route — there's no
        // bell icon anywhere else on the site, so on landing pages
        // and other shortcode hosts we skip both. Detection mirrors
        // the nocache_dashboard_pages() gate: a singular page whose
        // post body carries the [matrix_dashboard] shortcode. On
        // those pages it loads in the head with jQuery as a no-op
        // dependency (the bell file is jQuery-free for CSP-safety
        // but we declare jquery as a dep so the script tag's load
        // order matches the rest of the dashboard's head-loaded
        // scripts).
        $is_dashboard_page = false;
        if (is_singular()) {
            global $post;
            if ($post && is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'matrix_dashboard')) {
                $is_dashboard_page = true;
            }
        }

        if ($is_dashboard_page) {
            wp_enqueue_style(
                'matrix-mlm-notifications',
                MATRIX_MLM_PLUGIN_URL . 'public/css/matrix-notifications.css',
                ['matrix-mlm-dashboard'],
                MATRIX_MLM_VERSION
            );

            wp_enqueue_script(
                'matrix-mlm-notifications',
                MATRIX_MLM_PLUGIN_URL . 'public/js/matrix-notifications.js',
                [], // No deps — the bell is intentionally jQuery-free
                    // and only reads matrixMLM via window lookup, so
                    // strict-CSP installs that block inline scripts
                    // (which prevents matrix-public.js's IIFE from
                    // running) still get a working notification
                    // bell from this file alone.
                MATRIX_MLM_VERSION,
                false  // head-load so the bell is interactive as
                       // soon as the user can see it.
            );

            wp_localize_script('matrix-mlm-notifications', 'matrixNotifConfig', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('matrix_mlm_nonce'),
                // Polling cadence — 60s per the design doc. Capped
                // server-side at >=15s by the JS bootstrap to
                // prevent a misconfigured filter from hammering the
                // server.
                'pollMs'  => (int) apply_filters('matrix_mlm_notifications_poll_ms', 60000),
                'seeAllUrl' => Matrix_MLM_User_Dashboard::tab_url('notifications'),
                'l10n' => [
                    'empty'  => __("No notifications yet. We'll let you know here when something happens.", 'matrix-mlm'),
                    'unread' => __('Unread', 'matrix-mlm'),
                ],
            ]);
        }

        // Register (but don't enqueue) the D3.js genealogy view scripts.
        //
        // D3 is ~273 KB minified — significant enough that we don't
        // want to ship it on every page of the site. We only need
        // it where the genealogy tab actually renders. The previous
        // approach gated the enqueue on has_shortcode() against
        // $post->post_content, but that check is brittle: it returns
        // false whenever the dashboard is hosted by anything other
        // than a stock published Page whose body literally contains
        // "[matrix_dashboard]" — page-builder blocks (Elementor,
        // Bricks, etc.), FSE template parts, theme-template-injected
        // shortcodes, and custom post type wrappers all bypass the
        // detection and silently strand the scripts. The user-
        // visible symptom was the genealogy "Interactive view"
        // hanging on its loading shimmer because the JS module
        // never loaded to either mount the SVG or fail-over to the
        // classic tree.
        //
        // The fix is to register the scripts here unconditionally
        // (so the WP registry knows about them) and let
        // Matrix_MLM_User_Genealogy::render_d3_view() call
        // wp_enqueue_script() by handle when it actually emits the
        // canvas. wp_enqueue_script can be invoked late from inside
        // a shortcode because both scripts are footer-loaded
        // (true as the 5th arg) — wp_print_footer_scripts fires
        // after the_content, so anything enqueued during shortcode
        // rendering still makes it into the output. This binds
        // script lifetime to actual usage instead of guessing
        // from page content, eliminating the failure mode
        // entirely.
        //
        // Loading D3 BEFORE the genealogy module ensures `window.d3`
        // is defined by the time matrix-genealogy-d3.js executes.
        wp_register_script(
            'matrix-mlm-d3-vendor',
            MATRIX_MLM_PLUGIN_URL . 'public/vendor/d3/d3.v7.min.js',
            [],
            '7.9.0',
            true
        );

        wp_register_script(
            'matrix-mlm-genealogy-d3',
            MATRIX_MLM_PLUGIN_URL . 'public/js/matrix-genealogy-d3.js',
            ['matrix-mlm-d3-vendor', 'matrix-mlm-public'],
            MATRIX_MLM_VERSION,
            true
        );
    }

    /**
     * Send no-cache HTTP headers on any page that hosts the
     * [matrix_dashboard] shortcode.
     *
     * Hooked on `wp` (after the main query is set up, before any
     * output is sent) so $post is populated and we can detect the
     * shortcode without scanning the whole site. Limiting to
     * singular() avoids touching archive/feed responses where the
     * dashboard shortcode wouldn't run anyway, and the
     * `has_shortcode($post->post_content, ...)` check keeps
     * marketing/landing pages cacheable.
     *
     * Pairs with the JS-side cache-busting reload in
     * matrix-public.js (matrixMLMReload): the headers stop
     * intermediate caches from storing the dashboard HTML, and the
     * cache-busting URL on reload makes sure even non-compliant
     * caches (Cloudflare APO, some hosting-provider edge caches)
     * still serve a fresh page after a balance-changing action.
     */
    public function nocache_dashboard_pages() {
        if (!is_singular()) {
            return;
        }
        global $post;
        if (!$post || !is_a($post, 'WP_Post')) {
            return;
        }
        if (!has_shortcode($post->post_content, 'matrix_dashboard')) {
            return;
        }

        // Layer 1: standard HTTP Cache-Control + Expires headers.
        // Honoured by the browser and by RFC-compliant intermediate
        // caches. Was the only layer this method covered before —
        // sufficient for browser cache, but ignored by every
        // server-side full-page cache plugin/module on the market,
        // which is why logged-in users were seeing stale wallet
        // balances after transfers when LiteSpeed was in front.
        nocache_headers();

        // Layer 2: the DONOTCACHE* constants. Honoured by WP Rocket,
        // W3 Total Cache, WP Super Cache, WP Fastest Cache, Comet
        // Cache, and WP Engine's built-in page cache. LiteSpeed Cache
        // also reads DONOTCACHEPAGE — but only when its "Cache
        // Logged-in Users" toggle is OFF, which is NOT the default on
        // most cPanel/CloudLinux LiteSpeed hosting plans. Layer 3
        // covers the on-by-default case.
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (!defined('DONOTCACHEDB')) {
            define('DONOTCACHEDB', true);
        }
        if (!defined('DONOTCACHEOBJECT')) {
            define('DONOTCACHEOBJECT', true);
        }

        // Layer 3: LiteSpeed Cache plugin (the WordPress-side half
        // of the LiteSpeed stack). LSCWP exposes this action so
        // plugins can opt the current request out of caching even
        // when "Cache Logged-in Users" is on. No-op when LiteSpeed
        // is not installed, so it's safe to fire unconditionally.
        do_action('litespeed_control_set_nocache', 'matrix-mlm dashboard renders per-user wallet state');

        // Layer 4: LSCache web-server module (Apache mod_lsapi or
        // OpenLiteSpeed). The web-server reads
        // X-LiteSpeed-Cache-Control directly and makes its caching
        // decision before PHP returns to the request pool, so it
        // doesn't observe the litespeed_control_set_nocache hook
        // when the LSCWP plugin is not also installed (a real
        // configuration on shared LiteSpeed hosting where the host
        // runs LSCache at the server level but customers haven't
        // installed the WP plugin). Sending the header explicitly
        // covers that gap.
        if (!headers_sent()) {
            header('X-LiteSpeed-Cache-Control: no-cache');
        }
    }

    /**
     * One-time stale-cache purge fan-out after a plugin upgrade.
     *
     * Hooked on `init` priority 99 (see define_hooks). Runs once per
     * forward version change, no-op on every subsequent request.
     *
     * Why this exists at all: releases prior to the LiteSpeed
     * cache-control fix did not set DONOTCACHEPAGE,
     * litespeed_control_set_nocache, or X-LiteSpeed-Cache-Control on
     * dashboard pages — only the standard nocache_headers(). Customer
     * hosts running LiteSpeed (with "Cache Logged-in Users" enabled,
     * which is the default on most LiteSpeed shared-hosting plans)
     * accumulated full-page cached HTML snapshots of the dashboard
     * that survived past balance changes. nocache_dashboard_pages()
     * prevents NEW poisoned entries from being created, but pre-
     * existing snapshots on every customer site at the moment of
     * upgrade keep serving stale HTML until they naturally expire
     * (typically several hours, sometimes longer). Firing each
     * cache plugin's purge_all hook exactly once on the first
     * post-upgrade load evicts those legacy entries so members
     * see correct balances immediately after the version flip.
     *
     * Why init priority 99, not plugins_loaded:
     *
     * The previous revision called this from Matrix_MLM_Core::run()
     * (which is bound to plugins_loaded priority 10). LiteSpeed's
     * litespeed_purge_all handler translates a few of its own status
     * strings through __('…', 'litespeed-cache'). WordPress 6.7
     * raises a `_doing_it_wrong` notice when a textdomain loads
     * before `init`, and that notice text emits straight into the
     * response stream. The first subsequent header() call — including
     * the wp-admin/admin-header.php redirects — then fails with
     * "headers already sent (output started at functions.php:6170)",
     * which on the affected site presented as a wall of PHP notices
     * across every admin page load right after upgrade. Deferring
     * to init priority 99 lets every textdomain load through the
     * standard init priority 1 bootstrap before we trigger any
     * translation-bearing handlers.
     *
     * Each do_action() / function_exists() check below is a no-op
     * on hosts where that particular cache plugin isn't installed,
     * so the fan-out is safe to fire unconditionally.
     */
    public function maybe_purge_caches_after_upgrade() {
        $last_purged = (string) get_option('matrix_mlm_last_purged_version', '0.0.0');
        if (!version_compare($last_purged, MATRIX_MLM_VERSION, '<')) {
            return;
        }

        // Update the stamp BEFORE firing the purge handlers. If a
        // handler exits the request (an error, an exit() call deep
        // in someone else's code, a fatal in a translation lookup),
        // we still want the stamp to record that we attempted the
        // purge — better to skip a purge than to retry it on every
        // subsequent request and re-trigger whatever the failure was.
        // The autoload=false flag keeps this option out of the
        // alloptions cache.
        update_option('matrix_mlm_last_purged_version', MATRIX_MLM_VERSION, false);

        do_action('litespeed_purge_all');             // LiteSpeed Cache
        do_action('rocket_purge_cache');              // WP Rocket
        if (function_exists('w3tc_pgcache_flush')) {
            w3tc_pgcache_flush();                     // W3 Total Cache
        }
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();                   // WP Super Cache
        }
    }

    /**
     * Send the user to the Matrix login page after logout.
     *
     * If a `redirect_to` was already supplied (e.g. from
     * wp_logout_url() in the dashboard nav) and points at our own
     * site, honour it so callers can override per-link. Otherwise
     * fall back to /matrix-login. Off-site redirects are dropped
     * for safety so a malicious open-redirect query string can't
     * bounce a freshly-logged-out user to an attacker-controlled
     * domain.
     */
    public function filter_logout_redirect($redirect_to, $requested_redirect_to, $user) {
        $login_url = home_url('/matrix-login');

        if (!empty($requested_redirect_to)) {
            $safe = wp_validate_redirect($requested_redirect_to, '');
            if (!empty($safe)) {
                return $safe;
            }
        }

        return $login_url;
    }

    /**
     * show_admin_bar filter callback. Returns false for any
     * front-end visitor who is not a site admin, so members,
     * subscribers, and customers never see the WP admin bar above
     * the Matrix dashboard. Admins (capability manage_options)
     * keep it so they can still navigate into wp-admin from the
     * front-end.
     *
     * Capability check rather than a role check because some
     * installs grant manage_options to roles other than
     * 'administrator' (network admins, white-labelled custom
     * roles), and the intent is "people who run the site keep
     * the bar; everyone else doesn't".
     *
     * Logged-out visitors aren't a concern — WP doesn't show the
     * admin bar to them anyway — but current_user_can() correctly
     * returns false in that case so the filter is a no-op for
     * them.
     *
     * @param bool $show The incoming WP decision (typically true
     *                   for logged-in users on the front-end).
     * @return bool      Whether to render the admin bar.
     */
    public function filter_show_admin_bar($show) {
        if (current_user_can('manage_options')) {
            return $show;
        }
        return false;
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'matrix-mlm') === false) {
            return;
        }

        // Media library is required for the settings page logo uploader
        if (function_exists('wp_enqueue_media')) {
            wp_enqueue_media();
        }

        wp_enqueue_style(
            'matrix-mlm-admin',
            MATRIX_MLM_PLUGIN_URL . 'admin/css/matrix-admin.css',
            [],
            MATRIX_MLM_VERSION
        );

        wp_enqueue_script(
            'matrix-mlm-admin',
            MATRIX_MLM_PLUGIN_URL . 'admin/js/matrix-admin.js',
            ['jquery', 'wp-color-picker'],
            MATRIX_MLM_VERSION,
            true
        );

        wp_enqueue_style('wp-color-picker');

        wp_localize_script('matrix-mlm-admin', 'matrixMLMAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('matrix_mlm_admin_nonce'),
        ]);
    }

    public function register_shortcodes() {
        add_shortcode('matrix_dashboard', [$this->user_dashboard ?? new Matrix_MLM_User_Dashboard(), 'render_dashboard']);
        add_shortcode('matrix_login', [$this, 'render_login']);
        add_shortcode('matrix_register', [$this, 'render_register']);
        add_shortcode('matrix_plans', [$this, 'render_plans']);
    }

    public function render_login($atts) {
        if (is_user_logged_in()) {
            wp_redirect(home_url('/matrix-dashboard'));
            exit;
        }
        ob_start();
        include MATRIX_MLM_PLUGIN_DIR . 'public/templates/login.php';
        return ob_get_clean();
    }

    public function render_register($atts) {
        if (is_user_logged_in()) {
            wp_redirect(home_url('/matrix-dashboard'));
            exit;
        }
        ob_start();
        include MATRIX_MLM_PLUGIN_DIR . 'public/templates/register.php';
        return ob_get_clean();
    }

    public function render_plans($atts) {
        ob_start();
        include MATRIX_MLM_PLUGIN_DIR . 'public/templates/plans.php';
        return ob_get_clean();
    }

    public function handle_ajax() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        $action = isset($_POST['matrix_action']) ? sanitize_text_field($_POST['matrix_action']) : '';

        switch ($action) {
            case 'deposit':
                $this->process_deposit();
                break;
            case 'zebra_complete_otp':
                // Step 2 of the Zebra Wallet 2-step OTP deposit
                // flow. Step 1 (matrix_action=deposit with
                // gateway=zebra) returned requires_otp=true plus a
                // psp_reference; the frontend asks the user for the
                // OTP that was SMS'd to the wallet phone, and posts
                // it back here together with the deposit_id and
                // psp_reference. See process_zebra_complete_otp()
                // for the server-side checks.
                $this->process_zebra_complete_otp();
                break;
            case 'transfer':
                $this->process_transfer();
                break;
            // 'withdraw' branch retired in refactor/withdrawal-controls-five-toggles.
            // The Matrix-wallet → external-bank shortcut it backed was a
            // misleading surface (the operator settled the bank credit
            // off-platform; nothing in the code path actually reached a
            // payment rail). Users who need money in a Nigerian bank now
            // go Matrix → Fintava virtual via matrix_transfer_matrix_to_virtual,
            // then Fintava → bank via matrix_fintava_initiate_transfer.
            case 'join_plan':
                $this->process_join_plan();
                break;
            case 'redeem_epin':
                $this->process_epin_redeem();
                break;
            case 'fetch_subtree':
                $this->process_fetch_subtree();
                break;
            case 'fetch_subtree_json':
                $this->process_fetch_subtree_json();
                break;
            case 'search_genealogy':
                $this->process_genealogy_search();
                break;
            case 'node_details':
                $this->process_node_details();
                break;
            case 'fetch_new_descendants':
                $this->process_fetch_new_descendants();
                break;
            case 'submit_ticket':
                $this->process_ticket();
                break;
            case 'update_profile':
                $this->process_profile_update();
                break;
            case 'upload_avatar':
                $this->process_upload_avatar();
                break;
            case 'enable_2fa':
                $this->process_enable_2fa();
                break;
            case 'disable_2fa':
                $this->process_disable_2fa();
                break;
            case 'regenerate_recovery_codes':
                $this->process_regenerate_recovery_codes();
                break;
            case 'set_transaction_pin':
                $this->process_set_transaction_pin();
                break;
            case 'change_transaction_pin':
                $this->process_change_transaction_pin();
                break;
            case 'disable_transaction_pin':
                $this->process_disable_transaction_pin();
                break;
            case 'forgot_transaction_pin':
                $this->process_forgot_transaction_pin();
                break;
            case 'pay_subscription':
                $this->process_pay_subscription();
                break;
            case 'get_commission_attribution':
                $this->process_get_commission_attribution();
                break;
            default:
                wp_send_json_error(['message' => __('Invalid action', 'matrix-mlm')]);
        }
    }

    public function handle_public_ajax() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        $action = isset($_POST['matrix_action']) ? sanitize_text_field($_POST['matrix_action']) : '';

        switch ($action) {
            case 'login':
                $this->process_login();
                break;
            case 'register':
                $this->process_registration();
                break;
            case 'subscribe':
                $this->process_subscribe();
                break;
            default:
                wp_send_json_error(['message' => __('Invalid action', 'matrix-mlm')]);
        }
    }

    private function process_login() {
        // 2FA-aware login. The flow is:
        //
        //   Step 1: client posts {username, password}. We authenticate
        //           the password. If the user has 2FA enabled we DO NOT
        //           issue an auth cookie yet — instead we mint a short-
        //           lived single-use challenge token, return it to the
        //           client, and respond with requires_2fa=true so the
        //           frontend can prompt for the OTP.
        //
        //   Step 2: client posts {challenge_token, code}. We look the
        //           token up, verify the OTP via Matrix_MLM_Two_Factor,
        //           delete the token (single-use), and only then issue
        //           wp_set_auth_cookie().
        //
        // If a user does not have 2FA enabled, the flow degrades to the
        // original one-shot login path. This way enrolment is voluntary
        // but actually enforced for those who turn it on — the previous
        // implementation displayed a "2FA active" badge but never called
        // verify(), so the second factor was purely cosmetic (audit C5).
        //
        // Audit H12 also called for two protections layered on top of
        // the C5 fix:
        //   - Rate limit (per-username + per-IP transient counters) so a
        //     brute-force loop is bounded by network-side latency * cap.
        //   - Uniform error messages so the response cannot be used as
        //     a username-enumeration oracle. wp_authenticate emits
        //     'invalid_username' vs 'incorrect_password' on its own —
        //     we collapse both into "Invalid username or password" on
        //     the wire and keep the distinction only in error_log for
        //     operator-visible diagnostics.

        $challenge_token = isset($_POST['challenge_token']) ? sanitize_text_field($_POST['challenge_token']) : '';

        if ($challenge_token !== '') {
            $this->process_login_2fa_step($challenge_token);
            return;
        }

        $username = sanitize_text_field($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $ip = $this->get_client_ip();

        // Rate-limit gate. Sits in front of wp_authenticate so the
        // password-check codepath is unreachable from the brute-force
        // loop once the threshold is crossed.
        if ($this->is_login_rate_limited($username, $ip)) {
            wp_send_json_error([
                'message' => __('Too many login attempts. Please wait a few minutes before trying again.', 'matrix-mlm'),
            ]);
        }

        // Captcha gate. No-op if matrix_mlm_captcha_enabled is off; if
        // on but the secret key is unset the gate falls open so a
        // misconfigured install isn't locked out. (audit H13)
        $this->verify_captcha_or_die();

        $user = wp_authenticate($username, $password);
        if (is_wp_error($user)) {
            $this->record_failed_login_attempt($username, $ip, 'wp_auth:' . $user->get_error_code());
            wp_send_json_error([
                // Uniform message: does not distinguish 'invalid_username'
                // from 'incorrect_password'. (audit H12)
                'message' => __('Invalid username or password.', 'matrix-mlm'),
            ]);
        }

        // Email-verification gate. Refuse to issue an auth cookie to
        // accounts that registered while matrix_mlm_email_verification
        // was on but haven't clicked the verification link yet. The
        // verify-pending flag is set by process_registration() below
        // and cleared by handle_email_verification(). Legacy users
        // (registered before this PR) have no flag set, so this gate
        // is a no-op for them — the change rolls out without locking
        // existing accounts out. (audit H13)
        if ((int) get_option('matrix_mlm_email_verification', 1) === 1) {
            $pending = get_user_meta($user->ID, 'matrix_email_verify_pending', true);
            if ($pending) {
                // Resend the link in case the original got lost or
                // expired (the token has a 1-hour TTL — see
                // Matrix_MLM_Notifications::send_verification_email).
                Matrix_MLM_Notifications::send_verification_email($user->ID);
                wp_send_json_error([
                    'message' => __('Please verify your email before signing in. We\'ve resent the verification link to your inbox.', 'matrix-mlm'),
                ]);
            }
        }

        $two_factor = new Matrix_MLM_Two_Factor();
        if ($two_factor->is_enabled($user->ID)) {
            $token = bin2hex(random_bytes(32));
            // 5-minute TTL is long enough for users to fetch a code from
            // their authenticator app but short enough that a leaked
            // token isn't useful for long.
            //
            // Also persist the username + ip into the challenge payload
            // so process_login_2fa_step can score wrong-OTP failures
            // against the same rate-limit counters as step 1, instead
            // of letting an attacker who knows a valid password but no
            // OTP cycle through fresh tokens for free.
            set_transient('matrix_2fa_login_' . $token, [
                'user_id' => $user->ID,
                'created_at' => time(),
                'username' => $username,
                'ip' => $ip,
            ], 5 * MINUTE_IN_SECONDS);

            wp_send_json_success([
                'requires_2fa' => true,
                'challenge_token' => $token,
                'message' => __('Enter the 6-digit code from your authenticator app.', 'matrix-mlm'),
            ]);
            return;
        }

        // No 2FA — full success. Clear counters so the user isn't
        // penalised on subsequent legitimate logins after a couple of
        // typos earlier in the session.
        $this->clear_login_attempts($username, $ip);
        wp_set_auth_cookie($user->ID);
        wp_send_json_success(['redirect' => home_url('/matrix-dashboard')]);
    }

    /**
     * Second step of 2FA-aware login.
     *
     * Looks up the challenge token, verifies the OTP, and only then
     * issues the auth cookie. The token is consumed even on a wrong
     * code (single-use) — clients must restart from step 1. This
     * prevents a stolen token from being brute-forced against the
     * 6-digit OTP space.
     */
    private function process_login_2fa_step($challenge_token) {
        $key = 'matrix_2fa_login_' . $challenge_token;
        $payload = get_transient($key);

        if (!is_array($payload) || empty($payload['user_id'])) {
            wp_send_json_error([
                'message' => __('Login session expired. Please sign in again.', 'matrix-mlm'),
                'restart' => true,
            ]);
        }

        // Single-use: consume the token before validating the code so a
        // wrong OTP cannot be retried with the same token.
        delete_transient($key);

        $user_id = intval($payload['user_id']);
        $username = isset($payload['username']) ? (string) $payload['username'] : '';
        $ip = isset($payload['ip']) ? (string) $payload['ip'] : $this->get_client_ip();

        // Two acceptable input shapes:
        //   - A 6-digit numeric TOTP code from the authenticator app.
        //   - A recovery code (alphanumeric, dash optional, ~10 chars
        //     after normalisation). Audit M2 shipped recovery codes
        //     into Matrix_MLM_Two_Factor::verify, but the original
        //     digits-only filter here would have stripped a recovery
        //     code to either an empty string (mostly-letters) or a
        //     truncated digit substring (mixed) before verify() ever
        //     saw it — making the recovery path unreachable at the
        //     login step it was designed for.
        //
        // Normalise to uppercase A–Z + 0–9, accept lengths from 6
        // (TOTP) up to 20 (defence-in-depth headroom over the 10-char
        // recovery format). verify() does its own format-aware match,
        // so the only job here is to refuse trivially-short or
        // empty input and to score wrong codes into the brute-force
        // counters.
        $raw_code = isset($_POST['code']) ? (string) $_POST['code'] : '';
        $code = strtoupper($raw_code);
        $code = preg_replace('/[^A-Z0-9]/', '', $code);
        if (strlen($code) < 6 || strlen($code) > 20) {
            $this->record_failed_login_attempt($username, $ip, '2fa_short_code');
            wp_send_json_error([
                'message' => __('Enter the 6-digit code from your authenticator app, or one of your recovery codes.', 'matrix-mlm'),
                'restart' => true,
            ]);
        }

        $two_factor = new Matrix_MLM_Two_Factor();
        if (!$two_factor->verify($user_id, $code)) {
            // Score wrong OTP into the same per-username + per-IP
            // counters as step 1. Without this, an attacker who knows a
            // valid password but no OTP could cycle fresh challenge
            // tokens through step 1 indefinitely; here every failed
            // OTP costs them a slot. (audit H12)
            $this->record_failed_login_attempt($username, $ip, '2fa_wrong_code');
            wp_send_json_error([
                'message' => __('Invalid authentication code. Please sign in again.', 'matrix-mlm'),
                'restart' => true,
            ]);
        }

        // Full success — clear counters.
        $this->clear_login_attempts($username, $ip);
        wp_set_auth_cookie($user_id);
        wp_send_json_success(['redirect' => home_url('/matrix-dashboard')]);
    }

    /* ------------------------------------------------------------------
     * Login rate-limit helpers (audit H12)
     *
     * Two parallel transient counters: per-username (caps brute force
     * against a single account) and per-IP (caps credential stuffing
     * across many accounts from one source). Both gate ahead of
     * wp_authenticate so the password-check codepath is unreachable
     * once the threshold is crossed. Window and caps are filterable so
     * operators on shared NATs (university, mobile carrier) can raise
     * the per-IP cap without touching the per-username one.
     * ------------------------------------------------------------------ */

    /**
     * Resolve the client IP. REMOTE_ADDR is the only header we trust by
     * default — X-Forwarded-For etc. can be set by anyone making the
     * request unless the WP install is explicitly behind a known
     * reverse proxy that strips/sets it. Operators on cloud platforms
     * with verified-RP configs can hook 'matrix_mlm_client_ip' to
     * return the real client IP from the trusted forwarded header.
     */
    private function get_client_ip() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        return apply_filters('matrix_mlm_client_ip', $ip);
    }

    /**
     * Server-side captcha verification, shared by login and
     * registration entry points. (audit H13)
     *
     * The login.php and register.php templates already render a
     * Google reCAPTCHA widget when matrix_mlm_captcha_enabled is on,
     * but the server never validated the response. With this helper,
     * the same enable flag now actually enforces — a request without
     * a valid g-recaptcha-response is refused before the password
     * check or user-creation step runs.
     *
     * The helper fails OPEN (skips the gate, lets the request through)
     * when the operator has flagged captcha on but not configured a
     * secret key. Failing closed there would lock out every form on a
     * misconfigured install with no obvious way to recover; the
     * existing Settings UI doesn't validate the secret on save.
     *
     * Verification timeout is short (8s) so a flaky reCAPTCHA endpoint
     * doesn't gate the entire login surface for an extended outage.
     */
    private function verify_captcha_or_die() {
        $enabled = (int) get_option('matrix_mlm_captcha_enabled', 0);
        if (!$enabled) {
            return;
        }

        $secret = trim((string) get_option('matrix_mlm_captcha_secret_key', ''));
        if ($secret === '') {
            // Misconfigured — captcha enabled but no secret key. Fall
            // open rather than break every form on the site.
            return;
        }

        $response = isset($_POST['g-recaptcha-response'])
            ? sanitize_text_field((string) $_POST['g-recaptcha-response'])
            : '';
        if ($response === '') {
            wp_send_json_error([
                'message' => __('Please complete the captcha challenge.', 'matrix-mlm'),
            ]);
        }

        $verify = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
            'timeout' => 8,
            'body' => [
                'secret'   => $secret,
                'response' => $response,
                'remoteip' => $this->get_client_ip(),
            ],
        ]);

        if (is_wp_error($verify)) {
            error_log('[Matrix Captcha] verify request failed: ' . $verify->get_error_message());
            wp_send_json_error([
                'message' => __('Captcha verification could not be completed. Please try again in a moment.', 'matrix-mlm'),
            ]);
        }

        $body = json_decode(wp_remote_retrieve_body($verify), true);
        if (!is_array($body) || empty($body['success'])) {
            $codes = isset($body['error-codes']) && is_array($body['error-codes'])
                ? implode(',', array_map('sanitize_text_field', $body['error-codes']))
                : '(none)';
            // Hash the IP before it lands in the operator log. The
            // raw IP isn't useful for triage on this surface (the
            // failure is between the user and Google's verifier, and
            // the Login limiter already attributes per-IP via its
            // own log), and we'd rather not pile PII into error_log
            // every time a real user fails the challenge. Truncating
            // the sha1 to 12 hex keeps the log readable while still
            // letting an operator correlate clusters of failures
            // from the same source. (audit L4)
            $ip_tag = sha1($this->get_client_ip());
            $ip_tag = substr($ip_tag, 0, 12);
            error_log('[Matrix Captcha] verify failed for ip_h=' . $ip_tag . ' codes=' . $codes);
            wp_send_json_error([
                'message' => __('Captcha verification failed. Please try again.', 'matrix-mlm'),
            ]);
        }
    }

    /**
     * Normalise a login identifier for rate-limit bucketing.
     *
     * Trims whitespace and lowercases. WP usernames are already
     * case-insensitive on lookup, and email-form logins (which
     * wp_authenticate also accepts) are case-insensitive on the
     * mailbox-name part on every mailbox provider that matters.
     * Without this, "Alice" / "alice" / " alice " all get separate
     * counters, so an attacker can multiply their cap by simply
     * varying the casing or padding the identifier with whitespace.
     * (audit L2)
     *
     * Public form (wp_unslash → sanitize_text_field) already strips
     * tags and trims at the post boundary, but a centralised helper
     * lets the rate-limit path stay correct even if a future caller
     * forgets to call sanitize_text_field, and lets the same value
     * persist into the 2FA challenge token so step 2 scores against
     * the same bucket as step 1.
     */
    private function normalize_login_identifier($username) {
        return strtolower(trim((string) $username));
    }

    /**
     * Build the per-username transient key. sha1 normalises the byte
     * shape so usernames with characters outside the option_name
     * charset (rare, but possible on some installs) don't break the
     * key. Lowercased + trimmed (via normalize_login_identifier) so
     * 'Alice', 'alice', and ' alice ' all share the counter.
     */
    private function login_rate_limit_user_key($username) {
        return 'matrix_login_user_' . sha1($this->normalize_login_identifier($username));
    }

    /**
     * Build the per-IP transient key. sha1 likewise normalises and
     * also avoids storing raw IPs in option_name (tiny privacy nudge).
     */
    private function login_rate_limit_ip_key($ip) {
        return 'matrix_login_ip_' . sha1((string) $ip);
    }

    /**
     * @return bool true if either the per-username or per-IP counter
     *              is already at or above its cap.
     */
    private function is_login_rate_limited($username, $ip) {
        $max_user = (int) apply_filters('matrix_mlm_login_max_attempts_per_user', 10);
        $max_ip   = (int) apply_filters('matrix_mlm_login_max_attempts_per_ip',   50);

        // Normalise so an empty-after-trim identifier ('' / '   ')
        // doesn't get a real counter — emit no per-user limit for
        // that path; the per-IP limiter still bounds it. (audit L2)
        $normalized = $this->normalize_login_identifier($username);

        if ($normalized !== '') {
            $user_count = (int) get_transient($this->login_rate_limit_user_key($normalized));
            if ($user_count >= $max_user) {
                return true;
            }
        }

        if ($ip !== '') {
            $ip_count = (int) get_transient($this->login_rate_limit_ip_key($ip));
            if ($ip_count >= $max_ip) {
                return true;
            }
        }

        return false;
    }

    /**
     * Increment both counters. Each counter has its own (rolling) TTL —
     * legitimate users with a slow reset window aren't punished
     * indefinitely. The window is filterable for the same reason caps
     * are. Operator-visible diagnostic is logged so legitimate support
     * cases can still be reasoned about; user-facing response stays
     * uniform per audit H12.
     */
    private function record_failed_login_attempt($username, $ip, $reason = '') {
        $window = (int) apply_filters('matrix_mlm_login_window_seconds', 15 * MINUTE_IN_SECONDS);
        if ($window < MINUTE_IN_SECONDS) {
            $window = MINUTE_IN_SECONDS; // floor — never auto-reset every second
        }

        // Same normalisation used by is_login_rate_limited so the
        // increment lands in the same bucket the gate reads from.
        // (audit L2)
        $normalized = $this->normalize_login_identifier($username);

        if ($normalized !== '') {
            $user_key = $this->login_rate_limit_user_key($normalized);
            $user_count = (int) get_transient($user_key);
            set_transient($user_key, $user_count + 1, $window);
        }

        if ($ip !== '') {
            $ip_key = $this->login_rate_limit_ip_key($ip);
            $ip_count = (int) get_transient($ip_key);
            set_transient($ip_key, $ip_count + 1, $window);
        }

        error_log(sprintf(
            '[Matrix Login] failed_attempt user=%s ip=%s reason=%s',
            $normalized !== '' ? $normalized : '(empty)',
            $ip !== '' ? $ip : '(empty)',
            $reason !== '' ? $reason : '(unspecified)'
        ));
    }

    /**
     * Clear both counters on successful login so a legitimate user who
     * mistyped a couple of times isn't penalised on the next attempt.
     */
    private function clear_login_attempts($username, $ip) {
        $normalized = $this->normalize_login_identifier($username);
        if ($normalized !== '') {
            delete_transient($this->login_rate_limit_user_key($normalized));
        }
        if ($ip !== '') {
            delete_transient($this->login_rate_limit_ip_key($ip));
        }
    }

    private function process_registration() {
        // (audit H13) Three layered gates on the previously-bare
        // registration surface:
        //   1. Per-IP rate limit. Caps mass-account creation at a
        //      bounded rate per attacker source.
        //   2. Captcha verification (server-side, not just the
        //      template widget). Same helper as process_login.
        //   3. Email-verification gate. When the existing
        //      matrix_mlm_email_verification option is on (default),
        //      registration no longer auto-issues the auth cookie —
        //      the user must click the link in the verification email
        //      first. process_login refuses to log in users with a
        //      pending verify until they do.
        $ip = $this->get_client_ip();
        if ($this->is_registration_rate_limited($ip)) {
            wp_send_json_error([
                'message' => __('Too many registration attempts from this network. Please try again later.', 'matrix-mlm'),
            ]);
        }

        // Master site-wide registration toggle. The option already
        // existed (Settings > General > Allow new user registration)
        // but was only honored on the front-end form template; a
        // direct POST bypassed it. Enforce server-side too.
        if ((int) get_option('matrix_mlm_registration_enabled', 1) !== 1) {
            wp_send_json_error([
                'message' => __('Registration is currently disabled.', 'matrix-mlm'),
            ]);
        }

        $this->verify_captcha_or_die();

        $username = sanitize_text_field($_POST['username'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $referral_code = sanitize_text_field($_POST['referral_code'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');

        if (empty($username) || empty($email) || empty($password)) {
            $this->record_registration_attempt($ip);
            wp_send_json_error(['message' => __('All fields are required', 'matrix-mlm')]);
        }

        if (empty($phone)) {
            $this->record_registration_attempt($ip);
            wp_send_json_error(['message' => __('Phone number is required', 'matrix-mlm')]);
        }

        if (empty($referral_code)) {
            $this->record_registration_attempt($ip);
            wp_send_json_error(['message' => __('Referral code is required. Please ask the person who invited you for their code.', 'matrix-mlm')]);
        }

        // Validate referral code exists
        global $wpdb;
        $referrer = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}matrix_user_meta WHERE referral_code = %s",
            $referral_code
        ));

        if (!$referrer) {
            $this->record_registration_attempt($ip);
            wp_send_json_error(['message' => __('Invalid referral code. Please check and try again.', 'matrix-mlm')]);
        }

        if (username_exists($username) || email_exists($email)) {
            $this->record_registration_attempt($ip);
            wp_send_json_error(['message' => __('Username or email already exists', 'matrix-mlm')]);
        }

        $user_id = wp_create_user($username, $password, $email);
        if (is_wp_error($user_id)) {
            $this->record_registration_attempt($ip);
            wp_send_json_error(['message' => $user_id->get_error_message()]);
        }

        // Save first name, last name to WP user
        wp_update_user([
            'ID' => $user_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
        ]);

        // Create matrix user meta. generate_unique_referral_code()
        // retries on collision against the UNIQUE index on
        // matrix_user_meta.referral_code — same helper the importer
        // and the admin "Backfill Referral Codes" tool use, so all
        // three paths share one algorithm and one uniqueness story.
        $ref_code = Matrix_MLM_User::generate_unique_referral_code($user_id);

        $wpdb->insert($wpdb->prefix . 'matrix_user_meta', [
            'user_id' => $user_id,
            'referral_code' => $ref_code,
            'referred_by' => $referrer->user_id,
            'phone' => $phone,
            'balance' => 0.00,
            'status' => 'active'
        ]);

        // Send verification email
        Matrix_MLM_Notifications::send_verification_email($user_id);

        // A successful registration ALSO counts toward the rate-limit
        // counter — caps the number of NEW accounts an attacker can
        // mint from one IP per window, even when their POSTs are
        // syntactically valid.
        $this->record_registration_attempt($ip);

        // Email-verification gate. Set the pending flag now; cleared
        // by Matrix_MLM_Core::handle_email_verification on a valid
        // click of the link.
        $require_verification = (int) get_option('matrix_mlm_email_verification', 1) === 1;
        if ($require_verification) {
            update_user_meta($user_id, 'matrix_email_verify_pending', 1);
            wp_send_json_success([
                'message' => __('Account created. Check your email to verify your address before signing in.', 'matrix-mlm'),
                'verify_pending' => true,
            ]);
        }

        // Verification disabled site-wide — original auto-login path.
        wp_set_auth_cookie($user_id);
        wp_send_json_success(['redirect' => home_url('/matrix-dashboard')]);
    }

    /* ------------------------------------------------------------------
     * Registration rate-limit helpers (audit H13)
     *
     * Single per-IP counter. Lower cap than the login per-IP counter
     * because registration is a much rarer legitimate action — most
     * residential IPs see one or two registrations a year, not dozens.
     * ------------------------------------------------------------------ */

    private function registration_rate_limit_key($ip) {
        return 'matrix_register_ip_' . sha1((string) $ip);
    }

    private function is_registration_rate_limited($ip) {
        if ($ip === '') {
            return false;
        }
        $max = (int) apply_filters('matrix_mlm_registration_max_per_window', 5);
        $count = (int) get_transient($this->registration_rate_limit_key($ip));
        return $count >= $max;
    }

    private function record_registration_attempt($ip) {
        if ($ip === '') {
            return;
        }
        $window = (int) apply_filters('matrix_mlm_registration_window_seconds', HOUR_IN_SECONDS);
        if ($window < MINUTE_IN_SECONDS) {
            $window = MINUTE_IN_SECONDS; // floor
        }
        $key = $this->registration_rate_limit_key($ip);
        $count = (int) get_transient($key);
        set_transient($key, $count + 1, $window);
    }

    private function process_deposit() {
        $user_id = get_current_user_id();
        $amount = floatval($_POST['amount'] ?? 0);
        $gateway = sanitize_text_field($_POST['gateway'] ?? '');

        if ($amount < get_option('matrix_mlm_min_deposit', 1000)) {
            wp_send_json_error(['message' => __('Amount below minimum deposit', 'matrix-mlm')]);
        }

        if ($amount > get_option('matrix_mlm_max_deposit', 5000000)) {
            wp_send_json_error(['message' => __('Amount exceeds maximum deposit', 'matrix-mlm')]);
        }

        global $wpdb;
        $gateway_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_gateways WHERE slug = %s AND status = 1",
            $gateway
        ));

        if (!$gateway_data) {
            wp_send_json_error(['message' => __('Payment gateway not available', 'matrix-mlm')]);
        }

        $charge = $gateway_data->fixed_charge + ($amount * $gateway_data->percent_charge / 100);
        $net_amount = $amount - $charge;

        $wpdb->insert($wpdb->prefix . 'matrix_deposits', [
            'user_id' => $user_id,
            'gateway' => $gateway,
            'amount' => $amount,
            'charge' => $charge,
            'net_amount' => $net_amount,
            'currency' => get_option('matrix_mlm_currency', 'NGN'),
            'status' => 'pending'
        ]);

        $deposit_id = $wpdb->insert_id;

        // Initialize payment based on gateway
        if ($gateway === 'paystack') {
            $paystack = new Matrix_MLM_Paystack();
            $result = $paystack->initialize_payment($deposit_id, $amount, $user_id);
        } elseif ($gateway === 'flutterwave') {
            $flutterwave = new Matrix_MLM_Flutterwave();
            $result = $flutterwave->initialize_payment($deposit_id, $amount, $user_id);
        } elseif ($gateway === 'zebra') {
            // Zebra Wallet (Bibimoney / Nazmo Banking Platform) is a
            // direct-debit-with-OTP flow, NOT a hosted-checkout
            // redirect. The frontend collects the customer's wallet
            // identifier (IWAN, local reference, or MSISDN) in the
            // same form as the amount and posts it through as
            // `wallet_account`. initialize_payment() runs step 1
            // (POST /PaymentAuth) which causes the platform to SMS
            // an OTP to the wallet's primary phone number; the
            // returned response carries requires_otp=true and a
            // psp_reference, which the frontend echoes back in the
            // separate `matrix_action=zebra_complete_otp` call once
            // the user has entered their OTP. See
            // gateways/class-matrix-zebra.php for the full flow.
            $primary_account = sanitize_text_field((string) ($_POST['wallet_account'] ?? ''));
            $zebra  = new Matrix_MLM_Zebra();
            $result = $zebra->initialize_payment($deposit_id, $amount, $user_id, $primary_account);
        } else {
            wp_send_json_error(['message' => __('Unsupported gateway', 'matrix-mlm')]);
            return;
        }

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Step 2 of the Zebra Wallet two-step OTP deposit flow.
     *
     * Step 1 (process_deposit with gateway='zebra') has already
     *   - inserted a matrix_deposits row in 'pending' status
     *   - called /PaymentAuth and stored the platform's PSPreference
     *     in the deposit's gateway_response JSON
     *   - returned { requires_otp: true, psp_reference } to the JS
     *
     * The frontend then asks the user for the OTP that Bibimoney
     * SMS'd to the wallet's primary phone, and posts the OTP back
     * here together with the deposit_id and the psp_reference echo.
     * This method:
     *
     *   1. Validates the deposit_id belongs to the current user and
     *      the gateway is 'zebra' (defense in depth — the gateway
     *      class re-checks this too, but failing fast at the AJAX
     *      boundary keeps error messages cleaner).
     *
     *   2. Hands off to Matrix_MLM_Zebra::complete_otp_payment()
     *      which calls /Payment with the OTP, runs amount/currency
     *      mismatch defenses on the response, and credits the
     *      Matrix wallet through the same idempotent code path the
     *      IPN webhook uses.
     *
     *   3. Returns the result envelope to the JS so the modal can
     *      render success or surface the specific error (e.g.
     *      "OTP expired", "OTP already used", "HMAC invalid").
     */
    private function process_zebra_complete_otp() {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Authentication required.', 'matrix-mlm')]);
        }

        $user_id       = get_current_user_id();
        $deposit_id    = (int) ($_POST['deposit_id'] ?? 0);
        $psp_reference = sanitize_text_field((string) ($_POST['psp_reference'] ?? ''));
        // OTPs are short numeric/alphanumeric tokens — strip
        // surrounding whitespace from paste, but keep the raw value
        // otherwise. sanitize_text_field is enough; the gateway
        // class trims again.
        $otp = sanitize_text_field((string) ($_POST['otp'] ?? ''));

        if ($deposit_id <= 0 || $psp_reference === '' || $otp === '') {
            wp_send_json_error(['message' => __('Deposit ID, payment reference, and OTP are all required.', 'matrix-mlm')]);
        }

        $zebra  = new Matrix_MLM_Zebra();
        $result = $zebra->complete_otp_payment($deposit_id, $user_id, $psp_reference, $otp);

        if (!empty($result['success'])) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Process a user-to-user (Matrix wallet → Matrix wallet) transfer.
     *
     * Atomicity contract: either ALL three persistence steps succeed
     * (sender debit, recipient credit, matrix_transfers audit row) or
     * the whole thing rolls back and the user sees a real error. The
     * previous revision called debit/credit/insert in sequence without
     * checking any return value and unconditionally returned
     * "Transfer completed successfully", which is the bug behind the
     * "wallet-to-wallet says success but my balance is unchanged"
     * report on the consolidated Wallet page: a silent $wpdb->update
     * no-op (e.g. missing matrix_user_meta row) presented to the user
     * as a successful transfer that didn't actually move any money.
     *
     * The fix has three layers:
     *
     *   1. Matrix_MLM_Wallet::debit()/credit() now return false when
     *      the underlying $wpdb->update or $wpdb->insert fails or
     *      affects zero rows. (See those docblocks for the failure
     *      modes covered.)
     *
     *   2. This method checks every return value and bails with a
     *      real wp_send_json_error() — instead of pretending success —
     *      whenever any persistence step fails. The error_log() lines
     *      capture $wpdb->last_error so the next time this fires we
     *      can see exactly which DB op failed and why.
     *
     *   3. The whole flow runs inside a $wpdb->query('START
     *      TRANSACTION') / COMMIT / ROLLBACK envelope so partial
     *      failures don't leave the system in an inconsistent state
     *      (sender debited but recipient un-credited, or money moved
     *      but no matrix_transfers row to reconcile against). InnoDB
     *      is the WordPress default engine and dbDelta uses it without
     *      ENGINE= override, so the transaction is honoured.
     */
    private function process_transfer() {
        $user_id = get_current_user_id();

        // Banned-user gap close + circumvention defence. Peer Matrix
        // → Matrix transfers are a Matrix-wallet outflow path, so they
        // run through the same five-toggle eligibility chain every
        // other Matrix-side outflow uses. Crucially this includes the
        // plan-tier gate (gate 2) — without that, a non-qualifying
        // user could relay their balance through a qualifying peer
        // who then cashes out, defeating the gate. The master kill
        // switch (gate 5) and active-account check (gate 3) likewise
        // cover this surface uniformly. The Matrix Transfers toggle
        // itself (gate 1) lets admins disable peer + Matrix→Virtual
        // movement together without touching bank transfers.
        $eligibility = Matrix_MLM_User::can_move_funds($user_id, 'matrix_transfer');
        if (!$eligibility['allowed']) {
            wp_send_json_error(['message' => $eligibility['reason']]);
        }

        // Transaction PIN gate (PR 2). Runs immediately after the
        // can_move_funds eligibility check and BEFORE the START
        // TRANSACTION below, so a wrong PIN can't dirty the wallet
        // ledger or burn an audit-row insert. Path key 'transfers'
        // maps to the matrix_mlm_pin_required_for_transfers admin
        // toggle on Settings → Financial. The helper short-circuits
        // (no-op return) when the path isn't gated or when the
        // current user has no PIN configured, so this is inert
        // until the admin opts in AND the user enrols.
        Matrix_MLM_Transaction_Pin::require_pin_for_request($user_id, 'transfers');

        $amount = floatval($_POST['amount'] ?? 0);
        $recipient_username = sanitize_text_field($_POST['recipient'] ?? '');

        $min = get_option('matrix_mlm_min_transfer', 500);
        if ($amount < $min) {
            wp_send_json_error(['message' => sprintf(__('Minimum transfer amount is %s', 'matrix-mlm'), $min)]);
        }

        $recipient = get_user_by('login', $recipient_username);
        if (!$recipient || $recipient->ID === $user_id) {
            wp_send_json_error(['message' => __('Invalid recipient', 'matrix-mlm')]);
        }

        $wallet = new Matrix_MLM_Wallet();
        $charge_type = get_option('matrix_mlm_transfer_charge_type', 'fixed');
        $charge_value = get_option('matrix_mlm_transfer_charge', 100);
        $charge = $charge_type === 'percent' ? ($amount * $charge_value / 100) : $charge_value;
        $total = $amount + $charge;

        $balance = $wallet->get_balance($user_id);
        if ($balance < $total) {
            wp_send_json_error(['message' => __('Insufficient balance', 'matrix-mlm')]);
        }

        global $wpdb;

        // All three persistence steps below are part of the same
        // logical operation; either they all commit or none of them
        // do. Wrapping in START TRANSACTION + COMMIT/ROLLBACK gives
        // us that guarantee on InnoDB tables (the WordPress default
        // and the engine dbDelta uses for our matrix_* tables).
        $wpdb->query('START TRANSACTION');

        // Sender debit — the only step that can legitimately fail on
        // a balance check at this point (the early `if ($balance <
        // $total)` above only narrows the window; a concurrent
        // transfer from the same user could still race past it). A
        // false return here means either insufficient balance OR a
        // $wpdb->update no-op (missing user_meta row, schema
        // mismatch, read-only DB connection, etc.) — surface the
        // failure to the user instead of pretending the money moved.
        $debit_result = $wallet->debit(
            $user_id,
            $total,
            'transfer_out',
            sprintf(__('Transfer to %s', 'matrix-mlm'), $recipient_username)
        );
        if ($debit_result === false) {
            $wpdb->query('ROLLBACK');
            error_log(sprintf(
                '[Matrix MLM] process_transfer: debit() returned false for user_id=%d, total=%s, last_error=%s',
                $user_id, $total, $wpdb->last_error
            ));
            wp_send_json_error([
                'message' => __('Could not debit your wallet. Please refresh and try again, or contact support if the problem persists.', 'matrix-mlm')
            ]);
        }

        // Recipient credit. Same false-return contract as debit: a
        // false here means the recipient's user_meta row is missing
        // or the insert/update failed. Don't ship the money via the
        // matrix_transfers audit row if the credit didn't actually
        // land.
        $credit_result = $wallet->credit(
            $recipient->ID,
            $amount,
            'transfer_in',
            sprintf(__('Transfer from %s', 'matrix-mlm'), wp_get_current_user()->user_login)
        );
        if ($credit_result === false) {
            $wpdb->query('ROLLBACK');
            error_log(sprintf(
                '[Matrix MLM] process_transfer: credit() returned false for recipient_id=%d, amount=%s, last_error=%s',
                $recipient->ID, $amount, $wpdb->last_error
            ));
            wp_send_json_error([
                'message' => __('Could not credit the recipient. Your wallet was not debited. Please contact support if the recipient should exist.', 'matrix-mlm')
            ]);
        }

        // Audit row. Treated as required: if we can't write the
        // matrix_transfers row, we don't have a way to reconcile this
        // movement later, so roll back the whole thing rather than
        // leave a silent debit/credit pair with no transfer record.
        $insert_result = $wpdb->insert($wpdb->prefix . 'matrix_transfers', [
            'from_user_id' => $user_id,
            'to_user_id' => $recipient->ID,
            'amount' => $amount,
            'charge' => $charge,
            'status' => 'completed'
        ]);
        if ($insert_result === false) {
            $wpdb->query('ROLLBACK');
            error_log(sprintf(
                '[Matrix MLM] process_transfer: matrix_transfers insert failed; from=%d, to=%d, amount=%s, last_error=%s',
                $user_id, $recipient->ID, $amount, $wpdb->last_error
            ));
            wp_send_json_error([
                'message' => __('Could not record the transfer. Please try again or contact support.', 'matrix-mlm')
            ]);
        }

        $wpdb->query('COMMIT');

        // Post-COMMIT verification. Re-read both balances directly
        // from matrix_user_meta and confirm they match what the
        // debit/credit calls reported. If they don't, something
        // outside this function (a shutdown hook, an HTTP-cached
        // dashboard page, a HyperDB master/replica split, a MyISAM
        // table that silently no-op'd the START TRANSACTION) ate
        // our writes — we should NOT report success in that case,
        // because the user will refresh and see balances that
        // contradict the success message they just dismissed (the
        // exact "transfer succeeds but no money moves" report this
        // PR is fixing). Logging $wpdb->last_error and the
        // before/after values gives support a forensic trail to
        // chase the actual root cause if the symptom recurs.
        $sender_balance_after    = $wallet->get_balance($user_id);
        $recipient_balance_after = $wallet->get_balance($recipient->ID);

        $sender_ok    = is_numeric($debit_result)  && abs($sender_balance_after    - floatval($debit_result))  < 0.01;
        $recipient_ok = is_numeric($credit_result) && abs($recipient_balance_after - floatval($credit_result)) < 0.01;

        if (!$sender_ok || !$recipient_ok) {
            error_log(sprintf(
                '[Matrix MLM] process_transfer: post-COMMIT balance mismatch. ' .
                'sender_id=%d expected_after=%s actual_after=%s | ' .
                'recipient_id=%d expected_after=%s actual_after=%s | ' .
                'amount=%s charge=%s last_error=%s',
                $user_id, $debit_result, $sender_balance_after,
                $recipient->ID, $credit_result, $recipient_balance_after,
                $amount, $charge, $wpdb->last_error
            ));
            wp_send_json_error([
                'message' => __('Transfer could not be confirmed. Please refresh your wallet page and verify the balance before retrying. If the problem persists, contact support.', 'matrix-mlm')
            ]);
        }

        // Notify recipient about the incoming transfer. Done AFTER
        // the verification check so a balance mismatch surfaces as a
        // real error instead of an email-but-no-money outcome. Done
        // AFTER commit so a failing email send (downed SMTP, bad
        // template, etc.) doesn't roll back the money movement —
        // the user has already been debited and credited, the
        // transfer is real.
        Matrix_MLM_Notifications::send_transfer_notification($recipient->ID, $user_id, $amount);

        // Sender-side in-app notification (1.0.15). The recipient
        // gets theirs via send_transfer_notification above; this
        // pair gives the sender a durable record in their bell
        // dropdown that survives past the on-form success toast.
        // Useful for "did that ₦5,000 actually go through?" — the
        // notification sits in their history with the recipient
        // username and amount baked into both the title and meta.
        if (class_exists('Matrix_MLM_In_App_Notifications')) {
            $currency_sym = get_option('matrix_mlm_currency_symbol', '₦');
            $amount_fmt   = $currency_sym . number_format((float) $amount, 2);
            Matrix_MLM_In_App_Notifications::enqueue(
                (int) $user_id,
                'transfer_sent',
                sprintf(
                    /* translators: 1: amount, 2: recipient username */
                    __('Sent %1$s to %2$s', 'matrix-mlm'),
                    $amount_fmt,
                    (string) $recipient->user_login
                ),
                sprintf(
                    /* translators: 1: amount, 2: recipient */
                    __('Your transfer of %1$s to %2$s was completed.', 'matrix-mlm'),
                    $amount_fmt,
                    (string) $recipient->user_login
                ),
                '/matrix-dashboard/wallet/',
                ['amount' => (float) $amount, 'recipient_id' => (int) $recipient->ID, 'recipient_login' => (string) $recipient->user_login]
            );
        }

        wp_send_json_success(['message' => __('Transfer completed successfully', 'matrix-mlm')]);
    }

    // process_withdraw() (matrix_action=withdraw branch) retired in
    // refactor/withdrawal-controls-five-toggles. It backed the
    // standalone "Matrix Transfers" Wallet button that purported to
    // be an instant Matrix-wallet → external-bank transfer, but in
    // practice nothing in this method ever reached a payment rail —
    // the operator settled the bank credit off-platform from a
    // matrix_withdrawals row regardless. Users now go in two real
    // steps: Matrix → Fintava virtual (Matrix_MLM_Fintava::ajax_transfer_matrix_to_virtual)
    // then Fintava → bank (Matrix_MLM_Fintava::ajax_initiate_transfer).
    // The admin Manage Withdrawals page is left in place so the
    // historical pre-retirement matrix_withdrawals rows can still
    // be reviewed and exported.

    private function process_join_plan() {
        $user_id = get_current_user_id();
        $plan_id = intval($_POST['plan_id'] ?? 0);

        $plan_engine = new Matrix_MLM_Plan_Engine();
        $result = $plan_engine->join_plan($user_id, $plan_id);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    private function process_epin_redeem() {
        $user_id = get_current_user_id();
        $pin_code = sanitize_text_field($_POST['pin_code'] ?? '');

        $epin = new Matrix_MLM_Epin();
        $result = $epin->redeem($user_id, $pin_code);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX endpoint for the genealogy "Show more" button.
     *
     * The genealogy view server-renders only the first 4 levels of a
     * member's matrix on initial pageload — below that, leaf nodes
     * with downline get a "Show more" expand button. Click fires this
     * endpoint with the position id being expanded plus its current
     * absolute level; the server fetches the next 3 levels of subtree
     * rooted there and returns it as ready-to-inject HTML matching the
     * rest of the tree's markup.
     *
     * Why a chunked / on-demand model rather than a single deep
     * initial render:
     *
     *   - A 3x9 plan has up to 29,524 positions; rendering all of them
     *     server-side on every dashboard pageload would balloon both
     *     query time and HTML payload.
     *   - Most members never need to navigate past their direct
     *     downline. Lazy-loading deeper levels only when a member
     *     actually clicks to see them keeps the typical pageload
     *     small while still letting curious members drill all the
     *     way down.
     *   - Each click adds 3 levels (so 4 → 7 → 10 → …, capped at the
     *     plan's depth). The renderer places another expand button at
     *     the new bottom edge if more remains, so deep dives compose
     *     naturally without any client-side state.
     *
     * Authorization: a member can only fetch a subtree they're already
     * entitled to see — namely, anything that descends from their own
     * position in the same plan. Matrix_MLM_Plan_Engine::user_can_view_position()
     * walks parent_id up from the requested position and confirms the
     * caller's user id appears on the chain. That auth check costs
     * O(depth) queries (typically <= 9), well below the budget for
     * the rest of this call.
     *
     * Returns an HTML payload (server-rendered) rather than JSON
     * structured data because the rendering layer is PHP-only — the
     * genealogy view server-renders every other piece of tree markup,
     * and duplicating the render logic in JS just so this one
     * endpoint can return JSON would double the maintenance surface.
     * The response HTML is wrapped in a single <div class="matrix-tree-children">
     * so the client side just replaces the expand button with it
     * verbatim.
     */
    private function process_fetch_subtree() {
        $position_id = isset($_POST['position_id']) ? (int) $_POST['position_id'] : 0;
        $from_level  = isset($_POST['from_level'])  ? (int) $_POST['from_level']  : 0;
        // Optional: when the genealogy view is rendered against a
        // pivoted root (?pivot_user_id=X), the JS forwards the current
        // tree's root user id so the lazy-loaded subtree is classified
        // as direct vs spillover relative to that pivot — matching how
        // every node already on the page was rendered. Falls back to
        // the actual viewer when the request omits it (the unpivoted
        // case, which is also the common case).
        $root_user_id_raw = isset($_POST['root_user_id']) ? (int) $_POST['root_user_id'] : 0;

        if ($position_id <= 0 || $from_level <= 0) {
            wp_send_json_error(['message' => __('Invalid request.', 'matrix-mlm')]);
        }

        global $wpdb;

        // Look up the position to authorize the read AND get its plan
        // dimensions in one trip. We need plan width to render the
        // empty-slot placeholders consistently with the initial render,
        // and plan depth as the absolute ceiling for the expansion
        // chunk so we don't query past where the matrix can possibly
        // hold members.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT p.id AS position_id, p.user_id AS owner_user_id, p.plan_id,
                    pl.width AS plan_width, pl.depth AS plan_depth
               FROM {$wpdb->prefix}matrix_positions p
               LEFT JOIN {$wpdb->prefix}matrix_plans pl ON pl.id = p.plan_id
              WHERE p.id = %d",
            $position_id
        ));

        if (!$row || !$row->plan_id) {
            wp_send_json_error(['message' => __('Position not found.', 'matrix-mlm')]);
        }

        $current_user_id = get_current_user_id();
        $plan_engine     = new Matrix_MLM_Plan_Engine();

        // Refuse the lookup if the caller can't see this branch of the
        // tree. See user_can_view_position()'s docblock for the
        // walk-up algorithm and why parent_id is the right structural
        // truth to query against.
        if (!$plan_engine->user_can_view_position($current_user_id, $position_id)) {
            wp_send_json_error(['message' => __('You do not have access to this part of the genealogy.', 'matrix-mlm')]);
        }

        // Each click reveals 3 more absolute levels, capped by the
        // plan's depth. Three is small enough to keep the round-trip
        // payload bounded yet large enough to feel like meaningful
        // progress on each click — picking 1 would leave members
        // clicking endlessly down a 9-level plan.
        $expand_levels = 3;
        $max_depth     = min((int) $row->plan_depth, $from_level + $expand_levels);

        $tree = $plan_engine->build_subtree($position_id, (int) $row->plan_id, $from_level, $max_depth);
        if (!$tree) {
            wp_send_json_error(['message' => __('Could not build subtree.', 'matrix-mlm')]);
        }

        // Decide which user id the renderer should treat as the tree's
        // root for direct/spillover classification. Defaults to the
        // viewer themselves; if the client passed a different
        // root_user_id (the pivoted-view case) we accept it only when
        // that user has a position in this plan AND the viewer can see
        // it — i.e., the same auth gate the page-level pivot uses, so
        // the AJAX path can never grant visibility the pivoted page
        // wouldn't have.
        $effective_root_user_id = $current_user_id;
        if ($root_user_id_raw > 0 && $root_user_id_raw !== $current_user_id) {
            $root_position_id = $plan_engine->get_position_id_for_user_in_plan(
                $root_user_id_raw,
                (int) $row->plan_id
            );
            if ($root_position_id > 0
                && $plan_engine->user_can_view_position($current_user_id, $root_position_id)) {
                $effective_root_user_id = $root_user_id_raw;
            }
            // Silently fall back to the viewer otherwise — same
            // behaviour as the page render rejects an unauthorised
            // ?pivot_user_id and shows the viewer's own tree.
        }

        // Render only the children block (the parent node itself is
        // already on the page — this AJAX call replaces the expand
        // button under it with a freshly-rendered .matrix-tree-children
        // sibling).
        //
        // Hydrate the renderer's per-render pivot_state with just
        // enough context for empty-slot CTAs to render their
        // "Refer 1 more here →" affordance: viewer, plan id,
        // referral URL, plan-level commission map, and currency
        // symbol. We deliberately omit goal_level — the goal-level
        // pulse is anchored to the levels visible in the
        // page-rendered banner, and re-deriving it on each AJAX
        // expansion would just compete for attention with the
        // banner itself.
        $referral_url = '';
        if (class_exists('Matrix_MLM_User')) {
            $maybe = (string) Matrix_MLM_User::get_referral_link($current_user_id);
            if ($maybe !== '' && substr($maybe, -4) !== 'ref=') {
                $referral_url = $maybe;
            }
        }
        $level_commissions = [];
        if (!empty($row->plan_id)) {
            $plan_row = $wpdb->get_row($wpdb->prepare(
                "SELECT level_commission FROM {$wpdb->prefix}matrix_plans WHERE id = %d",
                (int) $row->plan_id
            ));
            if ($plan_row && !empty($plan_row->level_commission)) {
                $decoded = json_decode((string) $plan_row->level_commission, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $k => $v) {
                        $level_commissions[(int) $k] = (float) $v;
                    }
                }
            }
        }

        ob_start();
        echo '<div class="matrix-tree-children">';
        $renderer = new Matrix_MLM_User_Genealogy();
        // Public setter so the AJAX path can match the page-render
        // contract that empty-slot rendering depends on. Without
        // this, lazy-loaded subtrees would silently lose the
        // "Refer 1 more here" affordance the rest of the tree shows.
        $renderer->set_render_state([
            'viewer_user_id'    => $current_user_id,
            'display_user_id'   => $effective_root_user_id,
            'plan_id'           => (int) $row->plan_id,
            'is_pivoted'        => ($effective_root_user_id !== $current_user_id),
            'referral_url'      => $referral_url,
            'goal_level'        => 0,
            'level_commissions' => $level_commissions,
            'currency_symbol'   => get_option('matrix_mlm_currency_symbol', '₦'),
        ]);
        // Compute heat data for the lazy-loaded subtree so the
        // newly-injected nodes carry the same data-heat-* and
        // data-pill-* attributes as the initial render. Without
        // this, expanding a branch in Activity mode would drop
        // back to neutral cards under the expansion point — the
        // CSS heat selectors would have nothing to match against.
        //
        // The bucketing is necessarily local to this subtree
        // (relative to the descendants we just loaded, not the
        // whole on-screen tree). That's an intentional trade
        // documented in compute_heat_data()'s "boundary caveat":
        // expanding a branch is a focused inspection action, and
        // re-bucketing against just-loaded peers is what members
        // care about at that moment of inspection.
        //
        // The mode + heat_metric URL params from the page render
        // are NOT plumbed through the AJAX call — instead the JS
        // mode-toggle script's matrix:subtree-loaded handler
        // refreshes the visible pill text against whatever metric
        // is currently active on the page. That keeps the
        // AJAX contract narrow (no extra params to validate) and
        // avoids drift if the user toggles modes while the
        // request is in flight.
        $heat_data = $renderer->compute_heat_data(
            $tree,
            $current_user_id,
            (int) $row->plan_id
        );
        // Augment the render state with heat data so render_tree_node
        // emits the same attribute set as the page render. We have to
        // re-call set_render_state because the previous call replaced
        // the array wholesale.
        $existing_state = [
            'viewer_user_id'    => $current_user_id,
            'display_user_id'   => $effective_root_user_id,
            'plan_id'           => (int) $row->plan_id,
            'is_pivoted'        => ($effective_root_user_id !== $current_user_id),
            'referral_url'      => $referral_url,
            'goal_level'        => 0,
            'level_commissions' => $level_commissions,
            'currency_symbol'   => get_option('matrix_mlm_currency_symbol', '₦'),
            'heat_data'         => $heat_data,
            // mode + heat_metric drive which metric's label is
            // pre-filled into the visible pill text. We default to
            // 'structure'/'downline' so the SSR pill is hidden by
            // default — when the page is in Activity mode, the
            // matrix:subtree-loaded JS handler immediately rewrites
            // every newly-arrived pill against the live metric, so
            // there's no flash of stale or wrong-metric text.
            'mode'              => 'structure',
            'heat_metric'       => 'downline',
        ];
        $renderer->set_render_state($existing_state);
        $renderer->render_children_inner(
            $tree,
            (int) $row->plan_width,
            $effective_root_user_id,
            $max_depth
        );
        echo '</div>';
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }

    /**
     * AJAX: lazy-load a deeper subtree as raw JSON node data.
     *
     * Sibling of process_fetch_subtree() that powers the *D3.js*
     * genealogy view (matrix-genealogy-d3.js). The two endpoints
     * share their auth gate, their input shape, and the same
     * Matrix_MLM_Plan_Engine::build_subtree() data fetcher — they
     * differ only in the response format:
     *
     *   - process_fetch_subtree()      → ready-to-inject HTML chunk
     *                                    (used by the classic CSS
     *                                    tree's expand button).
     *   - process_fetch_subtree_json() → raw nested node objects
     *                                    (used by the D3 view, which
     *                                    re-runs its own d3-hierarchy
     *                                    layout when new nodes land).
     *
     * Why a separate endpoint rather than a `format=json` toggle on
     * the existing one:
     *
     *   - The HTML response goes through render_children_inner()
     *     which depends on per-render pivot_state (referral URL,
     *     level commissions, currency symbol, heat data) AND a
     *     fresh recursion partner instance of Matrix_MLM_User_Genealogy.
     *     The JSON response needs none of that — it's just data.
     *     A toggle on the existing method would mean every change
     *     to the render path has to remember to early-return on
     *     the JSON branch, which is exactly the kind of coupling
     *     that lets bugs slip in.
     *   - Two endpoints means the classic and D3 views can evolve
     *     independently. If we ever simplify the classic view's
     *     lazy-load (or remove it), this endpoint stays stable.
     *
     * Response shape on success:
     *
     *     {
     *       success: true,
     *       data: {
     *         tree: {
     *           id:            123,           // matrix_positions.id
     *           user_id:       42,
     *           sponsor_id:    7,             // or null
     *           username:      "alice",
     *           level:         5,             // absolute level in the
     *                                        //  plan, NOT relative to
     *                                        //  the requested root —
     *                                        //  preserves continuity
     *                                        //  with the page render
     *                                        //  and lets the JS keep
     *                                        //  level-aware UI (heat
     *                                        //  bands, level badges)
     *                                        //  consistent.
     *           total_downline: 17,
     *           status:         "active",
     *           children:       [ ... recursive same shape ... ]
     *         },
     *         from_level: 4,                  // echo of the request
     *                                        //  param so the client
     *                                        //  can tell which call
     *                                        //  this response answers
     *                                        //  if multiple are in
     *                                        //  flight.
     *         max_depth:  7,                  // absolute depth covered
     *                                        //  by this response.
     *         plan_width: 3,                  // for empty-slot padding
     *                                        //  on the D3 side, which
     *                                        //  doesn't have access to
     *                                        //  matrix_plans on its
     *                                        //  own.
     *         plan_depth: 9
     *       }
     *     }
     *
     * Authorization is identical to process_fetch_subtree(): the
     * caller must be the owner of the requested position OR an
     * ancestor of it (validated by user_can_view_position(), which
     * walks parent_id up the chain). Returning JSON instead of HTML
     * doesn't change the auth contract — what a viewer is allowed
     * to *see* is the same regardless of how the response is
     * formatted.
     *
     * Cap: same as the HTML endpoint — three more absolute levels
     * per click, bounded by the plan's depth. Tuning the chunk size
     * would be a coordinated change across both endpoints.
     */
    private function process_fetch_subtree_json() {
        $position_id = isset($_POST['position_id']) ? (int) $_POST['position_id'] : 0;
        $from_level  = isset($_POST['from_level'])  ? (int) $_POST['from_level']  : 0;

        if ($position_id <= 0 || $from_level <= 0) {
            wp_send_json_error(['message' => __('Invalid request.', 'matrix-mlm')]);
        }

        global $wpdb;

        // Same single-row position+plan join used by the HTML
        // endpoint — see process_fetch_subtree() for the rationale.
        // We need plan_width and plan_depth on the response so the
        // D3 client can pad empty slots and stop-condition its
        // expand buttons without a separate plan-metadata round
        // trip.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT p.id AS position_id, p.user_id AS owner_user_id, p.plan_id,
                    pl.width AS plan_width, pl.depth AS plan_depth
               FROM {$wpdb->prefix}matrix_positions p
               LEFT JOIN {$wpdb->prefix}matrix_plans pl ON pl.id = p.plan_id
              WHERE p.id = %d",
            $position_id
        ));

        if (!$row || !$row->plan_id) {
            wp_send_json_error(['message' => __('Position not found.', 'matrix-mlm')]);
        }

        $current_user_id = get_current_user_id();
        $plan_engine     = new Matrix_MLM_Plan_Engine();

        // Same auth gate the HTML endpoint uses — caller must be on
        // the parent_id chain of the requested position. This is the
        // only access-control check the JSON endpoint needs because
        // build_subtree() only follows parent_id downwards from the
        // authorised root.
        if (!$plan_engine->user_can_view_position($current_user_id, $position_id)) {
            wp_send_json_error(['message' => __('You do not have access to this part of the genealogy.', 'matrix-mlm')]);
        }

        // Same chunk-size policy as the HTML endpoint: 3 more
        // absolute levels per click, never deeper than the plan's
        // configured depth. Keeping the two endpoints in lockstep
        // means a member who toggles between classic and D3 views
        // sees the same expand granularity in both, so muscle
        // memory ports across.
        $expand_levels = 3;
        $max_depth     = min((int) $row->plan_depth, $from_level + $expand_levels);

        $tree = $plan_engine->build_subtree($position_id, (int) $row->plan_id, $from_level, $max_depth);
        if (!$tree) {
            wp_send_json_error(['message' => __('Could not build subtree.', 'matrix-mlm')]);
        }

        wp_send_json_success([
            'tree'       => $tree,
            'from_level' => (int) $from_level,
            'max_depth'  => (int) $max_depth,
            'plan_width' => (int) $row->plan_width,
            'plan_depth' => (int) $row->plan_depth,
        ]);
    }

    /**
     * AJAX: search the current viewer's downline for members whose
     * username, email or display name matches a free-text query.
     *
     * Powers the typeahead in the genealogy view's search box. The
     * request shape is intentionally minimal — `q` and `plan_id` —
     * because the box only ever runs against the currently-viewed
     * plan and the only thing the viewer is allowed to do with a
     * result is jump to it (so we precompute the pivot URL on the
     * server and ship it down with the row).
     *
     * Authorization model:
     *   - Standard nonce check (already done in the dispatcher).
     *   - Must be logged in (get_current_user_id > 0). Anonymous
     *     callers can't have a downline so their search is never
     *     meaningful.
     *   - The downline scope itself is enforced inside
     *     Matrix_MLM_Plan_Engine::search_downline_users(), which
     *     runs each candidate position through user_can_view_position()
     *     before letting the row out. That's the same gate the
     *     ?pivot_user_id=X page render and the fetch_subtree AJAX
     *     endpoint use, so what comes back from the search will
     *     always be a valid pivot target — the JS can navigate
     *     straight to result.pivot_url without re-validating.
     *
     * Response shape on success:
     *     {
     *       success: true,
     *       data: {
     *         query:   "ali",
     *         results: [
     *           {
     *             user_id:      42,
     *             username:     "alice",
     *             display_name: "Alice Wonderland",
     *             email_masked: "a***@example.com",
     *             level:        3,
     *             pivot_url:    "https://…/matrix-dashboard/?tab=genealogy&plan_id=…&pivot_user_id=42"
     *           },
     *           …
     *         ]
     *       }
     *     }
     *
     * The email is *masked* before being returned because the
     * downline list above contains members the viewer hasn't
     * necessarily transacted with — they're often just structural
     * placements via spillover. Showing full email addresses to
     * upline members would be a privacy regression compared to
     * the rest of the genealogy view, which only shows usernames.
     * The masked form is a disambiguator (so two members named
     * "Alice" can be told apart), not a contact channel.
     */
    private function process_genealogy_search() {
        $current_user_id = get_current_user_id();
        if ($current_user_id <= 0) {
            wp_send_json_error(['message' => __('You must be logged in to search.', 'matrix-mlm')]);
        }

        $query   = isset($_POST['q'])       ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
        $plan_id = isset($_POST['plan_id']) ? (int) $_POST['plan_id']                       : 0;

        if ($plan_id <= 0) {
            wp_send_json_error(['message' => __('Plan is required.', 'matrix-mlm')]);
        }

        // Mirror the engine's own minimum-length contract here so
        // we return a clean empty payload (rather than an error)
        // when the user has only typed one character — that lets
        // the JS render the empty-state hint instead of an error
        // toast every keystroke.
        if (mb_strlen($query) < 2) {
            wp_send_json_success([
                'query'   => $query,
                'results' => [],
            ]);
        }

        $plan_engine = new Matrix_MLM_Plan_Engine();
        $matches     = $plan_engine->search_downline_users(
            $current_user_id,
            $plan_id,
            $query,
            10
        );

        // Build the pivot URL on the server so the JS doesn't have
        // to know about pretty-permalink vs query-string
        // dashboards. Matrix_MLM_User_Dashboard::tab_url() already
        // makes that decision for the rest of the dashboard, and
        // routing every pivot URL through it keeps the search
        // results consistent with the breadcrumb crumb hrefs.
        $base_url = class_exists('Matrix_MLM_User_Dashboard')
            ? Matrix_MLM_User_Dashboard::tab_url('genealogy')
            : home_url('/matrix-dashboard/?tab=genealogy');

        $rows = [];
        foreach ($matches as $m) {
            $rows[] = [
                'user_id'      => (int) $m['user_id'],
                'username'     => (string) $m['username'],
                'display_name' => (string) $m['display_name'],
                'email_masked' => $this->mask_email_for_search((string) $m['email']),
                'level'        => (int) $m['level'],
                'pivot_url'    => add_query_arg(
                    [
                        'plan_id'       => $plan_id,
                        'pivot_user_id' => (int) $m['user_id'],
                    ],
                    $base_url
                ),
            ];
        }

        wp_send_json_success([
            'query'   => $query,
            'results' => $rows,
        ]);
    }

    /**
     * Mask an email address for display in the genealogy search
     * dropdown.
     *
     * Renders alice@example.com as a***@example.com — enough for
     * the searcher to disambiguate two members with the same
     * display name but not enough to harvest contact details from
     * the downline tree. Used only by the search response; full
     * email addresses are never returned to upline members through
     * any genealogy-view surface.
     *
     * Edge cases that fall through to a generic placeholder:
     *   - Missing or malformed addresses (no '@'): returns ''.
     *   - Single-character local part: keeps the visible char and
     *     replaces the rest of the local with three asterisks
     *     (e.g. 'a@x.com' -> 'a***@x.com') so the format reads
     *     consistently in the dropdown.
     */
    private function mask_email_for_search($email) {
        $email = trim((string) $email);
        if ($email === '' || strpos($email, '@') === false) {
            return '';
        }
        list($local, $domain) = explode('@', $email, 2);
        if ($local === '') {
            return '';
        }
        $first = mb_substr($local, 0, 1);
        return $first . '***@' . $domain;
    }

    /**
     * AJAX: return enriched detail for a single genealogy node so the
     * hover-card above the tree can show full name, joined date,
     * sponsor, plans and total branch commission without bloating
     * every server-rendered tree node up front.
     *
     * Why this is its own endpoint rather than baking the data into
     * the initial tree payload:
     *   - The branch-commission sum is the expensive bit (BFS over
     *     the subtree + one SUM query). Doing it for every visible
     *     node on every dashboard pageload would multiply the cost
     *     by the visible-node count for data the member only
     *     actually wants when they hover.
     *   - The "view profile" admin link needs runtime
     *     current_user_can() context that's cleanest to compute on
     *     the request that needs it; baking it into a JSON blob
     *     served alongside the static HTML would mix concerns.
     *
     * Authorization: same user_can_view_position() walk every other
     * genealogy surface uses, so a member can't poke around someone
     * else's downline by guessing position ids — the search box,
     * the page-level pivot, the lazy-load endpoint and now the
     * hover-card all share one auth gate.
     *
     * Returns (on success):
     *   {
     *     position_id, user_id, username, full_name, joined,
     *     level, sponsor (string label or ''), plans (array of
     *     "Name (WxD)"), commission { amount, amount_display,
     *     capped }, profile_url (admins only, '' otherwise),
     *     is_self (bool — viewer hovering their own card)
     *   }
     */
    private function process_node_details() {
        $current_user_id = get_current_user_id();
        if ($current_user_id <= 0) {
            wp_send_json_error(['message' => __('You must be logged in.', 'matrix-mlm')]);
        }

        $position_id = isset($_POST['position_id']) ? (int) $_POST['position_id'] : 0;
        if ($position_id <= 0) {
            wp_send_json_error(['message' => __('Invalid request.', 'matrix-mlm')]);
        }

        global $wpdb;

        // Single round-trip pull of position + plan + user fields the
        // hover-card needs. matrix_positions and wp_users on user_id,
        // matrix_user_meta only as a left-join in case the row is
        // missing on legacy imports.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT p.id, p.user_id, p.plan_id, p.sponsor_id,
                    p.joined_at, p.level
               FROM {$wpdb->prefix}matrix_positions p
              WHERE p.id = %d",
            $position_id
        ));
        if (!$row || !$row->plan_id) {
            wp_send_json_error(['message' => __('Position not found.', 'matrix-mlm')]);
        }

        $plan_engine = new Matrix_MLM_Plan_Engine();
        if (!$plan_engine->user_can_view_position($current_user_id, $position_id)) {
            wp_send_json_error([
                'message' => __('You do not have access to this member.', 'matrix-mlm')
            ]);
        }

        $node_user_id = (int) $row->user_id;
        $user         = get_userdata($node_user_id);
        if (!$user) {
            // The position points at a user_id with no matching
            // wp_users row. Shouldn't happen — but if it does the
            // hover-card has nothing useful to render, so error
            // gracefully rather than ship half a payload.
            wp_send_json_error(['message' => __('Member record not found.', 'matrix-mlm')]);
        }

        // Full name: prefer first + last; fall back to display_name;
        // last fall back to user_login. Members commonly leave their
        // first/last empty so the fall-through chain matters.
        $first_name = trim((string) $user->first_name);
        $last_name  = trim((string) $user->last_name);
        $full_name  = trim($first_name . ' ' . $last_name);
        if ($full_name === '') {
            $full_name = $user->display_name ?: $user->user_login;
        }

        // Joined date: prefer the matrix_positions.joined_at because
        // the WP user could have registered long before they joined a
        // plan (or via an importer that backfilled wp_users with the
        // old registration date). Falls back to user_registered when
        // the position row's joined_at is empty (legacy rows).
        $joined_raw    = !empty($row->joined_at) ? (string) $row->joined_at : (string) $user->user_registered;
        $joined_ts     = $joined_raw ? strtotime($joined_raw) : 0;
        $joined_label  = $joined_ts ? date_i18n(get_option('date_format'), $joined_ts) : '';

        // Sponsor: matrix_positions.sponsor_id is the explicit
        // structural sponsor (who actually referred this member),
        // distinct from parent_id which is just placement. NULL on
        // the root and on legacy-imported rows where the backfill
        // couldn't resolve a sponsor.
        $sponsor_label = '';
        $sponsor_id    = (int) $row->sponsor_id;
        if ($sponsor_id > 0) {
            $sponsor_user = get_userdata($sponsor_id);
            $sponsor_label = $sponsor_user
                ? (string) $sponsor_user->user_login
                : sprintf(__('User #%d', 'matrix-mlm'), $sponsor_id);
        }

        // All active positions this member holds, across every plan
        // (not just the currently-viewed plan). Members commonly
        // join several plans — surfacing them all in the hover-card
        // gives the upline context they can't get from a tree
        // rooted at one plan.
        $plan_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pl.name, pl.width, pl.depth
               FROM {$wpdb->prefix}matrix_positions p
               JOIN {$wpdb->prefix}matrix_plans pl ON pl.id = p.plan_id
              WHERE p.user_id = %d
                AND p.status = 'active'
              ORDER BY pl.price ASC",
            $node_user_id
        ));
        $plans_display = [];
        foreach ($plan_rows as $pl) {
            $plans_display[] = sprintf(
                '%s (%dx%d)',
                $pl->name,
                (int) $pl->width,
                (int) $pl->depth
            );
        }

        // Branch commission (in this plan's tree): how much money has
        // the *viewer* earned because of this branch. See
        // sum_branch_commissions_for_viewer() docblock for the
        // recipient/trigger semantics. Hard-capped descendant set so
        // the hover stays snappy on huge downlines.
        $commission = $plan_engine->sum_branch_commissions_for_viewer(
            $current_user_id,
            (int) $row->id,
            (int) $row->plan_id,
            2000
        );

        $currency_symbol  = get_option('matrix_mlm_currency_symbol', '₦');
        $commission_display = $currency_symbol . number_format((float) $commission['amount'], 2);

        // Admin profile link: only handed back to the front end when
        // the viewer can actually edit users in wp-admin. Anyone
        // without that cap gets an empty string and the hover-card
        // hides the link entirely.
        $profile_url = '';
        if (current_user_can('edit_users') || current_user_can('manage_options')) {
            $profile_url = admin_url('user-edit.php?user_id=' . $node_user_id);
        }

        wp_send_json_success([
            'position_id' => (int) $row->id,
            'user_id'     => $node_user_id,
            'username'    => (string) $user->user_login,
            'full_name'   => $full_name,
            'joined'      => $joined_label,
            'level'       => (int) $row->level,
            'sponsor'     => $sponsor_label,
            'plans'       => $plans_display,
            'commission'  => [
                'amount'         => (float) $commission['amount'],
                'amount_display' => $commission_display,
                'capped'         => (bool) $commission['capped'],
                'descendants'    => (int) $commission['descendants'],
            ],
            'profile_url' => $profile_url,
            'is_self'     => ($node_user_id === $current_user_id),
        ]);
    }

    /**
     * AJAX: per-position commission attribution map for the
     * genealogy "income map" overlay.
     *
     * Backs the toolbar toggle in the D3 genealogy view that turns
     * every node card into a literal earnings tag — for each
     * descendant, "this is how much money this person, sitting
     * exactly where they're sitting, has earned for you". The
     * recipient/trigger semantics are the same as the hover-card's
     * branch-commission number (which sums the whole branch); this
     * endpoint just doesn't collapse the per-member contributions.
     *
     * Single round-trip: the helper returns the sparse map for
     * the entire authorised subtree, regardless of how much of it
     * the JS has currently rendered. That's important because the
     * D3 view lazy-loads deeper levels — once a member toggles the
     * overlay on, expanding a "Show more" badge later should
     * surface attribution badges on the newly rendered nodes
     * without re-fetching. The hard cap (5000 by default) is the
     * only reason the map could ever miss a node, and the
     * `capped` flag in the response lets the client warn about it.
     *
     * Auth: same gate as fetch_subtree_json / node_details. The
     * viewer must be the position owner or an ancestor.
     */
    private function process_get_commission_attribution() {
        $current_user_id = get_current_user_id();
        if ($current_user_id <= 0) {
            wp_send_json_error(['message' => __('You must be logged in.', 'matrix-mlm')]);
        }

        $position_id = isset($_POST['position_id']) ? (int) $_POST['position_id'] : 0;
        if ($position_id <= 0) {
            wp_send_json_error(['message' => __('Invalid request.', 'matrix-mlm')]);
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, plan_id FROM {$wpdb->prefix}matrix_positions WHERE id = %d",
            $position_id
        ));
        if (!$row || !$row->plan_id) {
            wp_send_json_error(['message' => __('Position not found.', 'matrix-mlm')]);
        }

        $plan_engine = new Matrix_MLM_Plan_Engine();
        if (!$plan_engine->user_can_view_position($current_user_id, $position_id)) {
            wp_send_json_error([
                'message' => __('You do not have access to this part of the genealogy.', 'matrix-mlm')
            ]);
        }

        $result = $plan_engine->get_per_user_commission_attribution(
            $current_user_id,
            (int) $row->id,
            (int) $row->plan_id,
            5000
        );

        // Format every amount on the server side so the JS doesn't
        // have to know about locale-aware thousands/decimal
        // separators. Mirrors the heatmap's compact-money
        // convention (no decimals on >= 1000) so the income-map
        // and the heatmap don't visually fight each other when a
        // member toggles between them.
        $currency_symbol = get_option('matrix_mlm_currency_symbol', '₦');
        $attribution_payload = [];
        foreach ($result['attribution'] as $uid => $entry) {
            $amount = (float) $entry['amount'];
            $attribution_payload[$uid] = [
                'amount'         => $amount,
                'amount_display' => $currency_symbol . number_format_i18n(
                    $amount,
                    $amount >= 1000 ? 0 : 2
                ),
                'count'          => (int) $entry['count'],
            ];
        }

        $total_amount = (float) $result['total']['amount'];

        wp_send_json_success([
            'currency'    => $currency_symbol,
            'attribution' => $attribution_payload,
            'total'       => [
                'amount'         => $total_amount,
                'amount_display' => $currency_symbol . number_format_i18n($total_amount, 2),
                'count'          => (int) $result['total']['count'],
                'members'        => (int) $result['total']['members'],
            ],
            'capped'      => (bool) $result['capped'],
            'descendants' => (int) $result['descendants'],
        ]);
    }

    private function process_ticket() {
        $user_id = get_current_user_id();
        $subject = sanitize_text_field($_POST['subject'] ?? '');
        $message = sanitize_textarea_field($_POST['message'] ?? '');

        // Whitelist priority. sanitize_text_field strips tags but lets attribute-
        // breaking bytes (", >, =) through, and the ticket views render priority
        // both inside class="matrix-badge matrix-badge-..." and as visible text
        // (audit H15). Coerce to a fixed enum so neither sink can ever receive
        // attacker-controlled bytes, regardless of whether the output sites
        // remember to escape.
        $priority_raw = sanitize_text_field($_POST['priority'] ?? 'medium');
        $allowed_priorities = ['low', 'medium', 'high', 'urgent'];
        $priority = in_array($priority_raw, $allowed_priorities, true) ? $priority_raw : 'medium';

        if (empty($subject) || empty($message)) {
            wp_send_json_error(['message' => __('Subject and message are required', 'matrix-mlm')]);
        }

        $support = new Matrix_MLM_Support();
        $result = $support->create_ticket($user_id, $subject, $message, $priority);

        wp_send_json_success(['message' => __('Ticket created successfully', 'matrix-mlm'), 'ticket_id' => $result]);
    }

    private function process_profile_update() {
        $user_id = get_current_user_id();
        $data = [
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'address' => sanitize_textarea_field($_POST['address'] ?? ''),
            'city' => sanitize_text_field($_POST['city'] ?? ''),
            'state' => sanitize_text_field($_POST['state'] ?? ''),
            'country' => sanitize_text_field($_POST['country'] ?? ''),
            'zip_code' => sanitize_text_field($_POST['zip_code'] ?? ''),
        ];

        global $wpdb;
        $wpdb->update($wpdb->prefix . 'matrix_user_meta', $data, ['user_id' => $user_id]);

        // Update WP user data
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');
        if ($first_name) update_user_meta($user_id, 'first_name', $first_name);
        if ($last_name) update_user_meta($user_id, 'last_name', $last_name);

        // Update extended profile fields
        $date_of_birth = sanitize_text_field($_POST['date_of_birth'] ?? '');
        $gender = sanitize_text_field($_POST['gender'] ?? '');
        $bio = sanitize_textarea_field($_POST['bio'] ?? '');
        $bank_name = sanitize_text_field($_POST['bank_name'] ?? '');
        $bank_account = sanitize_text_field($_POST['bank_account'] ?? '');
        $bank_account_name = sanitize_text_field($_POST['bank_account_name'] ?? '');

        update_user_meta($user_id, 'matrix_date_of_birth', $date_of_birth);
        update_user_meta($user_id, 'matrix_gender', $gender);
        update_user_meta($user_id, 'matrix_bio', $bio);
        update_user_meta($user_id, 'matrix_bank_name', $bank_name);
        update_user_meta($user_id, 'matrix_bank_account', $bank_account);
        update_user_meta($user_id, 'matrix_bank_account_name', $bank_account_name);

        wp_send_json_success(['message' => __('Profile updated successfully', 'matrix-mlm')]);
    }

    private function process_upload_avatar() {
        $user_id = get_current_user_id();

        if (empty($_FILES['avatar']) || !isset($_FILES['avatar']['tmp_name'])) {
            wp_send_json_error(['message' => __('No file uploaded', 'matrix-mlm')]);
        }

        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        $file = $_FILES['avatar'];

        // PHP-side upload error surface (oversize per ini, partial,
        // no-file). Filter these out before any further work so the
        // user sees a clear "no file" message instead of a confusing
        // mime/size message produced from a half-uploaded tmp file.
        $err = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE || empty($file['tmp_name'])) {
            wp_send_json_error(['message' => __('No file uploaded', 'matrix-mlm')]);
        }
        if ($err !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => __('Upload failed. Please try again.', 'matrix-mlm')]);
        }

        // Real on-disk size — never trust $_FILES['size'] which is
        // attacker-controlled (the multipart form field can claim any
        // value regardless of how many bytes actually landed in
        // tmp_name). 2 MB cap matches the existing UI copy. (audit L3)
        $real_size = @filesize($file['tmp_name']);
        if ($real_size === false || $real_size <= 0) {
            wp_send_json_error(['message' => __('Could not read uploaded file. Please try again.', 'matrix-mlm')]);
        }
        if ($real_size > 2 * 1024 * 1024) {
            wp_send_json_error(['message' => __('File too large. Maximum 2MB', 'matrix-mlm')]);
        }

        // Server-side MIME detection. wp_check_filetype_and_ext()
        // inspects file content with fileinfo where available — it
        // does NOT trust the client-supplied Content-Type header
        // ($file['type']), which can be spoofed to anything by a curl
        // attacker. Same allow-list as before, but expressed in the
        // shape WP wants ('extension-regex' => 'mime'). (audit M4)
        $allowed_mimes = [
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'gif'          => 'image/gif',
            'webp'         => 'image/webp',
        ];
        $check = wp_check_filetype_and_ext(
            $file['tmp_name'],
            $file['name'],
            $allowed_mimes
        );
        if (empty($check['type']) || empty($check['ext']) || !in_array($check['type'], $allowed_mimes, true)) {
            wp_send_json_error(['message' => __('Invalid file type. Allowed: JPG, PNG, GIF, WebP', 'matrix-mlm')]);
        }

        $upload = wp_handle_upload($file, [
            'test_form' => false,
            'mimes'     => $allowed_mimes,
        ]);
        if (isset($upload['error'])) {
            wp_send_json_error(['message' => $upload['error']]);
        }

        update_user_meta($user_id, 'matrix_avatar_url', $upload['url']);
        wp_send_json_success(['message' => __('Avatar updated', 'matrix-mlm'), 'url' => $upload['url']]);
    }

    /**
     * AJAX: poll for new descendants that joined under the
     * currently-viewed root since a given timestamp.
     *
     * Powers the genealogy tree's real-time updates: the D3 view
     * fires this every ~45 seconds and grafts the response into
     * its live data, animating new arrivals with a subtle pulse.
     * The whole point is to remove the "refresh to see your new
     * referral" friction that previously sat between members and
     * the dopamine hit of seeing their tree grow.
     *
     * Request shape:
     *   matrix_action = fetch_new_descendants
     *   plan_id       (int, required)  Plan whose tree is being viewed.
     *   position_id   (int, optional)  matrix_positions.id of the
     *                                  currently-displayed root —
     *                                  defaults to the viewer's own
     *                                  active position in the plan.
     *                                  Set when the genealogy view is
     *                                  pivoted onto a downline member
     *                                  (?pivot_user_id=X) so polling
     *                                  scopes to that pivot's subtree.
     *   since         (int, required)  Unix timestamp; only positions
     *                                  with joined_at strictly greater
     *                                  than this are returned. Capped
     *                                  server-side to a 24-hour
     *                                  window so a stale or
     *                                  clock-skewed client can't
     *                                  request a full-history scan.
     *
     * Authorization model:
     *   - Standard nonce check (already done by handle_ajax dispatcher).
     *   - Must be logged in (get_current_user_id > 0).
     *   - The supplied position_id must pass user_can_view_position()
     *     against the viewer — same gate the lazy-expand and node-
     *     details endpoints use, so polling can never grant
     *     visibility the page render itself wouldn't.
     *   - Each candidate row gets an individual user_can_view_position()
     *     check before being emitted, defending against a malicious
     *     since value combined with a guessed plan id.
     *
     * Response shape:
     *     {
     *       success: true,
     *       data: {
     *         server_time:    1716489600,   // unix ts the client should send
     *                                       // back as the next `since`
     *         plan_id:        3,
     *         root_user_id:   42,
     *         new_nodes:      [
     *           {
     *             id:             123,      // matrix_positions.id
     *             user_id:        77,
     *             parent_id:      45,
     *             sponsor_id:     42,
     *             username:       "alice",
     *             level:          5,
     *             total_downline: 0,
     *             status:         "active",
     *             relationship:   "direct" | "spillover" | "you",
     *             joined_ts:      1716489580
     *           },
     *           ...
     *         ],
     *         updated_parents: [             // every visible ancestor of
     *                                        // any new node — the client
     *                                        // refreshes their meta line
     *                                        // ("N downline") in place.
     *           { id: 45, total_downline: 1 },
     *           { id: 12, total_downline: 8 },
     *           ...
     *         ]
     *       }
     *     }
     *
     * Performance budget:
     *   - At most 50 candidate rows are scanned per call. The 24-hour
     *     `since` floor is the second guardrail: even an attacker
     *     replaying the request can't exceed 50 auth walks against
     *     the rolling 24-hour set of joins in this plan.
     *   - Each auth walk is the same O(plan_depth) parent_id climb
     *     used by the existing tree endpoints. For a 3x9 matrix that's
     *     <= 9 single-row queries.
     *   - Ancestor refresh: at most ~plan_depth distinct parent ids
     *     across all returned new nodes (because their chains converge
     *     at the root). Returned in a single IN(...) query.
     */
    private function process_fetch_new_descendants() {
        $current_user_id = get_current_user_id();
        if ($current_user_id <= 0) {
            wp_send_json_error(['message' => __('You must be logged in.', 'matrix-mlm')]);
        }

        $plan_id     = isset($_POST['plan_id'])     ? (int) $_POST['plan_id']     : 0;
        $position_id = isset($_POST['position_id']) ? (int) $_POST['position_id'] : 0;
        $since       = isset($_POST['since'])       ? (int) $_POST['since']       : 0;

        if ($plan_id <= 0) {
            wp_send_json_error(['message' => __('Invalid request.', 'matrix-mlm')]);
        }

        // Cap the polling window. A well-behaved client always sends
        // back the previous response's server_time, which keeps `since`
        // within a poll-interval of "now". A misbehaving or
        // long-paused client (tab buried for hours, then visibility
        // restores) could otherwise drag in months of history; the
        // 24-hour floor bounds that scenario without breaking the
        // common case.
        $now          = time();
        $window_floor = $now - DAY_IN_SECONDS;
        if ($since <= 0 || $since < $window_floor) {
            $since = $window_floor;
        }

        global $wpdb;
        $plan_engine = new Matrix_MLM_Plan_Engine();

        // Resolve / authorize the root the viewer is currently looking
        // at. Two paths:
        //   - Client supplied position_id (pivoted view OR
        //     belt-and-braces request from D3 with bootstrap.tree.id):
        //     check it belongs to the same plan and that the viewer
        //     can see it.
        //   - Client omitted it: fall back to the viewer's own active
        //     position in the plan. This is the unpivoted, common
        //     case.
        if ($position_id > 0) {
            $root_row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, user_id, plan_id
                   FROM {$wpdb->prefix}matrix_positions
                  WHERE id = %d",
                $position_id
            ));
            if (!$root_row || (int) $root_row->plan_id !== $plan_id) {
                wp_send_json_error(['message' => __('Position not in this plan.', 'matrix-mlm')]);
            }
            if (!$plan_engine->user_can_view_position($current_user_id, (int) $root_row->id)) {
                wp_send_json_error(['message' => __('You do not have access to this position.', 'matrix-mlm')]);
            }
            $root_position_id = (int) $root_row->id;
            $root_user_id     = (int) $root_row->user_id;
        } else {
            $root_position_id = $plan_engine->get_position_id_for_user_in_plan(
                $current_user_id,
                $plan_id
            );
            if ($root_position_id <= 0) {
                wp_send_json_error(['message' => __('No active position in this plan.', 'matrix-mlm')]);
            }
            $root_user_id = $current_user_id;
        }

        // Pull recent joins in the plan, ordered oldest-first so the
        // animated insertion sequence on the client mirrors the order
        // they actually arrived — small detail, but it keeps the
        // pulse cascade narratively coherent ("then this one, then
        // that one") instead of randomly jumbled.
        //
        // The status='active' filter mirrors every other tree
        // surface — inactive/completed positions are not on the
        // visible tree so they shouldn't be polled either.
        //
        // We exclude the root position itself defensively: a clock
        // skew that placed `since` before the root's own join_at
        // would otherwise spam the response with the viewer's own
        // node, which they obviously already see.
        $candidates = $wpdb->get_results($wpdb->prepare(
            "SELECT p.id, p.user_id, p.parent_id, p.sponsor_id, p.level,
                    p.total_downline, p.status, p.joined_at,
                    u.user_login
               FROM {$wpdb->prefix}matrix_positions p
          LEFT JOIN {$wpdb->users} u ON u.ID = p.user_id
              WHERE p.plan_id    = %d
                AND p.joined_at  > FROM_UNIXTIME(%d)
                AND p.status     = 'active'
                AND p.id        <> %d
           ORDER BY p.joined_at ASC
              LIMIT 50",
            $plan_id,
            (int) $since,
            $root_position_id
        ));

        $new_nodes        = [];
        $ancestor_id_set  = [];

        foreach ($candidates as $row) {
            // Per-row downline auth gate. user_can_view_position()
            // walks parent_id upward from $row->id and returns true
            // iff the viewer's user id appears on the chain. This is
            // the structural truth — denormalised counters can lag
            // but the parent_id chain can't, so this is the right
            // place to make the access decision.
            if (!$plan_engine->user_can_view_position($current_user_id, (int) $row->id)) {
                continue;
            }

            $sponsor_id = ($row->sponsor_id !== null) ? (int) $row->sponsor_id : 0;
            // Mirror prepare_tree_for_d3()'s classification exactly so
            // the polling-grafted nodes colour-match the rest of the
            // tree. See Matrix_MLM_User_Genealogy::prepare_tree_for_d3
            // for the rationale on why this rule lives in PHP.
            if ((int) $row->user_id === $root_user_id) {
                $relationship = 'you';
            } elseif ($sponsor_id > 0 && $sponsor_id === $root_user_id) {
                $relationship = 'direct';
            } else {
                $relationship = 'spillover';
            }

            $joined_ts = $row->joined_at ? strtotime((string) $row->joined_at) : 0;

            $new_nodes[] = [
                'id'             => (int) $row->id,
                'user_id'        => (int) $row->user_id,
                'parent_id'      => $row->parent_id !== null ? (int) $row->parent_id : null,
                'sponsor_id'     => $sponsor_id > 0 ? $sponsor_id : null,
                'username'       => (string) ($row->user_login ?: ('User #' . (int) $row->user_id)),
                'level'          => (int) $row->level,
                'total_downline' => (int) $row->total_downline,
                'status'         => (string) $row->status,
                'relationship'   => $relationship,
                'joined_ts'      => $joined_ts ?: 0,
            ];

            // Walk the parent_id chain up to the root and collect
            // every ancestor's id. The client refreshes the
            // total_downline meta on each in place so the user sees
            // the count tick up on every visible ancestor card —
            // the parent's "1 downline" becoming "2 downline" is
            // half the satisfaction of the live update.
            //
            // Bounded by the same 30-hop ceiling user_can_view_position
            // uses; in practice we stop at $root_position_id.
            $cur = $row->parent_id !== null ? (int) $row->parent_id : 0;
            for ($hops = 0; $hops < 30 && $cur > 0; $hops++) {
                if (isset($ancestor_id_set[$cur])) {
                    // Already seen this ancestor on a previous
                    // candidate's walk — its own ancestors are
                    // already collected too, so we can short-circuit.
                    break;
                }
                $ancestor_id_set[$cur] = true;
                if ($cur === $root_position_id) {
                    break;
                }
                $next_parent = $wpdb->get_var($wpdb->prepare(
                    "SELECT parent_id FROM {$wpdb->prefix}matrix_positions WHERE id = %d",
                    $cur
                ));
                if ($next_parent === null) {
                    break;
                }
                $cur = (int) $next_parent;
            }
        }

        // Resolve the ancestor id set into fresh total_downline values.
        // Single IN(...) lookup so this stays one round-trip even when
        // 50 new nodes land at once on a deep matrix.
        //
        // We don't re-run user_can_view_position() on each ancestor —
        // membership in the chain of a candidate that already passed
        // the auth gate is itself proof of visibility (transitivity:
        // if viewer can see X, viewer can see every ancestor of X up
        // to and including the root).
        $updated_parents = [];
        if (!empty($ancestor_id_set)) {
            $ids          = array_map('intval', array_keys($ancestor_id_set));
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, total_downline
                   FROM {$wpdb->prefix}matrix_positions
                  WHERE id IN ($placeholders)",
                $ids
            ));
            foreach ($rows as $r) {
                $updated_parents[] = [
                    'id'             => (int) $r->id,
                    'total_downline' => (int) $r->total_downline,
                ];
            }
        }

        wp_send_json_success([
            'server_time'     => $now,
            'plan_id'         => $plan_id,
            'root_user_id'    => $root_user_id,
            'new_nodes'       => $new_nodes,
            'updated_parents' => $updated_parents,
        ]);
    }

    /**
     * Enable 2FA for the current user.
     *
     * Hardened per audit M2:
     *
     *   1. Require a fresh password reauth. A logged-in session alone
     *      is not enough — without this, an attacker who hijacked a
     *      session (XSS on a non-MatrixPro plugin, stolen auth cookie
     *      on a shared device, browser-extension token theft) could
     *      silently rotate the user's authenticator to a device they
     *      control, locking the legitimate user out of their own
     *      second factor.
     *
     *   2. Reject re-enrolment when 2FA is already enabled. The
     *      caller must disable first (which itself requires a
     *      current OTP or recovery code), so a hijacked session can
     *      never overwrite an existing authenticator without also
     *      possessing the second factor.
     *
     *   3. Return one-time recovery codes alongside the QR/secret so
     *      a user who later loses their device has a self-service
     *      recovery path, instead of depending on admin
     *      intervention. The codes are displayed exactly once; the
     *      DB stores only password_hash() digests.
     */
    private function process_enable_2fa() {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            wp_send_json_error(['message' => __('Authentication required.', 'matrix-mlm')]);
        }

        // Rate-limit before any bcrypt work. Without this, a stolen
        // session can spam the endpoint to drain wp_check_password
        // cycles. 5 attempts per 15-min is plenty for a real user
        // typo'ing their password a few times; brute-force loops are
        // bounded the same way the login surface is.
        if (Matrix_MLM_Rate_Limiter::throttle(
            '2fa_enable',
            Matrix_MLM_Rate_Limiter::key_for_request(),
            ['max_attempts' => 5, 'window_seconds' => 15 * MINUTE_IN_SECONDS]
        )) {
            wp_send_json_error([
                'message' => __('Too many attempts. Please wait a few minutes before trying again.', 'matrix-mlm'),
            ]);
        }

        $two_factor = new Matrix_MLM_Two_Factor();

        // Password reauth comes BEFORE the is_enabled() probe so a
        // session-only attacker cannot use this endpoint to learn
        // whether 2FA is currently on for the account they hijacked
        // — without this ordering, the already_enabled early-return
        // would answer that question without requiring a password.
        // Mirrors the ordering used in process_disable_2fa and
        // process_regenerate_recovery_codes (audit M2 follow-up).
        $this->require_password_reauth($user_id);

        // Block silent rotation. The disable path is the only way to
        // reach a state where this branch falls through, and that path
        // already requires a current OTP / recovery code.
        if ($two_factor->is_enabled($user_id)) {
            wp_send_json_error([
                'message' => __('Two-factor authentication is already enabled. Disable it first if you want to re-enrol.', 'matrix-mlm'),
                'already_enabled' => true,
            ]);
        }

        // Successful reauth — clear the throttle so a real user who
        // typo'd their password a few times isn't penalised on the
        // good attempt that followed.
        Matrix_MLM_Rate_Limiter::reset(
            '2fa_enable',
            Matrix_MLM_Rate_Limiter::key_for_request()
        );

        $result = $two_factor->enable($user_id);
        wp_send_json_success($result);
    }

    /**
     * Disable 2FA for the current user.
     *
     * Two gates: a fresh password reauth, and proof the caller still
     * controls the second factor (a current TOTP code OR an unused
     * recovery code). The second gate is what makes the disable path
     * safe to publish — without it, a hijacked session could disable
     * 2FA in one click and re-enable it pointed at the attacker's
     * device.
     *
     * Wires up the front-end button that has been calling
     * 'matrix_action: disable_2fa' since at least the public.js
     * baseline; before this PR the dispatcher had no case for it and
     * every disable click silently failed with 'Invalid action'.
     */
    private function process_disable_2fa() {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            wp_send_json_error(['message' => __('Authentication required.', 'matrix-mlm')]);
        }

        // Rate-limit BEFORE any state probe or bcrypt work. This is
        // the most sensitive of the three new endpoints because it
        // accepts a 6-digit OTP from an attacker who already has the
        // password — exactly the threat model 2FA exists for. Login
        // is bounded at 10/user/15-min, so an unbounded disable
        // endpoint would be the asymmetric way around that gate.
        // Five attempts per 15-min is the same shape used by the
        // bank/PAN handlers and gives the OTP space ~3 days to
        // brute-force at the cap, which is well below the practical
        // detection window on any operator with any monitoring.
        if (Matrix_MLM_Rate_Limiter::throttle(
            '2fa_disable',
            Matrix_MLM_Rate_Limiter::key_for_request(),
            ['max_attempts' => 5, 'window_seconds' => 15 * MINUTE_IN_SECONDS]
        )) {
            wp_send_json_error([
                'message' => __('Too many attempts. Please wait a few minutes before trying again.', 'matrix-mlm'),
            ]);
        }

        // Password reauth comes BEFORE the is_enabled() probe so a
        // session-only attacker cannot use this endpoint to learn
        // whether 2FA is currently on for the account they hijacked.
        // Without this ordering, the early-return on the
        // already-disabled branch would answer that question without
        // requiring a password.
        $this->require_password_reauth($user_id);

        $two_factor = new Matrix_MLM_Two_Factor();
        if (!$two_factor->is_enabled($user_id)) {
            // Idempotent — disable on an already-disabled account is
            // a no-op success rather than an error so the UI doesn't
            // surface a confusing message after a quick double-click.
            // Reset the throttle on this path too: a successful
            // password reauth has happened.
            Matrix_MLM_Rate_Limiter::reset(
                '2fa_disable',
                Matrix_MLM_Rate_Limiter::key_for_request()
            );
            wp_send_json_success([
                'message' => __('Two-factor authentication is already disabled.', 'matrix-mlm'),
            ]);
        }

        // The caller submits whichever they have available — a
        // current 6-digit OTP from their authenticator, or an unused
        // recovery code. verify() accepts both and consumes the
        // recovery code if that's what matched.
        $code = isset($_POST['code']) ? (string) $_POST['code'] : '';
        if ($code === '') {
            wp_send_json_error([
                'message' => __('Enter your current 2FA code or a recovery code to confirm.', 'matrix-mlm'),
            ]);
        }
        if (!$two_factor->verify($user_id, $code)) {
            wp_send_json_error([
                'message' => __('Invalid 2FA code.', 'matrix-mlm'),
            ]);
        }

        $two_factor->disable($user_id);
        Matrix_MLM_Rate_Limiter::reset(
            '2fa_disable',
            Matrix_MLM_Rate_Limiter::key_for_request()
        );
        wp_send_json_success([
            'message' => __('Two-factor authentication has been disabled.', 'matrix-mlm'),
        ]);
    }

    /**
     * Regenerate the user's recovery codes.
     *
     * Used when the user has burned through (or lost) their original
     * codes and wants a fresh batch. Replaces the entire previous
     * batch — partial-list regeneration is not a pattern any
     * reference implementation supports and would be confusing.
     *
     * Gated on password reauth + 2FA being currently enabled. The
     * user is not asked for a current OTP because they may already
     * be in the "lost device" path that prompted the regeneration in
     * the first place; the password reauth is the integrity gate.
     */
    private function process_regenerate_recovery_codes() {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            wp_send_json_error(['message' => __('Authentication required.', 'matrix-mlm')]);
        }

        if (Matrix_MLM_Rate_Limiter::throttle(
            '2fa_regen_recovery',
            Matrix_MLM_Rate_Limiter::key_for_request(),
            ['max_attempts' => 5, 'window_seconds' => 15 * MINUTE_IN_SECONDS]
        )) {
            wp_send_json_error([
                'message' => __('Too many attempts. Please wait a few minutes before trying again.', 'matrix-mlm'),
            ]);
        }

        // Reauth before probing 2FA state, same reasoning as
        // process_disable_2fa — don't make this endpoint a 2FA-state
        // oracle for an attacker who only has the session.
        $this->require_password_reauth($user_id);

        $two_factor = new Matrix_MLM_Two_Factor();
        if (!$two_factor->is_enabled($user_id)) {
            wp_send_json_error([
                'message' => __('Two-factor authentication is not enabled on this account.', 'matrix-mlm'),
            ]);
        }

        Matrix_MLM_Rate_Limiter::reset(
            '2fa_regen_recovery',
            Matrix_MLM_Rate_Limiter::key_for_request()
        );

        $codes = $two_factor->regenerate_recovery_codes($user_id);
        wp_send_json_success([
            'recovery_codes' => $codes,
            'message' => __('New recovery codes generated. Save them now — they are shown only once.', 'matrix-mlm'),
        ]);
    }

    /**
     * Set a transaction PIN for the current user.
     *
     * Threat-model ordering matches process_enable_2fa:
     *   1. Authentication required.
     *   2. Rate-limit (5 / 15 min) BEFORE any bcrypt or state probe.
     *      Without this a session-only attacker could spam the
     *      endpoint to drain password_verify cycles.
     *   3. Master-feature check. The admin can disable PIN setup
     *      site-wide via Settings → Security; defence-in-depth
     *      against UI drift (a stale dashboard page that still
     *      shows the PIN form after the admin disabled the feature
     *      shouldn't be able to set a PIN).
     *   4. Password reauth — proves the caller still has the
     *      password, not just a hijacked session cookie. Comes
     *      BEFORE the is_set() probe so the endpoint can't be
     *      used as an oracle to learn whether a hijacked account
     *      already has a PIN.
     *   5. Reject if a PIN is already set — caller must use the
     *      change_transaction_pin path, which gates the swap on
     *      the current PIN as well as the password.
     *   6. Hash + store. Reset the rate-limit counter on success
     *      so a real user who fat-fingered their password a few
     *      times isn't penalised on the good attempt that
     *      followed.
     *
     * POST shape:
     *   - current_password: existing WP password (re-auth gate)
     *   - pin            : 4–6 digits, normalised server-side
     */
    private function process_set_transaction_pin() {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            wp_send_json_error(['message' => __('Authentication required.', 'matrix-mlm')]);
        }

        if (Matrix_MLM_Rate_Limiter::throttle(
            'transaction_pin_set',
            Matrix_MLM_Rate_Limiter::key_for_request(),
            [
                'max_attempts'   => Matrix_MLM_Transaction_Pin::VERIFY_MAX_ATTEMPTS,
                'window_seconds' => Matrix_MLM_Transaction_Pin::VERIFY_WINDOW_SECONDS,
            ]
        )) {
            wp_send_json_error([
                'message' => __('Too many attempts. Please wait a few minutes before trying again.', 'matrix-mlm'),
            ]);
        }

        if (!Matrix_MLM_Transaction_Pin::is_master_enabled()) {
            wp_send_json_error([
                'message' => __('Transaction PIN is not enabled on this site.', 'matrix-mlm'),
            ]);
        }

        $this->require_password_reauth($user_id);

        if (Matrix_MLM_Transaction_Pin::is_set($user_id)) {
            wp_send_json_error([
                'message' => __('A transaction PIN is already set on this account. Use Change PIN to update it.', 'matrix-mlm'),
                'already_set' => true,
            ]);
        }

        $pin = isset($_POST['pin']) ? $_POST['pin'] : '';
        $result = Matrix_MLM_Transaction_Pin::set($user_id, $pin);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        Matrix_MLM_Rate_Limiter::reset(
            'transaction_pin_set',
            Matrix_MLM_Rate_Limiter::key_for_request()
        );

        wp_send_json_success([
            'message' => __('Transaction PIN set. You will be asked for it on fund-movement actions configured to require it.', 'matrix-mlm'),
        ]);
    }

    /**
     * Change an existing transaction PIN.
     *
     * Two gates: a fresh password reauth, and proof the caller
     * knows the current PIN. The second gate is what makes the
     * change endpoint safe to publish — without it, a hijacked
     * session could rotate the PIN to one the attacker controls
     * and then drain the wallet behind the new PIN gate.
     *
     * POST shape:
     *   - current_password: existing WP password (re-auth gate)
     *   - current_pin     : the PIN currently on file
     *   - new_pin         : 4–6 digits
     */
    private function process_change_transaction_pin() {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            wp_send_json_error(['message' => __('Authentication required.', 'matrix-mlm')]);
        }

        if (Matrix_MLM_Rate_Limiter::throttle(
            'transaction_pin_change',
            Matrix_MLM_Rate_Limiter::key_for_request(),
            [
                'max_attempts'   => Matrix_MLM_Transaction_Pin::VERIFY_MAX_ATTEMPTS,
                'window_seconds' => Matrix_MLM_Transaction_Pin::VERIFY_WINDOW_SECONDS,
            ]
        )) {
            wp_send_json_error([
                'message' => __('Too many attempts. Please wait a few minutes before trying again.', 'matrix-mlm'),
            ]);
        }

        if (!Matrix_MLM_Transaction_Pin::is_master_enabled()) {
            wp_send_json_error([
                'message' => __('Transaction PIN is not enabled on this site.', 'matrix-mlm'),
            ]);
        }

        $this->require_password_reauth($user_id);

        $current_pin = isset($_POST['current_pin']) ? $_POST['current_pin'] : '';
        $new_pin     = isset($_POST['new_pin'])     ? $_POST['new_pin']     : '';

        $result = Matrix_MLM_Transaction_Pin::change($user_id, $current_pin, $new_pin);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        Matrix_MLM_Rate_Limiter::reset(
            'transaction_pin_change',
            Matrix_MLM_Rate_Limiter::key_for_request()
        );

        wp_send_json_success([
            'message' => __('Transaction PIN updated.', 'matrix-mlm'),
        ]);
    }

    /**
     * Disable / clear an existing transaction PIN.
     *
     * Gated on password reauth + a current-PIN verify. Idempotent
     * on the no-PIN-set path (returns success rather than an error
     * so a quick double-click doesn't surface a confusing message
     * after the underlying state already flipped).
     *
     * Note: disabling a PIN does NOT bypass any per-path
     * "Require PIN" admin toggles. With no PIN set the gate
     * helper short-circuits to "allowed" — disabling moves the
     * user from the gated state back to the ungated state.
     * Admins who want to mandate a PIN site-wide can ship a
     * follow-up that turns the user-side disable button off
     * conditionally; that's deliberately out of scope here so
     * users always have a self-service path back to a known-good
     * state if they fat-finger a PIN they later forget.
     *
     * POST shape:
     *   - current_password: existing WP password (re-auth gate)
     *   - current_pin     : the PIN currently on file
     */
    private function process_disable_transaction_pin() {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            wp_send_json_error(['message' => __('Authentication required.', 'matrix-mlm')]);
        }

        if (Matrix_MLM_Rate_Limiter::throttle(
            'transaction_pin_disable',
            Matrix_MLM_Rate_Limiter::key_for_request(),
            [
                'max_attempts'   => Matrix_MLM_Transaction_Pin::VERIFY_MAX_ATTEMPTS,
                'window_seconds' => Matrix_MLM_Transaction_Pin::VERIFY_WINDOW_SECONDS,
            ]
        )) {
            wp_send_json_error([
                'message' => __('Too many attempts. Please wait a few minutes before trying again.', 'matrix-mlm'),
            ]);
        }

        // Reauth before the is_set() probe — same reasoning as
        // process_disable_2fa. Don't let this endpoint be a
        // PIN-state oracle for a session-only attacker.
        $this->require_password_reauth($user_id);

        if (!Matrix_MLM_Transaction_Pin::is_set($user_id)) {
            // Idempotent — disable on an already-disabled account
            // is a no-op success rather than an error so the UI
            // doesn't surface a confusing message after a quick
            // double-click. Reset the throttle on this path too:
            // a successful password reauth has happened.
            Matrix_MLM_Rate_Limiter::reset(
                'transaction_pin_disable',
                Matrix_MLM_Rate_Limiter::key_for_request()
            );
            wp_send_json_success([
                'message' => __('Transaction PIN is already disabled.', 'matrix-mlm'),
            ]);
        }

        $current_pin = isset($_POST['current_pin']) ? $_POST['current_pin'] : '';

        $result = Matrix_MLM_Transaction_Pin::disable($user_id, $current_pin);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        Matrix_MLM_Rate_Limiter::reset(
            'transaction_pin_disable',
            Matrix_MLM_Rate_Limiter::key_for_request()
        );

        wp_send_json_success([
            'message' => __('Transaction PIN disabled.', 'matrix-mlm'),
        ]);
    }

    /**
     * Self-service "Forgot PIN" recovery (PIN-hardening item #17).
     *
     * Distinct from process_disable_transaction_pin: that one
     * requires the user to remember the current PIN. The forgot
     * flow is what saves a user who has, by definition, forgotten
     * it. Same threat-model ordering as the other PIN handlers:
     *
     *   rate-limit  →  master-feature probe  →  password reauth  →
     *   wipe hash + lockout + counter  →  reset throttle  →  done
     *
     * The password reauth is the integrity gate — if the user has
     * lost both their PIN AND their password, the account-recovery
     * path is the standard WordPress lost-password flow, NOT this.
     *
     * Audit + notification are handled inside Matrix_MLM_Transaction_Pin::forgot
     * (the user is emailed, the event lands in the error log).
     *
     * POST shape:
     *   - current_password : existing WP password (re-auth gate)
     */
    private function process_forgot_transaction_pin() {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            wp_send_json_error(['message' => __('Authentication required.', 'matrix-mlm')]);
        }

        if (Matrix_MLM_Rate_Limiter::throttle(
            'transaction_pin_forgot',
            Matrix_MLM_Rate_Limiter::key_for_request(),
            [
                'max_attempts'   => Matrix_MLM_Transaction_Pin::VERIFY_MAX_ATTEMPTS,
                'window_seconds' => Matrix_MLM_Transaction_Pin::VERIFY_WINDOW_SECONDS,
            ]
        )) {
            wp_send_json_error([
                'message' => __('Too many attempts. Please wait a few minutes before trying again.', 'matrix-mlm'),
            ]);
        }

        if (!Matrix_MLM_Transaction_Pin::is_master_enabled()) {
            wp_send_json_error([
                'message' => __('Transaction PIN is not enabled on this site.', 'matrix-mlm'),
            ]);
        }

        $this->require_password_reauth($user_id);

        $result = Matrix_MLM_Transaction_Pin::forgot($user_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        Matrix_MLM_Rate_Limiter::reset(
            'transaction_pin_forgot',
            Matrix_MLM_Rate_Limiter::key_for_request()
        );

        wp_send_json_success([
            'message' => __('Transaction PIN cleared. You can set a new PIN from your Security tab.', 'matrix-mlm'),
        ]);
    }

    /**
     * Verify the current user's password against a `current_password`
     * POST field, or terminate the AJAX response with an error.
     *
     * Shared gate for sensitive self-service flows (2FA enrol /
     * disable / recovery-code regeneration). Deliberately a hard
     * wp_send_json_error so callers can rely on it as a guard
     * statement, the same way require_admin() works in the import
     * module.
     *
     * Uses wp_check_password rather than wp_authenticate so we don't
     * accidentally trigger any of the wp_authenticate filters that
     * could log a "failed login" (these flows aren't logins; the
     * user is already authenticated and we're just reverifying
     * their possession of the password).
     */
    private function require_password_reauth($user_id) {
        $current_password = isset($_POST['current_password']) ? (string) $_POST['current_password'] : '';
        if ($current_password === '') {
            wp_send_json_error([
                'message' => __('Enter your current password to continue.', 'matrix-mlm'),
                'reauth_required' => true,
            ]);
        }
        $user = get_userdata($user_id);
        if (!$user) {
            wp_send_json_error(['message' => __('Authentication required.', 'matrix-mlm')]);
        }
        if (!wp_check_password($current_password, $user->user_pass, $user_id)) {
            wp_send_json_error([
                'message' => __('Password is incorrect.', 'matrix-mlm'),
                'reauth_required' => true,
            ]);
        }
    }

    private function process_pay_subscription() {
        $user_id = get_current_user_id();

        // Transaction PIN gate (PR 2). The manual subscription-pay
        // surface debits the Matrix wallet without a separate
        // can_move_funds preflight (the manual_pay() helper does
        // its own balance + status checks under a per-user-month
        // advisory lock). The PIN gate fires here, BEFORE
        // manual_pay() acquires the lock or touches the wallet,
        // so a wrong PIN can't dirty the subscription-payment
        // history or contend with the cron-driven monthly job.
        // Path key 'subscription' maps to the
        // matrix_mlm_pin_required_for_subscription admin toggle.
        Matrix_MLM_Transaction_Pin::require_pin_for_request($user_id, 'subscription');

        $subscription = new Matrix_MLM_Subscription();
        $result = $subscription->manual_pay($user_id);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    private function process_subscribe() {
        $email = sanitize_email($_POST['email'] ?? '');
        if (!is_email($email)) {
            wp_send_json_error(['message' => __('Invalid email address', 'matrix-mlm')]);
        }

        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}matrix_subscribers WHERE email = %s",
            $email
        ));

        // Audit L1: uniform response regardless of pre-existence so
        // the endpoint stops being a subscriber-list enumeration
        // oracle. The two pre-existing branches ('already subscribed'
        // vs 'subscribed successfully') let an unauthenticated caller
        // probe whether any specific email had ever subscribed —
        // useful for spear-phishing target lists, especially because
        // the endpoint has no rate limit beyond the registration one.
        // We still skip the insert when the row already exists (no
        // double-counting in the subscribers table) but the response
        // shape is identical either way.
        if (!$exists) {
            $wpdb->insert($wpdb->prefix . 'matrix_subscribers', ['email' => $email]);
        }
        wp_send_json_success([
            'message' => __('Thanks! If this address isn\'t already on the list, it\'s now subscribed.', 'matrix-mlm'),
        ]);
    }

    public function register_rest_routes() {
        // Webhook callback (server-to-server, signed).
        //
        // permission_callback runs BEFORE the handler. The handler
        // (Matrix_MLM_Paystack::handle_webhook / Matrix_MLM_Flutterwave::
        // handle_webhook) is the authority on the signature itself —
        // it does the constant-time hash_equals check and gates the
        // deposit credit on a successful match. This permission_callback
        // is defense-in-depth on the route shape: refuse the request at
        // the framework boundary if the signature header is structurally
        // missing, so a future refactor that accidentally weakens the
        // handler check still has a second line of defense at the route
        // layer. (Audit H18: webhook routes used permission_callback =>
        // '__return_true' so the per-gateway handler was the SOLE gate.)
        register_rest_route('matrix-mlm/v1', '/payment/callback/(?P<gateway>[a-z]+)', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_payment_callback'],
            'permission_callback' => [$this, 'check_payment_callback_permission'],
        ]);

        // Zebra Wallet IPN with a per-install token segment.
        //
        // Bibimoney's IPN does not carry a signature header (spec
        // section 5: "Your endpoint must return HTTP 200 + body
        // 'accepted'", no auth scheme described). To compensate
        // we let the operator generate a long random webhook_token
        // in Admin -> Gateways -> Zebra Wallet, bake it into the
        // IPN URL, and register that exact URL with developers@
        // bibimoney.com as the destination. An attacker who
        // doesn't know the token can't post to a guessable URL.
        // The handler still re-validates VendorReference /
        // PSPreference / amount / currency against our own deposit
        // row before crediting, so even if the token leaks the
        // worst-case is "platform retries an already-completed
        // deposit" rather than "attacker credits arbitrary wallets".
        //
        // The (?P<token>...) regex is permissive on the token
        // shape so future operator rotations don't have to match
        // any particular character class — check_payment_callback_permission
        // does the actual constant-time match against the stored
        // value.
        register_rest_route('matrix-mlm/v1', '/payment/callback/(?P<gateway>zebra)/(?P<token>[A-Za-z0-9_\-]+)', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_payment_callback'],
            'permission_callback' => [$this, 'check_payment_callback_permission'],
        ]);

        // User-facing verify endpoint.
        //
        // This is the URL the gateway redirects the user's browser to
        // AFTER they complete (or abandon) checkout. There's no signature
        // — it's a GET hit by the user, with a reference query param.
        // The handler then makes an authenticated server-to-server call
        // to the gateway's /verify endpoint to confirm payment status,
        // and only credits on a positive verification. No way to forge
        // a credit through this endpoint without first compromising the
        // gateway's API key, which would give the attacker much more
        // direct routes to fraud than this one. Keeping it open
        // intentionally; documenting the rationale so a future reviewer
        // doesn't re-flag it.
        register_rest_route('matrix-mlm/v1', '/payment/verify/(?P<gateway>[a-z]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'handle_payment_verify'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Webhook permission pre-check — verifies the signature header is
     * structurally present BEFORE we hand off to the gateway handler.
     *
     * Returns WP_Error (which REST renders as 401) if the header is
     * missing for the gateway type, or true to let the handler proceed.
     * The handler then performs the actual constant-time hash_equals
     * comparison against the configured secret. This two-stage gate
     * means a misconfigured route, a future handler refactor, or a
     * silent change to the underlying signature-verification code can't
     * accidentally make the route accept anonymous webhooks.
     *
     * Unknown gateway names fall through to the handler's existing 400
     * branch — it's the right place to surface "unsupported gateway",
     * not 401 "forbidden", since the failure mode is configuration
     * rather than auth.
     */
    public function check_payment_callback_permission($request) {
        $gateway = (string) $request->get_param('gateway');
        switch ($gateway) {
            case 'paystack':
                $signature = $request->get_header('x-paystack-signature');
                break;
            case 'flutterwave':
                $signature = $request->get_header('verif-hash');
                break;
            case 'zebra':
                // Bibimoney's IPN has no signature header. The
                // route's {token} segment IS the auth credential.
                // Constant-time compare it against the configured
                // webhook_token; reject any request whose token
                // doesn't match. This is the same defense pattern
                // the (header-based) Paystack/Flutterwave checks
                // use, just bound to the URL path because that's
                // where Bibimoney can authenticate us. Empty
                // configured token -> refuse all calls (the
                // operator hasn't finished setup).
                $supplied   = (string) $request->get_param('token');
                $zebra      = new Matrix_MLM_Zebra();
                $configured = (string) $zebra->get_webhook_token();
                if ($configured === '' || $supplied === '' || !hash_equals($configured, $supplied)) {
                    return new WP_Error(
                        'rest_forbidden',
                        __('Webhook token is missing or invalid.', 'matrix-mlm'),
                        ['status' => 401]
                    );
                }
                return true;
            default:
                return true;
        }
        if (!is_string($signature) || $signature === '') {
            return new WP_Error(
                'rest_forbidden',
                __('Webhook signature header is missing.', 'matrix-mlm'),
                ['status' => 401]
            );
        }
        return true;
    }

    public function handle_payment_callback($request) {
        $gateway = $request->get_param('gateway');

        if ($gateway === 'paystack') {
            $paystack = new Matrix_MLM_Paystack();
            return $paystack->handle_webhook($request);
        } elseif ($gateway === 'flutterwave') {
            $flutterwave = new Matrix_MLM_Flutterwave();
            return $flutterwave->handle_webhook($request);
        } elseif ($gateway === 'zebra') {
            $zebra = new Matrix_MLM_Zebra();
            return $zebra->handle_webhook($request);
        }

        return new WP_REST_Response(['status' => 'error'], 400);
    }

    public function handle_payment_verify($request) {
        $gateway = $request->get_param('gateway');
        $reference = $request->get_param('reference') ?? $request->get_param('tx_ref') ?? '';

        if (empty($reference)) {
            return new WP_REST_Response(['status' => 'error', 'message' => 'Reference is required'], 400);
        }

        // Audit L10 — per-IP rate limit on the unauthenticated
        // verify route.
        //
        // The route is intentionally permission_callback => true:
        // it's the user-return surface after a redirect from
        // Paystack / Flutterwave / Zebra and there's no way to
        // authenticate the bouncing browser at the framework
        // boundary. Forging a credit through this endpoint is not
        // possible without first compromising the gateway's API
        // key (verify_payment() calls the gateway server-to-server
        // and the gateway is the source of truth), but an attacker
        // who knows the route can still abuse it for:
        //
        //   - Upstream-quota exhaustion: every call hits the
        //     gateway's verify endpoint, which is rate-limited
        //     server-side. Burning through the merchant's quota
        //     starves legitimate user-return flows.
        //   - Reference enumeration: probing for valid tx_refs
        //     to learn deposit IDs / amounts via the response
        //     shape.
        //
        // Cap inputs through the same per-action / per-IP
        // limiter the AJAX handlers use. 60 attempts per 15
        // minutes per IP is generous enough to never bother a
        // real user retrying their post-redirect verify a few
        // times, and far below the rate that would meaningfully
        // burn upstream quota or enable enumeration. Both numbers
        // are filterable per-action via matrix_mlm_rate_limit_max
        // / matrix_mlm_rate_limit_window so installs behind a
        // shared NAT can tune up.
        $rate_key = 'ip:' . sha1(Matrix_MLM_Rate_Limiter::client_ip());
        if (Matrix_MLM_Rate_Limiter::throttle('payment_verify', $rate_key, [
            'max_attempts'   => 60,
            'window_seconds' => 15 * MINUTE_IN_SECONDS,
        ])) {
            return new WP_REST_Response(
                ['status' => 'error', 'message' => 'Too many verification attempts. Please wait a few minutes and try again.'],
                429
            );
        }

        if ($gateway === 'paystack') {
            $paystack = new Matrix_MLM_Paystack();
            return $paystack->verify_payment($reference);
        } elseif ($gateway === 'flutterwave') {
            $flutterwave = new Matrix_MLM_Flutterwave();
            return $flutterwave->verify_payment($reference);
        } elseif ($gateway === 'zebra') {
            // Zebra has no redirect-and-return flow, so this
            // endpoint is a polling helper rather than a primary
            // completion path. The verify_payment() implementation
            // reads the local deposit row's status (which the IPN
            // will have updated) rather than calling the platform,
            // because Bibimoney does not expose a transaction
            // lookup endpoint in the spec we have.
            $zebra = new Matrix_MLM_Zebra();
            return $zebra->verify_payment($reference);
        }

        return new WP_REST_Response(['status' => 'error', 'message' => 'Unsupported gateway'], 400);
    }

    /**
     * Handle email verification link
     */
    public function handle_email_verification() {
        if (!isset($_GET['action']) || $_GET['action'] !== 'matrix_verify_email') {
            return;
        }

        $token = sanitize_text_field($_GET['token'] ?? '');
        $user_id = intval($_GET['user'] ?? 0);

        if (empty($token) || !$user_id) {
            wp_die(__('Invalid verification link.', 'matrix-mlm'));
        }

        $stored_token = get_user_meta($user_id, 'matrix_email_verify_token', true);
        $expiry = get_user_meta($user_id, 'matrix_email_verify_expiry', true);

        // Constant-time comparison. Every other security-sensitive
        // string compare in this plugin (webhook signatures, TOTP,
        // attachment HMAC, Zebra IPN URL token) uses hash_equals();
        // the email-verify path was the lone outlier on plain !==,
        // which leaks via timing on a long-lived per-user token.
        // The is_string guard keeps hash_equals() from emitting a
        // type warning when get_user_meta() returns '' for a user
        // who has no pending verification — empty($token) above
        // already short-circuits the corresponding empty-input case.
        if (!is_string($stored_token) || !hash_equals($stored_token, $token)) {
            wp_die(__('Invalid or expired verification link.', 'matrix-mlm'));
        }

        if ($expiry && time() > $expiry) {
            wp_die(__('This verification link has expired. Please request a new one.', 'matrix-mlm'));
        }

        // Mark email as verified
        update_user_meta($user_id, 'matrix_email_verified', true);
        delete_user_meta($user_id, 'matrix_email_verify_token');
        delete_user_meta($user_id, 'matrix_email_verify_expiry');
        // Clear the verify-pending gate set during registration so the
        // login path stops blocking this user. (audit H13)
        delete_user_meta($user_id, 'matrix_email_verify_pending');

        // Send welcome email
        Matrix_MLM_Notifications::send_welcome_email($user_id);

        // Redirect to login with success message
        $redirect_url = add_query_arg('verified', '1', home_url('/matrix-login'));
        wp_redirect($redirect_url);
        exit;
    }

    public function add_rewrite_rules() {
        add_rewrite_rule('^matrix-dashboard/([^/]+)/?$', 'index.php?pagename=matrix-dashboard&matrix_tab=$matches[1]', 'top');

        // Self-heal: if the persisted rewrite_rules cache predates this rule
        // (e.g. the plugin was upgraded without re-activation, or a third
        // party plugin called flush_rewrite_rules() between our register
        // and persist), add_rewrite_rule() alone won't make the route
        // resolvable — WP would 404 the pretty /matrix-dashboard/{tab}/
        // URLs that Matrix_MLM_User_Dashboard::tab_url() now emits. Detect
        // that state and trigger one flush, then mark a flag so we don't
        // flush on every page load forever after.
        //
        // Skipped on Plain-permalink installs (empty permalink_structure)
        // because rewrite rules are inert in that mode; tab_url() falls
        // back to ?tab=X on those sites and the flush would be a no-op.
        if ((string) get_option('permalink_structure', '') !== '' &&
            (int) get_option('matrix_mlm_rewrite_rules_v2_flushed', 0) !== 1) {
            $persisted = get_option('rewrite_rules');
            if (!is_array($persisted) || !isset($persisted['^matrix-dashboard/([^/]+)/?$'])) {
                flush_rewrite_rules(false);
            }
            update_option('matrix_mlm_rewrite_rules_v2_flushed', 1, false);
        }
    }

    public function add_query_vars($vars) {
        $vars[] = 'matrix_tab';
        return $vars;
    }

    public function daily_cron() {
        // Expire only pins that have a real expiry date that has passed.
        // Previously this also unconditionally flipped every 'unused' pin
        // to 'expired', which silently destroyed valid pins overnight.
        global $wpdb;
        $wpdb->query(
            "UPDATE {$wpdb->prefix}matrix_epins
                SET status = 'expired'
              WHERE status = 'unused'
                AND expires_at IS NOT NULL
                AND expires_at < NOW()"
        );
    }

    /**
     * Render the public-side livechat surfaces in wp_footer.
     *
     * Two independent surfaces, both gated by the master
     * matrix_mlm_livechat_enabled toggle:
     *
     *   1. Custom embed code (matrix_mlm_livechat_code) — printed
     *      verbatim as raw HTML so a third-party <script> tag from
     *      Tawk.to / Crisp / LiveChat / Intercom / etc. executes.
     *      No escaping at output time because the operator's intent
     *      with this field is "execute this snippet"; the field is
     *      already gated to admin-with-manage-options at save time
     *      via the standard WP settings nonce + capability check.
     *
     *   2. WhatsApp click-to-chat button (matrix_mlm_whatsapp_enabled)
     *      — emits a self-contained floating button that opens
     *      https://wa.me/<digits>?text=<urlencoded message> in a
     *      new tab. wa.me opens the WhatsApp app on mobile and
     *      web.whatsapp.com on desktop, so a single href works for
     *      both. The number is digit-stripped at save time so the
     *      URL is always valid — wa.me rejects anything other than
     *      bare digits.
     *
     * Both surfaces are skipped on wp-admin and on the wp-login
     * page since they exist for end-user support, not for the
     * authenticated admin who is configuring them.
     *
     * Output is also skipped entirely when nothing is configured —
     * no empty <div> wrapper, no comment marker — so a default
     * install with no livechat configured produces no footer
     * pollution.
     */
    public function render_livechat_surfaces() {
        if (is_admin()) {
            return;
        }
        if (!(int) get_option('matrix_mlm_livechat_enabled', 0)) {
            return;
        }

        // Surface 1: custom embed code. Operator-supplied HTML/JS,
        // printed raw — see method docblock.
        $code = (string) get_option('matrix_mlm_livechat_code', '');
        if (trim($code) !== '') {
            echo $code; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        // Surface 2: WhatsApp button.
        if ((int) get_option('matrix_mlm_whatsapp_enabled', 0)) {
            $number  = preg_replace('/\D+/', '', (string) get_option('matrix_mlm_whatsapp_number', ''));
            $message = (string) get_option('matrix_mlm_whatsapp_message', '');
            if ($number !== '') {
                $url = 'https://wa.me/' . $number;
                if ($message !== '') {
                    $url .= '?text=' . rawurlencode($message);
                }
                $aria = esc_attr__('Chat with us on WhatsApp', 'matrix-mlm');
                ?>
                <a href="<?php echo esc_url($url); ?>"
                   class="matrix-whatsapp-btn"
                   target="_blank"
                   rel="noopener noreferrer"
                   aria-label="<?php echo $aria; ?>"
                   title="<?php echo $aria; ?>">
                    <svg viewBox="0 0 32 32" width="28" height="28" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
                        <path fill="#fff" d="M16 .5C7.44.5.5 7.44.5 16c0 2.83.74 5.49 2.04 7.79L.5 31.5l7.93-2.07A15.43 15.43 0 0 0 16 31.5c8.56 0 15.5-6.94 15.5-15.5S24.56.5 16 .5zm0 28a12.46 12.46 0 0 1-6.36-1.74l-.45-.27-4.71 1.23 1.26-4.59-.29-.47A12.46 12.46 0 1 1 16 28.5zm7.13-9.36c-.39-.2-2.32-1.14-2.68-1.27-.36-.13-.62-.2-.88.2-.26.39-1 1.27-1.23 1.53-.23.26-.45.29-.84.1-.39-.2-1.66-.61-3.16-1.95a11.86 11.86 0 0 1-2.18-2.71c-.23-.39-.02-.6.17-.79.18-.18.39-.45.59-.68.2-.23.26-.39.39-.65.13-.26.07-.49-.03-.68-.1-.2-.88-2.13-1.21-2.91-.32-.78-.65-.67-.88-.68l-.75-.01a1.44 1.44 0 0 0-1.05.49c-.36.39-1.39 1.36-1.39 3.31s1.42 3.84 1.62 4.1c.2.26 2.79 4.27 6.77 5.99 2.36 1.02 3.28 1.11 4.46.93.71-.11 2.32-.95 2.65-1.86.33-.91.33-1.69.23-1.86-.1-.16-.36-.26-.75-.46z"/>
                    </svg>
                </a>
                <style>
                    .matrix-whatsapp-btn {
                        position: fixed;
                        bottom: 24px;
                        right: 24px;
                        z-index: 99998; /* one below the level-toast stack (99999) */
                        display: inline-flex;
                        align-items: center;
                        justify-content: center;
                        width: 56px;
                        height: 56px;
                        border-radius: 50%;
                        background: #25d366;
                        box-shadow: 0 8px 20px -4px rgba(37, 211, 102, 0.45);
                        text-decoration: none;
                        transition: transform .18s ease, box-shadow .18s ease;
                    }
                    .matrix-whatsapp-btn:hover,
                    .matrix-whatsapp-btn:focus {
                        background: #1ebe5b;
                        transform: scale(1.06);
                        box-shadow: 0 12px 26px -4px rgba(37, 211, 102, 0.55);
                    }
                    .matrix-whatsapp-btn:focus { outline: 2px solid #128c7e; outline-offset: 3px; }
                    @media (max-width: 600px) {
                        .matrix-whatsapp-btn { bottom: 16px; right: 16px; width: 52px; height: 52px; }
                    }
                </style>
                <?php
            }
        }
    }
}
