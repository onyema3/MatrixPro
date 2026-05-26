<?php
/**
 * Plugin Activator
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Activator {

    public static function activate() {
        // Create database tables
        Matrix_MLM_Database::create_tables();

        // Create Fintava payout table
        Matrix_MLM_Fintava::create_table();

        // Create Fintava card table
        Matrix_MLM_Fintava_Card::create_table();

        // Create billing transactions table
        Matrix_MLM_Fintava_Billing::create_table();

        // Create subscriptions table
        Matrix_MLM_Subscription::create_table();

        // Seed default data
        Matrix_MLM_Database::seed_defaults();

        // Migrate auth-page slugs FIRST so /matrix/ is vacated by
        // any pre-existing registration page before we seed the new
        // login page into that slot. See migrate_auth_page_slugs()
        // for the full state-machine that handles every install
        // shape we've seen across PR #339 and earlier.
        self::migrate_auth_page_slugs();

        // Create required pages
        self::create_pages();

        // Set capabilities
        self::set_capabilities();

        // Provision the backup directory now so the first manual or
        // scheduled run doesn't have to do it lazily under load, and
        // re-reconcile the weekly cron against the saved option in
        // case the plugin was deactivated/reactivated between runs.
        if (class_exists('Matrix_MLM_Admin_Backup')) {
            Matrix_MLM_Admin_Backup::get_backup_dir();
            Matrix_MLM_Admin_Backup::maybe_schedule_cron();
        }

        // Flush rewrite rules
        flush_rewrite_rules();

        // Set activation flag
        update_option('matrix_mlm_activated', true);
        update_option('matrix_mlm_activation_time', current_time('mysql'));
    }

    private static function create_pages() {
        $pages = [
            'matrix-dashboard' => [
                'title' => 'Matrix Dashboard',
                'content' => '[matrix_dashboard]'
            ],
            'matrix' => [
                'title' => 'Login',
                'content' => '[matrix_login]'
            ],
            'signup' => [
                'title' => 'Register',
                'content' => '[matrix_register]'
            ],
            'matrix-plans' => [
                'title' => 'Plans',
                'content' => '[matrix_plans]'
            ],
        ];

        foreach ($pages as $slug => $page) {
            $existing = get_page_by_path($slug);
            if (!$existing) {
                wp_insert_post([
                    'post_title' => $page['title'],
                    'post_content' => $page['content'],
                    'post_status' => 'publish',
                    'post_type' => 'page',
                    'post_name' => $slug,
                ]);
            }
        }
    }

    private static function set_capabilities() {
        $admin_role = get_role('administrator');
        if ($admin_role) {
            $admin_role->add_cap('manage_matrix_mlm');
            $admin_role->add_cap('manage_matrix_plans');
            $admin_role->add_cap('manage_matrix_users');
            $admin_role->add_cap('manage_matrix_deposits');
            $admin_role->add_cap('manage_matrix_withdrawals');
            $admin_role->add_cap('manage_matrix_tickets');
            $admin_role->add_cap('manage_matrix_settings');
        }
    }

    /**
     * Move existing auth pages onto their new slugs.
     *
     * The plugin's URL layout has shifted twice in close succession:
     *
     *   - Original (pre-PR #339):
     *       /matrix-login/    -> [matrix_login]
     *       /matrix-register/ -> [matrix_register]
     *
     *   - Post-PR #339 (2026, ~24h window):
     *       /matrix-login/    -> [matrix_login]
     *       /matrix/          -> [matrix_register]   (was /matrix-register/)
     *
     *   - Current (this rename):
     *       /matrix/          -> [matrix_login]      (was /matrix-login/)
     *       /signup/          -> [matrix_register]   (was /matrix/)
     *
     * Operator request: shorten the login URL to /matrix/ (the
     * branded slug) and move registration to its own /signup/ slug.
     * /matrix/ holding the login form is the high-traffic surface
     * existing members hit every day; /signup/ is reached by
     * prospects via referral links and from the "Don't have an
     * account?" footer link on the login page.
     *
     * In-place wp_update_post(post_name=…) renames rather than
     * create-new + delete-old:
     *
     *   - Same post ID survives. Anything in the database that
     *     references the page by ID (saved menu items, embedded
     *     shortcodes, page-builder widgets, redirect-plugin rules
     *     keyed off post_id, custom analytics tags) keeps working
     *     without a manual fix-up pass.
     *
     *   - Operator customisations to the page (custom hero image,
     *     extended intro paragraph, embedded video, layout tweaks
     *     in Elementor / Beaver Builder / etc.) are preserved.
     *     create_pages() above only inserts when the slug is
     *     missing, so on installs with prior customisations that
     *     branch is a no-op and the operator's edits live on the
     *     old slug — this method carries them forward to the new
     *     slug.
     *
     * Order matters
     * -------------
     *
     * /matrix/ has to be VACATED (registration moved out to /signup/)
     * BEFORE we move the login page INTO /matrix/. If we did it the
     * other way round, wp_update_post() would either fail with a
     * slug collision or, worse, succeed by silently appending a
     * numeric suffix and produce a /matrix-2/ page that nothing
     * links to.
     *
     * Step 1 — vacate /matrix/
     *   1a. If `/matrix-register/` exists (pre-PR #339 install),
     *       rename straight to `/signup/`. Skips the intermediate
     *       /matrix/ slug entirely so installs that never picked up
     *       PR #339 don't have to bounce through it.
     *   1b. Else if `/matrix/` exists AND its content holds the
     *       [matrix_register] shortcode (post-PR #339 install),
     *       rename to `/signup/`. The content sniff is what
     *       distinguishes an install that already ran this
     *       migration (where /matrix/ now holds [matrix_login])
     *       from one that hasn't — without it, a re-run would
     *       move the freshly-migrated login page back out to
     *       /signup/ and undo its own work.
     *   1c. Both gates check `/signup/` doesn't already exist, so
     *       a re-run is a safe no-op.
     *
     * Step 2 — move login into /matrix/
     *   2a. If `/matrix-login/` exists AND `/matrix/` does NOT,
     *       rename `matrix-login` → `matrix`. After Step 1 the
     *       /matrix/ slot is empty on every pre-existing install,
     *       so this branch fires for them. On already-migrated
     *       installs /matrix/ still holds the login page from a
     *       prior run, so the gate skips and nothing happens.
     *
     * Same gating rationale as the original PR #339 migration:
     *   - Source exists AND target does NOT exist → migrate.
     *   - Both exist → leave both alone (the operator can resolve
     *     by hand; we don't want to clobber a hand-created page).
     *   - Neither exists → nothing to do; create_pages() will seed
     *     fresh ones a moment later.
     *
     * Legacy URLs (/matrix-login/, /matrix-register/, and the
     * /matrix/?ref=CODE referral links members shared during the
     * 24h that PR #339's layout was live) are preserved as 301
     * redirects via Matrix_MLM_Core::redirect_legacy_auth_urls()
     * so every link members shared on social media, in WhatsApp
     * groups, and in promo emails keeps converting.
     */
    /**
     * Public wrapper around the auth-page slug migration so
     * Matrix_MLM_Core::maybe_self_heal_auth_pages() can re-use the
     * same state-machine on every page load (not just on plugin
     * activation). Idempotent — safe to call repeatedly.
     */
    public static function self_heal_auth_pages() {
        self::migrate_auth_page_slugs();

        // Backfill: any slug still missing after migration gets a
        // fresh insert with canonical content. Mirrors create_pages
        // exactly for the two auth slugs.
        if (!get_page_by_path('matrix', OBJECT, 'page')) {
            wp_insert_post([
                'post_title'   => 'Login',
                'post_content' => '[matrix_login]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => 'matrix',
            ]);
        }
        if (!get_page_by_path('signup', OBJECT, 'page')) {
            wp_insert_post([
                'post_title'   => 'Register',
                'post_content' => '[matrix_register]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => 'signup',
            ]);
        }
    }

    private static function migrate_auth_page_slugs() {
        $signup = get_page_by_path('signup', OBJECT, 'page');

        // Step 1a — pre-PR #339 install: /matrix-register/ → /signup/.
        if (!$signup) {
            $pre339 = get_page_by_path('matrix-register', OBJECT, 'page');
            if ($pre339) {
                wp_update_post([
                    'ID'        => $pre339->ID,
                    'post_name' => 'signup',
                ]);
                $signup = get_post($pre339->ID);
            }
        }

        // Step 1b — post-PR #339 install: /matrix/ holds register
        // content → rename to /signup/. Content sniff distinguishes
        // the registration page from a login page that's already
        // been migrated into /matrix/ on a prior run.
        if (!$signup) {
            $matrix = get_page_by_path('matrix', OBJECT, 'page');
            if ($matrix && strpos((string) $matrix->post_content, '[matrix_register]') !== false) {
                wp_update_post([
                    'ID'        => $matrix->ID,
                    'post_name' => 'signup',
                ]);
            }
        }

        // Step 2 — move login page into the now-vacated /matrix/.
        $matrix_after = get_page_by_path('matrix', OBJECT, 'page');
        if (!$matrix_after) {
            $login = get_page_by_path('matrix-login', OBJECT, 'page');
            if ($login) {
                wp_update_post([
                    'ID'        => $login->ID,
                    'post_name' => 'matrix',
                ]);
            }
        }
    }
}
