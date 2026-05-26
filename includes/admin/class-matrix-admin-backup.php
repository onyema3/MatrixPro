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
     * written to.
     *
     * H9 hardening:
     *
     *   - Default location moved from wp-content/uploads/matrix-mlm-
     *     backups/ to wp-content/matrix-mlm-backups-private/. The
     *     uploads directory is unconditionally web-readable on every
     *     hosting stack, while wp-content/<plugin-private-dir>/ is
     *     only readable when the host's web server is configured to
     *     serve arbitrary wp-content paths AND no deny rule is in
     *     place — most modern stacks drop everything outside
     *     wp-content/uploads/, wp-content/themes/, and wp-content/
     *     plugins/ at the vhost level.
     *
     *   - Operators who want to fully eliminate the webroot exposure
     *     (the audit's "broken on Nginx/IIS" point) can define
     *     MATRIX_MLM_BACKUP_DIR in wp-config.php to point at any
     *     directory outside the webroot. The constant takes
     *     precedence over the default and is the recommended
     *     production setup.
     *
     *   - Cross-server deny rules are written on first creation:
     *       .htaccess        — Apache 2.2 + 2.4
     *       web.config       — IIS 7+
     *       nginx-deny.conf  — copy-pasteable snippet for Nginx
     *                          (Nginx does not honor per-directory
     *                          config files; the operator must splice
     *                          the snippet into their site vhost).
     *       index.html       — empty file as belt-and-braces against
     *                          directory listing on stacks that don't
     *                          honour any of the above.
     *
     *   - One-shot migration: if the legacy uploads-based path exists
     *     AND the new location is empty, move every backup file
     *     across. Idempotent — runs at most once because subsequent
     *     calls find the new directory non-empty (or the old one
     *     empty) and skip. Failed migrations leave both copies in
     *     place rather than dropping a backup.
     */
    public static function get_backup_dir() {
        // Operator override via wp-config.php constant. Documented
        // exit hatch for production setups that want backups outside
        // the webroot entirely. We do NOT validate that the path is
        // actually outside the webroot — that's the operator's
        // responsibility — but we do require that the directory
        // either exists or is creatable and writable.
        if (defined('MATRIX_MLM_BACKUP_DIR') && is_string(MATRIX_MLM_BACKUP_DIR) && MATRIX_MLM_BACKUP_DIR !== '') {
            $dir = rtrim(MATRIX_MLM_BACKUP_DIR, '/\\');
            if (!file_exists($dir)) {
                wp_mkdir_p($dir);
            }
            self::write_protection_files($dir);
            return $dir;
        }

        // Default: wp-content/matrix-mlm-backups-private/. Sibling of
        // uploads/, themes/, plugins/, but NOT inside any of them, so
        // a request for /wp-content/matrix-mlm-backups-private/<file>
        // is not a path that the typical Nginx wp-content rule
        // serves. The deny files give a second layer of protection on
        // hosts that DO serve arbitrary wp-content paths.
        $dir = rtrim(WP_CONTENT_DIR, '/\\') . '/matrix-mlm-backups-private';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        self::write_protection_files($dir);
        self::migrate_legacy_backups($dir);

        return $dir;
    }

    /**
     * Drop deny-all server config files into the backup directory for
     * Apache, IIS, and Nginx. Idempotent — only writes a file if it
     * does not already exist, so an operator who has customised any
     * of these is not overwritten.
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

        // Nginx does not honor per-directory config files. Drop a
        // copy-pasteable snippet alongside the other deny files so
        // the operator can splice it into their site vhost. The
        // README header explains why this file exists.
        $nginx_snippet = $dir . '/nginx-deny.conf.example';
        if (!file_exists($nginx_snippet)) {
            $rel = '/wp-content/' . basename($dir) . '/';
            @file_put_contents(
                $nginx_snippet,
                "# Matrix MLM Pro — backup directory protection (Nginx)\n" .
                "#\n" .
                "# Nginx does not honor per-directory .htaccess or web.config.\n" .
                "# Splice the following block into your site's server { } stanza\n" .
                "# (alongside the existing wp-content rules) and reload nginx:\n" .
                "#\n" .
                "#     location {$rel} {\n" .
                "#         deny all;\n" .
                "#         return 404;\n" .
                "#     }\n" .
                "#\n" .
                "# Or move backups outside the webroot entirely by defining\n" .
                "# MATRIX_MLM_BACKUP_DIR in wp-config.php — the recommended\n" .
                "# production setup. Example:\n" .
                "#\n" .
                "#     define('MATRIX_MLM_BACKUP_DIR', '/var/lib/matrix-mlm-backups');\n"
            );
        }

        // Belt-and-braces against directory listing.
        $index = $dir . '/index.html';
        if (!file_exists($index)) {
            @file_put_contents($index, '');
        }
    }

    /**
     * One-shot migration of backups from the legacy
     * wp-content/uploads/matrix-mlm-backups/ location into the new
     * dir. Idempotent: skipped when the legacy dir does not exist or
     * is empty.
     *
     * Files are MOVED, not copied — leaving two on-disk copies of a
     * sensitive dump would defeat the point. If a move fails for any
     * reason (cross-filesystem, permissions, etc.) the source is
     * left in place so the data is never lost; the operator can
     * clean up manually after confirming the new location works.
     */
    private static function migrate_legacy_backups($new_dir) {
        $uploads = wp_upload_dir();
        $legacy_dir = trailingslashit($uploads['basedir']) . 'matrix-mlm-backups';
        if (!is_dir($legacy_dir)) {
            return;
        }
        if (rtrim($legacy_dir, '/\\') === rtrim($new_dir, '/\\')) {
            return; // operator pointed MATRIX_MLM_BACKUP_DIR at the legacy dir
        }

        $files = @glob($legacy_dir . '/matrix-backup-*.{sql,sql.gz}', GLOB_BRACE);
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
                '[Matrix MLM] Migrated %d backup(s) from %s to %s',
                $moved, $legacy_dir, $new_dir
            ));
        }
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
        // H9: filename gets 8 hex chars of entropy from random_bytes
        // so an attacker who can guess the timestamp window cannot
        // also guess the filename (a per-second-precision timestamp
        // is otherwise easy to enumerate). Belt-and-braces against
        // any failure of the deny-rule layer above. Backwards
        // compatible: list_backups() still globs matrix-backup-* so
        // legacy timestamp-only filenames are still listed and
        // restorable.
        $rand_suffix = bin2hex(random_bytes(4));
        $filename  = "matrix-backup-{$stamp}-{$trigger_s}-{$rand_suffix}.{$ext}";
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
     * Statement-shape allow-list for restore_backup().
     *
     * Every statement read from a backup file is matched against
     * exactly one of these patterns BEFORE any execution begins.
     * If any statement fails to classify, the entire restore is
     * aborted with no rows touched. Threat model:
     *
     *   - The audit (H16) flagged that `$wpdb->query()` was called
     *     on every statement in the file with no scoping or
     *     statement-type check. A compromised admin uploading a
     *     malicious .sql file could include `DROP TABLE wp_users`
     *     or `INSERT INTO wp_options ('siteurl', 'attacker.example')`
     *     and pivot to full WordPress takeover.
     *
     *   - This allow-list is the choke point. It enforces:
     *
     *       1. Statement TYPE is one of the four shapes that
     *          create_backup() emits: SET FOREIGN_KEY_CHECKS, SET
     *          SQL_MODE, DROP TABLE [IF EXISTS], CREATE TABLE [IF
     *          NOT EXISTS], INSERT INTO. UPDATE, DELETE, ALTER,
     *          GRANT, CREATE USER, etc. are all rejected.
     *
     *       2. Any DDL/DML target table is identified by its
     *          leading backticked identifier and verified to
     *          start with the {prefix}matrix_ scope. Core WP
     *          tables (wp_users, wp_options, wp_usermeta) and any
     *          other plugin's tables cannot be touched.
     *
     * Future change to create_backup() must add a corresponding
     * pattern here, otherwise legitimate dumps will start failing
     * to restore — that's the point: extending the allow-list is
     * a deliberate decision, not an oversight.
     *
     * @return array<string,string> Map of label => regex.
     */
    private static function get_restore_statement_patterns() {
        global $wpdb;
        $prefix = preg_quote($wpdb->prefix, '/');
        // matrix_* table identifiers in this plugin are alphanumeric
        // plus underscore; this character class matches that exactly.
        $tbl = "{$prefix}matrix_[A-Za-z0-9_]+";
        return [
            // SET FOREIGN_KEY_CHECKS = 0 | 1   (with or without surrounding whitespace)
            'set_fk_checks' => '/^SET\s+FOREIGN_KEY_CHECKS\s*=\s*[01]\s*$/i',
            // SET SQL_MODE='...'    only single-quoted, alpha + underscore + comma chars
            'set_sql_mode'  => "/^SET\s+SQL_MODE\s*=\s*'[A-Z0-9_,\\s]*'\s*$/i",
            // DROP TABLE [IF EXISTS] `{prefix}matrix_<name>`
            'drop_table'    => "/^DROP\s+TABLE(?:\s+IF\s+EXISTS)?\s+`{$tbl}`\s*$/i",
            // CREATE TABLE [IF NOT EXISTS] `{prefix}matrix_<name>` ( ... )
            'create_table'  => "/^CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`{$tbl}`\s*\\(/i",
            // INSERT INTO `{prefix}matrix_<name>` ...
            'insert_into'   => "/^INSERT\s+INTO\s+`{$tbl}`/i",
        ];
    }

    /**
     * Classify a single restore statement against the allow-list.
     * Returns the matching label, or false if no pattern accepts it.
     */
    private static function classify_restore_statement($stmt) {
        foreach (self::get_restore_statement_patterns() as $label => $pattern) {
            if (preg_match($pattern, $stmt)) {
                return $label;
            }
        }
        return false;
    }

    /**
     * Restore a backup file by re-running its statements against the
     * live database. Designed to round-trip our own dumps; uploaded
     * dumps from other tools may work if they use the same statement
     * separator (a `;` followed by a newline) AND every statement
     * passes the allow-list (audit H16). Anything else is refused.
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

        // Pre-validate every statement against the allow-list before
        // running any of them. This is a two-pass restore so that an
        // attempt that contains a single rogue statement is rejected
        // up-front with the schema untouched, rather than partially
        // applied and then rolled back (DDL auto-commits on MySQL,
        // so the rollback safety net only protects the INSERT phase
        // anyway). (audit H16)
        $validated = [];
        foreach ($statements as $idx => $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') {
                continue;
            }

            $kind = self::classify_restore_statement($stmt);
            if ($kind === false) {
                // Surface a short excerpt so the operator can see
                // why the file was rejected, but cap it so a multi-
                // megabyte INSERT doesn't end up in a flash notice.
                $excerpt = mb_substr($stmt, 0, 200);
                if (mb_strlen($stmt) > 200) {
                    $excerpt .= '...';
                }
                return new WP_Error(
                    'matrix_restore_rejected',
                    sprintf(
                        /* translators: 1: 1-based statement index, 2: short SQL excerpt */
                        __('Restore aborted: statement #%1$d is not on the allow-list. Backups must contain only Matrix MLM table operations (DROP/CREATE TABLE and INSERT INTO scoped to {prefix}matrix_*). Excerpt: %2$s', 'matrix-mlm'),
                        $idx + 1,
                        $excerpt
                    ),
                    ['rejected_index' => $idx + 1, 'excerpt' => $excerpt]
                );
            }

            $validated[] = $stmt;
        }

        if (empty($validated)) {
            return new WP_Error('matrix_restore_empty', __('Backup file contained no recognised SQL statements.', 'matrix-mlm'));
        }

        $errors   = [];
        $executed = 0;

        // Wrap the whole restore in a transaction so a failure halfway
        // doesn't leave the schema in a half-restored state on InnoDB
        // tables. DDL still auto-commits on MySQL, so this only fully
        // protects the INSERT phase, but it's a worthwhile safety net.
        $wpdb->query('START TRANSACTION');

        foreach ($validated as $stmt) {
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
                ['errors' => $errors, 'executed' => $executed]
            );
        }

        $wpdb->query('COMMIT');

        return ['executed' => $executed];
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
            //
            // Tightened to refuse plain '.gz'. The previous regex —
            // /\.(sql|sql\.gz|gz)$/i — accepted any '.gz' file
            // because the alternation pattern listed 'gz' as its
            // own branch alongside 'sql.gz'. A .gz file that wasn't
            // a SQL dump (a tar.gz, a log.gz, an arbitrary .gz blob)
            // would land on disk inside the private backup directory
            // and the downstream restore parser would fail opaquely.
            // Admin-only path with capability + nonce gates so the
            // blast radius was already minimal — but accepting only
            // '.sql' and '.sql.gz' is the strictly tighter input
            // contract that matches what create_backup() actually
            // produces, so this is purely a refusal-of-malformed-input
            // hardening rather than a security fix.
            if (!preg_match('/\.sql(\.gz)?$/i', $original)) {
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
            $first    = $errors ? ' [' . esc_html($errors[0]) . ']' : '';
            self::redirect_with_notice(
                'error',
                $result->get_error_message() . sprintf(' (%d statements executed before failure)%s', $executed, $first)
            );
        }

        self::redirect_with_notice(
            'success',
            sprintf(__('Restore complete. %d statements executed.', 'matrix-mlm'), $result['executed'])
        );
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
