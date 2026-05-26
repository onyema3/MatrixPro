<?php
/**
 * Matrix MLM Pro — Inline SVG icon registry.
 *
 * Why this class exists
 * ---------------------
 * The dashboard sidebar nav, the notifications bell, and the
 * notifications dropdown all used to render icons via WordPress's
 * `dashicons` icon font (`<span class="dashicons dashicons-X">`).
 * That stack repeatedly broke on production deploys against
 * opinionated WordPress themes for two distinct, well-understood
 * reasons:
 *
 *   1. Theme CSS clobbering `font-family` on every `<span>` nested
 *      under a `<nav>` (`nav a span { font-family: inherit !important }`
 *      is a common pattern in themes that style sidebar menus).
 *      With `!important` set on a tied-or-stronger specificity rule
 *      loaded later in the source order, the plugin's stylesheet
 *      cannot win the cascade — see PRs #335 and #336 for two
 *      previous attempts (class-based `!important`, then inline
 *      `style="font-family:dashicons!important"`). Both got beaten
 *      by the production theme.
 *   2. Even when the font *does* resolve, theme resets that null out
 *      `::before` pseudo-elements (which is how dashicons positions
 *      its glyph) leave empty boxes in the layout.
 *
 * Going SVG bypasses both failure modes entirely. An `<svg>` element
 * is a graphical primitive, not a glyph in a font; theme CSS that
 * targets `font-family`, `::before`, or any text-rendering reset
 * cannot affect SVG rendering. Every browser since 2010 renders
 * inline SVG, so the compatibility floor is far below the WP
 * `Requires at least: 5.8` (2021) baseline declared in matrix-mlm.php.
 *
 * Surface
 * -------
 *
 *   Matrix_MLM_Icons::svg($name, $extra_class = '', $size = 16)
 *       Returns escaped, ready-to-echo inline SVG markup.
 *       $name accepts both bare ("download") and dashicons-prefixed
 *       ("dashicons-download") forms so callers don't have to
 *       normalise — the menu definition in
 *       Matrix_MLM_User_Dashboard::dashboard_menu_definition()
 *       carries the prefixed form, while the notifications JS
 *       passes the bare form via wp_localize_script.
 *
 *   Matrix_MLM_Icons::svg_string_map()
 *       Returns name => svg-string array, suitable for handing to
 *       wp_localize_script so the JS notification poll can render
 *       the same icons the server does.
 *
 * Adding a new icon
 * -----------------
 * Add an entry to self::$icons. Keys are bare names (no prefix).
 * Each entry is the inner SVG markup (paths/shapes only, no outer
 * <svg> wrapper). Use `fill="currentColor"` so the icon picks up
 * the link/text color from CSS — the wrapper writes that for you.
 *
 * @package MatrixMLM
 * @since   2.0.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Icons {

    /**
     * Inner SVG markup for every icon used in the dashboard nav,
     * notifications bell, and notifications dropdown. ViewBox is
     * a uniform 24x24 across the registry so call-site sizing is
     * consistent. Paths use `fill="currentColor"` so CSS color
     * inheritance from `<a>`/`<span>` continues to work.
     *
     * Naming follows the dashicons slug convention (e.g. 'money-alt')
     * so call sites can pass through the existing icon string from
     * dashboard_menu_definition() / notification_icon_name() with
     * only the 'dashicons-' prefix optionally stripped.
     *
     * @var array<string,string>
     */
    private static $icons = [
        // ------------------------------------------------------------
        // Sidebar menu icons (Matrix_MLM_User_Dashboard::dashboard_menu_definition())
        // ------------------------------------------------------------

        // Four-pane grid — overview / dashboard.
        'dashboard' => '<path fill="currentColor" d="M3 3h8v8H3zM13 3h8v8h-8zM3 13h8v8H3zM13 13h8v8h-8z"/>',

        // Down-arrow into a tray — deposit.
        'download' => '<path fill="currentColor" d="M11 3h2v9.6l3.3-3.3 1.4 1.4-5 5a1 1 0 0 1-1.4 0l-5-5 1.4-1.4 3.3 3.3zM4 19h16v2H4z"/>',

        // Three rows of list — deposit history.
        'list-view' => '<path fill="currentColor" d="M4 5h3v3H4zm6 1h11v2H10zM4 11h3v3H4zm6 1h11v2H10zM4 17h3v3H4zm6 1h11v2H10z"/>',

        // Tree of three nodes — genealogy / plans.
        'networking' => '<path fill="currentColor" d="M10 2h4v4h-4zm-8 16h4v4H2zm16 0h4v4h-4zM11 6h2v4h-2zm-7 10v-2c0-1.1.9-2 2-2h12c1.1 0 2 .9 2 2v2h-2v-2H6v2z"/>',

        // Three person silhouettes — referrals.
        'groups' => '<path fill="currentColor" d="M12 5a3 3 0 1 1 0 6 3 3 0 0 1 0-6zm-7 2a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5zm14 0a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5zM6 13c1 0 1.9.2 2.7.5A6.5 6.5 0 0 0 6 18.5V20H1v-1.7C1 16 3.2 14.5 6 14.5zm12 0c2.8 0 5 1.5 5 3.8V20h-5v-1.5a6.5 6.5 0 0 0-2.7-5c.8-.3 1.7-.5 2.7-.5zm-6 0c3 0 6 1.5 6 4.5V20H6v-2.5c0-3 3-4.5 6-4.5z"/>',

        // Mountain-line area chart — commissions.
        'chart-area' => '<path fill="currentColor" d="M3 3h2v18h16v2H3zm4 14 4-6 4 4 5-7v8z"/>',

        // Ticket stub — e-pin.
        'tickets-alt' => '<path fill="currentColor" d="M2 7h20v4a2 2 0 0 0 0 4v4H2v-4a2 2 0 0 0 0-4zm5 1v8h2V8zm4 0v8h2V8zm4 0v8h2V8z"/>',

        // Classical bank façade — wallet.
        'bank' => '<path fill="currentColor" d="M12 2 2 7v2h20V7zM4 10h2v8H4zm5 0h2v8H9zm4 0h2v8h-2zm5 0h2v8h-2zM2 19h20v3H2z"/>',

        // ID card with avatar — verve card.
        'id-alt' => '<path fill="currentColor" d="M3 5h18a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm5 4a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zm-3.5 8h7c0-1.5-1.5-2.5-3.5-2.5S4.5 15.5 4.5 17zM14 9h6v2h-6zm0 4h6v2h-6z"/>',

        // Phone outline — bill payments.
        'smartphone' => '<path fill="currentColor" d="M7 1h10a2 2 0 0 1 2 2v18a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2zm0 3v15h10V4zm4 16h2v1h-2z"/>',

        // Medal with ribbon — benefits / awards.
        'awards' => '<path fill="currentColor" d="M7 2h10l-2 6h-6zm5 8a5 5 0 1 1 0 10 5 5 0 0 1 0-10zm0 2.5L11 15l-2.5.4 1.8 1.8-.4 2.5 2.1-1.2 2.1 1.2-.4-2.5 1.8-1.8L13 15z"/>',

        // Lifebuoy ring — support / SOS.
        'sos' => '<path fill="currentColor" d="M12 2a10 10 0 1 1 0 20 10 10 0 0 1 0-20zm0 5a5 5 0 1 0 0 10 5 5 0 0 0 0-10zm-7.6 4.6 3.2.7a4.5 4.5 0 0 0 0 1.4l-3.2.7zM19.6 11.6 16.4 12a4.5 4.5 0 0 1 0 1.4l3.2.7zM11.3 4.4l.7 3.2a4.5 4.5 0 0 1 1.4 0l-.7-3.2zM12 16.4a4.5 4.5 0 0 1-1.4 0l-.7 3.2 3.4-.4z"/>',

        // Single person — profile.
        'admin-users' => '<path fill="currentColor" d="M12 4a4 4 0 1 1 0 8 4 4 0 0 1 0-8zm0 10c4.4 0 8 2.7 8 6v2H4v-2c0-3.3 3.6-6 8-6z"/>',

        // Shield outline — 2FA security.
        'shield' => '<path fill="currentColor" d="M12 2 4 5v6c0 5 3.5 9.5 8 11 4.5-1.5 8-6 8-11V5z"/>',

        // Door with arrow — logout.
        'exit' => '<path fill="currentColor" d="M14 3h6a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1h-6v-2h5V5h-5zm-2 4 5 5-5 5v-3H3v-4h9z"/>',

        // ------------------------------------------------------------
        // Notification bell + dropdown icons
        // ------------------------------------------------------------

        // Bell with clapper — notification trigger.
        'bell' => '<path fill="currentColor" d="M12 2a2 2 0 0 1 2 2v.6c3.4.9 6 4 6 7.4v4l2 2v1H2v-1l2-2v-4c0-3.4 2.6-6.5 6-7.4V4a2 2 0 0 1 2-2zm-2 18h4a2 2 0 0 1-4 0z"/>',

        // Dollar bill — money received/loan.
        'money-alt' => '<path fill="currentColor" d="M3 6h18v12H3zm9 2a4 4 0 1 0 0 8 4 4 0 0 0 0-8zM6 9a1 1 0 0 0 0 2zm12 0a1 1 0 0 1 0 2zM6 13a1 1 0 0 0 0 2zm12 0a1 1 0 0 1 0 2zm-6.5-2.5h1c.4 0 .7.1.7.5a.5.5 0 0 1-.4.5h-.6a1.5 1.5 0 0 0 0 3h.5v.5h1V14h.5a1.5 1.5 0 0 0 0-3h-1c-.4 0-.7-.1-.7-.5a.5.5 0 0 1 .4-.5h.6V9h-1V8h-1v1h-.5a1.5 1.5 0 0 0 0 3h1z"/>',

        // Arrow up-out node — share / transfer-sent.
        'share' => '<path fill="currentColor" d="M18 2a3 3 0 1 1-2.8 4.1L9.5 9.4a3 3 0 0 1 0 5.2l5.7 3.3a3 3 0 1 1-1 1.7l-5.7-3.3a3 3 0 1 1 0-5.2l5.7-3.3A3 3 0 0 1 18 2z"/>',

        // Check in circle — approved.
        'yes-alt' => '<path fill="currentColor" d="M12 2a10 10 0 1 1 0 20 10 10 0 0 1 0-20zm-1 14 7-7-1.4-1.4L11 13.2 7.4 9.6 6 11z"/>',

        // X in circle — rejected.
        'no-alt' => '<path fill="currentColor" d="M12 2a10 10 0 1 1 0 20 10 10 0 0 1 0-20zm-3.5 5.1L7.1 8.5l3.5 3.5-3.5 3.5 1.4 1.4 3.5-3.5 3.5 3.5 1.4-1.4-3.5-3.5 3.5-3.5-1.4-1.4L12 10.6z"/>',

        // Triangle with exclamation — warning.
        'warning' => '<path fill="currentColor" d="M12 2 2 21h20zm-1 7h2v6h-2zm0 8h2v2h-2z"/>',

        // Play button — cable / video.
        'format-video' => '<path fill="currentColor" d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zm6 4v8l6-4z"/>',

        // Light bulb — electricity.
        'lightbulb' => '<path fill="currentColor" d="M12 2a7 7 0 0 1 5 11.9c-.6.7-1 1.5-1 2.1v.5h-8v-.5c0-.6-.4-1.4-1-2.1A7 7 0 0 1 12 2zm-3 16h6v2a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1z"/>',

        // Arrow circling — refund / refresh.
        'image-rotate' => '<path fill="currentColor" d="M12 4V1L7 5l5 4V6a6 6 0 0 1 6 6h2a8 8 0 0 0-8-8zm0 16v3l5-4-5-4v3a6 6 0 0 1-6-6H4a8 8 0 0 0 8 8z"/>',

        // Calendar — subscription.
        'calendar-alt' => '<path fill="currentColor" d="M7 2v2H4a1 1 0 0 0-1 1v15a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1V5a1 1 0 0 0-1-1h-3V2h-2v2H9V2zm-2 8h14v10H5zm2 2v2h2v-2zm4 0v2h2v-2zm4 0v2h2v-2zM7 16v2h2v-2zm4 0v2h2v-2zm4 0v2h2v-2z"/>',

        // Heart — healthcare.
        'heart' => '<path fill="currentColor" d="M12 21.3 3 12.4a5.6 5.6 0 0 1 7.9-7.9L12 5.6l1.1-1.1a5.6 5.6 0 0 1 7.9 7.9z"/>',

        // Megaphone — admin announcement.
        'megaphone' => '<path fill="currentColor" d="M3 9h4l8-5v16l-8-5H3a1 1 0 0 1-1-1v-4a1 1 0 0 1 1-1zm6 8a3 3 0 0 0 6 0v-1H9zm10-4h3v2h-3zm0-4 2.5-1.5.6 1.7L19 11zm0 8 2.5 1.5.6-1.7L19 13z"/>',

        // i in circle — generic info / fallback.
        'info-outline' => '<path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 4a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3zm-2 5h3v6h1v2h-4v-2h1v-4h-1z"/>',
    ];

    /**
     * Render a single SVG icon ready for echo.
     *
     * Output shape:
     *
     *     <svg class="matrix-icon matrix-icon-{name} {extra_class}"
     *          width="16" height="16" viewBox="0 0 24 24"
     *          aria-hidden="true" focusable="false"
     *          xmlns="http://www.w3.org/2000/svg">
     *         {inner-paths-from-registry}
     *     </svg>
     *
     * Notes:
     *   - aria-hidden="true" matches the previous span behaviour
     *     (every dashicon span in the patched call sites carried
     *     aria-hidden — visible accessible name was on the wrapping
     *     <a> or <button>, not the icon).
     *   - focusable="false" is the IE11 carry-over that prevents a
     *     stray tab-stop on the SVG element itself; cheap insurance
     *     for any caller running on ancient browsers.
     *   - Default 16px matches the pre-existing
     *     `.matrix-dashboard-nav a .dashicons { width:16px; height:16px }`
     *     rule, so the swap is layout-neutral on the sidebar.
     *
     * @param string $name        Bare ('download') or prefixed ('dashicons-download') icon name.
     * @param string $extra_class Optional extra CSS class(es) for the <svg>.
     * @param int    $size        Width/height in px on the rendered element. Default 16.
     * @return string             Escape-safe inline SVG. Returns '' if $name is unknown.
     */
    public static function svg($name, $extra_class = '', $size = 16) {
        $key = self::normalise_name($name);
        if ($key === '' || !isset(self::$icons[$key])) {
            return '';
        }

        $size_attr = (int) $size;
        if ($size_attr <= 0) {
            $size_attr = 16;
        }

        $classes = 'matrix-icon matrix-icon-' . $key;
        if (is_string($extra_class) && $extra_class !== '') {
            $classes .= ' ' . $extra_class;
        }

        return sprintf(
            '<svg class="%s" width="%d" height="%d" viewBox="0 0 24 24" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">%s</svg>',
            esc_attr($classes),
            $size_attr,
            $size_attr,
            self::$icons[$key]
        );
    }

    /**
     * Build a name => fully-rendered-svg-string map, suitable for
     * handing to wp_localize_script() so the JS notification poll
     * can inject the same SVG strings the server renders.
     *
     * The JS side reads matrixNotifConfig.icons[type] when it
     * substitutes a freshly-polled row into the dropdown — see
     * iconSvgFor() in public/js/matrix-notifications.js. Keeping
     * the mapping server-driven means a future icon swap on the
     * server propagates to the client without a JS code change.
     *
     * Only icons that the notifications surface can render are
     * included (sidebar-only icons would just bloat the localized
     * config). The 'info-outline' fallback is always present so
     * the JS side never has to worry about a missing key.
     *
     * @return array<string,string>
     */
    public static function svg_string_map() {
        $notification_icons = [
            'bell',
            'money-alt',
            'share',
            'groups',
            'chart-area',
            'awards',
            'download',
            'yes-alt',
            'no-alt',
            'bank',
            'warning',
            'smartphone',
            'format-video',
            'lightbulb',
            'image-rotate',
            'id-alt',
            'tickets-alt',
            'calendar-alt',
            'shield',
            'heart',
            'megaphone',
            'info-outline',
        ];

        $out = [];
        foreach ($notification_icons as $name) {
            // Notification rows render the SVG inside a 32x32
            // .matrix-notif-icon span (the coloured-bubble badge),
            // with the SVG itself sized at 18px to leave room for
            // the bubble's padding. The 'matrix-notif-icon' class
            // is on the SPAN, not the SVG, so we don't add it here.
            $out[$name] = self::svg($name, '', 18);
        }
        return $out;
    }

    /**
     * Strip the 'dashicons-' prefix if present and lowercase the
     * result. Lets call sites pass either form interchangeably so
     * existing dashicon-named registries (the menu definition,
     * notification_icon_name()) don't have to be rewritten when a
     * new icon is added.
     *
     * @param string $name
     * @return string
     */
    private static function normalise_name($name) {
        if (!is_string($name)) {
            return '';
        }
        $name = strtolower(trim($name));
        if (strpos($name, 'dashicons-') === 0) {
            $name = substr($name, strlen('dashicons-'));
        }
        return $name;
    }
}
