<?php
/**
 * Genealogy share-token lifecycle, public route, and AJAX surface.
 *
 * One class owns the entire "share my tree to a prospect" feature:
 *
 *   - Token lifecycle (mint, list, revoke, look up).
 *   - Rewrite rule + query var registration so the pretty URL
 *     /genealogy/share/{token}/ resolves on permalink-enabled
 *     installs, with a graceful ?matrix_share_token=X fallback for
 *     plain-permalink hosts.
 *   - template_redirect intercept that renders the public
 *     read-only tree page when a valid token resolves, before WP
 *     dispatches to the regular template (which would 404 on the
 *     non-existent /genealogy/share/{token}/ page).
 *   - AJAX endpoints (matrix_create_share_token,
 *     matrix_revoke_share_token) the dashboard panel posts to.
 *
 * The class is intentionally NOT scoped to is_admin() — token
 * creation is logged-in-user only, but the public route handler
 * has to fire for anonymous prospects, so the whole class is
 * loaded on every request from matrix-mlm.php.
 *
 * Schema lives in matrix_share_tokens (see
 * Matrix_MLM_Database::create_tables() for the column-level
 * design notes). Lookups gate on `revoked_at IS NULL AND
 * (expires_at IS NULL OR expires_at > NOW())` so both knobs
 * (manual revocation + scheduled expiry) work independently.
 *
 * Rendering reuses Matrix_MLM_Plan_Engine::get_matrix_tree() for
 * tree data so the public view never drifts from the dashboard's
 * structural shape, but keeps its own simplified renderer (no
 * pivot trail, no heat map, no lazy-load) because those features
 * presume an authenticated viewer.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Share {

    /**
     * Cap on initial render depth for the public share page.
     *
     * Mirrors Matrix_MLM_User_Genealogy::INITIAL_DEPTH (4) so a
     * prospect viewing a shared tree sees the same shape the
     * member saw when they minted the link. Lazy-load is
     * deliberately NOT wired up on the public surface — anonymous
     * viewers shouldn't be hammering the AJAX subtree endpoint,
     * and "what fits in one screen" is the right read-only
     * experience for a recruiting demo anyway.
     */
    const PUBLIC_RENDER_DEPTH = 4;

    /**
     * Token byte length. 32 bytes -> 64 hex chars, drawn from
     * random_bytes() / openssl_random_pseudo_bytes() — a much
     * larger key space than wp_generate_password() and immune to
     * the ambiguous-character substitutions that helper does.
     */
    const TOKEN_BYTES = 32;

    /**
     * Wire all routing + AJAX hooks. Called once from
     * matrix-mlm.php at plugin bootstrap. The _GET-keyed query
     * var fallback works without a flush because WP parses
     * registered query vars from the request directly; the
     * pretty-URL form requires the rewrite rule to have been
     * persisted into the rewrite_rules option, hence the
     * one-shot self-heal flush below.
     */
    public static function init() {
        add_action('init',              [__CLASS__, 'register_rewrites']);
        add_filter('query_vars',        [__CLASS__, 'register_query_vars']);
        add_action('template_redirect', [__CLASS__, 'maybe_render_public_share']);

        // Logged-in member endpoints. No nopriv equivalents — minting
        // and revoking a share token both require a logged-in account.
        add_action('wp_ajax_matrix_create_share_token', [__CLASS__, 'ajax_create_token']);
        add_action('wp_ajax_matrix_revoke_share_token', [__CLASS__, 'ajax_revoke_token']);
    }

    /**
     * Register the rewrite rule for /genealogy/share/{token}/ and
     * self-heal the rewrite_rules option on first hit so the rule
     * is actually resolvable without a manual permalink flush.
     *
     * Pattern is anchored at /genealogy/ rather than
     * /matrix-dashboard/genealogy/ to keep the public link short
     * and rememberable when shared verbally — "go to
     * site.com/genealogy/share/abc123" is dramatically more
     * shareable than embedding the dashboard prefix.
     *
     * The self-heal flag (matrix_mlm_share_rewrite_v1_flushed)
     * mirrors the same pattern Matrix_MLM_Core uses for the
     * /matrix-dashboard/{tab}/ rule so an in-place plugin upgrade
     * doesn't leave the route 404'ing until the operator
     * remembers to visit Settings → Permalinks.
     */
    public static function register_rewrites() {
        add_rewrite_rule(
            '^genealogy/share/([^/]+)/?$',
            'index.php?matrix_share_token=$matches[1]',
            'top'
        );

        if ((string) get_option('permalink_structure', '') !== '' &&
            (int) get_option('matrix_mlm_share_rewrite_v1_flushed', 0) !== 1) {
            $persisted = get_option('rewrite_rules');
            if (!is_array($persisted) || !isset($persisted['^genealogy/share/([^/]+)/?$'])) {
                flush_rewrite_rules(false);
            }
            update_option('matrix_mlm_share_rewrite_v1_flushed', 1, false);
        }
    }

    /**
     * Add matrix_share_token to the recognised query-var list so
     * WP exposes it via get_query_var() on the
     * template_redirect hook regardless of whether the request
     * came in via the pretty URL or the ?matrix_share_token=X
     * fallback.
     */
    public static function register_query_vars($vars) {
        $vars[] = 'matrix_share_token';
        return $vars;
    }

    // ---------------------------------------------------------------
    // Token lifecycle
    // ---------------------------------------------------------------

    /**
     * Generate a cryptographically random URL-safe token string.
     *
     * Prefers random_bytes() (PHP 7+), falls back to
     * openssl_random_pseudo_bytes() if for some reason
     * random_bytes raises (defensive — PHP_VERSION_ID is already
     * gated by Requires PHP 7.4 in the plugin header). Hex output
     * keeps the URL trivially copy-pasteable; base64 would be
     * shorter but introduces +/= which break naïve URL handling
     * upstream of this plugin's code.
     */
    public static function generate_token_string() {
        try {
            return bin2hex(random_bytes(self::TOKEN_BYTES));
        } catch (\Throwable $e) {
            // Shouldn't fire, but a deterministic fallback is
            // better than letting the AJAX endpoint 500.
            return bin2hex(openssl_random_pseudo_bytes(self::TOKEN_BYTES));
        }
    }

    /**
     * Mint a new share token for the given user.
     *
     * @param int      $user_id        Owner of the share link.
     * @param int|null $plan_id        Specific plan id, or null
     *                                 to let the public renderer
     *                                 pick the user's first
     *                                 active plan at view time.
     * @param int|null $pivot_user_id  Pin the share to a downline
     *                                 member's branch instead of
     *                                 the owner's full tree.
     *                                 Caller must validate that
     *                                 the pivot user is in the
     *                                 owner's downline; this
     *                                 method does not re-check
     *                                 because the dashboard form
     *                                 only offers users the owner
     *                                 already has access to.
     * @param string|null $expires_at  MySQL datetime, or null for
     *                                 no expiry.
     * @param string   $label          Human-readable label.
     *
     * @return array{
     *     id:    int,
     *     token: string,
     *     url:   string
     * }|WP_Error
     */
    public static function create_token($user_id, $plan_id, $pivot_user_id, $expires_at, $label) {
        global $wpdb;

        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return new WP_Error('invalid_user', __('Invalid user.', 'matrix-mlm'));
        }

        $token = self::generate_token_string();

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'matrix_share_tokens',
            [
                'user_id'       => $user_id,
                'plan_id'       => $plan_id !== null ? (int) $plan_id : null,
                'pivot_user_id' => $pivot_user_id !== null ? (int) $pivot_user_id : null,
                'token'         => $token,
                'label'         => $label !== '' ? $label : null,
                'expires_at'    => $expires_at,
                'created_at'    => current_time('mysql'),
            ],
            // Format strings — wpdb wants %d/%s in lockstep with the
            // values array, with %s used for nullable INT columns
            // because passing %d for a NULL value coerces to 0
            // (which would make a NULL plan_id show as plan #0).
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            return new WP_Error(
                'db_insert_failed',
                $wpdb->last_error ?: __('Could not create share token.', 'matrix-mlm')
            );
        }

        return [
            'id'    => (int) $wpdb->insert_id,
            'token' => $token,
            'url'   => self::build_share_url($token),
        ];
    }

    /**
     * Soft-revoke a token by stamping revoked_at. The row stays
     * around so the dashboard can show "revoked on X" history; a
     * subsequent public hit on the URL will hit the
     * `revoked_at IS NULL` clause in get_active_token() and bounce.
     *
     * Authorization: caller must be the token owner. We accept the
     * user id as a parameter (rather than read get_current_user_id()
     * inside) so callers from non-AJAX contexts can pass through
     * an explicit owner — matches the rest of the plugin's static
     * lifecycle helpers.
     *
     * @return bool|WP_Error true on success, WP_Error if the
     *                       token is missing or owned by someone
     *                       else.
     */
    public static function revoke_token($token_id, $user_id) {
        global $wpdb;
        $token_id = (int) $token_id;
        $user_id  = (int) $user_id;
        if ($token_id <= 0 || $user_id <= 0) {
            return new WP_Error('invalid_args', __('Invalid token id.', 'matrix-mlm'));
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, user_id, revoked_at
               FROM {$wpdb->prefix}matrix_share_tokens
              WHERE id = %d",
            $token_id
        ));
        if (!$row) {
            return new WP_Error('not_found', __('Share token not found.', 'matrix-mlm'));
        }
        if ((int) $row->user_id !== $user_id) {
            // Not the owner. Don't leak existence — return the same
            // not_found error rather than a "wrong owner" message
            // that could be probed for token id enumeration.
            return new WP_Error('not_found', __('Share token not found.', 'matrix-mlm'));
        }
        if (!empty($row->revoked_at)) {
            // Already revoked — idempotent return. The dashboard's
            // optimistic UI can re-call without producing an error
            // toast.
            return true;
        }

        $wpdb->update(
            $wpdb->prefix . 'matrix_share_tokens',
            ['revoked_at' => current_time('mysql')],
            ['id'         => $token_id],
            ['%s'],
            ['%d']
        );
        return true;
    }

    /**
     * Look up an active token by its string and return the full
     * row. Used by the public route handler. Returns null on miss
     * (revoked, expired, or no such token) — the caller is
     * responsible for surfacing "this share link is no longer
     * valid" messaging.
     *
     * Side effects: on a hit, increments view_count and stamps
     * last_viewed_at. The bump is fire-and-forget — if the UPDATE
     * fails for any reason we still return the row so the
     * prospect's render isn't blocked by an instrumentation
     * write.
     */
    public static function resolve_active_token($token_string) {
        global $wpdb;
        $token_string = (string) $token_string;
        if (strlen($token_string) === 0) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT *
               FROM {$wpdb->prefix}matrix_share_tokens
              WHERE token = %s
                AND revoked_at IS NULL
                AND (expires_at IS NULL OR expires_at > NOW())
              LIMIT 1",
            $token_string
        ));

        if (!$row) {
            return null;
        }

        // View counter + last-viewed stamp. Best-effort; we don't
        // surface failures because the prospect's render path
        // doesn't depend on the write succeeding.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}matrix_share_tokens
                SET view_count = view_count + 1,
                    last_viewed_at = %s
              WHERE id = %d",
            current_time('mysql'),
            (int) $row->id
        ));

        return $row;
    }

    /**
     * List every token the user has ever minted (active + revoked
     * + expired), newest first. Drives the dashboard panel.
     *
     * Return shape decorates each row with a derived `status`
     * field (active|revoked|expired) so the UI doesn't have to
     * re-implement the WHERE-clause logic inline.
     */
    public static function list_tokens_for_user($user_id) {
        global $wpdb;
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return [];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT *
               FROM {$wpdb->prefix}matrix_share_tokens
              WHERE user_id = %d
              ORDER BY created_at DESC",
            $user_id
        ));
        if (!$rows) {
            return [];
        }

        $now = current_time('timestamp');
        foreach ($rows as $row) {
            if (!empty($row->revoked_at)) {
                $row->status = 'revoked';
            } elseif (!empty($row->expires_at) && strtotime($row->expires_at) <= $now) {
                $row->status = 'expired';
            } else {
                $row->status = 'active';
            }
            $row->url = self::build_share_url($row->token);
        }
        return $rows;
    }

    /**
     * Compose the public share URL for a given token, picking the
     * permalink-aware form when permalinks are enabled and falling
     * back to the query-var form otherwise.
     */
    public static function build_share_url($token) {
        $token = (string) $token;
        if (get_option('permalink_structure')) {
            return home_url('/genealogy/share/' . rawurlencode($token) . '/');
        }
        return add_query_arg('matrix_share_token', rawurlencode($token), home_url('/'));
    }

    // ---------------------------------------------------------------
    // Public route — render the read-only tree page
    // ---------------------------------------------------------------

    /**
     * template_redirect handler. Fires before WP template
     * dispatch. If the request carries a matrix_share_token query
     * var (set either by the rewrite rule or by an explicit
     * ?matrix_share_token= fallback), we render the public share
     * page and exit. Otherwise we no-op and let WP take its
     * normal course.
     *
     * Renders a complete HTML document (with our own
     * <head>/<body>) rather than hooking into the active theme,
     * because the prospect-facing surface deliberately avoids
     * the site's nav/footer chrome — recruiting flows want a
     * focused page, and a misconfigured theme could otherwise
     * eat the tree visually.
     */
    public static function maybe_render_public_share() {
        $token_string = (string) get_query_var('matrix_share_token');
        if ($token_string === '') {
            // Also accept ?matrix_share_token= directly on Plain
            // permalink installs where the rewrite rule never
            // fires. get_query_var picks this up automatically
            // via register_query_vars(), so this branch is the
            // belt-and-suspenders for setups where some upstream
            // handler stripped the var.
            $token_string = isset($_GET['matrix_share_token'])
                ? sanitize_text_field((string) $_GET['matrix_share_token'])
                : '';
            if ($token_string === '') {
                return;
            }
        }

        $row = self::resolve_active_token($token_string);
        self::render_public_page($row);
        exit;
    }

    /**
     * Render the prospect-facing HTML. Two states:
     *
     *   1. No row — token is unknown, revoked, or expired. Show a
     *      friendly "this link is no longer valid" page.
     *   2. Hit — render the owner's tree using the same plan
     *      engine the dashboard uses, capped at PUBLIC_RENDER_DEPTH
     *      and stripped of any feature that requires an
     *      authenticated viewer (lazy-load AJAX, pivot trail,
     *      heat-map data attrs).
     */
    private static function render_public_page($row) {
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');

        $site_name   = get_option('matrix_mlm_site_title', get_bloginfo('name'));
        ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html(sprintf(__('%s — Genealogy', 'matrix-mlm'), $site_name)); ?></title>
<style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; background: #f8fafc; color: #111827; }
    .mma-share-bar { background: #4f46e5; color: #fff; padding: 12px 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; }
    .mma-share-bar h1 { margin: 0; font-size: 16px; font-weight: 600; }
    .mma-share-bar .badge { background: rgba(255,255,255,0.2); padding: 3px 10px; border-radius: 999px; font-size: 11px; }
    .mma-share-wrap { padding: 20px; max-width: 1280px; margin: 0 auto; }
    .mma-share-meta { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 18px; margin-bottom: 16px; }
    .mma-share-meta h2 { margin: 0 0 6px; font-size: 15px; }
    .mma-share-meta .stat { display: inline-block; margin-right: 24px; color: #4b5563; font-size: 13px; }
    .mma-share-meta .stat strong { color: #111827; }
    .mma-share-error { background: #fff; border: 1px solid #fecaca; border-radius: 8px; padding: 32px 24px; text-align: center; max-width: 480px; margin: 60px auto; }
    .mma-share-error h2 { margin: 0 0 8px; color: #b91c1c; font-size: 18px; }
    .mma-share-error p { margin: 0; color: #4b5563; font-size: 14px; line-height: 1.55; }

    /* Tree styles — locally namespaced (mma-share-tree-*) so the
       public page is fully self-contained and doesn't depend on
       the dashboard CSS being enqueued. */
    .mma-share-tree-wrapper { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px; overflow: auto; }
    .mma-share-tree { display: flex; justify-content: center; min-width: fit-content; }
    .mma-share-item { display: flex; flex-direction: column; align-items: center; position: relative; padding: 0 6px; }
    .mma-share-node { background: #fff; border: 2px solid #e2e8f0; border-radius: 8px; padding: 8px 12px; min-width: 150px; max-width: 220px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); display: flex; flex-direction: column; gap: 4px; }
    .mma-share-node-root { border-color: #4f46e5; background: linear-gradient(135deg, #eef2ff, #e0e7ff); }
    .mma-share-node-direct { border-color: #10b981; background: #ecfdf5; }
    .mma-share-node-spillover { border-color: #f59e0b; background: #fffbeb; }
    .mma-share-node-empty { border-style: dashed; border-color: #cbd5e1; background: #f9fafb; color: #94a3b8; font-size: 12px; min-width: 120px; min-height: 38px; display: flex; align-items: center; justify-content: center; font-style: italic; }
    .mma-share-name { font-weight: 600; font-size: 13px; word-break: break-word; }
    .mma-share-meta-row { font-size: 11px; color: #4b5563; }
    .mma-share-children { display: flex; flex-direction: row; justify-content: center; margin-top: 22px; position: relative; }
    .mma-share-children::before { content: ''; position: absolute; top: -12px; left: 50%; width: 1px; height: 12px; background: #cbd5e1; }
    .mma-share-children > .mma-share-item::before { content: ''; position: absolute; top: -10px; left: 50%; width: 1px; height: 10px; background: #cbd5e1; }
    .mma-share-children > .mma-share-item:not(:only-child)::after { content: ''; position: absolute; top: -10px; left: 0; right: 0; height: 1px; background: #cbd5e1; }
    .mma-share-children > .mma-share-item:first-child::after { left: 50%; }
    .mma-share-children > .mma-share-item:last-child::after { right: 50%; }
    .mma-share-truncated { margin-top: 12px; text-align: center; font-size: 12px; color: #6b7280; font-style: italic; }
    .mma-share-footer { margin-top: 20px; text-align: center; font-size: 11px; color: #9ca3af; }
    .mma-share-footer a { color: #4f46e5; text-decoration: none; }

    /* Print: hide the chrome, leave the tree. The print pattern
       matches the rest of the plugin (window.print()-driven PDF
       export from admin reports/import pages). */
    @media print {
        .mma-share-bar, .mma-share-footer, .no-print { display: none !important; }
        body { background: #fff; }
        .mma-share-wrap { padding: 0; }
        .mma-share-tree-wrapper { border: 0; padding: 0; }
    }
</style>
</head>
<body>
<?php if (!$row): ?>
    <div class="mma-share-error">
        <h2><?php esc_html_e('This share link is no longer valid', 'matrix-mlm'); ?></h2>
        <p>
            <?php
            esc_html_e(
                'The link you followed has been revoked, has expired, or never existed. Ask the person who shared it for a fresh link.',
                'matrix-mlm'
            );
            ?>
        </p>
    </div>
<?php else: ?>
    <?php self::render_share_chrome_and_tree($row); ?>
<?php endif; ?>
</body>
</html>
        <?php
    }

    /**
     * Render the actual tree + the wrapper chrome (top bar with
     * member name + "Shared read-only" badge, stat strip with
     * downline counts, simplified tree).
     *
     * Pulls authoritative data each request — we deliberately do
     * NOT cache the rendered HTML against the token because a
     * revocation has to take effect immediately, and the tree
     * shape changes whenever members join.
     */
    private static function render_share_chrome_and_tree($row) {
        global $wpdb;

        $owner = get_userdata((int) $row->user_id);
        if (!$owner) {
            // Owner deleted — surface as an invalid-link page
            // rather than an internal error.
            ?>
            <div class="mma-share-error">
                <h2><?php esc_html_e('This share link is no longer valid', 'matrix-mlm'); ?></h2>
                <p><?php esc_html_e('The owner of this link no longer has an account.', 'matrix-mlm'); ?></p>
            </div>
            <?php
            return;
        }

        // Resolve plan & root position. Two-step:
        //   1. If the token pinned a plan_id, use it. Otherwise
        //      pick the owner's most-recent active position.
        //   2. If the token pinned a pivot_user_id (sub-branch
        //      share), root the view there; else use the owner.
        $position = null;
        if (!empty($row->plan_id)) {
            $position = $wpdb->get_row($wpdb->prepare(
                "SELECT p.*, pl.name AS plan_name, pl.width, pl.depth
                   FROM {$wpdb->prefix}matrix_positions p
                   JOIN {$wpdb->prefix}matrix_plans pl ON p.plan_id = pl.id
                  WHERE p.user_id = %d AND p.plan_id = %d AND p.status = 'active'
                  ORDER BY p.joined_at DESC LIMIT 1",
                (int) $row->user_id,
                (int) $row->plan_id
            ));
        }
        if (!$position) {
            $position = $wpdb->get_row($wpdb->prepare(
                "SELECT p.*, pl.name AS plan_name, pl.width, pl.depth
                   FROM {$wpdb->prefix}matrix_positions p
                   JOIN {$wpdb->prefix}matrix_plans pl ON p.plan_id = pl.id
                  WHERE p.user_id = %d AND p.status = 'active'
                  ORDER BY p.joined_at DESC LIMIT 1",
                (int) $row->user_id
            ));
        }
        if (!$position) {
            ?>
            <div class="mma-share-error">
                <h2><?php esc_html_e('Nothing to show yet', 'matrix-mlm'); ?></h2>
                <p><?php esc_html_e('This user has no active matrix to share at the moment.', 'matrix-mlm'); ?></p>
            </div>
            <?php
            return;
        }

        // Apply the optional pivot_user_id pin. Same authorization
        // rule the dashboard uses — the pivot must be in the
        // owner's downline. Mint-time validation already enforces
        // that, but we re-check at view time so a revoked /
        // moved-out-of-downline branch can't be re-shared via a
        // stale token.
        $display_position = $position;
        if (!empty($row->pivot_user_id)) {
            $plan_engine = new Matrix_MLM_Plan_Engine();
            $pivot_pid = $plan_engine->get_position_id_for_user_in_plan(
                (int) $row->pivot_user_id,
                (int) $position->plan_id
            );
            if ($pivot_pid > 0
                && $plan_engine->user_can_view_position((int) $row->user_id, $pivot_pid)) {
                $maybe_pivot = $wpdb->get_row($wpdb->prepare(
                    "SELECT p.*, pl.name AS plan_name, pl.width, pl.depth
                       FROM {$wpdb->prefix}matrix_positions p
                       JOIN {$wpdb->prefix}matrix_plans pl ON p.plan_id = pl.id
                      WHERE p.id = %d",
                    $pivot_pid
                ));
                if ($maybe_pivot) {
                    $display_position = $maybe_pivot;
                }
            }
        }

        $plan_engine = new Matrix_MLM_Plan_Engine();
        $tree = $plan_engine->get_matrix_tree(
            (int) $display_position->user_id,
            (int) $display_position->plan_id,
            self::PUBLIC_RENDER_DEPTH
        );

        $owner_name = $owner->display_name !== '' ? $owner->display_name : $owner->user_login;
        $max_members = Matrix_MLM_Plan_Engine::calculate_max_members(
            (int) $display_position->width,
            (int) $display_position->depth
        );
        ?>
        <div class="mma-share-bar">
            <h1>
                <?php
                printf(
                    /* translators: %s: member display name */
                    esc_html__('%s — Genealogy', 'matrix-mlm'),
                    esc_html($owner_name)
                );
                ?>
            </h1>
            <span class="badge"><?php esc_html_e('Shared read-only', 'matrix-mlm'); ?></span>
        </div>

        <div class="mma-share-wrap">
            <div class="mma-share-meta">
                <h2><?php echo esc_html($display_position->plan_name); ?>
                    <span style="color:#6b7280;font-weight:400;font-size:13px;">
                        (<?php echo (int) $display_position->width . 'x' . (int) $display_position->depth; ?>)
                    </span>
                </h2>
                <span class="stat">
                    <?php esc_html_e('Downline:', 'matrix-mlm'); ?>
                    <strong><?php echo number_format((int) $display_position->total_downline); ?></strong>
                </span>
                <span class="stat">
                    <?php esc_html_e('Plan capacity:', 'matrix-mlm'); ?>
                    <strong><?php echo number_format($max_members); ?></strong>
                </span>
                <span class="stat no-print" style="float:right;">
                    <button onclick="window.print()" style="background:#4f46e5;color:#fff;border:0;padding:6px 14px;border-radius:6px;cursor:pointer;font-size:12px;">
                        <?php esc_html_e('Print / Save as PDF', 'matrix-mlm'); ?>
                    </button>
                </span>
            </div>

            <div class="mma-share-tree-wrapper">
                <div class="mma-share-tree">
                    <?php
                    if ($tree) {
                        self::render_node(
                            $tree,
                            (int) $display_position->width,
                            true,
                            (int) $display_position->user_id,
                            self::PUBLIC_RENDER_DEPTH
                        );
                    } else {
                        echo '<p style="color:#6b7280;">'
                            . esc_html__('No tree data available yet.', 'matrix-mlm')
                            . '</p>';
                    }
                    ?>
                </div>
            </div>

            <p class="mma-share-footer">
                <?php
                printf(
                    /* translators: %s: site title link */
                    wp_kses(
                        __('Powered by %s', 'matrix-mlm'),
                        ['a' => ['href' => []]]
                    ),
                    '<a href="' . esc_url(home_url('/')) . '">' . esc_html(get_bloginfo('name')) . '</a>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Self-contained recursive renderer for the public tree.
     *
     * Distinct from Matrix_MLM_User_Genealogy::render_tree_node()
     * and Matrix_MLM_Admin_Genealogy::render_tree_node() because
     * the public surface has different needs:
     *
     *   - No drag-handles or empty-slot data attrs (read-only).
     *   - No heat-map data attrs (no toggle).
     *   - No lazy-load button (the depth cap is a hard wall here).
     *   - No "you" badge (the viewer isn't logged in).
     *
     * Trying to thread these absences through the existing
     * renderers would push the conditionals into every helper
     * method; a 60-line dedicated walker is cleaner.
     */
    private static function render_node($node, $width, $is_root, $root_user_id, $max_depth) {
        if (!$node) {
            return;
        }

        $username       = isset($node['username']) ? (string) $node['username'] : '';
        $user_id        = (int) ($node['user_id'] ?? 0);
        $level          = (int) ($node['level'] ?? 0);
        $total_downline = (int) ($node['total_downline'] ?? 0);
        $sponsor_id     = isset($node['sponsor_id']) ? (int) $node['sponsor_id'] : 0;
        $children       = isset($node['children']) && is_array($node['children'])
            ? $node['children']
            : [];

        $node_class = 'mma-share-node-spillover';
        if ($is_root) {
            $node_class = 'mma-share-node-root';
        } elseif ($sponsor_id > 0 && $sponsor_id === (int) $root_user_id) {
            $node_class = 'mma-share-node-direct';
        }

        $display = $username !== '' ? $username : ('User #' . $user_id);
        ?>
        <div class="mma-share-item">
            <div class="mma-share-node <?php echo esc_attr($node_class); ?>">
                <div class="mma-share-name"><?php echo esc_html($display); ?></div>
                <div class="mma-share-meta-row">
                    <?php
                    printf(
                        /* translators: %d: subtree size */
                        esc_html__('Downline: %d', 'matrix-mlm'),
                        $total_downline
                    );
                    ?>
                </div>
            </div>

            <?php
            $rendered = count($children);
            $can_recurse    = ($level < $max_depth);
            $has_more_below = ($total_downline > $rendered);

            if ($can_recurse): ?>
                <?php if ($rendered > 0 || $width > 0): ?>
                <div class="mma-share-children">
                    <?php
                    foreach ($children as $child) {
                        self::render_node($child, $width, false, $root_user_id, $max_depth);
                    }
                    $remaining = max(0, $width - $rendered);
                    for ($i = 0; $i < $remaining; $i++) {
                        echo '<div class="mma-share-item"><div class="mma-share-node mma-share-node-empty">'
                            . esc_html__('Empty slot', 'matrix-mlm')
                            . '</div></div>';
                    }
                    ?>
                </div>
                <?php endif; ?>
            <?php elseif ($has_more_below): ?>
                <div class="mma-share-truncated">
                    <?php
                    printf(
                        /* translators: %d: hidden descendants */
                        esc_html__('+%d more below', 'matrix-mlm'),
                        $total_downline - $rendered
                    );
                    ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // ---------------------------------------------------------------
    // AJAX endpoints — called from the dashboard share panel
    // ---------------------------------------------------------------

    public static function ajax_create_token() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Login required.', 'matrix-mlm')]);
        }

        $user_id       = get_current_user_id();
        $plan_id_raw   = isset($_POST['plan_id']) ? (int) $_POST['plan_id'] : 0;
        $expiry_days   = isset($_POST['expiry_days']) ? (int) $_POST['expiry_days'] : 0;
        $label         = isset($_POST['label']) ? sanitize_text_field((string) $_POST['label']) : '';
        $pivot_user_id = isset($_POST['pivot_user_id']) ? (int) $_POST['pivot_user_id'] : 0;

        // expiry_days bounds: 0 means "never", 1-365 picks an
        // interval. Clamp the upper bound so a typo can't write a
        // pseudo-eternal share that's hard to find later.
        $expires_at = null;
        if ($expiry_days > 0) {
            $expiry_days = min(365, max(1, $expiry_days));
            $expires_at  = date('Y-m-d H:i:s', current_time('timestamp') + ($expiry_days * 86400));
        }

        // Validate plan ownership: a user can only mint a token
        // for a plan they actually have an active position in.
        // Passing 0 (or an invalid id) is treated as "let the
        // public renderer pick at view time".
        $plan_id_to_save = null;
        if ($plan_id_raw > 0) {
            global $wpdb;
            $owns = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}matrix_positions
                  WHERE user_id = %d AND plan_id = %d AND status = 'active'",
                $user_id, $plan_id_raw
            ));
            if ($owns > 0) {
                $plan_id_to_save = $plan_id_raw;
            }
        }

        // Validate pivot ownership: the pivot user must be in the
        // owner's downline (same auth gate the dashboard pivot
        // flow uses). We re-check here defensively because the
        // dashboard form is the friendly path but the AJAX
        // endpoint is the authoritative one.
        $pivot_to_save = null;
        if ($pivot_user_id > 0 && $plan_id_to_save !== null) {
            $plan_engine = new Matrix_MLM_Plan_Engine();
            $pivot_pid = $plan_engine->get_position_id_for_user_in_plan(
                $pivot_user_id,
                $plan_id_to_save
            );
            if ($pivot_pid > 0
                && $plan_engine->user_can_view_position($user_id, $pivot_pid)) {
                $pivot_to_save = $pivot_user_id;
            }
        }

        $result = self::create_token(
            $user_id,
            $plan_id_to_save,
            $pivot_to_save,
            $expires_at,
            $label
        );
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message'    => __('Share link created.', 'matrix-mlm'),
            'id'         => $result['id'],
            'token'      => $result['token'],
            'url'        => $result['url'],
            'label'      => $label,
            'expires_at' => $expires_at,
        ]);
    }

    public static function ajax_revoke_token() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Login required.', 'matrix-mlm')]);
        }

        $token_id = isset($_POST['token_id']) ? (int) $_POST['token_id'] : 0;
        $result   = self::revoke_token($token_id, get_current_user_id());
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['message' => __('Share link revoked.', 'matrix-mlm')]);
    }
}
