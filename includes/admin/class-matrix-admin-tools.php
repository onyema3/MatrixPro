<?php
/**
 * Admin Tools — operator-triggered maintenance utilities.
 *
 * Currently hosts a single tool: the avatar orphan-cleanup
 * scan/delete surface. Designed as a generic landing for similar
 * one-off maintenance operations we may add later (e.g. orphan
 * loan-doc cleanup, dangling Fintava virtual rows) so we don't
 * accumulate one submenu per tool.
 *
 * Why a dedicated tools page rather than an inline button on
 * Settings:
 *
 *   - Settings is the read/write surface for runtime configuration;
 *     it should never accidentally trigger a destructive disk
 *     operation. A separate page makes the intent of every link the
 *     operator clicks unambiguous.
 *
 *   - The capability is manage_matrix_settings — the same admin tier
 *     used by Backup & Restore and the import tooling. A reviewer
 *     who can triage tickets/deposits should NOT also be able to
 *     unlink files from disk; this class enforces that gate before
 *     rendering the page AND inside the form-handler before doing
 *     any work.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Admin_Tools {

    /** WP nonce action used by the avatar-orphan delete form. */
    const NONCE_AVATAR_CLEANUP = 'matrix_mlm_avatar_orphan_cleanup';

    /**
     * Hard cap on the number of orphan paths embedded in the page
     * preview list. Above this threshold we still show the totals
     * (count + bytes) and still delete every orphan when the
     * operator confirms — we just truncate the on-screen sample so
     * a runaway scan doesn't render a multi-megabyte HTML page.
     */
    const PREVIEW_LIMIT = 100;

    /**
     * Hard cap on directory traversal during a single scan. Defends
     * against a pathological filesystem (symlink loop, accidentally
     * mounted recursive bind) that could otherwise hang the request.
     * Realistic worst-case avatar trees are well under 100k files;
     * this is simply a fence.
     */
    const SCAN_FILE_LIMIT = 100000;

    /**
     * Wire the form-handler. Called from matrix-mlm.php inside the
     * is_admin() branch, so the admin-post hook is registered for
     * every admin request and unregistered for front-end pageloads.
     * The render() method itself is invoked through the submenu
     * registered in Matrix_MLM_Admin::add_admin_menus.
     */
    public static function init() {
        add_action('admin_post_matrix_mlm_delete_avatar_orphans',
            [__CLASS__, 'handle_delete_avatar_orphans']);
    }

    /**
     * Render the Tools page.
     *
     * Always scans live on each render — the data is small enough
     * that caching would just introduce a second source of truth.
     * Defence-in-depth capability check duplicates the menu-router
     * gate so this method stays safe if it ever gets wired into a
     * different surface.
     */
    public function render() {
        if (!current_user_can('manage_matrix_settings')) {
            wp_die(__('Sorry, you are not allowed to access this page.', 'matrix-mlm'), 403);
        }

        $scan = self::scan_avatar_subtree();
        ?>
        <div class="wrap matrix-admin-wrap">
            <h1><?php _e('Matrix MLM Tools', 'matrix-mlm'); ?></h1>

            <?php
            // Surface a one-shot success/error notice when the page
            // is reached via a redirect from handle_delete_avatar_orphans.
            // Querystring carries:
            //   matrix_avatar_cleanup_done=<int deleted>
            //   matrix_avatar_cleanup_failed=<int failures>  (optional)
            // and an integer-only contract so anything else is ignored.
            if (isset($_GET['matrix_avatar_cleanup_done'])) {
                $deleted_count = (int) $_GET['matrix_avatar_cleanup_done'];
                $failed_count  = isset($_GET['matrix_avatar_cleanup_failed'])
                    ? (int) $_GET['matrix_avatar_cleanup_failed']
                    : 0;
                if ($failed_count > 0) {
                    echo '<div class="notice notice-warning is-dismissible"><p>'
                        . esc_html(sprintf(
                            /* translators: 1: deleted count, 2: failed count */
                            __('Deleted %1$d orphan avatar file(s). %2$d file(s) could not be deleted (see error log).', 'matrix-mlm'),
                            $deleted_count,
                            $failed_count
                        ))
                        . '</p></div>';
                } else {
                    echo '<div class="notice notice-success is-dismissible"><p>'
                        . esc_html(sprintf(
                            /* translators: %d: deleted count */
                            _n(
                                'Deleted %d orphan avatar file.',
                                'Deleted %d orphan avatar files.',
                                $deleted_count,
                                'matrix-mlm'
                            ),
                            $deleted_count
                        ))
                        . '</p></div>';
                }
            }
            ?>

            <div class="matrix-admin-card" style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin-top:20px;">
                <h2><?php _e('Avatar Orphan Cleanup', 'matrix-mlm'); ?></h2>

                <p style="max-width:760px;">
                    <?php
                    // Word the explanation precisely so an operator
                    // running this for the first time understands what
                    // gets deleted and what doesn't. The historical-
                    // orphan caveat is the single most important thing
                    // on the page — without it, an operator might
                    // expect this tool to clean up the years of
                    // pre-fix orphans in wp-content/uploads/YYYY/MM/
                    // and be confused when it doesn't.
                    echo wp_kses_post(__(
                        'Scans <code>wp-content/uploads/matrix-mlm-avatars/</code> for files that no member currently references via their profile picture, and offers to delete them. Only files in this dedicated subdirectory are touched — historical avatars in <code>wp-content/uploads/YYYY/MM/</code> from before the dedicated subdir was introduced are left alone, since they sit alongside post images and other media-library files where automated cleanup would be unsafe.',
                        'matrix-mlm'
                    ));
                    ?>
                </p>

                <?php if (!$scan['root_exists']): ?>
                    <p>
                        <em><?php _e('No avatar uploads have happened yet on this install — the directory does not exist. Nothing to do.', 'matrix-mlm'); ?></em>
                    </p>
                <?php else: ?>

                    <table class="widefat striped" style="max-width:600px;margin-top:16px;">
                        <tbody>
                            <tr>
                                <th style="width:60%;"><?php _e('Files in avatar directory', 'matrix-mlm'); ?></th>
                                <td>
                                    <strong><?php echo esc_html(number_format_i18n($scan['total_files'])); ?></strong>
                                    (<?php echo esc_html(size_format($scan['total_bytes'], 2)); ?>)
                                </td>
                            </tr>
                            <tr>
                                <th><?php _e('Avatars currently referenced', 'matrix-mlm'); ?></th>
                                <td>
                                    <strong><?php echo esc_html(number_format_i18n($scan['referenced_count'])); ?></strong>
                                </td>
                            </tr>
                            <tr>
                                <th><?php _e('Orphan files (eligible for deletion)', 'matrix-mlm'); ?></th>
                                <td>
                                    <strong style="color:<?php echo $scan['orphan_count'] > 0 ? '#d63638' : '#00a32a'; ?>;">
                                        <?php echo esc_html(number_format_i18n($scan['orphan_count'])); ?>
                                    </strong>
                                    (<?php echo esc_html(size_format($scan['orphan_bytes'], 2)); ?>)
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <?php if ($scan['orphan_count'] > 0): ?>
                        <h3 style="margin-top:24px;"><?php _e('Files to be deleted', 'matrix-mlm'); ?></h3>

                        <p>
                            <?php
                            if ($scan['orphan_count'] > self::PREVIEW_LIMIT) {
                                echo esc_html(sprintf(
                                    /* translators: 1: shown, 2: total */
                                    __('Showing first %1$d of %2$d orphan files. All %2$d will be deleted when you confirm below.', 'matrix-mlm'),
                                    self::PREVIEW_LIMIT,
                                    $scan['orphan_count']
                                ));
                            } else {
                                _e('All orphan files listed below will be deleted when you confirm.', 'matrix-mlm');
                            }
                            ?>
                        </p>

                        <div style="background:#f6f7f7;border:1px solid #ccd0d4;border-radius:3px;padding:12px;max-height:320px;overflow:auto;font-family:Menlo,Consolas,monospace;font-size:12px;line-height:1.6;">
                            <?php
                            $shown = array_slice($scan['orphan_paths'], 0, self::PREVIEW_LIMIT);
                            foreach ($shown as $entry) {
                                // entry shape: ['path' => abs path, 'size' => bytes]
                                // Render the relative path (under uploads/)
                                // rather than the absolute path — operators
                                // don't need the full /var/www/... prefix
                                // and it leaks server layout into a screen
                                // a support member might screenshot.
                                $rel = self::path_relative_to_uploads_dir($entry['path']);
                                echo esc_html($rel) . ' '
                                    . '<span style="color:#646970;">('
                                    . esc_html(size_format($entry['size'], 2))
                                    . ')</span><br>';
                            }
                            ?>
                        </div>

                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:20px;">
                            <input type="hidden" name="action" value="matrix_mlm_delete_avatar_orphans">
                            <?php wp_nonce_field(self::NONCE_AVATAR_CLEANUP); ?>
                            <p>
                                <button type="submit"
                                        class="button button-primary"
                                        onclick="return confirm('<?php
                                            echo esc_js(sprintf(
                                                /* translators: %d: orphan count */
                                                __('Delete %d orphan avatar file(s)? This cannot be undone.', 'matrix-mlm'),
                                                $scan['orphan_count']
                                            ));
                                        ?>');">
                                    <?php
                                    echo esc_html(sprintf(
                                        /* translators: %d: orphan count */
                                        __('Delete %d orphan file(s)', 'matrix-mlm'),
                                        $scan['orphan_count']
                                    ));
                                    ?>
                                </button>
                            </p>
                        </form>
                    <?php else: ?>
                        <p style="margin-top:20px;color:#00a32a;">
                            <strong><?php _e('No orphan files found. Nothing to clean up.', 'matrix-mlm'); ?></strong>
                        </p>
                    <?php endif; ?>

                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * admin-post handler for the orphan-cleanup confirm button.
     *
     * Re-runs the scan from scratch rather than trusting a list of
     * paths posted from the page — the operator might have spent
     * minutes reading the preview, during which time other users
     * could have changed their avatars (creating new keepers, OR
     * promoting what was a keeper into an orphan). Re-scanning at
     * delete time means we always act on the current state.
     *
     * Capability + nonce gates fire BEFORE any disk read so an
     * unauthenticated forged POST is bounded at "request rejected"
     * with no side effect.
     */
    public static function handle_delete_avatar_orphans() {
        if (!current_user_can('manage_matrix_settings')) {
            wp_die(__('Sorry, you are not allowed to perform this action.', 'matrix-mlm'), 403);
        }
        check_admin_referer(self::NONCE_AVATAR_CLEANUP);

        $scan = self::scan_avatar_subtree();

        $deleted = 0;
        $failed  = 0;

        if ($scan['root_exists']) {
            $real_root = realpath($scan['root_path']);
            foreach ($scan['orphan_paths'] as $entry) {
                $real_path = realpath($entry['path']);
                // Re-validate containment at delete time. realpath
                // resolves any symlinks; we only delete files that,
                // after symlink resolution, still sit under the
                // avatar root. Defends against a symlink injected
                // between scan and delete (vanishingly unlikely on
                // a plugin-owned directory, but the cost of the
                // check is one syscall per file).
                if ($real_root === false || $real_path === false) {
                    $failed++;
                    continue;
                }
                if (strpos($real_path, rtrim($real_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) {
                    $failed++;
                    continue;
                }

                if (function_exists('wp_delete_file')) {
                    wp_delete_file($real_path);
                } else {
                    @unlink($real_path);
                }

                // wp_delete_file returns void — verify by re-stat.
                // file_exists() suppresses warnings on missing dirs.
                if (file_exists($real_path)) {
                    $failed++;
                    error_log(sprintf(
                        '[Matrix MLM] avatar orphan cleanup: failed to delete %s',
                        $real_path
                    ));
                } else {
                    $deleted++;
                }
            }

            // Best-effort directory rmdir for any per-user folders
            // that emptied out as a result. Suppresses warnings —
            // a non-empty directory just gets left in place.
            self::prune_empty_user_dirs($scan['root_path']);
        }

        $redirect = add_query_arg(
            [
                'page'                            => 'matrix-mlm-tools',
                'matrix_avatar_cleanup_done'      => $deleted,
                'matrix_avatar_cleanup_failed'    => $failed,
            ],
            admin_url('admin.php')
        );
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Walk the avatar subtree and classify every file as either
     * referenced (a member's matrix_avatar_url currently points at
     * it) or orphan.
     *
     * Returns:
     *   [
     *     'root_exists'      => bool
     *     'root_path'        => string  (absolute, even if missing)
     *     'total_files'      => int
     *     'total_bytes'      => int
     *     'referenced_count' => int
     *     'orphan_count'     => int
     *     'orphan_bytes'     => int
     *     'orphan_paths'     => array of ['path' => abs path, 'size' => int bytes]
     *   ]
     */
    private static function scan_avatar_subtree() {
        $upload_dir = wp_upload_dir();
        $basedir    = isset($upload_dir['basedir']) ? (string) $upload_dir['basedir'] : '';
        $subdir     = Matrix_MLM_Core::AVATAR_UPLOAD_SUBDIR;
        $root       = trailingslashit($basedir) . $subdir;

        $result = [
            'root_exists'      => false,
            'root_path'        => $root,
            'total_files'      => 0,
            'total_bytes'      => 0,
            'referenced_count' => 0,
            'orphan_count'     => 0,
            'orphan_bytes'     => 0,
            'orphan_paths'     => [],
        ];

        if ($basedir === '' || !is_dir($root)) {
            return $result;
        }
        $result['root_exists'] = true;

        $keep_set = self::build_keep_set($basedir, $subdir);
        $result['referenced_count'] = count($keep_set);

        // RecursiveIteratorIterator gives us every file under the
        // root in a single pass without us having to write the
        // walk by hand. SKIP_DOTS keeps . and .. out of results.
        try {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $root,
                    FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
                ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
        } catch (Throwable $e) {
            // RecursiveDirectoryIterator throws if the directory
            // is unreadable mid-traversal. Log and return empty
            // so the page still renders with the totals we have.
            error_log('[Matrix MLM] avatar scan failed: ' . $e->getMessage());
            return $result;
        }

        $count = 0;
        foreach ($iter as $fileinfo) {
            if ($count++ >= self::SCAN_FILE_LIMIT) {
                error_log(sprintf(
                    '[Matrix MLM] avatar scan capped at %d files; partial result',
                    self::SCAN_FILE_LIMIT
                ));
                break;
            }
            if (!$fileinfo->isFile()) {
                continue;
            }

            $abs  = $fileinfo->getPathname();
            $size = (int) $fileinfo->getSize();

            $result['total_files']++;
            $result['total_bytes'] += $size;

            // Containment guard: skip anything that resolves outside
            // $root after realpath. This shouldn't happen given the
            // root itself is what we walked, but FOLLOW_SYMLINKS
            // means a symlink inside the tree could point elsewhere
            // — and we don't want a count or a delete to follow it.
            $real_abs  = realpath($abs);
            $real_root = realpath($root);
            if ($real_abs === false || $real_root === false) {
                continue;
            }
            if (strpos($real_abs, rtrim($real_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) {
                continue;
            }

            // Membership test: keep_set is keyed by realpath, so a
            // symlinked keeper still matches its referenced file.
            if (isset($keep_set[$real_abs])) {
                continue;
            }

            $result['orphan_count']++;
            $result['orphan_bytes'] += $size;
            $result['orphan_paths'][] = [
                'path' => $real_abs,
                'size' => $size,
            ];
        }

        // Stable display order — sort by path so two consecutive
        // page loads show the same list.
        usort($result['orphan_paths'], function ($a, $b) {
            return strcmp($a['path'], $b['path']);
        });

        return $result;
    }

    /**
     * Build the keep-set: realpaths of every file currently
     * referenced by any user's matrix_avatar_url meta and sitting
     * under the avatar subtree.
     *
     * Returns a map keyed by absolute realpath -> true so callers
     * can do an O(1) isset() probe per scanned file.
     *
     * URLs that point outside the avatar subtree (gravatar URLs,
     * legacy matrix-avatar files in YYYY/MM/, anything an operator
     * pasted manually) are silently ignored — they're irrelevant
     * to a scan whose scope is the dedicated subdir.
     */
    private static function build_keep_set($basedir, $subdir) {
        global $wpdb;

        $keep = [];

        // DISTINCT — a single user only stores one URL but we read
        // every row anyway in case future code stores history.
        $rows = $wpdb->get_col(
            "SELECT DISTINCT meta_value
               FROM {$wpdb->usermeta}
              WHERE meta_key = 'matrix_avatar_url'
                AND meta_value <> ''"
        );

        if (empty($rows)) {
            return $keep;
        }

        $upload_dir = wp_upload_dir();
        $baseurl    = isset($upload_dir['baseurl']) ? (string) $upload_dir['baseurl'] : '';
        if ($baseurl === '') {
            return $keep;
        }

        // Scheme normalisation matches delete_avatar_file_safely so
        // a stored http:// URL on an https:// install still resolves
        // to its on-disk file.
        $baseurl_norm = set_url_scheme($baseurl, 'https');
        $sub_prefix   = '/' . trim((string) $subdir, '/') . '/';

        foreach ($rows as $url) {
            if (!is_string($url) || $url === '') {
                continue;
            }
            $url_norm = set_url_scheme($url, 'https');

            if (strpos($url_norm, $baseurl_norm) !== 0) {
                continue;
            }
            $relative = substr($url_norm, strlen($baseurl_norm));

            // Only files inside our dedicated subdir are keepers
            // for the orphan scan. Members whose meta still points
            // at a legacy YYYY/MM/ avatar are simply excluded —
            // their file lives outside the scan scope so it can't
            // be classified as an orphan from this tool's POV.
            if (strpos($relative, $sub_prefix) !== 0) {
                continue;
            }

            if (strpos($relative, '..') !== false) {
                continue;
            }

            $abs  = trailingslashit($basedir) . ltrim($relative, '/');
            $real = realpath($abs);
            if ($real === false) {
                continue;
            }
            $keep[$real] = true;
        }

        return $keep;
    }

    /**
     * Best-effort cleanup of empty per-user directories under the
     * avatar root. Called after a successful delete pass so a user
     * who burned through ten avatars and is now down to zero
     * doesn't leave an empty wp-content/uploads/matrix-mlm-avatars/
     * 123/ directory lying around.
     *
     * rmdir() refuses non-empty directories, so this is naturally
     * safe — at worst it's a no-op.
     */
    private static function prune_empty_user_dirs($root) {
        if (!is_dir($root)) {
            return;
        }
        $entries = @scandir($root);
        if (!is_array($entries)) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $sub = trailingslashit($root) . $entry;
            if (!is_dir($sub)) {
                continue;
            }
            $children = @scandir($sub);
            if (is_array($children) && count($children) === 2) {
                // Only . and .. — directory is empty.
                @rmdir($sub);
            }
        }
    }

    /**
     * Render an absolute path as its uploads-relative form for
     * display. Falls back to the absolute path on any normalisation
     * surprise.
     */
    private static function path_relative_to_uploads_dir($abs_path) {
        $upload_dir = wp_upload_dir();
        $basedir    = isset($upload_dir['basedir']) ? (string) $upload_dir['basedir'] : '';
        if ($basedir === '') {
            return $abs_path;
        }
        $prefix = trailingslashit($basedir);
        if (strpos($abs_path, $prefix) === 0) {
            return 'uploads/' . substr($abs_path, strlen($prefix));
        }
        return $abs_path;
    }
}
