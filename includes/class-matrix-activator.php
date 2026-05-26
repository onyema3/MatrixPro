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

        // Create required pages
        self::create_pages();

        // Slug-migration for installs that already have the legacy
        // /matrix-register/ page. The page slug rename happened in
        // 2026 (when the public referral URL changed from
        // /matrix-register/?ref=X to /matrix/?ref=X). Migrate the
        // existing page in place — same post ID, same content, new
        // slug — so historical bookmarks of the post (and any DB
        // references by post ID) survive untouched, and so wp_posts
        // doesn't accumulate two parallel registration pages.
        self::migrate_register_page_slug();

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
            'matrix-login' => [
                'title' => 'Login',
                'content' => '[matrix_login]'
            ],
            'matrix' => [
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
     * Rename the registration page slug from `matrix-register` to
     * `matrix` on installs that pre-date the rename.
     *
     * Why an in-place slug rename rather than create-new + delete-old:
     *   - Same post ID survives. Anything in the database that refers
     *     to the page by ID (a saved menu item, a shortcode embed,
     *     an Elementor widget, a custom widget, a redirect-plugin
     *     rule keyed off post_id, etc.) keeps pointing at the right
     *     page.
     *   - No risk of dropping content the operator may have customised
     *     on the existing page (added a hero image, written a longer
     *     intro paragraph, embedded a video). create_pages() above
     *     only inserts when the slug is missing, so for pre-rename
     *     installs that branch is a no-op and any prior customisation
     *     stays on the old slug — this method moves that same content
     *     onto the new slug.
     *
     * Why gated on (old exists) AND (new does NOT exist):
     *   - If only the new slug exists: nothing to migrate. Common case
     *     on a fresh install where create_pages() just minted /matrix/
     *     directly.
     *   - If only the old slug exists: migrate. The pre-rename case.
     *   - If BOTH exist: don't touch either. Could happen if an admin
     *     hand-created a /matrix/ page for unrelated reasons before
     *     the rename. Renaming the legacy /matrix-register/ on top of
     *     that would either fail (slug collision) or, worse, succeed
     *     by silently appending a numeric suffix (-2) and produce a
     *     /matrix-2/ page that nothing links to. Skipping is the
     *     correct behaviour — the operator can resolve the conflict
     *     manually.
     *   - If neither exists: nothing to do.
     *
     * The legacy /matrix-register/ URL is preserved as a 301 redirect
     * to /matrix/ via Matrix_MLM_Core::redirect_legacy_register_url(),
     * so all referral links members already shared on social media,
     * via email, on flyers etc. continue to credit correctly. See
     * that method for the redirect logic.
     */
    private static function migrate_register_page_slug() {
        $old = get_page_by_path('matrix-register', OBJECT, 'page');
        $new = get_page_by_path('matrix', OBJECT, 'page');

        if ($old && !$new) {
            wp_update_post([
                'ID'        => $old->ID,
                'post_name' => 'matrix',
            ]);
        }
    }
}
