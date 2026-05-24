<?php
/**
 * Database Backup & Restore
 *
 * Provides:
 *   - Manual SQL dump generation of every {prefix}matrix_* table
 *   - Listing / downloading / deleting / restoring backups
 *   - Restoring from an uploaded .sql or .sql.gz file
 *   - A weekly WP-Cron event that emails the freshest backup to a
 *     configurable admin address and prunes old files based on a
 *     retention setting
 *
 * The class is intentionally implemented with all-static methods so
 * its hooks register exactly once even if something else instantiates
 * it. Nothing on this class needs per-instance state — every operation
 * (create, list, delete, restore, send) is either a pure file/SQL
 * action or a static read of plugin options.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Backup {

    /** WP-Cron hook name used for the weekly automatic backup. */
    const CRON_HOOK = 'matrix_mlm_weekly_backup';

    /** Option keys. Kept on the class so callers don't typo them. */
    const OPT_ENABLED   = 'matrix_mlm_backup_weekly_enabled';
    const OPT_EMAIL     = 'matrix_mlm_backup_email';
    const OPT_RETENTION = 'matrix_mlm_backup_retention';
    const OPT_LAST_RUN  = 'matrix_mlm_backup_last_run';

    /**
     * Register every hook this module depends on. Idempotent — safe
     * to call from multiple bootstrap paths because all callbacks are
     * static, so add_action() de-duplicates them by callable identity.
     *
     * Called from Matrix_MLM_Core::run() so cron and admin-post
     * handlers are wired up on every request (admin, front-end, and
     * wp-cron alike).
     */
    public static function init() {
        // Form / link handlers — all routed through admin-post.php so
        // they get a clean redirect after processing instead of being
        // tangled up with the dashboard render path.
        add_action('admin_post_matrix_mlm_run_backup',           [__CLASS__, 'handle_run_backup']);
        add_action('admin_post_matrix_mlm_restore_backup',       [__CLASS__, 'handle_restore_backup']);
        add_action('admin_post_matrix_mlm_delete_backup',        [__CLASS__, 'handle_delete_backup']);
        add_action('admin_post_matrix_mlm_download_backup',      [__CLASS__, 'handle_download_backup']);
        add_action('admin_post_matrix_mlm_save_backup_settings', [__CLASS__, 'handle_save_settings']);

        // Weekly cron handler. WordPress ships a 'weekly' schedule
        // since 5.4 (this plugin requires 5.8+) so we don't need to
        // register a custom cron_schedules filter.
        add_action(self::CRON_HOOK, [__CLASS__, 'run_weekly_backup']);

        // Self-healing scheduler: every admin pageload, reconcile the
        // scheduled event with the current option value. This means
        // toggling the setting on/off updates the schedule without
        // requiring a plugin reactivation, and also re-creates the
        // event if WP-Cron's option ever gets wiped (e.g. by a
        // hosting-provider cleanup script). Cheap — the inner
        // function is two option reads and at most one schedule call.
        add_action('admin_init', [__CLASS__, 'maybe_schedule_cron']);
    }

    /* ------------------------------------------------------------------
     * Cron scheduling
     * ------------------------------------------------------------------ */

    /**
     * Reconcile the weekly cron event with the OPT_ENABLED option.
     * Adds the event when the toggle is on and missing, removes it
     * when the toggle is off but a stale event still exists.
     */
    public static function maybe_schedule_cron() {
        $enabled = (int) get_option(self::OPT_ENABLED, 0);
        $next    = wp_next_scheduled(self::CRON_HOOK);

        if ($enabled && !$next) {
            // Stagger the first run by an hour so an admin who toggles
            // the setting on doesn't immediately get hit with a backup
            // job in the same request cycle.
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'weekly', self::CRON_HOOK);
        } elseif (!$enabled && $next) {
            wp_unschedule_event($next, self::CRON_HOOK);
        }
    }

    /**
     * Unconditionally clear the weekly event. Called from the plugin
     * deactivator so a deactivated plugin doesn't keep firing cron.
     */
    public static function clear_cron() {
        $next = wp_next_scheduled(self::CRON_HOOK);
        if ($next) {
            wp_unschedule_event($next, self::CRON_HOOK);
        }
        // Belt-and-braces: clear any other scheduled instances.
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /* ------------------------------------------------------------------
     * Filesystem helpers
     * ------------------------------------------------------------------ */

    /**
     * Return (and create on first call) the directory backups are
     * written to. Lives under wp-content/uploads/ so it inherits the
     * existing uploads writability checks.
     *
     * Audit H9 hardening: backups carry every member's bcrypt hash,
     * BVN, KYC docs, and (until PR #228) plaintext TOTP secrets.
     * Direct file access from the web has to be blocked on every
     * server flavour:
     *
     *   - Apache: .htaccess "Require all denied" stanza.
     *   - IIS:    web.config <denyUrlSequences> + <httpErrors errorMode>.
     *   - Nginx:  cannot be configured per-directory; the docblock
     *             on Matrix_MLM_Admin_Backup::render() points operators
     *             at a copy-paste server-block snippet, and the
     *             unguessable filename below is the primary defence
     *             on this flavour.
     *   - Generic fallback: index.html so a misconfigured server
     *             that has directory-listing on can't enumerate.
     *
     * Combined with the random suffix in create_backup() this means
     * an attacker on Nginx can't guess a backup URL even if they
     * know the deployment time.
     */
    public static function get_backup_dir() {
        $uploads = wp_upload_dir();
        $dir = trailingslashit($uploads['basedir']) . 'matrix-mlm-backups';

        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        // Always (re)write the protection files even if the directory
        // existed — they're cheap and a previous admin clean-up may
        // have removed them.
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            // Apache 2.2 + 2.4 compatible "deny all" stanza.
            @file_put_contents(
                $htaccess,
                "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" .
                "<IfModule !mod_authz_core.c>\nOrder Deny,Allow\nDeny from all\n</IfModule>\n"
            );
        }

        // IIS equivalent. <denyUrlSequences> blocks any path that
        // resolves into this directory; <httpErrors errorMode="Custom">
        // ensures the 403 doesn't accidentally fall through to a
        // detailed error response that leaks the local path. Wrapping
        // in a configBuilders-aware block isn't necessary because
        // every supported IIS version (7.5+) honours this shape.
        $webconfig = $dir . '/web.config';
        if (!file_exists($webconfig)) {
            @file_put_contents(
                $webconfig,
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" .
                "<configuration>\n" .
                "  <system.webServer>\n" .
                "    <security>\n" .
                "      <requestFiltering>\n" .
                "        <denyUrlSequences>\n" .
                "          <add sequence=\".sql\" />\n" .
                "          <add sequence=\".gz\" />\n" .
                "        </denyUrlSequences>\n" .
                "        <hiddenSegments>\n" .
                "          <add segment=\"matrix-mlm-backups\" />\n" .
                "        </hiddenSegments>\n" .
                "      </requestFiltering>\n" .
                "    </security>\n" .
                "    <httpErrors errorMode=\"Custom\" existingResponse=\"Replace\">\n" .
                "      <remove statusCode=\"403\" />\n" .
                "      <error statusCode=\"403\" path=\"\" responseMode=\"ExecuteURL\" />\n" .
                "    </httpErrors>\n" .
                "  </system.webServer>\n" .
                "</configuration>\n"
            );
        }

        $index = $dir . '/index.html';
        if (!file_exists($index)) {
            @file_put_contents($index, '');
        }

        // Belt-and-braces index.php for hosts that prioritise PHP
        // over .html in DirectoryIndex/index priority. Returns 403
        // unconditionally — does NOT route to the WP frontend (which
        // would be a wp_safe_redirect and reveal the directory exists).
        $index_php = $dir . '/index.php';
        if (!file_exists($index_php)) {
            @file_put_contents($index_php, "<?php\nhttp_response_code(403);\nexit;\n");
        }

        return $dir;
    }

    /**
     * List backup files in the backup directory, newest first.
     */
    public static function list_backups() {
        $dir = self::get_backup_dir();
        $files = glob($dir . '/matrix-backup-*.{sql,sql.gz}', GLOB_BRACE);
        if (!is_array($files)) {
            $files = [];
        }

        $list = [];
        foreach ($files as $file) {
            $list[] = [
                'filename' => basename($file),
                'path'     => $file,
                'size'     => filesize($file),
                'mtime'    => filemtime($file),
            ];
        }
        usort($list, function ($a, $b) {
            return $b['mtime'] <=> $a['mtime'];
        });
        return $list;
    }

    /* ------------------------------------------------------------------
     * Backup creation
     * ------------------------------------------------------------------ */

    /**
     * Generate a SQL dump of every {prefix}matrix_* table.
     *
     * @param string $trigger 'manual' or 'auto' — recorded in the
     *                        filename and metadata for traceability.
     * @return array|WP_Error On success an array with keys
     *                        path, filename, tables, rows, size.
     */
    public static function create_backup($trigger = 'manual') {
        global $wpdb;

        // Don't let the request die mid-dump on a large install.
        // Cron context already has no time limit, but a manual run
        // from the admin screen would otherwise hit the default
        // 30-second cap on shared hosting.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $dir = self::get_backup_dir();

        $stamp     = date('Y-m-d_H-i-s');
        $use_gzip  = function_exists('gzopen');
        $ext       = $use_gzip ? 'sql.gz' : 'sql';
        // sanitize_file_name keeps the trigger string filesystem-safe
        // even though we only ever pass 'manual'/'auto' today.
        $trigger_s = sanitize_file_name($trigger ?: 'manual');
        // Audit H9: append 32 hex chars of cryptographic randomness
        // (16 bytes from the OS CSPRNG) to the filename so the URL is
        // unguessable on hosts where direct file access can't be
        // blocked at the webserver layer (notably Nginx, which doesn't
        // honour .htaccess). Effective keyspace is 2^128 — an attacker
        // who knows the deployment time can't enumerate the file even
        // with directory listing on. Backups generated before this
        // change retain their old (predictable) names; ops with such
        // backups should re-create them after deploying this PR.
        $token = bin2hex(random_bytes(16));
        $filename  = "matrix-backup-{$stamp}-{$trigger_s}-{$token}.{$ext}";
        $filepath  = $dir . '/' . $filename;

        // Discover plugin tables. Scope to the wp prefix so a multisite
        // / shared-DB install with another plugin called "matrix_*"
        // doesn't get its tables silently swept into our dump.
        $like   = $wpdb->esc_like($wpdb->prefix . 'matrix_') . '%';
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));

        if (empty($tables)) {
            return new WP_Error(
                'matrix_backup_no_tables',
                __('No Matrix MLM tables were found to back up.', 'matrix-mlm')
            );
        }

        // Open output stream. Wrap fwrite/gzwrite behind closures so
        // the rest of the function reads the same regardless of
        // compression support.
        if ($use_gzip) {
            $handle = @gzopen($filepath, 'wb6');
            if (!$handle) {
                return new WP_Error('matrix_backup_open', __('Could not open backup file for writing.', 'matrix-mlm'));
            }
            $write = function ($s) use ($handle) { gzwrite($handle, $s); };
            $close = function () use ($handle) { gzclose($handle); };
        } else {
            $handle = @fopen($filepath, 'wb');
            if (!$handle) {
                return new WP_Error('matrix_backup_open', __('Could not open backup file for writing.', 'matrix-mlm'));
            }
            $write = function ($s) use ($handle) { fwrite($handle, $s); };
            $close = function () use ($handle) { fclose($handle); };
        }

        // Header — useful for forensic checks if a dump ever fails to
        // restore on a different environment.
        $write("-- Matrix MLM Pro database backup\n");
        $write('-- Site: ' . home_url() . "\n");
        $write('-- Generated: ' . current_time('mysql') . " (trigger: {$trigger_s})\n");
        $write('-- Plugin version: ' . (defined('MATRIX_MLM_VERSION') ? MATRIX_MLM_VERSION : 'unknown') . "\n");
        $write('-- DB version: ' . (defined('MATRIX_MLM_DB_VERSION') ? MATRIX_MLM_DB_VERSION : 'unknown') . "\n");
        $write('-- WordPress prefix: ' . $wpdb->prefix . "\n\n");

        // FK checks off so DROP TABLE inside the dump won't fail on
        // tables referenced by another. NO_AUTO_VALUE_ON_ZERO so 0
        // PKs (rare but possible in seed data) round-trip correctly.
        $write("SET FOREIGN_KEY_CHECKS=0;\n");
        $write("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

        $total_rows = 0;

        foreach ($tables as $table) {
            // CREATE TABLE — pulled from SHOW CREATE TABLE so we keep
            // engine, charset, indexes, defaults, and AUTO_INCREMENT
            // exactly as the live schema has them.
            $create_row = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
            if (!$create_row || !isset($create_row[1])) {
                continue;
            }

            $write("--\n-- Table: {$table}\n--\n\n");
            $write("DROP TABLE IF EXISTS `{$table}`;\n");
            $write($create_row[1] . ";\n\n");

            // INSERT data in batches so memory stays bounded even on
            // large tables (commission ledgers, transfer histories).
            $chunk  = 500;
            $offset = 0;
            while (true) {
                $rows = $wpdb->get_results(
                    "SELECT * FROM `{$table}` LIMIT {$chunk} OFFSET {$offset}",
                    ARRAY_A
                );
                if (empty($rows)) {
                    break;
                }

                $columns  = array_keys($rows[0]);
                $col_list = '`' . implode('`,`', $columns) . '`';
                $values_list = [];

                foreach ($rows as $row) {
                    $vals = [];
                    foreach ($columns as $col) {
                        $v = $row[$col];
                        if ($v === null) {
                            $vals[] = 'NULL';
                        } else {
                            // esc_sql is the WP-blessed wrapper around
                            // wpdb::_real_escape — handles the same
                            // quoting/encoding $wpdb->prepare() does
                            // for %s args.
                            $vals[] = "'" . esc_sql($v) . "'";
                        }
                    }
                    $values_list[] = '(' . implode(',', $vals) . ')';
                }

                $write("INSERT INTO `{$table}` ({$col_list}) VALUES\n" . implode(",\n", $values_list) . ";\n");

                $total_rows += count($rows);
                $offset     += $chunk;

                // Last partial chunk — bail before the next SELECT
                // returns an empty result.
                if (count($rows) < $chunk) {
                    break;
                }
            }
            $write("\n");
        }

        $write("SET FOREIGN_KEY_CHECKS=1;\n");
        $close();

        $size = file_exists($filepath) ? filesize($filepath) : 0;

        update_option(self::OPT_LAST_RUN, [
            'time'     => time(),
            'trigger'  => $trigger_s,
            'filename' => $filename,
            'tables'   => count($tables),
            'rows'     => $total_rows,
            'size'     => $size,
        ]);

        return [
            'path'     => $filepath,
            'filename' => $filename,
            'tables'   => count($tables),
            'rows'     => $total_rows,
            'size'     => $size,
        ];
    }

    /* ------------------------------------------------------------------
     * Backup management
     * ------------------------------------------------------------------ */

    /**
     * Delete a backup file by name. Confined to the backup directory:
     * the realpath() comparison means a maliciously crafted filename
     * containing ../ traversal can't reach files outside the dir.
     */
    public static function delete_backup($filename) {
        $filename = sanitize_file_name(basename($filename));
        if ($filename === '') {
            return false;
        }
        $dir  = self::get_backup_dir();
        $path = $dir . '/' . $filename;
        $real_dir = realpath($dir);
        $real_path = realpath($path);
        if (!$real_path || !$real_dir || strpos($real_path, $real_dir) !== 0) {
            return false;
        }
        return @unlink($real_path);
    }

    /**
     * Keep only the N newest backups; delete the rest. Called after
     * each scheduled run so the backup directory doesn't grow without
     * bound on long-lived installs.
     */
    public static function prune_old_backups($keep = null) {
        if ($keep === null) {
            $keep = (int) get_option(self::OPT_RETENTION, 5);
        }
        $keep = max(1, (int) $keep);

        $list = self::list_backups();
        if (count($list) <= $keep) {
            return 0;
        }

        $deleted = 0;
        foreach (array_slice($list, $keep) as $b) {
            if (@unlink($b['path'])) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /* ------------------------------------------------------------------
     * Restore
     * ------------------------------------------------------------------ */

    /**
     * Classify a single SQL statement for the restore allow-list.
     *
     * Audit H16: the previous restore loop ran every statement
     * verbatim with $wpdb->query, so a tampered backup file (or a
     * compromised admin) could embed `DROP USER`, `CREATE USER`,
     * `GRANT ALL`, `INSERT INTO wp_users`, `UPDATE wp_options`, etc.
     * That gave the restore path more authority than the rest of the
     * plugin combined: a single uploaded .sql could take over the
     * entire WordPress install, not just the Matrix MLM tables.
     *
     * The classifier rejects anything that isn't:
     *
     *   - One of our six expected DDL/DML keywords (CREATE TABLE,
     *     DROP TABLE, INSERT INTO, ALTER TABLE, TRUNCATE [TABLE],
     *     LOCK / UNLOCK TABLES — the last two are accepted as no-ops
     *     to keep us compatible with dumps from external tools), AND
     *
     *   - Targeting a table whose name starts with `{wpdb->prefix}matrix_`,
     *
     * OR
     *
     *   - One of a fixed allow-list of session-scoped SET statements
     *     (FOREIGN_KEY_CHECKS, SQL_MODE, NAMES, CHARACTER_SET_*) —
     *     these don't take a table identifier but are necessary for
     *     a clean restore.
     *
     * Anything else returns null and the caller refuses the row.
     *
     * @param string $stmt   Trimmed SQL statement (no trailing `;`).
     * @param string $prefix WordPress table prefix from $wpdb.
     * @return array|null    {kind, table} on accept; null on refuse.
     */
    private static function classify_statement($stmt, $prefix) {
        // Strip leading whitespace + leading comments (line and block)
        // for classification only — the actual query passes through
        // untouched.
        $head = ltrim((string) $stmt);
        $head = preg_replace('/^(?:--[^\n]*\n|\/\*[\s\S]*?\*\/\s*)*/', '', $head);
        $head = ltrim($head);

        if ($head === '') {
            return null;
        }

        // Allow-list of session-scoped SET statements. We pin to the
        // four keys our dump actually emits (and the two charset
        // variants third-party tools also emit) — refuse arbitrary
        // SET so an attacker can't, e.g., SET sql_log_bin=0 to make
        // their next statement invisible to replication.
        if (preg_match('/^SET\s+(@@)?(SESSION\s+|GLOBAL\s+)?(FOREIGN_KEY_CHECKS|SQL_MODE|NAMES|CHARACTER_SET_CLIENT|CHARACTER_SET_RESULTS|CHARACTER_SET_CONNECTION|TIME_ZONE)\b/i', $head)) {
            return ['kind' => 'set', 'table' => null];
        }

        // Match the DDL/DML keyword.
        $upper = strtoupper(substr($head, 0, 80));
        $kinds = [
            'CREATE TABLE'   => '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`/i',
            'DROP TABLE'     => '/^DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`([^`]+)`/i',
            'INSERT INTO'    => '/^INSERT\s+(?:IGNORE\s+)?INTO\s+`([^`]+)`/i',
            'ALTER TABLE'    => '/^ALTER\s+TABLE\s+`([^`]+)`/i',
            'TRUNCATE TABLE' => '/^TRUNCATE\s+(?:TABLE\s+)?`([^`]+)`/i',
        ];

        foreach ($kinds as $kind => $regex) {
            $kw = strtok($kind, ' '); // first word for the upper prefix probe
            if (strpos($upper, $kw) !== 0) {
                continue;
            }
            if (preg_match($regex, $head, $m)) {
                $table = $m[1];
                $expected_prefix = $prefix . 'matrix_';
                if (strpos($table, $expected_prefix) !== 0) {
                    return null;
                }
                return ['kind' => $kind, 'table' => $table];
            }
        }

        // LOCK / UNLOCK TABLES are sometimes present in dumps from
        // mysqldump-style tools. Accept LOCK only when every named
        // table is in our prefix; UNLOCK is a session-state statement
        // with no identifier and is always safe.
        if (preg_match('/^UNLOCK\s+TABLES\b/i', $head)) {
            return ['kind' => 'unlock', 'table' => null];
        }
        if (preg_match('/^LOCK\s+TABLES\s+(.+)$/is', $head, $m)) {
            $expected_prefix = $prefix . 'matrix_';
            // Each LOCK TABLES entry is `table` [AS alias] LOCK_TYPE
            // separated by commas.
            if (preg_match_all('/`([^`]+)`/', $m[1], $names)) {
                foreach ($names[1] as $tn) {
                    if (strpos($tn, $expected_prefix) !== 0) {
                        return null;
                    }
                }
                return ['kind' => 'lock', 'table' => null];
            }
            return null;
        }

        return null;
    }

    /**
     * Quote-aware "embedded second statement" detector.
     *
     * preg_split('/;\s*\n/', ...) in restore_backup() handles the
     * normal case where dumps emit one statement per line. An
     * attacker could craft a tampered backup with two statements on
     * the same line (no newline between them) and the splitter would
     * keep them as one chunk; classify_statement() would then match
     * on the head of the first one and accept the whole thing.
     *
     * In practice $wpdb->query() calls mysqli_query() which does NOT
     * support multi-statement execution by default — only the first
     * statement runs. But that's a driver-layer mitigation we don't
     * want to depend on; if a future refactor swaps in mysqli_multi_query
     * the smuggling becomes live again.
     *
     * This helper walks the statement looking for any `;` outside a
     * single-quoted SQL string. Honors backslash-escapes the way
     * mysqli_real_escape_string emits them. Returns true if a smuggled
     * second statement appears to be present (caller refuses), false
     * otherwise.
     */
    private static function contains_embedded_statement($stmt) {
        $len = strlen($stmt);
        $in_quote = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $stmt[$i];
            if ($in_quote) {
                if ($c === '\\' && $i + 1 < $len) {
                    // Skip the escaped char — covers \', \\, \n,
                    // \0, \032, etc.
                    $i++;
                    continue;
                }
                if ($c === "'") {
                    $in_quote = false;
                }
                continue;
            }
            if ($c === "'") {
                $in_quote = true;
                continue;
            }
            if ($c === ';') {
                $rest = ltrim(substr($stmt, $i + 1));
                if ($rest !== '') {
                    return true;
                }
                // Trailing `;` (rare — the splitter normally consumes
                // it) is harmless, treat as no smuggle.
                return false;
            }
        }
        return false;
    }

    /**
     * Restore a backup file by re-running its statements against the
     * live database. Designed to round-trip our own dumps; uploaded
     * dumps from other tools may work if they use the same statement
     * separator (a `;` followed by a newline) AND every statement
     * passes the allow-list in classify_statement().
     *
     * Audit H16 hardening: every parsed statement is classified
     * BEFORE execution. Rejected rows are recorded as errors but
     * do not abort the restore — the operator sees the count and
     * the first refused statement so they can investigate, while
     * legitimate statements in the same file still apply. If the
     * refusal count crosses 10 we abort the restore entirely
     * (almost certainly a mismatched / malicious file).
     *
     * @return array|WP_Error
     */
    public static function restore_backup($filepath) {
        global $wpdb;

        if (!file_exists($filepath)) {
            return new WP_Error('matrix_restore_missing', __('Backup file not found.', 'matrix-mlm'));
        }

        // Read the whole file into memory. Backups produced by this
        // plugin are scoped to plugin tables only, so they stay in
        // the megabytes range; a multi-GB site would need a streaming
        // restore which is out of scope for this iteration.
        if (substr($filepath, -3) === '.gz') {
            if (!function_exists('gzopen')) {
                return new WP_Error('matrix_restore_no_gzip', __('Server does not support gzip — cannot restore a .gz backup here.', 'matrix-mlm'));
            }
            $h = @gzopen($filepath, 'rb');
            if (!$h) {
                return new WP_Error('matrix_restore_open', __('Could not open backup file for reading.', 'matrix-mlm'));
            }
            $sql = '';
            while (!gzeof($h)) {
                $sql .= gzread($h, 65536);
            }
            gzclose($h);
        } else {
            $sql = @file_get_contents($filepath);
        }

        if ($sql === false || $sql === '') {
            return new WP_Error('matrix_restore_read', __('Could not read backup file.', 'matrix-mlm'));
        }

        // Strip our `-- ` header comment lines before splitting; the
        // header is informational and only appears at the top of dumps
        // produced by this plugin, but some restore-from-other-tool
        // dumps will contain similar comments mid-stream.
        $sql = preg_replace('/^--.*$/m', '', $sql);

        // Statement split: `;` immediately followed by a newline. Our
        // own dump emits exactly that pattern and avoids using the
        // sequence inside string literals (esc_sql escapes single
        // quotes; multi-line VALUES use `,\n` not `;\n`).
        $statements = preg_split('/;\s*\n/', $sql);

        $errors        = [];
        $executed      = 0;
        $refused       = 0;
        $first_refused = '';

        // Wrap the whole restore in a transaction so a failure halfway
        // doesn't leave the schema in a half-restored state on InnoDB
        // tables. DDL still auto-commits on MySQL, so this only fully
        // protects the INSERT phase, but it's a worthwhile safety net.
        $wpdb->query('START TRANSACTION');

        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') {
                continue;
            }

            // H16 allow-list gate. Refusing here keeps `DROP USER`,
            // `CREATE USER`, `GRANT`, `INSERT INTO wp_users`, etc.,
            // from running even if a malicious or tampered backup
            // file makes it past the upload gate.
            $cls = self::classify_statement($stmt, $wpdb->prefix);
            if ($cls === null || self::contains_embedded_statement($stmt)) {
                $refused++;
                if ($first_refused === '') {
                    // Log the first 80 characters of the refused
                    // statement so an operator can identify it
                    // without dumping multi-kilobyte INSERTs.
                    $first_refused = substr(preg_replace('/\s+/', ' ', $stmt), 0, 80);
                }
                if ($refused > 10) {
                    // Probably not our dump — abort.
                    $wpdb->query('ROLLBACK');
                    return new WP_Error(
                        'matrix_restore_refused',
                        __('Restore aborted — too many statements were refused by the allow-list. The backup file is not a Matrix MLM dump or has been tampered with.', 'matrix-mlm'),
                        [
                            'errors' => $errors,
                            'executed' => $executed,
                            'refused' => $refused,
                            'first_refused' => $first_refused,
                        ]
                    );
                }
                continue;
            }

            // Allow-listed. Run.
            $result = $wpdb->query($stmt);
            if ($result === false) {
                $errors[] = $wpdb->last_error;
                // Bail after a handful of errors so we don't flood the
                // log with cascading failures.
                if (count($errors) > 10) {
                    break;
                }
            } else {
                $executed++;
            }
        }

        if (!empty($errors)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error(
                'matrix_restore_partial',
                __('Restore failed — rolled back. See errors below.', 'matrix-mlm'),
                ['errors' => $errors, 'executed' => $executed, 'refused' => $refused]
            );
        }

        $wpdb->query('COMMIT');

        return [
            'executed' => $executed,
            'refused'  => $refused,
            'first_refused' => $first_refused,
        ];
    }

    /* ------------------------------------------------------------------
     * Weekly cron + email
     * ------------------------------------------------------------------ */

    /**
     * Cron callback: create a backup, email it to the admin, and
     * prune old files. Errors are logged rather than thrown — a
     * cron job that bubbles a fatal would mark the event as broken
     * in WP-Cron and skip future runs.
     */
    public static function run_weekly_backup() {
        $result = self::create_backup('auto');
        if (is_wp_error($result)) {
            error_log('[Matrix MLM] Weekly backup failed: ' . $result->get_error_message());
            return;
        }

        $sent = self::send_backup_email($result);
        if (!$sent) {
            error_log('[Matrix MLM] Weekly backup email send failed for ' . $result['filename']);
        }

        self::prune_old_backups();
    }

    /**
     * Send the freshly generated backup to the configured admin
     * address. Skips the attachment when the file would exceed the
     * configurable max attachment size and falls back to a download
     * link for the admin to grab from the Backup & Restore screen.
     *
     * @return bool wp_mail() return value (true on hand-off to MTA,
     *              false if mail was rejected or skipped).
     */
    public static function send_backup_email($info) {
        $to = get_option(self::OPT_EMAIL);
        if (empty($to) || !is_email($to)) {
            $to = get_option('admin_email');
        }
        if (empty($to) || !is_email($to)) {
            return false;
        }

        $site    = get_bloginfo('name');
        $subject = sprintf(__('[%s] Matrix MLM weekly database backup', 'matrix-mlm'), $site);
        $size_kb = round(($info['size'] ?? 0) / 1024, 1);

        $body  = __("A weekly backup of your Matrix MLM Pro database tables has been generated.", 'matrix-mlm') . "\n\n";
        $body .= sprintf(__('Site: %s', 'matrix-mlm'), home_url()) . "\n";
        $body .= sprintf(__('Filename: %s', 'matrix-mlm'), $info['filename']) . "\n";
        $body .= sprintf(__('Tables: %d', 'matrix-mlm'), $info['tables']) . "\n";
        $body .= sprintf(__('Rows: %d', 'matrix-mlm'), $info['rows']) . "\n";
        $body .= sprintf(__('Size: %s KB', 'matrix-mlm'), number_format($size_kb, 1)) . "\n";
        $body .= sprintf(__('Generated: %s', 'matrix-mlm'), current_time('mysql')) . "\n\n";

        // Many MTAs reject anything over ~25 MB. 20 MB default keeps
        // us comfortably under that on every popular provider while
        // still covering the vast majority of plugin databases. The
        // filter lets ops shops with a higher limit raise the cap.
        $max_attach  = (int) apply_filters('matrix_mlm_backup_max_attachment_bytes', 20 * 1024 * 1024);
        $attachments = [];

        if (!empty($info['size']) && $info['size'] <= $max_attach && file_exists($info['path'])) {
            $attachments[] = $info['path'];
            $body .= __('The backup file is attached to this email. Keep it in a safe place.', 'matrix-mlm') . "\n";
        } else {
            $admin_url = admin_url('admin.php?page=matrix-mlm-backup');
            $body .= __('The backup file is too large to email as an attachment. Download it from the admin panel:', 'matrix-mlm') . "\n";
            $body .= $admin_url . "\n";
        }

        return (bool) wp_mail($to, $subject, $body, [], $attachments);
    }

    /* ------------------------------------------------------------------
     * Form / link handlers (admin-post.php)
     * ------------------------------------------------------------------ */

    /** Capability gate shared by every admin-post handler. */
    private static function require_admin() {
        if (!current_user_can('manage_matrix_settings')) {
            wp_die(__('You do not have permission to manage backups.', 'matrix-mlm'));
        }
    }

    public static function handle_run_backup() {
        self::require_admin();
        check_admin_referer('matrix_mlm_run_backup');

        $result = self::create_backup('manual');
        if (is_wp_error($result)) {
            self::redirect_with_notice('error', $result->get_error_message());
        }

        self::redirect_with_notice(
            'success',
            sprintf(
                /* translators: 1: backup filename, 2: number of tables, 3: number of rows */
                __('Backup created: %1$s (%2$d tables, %3$d rows).', 'matrix-mlm'),
                $result['filename'],
                $result['tables'],
                $result['rows']
            )
        );
    }

    public static function handle_delete_backup() {
        self::require_admin();
        check_admin_referer('matrix_mlm_delete_backup');

        $filename = isset($_POST['filename']) ? sanitize_file_name(wp_unslash($_POST['filename'])) : '';
        if ($filename !== '' && self::delete_backup($filename)) {
            self::redirect_with_notice('success', __('Backup deleted.', 'matrix-mlm'));
        }
        self::redirect_with_notice('error', __('Could not delete backup.', 'matrix-mlm'));
    }

    public static function handle_download_backup() {
        self::require_admin();
        check_admin_referer('matrix_mlm_download_backup');

        $filename = isset($_GET['filename']) ? sanitize_file_name(wp_unslash($_GET['filename'])) : '';
        if ($filename === '') {
            wp_die(__('Backup file not specified.', 'matrix-mlm'));
        }

        $dir  = self::get_backup_dir();
        $path = $dir . '/' . $filename;

        // Path-traversal guard: the requested file must resolve to a
        // path inside the backup directory.
        $real_dir  = realpath($dir);
        $real_path = realpath($path);
        if (!$real_path || !$real_dir || strpos($real_path, $real_dir) !== 0 || !file_exists($real_path)) {
            wp_die(__('Backup file not found.', 'matrix-mlm'));
        }

        // Stream the file. nocache_headers() prevents intermediate
        // caches from holding onto a backup blob (these can contain
        // PII so they should never sit in a shared cache).
        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($real_path) . '"');
        header('Content-Length: ' . filesize($real_path));
        // Flush PHP's output buffer before readfile so we don't
        // accidentally double-buffer the entire dump in memory.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        readfile($real_path);
        exit;
    }

    public static function handle_restore_backup() {
        self::require_admin();
        check_admin_referer('matrix_mlm_restore_backup');

        // Two restore sources: a file already on the server (selected
        // from the backup list) or a fresh upload from the admin's
        // computer. Each path resolves $path before delegating to the
        // shared restore_backup() routine.
        $source = isset($_POST['source']) ? sanitize_text_field(wp_unslash($_POST['source'])) : 'existing';
        $path   = '';

        if ($source === 'upload') {
            if (empty($_FILES['backup_file']) || (($_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)) {
                self::redirect_with_notice('error', __('No file uploaded or upload failed.', 'matrix-mlm'));
            }

            $original = sanitize_file_name($_FILES['backup_file']['name']);
            // Restrict accepted extensions to what create_backup()
            // produces. Anything else is almost certainly a mistake
            // (e.g. an admin trying to restore a wp_users.csv export).
            if (!preg_match('/\.(sql|sql\.gz|gz)$/i', $original)) {
                self::redirect_with_notice('error', __('Only .sql or .sql.gz files can be restored.', 'matrix-mlm'));
            }

            $dir  = self::get_backup_dir();
            $path = $dir . '/restore-' . time() . '-' . $original;

            if (!@move_uploaded_file($_FILES['backup_file']['tmp_name'], $path)) {
                self::redirect_with_notice('error', __('Could not save uploaded file.', 'matrix-mlm'));
            }
        } else {
            $filename = isset($_POST['filename']) ? sanitize_file_name(wp_unslash($_POST['filename'])) : '';
            if ($filename === '') {
                self::redirect_with_notice('error', __('Backup file not specified.', 'matrix-mlm'));
            }
            $dir  = self::get_backup_dir();
            $path = $dir . '/' . $filename;

            // Same realpath confinement as the download handler.
            $real_dir  = realpath($dir);
            $real_path = realpath($path);
            if (!$real_path || !$real_dir || strpos($real_path, $real_dir) !== 0 || !file_exists($real_path)) {
                self::redirect_with_notice('error', __('Backup file not found.', 'matrix-mlm'));
            }
            $path = $real_path;
        }

        $result = self::restore_backup($path);
        if (is_wp_error($result)) {
            $data     = $result->get_error_data();
            $executed = isset($data['executed']) ? (int) $data['executed'] : 0;
            $errors   = isset($data['errors']) && is_array($data['errors']) ? $data['errors'] : [];
            $refused  = isset($data['refused']) ? (int) $data['refused'] : 0;
            $first_refused = isset($data['first_refused']) ? (string) $data['first_refused'] : '';
            $first    = $errors ? ' [' . esc_html($errors[0]) . ']' : '';
            $refused_note = $refused > 0
                ? sprintf(
                    /* translators: 1: number of refused statements, 2: first refused statement preview */
                    ' (%1$d statements refused by allow-list%2$s)',
                    $refused,
                    $first_refused !== '' ? ': ' . $first_refused : ''
                )
                : '';
            self::redirect_with_notice(
                'error',
                $result->get_error_message()
                    . sprintf(' (%d statements executed before failure)%s', $executed, $first)
                    . $refused_note
            );
        }

        $refused = isset($result['refused']) ? (int) $result['refused'] : 0;
        $msg = sprintf(__('Restore complete. %d statements executed.', 'matrix-mlm'), $result['executed']);
        if ($refused > 0) {
            $msg .= ' ' . sprintf(
                /* translators: %d: number of refused statements */
                __('%d non-Matrix statements were refused by the allow-list.', 'matrix-mlm'),
                $refused
            );
        }
        self::redirect_with_notice('success', $msg);
    }

    public static function handle_save_settings() {
        self::require_admin();
        check_admin_referer('matrix_mlm_backup_settings');

        $enabled   = !empty($_POST['weekly_enabled']) ? 1 : 0;
        $email_in  = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $retention = isset($_POST['retention']) ? max(1, intval($_POST['retention'])) : 5;

        // Empty / invalid email falls back to the WordPress admin
        // email so the feature never silently mails into the void.
        if (!$email_in || !is_email($email_in)) {
            $email_in = get_option('admin_email');
        }

        update_option(self::OPT_ENABLED, $enabled);
        update_option(self::OPT_EMAIL, $email_in);
        update_option(self::OPT_RETENTION, $retention);

        self::maybe_schedule_cron();

        self::redirect_with_notice('success', __('Backup settings saved.', 'matrix-mlm'));
    }

    /**
     * Redirect back to the Backup & Restore page with a one-shot
     * notice query string. exit() to make sure no further output is
     * sent (admin-post.php callbacks are otherwise free to keep
     * running and corrupt the redirect).
     */
    private static function redirect_with_notice($type, $message) {
        $url = add_query_arg([
            'page'           => 'matrix-mlm-backup',
            'matrix_notice'  => $type,
            'matrix_message' => rawurlencode($message),
        ], admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    /* ------------------------------------------------------------------
     * Page render
     * ------------------------------------------------------------------ */

    /**
     * Render the Backup & Restore admin page.
     *
     * Static so the menu callback can reference it without
     * instantiating the class — safe because every supporting method
     * is already static and reads/writes options directly.
     */
    public static function render() {
        if (!current_user_can('manage_matrix_settings')) {
            wp_die(__('You do not have permission to access this page.', 'matrix-mlm'));
        }

        // One-shot notice rendered from the redirect query string.
        if (!empty($_GET['matrix_notice'])) {
            $type  = sanitize_text_field(wp_unslash($_GET['matrix_notice']));
            $msg   = isset($_GET['matrix_message']) ? wp_unslash(rawurldecode($_GET['matrix_message'])) : '';
            $class = $type === 'success' ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }

        $backups   = self::list_backups();
        $enabled   = (int) get_option(self::OPT_ENABLED, 0);
        $email     = get_option(self::OPT_EMAIL, get_option('admin_email'));
        $retention = (int) get_option(self::OPT_RETENTION, 5);
        $last_run  = get_option(self::OPT_LAST_RUN);
        $next      = wp_next_scheduled(self::CRON_HOOK);
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php _e('Database Backup & Restore', 'matrix-mlm'); ?></h1>
            <p class="description">
                <?php _e('Backup and restore the Matrix MLM Pro database tables (every table prefixed with <code>matrix_</code>). WordPress core tables, posts, users, and other plugins are not included.', 'matrix-mlm'); ?>
            </p>

            <!-- Nginx hardening notice -->
            <div class="notice notice-warning" style="margin-top:16px;padding:12px 16px;">
                <p style="margin:0 0 8px;">
                    <strong><?php _e('Nginx server: extra step required.', 'matrix-mlm'); ?></strong>
                    <?php _e('Backup files include every member\'s bcrypt password hash and KYC data. The plugin auto-protects them on Apache (.htaccess) and IIS (web.config), but Nginx ignores per-directory config. Add this server-block snippet to deny direct access:', 'matrix-mlm'); ?>
                </p>
                <pre style="background:#f6f7f7;padding:10px 12px;margin:0;border:1px solid #ddd;font-size:12px;overflow:auto;">location ^~ /wp-content/uploads/matrix-mlm-backups/ {
    deny all;
    return 403;
}</pre>
                <p style="margin:8px 0 0;font-size:12px;color:#555;">
                    <?php _e('Even without this server block, backup filenames now include 32 hex chars of cryptographic randomness — an attacker cannot guess a backup URL even with directory listing on. The server block is defence-in-depth.', 'matrix-mlm'); ?>
                </p>
            </div>

            <!-- Run a backup now -->
            <div class="matrix-admin-card" style="padding:16px;border:1px solid #ddd;background:#fff;margin-top:16px;">
                <h2><?php _e('Run a Backup Now', 'matrix-mlm'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <?php wp_nonce_field('matrix_mlm_run_backup'); ?>
                    <input type="hidden" name="action" value="matrix_mlm_run_backup">
                    <button type="submit" class="button button-primary"><?php _e('Create Backup', 'matrix-mlm'); ?></button>
                    <span class="description"><?php _e('Generates a SQL dump of all Matrix MLM tables and stores it on the server. Recent backups appear in the list below.', 'matrix-mlm'); ?></span>
                </form>
                <?php if (is_array($last_run)): ?>
                    <p style="margin-top:10px;">
                        <strong><?php _e('Last backup:', 'matrix-mlm'); ?></strong>
                        <?php echo esc_html(date_i18n('M j, Y H:i', (int) ($last_run['time'] ?? 0))); ?>
                        — <code><?php echo esc_html($last_run['filename'] ?? ''); ?></code>
                        (<?php echo esc_html($last_run['trigger'] ?? ''); ?>,
                        <?php echo esc_html(number_format(((int) ($last_run['size'] ?? 0)) / 1024, 1)); ?> KB,
                        <?php echo (int) ($last_run['tables'] ?? 0); ?> <?php _e('tables', 'matrix-mlm'); ?>,
                        <?php echo (int) ($last_run['rows'] ?? 0); ?> <?php _e('rows', 'matrix-mlm'); ?>)
                    </p>
                <?php endif; ?>
            </div>

            <!-- Weekly schedule settings -->
            <div class="matrix-admin-card" style="padding:16px;border:1px solid #ddd;background:#fff;margin-top:16px;">
                <h2><?php _e('Weekly Automatic Backup', 'matrix-mlm'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('matrix_mlm_backup_settings'); ?>
                    <input type="hidden" name="action" value="matrix_mlm_save_backup_settings">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Enable Weekly Backup', 'matrix-mlm'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="weekly_enabled" value="1" <?php checked($enabled, 1); ?>>
                                    <?php _e('Run an automatic backup every week and email it to the recipient below.', 'matrix-mlm'); ?>
                                </label>
                                <?php if ($enabled && $next): ?>
                                    <p class="description">
                                        <?php printf(__('Next scheduled run: %s', 'matrix-mlm'), esc_html(date_i18n('M j, Y H:i', $next))); ?>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Recipient Email', 'matrix-mlm'); ?></th>
                            <td>
                                <input type="email" name="email" class="regular-text" value="<?php echo esc_attr($email); ?>" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                                <p class="description"><?php _e('The backup file will be attached to this address. Defaults to the WordPress admin email if left blank.', 'matrix-mlm'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Retention', 'matrix-mlm'); ?></th>
                            <td>
                                <input type="number" name="retention" min="1" max="50" value="<?php echo esc_attr($retention); ?>" class="small-text">
                                <p class="description"><?php _e('How many backup files to keep on the server. Older files are pruned automatically after each scheduled run.', 'matrix-mlm'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p><button type="submit" class="button button-primary"><?php _e('Save Settings', 'matrix-mlm'); ?></button></p>
                </form>
            </div>

            <!-- Existing backups -->
            <div class="matrix-admin-card" style="padding:16px;border:1px solid #ddd;background:#fff;margin-top:16px;">
                <h2><?php _e('Existing Backups', 'matrix-mlm'); ?></h2>
                <?php if (empty($backups)): ?>
                    <p><?php _e('No backups yet. Use "Create Backup" above to make your first one.', 'matrix-mlm'); ?></p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('Filename', 'matrix-mlm'); ?></th>
                                <th style="width:100px;"><?php _e('Size', 'matrix-mlm'); ?></th>
                                <th style="width:180px;"><?php _e('Created', 'matrix-mlm'); ?></th>
                                <th style="width:300px;"><?php _e('Actions', 'matrix-mlm'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($backups as $b): ?>
                                <tr>
                                    <td><code><?php echo esc_html($b['filename']); ?></code></td>
                                    <td><?php echo esc_html(number_format($b['size'] / 1024, 1)); ?> KB</td>
                                    <td><?php echo esc_html(date_i18n('M j, Y H:i', (int) $b['mtime'])); ?></td>
                                    <td>
                                        <a class="button"
                                           href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=matrix_mlm_download_backup&filename=' . rawurlencode($b['filename'])), 'matrix_mlm_download_backup')); ?>">
                                            <?php _e('Download', 'matrix-mlm'); ?>
                                        </a>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                              style="display:inline;"
                                              onsubmit="return confirm('<?php echo esc_js(__('Restore this backup? This will overwrite your current Matrix MLM tables.', 'matrix-mlm')); ?>');">
                                            <?php wp_nonce_field('matrix_mlm_restore_backup'); ?>
                                            <input type="hidden" name="action" value="matrix_mlm_restore_backup">
                                            <input type="hidden" name="source" value="existing">
                                            <input type="hidden" name="filename" value="<?php echo esc_attr($b['filename']); ?>">
                                            <button class="button" type="submit"><?php _e('Restore', 'matrix-mlm'); ?></button>
                                        </form>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                              style="display:inline;"
                                              onsubmit="return confirm('<?php echo esc_js(__('Delete this backup file?', 'matrix-mlm')); ?>');">
                                            <?php wp_nonce_field('matrix_mlm_delete_backup'); ?>
                                            <input type="hidden" name="action" value="matrix_mlm_delete_backup">
                                            <input type="hidden" name="filename" value="<?php echo esc_attr($b['filename']); ?>">
                                            <button class="button button-link-delete" type="submit"><?php _e('Delete', 'matrix-mlm'); ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Restore from upload -->
            <div class="matrix-admin-card" style="padding:16px;border:1px solid #ddd;background:#fff;margin-top:16px;">
                <h2><?php _e('Restore from Upload', 'matrix-mlm'); ?></h2>
                <p><?php _e('Upload a previously downloaded Matrix MLM backup file (.sql or .sql.gz). This will overwrite the existing Matrix MLM tables.', 'matrix-mlm'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                      enctype="multipart/form-data"
                      onsubmit="return confirm('<?php echo esc_js(__('Restore from this uploaded backup? This will overwrite your current Matrix MLM tables.', 'matrix-mlm')); ?>');">
                    <?php wp_nonce_field('matrix_mlm_restore_backup'); ?>
                    <input type="hidden" name="action" value="matrix_mlm_restore_backup">
                    <input type="hidden" name="source" value="upload">
                    <p><input type="file" name="backup_file" accept=".sql,.gz,.sql.gz" required></p>
                    <p><button type="submit" class="button button-primary"><?php _e('Upload & Restore', 'matrix-mlm'); ?></button></p>
                </form>
            </div>
        </div>
        <?php
    }
}
