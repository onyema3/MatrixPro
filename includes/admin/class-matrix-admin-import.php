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
 * Architecture
 *  - All hooks register via init() so cron/AJAX/admin-post wire up
 *    on every request. Every method is static; there is no
 *    per-instance state.
 *  - Upload streams the .sql/.sql.gz file into wp-content/uploads/
 *    matrix-mlm-imports/ (deny-all .htaccess, same as backups).
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

    /** Tables PR 1 actually stages and consumes. */
    const STAGED_TABLES = ['users', 'plans', 'levels'];

    /** Default tree shape — pulled from Laravel general_settings (3×9). */
    const DEFAULT_WIDTH = 3;
    const DEFAULT_DEPTH = 9;

    /** Chunk sizes per phase — sized to fit comfortably under a 30s timeout. */
    const CHUNK_PLANS     = 50;
    const CHUNK_LEVELS    = 200;
    const CHUNK_USERS     = 200;
    const CHUNK_POSITIONS = 300;
    const CHUNK_PARENTS   = 500;
    const CHUNK_RECALC    = 500;

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
     * Return the directory where uploaded Laravel dumps live. Created
     * lazily with the same .htaccess + index.html guards the backup
     * tool uses, since dumps contain bcrypt password hashes and
     * full member contact details that should never be web-readable.
     */
    private static function get_import_dir() {
        $uploads = wp_upload_dir();
        $dir = trailingslashit($uploads['basedir']) . 'matrix-mlm-imports';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
        $h = $dir . '/.htaccess';
        if (!file_exists($h)) {
            @file_put_contents(
                $h,
                "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" .
                "<IfModule !mod_authz_core.c>\nOrder Deny,Allow\nDeny from all\n</IfModule>\n"
            );
        }
        $i = $dir . '/index.html';
        if (!file_exists($i)) {
            @file_put_contents($i, '');
        }
        return $dir;
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
     * Replay one Laravel INSERT against the matching staging table,
     * by rewriting just the table name in the INSERT INTO clause.
     * Everything else (column list, VALUES tuple format, escapes) is
     * already valid MariaDB/MySQL syntax.
     */
    private static function execute_staging_insert($table, $stmt) {
        global $wpdb;
        $staging = self::staging_table($table);
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

        // If we got 0 users, the dump probably contained the wrong
        // tables — don't silently advance the state machine.
        if ($stats['users'] === 0) {
            self::redirect_with_notice('error', __('The uploaded file did not contain any rows for the `users` table. Make sure you exported data (not just structure) for the relevant tables.', 'matrix-mlm'));
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
            'plans',
            'levels',
            'users',
            'positions_placed',
            'positions_spill',
            'parents',
            'recalc_levels',
            'recalc_downline',
        ];
        $i = array_search($current, $order, true);
        return ($i !== false && $i + 1 < count($order)) ? $order[$i + 1] : null;
    }

    /** Dispatch one chunk to the right phase handler. */
    private static function process_chunk($phase, $cursor) {
        switch ($phase) {
            case 'plans':            return self::process_plans($cursor);
            case 'levels':           return self::process_levels($cursor);
            case 'users':            return self::process_users($cursor);
            case 'positions_placed': return self::process_positions_placed($cursor);
            case 'positions_spill':  return self::process_positions_spillover($cursor);
            case 'parents':          return self::process_parents($cursor);
            case 'recalc_levels':    return self::process_recalc_levels($cursor);
            case 'recalc_downline':  return self::process_recalc_downline($cursor);
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
            $wpdb->insert($wpdb->prefix . 'matrix_user_meta', [
                'user_id'            => $new_user_id,
                'balance'            => $row->balance,
                'referral_code'      => null, // generated lazily by plan engine
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
                <p><?php _e('Run on your Laravel server:', 'matrix-mlm'); ?>
                <br><code>mysqldump -u USER -p --no-create-info DBNAME users plans levels card_holders cards deposits withdrawals commissions pins support_tickets support_messages subscribers > laravel-data.sql</code></p>
                <p><?php _e('Then upload that file here:', 'matrix-mlm'); ?></p>
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
                            <p><?php _e('Members can now log in with their Laravel usernames/emails and existing passwords. Run PR 2 next to import deposits, withdrawals, commissions, e-pins, and Fintava wallets.', 'matrix-mlm'); ?></p>
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
