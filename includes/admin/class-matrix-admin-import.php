<?php
/**
 * Laravel → MatrixPro importer.
 *
 * Migrates an existing ViserMLM-family Laravel install into MatrixPro.
 * Designed for the schema dumped from libertym_maindb (users, plans,
 * levels, card_holders, cards, deposits, withdrawals, commissions,
 * pins, support_tickets, support_messages, subscribers).
 *
 * PR 1 scope (this file): upload + stage + dry-run + commit of
 *   - plans (1 row in the sample install)
 *   - per-level commissions (collapsed into matrix_plans.level_commission JSON)
 *   - users (creates wp_users + matrix_user_meta, preserves bcrypt password)
 *   - matrix_positions (placed users carry their Laravel parent_id;
 *     unplaced users with ref_by>0 are spillover-placed under their
 *     sponsor, falling back to root for orphans)
 *   - level + total_downline tree recompute
 *   - bcrypt-compatible WordPress login filter
 *
 * Out of scope here (PR 2): deposits, withdrawals, commissions
 * history, e-pins, card_holders → matrix_fintava_wallets, cards →
 * matrix_fintava_cards, support tickets, subscribers, and the
 * post-import bank-code backfill auto-trigger.
 *
 * PR 3 scope (this file): a single new phase, `referrals`, slotted
 * between `recalc_downline` and the PR 2 block. PR 1 created
 * wp_matrix_user_meta rows with referred_by=NULL and noted the
 * value would be "resolved in a later phase via map" — that phase
 * never landed and every imported member showed 0 referrals on
 * day one. The new phase joins staging users on themselves
 * (sponsor.id = me.ref_by) to translate Laravel ref_by → WP user
 * id, and writes the result into both wp_matrix_user_meta.referred_by
 * (drives the user-facing referrals UI and the admin referrals
 * report) and wp_matrix_positions.sponsor_id (drives the admin
 * Move-in-Genealogy tool and any future commission attribution
 * that walks position.sponsor_id rather than user-meta).
 *
 * Architecture
 *  - All hooks register via init() so cron/AJAX/admin-post wire up
 *    on every request. Every method is static; there is no
 *    per-instance state.
 *  - Upload streams the .sql/.sql.gz file into a private staging
 *    directory outside wp-content/uploads/ (audit L6 — moved from
 *    wp-content/uploads/matrix-mlm-imports/ to
 *    wp-content/matrix-mlm-imports-private/, with the same Apache /
 *    IIS / Nginx-snippet deny rules the backup tool drops).
 *  - Stage parses the dump's INSERT statements with a tiny string-
 *    aware state machine (handles multi-row VALUES, escapes, and
 *    backtick identifiers) and replays them into temporary
 *    {prefix}matrix_lv_* tables. Staging tables carry mapping
 *    columns (wp_user_id, wp_position_id, wp_plan_id) so each
 *    later phase can JOIN against them instead of round-tripping
 *    through PHP arrays.
 *  - Dry-run is a pure read against staging — counts, collisions,
 *    orphans — saved into the import state for the page to render.
 *  - Commit is AJAX-driven, chunked. JS auto-fires
 *    wp_ajax_matrix_mlm_import_chunk repeatedly; each call advances
 *    one phase by one batch and returns progress JSON. This keeps
 *    every request bounded so shared-hosting timeouts can't kill
 *    a 12k-row import. State (phase, cursor, stats) lives in the
 *    matrix_mlm_import_state option so a dropped browser tab or
 *    server restart doesn't lose progress — the page reloads and
 *    resumes from the saved phase/cursor.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Import {

    /** Prefix for staging tables: {wp_prefix}matrix_lv_users etc. */
    const STAGING_PREFIX = 'matrix_lv_';

    /** Persistent state (status, phase, cursor, stats, errors, analysis). */
    const OPT_STATE = 'matrix_mlm_import_state';

    /** Snapshot of the most recent completed import (for the summary card). */
    const OPT_LAST_RESULT = 'matrix_mlm_import_last_result';

    /**
     * Tables we stage and consume.
     *
     * PR 1 added: users, plans, levels (foundation — WP users, tree, plan/level config).
     * PR 2 added: card_holders, cards, deposits, withdrawals, commissions, pins,
     *             support_tickets, support_messages, subscribers (financials,
     *             Fintava wallets/cards, ticketing, mailing list).
     *
     * Order in this array doesn't drive processing order (that lives in
     * next_phase()); it's just the set of table names the upload parser
     * will recognise and stage. Anything not on this list is silently
     * dropped from the dump.
     */
    const STAGED_TABLES = [
        'users',
        'plans',
        'levels',
        'card_holders',
        'cards',
        'deposits',
        'withdrawals',
        'commissions',
        'pins',
        'support_tickets',
        'support_messages',
        'subscribers',
    ];

    /** Default tree shape — pulled from Laravel general_settings (3×9). */
    const DEFAULT_WIDTH = 3;
    const DEFAULT_DEPTH = 9;

    /**
     * Canonical Laravel column ordering for each source table, exactly
     * as `mysqldump` writes them. Used by execute_staging_insert() to
     * inject a column list into bare-VALUES INSERTs (mysqldump's
     * default format without --complete-insert), so that staging
     * tables — which carry extra mapping columns (wp_user_id,
     * wp_position_id, import_status, import_error, etc.) appended
     * after the Laravel-source columns — accept the dump's positional
     * VALUES tuples without raising "Column count doesn't match value
     * count" on every row.
     *
     * Why this exists: a vanilla `mysqldump --no-create-info DB t1 t2 ...`
     * — the command we tell operators to run on the Import page —
     * produces statements like `INSERT INTO \`users\` VALUES (1,...)`
     * with no column list. MySQL then expects a value for every
     * column in the target table; staging carries four extra trailing
     * mapping columns, so the insert fails and the row is silently
     * dropped (the error gets buried in state.errors and the operator
     * just sees "0 rows staged"). Operators previously had to know to
     * add --complete-insert to their mysqldump command to dodge this.
     * Detecting the bare-VALUES form here and prepending the source
     * column list makes the importer accept the natural command.
     *
     * INSERTs that already carry their own column list (mysqldump
     * with --complete-insert, phpMyAdmin Custom Export, hand-crafted
     * dumps) skip injection and pass through with only the table-
     * name rewrite — operators who already use the verbose form
     * lose nothing.
     *
     * Schema-drift caveat: the column lists below assume the stock
     * ViserMLM Laravel schema. A fork that adds columns to a source
     * table will produce dumps with more values per row than this
     * map names — those INSERTs will then fail their own column-
     * count check at staging. The fix in that case is for the
     * operator to use --complete-insert, which bypasses injection
     * entirely. We keep the injection path conservative rather than
     * trying to auto-detect the dump's row arity, because guessing
     * wrong would silently misalign data into the wrong columns.
     *
     * If you change a staging table's Laravel-side columns in
     * create_staging_tables() above, mirror the change here. The
     * order MUST match the source CREATE TABLE column order, not
     * the staging schema's order.
     */
    const LARAVEL_SOURCE_COLUMNS = [
        'users' => [
            'id', 'ref_by', 'position_id', 'position', 'plan_id',
            'firstname', 'lastname', 'username', 'email', 'country_code',
            'mobile', 'balance', 'password', 'image', 'address',
            'status', 'kyc_data', 'kv', 'ev', 'sv',
            'profile_complete', 'ver_code', 'ver_code_send_at', 'ts', 'tv',
            'tsc', 'ban_reason', 'remember_token', 'next_payment_date',
            'withdraw_status', 'completed_level', 'created_at', 'updated_at',
        ],
        'plans' => [
            'id', 'name', 'price', 'referral_bonus', 'monthly_subscription',
            'status', 'created_at', 'updated_at',
        ],
        'levels' => [
            'id', 'plan_id', 'level', 'amount', 'created_at', 'updated_at',
        ],
        'card_holders' => [
            'id', 'user_id', 'cardholder_id', 'wallet_id', 'firstname',
            'lastname', 'date_of_birth', 'email', 'mobile', 'account_name',
            'account_number', 'bvn', 'nin', 'address', 'user_type',
            'fund_method', 'is_frozen', 'status', 'provider', 'created_at',
            'updated_at',
        ],
        'cards' => [
            'id', 'user_id', 'card_holder_id', 'card_id', 'card_holder_name',
            'card_holder_email', 'card_holder_mobile', 'card_brand', 'account_number',
            'map_id', 'card_no', 'last4', 'exp_month', 'exp_year',
            'card_type', 'card_request_status', 'status', 'admin_feedback',
            'created_at', 'updated_at', 'is_linked', 'linked_at', 'activated_at',
        ],
        'deposits' => [
            'id', 'user_id', 'method_code', 'amount', 'method_currency',
            'charge', 'rate', 'final_amo', 'detail', 'btc_amo',
            'btc_wallet', 'trx', 'try', 'status', 'from_api',
            'admin_feedback', 'created_at', 'updated_at',
        ],
        'withdrawals' => [
            'id', 'method_id', 'user_id', 'amount', 'currency',
            'rate', 'charge', 'trx', 'final_amount', 'after_charge',
            'withdraw_information', 'status', 'admin_feedback', 'created_at', 'updated_at',
        ],
        'commissions' => [
            'id', 'user_id', 'from_user_id', 'amount', 'charge',
            'post_balance', 'trx', 'level', 'mark', 'details',
            'created_at', 'updated_at',
        ],
        'pins' => [
            'id', 'user_id', 'generate_user_id', 'amount', 'pin',
            'status', 'details', 'created_at', 'updated_at',
        ],
        'support_tickets' => [
            'id', 'user_id', 'name', 'email', 'ticket',
            'subject', 'status', 'priority', 'last_reply', 'created_at',
            'updated_at',
        ],
        'support_messages' => [
            'id', 'support_ticket_id', 'admin_id', 'message', 'created_at', 'updated_at',
        ],
        'subscribers' => [
            'id', 'email', 'created_at', 'updated_at',
        ],
    ];

    /**
     * Tables that should never be empty on a complete Laravel dump.
     *
     * Used by handle_upload() to flag dumps where the export step
     * dropped tables silently. The historical failure mode this
     * guards against is phpMyAdmin "Custom Export" — operators tick
     * tables in a long list and miss some, the export succeeds with
     * no warning, the resulting file is "valid SQL" but partial,
     * and the importer used to only check the `users` table for
     * emptiness (so a missing card_holders / cards / withdrawals /
     * support / subscribers wouldn't trip the guard). Now we list
     * every empty table on the error notice so operators see the
     * full picture in one round-trip.
     *
     * `commissions` is intentionally absent — a Laravel install
     * with no commission events yet has zero rows legitimately,
     * and we don't want to false-positive on that case.
     */
    const EXPECTED_NON_EMPTY_TABLES = [
        'users', 'plans', 'levels',
        'card_holders', 'cards',
        'deposits', 'withdrawals', 'pins',
        'support_tickets', 'support_messages', 'subscribers',
    ];

    /** Chunk sizes per phase — sized to fit comfortably under a 30s timeout. */
    const CHUNK_PLANS     = 50;
    const CHUNK_LEVELS    = 200;
    const CHUNK_USERS     = 200;
    const CHUNK_POSITIONS = 300;
    const CHUNK_PARENTS   = 500;
    const CHUNK_RECALC    = 500;
    const CHUNK_REFERRALS = 500;
    // PR 2 phase chunks. Smaller for INSERT-heavy phases; larger for
    // pure UPDATE/lookup phases.
    const CHUNK_FINTAVA_W = 200;
    const CHUNK_FINTAVA_C = 200;
    const CHUNK_DEPOSITS  = 300;
    const CHUNK_WITHDRAW  = 300;
    const CHUNK_COMMS     = 500;
    const CHUNK_EPINS     = 500;
    const CHUNK_TICKETS   = 200;
    const CHUNK_TMSGS     = 500;
    const CHUNK_SUBS      = 500;

    /**
     * Register every hook this module owns. Idempotent — safe to call
     * from multiple bootstrap paths because every callback is static
     * so add_action() de-duplicates by callable identity.
     */
    public static function init() {
        add_action('admin_post_matrix_mlm_import_upload', [__CLASS__, 'handle_upload']);
        add_action('admin_post_matrix_mlm_import_dryrun', [__CLASS__, 'handle_dryrun']);
        add_action('admin_post_matrix_mlm_import_commit', [__CLASS__, 'handle_commit_start']);
        add_action('admin_post_matrix_mlm_import_reset',  [__CLASS__, 'handle_reset']);
        add_action('wp_ajax_matrix_mlm_import_chunk',     [__CLASS__, 'handle_commit_chunk']);

        // Bcrypt-compatible login filter — runs on every authentication
        // attempt, lets imported Laravel users keep their existing
        // passwords without requiring a reset.
        add_filter('check_password', [__CLASS__, 'check_password_bcrypt'], 10, 4);
    }

    /* ================================================================
     * Bcrypt-compatible password verification
     * ================================================================ */

    /**
     * Fall back to PHP's native password_verify() for bcrypt hashes.
     *
     * WordPress core's wp_check_password() only handles PHPass ($P$, $H$)
     * and legacy MD5; Laravel's $2y$ bcrypt hashes never match. By
     * hooking the check_password filter we let imported users log in
     * with their existing passwords. On a successful match we leave
     * the hash untouched — we don't transparently rehash to PHPass
     * because the filter signature doesn't expose enough context to do
     * that safely (the user_id can be 0 for fresh logins) and because
     * keeping the bcrypt hash means the next login on a different
     * server / replica still works without depending on a one-time
     * rehash having landed.
     *
     * Scoped tightly: we only intervene when WP's own check returned
     * false AND the stored hash looks like bcrypt ($2y$/$2a$/$2b$,
     * exactly 60 characters). Anything else passes through unchanged.
     *
     * @param bool   $check    WP's pre-existing verdict (true = matched).
     * @param string $password Plaintext password being verified.
     * @param string $hash     Stored hash from wp_users.user_pass.
     * @param int    $user_id  User ID (may be 0 on first-pass login).
     */
    public static function check_password_bcrypt($check, $password, $hash, $user_id) {
        if ($check) {
            return $check;
        }
        if (!is_string($hash) || strlen($hash) !== 60) {
            return $check;
        }
        $prefix = substr($hash, 0, 4);
        if ($prefix !== '$2y$' && $prefix !== '$2a$' && $prefix !== '$2b$') {
            return $check;
        }
        return password_verify($password, $hash);
    }

    /* ================================================================
     * State management
     * ================================================================ */

    /** Read the current import state, with sensible defaults. */
    private static function get_state() {
        $s = get_option(self::OPT_STATE);
        if (!is_array($s)) {
            $s = [];
        }
        return array_merge([
            'status'       => 'idle',
            'phase'        => null,
            'cursor'       => 0,
            'stats'        => [],
            'errors'       => [],
            'analysis'     => null,
            'created_at'   => null,
            'completed_at' => null,
            'file'         => null,
        ], $s);
    }

    /** Patch the state and persist. Returns the merged state. */
    private static function set_state(array $patch) {
        $s = array_merge(self::get_state(), $patch);
        update_option(self::OPT_STATE, $s);
        return $s;
    }

    /** Capability gate shared by every admin-post / AJAX handler. */
    private static function require_admin() {
        if (!current_user_can('manage_matrix_settings')) {
            wp_die(__('You do not have permission to run the importer.', 'matrix-mlm'));
        }
    }

    /**
     * Redirect back to the Import page with a one-shot notice. exit()
     * to make sure no further output corrupts the redirect.
     */
    private static function redirect_with_notice($type, $message) {
        $url = add_query_arg([
            'page'           => 'matrix-mlm-import',
            'matrix_notice'  => $type,
            'matrix_message' => rawurlencode($message),
        ], admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    /* ================================================================
     * Filesystem helpers
     * ================================================================ */

    /**
     * Return (and create on first call) the directory uploaded
     * Laravel dumps live in.
     *
     * Audit L6 hardening — mirrors the H9 fix the backup module
     * already shipped:
     *
     *   - Default location moved from wp-content/uploads/matrix-mlm-
     *     imports/ to wp-content/matrix-mlm-imports-private/. The
     *     uploads directory is unconditionally web-readable on every
     *     hosting stack; sibling private dirs under wp-content/ are
     *     not, on most modern Nginx and IIS configurations whose
     *     vhost rules only serve uploads/, themes/, plugins/.
     *
     *   - Operator override: define MATRIX_MLM_IMPORT_DIR in
     *     wp-config.php to point at a directory outside the webroot
     *     entirely. Recommended for production setups handling real
     *     member dumps. The constant takes precedence over the
     *     default and is not validated for path safety — the
     *     operator owns that.
     *
     *   - Cross-server deny rules written on first creation:
     *       .htaccess        — Apache 2.2 + 2.4
     *       web.config       — IIS 7+
     *       nginx-deny.conf.example
     *                        — copy-pasteable Nginx snippet (Nginx
     *                          does not honour per-directory configs;
     *                          operator must splice it into the
     *                          vhost server { } block)
     *       index.html       — empty, belt-and-braces against
     *                          directory listing
     *
     *   - One-shot migration of any leftover dumps from the legacy
     *     wp-content/uploads/matrix-mlm-imports/ location into the
     *     new dir. Idempotent — files are MOVED, not copied, and a
     *     same-named destination is skipped rather than clobbered so
     *     a partial migration never destroys data.
     *
     * The protection matters more here than on the backup dir:
     * Laravel dumps carry bcrypt password hashes, BVNs, NINs, full
     * phone numbers and contact details for every imported member.
     */
    private static function get_import_dir() {
        // Operator override via wp-config.php constant. Documented
        // exit hatch for production setups that want imports outside
        // the webroot entirely.
        if (defined('MATRIX_MLM_IMPORT_DIR') && is_string(MATRIX_MLM_IMPORT_DIR) && MATRIX_MLM_IMPORT_DIR !== '') {
            $dir = rtrim(MATRIX_MLM_IMPORT_DIR, '/\\');
            if (!file_exists($dir)) {
                wp_mkdir_p($dir);
            }
            self::write_protection_files($dir);
            return $dir;
        }

        // Default: wp-content/matrix-mlm-imports-private/. Sibling of
        // uploads/, themes/, plugins/ but NOT inside any of them, so
        // a request for /wp-content/matrix-mlm-imports-private/<file>
        // is not a path that the typical Nginx wp-content rule
        // serves. The deny files give a second layer of protection
        // on hosts that DO serve arbitrary wp-content paths.
        $dir = rtrim(WP_CONTENT_DIR, '/\\') . '/matrix-mlm-imports-private';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        self::write_protection_files($dir);
        self::migrate_legacy_imports($dir);

        return $dir;
    }

    /**
     * Drop deny-all server config files into the import directory
     * for Apache, IIS, and Nginx. Idempotent — only writes a file
     * if it does not already exist, so an operator who has
     * customised any of these is not overwritten.
     */
    private static function write_protection_files($dir) {
        // Apache 2.2 + 2.4 compatible "deny all" stanza.
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents(
                $htaccess,
                "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" .
                "<IfModule !mod_authz_core.c>\nOrder Deny,Allow\nDeny from all\n</IfModule>\n"
            );
        }

        // IIS 7+ deny rule.
        $webconfig = $dir . '/web.config';
        if (!file_exists($webconfig)) {
            @file_put_contents(
                $webconfig,
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" .
                "<configuration>\n" .
                "  <system.webServer>\n" .
                "    <authorization>\n" .
                "      <deny users=\"*\" />\n" .
                "    </authorization>\n" .
                "  </system.webServer>\n" .
                "</configuration>\n"
            );
        }

        // Nginx does not honour per-directory config files. Drop a
        // copy-pasteable snippet alongside the other deny files so
        // the operator can splice it into their site vhost. The
        // README header explains why this file exists.
        $nginx_snippet = $dir . '/nginx-deny.conf.example';
        if (!file_exists($nginx_snippet)) {
            $rel = '/wp-content/' . basename($dir) . '/';
            @file_put_contents(
                $nginx_snippet,
                "# Matrix MLM Pro — import staging directory protection (Nginx)\n" .
                "#\n" .
                "# Nginx does not honour per-directory .htaccess or web.config.\n" .
                "# Splice the following block into your site's server { } stanza\n" .
                "# (alongside the existing wp-content rules) and reload nginx:\n" .
                "#\n" .
                "#     location {$rel} {\n" .
                "#         deny all;\n" .
                "#         return 404;\n" .
                "#     }\n" .
                "#\n" .
                "# Or move imports outside the webroot entirely by defining\n" .
                "# MATRIX_MLM_IMPORT_DIR in wp-config.php — the recommended\n" .
                "# production setup. Example:\n" .
                "#\n" .
                "#     define('MATRIX_MLM_IMPORT_DIR', '/var/lib/matrix-mlm-imports');\n"
            );
        }

        // Belt-and-braces against directory listing.
        $index = $dir . '/index.html';
        if (!file_exists($index)) {
            @file_put_contents($index, '');
        }
    }

    /**
     * One-shot migration of dumps from the legacy
     * wp-content/uploads/matrix-mlm-imports/ location into the new
     * dir. Idempotent: skipped when the legacy dir does not exist
     * or is empty.
     *
     * Files are MOVED, not copied — leaving two on-disk copies of a
     * sensitive dump (bcrypt hashes, BVNs, NINs) would defeat the
     * point. If a move fails for any reason (cross-filesystem,
     * permissions, etc.) the source is left in place so the data is
     * never lost; the operator can clean up manually after
     * confirming the new location works.
     */
    private static function migrate_legacy_imports($new_dir) {
        $uploads = wp_upload_dir();
        $legacy_dir = trailingslashit($uploads['basedir']) . 'matrix-mlm-imports';
        if (!is_dir($legacy_dir)) {
            return;
        }
        if (rtrim($legacy_dir, '/\\') === rtrim($new_dir, '/\\')) {
            return; // operator pointed MATRIX_MLM_IMPORT_DIR at the legacy dir
        }

        // The importer's handle_upload writes files as
        // 'laravel-<timestamp>-<sanitised-original>.{sql,sql.gz}'.
        // Match that shape so we don't sweep up arbitrary other
        // files an admin may have stashed in the legacy dir.
        $files = @glob($legacy_dir . '/laravel-*.{sql,sql.gz}', GLOB_BRACE);
        if (!is_array($files) || empty($files)) {
            return;
        }

        $moved = 0;
        foreach ($files as $src) {
            $dest = $new_dir . '/' . basename($src);
            if (file_exists($dest)) {
                continue; // do not clobber an existing same-named file
            }
            if (@rename($src, $dest)) {
                $moved++;
            }
        }

        if ($moved > 0) {
            error_log(sprintf(
                '[Matrix MLM] Migrated %d import dump(s) from %s to %s',
                $moved, $legacy_dir, $new_dir
            ));
        }
    }

    /** Fully-qualified staging table name. */
    private static function staging_table($name) {
        global $wpdb;
        return $wpdb->prefix . self::STAGING_PREFIX . $name;
    }

    /* ================================================================
     * Staging schema
     * ================================================================ */

    /**
     * Drop and recreate the staging tables. Schemas mirror Laravel's
     * column shapes (so INSERTs from a vanilla mysqldump replay
     * verbatim once we rewrite the table name) plus a few mapping
     * columns (wp_user_id, wp_position_id, wp_plan_id, import_status,
     * import_error) that each commit phase fills in as it works
     * through the rows.
     */
    private static function create_staging_tables() {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $users   = self::staging_table('users');
        $plans   = self::staging_table('plans');
        $levels  = self::staging_table('levels');

        // DROP first so a re-upload starts from a clean slate.
        $wpdb->query("DROP TABLE IF EXISTS `$users`");
        $wpdb->query("DROP TABLE IF EXISTS `$plans`");
        $wpdb->query("DROP TABLE IF EXISTS `$levels`");

        // Mirror Laravel users schema. Columns we don't strictly need
        // (image, kyc_data, ban_reason, etc.) are still defined so
        // INSERTs replay without column-count mismatches.
        $wpdb->query("CREATE TABLE `$users` (
            `id` BIGINT UNSIGNED NOT NULL,
            `ref_by` INT UNSIGNED NOT NULL DEFAULT 0,
            `position_id` INT NOT NULL DEFAULT 0,
            `position` INT NOT NULL DEFAULT 0,
            `plan_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `firstname` VARCHAR(40) DEFAULT NULL,
            `lastname` VARCHAR(40) DEFAULT NULL,
            `username` VARCHAR(40) NOT NULL,
            `email` VARCHAR(120) NOT NULL,
            `country_code` VARCHAR(40) DEFAULT NULL,
            `mobile` VARCHAR(40) DEFAULT NULL,
            `balance` DECIMAL(28,8) NOT NULL DEFAULT 0,
            `password` VARCHAR(255) NOT NULL DEFAULT '',
            `image` VARCHAR(255) DEFAULT NULL,
            `address` TEXT DEFAULT NULL,
            `status` TINYINT(1) NOT NULL DEFAULT 1,
            `kyc_data` TEXT DEFAULT NULL,
            `kv` TINYINT(1) NOT NULL DEFAULT 0,
            `ev` TINYINT(1) NOT NULL DEFAULT 0,
            `sv` TINYINT(1) NOT NULL DEFAULT 0,
            `profile_complete` TINYINT(1) NOT NULL DEFAULT 0,
            `ver_code` VARCHAR(40) DEFAULT NULL,
            `ver_code_send_at` DATETIME DEFAULT NULL,
            `ts` TINYINT(1) NOT NULL DEFAULT 0,
            `tv` TINYINT(1) NOT NULL DEFAULT 1,
            `tsc` VARCHAR(255) DEFAULT NULL,
            `ban_reason` VARCHAR(255) DEFAULT NULL,
            `remember_token` VARCHAR(255) DEFAULT NULL,
            `next_payment_date` TIMESTAMP NULL DEFAULT NULL,
            `withdraw_status` TINYINT(1) NOT NULL DEFAULT 1,
            `completed_level` INT NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP NULL DEFAULT NULL,
            `updated_at` TIMESTAMP NULL DEFAULT NULL,
            `wp_user_id` BIGINT UNSIGNED DEFAULT NULL,
            `wp_position_id` BIGINT UNSIGNED DEFAULT NULL,
            `import_status` VARCHAR(20) DEFAULT NULL,
            `import_error` VARCHAR(500) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `ref_by` (`ref_by`),
            KEY `position_id` (`position_id`),
            KEY `wp_user_id` (`wp_user_id`),
            KEY `wp_position_id` (`wp_position_id`),
            KEY `email` (`email`(64)),
            KEY `username` (`username`)
        ) $charset");

        $wpdb->query("CREATE TABLE `$plans` (
            `id` BIGINT UNSIGNED NOT NULL,
            `name` VARCHAR(255) DEFAULT NULL,
            `price` DECIMAL(28,8) NOT NULL DEFAULT 0,
            `referral_bonus` DECIMAL(28,8) NOT NULL DEFAULT 0,
            `monthly_subscription` TINYINT(1) NOT NULL DEFAULT 1,
            `status` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NULL DEFAULT NULL,
            `updated_at` TIMESTAMP NULL DEFAULT NULL,
            `wp_plan_id` BIGINT UNSIGNED DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) $charset");

        $wpdb->query("CREATE TABLE `$levels` (
            `id` BIGINT UNSIGNED NOT NULL,
            `plan_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `level` INT NOT NULL DEFAULT 0,
            `amount` DECIMAL(28,8) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NULL DEFAULT NULL,
            `updated_at` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plan_id` (`plan_id`)
        ) $charset");

        // ---------- PR 2 staging tables ----------
        //
        // Each carries:
        //   - The Laravel columns we actually consume (extras dropped quietly
        //     when the dump's INSERT replays — column-count mismatches would
        //     surface here, so we mirror the Laravel shape closely).
        //   - `import_status` + `import_error` for per-row diagnostics.
        //   - `wp_target_id` (or domain-specific equivalent like wp_ticket_id /
        //     wp_wallet_id) to link a Laravel row to the new MatrixPro row it
        //     produced. Lets dependent phases (e.g. ticket_messages →
        //     support_tickets) join through staging instead of round-tripping
        //     through PHP arrays.

        $card_holders     = self::staging_table('card_holders');
        $cards            = self::staging_table('cards');
        $deposits         = self::staging_table('deposits');
        $withdrawals      = self::staging_table('withdrawals');
        $commissions      = self::staging_table('commissions');
        $pins             = self::staging_table('pins');
        $support_tickets  = self::staging_table('support_tickets');
        $support_messages = self::staging_table('support_messages');
        $subscribers      = self::staging_table('subscribers');

        foreach ([
            $card_holders, $cards, $deposits, $withdrawals,
            $commissions, $pins, $support_tickets, $support_messages, $subscribers,
        ] as $name) {
            $wpdb->query("DROP TABLE IF EXISTS `$name`");
        }

        // card_holders → matrix_fintava_wallets (1 row per user, UNIQUE on user_id)
        $wpdb->query("CREATE TABLE `$card_holders` (
            `id` INT NOT NULL,
            `user_id` BIGINT DEFAULT 0,
            `cardholder_id` VARCHAR(255) DEFAULT NULL,
            `wallet_id` VARCHAR(255) DEFAULT NULL,
            `firstname` VARCHAR(255) DEFAULT NULL,
            `lastname` VARCHAR(255) DEFAULT NULL,
            `date_of_birth` DATE DEFAULT NULL,
            `email` VARCHAR(255) DEFAULT NULL,
            `mobile` VARCHAR(255) DEFAULT NULL,
            `account_name` VARCHAR(255) DEFAULT NULL,
            `account_number` VARCHAR(40) DEFAULT NULL,
            `bvn` VARCHAR(40) DEFAULT NULL,
            `nin` VARCHAR(40) DEFAULT NULL,
            `address` VARCHAR(255) DEFAULT NULL,
            `user_type` VARCHAR(40) DEFAULT NULL,
            `fund_method` VARCHAR(40) DEFAULT NULL,
            `is_frozen` TINYINT(1) NOT NULL DEFAULT 0,
            `status` TINYINT(1) NOT NULL DEFAULT 1,
            `provider` VARCHAR(255) DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT NULL,
            `updated_at` TIMESTAMP NULL DEFAULT NULL,
            `wp_wallet_id` BIGINT UNSIGNED DEFAULT NULL,
            `import_status` VARCHAR(20) DEFAULT NULL,
            `import_error` VARCHAR(500) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`),
            KEY `cardholder_id` (`cardholder_id`)
        ) $charset");

        // cards → matrix_fintava_cards. Joins to card_holders via card_holder_id
        // to resolve the destination wallet (since matrix_fintava_cards.wallet_id
        // mirrors the card_holders.wallet_id UUID).
        $wpdb->query("CREATE TABLE `$cards` (
            `id` BIGINT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `card_holder_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `card_id` VARCHAR(255) DEFAULT NULL,
            `card_holder_name` VARCHAR(255) DEFAULT NULL,
            `card_holder_email` VARCHAR(255) DEFAULT NULL,
            `card_holder_mobile` VARCHAR(255) DEFAULT NULL,
            `card_brand` VARCHAR(40) DEFAULT NULL,
            `account_number` VARCHAR(255) DEFAULT NULL,
            `map_id` VARCHAR(255) DEFAULT NULL,
            `card_no` VARCHAR(255) DEFAULT NULL,
            `last4` VARCHAR(40) DEFAULT NULL,
            `exp_month` VARCHAR(40) DEFAULT NULL,
            `exp_year` VARCHAR(40) DEFAULT NULL,
            `card_type` VARCHAR(40) DEFAULT NULL,
            `card_request_status` VARCHAR(40) DEFAULT NULL,
            `status` VARCHAR(40) DEFAULT NULL,
            `admin_feedback` VARCHAR(255) DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT NULL,
            `updated_at` TIMESTAMP NULL DEFAULT NULL,
            `is_linked` TINYINT(1) DEFAULT 0,
            `linked_at` TIMESTAMP NULL DEFAULT NULL,
            `activated_at` TIMESTAMP NULL DEFAULT NULL,
            `wp_target_id` BIGINT UNSIGNED DEFAULT NULL,
            `import_status` VARCHAR(20) DEFAULT NULL,
            `import_error` VARCHAR(500) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `card_holder_id` (`card_holder_id`),
            KEY `user_id` (`user_id`)
        ) $charset");

        // deposits — Laravel status enum: 1=success, 2=pending, 3=cancel
        $wpdb->query("CREATE TABLE `$deposits` (
            `id` BIGINT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `method_code` INT UNSIGNED NOT NULL DEFAULT 0,
            `amount` DECIMAL(28,8) NOT NULL DEFAULT 0,
            `method_currency` VARCHAR(40) DEFAULT NULL,
            `charge` DECIMAL(28,8) NOT NULL DEFAULT 0,
            `rate` DECIMAL(28,8) NOT NULL DEFAULT 0,
            `final_amo` DECIMAL(28,8) NOT NULL DEFAULT 0,
            `detail` TEXT DEFAULT NULL,
            `btc_amo` VARCHAR(255) DEFAULT NULL,
            `btc_wallet` VARCHAR(255) DEFAULT NULL,
            `trx` VARCHAR(40) DEFAULT NULL,
            `try` INT NOT NULL DEFAULT 0,
            `status` TINYINT(1) NOT NULL DEFAULT 0,
            `from_api` TINYINT(1) NOT NULL DEFAULT 0,
            `admin_feedback` VARCHAR(255) DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT NULL,
            `updated_at` TIMESTAMP NULL DEFAULT NULL,
            `wp_target_id` BIGINT UNSIGNED DEFAULT NULL,
            `import_status` VARCHAR(20) DEFAULT NULL,
            `import_error` VARCHAR(500) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`),
            KEY `trx` (`trx`)
        ) $charset");

        // withdrawals — Laravel status enum: 1=success(approved), 2=pending, 3=cancel
        $wpdb->query("CREATE TABLE `$withdrawals` (
            `id` BIGINT UNSIGNED NOT NULL,
            `method_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `amount` DECIMAL(28,8) NOT NULL DEFAULT 0,
            `currency` VARCHAR(40) DEFAULT NULL,
            `rate` DECIMAL(28,8) NOT NULL DEFAULT 0,
            `charge` DECIMAL(28,8) NOT NULL DEFAULT 0,
            `trx` VARCHAR(40) DEFAULT NULL,
            `final_amount` DECIMAL(28,8) NOT NULL DEFAULT 0,
            `after_charge` DECIMAL(28,8) NOT NULL DEFAULT 0,
            `withdraw_information` TEXT DEFAULT NULL,
            `status` TINYINT(1) NOT NULL DEFAULT 0,
            `admin_feedback` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT NULL,
            `updated_at` TIMESTAMP NULL DEFAULT NULL,
            `wp_target_id` BIGINT UNSIGNED DEFAULT NULL,
            `import_status` VARCHAR(20) DEFAULT NULL,
            `import_error` VARCHAR(500) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`),
            KEY `trx` (`trx`)
        ) $charset");

        // commissions — Laravel mark: 1=referral, 2=level (else fallback to 'level')
        $wpdb->query("CREATE TABLE `$commissions` (
            `id` BIGINT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED DEFAULT 0,
            `from_user_id` INT UNSIGNED DEFAULT 0,
            `amount` DECIMAL(28,8) NOT NULL DEFAULT 0,
            `charge` DECIMAL(28,8) NOT NULL DEFAULT 0,
            `post_balance` DECIMAL(28,8) NOT NULL DEFAULT 0,
            `trx` VARCHAR(30) DEFAULT NULL,
            `level` INT DEFAULT 0,
            `mark` TINYINT(1) DEFAULT 0,
            `details` VARCHAR(255) DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT NULL,
            `updated_at` TIMESTAMP NULL DEFAULT NULL,
            `wp_target_id` BIGINT UNSIGNED DEFAULT NULL,
            `import_status` VARCHAR(20) DEFAULT NULL,
            `import_error` VARCHAR(500) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`),
            KEY `from_user_id` (`from_user_id`)
        ) $charset");

        // pins → matrix_epins. Laravel status: 0=unused, 1=used.
        $wpdb->query("CREATE TABLE `$pins` (
            `id` BIGINT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED DEFAULT NULL,
            `generate_user_id` INT UNSIGNED DEFAULT NULL,
            `amount` DECIMAL(28,8) NOT NULL DEFAULT 0,
            `pin` VARCHAR(191) DEFAULT NULL,
            `status` TINYINT(1) NOT NULL DEFAULT 0,
            `details` VARCHAR(255) DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT NULL,
            `updated_at` TIMESTAMP NULL DEFAULT NULL,
            `wp_target_id` BIGINT UNSIGNED DEFAULT NULL,
            `import_status` VARCHAR(20) DEFAULT NULL,
            `import_error` VARCHAR(500) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `pin` (`pin`(64))
        ) $charset");

        // support_tickets — Laravel priority 1=low/2=med/3=high; status 0=open/1=answered/2=replied/3=closed
        $wpdb->query("CREATE TABLE `$support_tickets` (
            `id` BIGINT UNSIGNED NOT NULL,
            `user_id` INT NOT NULL DEFAULT 0,
            `name` VARCHAR(40) DEFAULT NULL,
            `email` VARCHAR(120) DEFAULT NULL,
            `ticket` VARCHAR(40) DEFAULT NULL,
            `subject` VARCHAR(255) DEFAULT NULL,
            `status` TINYINT(1) NOT NULL DEFAULT 0,
            `priority` TINYINT(1) NOT NULL DEFAULT 0,
            `last_reply` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT NULL,
            `updated_at` TIMESTAMP NULL DEFAULT NULL,
            `wp_ticket_id` BIGINT UNSIGNED DEFAULT NULL,
            `import_status` VARCHAR(20) DEFAULT NULL,
            `import_error` VARCHAR(500) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`)
        ) $charset");

        // support_messages — admin_id>0 means a staff reply (is_admin=1).
        $wpdb->query("CREATE TABLE `$support_messages` (
            `id` BIGINT UNSIGNED NOT NULL,
            `support_ticket_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `admin_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `message` LONGTEXT DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT NULL,
            `updated_at` TIMESTAMP NULL DEFAULT NULL,
            `wp_target_id` BIGINT UNSIGNED DEFAULT NULL,
            `import_status` VARCHAR(20) DEFAULT NULL,
            `import_error` VARCHAR(500) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `support_ticket_id` (`support_ticket_id`)
        ) $charset");

        // subscribers — straight email list, INSERT IGNORE on the WP side
        // because matrix_subscribers has UNIQUE on email and we want to
        // keep whatever's already there if a duplicate appears.
        $wpdb->query("CREATE TABLE `$subscribers` (
            `id` BIGINT UNSIGNED NOT NULL,
            `email` VARCHAR(120) DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT NULL,
            `updated_at` TIMESTAMP NULL DEFAULT NULL,
            `wp_target_id` BIGINT UNSIGNED DEFAULT NULL,
            `import_status` VARCHAR(20) DEFAULT NULL,
            `import_error` VARCHAR(500) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `email` (`email`)
        ) $charset");
    }

    /** Drop every staging table — called from the Reset action. */
    private static function drop_staging_tables() {
        global $wpdb;
        foreach (self::STAGED_TABLES as $t) {
            $name = self::staging_table($t);
            $wpdb->query("DROP TABLE IF EXISTS `$name`");
        }
    }

    /* ================================================================
     * SQL parser
     * ================================================================ */

    /**
     * Stream the dump file character-by-character with a tiny string-
     * aware state machine, emitting each terminating `;` as a complete
     * statement. We only act on INSERT INTO statements whose target
     * table is in the allowlist; everything else (CREATE TABLE, SET,
     * comments, COMMIT, ALTER, etc.) is silently skipped.
     *
     * The state machine tracks single-quoted strings, double-quoted
     * strings, and backtick-quoted identifiers, including backslash
     * escapes — matching MySQL's grammar exactly enough for vanilla
     * mysqldump output. We don't try to be a complete SQL parser;
     * we just need to recognise where one statement ends and the
     * next begins so a `;` inside a string literal (e.g. an address
     * field that happens to contain a semicolon) doesn't terminate
     * the statement early.
     *
     * @return int|WP_Error Number of INSERTs handed to $callback.
     */
    private static function stream_inserts($filepath, array $allowed_tables, callable $callback) {
        $fp = @fopen($filepath, 'rb');
        if (!$fp) {
            return new WP_Error('matrix_import_open', __('Could not open import file.', 'matrix-mlm'));
        }

        $allowed = array_flip($allowed_tables);
        $buffer  = '';
        $in_str  = false;
        $quote   = null;
        $escape  = false;
        $count   = 0;

        while (!feof($fp)) {
            $chunk = fread($fp, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }

            $len = strlen($chunk);
            for ($i = 0; $i < $len; $i++) {
                $c = $chunk[$i];

                // Backslash-escape inside a string consumes the next char literally.
                if ($escape) {
                    $escape = false;
                    $buffer .= $c;
                    continue;
                }

                if ($in_str) {
                    if ($c === '\\') {
                        $escape = true;
                        $buffer .= $c;
                        continue;
                    }
                    if ($c === $quote) {
                        $in_str = false;
                    }
                    $buffer .= $c;
                    continue;
                }

                // Outside any string: track quote entry and statement boundary.
                if ($c === '\'' || $c === '"' || $c === '`') {
                    $in_str = true;
                    $quote  = $c;
                    $buffer .= $c;
                    continue;
                }

                if ($c === ';') {
                    $stmt = trim($buffer);
                    $buffer = '';
                    if ($stmt !== '' && stripos($stmt, 'INSERT') === 0) {
                        // Only INSERT (and INSERT IGNORE) statements interest us;
                        // every other statement type is dropped on the floor.
                        if (preg_match('/^INSERT\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?/i', $stmt, $m)) {
                            if (isset($allowed[$m[1]])) {
                                $callback($m[1], $stmt);
                                $count++;
                            }
                        }
                    }
                    continue;
                }

                $buffer .= $c;
            }
        }

        fclose($fp);
        return $count;
    }

    /**
     * Replay one Laravel INSERT against the matching staging table.
     *
     * Two transformations:
     *   1. If the INSERT is in mysqldump's default bare-VALUES form
     *      (no column list between the table name and the VALUES
     *      keyword), inject the Laravel-side column list from
     *      LARAVEL_SOURCE_COLUMNS. Required because staging tables
     *      append mapping columns (wp_user_id, wp_position_id,
     *      import_status, import_error, …) after the source
     *      columns; without a column list MySQL expects a value for
     *      every column and raises "Column count doesn't match
     *      value count, table `<staging>`" on every row. See the
     *      LARAVEL_SOURCE_COLUMNS doc-block for the full story.
     *   2. Rewrite the table name from the Laravel name (e.g.
     *      `users`) to the staging name (e.g. `wp_matrix_lv_users`).
     *
     * Operations always happen in that order: inject first against
     * the source table name (so the regex anchors are correct),
     * then rewrite the table name. Doing it the other way around
     * would force the column-injection regex to know about the
     * runtime-generated staging prefix.
     */
    private static function execute_staging_insert($table, $stmt) {
        global $wpdb;
        $staging = self::staging_table($table);

        // Detect bare-VALUES form: "INSERT [IGNORE] INTO `table` VALUES …"
        // with no column list "(...)" between the table name and the
        // VALUES keyword. The whitespace allowance handles tabs,
        // multiple spaces, and the optional IGNORE keyword.
        $needs_column_injection = (bool) preg_match(
            '/^INSERT\s+(?:IGNORE\s+)?INTO\s+`?' . preg_quote($table, '/') . '`?\s+VALUES\b/i',
            $stmt
        );
        if ($needs_column_injection) {
            if (!isset(self::LARAVEL_SOURCE_COLUMNS[$table])) {
                // Should not be reachable via the streamer (it filters
                // to STAGED_TABLES, all of which have entries in
                // LARAVEL_SOURCE_COLUMNS), but guard anyway so a
                // future schema change that misses one of the maps
                // surfaces with a clear message instead of a SQL error.
                return new WP_Error(
                    'matrix_import_columns',
                    sprintf(
                        /* translators: %s: source table name */
                        __('Bare-VALUES INSERT for `%s` cannot be repaired (no source column map registered).', 'matrix-mlm'),
                        $table
                    )
                );
            }
            $cols = '`' . implode('`, `', self::LARAVEL_SOURCE_COLUMNS[$table]) . '`';
            $stmt = preg_replace(
                '/^(INSERT\s+(?:IGNORE\s+)?INTO\s+`?' . preg_quote($table, '/') . '`?\s+)(VALUES\b)/i',
                '$1(' . $cols . ') $2',
                $stmt,
                1
            );
        }

        $rewritten = preg_replace(
            '/^(INSERT\s+(?:IGNORE\s+)?INTO\s+)`?' . preg_quote($table, '/') . '`?/i',
            '$1`' . $staging . '`',
            $stmt,
            1,
            $cnt
        );
        if (!$cnt) {
            // Should never happen — the streamer only hands us INSERTs
            // it already matched against this same regex shape.
            return new WP_Error('matrix_import_rewrite', sprintf('Could not rewrite INSERT for %s', $table));
        }
        $r = $wpdb->query($rewritten);
        if ($r === false) {
            return new WP_Error('matrix_import_insert', $wpdb->last_error . ' — table: ' . $table);
        }
        return $r;
    }

    /* ================================================================
     * handle_upload — receives the dump, decompresses if .gz, stages
     * ================================================================ */

    public static function handle_upload() {
        self::require_admin();
        check_admin_referer('matrix_mlm_import_upload');

        if (empty($_FILES['dump_file']) || (($_FILES['dump_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)) {
            self::redirect_with_notice('error', __('No file uploaded or upload failed.', 'matrix-mlm'));
        }

        $original = sanitize_file_name($_FILES['dump_file']['name']);
        if (!preg_match('/\.(sql|gz|sql\.gz)$/i', $original)) {
            self::redirect_with_notice('error', __('Only .sql or .sql.gz files are accepted.', 'matrix-mlm'));
        }

        $dir = self::get_import_dir();
        $path = $dir . '/laravel-' . time() . '-' . $original;
        if (!@move_uploaded_file($_FILES['dump_file']['tmp_name'], $path)) {
            self::redirect_with_notice('error', __('Could not save uploaded file. Check that wp-content/uploads is writable.', 'matrix-mlm'));
        }

        // Decompress .gz inline so the parser only has to deal with one format.
        if (substr($path, -3) === '.gz') {
            if (!function_exists('gzopen')) {
                @unlink($path);
                self::redirect_with_notice('error', __('Server lacks zlib — upload an uncompressed .sql file instead.', 'matrix-mlm'));
            }
            $unzipped = preg_replace('/\.gz$/', '', $path);
            $in  = @gzopen($path, 'rb');
            $out = @fopen($unzipped, 'wb');
            if (!$in || !$out) {
                self::redirect_with_notice('error', __('Could not decompress the uploaded file.', 'matrix-mlm'));
            }
            while (!gzeof($in)) {
                fwrite($out, gzread($in, 65536));
            }
            gzclose($in);
            fclose($out);
            @unlink($path);
            $path = $unzipped;
        }

        // The staging step parses the whole file in one go. For a 12k-
        // user dump that's typically <30s on a healthy host, but bump
        // the time limit defensively for slower shared hosting.
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        // Reset state — a new upload starts a fresh import.
        delete_option(self::OPT_STATE);
        self::create_staging_tables();

        $errors = [];
        $r = self::stream_inserts($path, self::STAGED_TABLES, function ($table, $stmt) use (&$errors) {
            $res = self::execute_staging_insert($table, $stmt);
            if (is_wp_error($res)) {
                $errors[] = $res->get_error_message();
            }
        });

        if (is_wp_error($r)) {
            self::redirect_with_notice('error', $r->get_error_message());
        }

        global $wpdb;
        $u = self::staging_table('users');
        $p = self::staging_table('plans');
        $l = self::staging_table('levels');

        $stats = [
            'users'             => (int) $wpdb->get_var("SELECT COUNT(*) FROM `$u`"),
            'plans'             => (int) $wpdb->get_var("SELECT COUNT(*) FROM `$p`"),
            'levels'            => (int) $wpdb->get_var("SELECT COUNT(*) FROM `$l`"),
            'inserts_processed' => $r,
        ];
        // Per-table counts for the financial / Fintava / ticketing /
        // subscriber tables too, so the EXPECTED_NON_EMPTY_TABLES
        // guard below sees the full picture and so the Import page
        // can render every staged count immediately after upload (not
        // only after the dry-run runs).
        foreach (self::STAGED_TABLES as $tname) {
            if (isset($stats[$tname])) {
                continue; // users / plans / levels already counted above
            }
            $sn = self::staging_table($tname);
            $stats[$tname] = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$sn`");
        }

        // Guard: any expected-non-empty table that came in at 0 rows
        // points at one of two failure modes the operator needs to
        // know about:
        //   (a) a partial export — phpMyAdmin Custom Export silently
        //       dropped checkboxes for some tables, the resulting
        //       file is "valid SQL" but missing data;
        //   (b) a column-count mismatch in staging — bare-VALUES
        //       INSERTs against the staging schema (which carries
        //       extra mapping columns) used to fail every row,
        //       producing 0 staged rows even though the dump was
        //       complete. With LARAVEL_SOURCE_COLUMNS injection in
        //       execute_staging_insert() this should no longer
        //       happen, but the guard still catches it if a future
        //       schema change desyncs the column map.
        // We list every empty table on the notice (not just users)
        // and surface the first staging error if any were collected,
        // so the operator gets the full diagnostic in one round-trip
        // rather than fixing-and-retrying one table at a time.
        $missing = [];
        foreach (self::EXPECTED_NON_EMPTY_TABLES as $tname) {
            if (($stats[$tname] ?? 0) === 0) {
                $missing[] = $tname;
            }
        }
        if (!empty($missing)) {
            $hint = '';
            if (!empty($errors)) {
                // Prefix the first SQL error verbatim. For column-count
                // mismatches MySQL says "Column count doesn't match
                // value count" — that string makes the cause obvious.
                $hint = ' [' . __('First staging error', 'matrix-mlm') . ': ' . current($errors) . ']';
            }
            self::redirect_with_notice(
                'error',
                sprintf(
                    /* translators: 1: comma-separated list of empty table names, 2: optional first staging error */
                    __('The uploaded dump came in empty for these expected-non-empty tables: %1$s. Likely causes: (a) the export missed those tables (phpMyAdmin Custom Export silently drops unchecked tables), or (b) the dump\'s INSERT statements failed against staging. Re-run mysqldump with --no-create-info covering all twelve tables and re-upload.%2$s', 'matrix-mlm'),
                    implode(', ', $missing),
                    $hint
                )
            );
        }

        self::set_state([
            'status'     => 'staged',
            'phase'      => null,
            'cursor'     => 0,
            'stats'      => $stats,
            'errors'     => array_slice($errors, 0, 20), // cap to avoid bloated option blob
            'analysis'   => null,
            'created_at' => current_time('mysql'),
            'file'       => basename($path),
        ]);

        self::redirect_with_notice('success', sprintf(
            __('Staged successfully: %d users, %d plans, %d level rows. Run the dry-run analysis next.', 'matrix-mlm'),
            $stats['users'], $stats['plans'], $stats['levels']
        ));
    }

    /* ================================================================
     * handle_dryrun — pure read against staging
     * ================================================================ */

    public static function handle_dryrun() {
        self::require_admin();
        check_admin_referer('matrix_mlm_import_dryrun');

        global $wpdb;
        $u = self::staging_table('users');
        $p = self::staging_table('plans');
        $l = self::staging_table('levels');

        // Verify staging tables exist before querying them — a stale
        // state option (e.g. dropped manually from phpMyAdmin) would
        // otherwise crash with 'Table doesn't exist'.
        if ($wpdb->get_var("SHOW TABLES LIKE '$u'") !== $u) {
            self::redirect_with_notice('error', __('Staging tables not found. Upload the dump again.', 'matrix-mlm'));
        }

        $report = [];

        // --- Headline counts ---
        $report['total_users']    = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$u`");
        $report['active_users']   = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$u` WHERE status = 1");
        $report['banned_users']   = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$u` WHERE status = 0");
        $report['placed_users']   = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$u` WHERE position_id > 0");
        $report['unplaced_users'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$u` WHERE position_id = 0 AND id > 1");
        $report['no_plan_users']  = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$u` WHERE plan_id = 0");
        $report['kyc_verified']   = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$u` WHERE kv = 1");
        $report['email_verified'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$u` WHERE ev = 1");
        $report['mobile_verified']= (int) $wpdb->get_var("SELECT COUNT(*) FROM `$u` WHERE sv = 1");
        $report['twofa_enabled']  = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$u` WHERE ts = 1");
        $report['plans_count']    = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$p`");
        $report['levels_count']   = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$l`");

        // --- Issues we want the operator to acknowledge before commit ---
        $issues = [];

        // Email collisions with existing WP users (will be skipped).
        $email_collisions = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `$u` lv JOIN {$wpdb->users} wu ON wu.user_email = lv.email"
        );
        $report['email_collisions_existing'] = $email_collisions;
        if ($email_collisions > 0) {
            $issues[] = sprintf(
                __('%d Laravel emails already exist as WordPress users on this site. They will be skipped (existing WP user retained).', 'matrix-mlm'),
                $email_collisions
            );
        }

        // Username collisions.
        $u_collisions = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `$u` lv JOIN {$wpdb->users} wu ON wu.user_login = lv.username"
        );
        $report['username_collisions_existing'] = $u_collisions;
        if ($u_collisions > 0) {
            $issues[] = sprintf(
                __('%d Laravel usernames already exist on this site. They will be skipped.', 'matrix-mlm'),
                $u_collisions
            );
        }

        // Internal duplicate emails inside the dump itself.
        $dup_emails = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM (SELECT email FROM `$u` GROUP BY email HAVING COUNT(*) > 1) t"
        );
        $report['internal_duplicate_emails'] = $dup_emails;
        if ($dup_emails > 0) {
            $issues[] = sprintf(
                __('%d email addresses appear more than once in Laravel users — only the lowest id wins; later duplicates will be skipped.', 'matrix-mlm'),
                $dup_emails
            );
        }

        // Sponsor references to non-existent users.
        $orphan_sponsors = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `$u` lv WHERE lv.ref_by > 0 AND NOT EXISTS (SELECT 1 FROM `$u` lv2 WHERE lv2.id = lv.ref_by)"
        );
        $report['orphan_sponsors'] = $orphan_sponsors;
        if ($orphan_sponsors > 0) {
            $issues[] = sprintf(
                __('%d users reference a sponsor (ref_by) that does not exist in the dump — their sponsor will fall back to root during placement.', 'matrix-mlm'),
                $orphan_sponsors
            );
        }

        // Placed users whose position_id points to a missing user.
        $orphan_parents = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `$u` lv WHERE lv.position_id > 0 AND NOT EXISTS (SELECT 1 FROM `$u` lv2 WHERE lv2.id = lv.position_id)"
        );
        $report['orphan_parents'] = $orphan_parents;
        if ($orphan_parents > 0) {
            $issues[] = sprintf(
                __('%d placed users reference a parent (position_id) that does not exist — they will be re-placed via spillover instead.', 'matrix-mlm'),
                $orphan_parents
            );
        }

        // Multiple "root" candidates (ref_by=0 AND position_id=0). Lowest id wins.
        $multi_roots = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `$u` WHERE ref_by = 0 AND position_id = 0"
        );
        $report['root_candidates'] = $multi_roots;
        if ($multi_roots > 1) {
            $issues[] = sprintf(
                __('%d users have ref_by=0 AND position_id=0. The lowest id is treated as the tree root; the rest get spillover-placed under root.', 'matrix-mlm'),
                $multi_roots
            );
        }

        $report['issues'] = $issues;

        // PR 2 staging stats — gracefully omit when the operator
        // uploaded a PR 1-only dump (those tables won't exist in the
        // staging set at all).
        $pr2_tables = [
            'card_holders'     => 'Fintava virtual wallets',
            'cards'            => 'Fintava cards',
            'deposits'         => 'Deposits',
            'withdrawals'      => 'Withdrawals',
            'commissions'      => 'Commissions',
            'pins'             => 'E-pins',
            'support_tickets'  => 'Support tickets',
            'support_messages' => 'Ticket replies',
            'subscribers'      => 'Subscribers',
        ];
        $pr2_stats = [];
        foreach ($pr2_tables as $tname => $label) {
            $sn = self::staging_table($tname);
            if ($wpdb->get_var("SHOW TABLES LIKE '$sn'") === $sn) {
                $pr2_stats[$tname] = [
                    'label' => $label,
                    'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `$sn`"),
                ];
            }
        }
        $report['pr2_stats'] = $pr2_stats;

        // Sanity counts that depend on the user staging table (already
        // checked exists above) but are PR 2 specific:
        if (!empty($pr2_stats['card_holders'])) {
            $sch = self::staging_table('card_holders');
            $report['fintava_with_wallet_id'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `$sch` WHERE wallet_id IS NOT NULL AND wallet_id <> ''"
            );
            $report['fintava_with_account']   = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `$sch` WHERE account_number IS NOT NULL AND account_number <> ''"
            );
        }

        self::set_state([
            'status'   => 'analyzed',
            'analysis' => $report,
        ]);

        self::redirect_with_notice('success', __('Dry-run analysis complete. Review the report below before committing.', 'matrix-mlm'));
    }

    /* ================================================================
     * handle_commit_start — flips the state to "committing"
     * ================================================================ */

    public static function handle_commit_start() {
        self::require_admin();
        check_admin_referer('matrix_mlm_import_commit');

        $state = self::get_state();
        if ($state['status'] !== 'analyzed' && $state['status'] !== 'staged') {
            self::redirect_with_notice('error', __('Run the dry-run analysis first.', 'matrix-mlm'));
        }

        self::set_state([
            'status'    => 'committing',
            'phase'     => 'plans',
            'cursor'    => 0,
            'errors'    => [],
            'stats'     => array_merge($state['stats'] ?? [], [
                // Per-phase counters get filled in as chunks complete.
            ]),
            'started_at' => current_time('mysql'),
        ]);

        self::redirect_with_notice('success', __('Commit started. Progress will appear below — keep this page open.', 'matrix-mlm'));
    }

    /* ================================================================
     * handle_commit_chunk — AJAX-driven phase advancement
     * ================================================================ */

    public static function handle_commit_chunk() {
        check_ajax_referer('matrix_mlm_import_chunk', 'nonce');
        if (!current_user_can('manage_matrix_settings')) {
            wp_send_json_error(['message' => __('Unauthorized', 'matrix-mlm')]);
        }

        // Each chunk is intentionally small enough to finish in well
        // under a default 30s shared-hosting timeout, but bump anyway
        // in case the underlying SQL is slow on a packed table.
        @set_time_limit(60);

        $state = self::get_state();
        if ($state['status'] !== 'committing') {
            wp_send_json_error(['message' => __('No commit is currently in progress.', 'matrix-mlm')]);
        }

        $phase  = $state['phase'];
        $cursor = (int) ($state['cursor'] ?? 0);

        try {
            $result = self::process_chunk($phase, $cursor);
        } catch (Throwable $e) {
            // Persist the error and halt — the operator can hit Reset
            // and re-upload, or in PR 2 we'll add a "resume from
            // failed phase" button.
            $state['errors'][] = '[' . $phase . '] ' . $e->getMessage();
            $state['status']   = 'failed';
            update_option(self::OPT_STATE, $state);
            wp_send_json_error([
                'message' => $e->getMessage(),
                'phase'   => $phase,
                'trace'   => $e->getTraceAsString(),
            ]);
        }

        // Advance state from chunk result.
        $state['cursor'] = $result['cursor_after'];
        if (!isset($state['stats'][$phase])) {
            $state['stats'][$phase] = 0;
        }
        $state['stats'][$phase] += $result['done_in_chunk'];

        if ($result['advance_phase']) {
            $state['phase']  = $result['next_phase'];
            $state['cursor'] = 0;
            if ($result['next_phase'] === null) {
                $state['status']       = 'completed';
                $state['completed_at'] = current_time('mysql');
                update_option(self::OPT_LAST_RESULT, [
                    'finished_at' => current_time('mysql'),
                    'stats'       => $state['stats'],
                ]);
            }
        }

        update_option(self::OPT_STATE, $state);

        wp_send_json_success([
            'phase'           => $phase,
            'next_phase'      => $state['phase'],
            'status'          => $state['status'],
            'cursor'          => $state['cursor'],
            'done_in_chunk'   => $result['done_in_chunk'],
            'total_for_phase' => $result['total_for_phase'],
            'message'         => $result['message'] ?? '',
            'stats'           => $state['stats'],
        ]);
    }

    /** Phase order. Each phase's process_*() method handles one chunk. */
    private static function next_phase($current) {
        $order = [
            // PR 1: foundation — users, tree, plans, level_commission JSON.
            'plans',
            'levels',
            'users',
            'positions_placed',
            'positions_spill',
            'parents',
            'recalc_levels',
            'recalc_downline',
            // PR 3: sponsor/referral graph backfill. Sits between the
            // tree recompute (which only touches matrix_positions
            // structure, not the sponsor pointer) and the PR 2 block
            // (which doesn't depend on referred_by today, but if any
            // future PR 2 phase ever did, having the pointer set
            // before they run keeps the dependency direction sane).
            'referrals',
            // PR 2: financials, Fintava wallets/cards, ticketing, subscribers.
            // fintava_wallets must run before fintava_cards (cards JOIN
            // staging card_holders to resolve the destination wallet) and
            // before bank_code_backfill (which only touches rows we just
            // inserted). The ticket_messages phase needs tickets done
            // first because it joins through staging support_tickets to
            // resolve wp_ticket_id. The remaining order is operationally
            // independent — we just front-load Fintava since that's the
            // pre-condition for users seeing a balance on first login.
            'fintava_wallets',
            'fintava_cards',
            'deposits',
            'withdrawals',
            'commissions',
            'epins',
            'tickets',
            'ticket_messages',
            'subscribers',
            'bank_code_backfill',
        ];
        $i = array_search($current, $order, true);
        return ($i !== false && $i + 1 < count($order)) ? $order[$i + 1] : null;
    }

    /** Dispatch one chunk to the right phase handler. */
    private static function process_chunk($phase, $cursor) {
        switch ($phase) {
            case 'plans':              return self::process_plans($cursor);
            case 'levels':             return self::process_levels($cursor);
            case 'users':              return self::process_users($cursor);
            case 'positions_placed':   return self::process_positions_placed($cursor);
            case 'positions_spill':    return self::process_positions_spillover($cursor);
            case 'parents':            return self::process_parents($cursor);
            case 'recalc_levels':      return self::process_recalc_levels($cursor);
            case 'recalc_downline':    return self::process_recalc_downline($cursor);
            // PR 3 phase
            case 'referrals':          return self::process_referrals($cursor);
            // PR 2 phases
            case 'fintava_wallets':    return self::process_fintava_wallets($cursor);
            case 'fintava_cards':      return self::process_fintava_cards($cursor);
            case 'deposits':           return self::process_deposits($cursor);
            case 'withdrawals':        return self::process_withdrawals($cursor);
            case 'commissions':        return self::process_commissions($cursor);
            case 'epins':              return self::process_epins($cursor);
            case 'tickets':            return self::process_tickets($cursor);
            case 'ticket_messages':    return self::process_ticket_messages($cursor);
            case 'subscribers':        return self::process_subscribers($cursor);
            case 'bank_code_backfill': return self::process_bank_code_backfill($cursor);
            default:
                throw new RuntimeException('Unknown commit phase: ' . $phase);
        }
    }

    /* ================================================================
     * Phase: plans
     *
     * Insert one row into wp_matrix_plans per Laravel plan, then
     * collapse the matching wp_matrix_lv_levels rows into the
     * level_commission JSON column. Width/depth come from the
     * Laravel general_settings (3×9 in the source dump) — we
     * hard-code from constants here since PR 1 doesn't import
     * general_settings.
     * ================================================================ */

    private static function process_plans($cursor) {
        global $wpdb;
        $sp = self::staging_table('plans');

        $rows  = $wpdb->get_results("SELECT * FROM `$sp` WHERE wp_plan_id IS NULL ORDER BY id ASC LIMIT " . self::CHUNK_PLANS);
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$sp`");

        $plans_table = $wpdb->prefix . 'matrix_plans';

        foreach ($rows as $row) {
            // De-dupe by plan name. If an admin already created a plan
            // with this name on the WP side (e.g. they pre-seeded
            // before running the importer), reuse it — don't create
            // a phantom duplicate that would split commission settings
            // across two records.
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM `$plans_table` WHERE name = %s LIMIT 1",
                $row->name
            ));
            if ($existing) {
                $wp_plan_id = (int) $existing->id;
            } else {
                $wpdb->insert($plans_table, [
                    'name'                => $row->name,
                    'width'               => self::DEFAULT_WIDTH,
                    'depth'               => self::DEFAULT_DEPTH,
                    'price'               => $row->price,
                    'joining_fee'         => 0,
                    'level_commission'    => '{}', // populated in next phase
                    'referral_commission' => $row->referral_bonus,
                    'matrix_completion_bonus' => 0,
                    'max_members'         => 0,
                    'status'              => $row->status ? 'active' : 'inactive',
                    'description'         => null,
                    'created_at'          => $row->created_at ?: current_time('mysql'),
                ]);
                $wp_plan_id = (int) $wpdb->insert_id;
            }
            $wpdb->update($sp, ['wp_plan_id' => $wp_plan_id], ['id' => $row->id]);
        }

        $remaining = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$sp` WHERE wp_plan_id IS NULL");
        return [
            'done_in_chunk'   => count($rows),
            'total_for_phase' => $total,
            'cursor_after'    => 0,
            'advance_phase'   => $remaining === 0,
            'next_phase'      => self::next_phase('plans'),
            'message'         => sprintf('Imported %d plans', count($rows)),
        ];
    }

    /* ================================================================
     * Phase: levels
     *
     * Collapse Laravel's per-(plan,level) rows in `levels` into the
     * level_commission JSON object on the matching matrix_plans row.
     * Done as a single pass per plan, so the chunk size is "plans
     * processed", not "level rows processed".
     * ================================================================ */

    private static function process_levels($cursor) {
        global $wpdb;
        $sp = self::staging_table('plans');
        $sl = self::staging_table('levels');

        // Find plans that have a wp_plan_id but whose level_commission
        // hasn't been populated yet. We mark "populated" implicitly by
        // checking the matrix_plans row's level_commission == '{}'
        // sentinel we wrote in the previous phase, then updating it
        // to the real JSON here.
        $plans_table = $wpdb->prefix . 'matrix_plans';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT sp.id AS lv_plan_id, sp.wp_plan_id
             FROM `$sp` sp
             INNER JOIN `$plans_table` p ON p.id = sp.wp_plan_id
             WHERE p.level_commission = %s
             ORDER BY sp.id ASC
             LIMIT " . self::CHUNK_LEVELS,
            '{}'
        ));
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `$sp` sp INNER JOIN `$plans_table` p ON p.id = sp.wp_plan_id WHERE p.level_commission = '{}'"
        );

        foreach ($rows as $row) {
            $level_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT level, amount FROM `$sl` WHERE plan_id = %d ORDER BY level ASC",
                $row->lv_plan_id
            ));
            $map = [];
            foreach ($level_rows as $lr) {
                // MatrixPro's plan UI expects 1-indexed levels in the
                // JSON object, matching the form input shape (see
                // class-matrix-admin-plans.php:168). Cast amount to
                // float so round-trips through json_decode produce
                // the same shape the admin form would have written.
                $map[(int) $lr->level] = (float) $lr->amount;
            }
            $wpdb->update(
                $plans_table,
                ['level_commission' => wp_json_encode($map)],
                ['id' => (int) $row->wp_plan_id]
            );
        }

        $remaining = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `$sp` sp INNER JOIN `$plans_table` p ON p.id = sp.wp_plan_id WHERE p.level_commission = '{}'"
        );
        return [
            'done_in_chunk'   => count($rows),
            'total_for_phase' => $total,
            'cursor_after'    => 0,
            'advance_phase'   => $remaining === 0,
            'next_phase'      => self::next_phase('levels'),
            'message'         => sprintf('Populated level_commission for %d plans', count($rows)),
        ];
    }

    /* ================================================================
     * Phase: users
     *
     * For each Laravel user, create one wp_users row + one
     * matrix_user_meta row, then write the new wp_user_id back into
     * the staging table for downstream phases to JOIN against.
     *
     * Skip-with-warning when:
     *   - The email already exists in wp_users (existing user wins)
     *   - The username already exists in wp_users (existing user wins)
     *   - The Laravel email is internally duplicated (lowest-id wins)
     * ================================================================ */

    private static function process_users($cursor) {
        global $wpdb;
        $su = self::staging_table('users');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `$su` WHERE id > %d AND wp_user_id IS NULL AND import_status IS NULL ORDER BY id ASC LIMIT " . self::CHUNK_USERS,
            $cursor
        ));
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$su`");

        if (empty($rows)) {
            // No more rows to process — advance.
            return [
                'done_in_chunk'   => 0,
                'total_for_phase' => $total,
                'cursor_after'    => 0,
                'advance_phase'   => true,
                'next_phase'      => self::next_phase('users'),
                'message'         => 'Users phase complete',
            ];
        }

        $done = 0;
        $last_id = $cursor;
        foreach ($rows as $row) {
            $last_id = (int) $row->id;

            // Skip rows that collide with an existing WP user.
            $existing_by_email = email_exists($row->email);
            $existing_by_login = username_exists($row->username);
            if ($existing_by_email || $existing_by_login) {
                $wpdb->update(
                    $su,
                    [
                        'wp_user_id'    => (int) ($existing_by_email ?: $existing_by_login),
                        'import_status' => 'skipped_collision',
                        'import_error'  => $existing_by_email
                            ? 'Email already exists in wp_users'
                            : 'Username already exists in wp_users',
                    ],
                    ['id' => $row->id]
                );
                $done++;
                continue;
            }

            // Internal duplicate email check (within Laravel itself).
            $internal_dupe = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `$su` WHERE email = %s AND id < %d",
                $row->email, $row->id
            ));
            if ($internal_dupe > 0) {
                $wpdb->update($su, [
                    'import_status' => 'skipped_internal_dupe',
                    'import_error'  => 'Earlier Laravel row uses the same email',
                ], ['id' => $row->id]);
                $done++;
                continue;
            }

            // Insert directly into wp_users — wp_insert_user() would
            // re-hash the password and we explicitly want to preserve
            // the bcrypt $2y$ hash so members can log in with their
            // existing passwords. The bcrypt-aware check_password
            // filter (registered in init() above) handles verification.
            $display_name = trim(($row->firstname ?? '') . ' ' . ($row->lastname ?? ''));
            if ($display_name === '') {
                $display_name = $row->username;
            }
            $insert = $wpdb->insert($wpdb->users, [
                'user_login'          => $row->username,
                'user_pass'           => $row->password, // bcrypt, preserved verbatim
                'user_nicename'       => sanitize_title($row->username),
                'user_email'          => $row->email,
                'user_registered'     => $row->created_at ?: current_time('mysql'),
                'display_name'        => $display_name,
                'user_status'         => 0,
                'user_activation_key' => '',
            ]);
            if ($insert === false) {
                $wpdb->update($su, [
                    'import_status' => 'failed',
                    'import_error'  => 'wp_users insert failed: ' . $wpdb->last_error,
                ], ['id' => $row->id]);
                continue;
            }
            $new_user_id = (int) $wpdb->insert_id;

            // Subscriber-role meta. Matches what wp_insert_user() would
            // do via WP_User::set_role('subscriber') so the user looks
            // exactly the same as a freshly-registered WP account.
            $wpdb->insert($wpdb->usermeta, [
                'user_id'    => $new_user_id,
                'meta_key'   => $wpdb->prefix . 'capabilities',
                'meta_value' => serialize(['subscriber' => true]),
            ]);
            $wpdb->insert($wpdb->usermeta, [
                'user_id'    => $new_user_id,
                'meta_key'   => $wpdb->prefix . 'user_level',
                'meta_value' => '0',
            ]);
            update_user_meta($new_user_id, 'first_name', $row->firstname);
            update_user_meta($new_user_id, 'last_name',  $row->lastname);
            update_user_meta($new_user_id, 'nickname',   $row->username);

            // matrix_user_meta — the plugin's per-user record. status
            // mapping: Laravel 1=active → 'active', 0=banned → 'banned'.
            //
            // referral_code is generated here, not lazily later. The
            // schema enforces UNIQUE on referral_code and the public
            // referral link / "Manage Users" admin column both read
            // from this column directly — leaving NULL meant every
            // imported member showed an empty Referral Code in the
            // admin and couldn't share a referral link until they
            // re-registered. generate_unique_referral_code() retries
            // on collision against the UNIQUE index so this is safe
            // to call in the import loop.
            $wpdb->insert($wpdb->prefix . 'matrix_user_meta', [
                'user_id'            => $new_user_id,
                'balance'            => $row->balance,
                'referral_code'      => Matrix_MLM_User::generate_unique_referral_code($new_user_id),
                'referred_by'        => null, // resolved in a later phase via map
                'phone'              => trim(($row->country_code ?? '') . ($row->mobile ?? '')) ?: null,
                'address'            => $row->address ?: null,
                'city'               => null,
                'state'              => null,
                'country'            => null,
                'zip_code'           => null,
                'two_factor_enabled' => (int) $row->ts,
                'two_factor_secret'  => $row->tsc ?: null,
                'email_verified'     => (int) $row->ev,
                'sms_verified'       => (int) $row->sv,
                'kyc_verified'       => (int) $row->kv,
                'status'             => $row->status ? 'active' : 'banned',
                'created_at'         => $row->created_at ?: current_time('mysql'),
            ]);

            $wpdb->update($su, [
                'wp_user_id'    => $new_user_id,
                'import_status' => 'imported',
            ], ['id' => $row->id]);

            $done++;
        }

        // Cursor is the highest Laravel id we processed in this chunk.
        // Next call resumes after that, so a chunk that hit nothing but
        // skip_collision rows still advances rather than re-scanning.
        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$su` WHERE id > %d AND wp_user_id IS NULL AND import_status IS NULL",
            $last_id
        ));

        return [
            'done_in_chunk'   => $done,
            'total_for_phase' => $total,
            'cursor_after'    => $last_id,
            'advance_phase'   => $remaining === 0,
            'next_phase'      => self::next_phase('users'),
            'message'         => sprintf('Processed %d users (cursor at id %d)', $done, $last_id),
        ];
    }

    /* ================================================================
     * Phase: positions_placed
     *
     * Insert one matrix_positions row per Laravel user that had a
     * non-zero position_id (i.e. was placed in the tree on the
     * Laravel side). parent_id is left NULL here — the parents
     * phase resolves it via the staging map after every position
     * exists. sponsor_id is set immediately because it's a direct
     * lookup against the user staging table's wp_user_id column.
     * ================================================================ */

    private static function process_positions_placed($cursor) {
        global $wpdb;
        $su = self::staging_table('users');
        $pt = $wpdb->prefix . 'matrix_positions';
        $sp = self::staging_table('plans');

        // Only consider Laravel users that:
        //   - successfully imported as wp_users (have wp_user_id)
        //   - had a real position in Laravel (position_id > 0)
        //   - haven't been placed yet (wp_position_id IS NULL)
        //   - have a known plan in the staging plans table
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT u.*
             FROM `$su` u
             WHERE u.id > %d
               AND u.wp_user_id IS NOT NULL
               AND u.position_id > 0
               AND u.wp_position_id IS NULL
             ORDER BY u.id ASC
             LIMIT " . self::CHUNK_POSITIONS,
            $cursor
        ));
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `$su` WHERE wp_user_id IS NOT NULL AND position_id > 0"
        );

        if (empty($rows)) {
            return [
                'done_in_chunk'   => 0,
                'total_for_phase' => $total,
                'cursor_after'    => 0,
                'advance_phase'   => true,
                'next_phase'      => self::next_phase('positions_placed'),
                'message'         => 'Positions (placed) phase complete',
            ];
        }

        $done = 0;
        $last_id = $cursor;
        foreach ($rows as $row) {
            $last_id = (int) $row->id;

            // Resolve the WP plan id via the staging plans table.
            $wp_plan_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT wp_plan_id FROM `$sp` WHERE id = %d",
                (int) $row->plan_id
            ));
            if (!$wp_plan_id) {
                // Skip silently — user has no plan or plan wasn't imported.
                $wpdb->update($su, [
                    'import_error' => 'No matching plan for position insert',
                ], ['id' => $row->id]);
                continue;
            }

            // Idempotency guard: if the user already has a position in
            // this plan (re-run after a previous successful commit, or
            // a manual placement done before the import), reuse the
            // existing row instead of inserting a duplicate. The
            // staging.wp_position_id pointer is what every downstream
            // phase joins through, so all that matters here is that it
            // ends up pointing at *some* position row for this user.
            $existing_pos = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM `$pt` WHERE user_id = %d AND plan_id = %d ORDER BY id ASC LIMIT 1",
                (int) $row->wp_user_id, $wp_plan_id
            ));
            if ($existing_pos) {
                $wpdb->update($su, ['wp_position_id' => (int) $existing_pos], ['id' => $row->id]);
                $done++;
                continue;
            }

            // Resolve sponsor wp_user_id (NULL if ref_by is 0 or missing).
            $sponsor_wp_id = null;
            if ((int) $row->ref_by > 0) {
                $sponsor_wp_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT wp_user_id FROM `$su` WHERE id = %d",
                    (int) $row->ref_by
                ));
                $sponsor_wp_id = $sponsor_wp_id ? (int) $sponsor_wp_id : null;
            }

            $wpdb->insert($pt, [
                'user_id'        => (int) $row->wp_user_id,
                'plan_id'        => $wp_plan_id,
                'sponsor_id'     => $sponsor_wp_id,
                'parent_id'      => null, // resolved in `parents` phase
                'position'       => (int) $row->position,
                'level'          => 1,    // recomputed in recalc_levels
                'left_count'     => 0,
                'right_count'    => 0,
                'total_downline' => 0,    // recomputed in recalc_downline
                'status'         => 'active',
                'joined_at'      => $row->created_at ?: current_time('mysql'),
            ]);
            $new_pos_id = (int) $wpdb->insert_id;

            $wpdb->update($su, ['wp_position_id' => $new_pos_id], ['id' => $row->id]);
            $done++;
        }

        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$su` WHERE id > %d AND wp_user_id IS NOT NULL AND position_id > 0 AND wp_position_id IS NULL",
            $last_id
        ));

        return [
            'done_in_chunk'   => $done,
            'total_for_phase' => $total,
            'cursor_after'    => $last_id,
            'advance_phase'   => $remaining === 0,
            'next_phase'      => self::next_phase('positions_placed'),
            'message'         => sprintf('Placed %d positions (cursor at id %d)', $done, $last_id),
        ];
    }

    /* ================================================================
     * Phase: positions_spill
     *
     * Spillover-place every imported user that was unplaced on the
     * Laravel side (position_id = 0, but ref_by > 0). For each:
     *   1. Find the sponsor's matrix_positions row (in same plan).
     *   2. If sponsor has < width children, place directly under sponsor.
     *   3. Otherwise BFS down the sponsor's subtree finding the first
     *      open slot (shallowest-first, leftmost wins on ties).
     *   4. If even the sponsor's whole subtree is full, fall back to
     *      placing under root with the same BFS.
     *
     * Process in id ASC so an unplaced user whose sponsor is also
     * unplaced finds their sponsor already placed by the time we get
     * to them (sponsors typically have lower ids than referees in an
     * MLM signup chain).
     * ================================================================ */

    private static function process_positions_spillover($cursor) {
        global $wpdb;
        $su = self::staging_table('users');
        $pt = $wpdb->prefix . 'matrix_positions';
        $sp = self::staging_table('plans');

        // Eligible: imported user, no Laravel placement, no current
        // wp_position_id. Process id ASC for the dependency-order
        // reason above. Skip the root user (id=1, ref_by=0,
        // position_id=0 — it doesn't get a position because it IS the
        // tree, but we still want it represented; handled below by
        // creating a position for any user with ref_by=0 that maps to
        // a non-orphan).
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT u.*
             FROM `$su` u
             WHERE u.id > %d
               AND u.wp_user_id IS NOT NULL
               AND u.position_id = 0
               AND u.wp_position_id IS NULL
             ORDER BY u.id ASC
             LIMIT " . self::CHUNK_POSITIONS,
            $cursor
        ));
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `$su` WHERE wp_user_id IS NOT NULL AND position_id = 0"
        );

        if (empty($rows)) {
            return [
                'done_in_chunk'   => 0,
                'total_for_phase' => $total,
                'cursor_after'    => 0,
                'advance_phase'   => true,
                'next_phase'      => self::next_phase('positions_spill'),
                'message'         => 'Spillover phase complete',
            ];
        }

        $done    = 0;
        $last_id = $cursor;
        foreach ($rows as $row) {
            $last_id = (int) $row->id;

            // Determine target plan: if Laravel user had no plan
            // (plan_id=0), pick the lowest active plan as a fallback
            // so they still occupy a tree slot.
            $wp_plan_id = 0;
            if ((int) $row->plan_id > 0) {
                $wp_plan_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT wp_plan_id FROM `$sp` WHERE id = %d",
                    (int) $row->plan_id
                ));
            }
            if (!$wp_plan_id) {
                $wp_plan_id = (int) $wpdb->get_var(
                    "SELECT id FROM {$wpdb->prefix}matrix_plans WHERE status = 'active' ORDER BY id ASC LIMIT 1"
                );
            }
            if (!$wp_plan_id) {
                // Truly nothing to place into — record and skip.
                $wpdb->update($su, [
                    'import_error' => 'No active plan to spillover into',
                ], ['id' => $row->id]);
                continue;
            }

            // Idempotency guard: if this user already has a position in
            // the target plan (committed in a prior run, or via the
            // placed phase already in this run), pick that up instead
            // of double-inserting.
            $existing_pos = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM `$pt` WHERE user_id = %d AND plan_id = %d ORDER BY id ASC LIMIT 1",
                (int) $row->wp_user_id, $wp_plan_id
            ));
            if ($existing_pos) {
                $wpdb->update($su, ['wp_position_id' => (int) $existing_pos], ['id' => $row->id]);
                $done++;
                continue;
            }

            // Sponsor lookup.
            $sponsor_wp_id = null;
            if ((int) $row->ref_by > 0) {
                $sponsor_wp_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT wp_user_id FROM `$su` WHERE id = %d",
                    (int) $row->ref_by
                ));
                $sponsor_wp_id = $sponsor_wp_id ? (int) $sponsor_wp_id : null;
            }

            // Sponsor's matrix_positions row (same plan). If the
            // sponsor has no position in this plan, fall back to root.
            $sponsor_position_id = null;
            if ($sponsor_wp_id) {
                $sponsor_position_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM `$pt` WHERE user_id = %d AND plan_id = %d ORDER BY id ASC LIMIT 1",
                    $sponsor_wp_id, $wp_plan_id
                ));
                $sponsor_position_id = $sponsor_position_id ? (int) $sponsor_position_id : null;
            }

            // BFS root: sponsor's position, or the plan's root position
            // if no sponsor was resolvable.
            $bfs_root = $sponsor_position_id;
            if (!$bfs_root) {
                $bfs_root = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM `$pt` WHERE plan_id = %d AND parent_id IS NULL ORDER BY id ASC LIMIT 1",
                    $wp_plan_id
                ));
            }
            if (!$bfs_root) {
                // No root yet for this plan — make this user the root.
                // (Shouldn't normally happen because the placed-phase
                // creates the tree before we get here.)
                $wpdb->insert($pt, [
                    'user_id'        => (int) $row->wp_user_id,
                    'plan_id'        => $wp_plan_id,
                    'sponsor_id'     => $sponsor_wp_id,
                    'parent_id'      => null,
                    'position'       => 0,
                    'level'          => 1,
                    'left_count'     => 0,
                    'right_count'    => 0,
                    'total_downline' => 0,
                    'status'         => 'active',
                    'joined_at'      => $row->created_at ?: current_time('mysql'),
                ]);
                $wpdb->update($su, [
                    'wp_position_id' => (int) $wpdb->insert_id,
                ], ['id' => $row->id]);
                $done++;
                continue;
            }

            $slot = self::find_open_slot_bfs($wp_plan_id, $bfs_root);
            if (!$slot) {
                // Sponsor's whole subtree is full — try root as a final
                // fallback. (For 12k users in 3×9, this branch should
                // be unreachable, but coding defensively.)
                $root = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM `$pt` WHERE plan_id = %d AND parent_id IS NULL ORDER BY id ASC LIMIT 1",
                    $wp_plan_id
                ));
                if ($root && $root !== $bfs_root) {
                    $slot = self::find_open_slot_bfs($wp_plan_id, $root);
                }
            }
            if (!$slot) {
                $wpdb->update($su, [
                    'import_error' => 'No open slot found for spillover',
                ], ['id' => $row->id]);
                continue;
            }

            $wpdb->insert($pt, [
                'user_id'        => (int) $row->wp_user_id,
                'plan_id'        => $wp_plan_id,
                'sponsor_id'     => $sponsor_wp_id,
                'parent_id'      => (int) $slot['parent_id'],
                'position'       => (int) $slot['position'],
                'level'          => 1, // recomputed in recalc_levels
                'left_count'     => 0,
                'right_count'    => 0,
                'total_downline' => 0,
                'status'         => 'active',
                'joined_at'      => $row->created_at ?: current_time('mysql'),
            ]);
            $wpdb->update($su, [
                'wp_position_id' => (int) $wpdb->insert_id,
            ], ['id' => $row->id]);
            $done++;
        }

        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$su` WHERE id > %d AND wp_user_id IS NOT NULL AND position_id = 0 AND wp_position_id IS NULL",
            $last_id
        ));

        return [
            'done_in_chunk'   => $done,
            'total_for_phase' => $total,
            'cursor_after'    => $last_id,
            'advance_phase'   => $remaining === 0,
            'next_phase'      => self::next_phase('positions_spill'),
            'message'         => sprintf('Spillover-placed %d users (cursor at id %d)', $done, $last_id),
        ];
    }

    /**
     * BFS from $root_position_id looking for the first open slot.
     * Returns ['parent_id' => int, 'position' => int] or null when
     * the entire subtree (down to DEFAULT_DEPTH) is saturated.
     */
    private static function find_open_slot_bfs($plan_id, $root_position_id) {
        global $wpdb;
        $pt    = $wpdb->prefix . 'matrix_positions';
        $width = self::DEFAULT_WIDTH;
        $depth = self::DEFAULT_DEPTH;

        $queue = [[$root_position_id, 1]]; // [position_id, current_depth]
        $seen  = [];
        while (!empty($queue)) {
            [$pid, $d] = array_shift($queue);
            if (isset($seen[$pid])) continue;
            $seen[$pid] = true;

            // How many children does this node already have?
            $child_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `$pt` WHERE parent_id = %d",
                $pid
            ));
            if ($child_count < $width) {
                // Found a slot. Position index = next free slot
                // (1-indexed to match Laravel's existing convention).
                return ['parent_id' => $pid, 'position' => $child_count + 1];
            }

            // Full at this level — descend into children if depth allows.
            if ($d >= $depth) {
                continue;
            }
            $children = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM `$pt` WHERE parent_id = %d ORDER BY position ASC, id ASC",
                $pid
            ));
            foreach ($children as $cid) {
                $queue[] = [(int) $cid, $d + 1];
            }
        }
        return null;
    }

    /* ================================================================
     * Phase: parents
     *
     * Resolve the parent_id column for placed positions by joining
     * the staging table on itself: a Laravel user's position_id
     * points to the parent user's Laravel id; we look that up to get
     * the parent's wp_position_id and write it back.
     *
     * Spillover-placed users already have parent_id set during the
     * spillover phase (they don't have a Laravel parent to resolve
     * against), so this only touches placed users.
     * ================================================================ */

    private static function process_parents($cursor) {
        global $wpdb;
        $su = self::staging_table('users');
        $pt = $wpdb->prefix . 'matrix_positions';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT u.id            AS lv_id,
                    u.position_id   AS lv_parent_id,
                    u.wp_position_id AS my_pos
             FROM `$su` u
             INNER JOIN `$pt` p ON p.id = u.wp_position_id
             WHERE u.id > %d
               AND u.position_id > 0
               AND u.wp_position_id IS NOT NULL
               AND p.parent_id IS NULL
             ORDER BY u.id ASC
             LIMIT " . self::CHUNK_PARENTS,
            $cursor
        ));
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `$su` u INNER JOIN `$pt` p ON p.id = u.wp_position_id WHERE u.position_id > 0 AND p.parent_id IS NULL"
        );

        if (empty($rows)) {
            return [
                'done_in_chunk'   => 0,
                'total_for_phase' => $total,
                'cursor_after'    => 0,
                'advance_phase'   => true,
                'next_phase'      => self::next_phase('parents'),
                'message'         => 'Parents phase complete',
            ];
        }

        $done = 0;
        $last_id = $cursor;
        foreach ($rows as $row) {
            $last_id = (int) $row->lv_id;
            // Map Laravel parent user_id -> wp_position_id of that user.
            $parent_wp_position = $wpdb->get_var($wpdb->prepare(
                "SELECT wp_position_id FROM `$su` WHERE id = %d",
                (int) $row->lv_parent_id
            ));
            if (!$parent_wp_position) {
                // Orphan parent (already flagged in dry-run). Leave
                // parent_id NULL — the position becomes a sub-root.
                // The recalc_levels phase treats NULL parent_id as
                // level=1 anyway, so this won't break the rest of
                // the import.
                continue;
            }
            $wpdb->update($pt, [
                'parent_id' => (int) $parent_wp_position,
            ], ['id' => (int) $row->my_pos]);
            $done++;
        }

        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$su` u INNER JOIN `$pt` p ON p.id = u.wp_position_id
             WHERE u.id > %d AND u.position_id > 0 AND p.parent_id IS NULL",
            $last_id
        ));
        return [
            'done_in_chunk'   => $done,
            'total_for_phase' => $total,
            'cursor_after'    => $last_id,
            'advance_phase'   => $remaining === 0,
            'next_phase'      => self::next_phase('parents'),
            'message'         => sprintf('Resolved %d parent_id refs', $done),
        ];
    }

    /* ================================================================
     * Phase: recalc_levels
     *
     * Walk the tree top-down setting level = parent.level + 1.
     * Implemented as bounded iteration: at most DEFAULT_DEPTH passes
     * are needed because every position is at most DEFAULT_DEPTH
     * hops from the root. Each pass sets level on positions whose
     * parent already has a final level, so by pass N every level-N
     * row is correct.
     * ================================================================ */

    private static function process_recalc_levels($cursor) {
        global $wpdb;
        $pt = $wpdb->prefix . 'matrix_positions';

        // Mark roots (parent_id IS NULL) as level=1 in one shot.
        if ($cursor === 0) {
            $wpdb->query("UPDATE `$pt` SET level = 1 WHERE parent_id IS NULL");
        }

        // Single iteration sets levels for every direct child of
        // already-resolved nodes. We track "pass" via cursor and
        // advance up to DEFAULT_DEPTH passes; if a pass updates
        // zero rows we know the tree is fully labelled and we
        // can finish early.
        $updated = $wpdb->query("
            UPDATE `$pt` p
            INNER JOIN `$pt` parent ON parent.id = p.parent_id
            SET p.level = parent.level + 1
            WHERE p.parent_id IS NOT NULL
              AND (p.level = 1 OR p.level <> parent.level + 1)
        ");

        $next_cursor = $cursor + 1;
        $advance = ($updated === 0) || ($next_cursor >= self::DEFAULT_DEPTH + 2);
        return [
            'done_in_chunk'   => (int) $updated,
            'total_for_phase' => self::DEFAULT_DEPTH,
            'cursor_after'    => $next_cursor,
            'advance_phase'   => $advance,
            'next_phase'      => self::next_phase('recalc_levels'),
            'message'         => sprintf('Level pass %d updated %d rows', $cursor + 1, (int) $updated),
        ];
    }

    /* ================================================================
     * Phase: recalc_downline
     *
     * Bottom-up walk: for each level from deepest to shallowest,
     * total_downline = SUM(child.total_downline + 1) over its
     * direct children. One UPDATE per level pass (so DEFAULT_DEPTH
     * passes total) and we're done.
     * ================================================================ */

    private static function process_recalc_downline($cursor) {
        global $wpdb;
        $pt = $wpdb->prefix . 'matrix_positions';

        // Reset on the first pass so re-running the importer doesn't
        // accumulate stale counts.
        if ($cursor === 0) {
            $wpdb->query("UPDATE `$pt` SET total_downline = 0");
        }

        // Walk levels from deepest down. We don't actually know the
        // max level in advance, so iterate until an UPDATE touches
        // zero rows, capped at 2*DEFAULT_DEPTH for safety.
        $current_level = self::DEFAULT_DEPTH + 1 - $cursor;
        if ($current_level < 1) {
            return [
                'done_in_chunk'   => 0,
                'total_for_phase' => self::DEFAULT_DEPTH + 1,
                'cursor_after'    => $cursor + 1,
                'advance_phase'   => true,
                'next_phase'      => self::next_phase('recalc_downline'),
                'message'         => 'Downline recompute complete',
            ];
        }

        // Set every position at level $current_level - 1's
        // total_downline based on its children's totals + 1 per child.
        $updated = $wpdb->query($wpdb->prepare("
            UPDATE `$pt` parent
            SET parent.total_downline = (
                SELECT COALESCE(SUM(child.total_downline + 1), 0)
                FROM (SELECT id, parent_id, total_downline FROM `$pt`) child
                WHERE child.parent_id = parent.id
            )
            WHERE parent.level = %d
        ", $current_level - 1));

        return [
            'done_in_chunk'   => (int) $updated,
            'total_for_phase' => self::DEFAULT_DEPTH + 1,
            'cursor_after'    => $cursor + 1,
            'advance_phase'   => false,
            'next_phase'      => self::next_phase('recalc_downline'),
            'message'         => sprintf('Downline pass at level %d updated %d rows', $current_level, (int) $updated),
        ];
    }

    /* ================================================================
     * Phase: referrals
     *
     * Translate Laravel ref_by (sponsor) pointers into WP-side
     * referred_by / sponsor_id values. Joins staging users on
     * themselves: for each staging row whose ref_by points at
     * another staging row, the sponsor's wp_user_id (back-filled
     * by process_users() during either fresh insert or skip-
     * collision lookup) is the value we want.
     *
     * Two writes per imported member:
     *   - wp_matrix_user_meta.referred_by  — read by every "Referrals"
     *     view in the plugin (Matrix_MLM_User::get_referral_count(),
     *     get_referrals(), the admin Reports → Referrals tab).
     *   - wp_matrix_positions.sponsor_id   — joined by the admin
     *     Users → Move in Genealogy page and set by the plan engine
     *     on fresh registrations. Keeping it consistent with
     *     referred_by means imported members behave identically
     *     to natively-registered members from the admin's POV.
     *
     * Idempotency
     *   The cursor walks staging.id ascending. The chunk SELECT
     *   filters to rows where the corresponding wp_matrix_user_meta
     *   row still has referred_by IS NULL — so re-runs after a
     *   completed pass cleanly find zero rows and advance. Manual
     *   admin edits to referred_by post-import are preserved
     *   because the IS NULL filter skips them.
     *
     * Orphan sponsors
     *   When me.ref_by points to a Laravel id that wasn't imported
     *   (already counted in the dry-run as orphan_sponsors), the
     *   inner sponsor lookup returns NULL and we leave referred_by
     *   alone — the same fall-back-to-root behaviour the spillover
     *   placement uses for tree position.
     * ================================================================ */

    private static function process_referrals($cursor) {
        global $wpdb;
        $su  = self::staging_table('users');
        $um  = $wpdb->prefix . 'matrix_user_meta';
        $pos = $wpdb->prefix . 'matrix_positions';

        // Pre-flight: if the staging users table got dropped between
        // staging and this phase (operator cleanup, etc.), skip cleanly
        // rather than crashing — same defensive pattern as the PR 2
        // phases use for missing PR 2 staging tables.
        if ($wpdb->get_var("SHOW TABLES LIKE '$su'") !== $su) {
            return [
                'done_in_chunk'   => 0,
                'total_for_phase' => 0,
                'cursor_after'    => 0,
                'advance_phase'   => true,
                'next_phase'      => self::next_phase('referrals'),
                'message'         => 'No users staging table — skipping referrals backfill',
            ];
        }

        // Rows still needing a referred_by write: imported member,
        // had a Laravel sponsor, current referred_by is NULL.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT me.id        AS lv_id,
                    me.ref_by    AS lv_ref_by,
                    me.wp_user_id AS my_wp_user_id
             FROM `$su` me
             INNER JOIN `$um` um ON um.user_id = me.wp_user_id
             WHERE me.id > %d
               AND me.wp_user_id IS NOT NULL
               AND me.ref_by > 0
               AND um.referred_by IS NULL
             ORDER BY me.id ASC
             LIMIT " . self::CHUNK_REFERRALS,
            $cursor
        ));

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `$su` me
             INNER JOIN `$um` um ON um.user_id = me.wp_user_id
             WHERE me.wp_user_id IS NOT NULL
               AND me.ref_by > 0
               AND um.referred_by IS NULL"
        );

        if (empty($rows)) {
            return [
                'done_in_chunk'   => 0,
                'total_for_phase' => $total,
                'cursor_after'    => 0,
                'advance_phase'   => true,
                'next_phase'      => self::next_phase('referrals'),
                'message'         => 'Referrals backfill complete',
            ];
        }

        $done    = 0;
        $orphan  = 0;
        $last_id = $cursor;

        foreach ($rows as $row) {
            $last_id = (int) $row->lv_id;

            // Resolve sponsor's WP user id via staging.
            $sponsor_wp_id = $wpdb->get_var($wpdb->prepare(
                "SELECT wp_user_id FROM `$su` WHERE id = %d",
                (int) $row->lv_ref_by
            ));

            if (!$sponsor_wp_id) {
                // Orphan sponsor — Laravel ref_by points outside
                // the imported set. Already surfaced in the dry-
                // run; nothing actionable here.
                $orphan++;
                continue;
            }

            $sponsor_wp_id = (int) $sponsor_wp_id;
            $member_wp_id  = (int) $row->my_wp_user_id;

            // 1) user-meta level pointer (drives referrals UI).
            $wpdb->update(
                $um,
                ['referred_by' => $sponsor_wp_id],
                ['user_id'     => $member_wp_id]
            );

            // 2) position-level pointer (drives admin genealogy
            //    tooling). A member can in principle hold multiple
            //    active positions on different plans; update them
            //    all so genealogy never disagrees with user-meta.
            $wpdb->query($wpdb->prepare(
                "UPDATE `$pos` SET sponsor_id = %d
                 WHERE user_id = %d AND sponsor_id IS NULL",
                $sponsor_wp_id,
                $member_wp_id
            ));

            $done++;
        }

        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$su` me
             INNER JOIN `$um` um ON um.user_id = me.wp_user_id
             WHERE me.id > %d
               AND me.wp_user_id IS NOT NULL
               AND me.ref_by > 0
               AND um.referred_by IS NULL",
            $last_id
        ));

        return [
            'done_in_chunk'   => $done,
            'total_for_phase' => $total,
            'cursor_after'    => $last_id,
            'advance_phase'   => $remaining === 0,
            'next_phase'      => self::next_phase('referrals'),
            'message'         => sprintf(
                'Linked %d sponsors%s',
                $done,
                $orphan > 0 ? sprintf(' (%d orphan refs skipped)', $orphan) : ''
            ),
        ];
    }

    /* ================================================================
     * PR 2 phases: Fintava wallets, Fintava cards, deposits,
     * withdrawals, commissions, e-pins, tickets, ticket messages,
     * subscribers, and the bank-code backfill auto-trigger.
     *
     * All phases follow the same shape as PR 1's:
     *   - SELECT a chunk of staging rows whose import_status IS NULL
     *   - Resolve WP-side foreign keys via the staging tables' wp_*_id
     *     pointer columns (wp_user_id, wp_wallet_id, wp_ticket_id, etc.)
     *   - INSERT into the matching MatrixPro table
     *   - Mark the staging row 'imported' or 'skipped_*' for the next call
     * Each one is idempotent within an import session because the
     * staging.import_status check skips already-processed rows.
     * ================================================================ */

    /**
     * Phase: fintava_wallets
     *
     * card_holders → matrix_fintava_wallets. One row per Laravel
     * member with a virtual NUBAN. We deliberately do NOT migrate
     * bank_name/bank_code from Laravel because Laravel's card_holders
     * table doesn't carry them — the bank_code_backfill phase at the
     * very end of the commit pipeline calls Fintava's API for every
     * NULL bank_code we wrote here and resolves the partner-bank
     * metadata authoritatively.
     *
     * matrix_fintava_wallets has UNIQUE on user_id, so we look up by
     * wp_user_id and UPDATE if a row already exists (e.g. the operator
     * pre-linked one manually before running the importer).
     */
    private static function process_fintava_wallets($cursor) {
        global $wpdb;
        $sch = self::staging_table('card_holders');
        $su  = self::staging_table('users');
        $wt  = $wpdb->prefix . 'matrix_fintava_wallets';

        // Skip cleanly if the staging table doesn't exist (operator
        // uploaded a PR 1-only dump). Same pattern for every PR 2 phase.
        if ($wpdb->get_var("SHOW TABLES LIKE '$sch'") !== $sch) {
            return [
                'done_in_chunk'   => 0,
                'total_for_phase' => 0,
                'cursor_after'    => 0,
                'advance_phase'   => true,
                'next_phase'      => self::next_phase('fintava_wallets'),
                'message'         => 'No card_holders table staged — skipping Fintava wallets',
            ];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ch.* FROM `$sch` ch
             WHERE ch.id > %d
               AND ch.import_status IS NULL
             ORDER BY ch.id ASC
             LIMIT " . self::CHUNK_FINTAVA_W,
            $cursor
        ));
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$sch`");

        if (empty($rows)) {
            return [
                'done_in_chunk'   => 0,
                'total_for_phase' => $total,
                'cursor_after'    => 0,
                'advance_phase'   => true,
                'next_phase'      => self::next_phase('fintava_wallets'),
                'message'         => 'Fintava wallets phase complete',
            ];
        }

        $done    = 0;
        $last_id = $cursor;
        foreach ($rows as $row) {
            $last_id = (int) $row->id;

            // Resolve Laravel user_id → WP user_id via staging users table.
            $wp_uid = $wpdb->get_var($wpdb->prepare(
                "SELECT wp_user_id FROM `$su` WHERE id = %d",
                (int) $row->user_id
            ));
            if (!$wp_uid) {
                $wpdb->update($sch, [
                    'import_status' => 'skipped_no_user',
                    'import_error'  => 'Owning Laravel user did not import',
                ], ['id' => $row->id]);
                continue;
            }
            $wp_uid = (int) $wp_uid;

            // Account number is the only field we treat as required —
            // a wallet without a NUBAN is just metadata noise that
            // would never serve a balance to its owner.
            $account_number = trim((string) $row->account_number);
            if ($account_number === '') {
                $wpdb->update($sch, [
                    'import_status' => 'skipped_no_account',
                    'import_error'  => 'Empty account_number',
                ], ['id' => $row->id]);
                continue;
            }

            // Status: Laravel uses tinyint (1=active, 0=inactive). is_frozen
            // overrides if set. matrix_fintava_wallets uses a varchar.
            $status = ((int) $row->is_frozen === 1)
                ? 'frozen'
                : ((int) $row->status === 1 ? 'active' : 'inactive');

            // Pack KYC + provider extras into metadata so we don't lose
            // them; future support views can decode and display.
            $metadata = wp_json_encode([
                'nin'         => $row->nin ?: null,
                'address'     => $row->address ?: null,
                'user_type'   => $row->user_type ?: null,
                'fund_method' => $row->fund_method ?: null,
                'provider'    => $row->provider ?: 'fintava',
                'imported_from_laravel_id' => (int) $row->id,
            ], JSON_UNESCAPED_UNICODE);

            $account_name = trim(($row->firstname ?? '') . ' ' . ($row->lastname ?? ''));
            if ($account_name === '') {
                $account_name = $row->account_name ?: '';
            }

            $data = [
                'user_id'        => $wp_uid,
                'wallet_id'      => $row->wallet_id ?: null,
                'customer_id'    => $row->cardholder_id ?: null,
                'account_number' => $account_number,
                'account_name'   => $account_name,
                // bank_name defaults to 'Fintava' on the schema and the
                // bank_code_backfill phase resolves the real partner bank
                // (Globus/Wema/Providus/etc.) via the Fintava API at the
                // end of the import.
                'bank_name'      => 'Fintava',
                'bank_code'      => null,
                'currency'       => 'NGN',
                'customer_email' => $row->email ?: null,
                'customer_phone' => $row->mobile ?: null,
                // Encrypt BVN at rest via Matrix_MLM_Crypto (audit H5).
                // Source rows from the Laravel side carry plaintext BVN
                // by design; this is where they land in the WordPress
                // schema, so we encrypt at the boundary.
                'bvn'            => $row->bvn ? Matrix_MLM_Fintava::encrypt_bvn((string) $row->bvn) : null,
                'status'         => $status,
                'metadata'       => $metadata,
                'created_at'     => $row->created_at ?: current_time('mysql'),
            ];

            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM `$wt` WHERE user_id = %d", $wp_uid
            ));
            if ($existing) {
                // Preserve manually-set bank_code/bank_name on update —
                // we don't want a re-import to clobber an operator's
                // hand-corrected partner-bank info.
                unset($data['bank_name'], $data['bank_code'], $data['created_at']);
                $wpdb->update($wt, $data, ['id' => (int) $existing]);
                $wp_wallet_id = (int) $existing;
            } else {
                $wpdb->insert($wt, $data);
                $wp_wallet_id = (int) $wpdb->insert_id;
            }

            $wpdb->update($sch, [
                'wp_wallet_id'  => $wp_wallet_id,
                'import_status' => 'imported',
            ], ['id' => $row->id]);
            $done++;
        }

        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$sch` WHERE id > %d AND import_status IS NULL",
            $last_id
        ));
        return [
            'done_in_chunk'   => $done,
            'total_for_phase' => $total,
            'cursor_after'    => $last_id,
            'advance_phase'   => $remaining === 0,
            'next_phase'      => self::next_phase('fintava_wallets'),
            'message'         => sprintf('Imported %d Fintava wallets (cursor at id %d)', $done, $last_id),
        ];
    }

    /**
     * Phase: fintava_cards
     *
     * cards → matrix_fintava_cards. JOINs back through staging
     * card_holders to resolve the destination wallet_id (Fintava's
     * UUID, not the matrix_fintava_wallets row id). Status normalised
     * from Laravel's UPPERCASE ('ACTIVE'/'INACTIVE') to the lowercase
     * MatrixPro enum, with INACTIVE mapped to 'frozen' (the closest
     * semantic match — the card exists but isn't usable).
     */
    private static function process_fintava_cards($cursor) {
        global $wpdb;
        $scd = self::staging_table('cards');
        $sch = self::staging_table('card_holders');
        $ct  = $wpdb->prefix . 'matrix_fintava_cards';

        if ($wpdb->get_var("SHOW TABLES LIKE '$scd'") !== $scd) {
            return [
                'done_in_chunk' => 0, 'total_for_phase' => 0, 'cursor_after' => 0,
                'advance_phase' => true, 'next_phase' => self::next_phase('fintava_cards'),
                'message' => 'No cards table staged — skipping Fintava cards',
            ];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `$scd` WHERE id > %d AND import_status IS NULL ORDER BY id ASC LIMIT " . self::CHUNK_FINTAVA_C,
            $cursor
        ));
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$scd`");

        if (empty($rows)) {
            return [
                'done_in_chunk' => 0, 'total_for_phase' => $total, 'cursor_after' => 0,
                'advance_phase' => true, 'next_phase' => self::next_phase('fintava_cards'),
                'message' => 'Fintava cards phase complete',
            ];
        }

        $done = 0; $last_id = $cursor;
        foreach ($rows as $row) {
            $last_id = (int) $row->id;

            // Resolve owning user (via Laravel cards.user_id → staging users).
            $wp_uid = $wpdb->get_var($wpdb->prepare(
                "SELECT wp_user_id FROM `" . self::staging_table('users') . "` WHERE id = %d",
                (int) $row->user_id
            ));
            if (!$wp_uid) {
                $wpdb->update($scd, [
                    'import_status' => 'skipped_no_user',
                    'import_error'  => 'Owning Laravel user did not import',
                ], ['id' => $row->id]);
                continue;
            }

            // Resolve wallet UUID via card_holders join. matrix_fintava_cards
            // stores Fintava's wallet_id (UUID), not the wp wallet row id —
            // matches the cards-as-issued-against-a-wallet model the rest
            // of the plugin already uses.
            $wallet_uuid = $wpdb->get_var($wpdb->prepare(
                "SELECT wallet_id FROM `$sch` WHERE id = %d",
                (int) $row->card_holder_id
            ));

            // Dedupe: if a card with this Laravel card_id already exists,
            // update rather than re-insert. card_id is Fintava-issued so
            // it's globally unique.
            $existing = null;
            if (!empty($row->card_id)) {
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM `$ct` WHERE card_id = %s LIMIT 1",
                    $row->card_id
                ));
            }

            $status_raw = strtolower((string) $row->status);
            $status_map = [
                'active'    => 'active',
                'inactive'  => 'frozen',  // best-fit semantic
                'pending'   => 'pending',
                'processing'=> 'processing',
                'shipped'   => 'shipped',
                'delivered' => 'delivered',
                'linked'    => 'linked',
                'frozen'    => 'frozen',
                'blocked'   => 'blocked',
                'expired'   => 'expired',
            ];
            $status = $status_map[$status_raw] ?? 'pending';

            $metadata = wp_json_encode([
                'card_holder_email'    => $row->card_holder_email ?: null,
                'card_holder_mobile'   => $row->card_holder_mobile ?: null,
                'account_number'       => $row->account_number ?: null,
                'map_id'               => $row->map_id ?: null,
                'card_no'              => $row->card_no ?: null,
                'exp_month'            => $row->exp_month ?: null,
                'exp_year'             => $row->exp_year ?: null,
                'card_request_status'  => $row->card_request_status ?: null,
                'is_linked'            => (int) $row->is_linked,
                'linked_at'            => $row->linked_at ?: null,
                'imported_from_laravel_id' => (int) $row->id,
            ], JSON_UNESCAPED_UNICODE);

            $data = [
                'user_id'      => (int) $wp_uid,
                'card_id'      => $row->card_id ?: null,
                'wallet_id'    => $wallet_uuid ?: null,
                'card_type'    => $row->card_type ?: 'STATIC_NO_ACCOUNT',
                'card_brand'   => $row->card_brand ?: 'VERVE',
                'last_four'    => $row->last4 ?: null,
                'status'       => $status,
                'activated_at' => $row->activated_at ?: null,
                'metadata'     => $metadata,
                'created_at'   => $row->created_at ?: current_time('mysql'),
            ];

            if ($existing) {
                unset($data['created_at']);
                $wpdb->update($ct, $data, ['id' => (int) $existing]);
                $wp_target = (int) $existing;
            } else {
                $wpdb->insert($ct, $data);
                $wp_target = (int) $wpdb->insert_id;
            }

            $wpdb->update($scd, [
                'wp_target_id'  => $wp_target,
                'import_status' => 'imported',
            ], ['id' => $row->id]);
            $done++;
        }

        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$scd` WHERE id > %d AND import_status IS NULL",
            $last_id
        ));
        return [
            'done_in_chunk' => $done, 'total_for_phase' => $total, 'cursor_after' => $last_id,
            'advance_phase' => $remaining === 0, 'next_phase' => self::next_phase('fintava_cards'),
            'message' => sprintf('Imported %d Fintava cards (cursor at id %d)', $done, $last_id),
        ];
    }

    /**
     * Phase: deposits
     *
     * Laravel deposits.status: 1=success, 2=pending, 3=cancel.
     * MatrixPro deposits.status: 'pending' | 'completed' | 'rejected' | 'cancelled'.
     * Mapping: 1→completed, 2→pending, 3→cancelled, anything else→pending.
     *
     * Dedupe by transaction_id which we set to "lv-{laravel_id}" so a
     * re-run with the same dump doesn't double-credit history.
     */
    private static function process_deposits($cursor) {
        global $wpdb;
        $sd = self::staging_table('deposits');
        $su = self::staging_table('users');
        $dt = $wpdb->prefix . 'matrix_deposits';

        if ($wpdb->get_var("SHOW TABLES LIKE '$sd'") !== $sd) {
            return [
                'done_in_chunk' => 0, 'total_for_phase' => 0, 'cursor_after' => 0,
                'advance_phase' => true, 'next_phase' => self::next_phase('deposits'),
                'message' => 'No deposits staged — skipping',
            ];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `$sd` WHERE id > %d AND import_status IS NULL ORDER BY id ASC LIMIT " . self::CHUNK_DEPOSITS,
            $cursor
        ));
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$sd`");

        if (empty($rows)) {
            return [
                'done_in_chunk' => 0, 'total_for_phase' => $total, 'cursor_after' => 0,
                'advance_phase' => true, 'next_phase' => self::next_phase('deposits'),
                'message' => 'Deposits phase complete',
            ];
        }

        $status_map = [1 => 'completed', 2 => 'pending', 3 => 'cancelled'];

        $done = 0; $last_id = $cursor;
        foreach ($rows as $row) {
            $last_id = (int) $row->id;
            $wp_uid = $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM `$su` WHERE id = %d", (int) $row->user_id));
            if (!$wp_uid) {
                $wpdb->update($sd, ['import_status' => 'skipped_no_user'], ['id' => $row->id]);
                continue;
            }

            // Stable Laravel-anchored transaction id so re-imports skip.
            $marker = 'lv-deposit-' . (int) $row->id;
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM `$dt` WHERE transaction_id = %s LIMIT 1", $marker
            ));
            if ($existing) {
                $wpdb->update($sd, [
                    'wp_target_id'  => (int) $existing,
                    'import_status' => 'skipped_duplicate',
                ], ['id' => $row->id]);
                continue;
            }

            $status = $status_map[(int) $row->status] ?? 'pending';
            $wpdb->insert($dt, [
                'user_id'          => (int) $wp_uid,
                // Laravel stores method_code as int → keep readable.
                'gateway'          => 'laravel-method-' . (int) $row->method_code,
                'amount'           => $row->amount,
                'charge'           => $row->charge,
                'net_amount'       => $row->final_amo,
                'currency'         => $row->method_currency ?: 'NGN',
                'transaction_id'   => $marker . ($row->trx ? ('-' . $row->trx) : ''),
                'gateway_response' => $row->detail ?: null,
                'status'           => $status,
                'created_at'       => $row->created_at ?: current_time('mysql'),
            ]);
            $wpdb->update($sd, [
                'wp_target_id'  => (int) $wpdb->insert_id,
                'import_status' => 'imported',
            ], ['id' => $row->id]);
            $done++;
        }

        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$sd` WHERE id > %d AND import_status IS NULL", $last_id
        ));
        return [
            'done_in_chunk' => $done, 'total_for_phase' => $total, 'cursor_after' => $last_id,
            'advance_phase' => $remaining === 0, 'next_phase' => self::next_phase('deposits'),
            'message' => sprintf('Imported %d deposits (cursor %d)', $done, $last_id),
        ];
    }

    /**
     * Phase: withdrawals
     *
     * Laravel withdrawals.status: 1=success, 2=pending, 3=cancel.
     * MatrixPro withdrawals.status: 'pending' | 'approved' | 'rejected' | 'cancelled'.
     * Mapping: 1→approved, 2→pending, 3→cancelled.
     */
    private static function process_withdrawals($cursor) {
        global $wpdb;
        $sw = self::staging_table('withdrawals');
        $su = self::staging_table('users');
        $wt = $wpdb->prefix . 'matrix_withdrawals';

        if ($wpdb->get_var("SHOW TABLES LIKE '$sw'") !== $sw) {
            return [
                'done_in_chunk' => 0, 'total_for_phase' => 0, 'cursor_after' => 0,
                'advance_phase' => true, 'next_phase' => self::next_phase('withdrawals'),
                'message' => 'No withdrawals staged — skipping',
            ];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `$sw` WHERE id > %d AND import_status IS NULL ORDER BY id ASC LIMIT " . self::CHUNK_WITHDRAW,
            $cursor
        ));
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$sw`");

        if (empty($rows)) {
            return [
                'done_in_chunk' => 0, 'total_for_phase' => $total, 'cursor_after' => 0,
                'advance_phase' => true, 'next_phase' => self::next_phase('withdrawals'),
                'message' => 'Withdrawals phase complete',
            ];
        }

        $status_map = [1 => 'approved', 2 => 'pending', 3 => 'cancelled'];

        $done = 0; $last_id = $cursor;
        foreach ($rows as $row) {
            $last_id = (int) $row->id;
            $wp_uid = $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM `$su` WHERE id = %d", (int) $row->user_id));
            if (!$wp_uid) {
                $wpdb->update($sw, ['import_status' => 'skipped_no_user'], ['id' => $row->id]);
                continue;
            }

            // Dedupe by Laravel id — withdrawals don't have a unique col on the WP side.
            $marker = 'lv-withdrawal-' . (int) $row->id;
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM `$wt` WHERE admin_note LIKE %s LIMIT 1",
                '%' . $wpdb->esc_like($marker) . '%'
            ));
            if ($existing) {
                $wpdb->update($sw, [
                    'wp_target_id'  => (int) $existing,
                    'import_status' => 'skipped_duplicate',
                ], ['id' => $row->id]);
                continue;
            }

            $status = $status_map[(int) $row->status] ?? 'pending';
            $admin_note = $marker;
            if (!empty($row->admin_feedback)) {
                $admin_note .= ' | ' . $row->admin_feedback;
            }

            $wpdb->insert($wt, [
                'user_id'         => (int) $wp_uid,
                'method'          => 'laravel-method-' . (int) $row->method_id,
                'amount'          => $row->amount,
                'charge'          => $row->charge,
                'net_amount'      => $row->after_charge ?: $row->amount,
                'currency'        => $row->currency ?: 'NGN',
                'account_details' => $row->withdraw_information ?: null,
                'admin_note'      => $admin_note,
                'status'          => $status,
                'created_at'      => $row->created_at ?: current_time('mysql'),
            ]);
            $wpdb->update($sw, [
                'wp_target_id'  => (int) $wpdb->insert_id,
                'import_status' => 'imported',
            ], ['id' => $row->id]);
            $done++;
        }

        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$sw` WHERE id > %d AND import_status IS NULL", $last_id
        ));
        return [
            'done_in_chunk' => $done, 'total_for_phase' => $total, 'cursor_after' => $last_id,
            'advance_phase' => $remaining === 0, 'next_phase' => self::next_phase('withdrawals'),
            'message' => sprintf('Imported %d withdrawals (cursor %d)', $done, $last_id),
        ];
    }

    /**
     * Phase: commissions
     *
     * Laravel mark: 1=referral, 2=level. Anything else falls back to
     * 'level' (the more inclusive bucket — a misclassified commission
     * still renders correctly in the user dashboard's level commission
     * tab; misclassified the other way it would never appear).
     *
     * Dedupe by Laravel trx (when present) or by composite
     * (from_user, user, amount, level, created_at) which is unique
     * enough in practice for the ViserMLM commission distribution flow.
     */
    private static function process_commissions($cursor) {
        global $wpdb;
        $sc = self::staging_table('commissions');
        $su = self::staging_table('users');
        $ct = $wpdb->prefix . 'matrix_commissions';

        if ($wpdb->get_var("SHOW TABLES LIKE '$sc'") !== $sc) {
            return [
                'done_in_chunk' => 0, 'total_for_phase' => 0, 'cursor_after' => 0,
                'advance_phase' => true, 'next_phase' => self::next_phase('commissions'),
                'message' => 'No commissions staged — skipping',
            ];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `$sc` WHERE id > %d AND import_status IS NULL ORDER BY id ASC LIMIT " . self::CHUNK_COMMS,
            $cursor
        ));
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$sc`");

        if (empty($rows)) {
            return [
                'done_in_chunk' => 0, 'total_for_phase' => $total, 'cursor_after' => 0,
                'advance_phase' => true, 'next_phase' => self::next_phase('commissions'),
                'message' => 'Commissions phase complete',
            ];
        }

        $done = 0; $last_id = $cursor;
        foreach ($rows as $row) {
            $last_id = (int) $row->id;
            // Resolve the recipient (user_id) and the source (from_user_id).
            $wp_uid   = $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM `$su` WHERE id = %d", (int) $row->user_id));
            $wp_from  = $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM `$su` WHERE id = %d", (int) $row->from_user_id));
            if (!$wp_uid) {
                $wpdb->update($sc, ['import_status' => 'skipped_no_user'], ['id' => $row->id]);
                continue;
            }

            // Dedupe via details column — store Laravel id there so the
            // existing details payload stays human-readable while still
            // making the row uniquely identifiable.
            $marker = 'lv-commission-' . (int) $row->id;
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM `$ct` WHERE user_id = %d AND from_user_id = %d AND amount = %s AND level = %d AND DATE(created_at) = DATE(%s) LIMIT 1",
                (int) $wp_uid,
                (int) ($wp_from ?: 0),
                $row->amount,
                (int) $row->level,
                $row->created_at ?: current_time('mysql')
            ));
            if ($existing) {
                $wpdb->update($sc, [
                    'wp_target_id'  => (int) $existing,
                    'import_status' => 'skipped_duplicate',
                ], ['id' => $row->id]);
                continue;
            }

            $type = ((int) $row->mark === 1) ? 'referral' : 'level';
            // matrix_commissions has a NOT NULL from_user_id with no
            // sentinel; if the source user didn't import (orphan), use
            // the recipient as a fallback to avoid breaking the FK.
            $from_id = $wp_from ? (int) $wp_from : (int) $wp_uid;

            $wpdb->insert($ct, [
                'user_id'      => (int) $wp_uid,
                'from_user_id' => $from_id,
                'plan_id'      => null,
                'type'         => $type,
                'level'        => (int) $row->level,
                'amount'       => $row->amount,
                'status'       => 'paid',
                'created_at'   => $row->created_at ?: current_time('mysql'),
            ]);
            $wpdb->update($sc, [
                'wp_target_id'  => (int) $wpdb->insert_id,
                'import_status' => 'imported',
                'import_error'  => $marker, // breadcrumb for support diagnostics
            ], ['id' => $row->id]);
            $done++;
        }

        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$sc` WHERE id > %d AND import_status IS NULL", $last_id
        ));
        return [
            'done_in_chunk' => $done, 'total_for_phase' => $total, 'cursor_after' => $last_id,
            'advance_phase' => $remaining === 0, 'next_phase' => self::next_phase('commissions'),
            'message' => sprintf('Imported %d commissions (cursor %d)', $done, $last_id),
        ];
    }

    /**
     * Phase: epins
     *
     * pins → matrix_epins. Status: 0=unused → 'unused', 1=used → 'used'.
     * matrix_epins has UNIQUE on pin_code so a re-run with the same dump
     * is naturally idempotent — INSERT IGNORE skips dupes.
     */
    private static function process_epins($cursor) {
        global $wpdb;
        $sp = self::staging_table('pins');
        $su = self::staging_table('users');
        $et = $wpdb->prefix . 'matrix_epins';

        if ($wpdb->get_var("SHOW TABLES LIKE '$sp'") !== $sp) {
            return [
                'done_in_chunk' => 0, 'total_for_phase' => 0, 'cursor_after' => 0,
                'advance_phase' => true, 'next_phase' => self::next_phase('epins'),
                'message' => 'No pins staged — skipping',
            ];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `$sp` WHERE id > %d AND import_status IS NULL ORDER BY id ASC LIMIT " . self::CHUNK_EPINS,
            $cursor
        ));
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$sp`");

        if (empty($rows)) {
            return [
                'done_in_chunk' => 0, 'total_for_phase' => $total, 'cursor_after' => 0,
                'advance_phase' => true, 'next_phase' => self::next_phase('epins'),
                'message' => 'E-pins phase complete',
            ];
        }

        $done = 0; $last_id = $cursor;
        foreach ($rows as $row) {
            $last_id = (int) $row->id;
            if (empty($row->pin)) {
                $wpdb->update($sp, ['import_status' => 'skipped_empty_pin'], ['id' => $row->id]);
                continue;
            }

            // Skip if pin_code already exists.
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM `$et` WHERE pin_code = %s LIMIT 1", $row->pin
            ));
            if ($existing) {
                $wpdb->update($sp, [
                    'wp_target_id'  => (int) $existing,
                    'import_status' => 'skipped_duplicate',
                ], ['id' => $row->id]);
                continue;
            }

            $created_by = $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM `$su` WHERE id = %d", (int) $row->generate_user_id));
            $used_by    = (int) $row->user_id > 0
                ? $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM `$su` WHERE id = %d", (int) $row->user_id))
                : null;

            // matrix_epins.created_by is NOT NULL — fall back to user 1
            // (typically the WP super-admin) when the Laravel generator
            // didn't import or wasn't recorded.
            if (!$created_by) {
                $created_by = 1;
            }

            $status = ((int) $row->status === 1) ? 'used' : 'unused';
            $wpdb->insert($et, [
                'pin_code'   => $row->pin,
                'plan_id'    => null,
                'amount'     => $row->amount,
                'created_by' => (int) $created_by,
                'used_by'    => $used_by ? (int) $used_by : null,
                'status'     => $status,
                'created_at' => $row->created_at ?: current_time('mysql'),
                'used_at'    => ($status === 'used' && $row->updated_at) ? $row->updated_at : null,
                'expires_at' => null,
            ]);
            $wpdb->update($sp, [
                'wp_target_id'  => (int) $wpdb->insert_id,
                'import_status' => 'imported',
            ], ['id' => $row->id]);
            $done++;
        }

        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$sp` WHERE id > %d AND import_status IS NULL", $last_id
        ));
        return [
            'done_in_chunk' => $done, 'total_for_phase' => $total, 'cursor_after' => $last_id,
            'advance_phase' => $remaining === 0, 'next_phase' => self::next_phase('epins'),
            'message' => sprintf('Imported %d e-pins (cursor %d)', $done, $last_id),
        ];
    }

    /**
     * Phase: tickets
     *
     * support_tickets → matrix_tickets. The ticket-message phase joins
     * back through staging.wp_ticket_id to resolve which MatrixPro
     * ticket each Laravel message belongs to.
     */
    private static function process_tickets($cursor) {
        global $wpdb;
        $st = self::staging_table('support_tickets');
        $su = self::staging_table('users');
        $tt = $wpdb->prefix . 'matrix_tickets';

        if ($wpdb->get_var("SHOW TABLES LIKE '$st'") !== $st) {
            return [
                'done_in_chunk' => 0, 'total_for_phase' => 0, 'cursor_after' => 0,
                'advance_phase' => true, 'next_phase' => self::next_phase('tickets'),
                'message' => 'No tickets staged — skipping',
            ];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `$st` WHERE id > %d AND import_status IS NULL ORDER BY id ASC LIMIT " . self::CHUNK_TICKETS,
            $cursor
        ));
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$st`");

        if (empty($rows)) {
            return [
                'done_in_chunk' => 0, 'total_for_phase' => $total, 'cursor_after' => 0,
                'advance_phase' => true, 'next_phase' => self::next_phase('tickets'),
                'message' => 'Tickets phase complete',
            ];
        }

        $priority_map = [1 => 'low', 2 => 'medium', 3 => 'high'];
        $status_map   = [0 => 'open', 1 => 'answered', 2 => 'customer_reply', 3 => 'closed'];

        $done = 0; $last_id = $cursor;
        foreach ($rows as $row) {
            $last_id = (int) $row->id;
            $wp_uid = $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM `$su` WHERE id = %d", (int) $row->user_id));
            if (!$wp_uid) {
                $wpdb->update($st, ['import_status' => 'skipped_no_user'], ['id' => $row->id]);
                continue;
            }

            // Dedupe via Laravel ticket number when present.
            $existing = null;
            if (!empty($row->ticket)) {
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM `$tt` WHERE subject LIKE %s AND user_id = %d LIMIT 1",
                    '[' . $wpdb->esc_like($row->ticket) . ']%',
                    (int) $wp_uid
                ));
            }
            if ($existing) {
                $wpdb->update($st, [
                    'wp_ticket_id'  => (int) $existing,
                    'import_status' => 'skipped_duplicate',
                ], ['id' => $row->id]);
                continue;
            }

            // Prefix subject with the Laravel ticket number so support
            // staff can correlate back to the old system if needed.
            $subject = ($row->ticket ? '[' . $row->ticket . '] ' : '') . ($row->subject ?: '(no subject)');

            $wpdb->insert($tt, [
                'user_id'    => (int) $wp_uid,
                'subject'    => $subject,
                'priority'   => $priority_map[(int) $row->priority] ?? 'medium',
                'status'     => $status_map[(int) $row->status] ?? 'open',
                'department' => null,
                'created_at' => $row->created_at ?: current_time('mysql'),
            ]);
            $wpdb->update($st, [
                'wp_ticket_id'  => (int) $wpdb->insert_id,
                'import_status' => 'imported',
            ], ['id' => $row->id]);
            $done++;
        }

        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$st` WHERE id > %d AND import_status IS NULL", $last_id
        ));
        return [
            'done_in_chunk' => $done, 'total_for_phase' => $total, 'cursor_after' => $last_id,
            'advance_phase' => $remaining === 0, 'next_phase' => self::next_phase('tickets'),
            'message' => sprintf('Imported %d tickets (cursor %d)', $done, $last_id),
        ];
    }

    /**
     * Phase: ticket_messages
     *
     * support_messages → matrix_ticket_messages. Resolves the parent
     * ticket via staging.wp_ticket_id. is_admin = (admin_id > 0).
     * For staff replies, user_id is set to the lowest-id WP admin
     * (super-admin) since Laravel admins live in a separate table
     * we don't migrate — the message text is what matters for
     * support history; attribution to the exact original staff
     * member would require migrating the admins table too.
     */
    private static function process_ticket_messages($cursor) {
        global $wpdb;
        $sm = self::staging_table('support_messages');
        $st = self::staging_table('support_tickets');
        $mt = $wpdb->prefix . 'matrix_ticket_messages';

        if ($wpdb->get_var("SHOW TABLES LIKE '$sm'") !== $sm) {
            return [
                'done_in_chunk' => 0, 'total_for_phase' => 0, 'cursor_after' => 0,
                'advance_phase' => true, 'next_phase' => self::next_phase('ticket_messages'),
                'message' => 'No ticket messages staged — skipping',
            ];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.*, t.wp_ticket_id, t.user_id AS lv_ticket_user_id
             FROM `$sm` m
             LEFT JOIN `$st` t ON t.id = m.support_ticket_id
             WHERE m.id > %d AND m.import_status IS NULL
             ORDER BY m.id ASC
             LIMIT " . self::CHUNK_TMSGS,
            $cursor
        ));
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$sm`");

        if (empty($rows)) {
            return [
                'done_in_chunk' => 0, 'total_for_phase' => $total, 'cursor_after' => 0,
                'advance_phase' => true, 'next_phase' => self::next_phase('ticket_messages'),
                'message' => 'Ticket messages phase complete',
            ];
        }

        // Resolve the lowest-id WP admin once, used as the author for
        // every staff reply we import.
        $fallback_admin = (int) $wpdb->get_var(
            "SELECT u.ID FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key = '{$wpdb->prefix}capabilities'
             WHERE m.meta_value LIKE '%administrator%'
             ORDER BY u.ID ASC LIMIT 1"
        );
        if (!$fallback_admin) {
            $fallback_admin = 1;
        }

        $done = 0; $last_id = $cursor;
        foreach ($rows as $row) {
            $last_id = (int) $row->id;
            if (!$row->wp_ticket_id) {
                $wpdb->update($sm, ['import_status' => 'skipped_no_ticket'], ['id' => $row->id]);
                continue;
            }

            $is_admin = ((int) $row->admin_id > 0) ? 1 : 0;

            // For member replies, use the ticket's owning WP user.
            // We have to re-resolve from staging.support_tickets.user_id
            // → staging.users.wp_user_id since the JOIN above only got
            // us the Laravel user id.
            $user_id = (int) $fallback_admin;
            if (!$is_admin && (int) $row->lv_ticket_user_id > 0) {
                $resolved = $wpdb->get_var($wpdb->prepare(
                    "SELECT wp_user_id FROM `" . self::staging_table('users') . "` WHERE id = %d",
                    (int) $row->lv_ticket_user_id
                ));
                $user_id = $resolved ? (int) $resolved : (int) $fallback_admin;
            }

            $wpdb->insert($mt, [
                'ticket_id'   => (int) $row->wp_ticket_id,
                'user_id'     => $user_id,
                'message'     => $row->message ?: '',
                'attachments' => null,
                'is_admin'    => $is_admin,
                'created_at'  => $row->created_at ?: current_time('mysql'),
            ]);
            $wpdb->update($sm, [
                'wp_target_id'  => (int) $wpdb->insert_id,
                'import_status' => 'imported',
            ], ['id' => $row->id]);
            $done++;
        }

        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$sm` WHERE id > %d AND import_status IS NULL", $last_id
        ));
        return [
            'done_in_chunk' => $done, 'total_for_phase' => $total, 'cursor_after' => $last_id,
            'advance_phase' => $remaining === 0, 'next_phase' => self::next_phase('ticket_messages'),
            'message' => sprintf('Imported %d ticket replies (cursor %d)', $done, $last_id),
        ];
    }

    /**
     * Phase: subscribers
     *
     * subscribers → matrix_subscribers. matrix_subscribers has UNIQUE
     * on email so we use a per-row existence check rather than INSERT
     * IGNORE — that way the staging row records whether it was newly
     * inserted vs already-on-the-list, which the import summary surfaces.
     */
    private static function process_subscribers($cursor) {
        global $wpdb;
        $ss = self::staging_table('subscribers');
        $st = $wpdb->prefix . 'matrix_subscribers';

        if ($wpdb->get_var("SHOW TABLES LIKE '$ss'") !== $ss) {
            return [
                'done_in_chunk' => 0, 'total_for_phase' => 0, 'cursor_after' => 0,
                'advance_phase' => true, 'next_phase' => self::next_phase('subscribers'),
                'message' => 'No subscribers staged — skipping',
            ];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `$ss` WHERE id > %d AND import_status IS NULL ORDER BY id ASC LIMIT " . self::CHUNK_SUBS,
            $cursor
        ));
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$ss`");

        if (empty($rows)) {
            return [
                'done_in_chunk' => 0, 'total_for_phase' => $total, 'cursor_after' => 0,
                'advance_phase' => true, 'next_phase' => self::next_phase('subscribers'),
                'message' => 'Subscribers phase complete',
            ];
        }

        $done = 0; $last_id = $cursor;
        foreach ($rows as $row) {
            $last_id = (int) $row->id;
            $email = trim((string) $row->email);
            if ($email === '' || !is_email($email)) {
                $wpdb->update($ss, ['import_status' => 'skipped_invalid_email'], ['id' => $row->id]);
                continue;
            }
            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM `$st` WHERE email = %s", $email));
            if ($existing) {
                $wpdb->update($ss, [
                    'wp_target_id'  => (int) $existing,
                    'import_status' => 'skipped_duplicate',
                ], ['id' => $row->id]);
                continue;
            }
            $wpdb->insert($st, [
                'email'      => $email,
                'status'     => 1,
                'created_at' => $row->created_at ?: current_time('mysql'),
            ]);
            $wpdb->update($ss, [
                'wp_target_id'  => (int) $wpdb->insert_id,
                'import_status' => 'imported',
            ], ['id' => $row->id]);
            $done++;
        }

        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$ss` WHERE id > %d AND import_status IS NULL", $last_id
        ));
        return [
            'done_in_chunk' => $done, 'total_for_phase' => $total, 'cursor_after' => $last_id,
            'advance_phase' => $remaining === 0, 'next_phase' => self::next_phase('subscribers'),
            'message' => sprintf('Imported %d subscribers (cursor %d)', $done, $last_id),
        ];
    }

    /**
     * Phase: bank_code_backfill
     *
     * Auto-trigger of the existing Matrix_MLM_Fintava::backfill_missing_bank_codes()
     * orchestrator that the migration page exposes manually as a one-
     * click button. Runs as the final phase of an import so wallets
     * imported in the fintava_wallets phase land with a real partner-
     * bank sortCode (Globus, Wema, Providus, etc.) populated by the
     * Fintava API rather than the schema-default 'Fintava' placeholder.
     *
     * Without this, every imported member's first matrix→virtual
     * transfer would fail with the bank_code-missing error the
     * existing self-heal chain documents — and the user wouldn't
     * see their balance until an operator manually triggered the
     * backfill from Migration → Fintava.
     *
     * Runs as a single chunk (the orchestrator does its own internal
     * batching with throttled Fintava API round-trips).
     */
    private static function process_bank_code_backfill($cursor) {
        if (!class_exists('Matrix_MLM_Fintava')) {
            return [
                'done_in_chunk' => 0, 'total_for_phase' => 1, 'cursor_after' => 1,
                'advance_phase' => true, 'next_phase' => self::next_phase('bank_code_backfill'),
                'message' => 'Fintava class not loaded — skipping backfill',
            ];
        }
        $fintava = new Matrix_MLM_Fintava();
        if (!$fintava->is_active()) {
            return [
                'done_in_chunk' => 0, 'total_for_phase' => 1, 'cursor_after' => 1,
                'advance_phase' => true, 'next_phase' => self::next_phase('bank_code_backfill'),
                'message' => 'Fintava not configured — skipping backfill (re-run from Migration → Fintava once configured)',
            ];
        }

        // Allow the orchestrator a generous timeout — it can fire up to
        // 5 round-trips per row in the worst case.
        @set_time_limit(180);

        $report = $fintava->backfill_missing_bank_codes();
        if (is_wp_error($report)) {
            return [
                'done_in_chunk' => 0, 'total_for_phase' => 1, 'cursor_after' => 1,
                'advance_phase' => true, 'next_phase' => self::next_phase('bank_code_backfill'),
                'message' => 'Backfill error: ' . $report->get_error_message(),
            ];
        }

        $resolved = (int) $report['resolved_via_wallet_details']
                  + (int) $report['resolved_via_customer_api']
                  + (int) $report['resolved_via_static_lookup'];

        return [
            'done_in_chunk'   => $resolved,
            'total_for_phase' => (int) $report['total_missing_before'],
            'cursor_after'    => 1,
            'advance_phase'   => true,
            'next_phase'      => self::next_phase('bank_code_backfill'),
            'message'         => sprintf(
                'Bank-code backfill: %d resolved out of %d (%d still missing)',
                $resolved,
                (int) $report['total_missing_before'],
                (int) $report['still_missing']
            ),
        ];
    }

    /* ================================================================
     * handle_reset — drops staging tables and clears state
     * ================================================================ */

    public static function handle_reset() {
        self::require_admin();
        check_admin_referer('matrix_mlm_import_reset');

        self::drop_staging_tables();
        delete_option(self::OPT_STATE);

        self::redirect_with_notice('success', __('Import state cleared. Staging tables dropped.', 'matrix-mlm'));
    }

    /* ================================================================
     * Page render
     * ================================================================ */

    public static function render() {
        if (!current_user_can('manage_matrix_settings')) {
            wp_die(__('Unauthorized', 'matrix-mlm'));
        }

        // One-shot notice from redirects.
        if (!empty($_GET['matrix_notice'])) {
            $type  = sanitize_text_field(wp_unslash($_GET['matrix_notice']));
            $msg   = isset($_GET['matrix_message']) ? wp_unslash(rawurldecode($_GET['matrix_message'])) : '';
            $class = $type === 'success' ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }

        $state    = self::get_state();
        $status   = $state['status'];
        $stats    = $state['stats'] ?? [];
        $analysis = $state['analysis'] ?? null;
        $errors   = $state['errors'] ?? [];

        $ajax_nonce = wp_create_nonce('matrix_mlm_import_chunk');
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php _e('Import from Laravel (ViserMLM)', 'matrix-mlm'); ?></h1>
            <p class="description">
                <?php _e('Migrates an existing ViserMLM-family Laravel install into MatrixPro. Upload your <code>mysqldump</code>, run the dry-run analysis, then commit. Members keep their existing passwords (bcrypt), sponsor/parent relationships, and Fintava wallets.', 'matrix-mlm'); ?>
            </p>

            <?php if (!empty($errors)): ?>
                <div class="notice notice-error">
                    <p><strong><?php _e('Errors during import:', 'matrix-mlm'); ?></strong></p>
                    <ul style="margin-left:20px;">
                        <?php foreach (array_slice($errors, 0, 10) as $e): ?>
                            <li><?php echo esc_html($e); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Step 1: Upload -->
            <div class="matrix-admin-card" style="padding:16px;border:1px solid #ddd;background:#fff;margin-top:16px;<?php echo $status === 'idle' ? '' : 'opacity:0.65;'; ?>">
                <h2><?php _e('Step 1 — Upload Laravel SQL Dump', 'matrix-mlm'); ?></h2>
                <p><?php _e('Run on your Laravel server (or use phpMyAdmin → Export → Custom → Data only):', 'matrix-mlm'); ?>
                <br><code>mysqldump -u USER -p --no-create-info DBNAME users plans levels card_holders cards deposits withdrawals commissions pins support_tickets support_messages subscribers > laravel-data.sql</code></p>
                <p class="description">
                    <?php _e('All twelve tables are required for a complete migration. Tables omitted from the dump will simply have empty data on the WordPress side — for example, dropping <code>commissions</code> means the commission ledger starts fresh on import day. The financial / Fintava / ticketing / subscriber tables are processed in the second half of the commit pipeline; uploading only <code>users plans levels</code> still works (the PR 2 phases will trivially complete with zero rows).', 'matrix-mlm'); ?>
                </p>
                <p class="description">
                    <strong><?php _e('Tip:', 'matrix-mlm'); ?></strong>
                    <?php _e('Either form of <code>mysqldump</code> output is accepted — bare <code>INSERT INTO &lt;table&gt; VALUES …</code> (the default) and explicit-column <code>INSERT INTO &lt;table&gt; (col, …) VALUES …</code> (produced by <code>--complete-insert</code> or by phpMyAdmin\'s Custom Export). The importer adds the column list internally when it\'s missing, so you don\'t have to remember the flag.', 'matrix-mlm'); ?>
                </p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field('matrix_mlm_import_upload'); ?>
                    <input type="hidden" name="action" value="matrix_mlm_import_upload">
                    <p>
                        <input type="file" name="dump_file" accept=".sql,.gz,.sql.gz" required>
                    </p>
                    <p>
                        <button type="submit" class="button button-primary"><?php _e('Upload &amp; Stage', 'matrix-mlm'); ?></button>
                    </p>
                </form>
                <?php if (!empty($state['file'])): ?>
                    <p class="description"><?php printf(__('Last upload: %s — %s', 'matrix-mlm'), esc_html($state['file']), esc_html($state['created_at'] ?? '')); ?></p>
                <?php endif; ?>
            </div>

            <!-- Step 2: Dry Run -->
            <?php if (in_array($status, ['staged', 'analyzed', 'committing', 'completed', 'failed'], true)): ?>
                <div class="matrix-admin-card" style="padding:16px;border:1px solid #ddd;background:#fff;margin-top:16px;">
                    <h2><?php _e('Step 2 — Dry-Run Analysis', 'matrix-mlm'); ?></h2>
                    <p>
                        <?php printf(
                            __('Staged from upload: <strong>%d</strong> users, <strong>%d</strong> plans, <strong>%d</strong> level rows.', 'matrix-mlm'),
                            (int) ($stats['users'] ?? 0),
                            (int) ($stats['plans'] ?? 0),
                            (int) ($stats['levels'] ?? 0)
                        ); ?>
                    </p>
                    <?php
                    // Per-table breakdown for the financial / operational
                    // tables. Surfaced here (not only in the dry-run
                    // report) so a partial dump is obvious immediately
                    // after upload — operators can spot a 0-row
                    // card_holders or withdrawals row and re-export
                    // without having to run the dry-run first. The
                    // EXPECTED_NON_EMPTY_TABLES guard in handle_upload()
                    // already blocks the upload when these come in
                    // empty, so anything we render here is purely
                    // informational; we still highlight zero-row rows
                    // in red so a future change to the guard list
                    // doesn't bury the signal.
                    $secondary_tables = [
                        'card_holders'     => __('Fintava virtual wallets', 'matrix-mlm'),
                        'cards'            => __('Fintava cards', 'matrix-mlm'),
                        'deposits'         => __('Deposits', 'matrix-mlm'),
                        'withdrawals'      => __('Withdrawals', 'matrix-mlm'),
                        'commissions'      => __('Commissions', 'matrix-mlm'),
                        'pins'             => __('E-pins', 'matrix-mlm'),
                        'support_tickets'  => __('Support tickets', 'matrix-mlm'),
                        'support_messages' => __('Ticket replies', 'matrix-mlm'),
                        'subscribers'      => __('Subscribers', 'matrix-mlm'),
                    ];
                    $any_secondary = false;
                    foreach ($secondary_tables as $key => $label) {
                        if (isset($stats[$key])) { $any_secondary = true; break; }
                    }
                    if ($any_secondary):
                    ?>
                        <table class="widefat striped" style="max-width:480px;margin:8px 0 16px;">
                            <thead>
                                <tr>
                                    <th><?php _e('Table', 'matrix-mlm'); ?></th>
                                    <th style="width:120px;text-align:right;"><?php _e('Rows Staged', 'matrix-mlm'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($secondary_tables as $key => $label):
                                    if (!isset($stats[$key])) continue;
                                    $count = (int) $stats[$key];
                                    // commissions is allowed to be empty
                                    // legitimately (fresh install with no
                                    // commission events). Don't paint it
                                    // red in that case.
                                    $is_empty_concern = ($count === 0 && $key !== 'commissions');
                                ?>
                                    <tr<?php echo $is_empty_concern ? ' style="background:#fef0f0;"' : ''; ?>>
                                        <td><?php echo esc_html($label); ?> <code><?php echo esc_html($key); ?></code></td>
                                        <td style="text-align:right;"><?php echo number_format($count); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <?php if (in_array($status, ['staged'], true)): ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('matrix_mlm_import_dryrun'); ?>
                            <input type="hidden" name="action" value="matrix_mlm_import_dryrun">
                            <button type="submit" class="button button-primary"><?php _e('Run Dry-Run Analysis', 'matrix-mlm'); ?></button>
                        </form>
                    <?php endif; ?>

                    <?php if (is_array($analysis)): ?>
                        <table class="widefat striped" style="max-width:900px;margin-top:16px;">
                            <tbody>
                                <tr><th><?php _e('Total Laravel users', 'matrix-mlm'); ?></th><td><?php echo (int) ($analysis['total_users'] ?? 0); ?></td></tr>
                                <tr><th><?php _e('Active', 'matrix-mlm'); ?> / <?php _e('Banned', 'matrix-mlm'); ?></th>
                                    <td><?php echo (int) $analysis['active_users']; ?> / <?php echo (int) $analysis['banned_users']; ?></td></tr>
                                <tr><th><?php _e('Placed in tree', 'matrix-mlm'); ?></th><td><?php echo (int) $analysis['placed_users']; ?></td></tr>
                                <tr><th><?php _e('Unplaced (will be spillover-placed)', 'matrix-mlm'); ?></th><td><?php echo (int) $analysis['unplaced_users']; ?></td></tr>
                                <tr><th><?php _e('Users with no active plan', 'matrix-mlm'); ?></th><td><?php echo (int) $analysis['no_plan_users']; ?></td></tr>
                                <tr><th><?php _e('KYC verified', 'matrix-mlm'); ?></th><td><?php echo (int) $analysis['kyc_verified']; ?></td></tr>
                                <tr><th><?php _e('Email verified', 'matrix-mlm'); ?></th><td><?php echo (int) $analysis['email_verified']; ?></td></tr>
                                <tr><th><?php _e('2FA enabled', 'matrix-mlm'); ?></th><td><?php echo (int) $analysis['twofa_enabled']; ?></td></tr>
                                <tr><th><?php _e('Plans / level rows', 'matrix-mlm'); ?></th>
                                    <td><?php echo (int) $analysis['plans_count']; ?> / <?php echo (int) $analysis['levels_count']; ?></td></tr>
                                <tr><th><?php _e('Email collisions with existing WP users', 'matrix-mlm'); ?></th>
                                    <td><?php echo (int) $analysis['email_collisions_existing']; ?></td></tr>
                                <tr><th><?php _e('Username collisions with existing WP users', 'matrix-mlm'); ?></th>
                                    <td><?php echo (int) $analysis['username_collisions_existing']; ?></td></tr>
                                <tr><th><?php _e('Internal duplicate emails', 'matrix-mlm'); ?></th>
                                    <td><?php echo (int) $analysis['internal_duplicate_emails']; ?></td></tr>
                                <tr><th><?php _e('Orphan sponsor refs', 'matrix-mlm'); ?></th>
                                    <td><?php echo (int) $analysis['orphan_sponsors']; ?></td></tr>
                                <tr><th><?php _e('Orphan parent refs', 'matrix-mlm'); ?></th>
                                    <td><?php echo (int) $analysis['orphan_parents']; ?></td></tr>
                            </tbody>
                        </table>
                        <?php if (!empty($analysis['issues'])): ?>
                            <div style="margin-top:16px;padding:12px;background:#fff8e1;border-left:4px solid #f0b849;">
                                <strong><?php _e('Notes:', 'matrix-mlm'); ?></strong>
                                <ul style="margin-left:20px;list-style:disc;">
                                    <?php foreach ($analysis['issues'] as $issue): ?>
                                        <li><?php echo esc_html($issue); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($analysis['pr2_stats'])): ?>
                            <h3 style="margin-top:24px;"><?php _e('Financial / Operational Tables', 'matrix-mlm'); ?></h3>
                            <table class="widefat striped" style="max-width:900px;">
                                <thead>
                                    <tr>
                                        <th><?php _e('Table', 'matrix-mlm'); ?></th>
                                        <th style="width:140px;"><?php _e('Rows Staged', 'matrix-mlm'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($analysis['pr2_stats'] as $key => $info): ?>
                                        <tr>
                                            <td><?php echo esc_html($info['label']); ?> <code><?php echo esc_html($key); ?></code></td>
                                            <td><?php echo number_format((int) $info['count']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (isset($analysis['fintava_with_wallet_id'])): ?>
                                        <tr>
                                            <td><em><?php _e('… of which Fintava wallets carry a wallet_id', 'matrix-mlm'); ?></em></td>
                                            <td><?php echo number_format((int) $analysis['fintava_with_wallet_id']); ?></td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php if (isset($analysis['fintava_with_account'])): ?>
                                        <tr>
                                            <td><em><?php _e('… of which Fintava wallets carry an account_number', 'matrix-mlm'); ?></em></td>
                                            <td><?php echo number_format((int) $analysis['fintava_with_account']); ?></td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                            <p class="description" style="margin-top:8px;">
                                <?php _e('After commit, the importer auto-runs the existing <strong>Fintava bank-code backfill</strong> as the final phase. That call resolves the partner bank (Globus / Wema / Providus / etc.) for every wallet via the Fintava API so members see live balances on first login. Wallets that remain unresolved after backfill appear in the Fintava migration page for manual review.', 'matrix-mlm'); ?>
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Step 3: Commit -->
            <?php if (in_array($status, ['analyzed', 'committing', 'completed', 'failed'], true)): ?>
                <div class="matrix-admin-card" style="padding:16px;border:1px solid #ddd;background:#fff;margin-top:16px;">
                    <h2><?php _e('Step 3 — Commit Import', 'matrix-mlm'); ?></h2>
                    <?php if ($status === 'analyzed'): ?>
                        <p style="color:#b32d2e;"><strong><?php _e('Warning:', 'matrix-mlm'); ?></strong>
                            <?php _e('This will create WordPress users and matrix positions for every Laravel user. Existing WP users on this site are kept; colliding Laravel rows are skipped. The action cannot be undone — back up your WordPress database first.', 'matrix-mlm'); ?></p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                              onsubmit="return confirm('<?php echo esc_js(__('Commit the import? This creates WordPress users and matrix positions and cannot be undone.', 'matrix-mlm')); ?>');">
                            <?php wp_nonce_field('matrix_mlm_import_commit'); ?>
                            <input type="hidden" name="action" value="matrix_mlm_import_commit">
                            <button type="submit" class="button button-primary"><?php _e('Commit Import', 'matrix-mlm'); ?></button>
                        </form>
                    <?php elseif ($status === 'committing'): ?>
                        <p><?php _e('Import in progress — keep this page open. Each phase is processed in chunks so the request never times out.', 'matrix-mlm'); ?></p>
                        <div id="matrix-import-progress" style="padding:12px;background:#f0f6fc;border-left:4px solid #2271b1;font-family:monospace;">
                            <div><strong><?php _e('Phase:', 'matrix-mlm'); ?></strong> <span id="mi-phase"><?php echo esc_html($state['phase']); ?></span></div>
                            <div><strong><?php _e('Status:', 'matrix-mlm'); ?></strong> <span id="mi-status">running…</span></div>
                            <div><strong><?php _e('Last message:', 'matrix-mlm'); ?></strong> <span id="mi-message">—</span></div>
                            <pre id="mi-stats" style="margin-top:8px;padding:8px;background:#fff;font-size:11px;"><?php echo esc_html(wp_json_encode($state['stats'], JSON_PRETTY_PRINT)); ?></pre>
                        </div>
                        <script>
                        (function(){
                            var nonce = '<?php echo esc_js($ajax_nonce); ?>';
                            var ajaxurl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                            function tick() {
                                var data = new FormData();
                                data.append('action', 'matrix_mlm_import_chunk');
                                data.append('nonce', nonce);
                                fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
                                    .then(function(r){ return r.json(); })
                                    .then(function(j){
                                        if (!j.success) {
                                            document.getElementById('mi-status').textContent = 'FAILED';
                                            document.getElementById('mi-message').textContent = (j.data && j.data.message) || 'Unknown error';
                                            return;
                                        }
                                        var d = j.data;
                                        document.getElementById('mi-phase').textContent = d.next_phase || d.phase;
                                        document.getElementById('mi-status').textContent = d.status;
                                        document.getElementById('mi-message').textContent = d.message || '';
                                        document.getElementById('mi-stats').textContent = JSON.stringify(d.stats, null, 2);
                                        if (d.status === 'completed') {
                                            setTimeout(function(){ window.location.reload(); }, 800);
                                        } else if (d.status === 'committing') {
                                            setTimeout(tick, 200);
                                        }
                                    })
                                    .catch(function(err){
                                        document.getElementById('mi-status').textContent = 'NETWORK ERROR';
                                        document.getElementById('mi-message').textContent = err.toString();
                                    });
                            }
                            tick();
                        })();
                        </script>
                    <?php elseif ($status === 'completed'): ?>
                        <div style="padding:12px;background:#edfaef;border-left:4px solid #2c8b3a;">
                            <strong><?php _e('Import complete!', 'matrix-mlm'); ?></strong>
                            <pre style="margin-top:8px;font-size:11px;"><?php echo esc_html(wp_json_encode($state['stats'], JSON_PRETTY_PRINT)); ?></pre>
                            <p>
                                <?php _e('Members can now log in with their Laravel usernames/emails and existing passwords. Their Fintava wallet balances appear live on the dashboard, deposit/withdrawal history is visible, commission earnings are restored, and any open support tickets carry over with their full reply chain.', 'matrix-mlm'); ?>
                            </p>
                            <p>
                                <?php _e('Next: head to <strong>Fintava → Backfill Bank Codes</strong> on the Migration page if any wallets are still flagged as missing partner-bank info, then update the webhook URL in your Fintava merchant dashboard to point at this WordPress site so future deposits credit the right user.', 'matrix-mlm'); ?>
                            </p>
                        </div>
                    <?php elseif ($status === 'failed'): ?>
                        <div style="padding:12px;background:#fef0f0;border-left:4px solid #b32d2e;">
                            <strong><?php _e('Import failed.', 'matrix-mlm'); ?></strong>
                            <p><?php _e('Use the Reset button below, fix the underlying issue, and re-upload.', 'matrix-mlm'); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Reset -->
            <?php if ($status !== 'idle'): ?>
                <div class="matrix-admin-card" style="padding:16px;border:1px solid #ddd;background:#fff;margin-top:16px;">
                    <h2><?php _e('Reset Import State', 'matrix-mlm'); ?></h2>
                    <p><?php _e('Drops the staging tables and clears the import state. Does NOT remove WordPress users or matrix positions that have already been committed — only an SQL restore can do that.', 'matrix-mlm'); ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                          onsubmit="return confirm('<?php echo esc_js(__('Reset import state? Staging tables will be dropped.', 'matrix-mlm')); ?>');">
                        <?php wp_nonce_field('matrix_mlm_import_reset'); ?>
                        <input type="hidden" name="action" value="matrix_mlm_import_reset">
                        <button type="submit" class="button button-link-delete"><?php _e('Reset', 'matrix-mlm'); ?></button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
