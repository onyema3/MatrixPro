<?php
/**
 * Signed-URL attachment delivery for KYC documents (audit M3).
 *
 * ## Why this exists
 *
 * Loan and healthcare applications upload sensitive personal
 * documents (NIN slips, utility bills, ID cards, passport photos,
 * guarantor IDs, medical history). Pre-PR they were stored at a
 * public path under /wp-content/uploads/matrix-loan-files/<uid>/
 * with a deny-PHP-execution .htaccess but no other access control —
 * anyone who learned a URL (admin link forwarded over chat, CSRF
 * leak, log scrape, screenshot recipient) could fetch the file
 * indefinitely. The 8-hex slug in the filename gives only ~32 bits
 * of guessing entropy, far below what we'd want gating these
 * documents.
 *
 * The fix is two-layered:
 *
 *   1. Direct HTTP access to the upload dir is blocked at the
 *      web-server layer (see Matrix_MLM_User_Loan::ensure_upload_guards
 *      v2). Apache 'Require all denied' on every file in the dir;
 *      the deny-PHP rule is kept as defense-in-depth.
 *
 *   2. Admin views never link to the raw /uploads/ URL anymore.
 *      They call ::sign_url_from_public() which produces a short-
 *      lived signed URL pointing at this class's REST handler. The
 *      handler validates HMAC + expiry + the requesting admin's
 *      capability, resolves the path against an allow-list of
 *      subtrees, and streams the file from disk through PHP. The
 *      raw /uploads/ URL is now functionally unreachable.
 *
 * ## Threat model
 *
 *   - Forwarded-link replay: a signed URL screenshotted to a chat
 *     thread is useful for at most TTL_SECONDS (10 minutes),
 *     after which the HMAC fails the freshness check.
 *
 *   - Path traversal: relative paths are resolved with realpath()
 *     and rejected unless they sit under an allowlisted subtree
 *     of wp_upload_dir()['basedir']. The HMAC is computed over the
 *     relative path string before the realpath check, so a forged
 *     URL that includes '..' would still need to find a working
 *     HMAC for that exact string — and the realpath gate would
 *     refuse it anyway.
 *
 *   - Capability escalation: the handler calls current_user_can()
 *     with the same gate the admin pages use ('manage_matrix_mlm').
 *     A leaked signed URL handed to a non-admin still 403s.
 *
 *   - Key rotation: the signing key is derived from wp_salt('auth')
 *     with a domain-separating context. Operators rotating their
 *     salts will invalidate every outstanding signed URL — fine,
 *     they're 10-minute throwaways anyway. The optional
 *     MATRIX_MLM_ATTACHMENT_SIGNING_KEY constant lets a multi-host
 *     deployment pin a stable key independent of WP salts.
 *
 * ## What this is not
 *
 *   - This is not a member-facing share link. There is no public
 *     ownership claim; only admins (manage_matrix_mlm) can fetch.
 *     If members ever need to download their own uploads we'd add
 *     a parallel path that gates on user_id ownership.
 *
 *   - It does not enforce per-document access controls. Any admin
 *     with the manage_matrix_mlm cap can fetch any signed
 *     attachment in the allowed subtrees. That matches the existing
 *     admin model (the Loans/Healthcare review pages are an
 *     all-or-nothing surface).
 *
 * @since 1.0.10
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Attachment_Signer {

    /**
     * REST namespace + route. The full URL ends up looking like
     *     /wp-json/matrix-mlm/v1/attachment?p=...&exp=...&sig=...
     * matching the rest of the plugin's REST surface.
     */
    const REST_NAMESPACE = 'matrix-mlm/v1';
    const REST_ROUTE     = '/attachment';

    /**
     * Signed-URL lifetime. 10 minutes is long enough to load an
     * admin review page, click through every document, and re-open
     * one in a new tab without re-rendering — short enough that a
     * URL forwarded to the wrong chat thread isn't a long-lived
     * credential. Filterable via 'matrix_mlm_attachment_signing_ttl'
     * for installs that want to shrink it further.
     */
    const TTL_SECONDS = 600;

    /**
     * Domain-separating context for the HMAC key derivation.
     * Different contexts produce different keys, so a signed URL
     * for an attachment can never be reinterpreted as a token for
     * any other purpose (and vice versa).
     */
    const KEY_CONTEXT = 'matrix_mlm:attachment_signer:v1';

    /**
     * Allowlisted subtrees under wp_upload_dir()['basedir'].
     *
     * The relative path of any attachment must start with one of
     * these prefixes (after normalisation). Adding healthcare here
     * up-front because legacy healthcare rows may still have URLs
     * in their schema even though the current form doesn't accept
     * uploads anymore — admin doc cards still render those.
     */
    const ALLOWED_SUBTREES = [
        '/matrix-loan-files/',
        '/matrix-healthcare-files/',
    ];

    /**
     * Wire the REST handler. Called once from matrix-mlm.php on
     * every request (REST init only fires on REST requests, but
     * register_rest_route is cheap and idempotent so it's fine to
     * always hook it).
     */
    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes() {
        register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle_request'],
            // permission_callback runs before the handler. We do not
            // gate the route shape here — the handler is the
            // authority because it needs to validate the HMAC
            // signature in a uniform way regardless of the caller's
            // session state. A leaked signed URL handed to a logged-
            // out user is still rejected (capability check inside
            // the handler), and a logged-in admin without a signed
            // URL is also rejected. Returning '__return_true' here
            // is the conventional way to express "no route-level
            // permission, the handler decides".
            'permission_callback' => '__return_true',
            'args' => [
                'p'   => [
                    'required' => true,
                    'type'     => 'string',
                ],
                'exp' => [
                    'required' => true,
                    'type'     => 'integer',
                ],
                'sig' => [
                    'required' => true,
                    'type'     => 'string',
                ],
            ],
        ]);
    }

    /**
     * Sign an absolute /uploads/ URL into a short-lived REST URL.
     *
     * Returns the signed URL on success, or the original $public_url
     * unchanged if the URL doesn't sit under an allowlisted subtree
     * (so callers can pass any URL through without branching). The
     * "fall through to original URL" path is only ever exercised by
     * pre-existing rows pointing somewhere unexpected; in normal
     * operation every loan/healthcare URL resolves to a signable
     * path.
     */
    public static function sign_url_from_public($public_url) {
        $public_url = (string) $public_url;
        if ($public_url === '') {
            return $public_url;
        }
        $relative = self::public_url_to_relative($public_url);
        if ($relative === null) {
            // Not under an allowlisted upload subtree — leave the
            // URL alone so the existing render logic still produces
            // something. The web-server-layer .htaccess deny is the
            // backstop on legacy paths.
            return $public_url;
        }
        return self::sign_relative_path($relative);
    }

    /**
     * Build a signed URL for a path RELATIVE to wp_upload_dir basedir.
     * The path must start with a leading slash and must already match
     * one of the ALLOWED_SUBTREES prefixes.
     */
    public static function sign_relative_path($relative) {
        $relative = (string) $relative;
        if ($relative === '' || $relative[0] !== '/') {
            return ''; // Refuse to sign malformed input.
        }
        if (!self::is_allowed_subtree($relative)) {
            return '';
        }

        $ttl = (int) apply_filters('matrix_mlm_attachment_signing_ttl', self::TTL_SECONDS);
        if ($ttl < 30) {
            $ttl = 30; // Floor — never produce a URL that's already expiring.
        }
        $exp = time() + $ttl;

        $sig = self::compute_signature($relative, $exp);

        $base = rest_url(self::REST_NAMESPACE . self::REST_ROUTE);
        return add_query_arg([
            'p'   => self::b64u_encode($relative),
            'exp' => $exp,
            'sig' => $sig,
        ], $base);
    }

    /**
     * REST handler. Validates and streams.
     *
     * The order of checks is deliberate:
     *   1. Capability check first. A leaked URL handed to a logged-
     *      out user 403s without revealing whether the URL was even
     *      well-formed.
     *   2. Decode and length-bound the path. Blow up early on
     *      garbage input.
     *   3. Constant-time HMAC compare against the supplied signature.
     *   4. Freshness (exp). After this point we know the caller has
     *      a fresh, untampered signed URL.
     *   5. Allowlist the relative path against ALLOWED_SUBTREES.
     *   6. realpath() the resolved on-disk file and confirm it sits
     *      inside the resolved basedir + allowed subtree.
     *   7. Stream.
     */
    public static function handle_request($request) {
        // 1. Capability gate. Same gate the admin Loans/Healthcare
        //    pages use; a non-admin who somehow obtained a signed
        //    URL still cannot use it.
        if (!current_user_can('manage_matrix_mlm')) {
            return new WP_Error(
                'matrix_mlm_attachment_forbidden',
                __('You do not have permission to view this attachment.', 'matrix-mlm'),
                ['status' => 403]
            );
        }

        $p_b64 = (string) $request->get_param('p');
        $exp   = (int)    $request->get_param('exp');
        $sig   = (string) $request->get_param('sig');

        // 2. Decode the path. Length-bound to keep memory predictable
        //    on garbage input — real paths are well under this.
        if ($p_b64 === '' || strlen($p_b64) > 1024 || $sig === '' || $exp <= 0) {
            return new WP_Error(
                'matrix_mlm_attachment_invalid',
                __('Invalid attachment URL.', 'matrix-mlm'),
                ['status' => 400]
            );
        }
        $relative = self::b64u_decode($p_b64);
        if ($relative === null || $relative === '' || $relative[0] !== '/') {
            return new WP_Error(
                'matrix_mlm_attachment_invalid',
                __('Invalid attachment URL.', 'matrix-mlm'),
                ['status' => 400]
            );
        }

        // 3. Constant-time signature check. hash_equals neutralises
        //    timing attacks on the comparison itself; the HMAC over
        //    relative+exp is what binds the (path, expiry) tuple to
        //    the issuer.
        $expected = self::compute_signature($relative, $exp);
        if (!hash_equals($expected, $sig)) {
            return new WP_Error(
                'matrix_mlm_attachment_invalid',
                __('Invalid or expired attachment URL.', 'matrix-mlm'),
                ['status' => 403]
            );
        }

        // 4. Freshness. After signature validation so we never
        //    leak via timing whether 'sig' or 'exp' was wrong.
        if ($exp < time()) {
            return new WP_Error(
                'matrix_mlm_attachment_expired',
                __('This attachment link has expired. Reload the admin page to get a fresh link.', 'matrix-mlm'),
                ['status' => 410]
            );
        }

        // 5. Allowlist. The signature gate already ensures we
        //    issued this exact path string, but we re-check the
        //    subtree here as defense-in-depth: a future bug in the
        //    sign_relative_path allowlist could otherwise quietly
        //    issue tokens for unintended subtrees.
        if (!self::is_allowed_subtree($relative)) {
            return new WP_Error(
                'matrix_mlm_attachment_invalid',
                __('Invalid attachment URL.', 'matrix-mlm'),
                ['status' => 400]
            );
        }

        // 6. Resolve to an on-disk path under wp_upload_dir basedir
        //    and confirm via realpath that we didn't escape the
        //    intended subtree (symlinks, '..', whatever).
        $basedir = self::canonical_basedir();
        if ($basedir === null) {
            return new WP_Error(
                'matrix_mlm_attachment_unavailable',
                __('Attachment storage is not available.', 'matrix-mlm'),
                ['status' => 500]
            );
        }
        $candidate = $basedir . $relative;
        $real = @realpath($candidate);
        if ($real === false || strpos($real, $basedir) !== 0) {
            return new WP_Error(
                'matrix_mlm_attachment_not_found',
                __('Attachment not found.', 'matrix-mlm'),
                ['status' => 404]
            );
        }
        // Confirm the real path also stays inside an allowed subtree.
        // strpos(_, basedir) === 0 above ensures the resolved file
        // lives under the WP uploads root; this stricter check rules
        // out any non-loan / non-healthcare directory the basedir
        // happens to contain.
        $real_relative = substr($real, strlen($basedir));
        if (!self::is_allowed_subtree($real_relative)) {
            return new WP_Error(
                'matrix_mlm_attachment_not_found',
                __('Attachment not found.', 'matrix-mlm'),
                ['status' => 404]
            );
        }

        // 7. Stream. We bypass the REST response path here because
        //    REST_Server expects JSON; for binary file delivery we
        //    set headers and exit. nocache_headers() so admin
        //    browsers don't cache an attachment beyond the lifespan
        //    of the link.
        $mime = self::resolve_mime($real);
        if (!headers_sent()) {
            nocache_headers();
            header('Content-Type: ' . $mime);
            $size = @filesize($real);
            if ($size !== false) {
                header('Content-Length: ' . $size);
            }
            // 'inline' so the browser previews PDFs/images in a tab
            // (matches the existing target=_blank link UX in the
            // admin renders). Filename is sanitised to ASCII for
            // header safety.
            $download_name = sanitize_file_name(basename($real));
            header('Content-Disposition: inline; filename="' . $download_name . '"');
            header('X-Content-Type-Options: nosniff');
            // Same-origin only — defense-in-depth against an admin
            // page that accidentally embeds via a third-party iframe.
            header('X-Frame-Options: SAMEORIGIN');
        }
        @readfile($real);
        exit;
    }

    /**
     * Convert an absolute /uploads/... URL into a path relative to
     * the WP uploads basedir. Returns null if the URL does not sit
     * under the uploads baseurl at all (e.g. an external URL stored
     * in a legacy column from before wp_handle_upload was used).
     */
    private static function public_url_to_relative($public_url) {
        $upload = wp_upload_dir();
        if (!is_array($upload) || empty($upload['baseurl'])) {
            return null;
        }
        $baseurl = (string) $upload['baseurl'];
        // Normalise both sides to schemeless to tolerate http→https
        // upgrades and CDN host swaps that don't change the path
        // component. We want to compare the directory tree, not the
        // host.
        $bp = wp_parse_url($baseurl, PHP_URL_PATH);
        $up = wp_parse_url($public_url, PHP_URL_PATH);
        if (!is_string($bp) || !is_string($up)) {
            return null;
        }
        if (strpos($up, $bp) !== 0) {
            return null;
        }
        $rel = substr($up, strlen($bp));
        if ($rel === '' || $rel[0] !== '/') {
            return null;
        }
        return $rel;
    }

    /**
     * Returns the canonical (realpath'd) wp_upload_dir basedir, or
     * null if it cannot be determined / does not exist on disk.
     */
    private static function canonical_basedir() {
        $upload = wp_upload_dir();
        if (!is_array($upload) || empty($upload['basedir'])) {
            return null;
        }
        $real = @realpath($upload['basedir']);
        return $real === false ? null : $real;
    }

    private static function is_allowed_subtree($relative) {
        foreach (self::ALLOWED_SUBTREES as $prefix) {
            if (strpos($relative, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * HMAC-SHA256 over a domain-separated message. The signing key
     * itself is derived from wp_salt('auth') xor a fixed context
     * string, so the same install can use HMAC for unrelated
     * purposes later without those signatures being interchangeable.
     *
     * Operators can override the key source by defining the constant
     * MATRIX_MLM_ATTACHMENT_SIGNING_KEY in wp-config.php (useful for
     * blue/green deploys where you want a stable key across
     * environments without exporting the WP salts).
     */
    private static function compute_signature($relative, $exp) {
        $key = self::derive_key();
        $msg = $relative . '|' . (int) $exp;
        return hash_hmac('sha256', $msg, $key);
    }

    private static function derive_key() {
        if (defined('MATRIX_MLM_ATTACHMENT_SIGNING_KEY') && MATRIX_MLM_ATTACHMENT_SIGNING_KEY) {
            $material = (string) MATRIX_MLM_ATTACHMENT_SIGNING_KEY;
        } elseif (function_exists('wp_salt')) {
            $material = wp_salt('auth');
        } else {
            // wp_salt should always be available inside WP; the
            // fallback is purely defensive so unit tests outside the
            // WP bootstrap don't hard-fail.
            $material = (string) (defined('AUTH_KEY') ? AUTH_KEY : 'matrix_mlm_attachment_default');
        }
        return hash_hmac('sha256', self::KEY_CONTEXT, $material, true);
    }

    /**
     * Resolve a MIME type for a file on disk. Prefers WP's
     * filetype lookup (extension → mime via wp_get_mime_types
     * allow-list) and falls back to fileinfo for completeness.
     */
    private static function resolve_mime($abs_path) {
        $type = wp_check_filetype(basename($abs_path));
        if (!empty($type['type'])) {
            return $type['type'];
        }
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = @finfo_file($finfo, $abs_path);
                @finfo_close($finfo);
                if (is_string($detected) && $detected !== '') {
                    return $detected;
                }
            }
        }
        return 'application/octet-stream';
    }

    private static function b64u_encode($raw) {
        return rtrim(strtr(base64_encode((string) $raw), '+/', '-_'), '=');
    }

    private static function b64u_decode($s) {
        $s = strtr((string) $s, '-_', '+/');
        $padding = strlen($s) % 4;
        if ($padding > 0) {
            $s .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($s, true);
        return $decoded === false ? null : $decoded;
    }
}
