<?php
/**
 * User Dashboard
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_User_Dashboard {

    /**
     * Whitelist of tab slugs accepted by the dashboard. Used both to drive
     * the pretty-URL builder in self::tab_url() and to defend the resolver
     * in self::resolve_active_tab() from arbitrary query input. Keep this
     * in sync with the switch in render_tab().
     *
     * @var string[]
     */
    /**
     * Sidebar menu items the admin is NOT allowed to disable. Hiding any
     * of these would either strand the user (no landing tab) or remove
     * the only place a user can perform a critical self-service action,
     * so the visibility toggle is forced to "on" for these slugs.
     *
     *   - overview is the default landing tab AND the default: branch in
     *     render_tab(); a hidden landing tab leaves the dashboard with
     *     no fallback view.
     *   - profile is the only place a user can edit KYC / contact info.
     *   - security is the only surface for managing 2FA + recovery codes.
     *
     * The Logout link in the sidebar is built off wp_logout_url() and is
     * not slug-keyed, so it's naturally exempt without being listed here.
     *
     * @var string[]
     */
    const LOCKED_MENU_SLUGS = ['overview', 'profile', 'security'];

    private static $valid_tabs = [
        'overview',
        'deposits',
        'deposit-history',
        // 'withdraw' and 'withdraw-history' were retired when the
        // Withdraw form was folded into the consolidated Wallet page
        // ("Transfer to Bank") — the standalone tabs duplicated UI
        // and history that the Wallet page already surfaces. Slugs
        // are intentionally absent from the whitelist so any legacy
        // bookmark falls through to overview rather than rendering
        // an orphaned form. The Matrix_MLM_User_Withdrawals class
        // and its matrix_action=withdraw AJAX endpoint were removed
        // entirely in feat/admin-controlled-withdrawals — the live
        // withdrawal surfaces are now the Wallet tab's "Transfer to
        // Own Wallet" pane (Matrix → Fintava virtual) and "Transfer
        // to Bank" pane (Fintava virtual → external bank), both of
        // which evaluate Matrix_MLM_User::can_move_funds() so admin
        // toggles for stopping withdrawals apply uniformly.
        //
        // 'transactions' was retired for the same reason: the wallet
        // page now hosts a "Transaction History" pane (the only place
        // that ledger lives), so a sidebar entry would be a second
        // path to the same content. Legacy /matrix-dashboard/transactions/
        // URLs fall through to overview.
        'referrals',
        'genealogy',
        'commissions',
        'plans',
        'epin',
        'wallet',
        'card',
        'billing',
        'tickets',
        'profile',
        'security',
        'benefits',
        // 1.0.15: full-page notifications tab. Reachable from the
        // bell dropdown's "See all" footer link, paginates the
        // user's complete notification history. The bell icon
        // itself is rendered inline in render_dashboard() — see
        // build_notification_bell() — and is independent of this
        // tab; users can read and dismiss notifications without
        // ever leaving their current tab.
        'notifications',
    ];

    /**
     * Resolve the active dashboard tab from the request.
     *
     * Earlier versions used the second-argument default of get_query_var(),
     * which is a known WordPress trap: WP only falls through to the default
     * when the query var is *not in the parsed query_vars array at all*. As
     * soon as a query var is registered with add_query_var(), WP often
     * parses it as an empty string for unrelated requests on the same
     * page — and an empty string counts as "set", so the default never
     * fires. The result was $tab = '', the switch in render_tab() fell
     * through to default:, and the user saw the overview tab even though
     * the URL clearly carried ?tab=bank-payout.
     *
     * Resolve order (first non-empty wins):
     *   1. matrix_tab query var (set by the /matrix-dashboard/{tab}/ rewrite).
     *   2. ?tab=... query string (legacy / direct URL form).
     *   3. 'overview' default.
     *
     * The result is whitelisted against self::$valid_tabs so an attacker
     * can't drive the renderer down an unintended branch by passing a
     * crafted slug.
     *
     * @return string A safe tab slug.
     */
    private function resolve_active_tab() {
        $candidate = trim((string) get_query_var('matrix_tab', ''));
        if ($candidate === '' && isset($_GET['tab'])) {
            $candidate = trim(sanitize_text_field(wp_unslash($_GET['tab'])));
        }
        if ($candidate === '' || !in_array($candidate, self::$valid_tabs, true)) {
            $candidate = 'overview';
        }
        return $candidate;
    }

    /**
     * Canonical sidebar menu definition. The single source of truth for
     * what the user sees in /matrix-dashboard's left rail and what the
     * admin sees as a list of toggleable items on the Dashboard Menus
     * settings tab.
     *
     * Returned as an ordered array so the sidebar render and the admin
     * checkbox list both surface items in the same order users see them.
     * Each entry must have:
     *
     *   - slug:   matches a case in render_tab() and an entry in
     *             self::$valid_tabs (otherwise the link 404-styles back
     *             to overview as crafted-input).
     *   - label:  i18n string already passed through __().
     *   - icon:   dashicon class without the leading 'dashicons '.
     *
     * The 'overview', 'profile', and 'security' slugs are LOCKED via
     * self::LOCKED_MENU_SLUGS — see is_menu_visible() for the gate
     * semantics. Anything else can be hidden by an admin via the
     * matrix_mlm_dashboard_menu_visibility option.
     *
     * @return array<int, array{slug:string,label:string,icon:string}>
     */
    public static function dashboard_menu_definition() {
        return [
            ['slug' => 'overview',        'label' => __('Dashboard',        'matrix-mlm'), 'icon' => 'dashicons-dashboard'],
            ['slug' => 'deposits',        'label' => __('Deposit',          'matrix-mlm'), 'icon' => 'dashicons-download'],
            ['slug' => 'deposit-history', 'label' => __('Deposit History',  'matrix-mlm'), 'icon' => 'dashicons-list-view'],
            ['slug' => 'genealogy',       'label' => __('Genealogy Tree',   'matrix-mlm'), 'icon' => 'dashicons-networking'],
            ['slug' => 'referrals',       'label' => __('Referrals',        'matrix-mlm'), 'icon' => 'dashicons-groups'],
            ['slug' => 'commissions',     'label' => __('Commissions',      'matrix-mlm'), 'icon' => 'dashicons-chart-area'],
            ['slug' => 'plans',           'label' => __('My Plans',         'matrix-mlm'), 'icon' => 'dashicons-networking'],
            ['slug' => 'epin',            'label' => __('E-Pin Recharge',   'matrix-mlm'), 'icon' => 'dashicons-tickets-alt'],
            ['slug' => 'wallet',          'label' => __('Wallet',           'matrix-mlm'), 'icon' => 'dashicons-bank'],
            ['slug' => 'card',            'label' => __('Verve Card',       'matrix-mlm'), 'icon' => 'dashicons-id-alt'],
            ['slug' => 'billing',         'label' => __('Bill Payments',    'matrix-mlm'), 'icon' => 'dashicons-smartphone'],
            ['slug' => 'benefits',        'label' => __('Benefits',         'matrix-mlm'), 'icon' => 'dashicons-awards'],
            ['slug' => 'tickets',         'label' => __('Support',          'matrix-mlm'), 'icon' => 'dashicons-sos'],
            ['slug' => 'profile',         'label' => __('Profile',          'matrix-mlm'), 'icon' => 'dashicons-admin-users'],
            ['slug' => 'security',        'label' => __('2FA Security',     'matrix-mlm'), 'icon' => 'dashicons-shield'],
        ];
    }

    /**
     * Whether a sidebar menu item is currently visible to dashboard users.
     *
     * Resolution order:
     *
     *   1. Locked slugs (LOCKED_MENU_SLUGS) ALWAYS return true regardless
     *      of stored preference. This is the kill-switch protection that
     *      keeps the admin UI from rendering a dashboard with no landing
     *      tab / no profile editor / no 2FA management surface.
     *   2. The matrix_mlm_dashboard_menu_visibility option is read as a
     *      JSON-encoded slug=>0|1 map. Missing slugs and an unparseable
     *      blob both default to TRUE so:
     *        a. Fresh installs with no option saved show every menu.
     *        b. New menu items added in a future plugin version are
     *           visible by default until an admin chooses to hide them.
     *
     * Both the sidebar render (cosmetic, in render_dashboard()) and the
     * server-side dispatch (defensive, at the top of render_tab()) call
     * this — so a hidden tab is unreachable both via the rendered link
     * and via direct-URL navigation through cached pages.
     *
     * @param string $slug A sidebar slug from dashboard_menu_definition().
     * @return bool TRUE if the menu item should be shown / dispatched.
     */
    public static function is_menu_visible($slug) {
        if (in_array($slug, self::LOCKED_MENU_SLUGS, true)) {
            return true;
        }

        $raw = get_option('matrix_mlm_dashboard_menu_visibility', '');
        if (!is_string($raw) || $raw === '') {
            return true;
        }

        $map = json_decode($raw, true);
        if (!is_array($map) || !array_key_exists($slug, $map)) {
            return true;
        }

        return (bool) $map[$slug];
    }

    /**
     * Build a sidebar nav URL for the given tab.
     *
     * Uses the pretty form /matrix-dashboard/{tab}/ which the rewrite rule
     * registered in Matrix_MLM_Core::add_rewrite_rules() routes to
     * matrix_tab=<tab>. Each tab therefore has a distinct URL path, which
     * is the difference that makes page-cache layers (Cloudflare, LiteSpeed,
     * WP Rocket, etc.) treat them as separate cache entries instead of
     * collapsing every ?tab=X variant into one cached copy of the
     * dashboard page — the failure mode that caused Bank Payout (and any
     * other tab the cache had already captured as a different tab's
     * content) to render the wrong panel after a sidebar click while a
     * direct URL hit produced the right panel.
     *
     * The 'overview' tab is the only one that must NOT carry a slug; it
     * lives at the bare /matrix-dashboard/ URL because the pretty-URL
     * rewrite rule requires a non-empty segment after the slash.
     *
     * Falls back to ?tab=<tab> on installs running with the "Plain"
     * permalink structure (empty permalink_structure option), since the
     * rewrite rule is inert when WP isn't doing pretty URLs and a
     * /bank-payout/ path would 404. The cache-collapse problem this fix
     * targets doesn't apply to Plain permalink installs anyway, since
     * those are typically not behind aggressive page caches.
     *
     * @param string $tab Tab slug.
     * @return string Absolute URL.
     */
    public static function tab_url($tab) {
        $base = home_url('/matrix-dashboard/');
        if ($tab === 'overview' || $tab === '') {
            return $base;
        }
        $structure = (string) get_option('permalink_structure', '');
        if ($structure === '') {
            return add_query_arg('tab', $tab, $base);
        }
        return home_url('/matrix-dashboard/' . $tab . '/');
    }

    public function render_dashboard($atts) {
        if (!is_user_logged_in()) {
            return '<script>window.location.href="' . home_url('/matrix-login') . '";</script>';
        }

        $user_id = get_current_user_id();
        if (!Matrix_MLM_User::is_active($user_id)) {
            // Status-aware recovery branch (was: flat suspended-account
            // alert that locked every non-active user out of the
            // dashboard).
            //
            // The deactivation email at
            // Matrix_MLM_Notifications::send_subscription_deactivation_notification
            // tells lapsed users to "click Pay Subscription on the
            // dashboard" — but the previous early-return blocked the
            // dashboard from loading at all once the user flipped to
            // status='inactive'. That stranded subscription-deactivated
            // users in a dead loop: dashboard refuses to load because
            // they're inactive, but the only self-heal path is through
            // the dashboard.
            //
            // render_inactive_account_view() distinguishes:
            //   - status='inactive' (subscription-driven, recoverable)
            //     → Pay Subscription view with render_field('subscription')
            //     and an inline submit handler posting matrix_action=
            //     pay_subscription. After a successful manual_pay() the
            //     server flips status back to 'active' and the page
            //     reload lands on the normal dashboard.
            //   - any other non-active status (banned, pending, manually
            //     deactivated) → falls through to the legacy suspended
            //     alert, since those states require operator action and
            //     have no user-facing self-heal.
            return $this->render_inactive_account_view($user_id);
        }

        $tab = $this->resolve_active_tab();

        // Surface any unread level-completion milestones for this user
        // as a top-of-page toast stack. Buffered output, since
        // render_dashboard's caller wraps the whole shortcode in
        // ob_start()/ob_get_clean() — emitting the toast HTML before
        // the dashboard wrapper is what we want for a fixed-position
        // overlay, but it also has to flow through the same buffer.
        $level_toasts_html = $this->build_level_completion_toasts($user_id);

        // Transaction-PIN enrolment banner. Surfaces a one-time
        // (per session) prompt at the top of the dashboard when
        // the admin has enabled PIN enforcement on at least one
        // path AND the current user has not set a PIN. Pairs with
        // the inline render_enrolment_callout() in the actual
        // gated forms — the banner is the discovery surface, the
        // form callout is the just-in-time prompt at the moment
        // the user tries to act. Returns '' when no path requires
        // enrolment for this user, so users with a PIN already
        // set never see this and the dashboard render is unchanged
        // for the steady-state case.
        $pin_enrolment_banner_html = $this->build_pin_enrolment_banner($user_id);

        ob_start();
        ?>
        <?php echo $level_toasts_html; ?>
        <?php echo $pin_enrolment_banner_html; ?>
        <div class="matrix-dashboard">
            <div class="matrix-dashboard-sidebar">
                <div class="matrix-user-info">
                    <div class="matrix-avatar"><?php echo get_avatar($user_id, 60); ?></div>
                    <h4><?php echo esc_html(wp_get_current_user()->display_name); ?></h4>
                    <p class="matrix-balance"><?php echo get_option('matrix_mlm_currency_symbol', '₦'); ?><?php echo number_format((new Matrix_MLM_Wallet())->get_balance($user_id), 2); ?></p>
                </div>
                <nav class="matrix-dashboard-nav">
                    <?php
                    // Render only menu items the admin hasn't explicitly
                    // hidden via the Dashboard Menus settings tab. Locked
                    // slugs (overview / profile / security) always pass
                    // is_menu_visible() — see LOCKED_MENU_SLUGS.
                    foreach (self::dashboard_menu_definition() as $item) {
                        if (!self::is_menu_visible($item['slug'])) {
                            continue;
                        }
                        $is_active = ($tab === $item['slug']) ? 'active' : '';
                        ?>
                        <a href="<?php echo esc_url(self::tab_url($item['slug'])); ?>" class="<?php echo esc_attr($is_active); ?>"><span class="dashicons <?php echo esc_attr($item['icon']); ?>"></span> <?php echo esc_html($item['label']); ?></a>
                        <?php
                    }
                    ?>
                    <?php echo $this->build_notification_bell($user_id); ?>
                    <a href="<?php echo esc_url(wp_logout_url(home_url('/matrix-login'))); ?>" class="matrix-nav-logout"><span class="dashicons dashicons-exit"></span> <?php _e('Logout', 'matrix-mlm'); ?></a>
                </nav>
            </div>
            <div class="matrix-dashboard-content">
                <?php $this->render_tab($tab, $user_id); ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Recovery view for users whose account is currently in the
     * 'inactive' state.
     *
     * Two callers, two outcomes:
     *
     *   1. status === 'inactive' AND subscription is enabled AND
     *      amount > 0 — the typical post-cron lapsed-payment case.
     *      Render a self-contained "Pay Subscription" mini-view
     *      with the wallet balance, the amount due, the PIN field
     *      drop-in, and an inline submit handler that posts
     *      matrix_action=pay_subscription. On success the server
     *      flips status back to 'active' (see
     *      Matrix_MLM_Subscription::manual_pay()) and the page
     *      reload lands on the normal dashboard.
     *
     *   2. Any other path — banned, pending, manually deactivated
     *      with subscription disabled, etc. — fall through to the
     *      legacy "account suspended" alert. Those states require
     *      operator action and intentionally have no user-facing
     *      self-heal.
     *
     * The render_field('subscription') call uses the same
     * should_gate() predicate as the matching require_pin_for_request
     * gate in Matrix_MLM_Core::process_pay_subscription, so the
     * server never demands a PIN the form didn't ask for and vice
     * versa. Users without a PIN configured (or installs where the
     * admin hasn't enabled matrix_mlm_pin_required_for_subscription)
     * see no PIN field — the recovery surface stays usable for
     * pre-PIN-rollout accounts.
     *
     * The button is suppressed when balance < amount and an
     * informational note tells the user how much more they need.
     * This avoids surfacing a button that would just bounce off
     * manual_pay()'s "Insufficient Matrix wallet balance" check
     * and gives the user the actionable next step (earn / top up).
     *
     * @param int $user_id Current user id.
     * @return string Self-contained HTML string (the dashboard
     *                shortcode returns this verbatim, so the
     *                caller's ob_start/ob_get_clean wrapping does
     *                not apply here).
     */
    private function render_inactive_account_view($user_id) {
        $user_id = (int) $user_id;
        global $wpdb;

        $status = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}matrix_user_meta WHERE user_id = %d",
            $user_id
        ));

        $sub_enabled = (int) get_option('matrix_mlm_subscription_enabled', 0);
        $amount      = (float) get_option('matrix_mlm_subscription_amount', 0);

        // Recovery view applies only to subscription-driven
        // inactivity. 'banned' / 'pending' / inactive-with-no-
        // subscription fall through to the legacy alert.
        if ($status !== 'inactive' || $sub_enabled !== 1 || $amount <= 0) {
            return '<div class="matrix-alert matrix-alert-danger">'
                . esc_html__('Your account has been suspended. Please contact support.', 'matrix-mlm')
                . '</div>';
        }

        $wallet   = new Matrix_MLM_Wallet();
        $balance  = (float) $wallet->get_balance($user_id);
        $currency = (string) get_option('matrix_mlm_currency_symbol', '₦');
        $period   = date_i18n('F Y');
        $can_pay  = $balance >= $amount;
        $logout   = wp_logout_url(home_url('/matrix-login'));

        ob_start();
        ?>
        <div class="matrix-dashboard matrix-dashboard-recovery">
            <div class="matrix-dashboard-content">
                <div class="matrix-alert matrix-alert-warning">
                    <h2 style="margin-top:0;"><?php esc_html_e('Your account is currently inactive', 'matrix-mlm'); ?></h2>
                    <p>
                        <?php
                        printf(
                            /* translators: 1: amount, 2: billing period */
                            esc_html__('Your monthly subscription of %1$s for %2$s is unpaid. Pay now from your Matrix wallet to reactivate your account.', 'matrix-mlm'),
                            esc_html($currency . number_format($amount, 2)),
                            esc_html($period)
                        );
                        ?>
                    </p>
                </div>

                <div class="matrix-form-card">
                    <p>
                        <strong><?php esc_html_e('Amount due:', 'matrix-mlm'); ?></strong>
                        <?php echo esc_html($currency . number_format($amount, 2)); ?>
                    </p>
                    <p>
                        <strong><?php esc_html_e('Matrix wallet balance:', 'matrix-mlm'); ?></strong>
                        <?php echo esc_html($currency . number_format($balance, 2)); ?>
                    </p>

                    <?php if (!$can_pay): ?>
                        <div class="matrix-alert matrix-alert-info">
                            <?php
                            printf(
                                /* translators: %s: shortfall amount */
                                esc_html__('You need %s more in your Matrix wallet to pay this month. Earn more commissions or top up your wallet to continue.', 'matrix-mlm'),
                                esc_html($currency . number_format($amount - $balance, 2))
                            );
                            ?>
                        </div>
                    <?php else: ?>
                        <form id="matrix-pay-subscription-form" class="matrix-form">
                            <?php
                            // Transaction PIN field — rendered only
                            // when the admin requires PIN for
                            // path='subscription' AND the current
                            // user has one set. Helper returns ''
                            // for ungated paths or users without a
                            // PIN, so the inactive-recovery surface
                            // stays usable for users who never
                            // enrolled. Server gate at
                            // Matrix_MLM_Core::process_pay_subscription
                            // mirrors the same predicate via
                            // should_gate(), so prompt and
                            // enforcement stay locked together.
                            echo Matrix_MLM_Transaction_Pin::render_field($user_id, 'subscription');
                            ?>
                            <button type="submit" class="matrix-btn matrix-btn-primary matrix-btn-block" id="matrix-pay-subscription-btn">
                                <?php esc_html_e('Pay Subscription', 'matrix-mlm'); ?>
                            </button>
                        </form>
                    <?php endif; ?>

                    <p style="margin-top:16px;text-align:center;">
                        <a href="<?php echo esc_url($logout); ?>"><?php esc_html_e('Log out', 'matrix-mlm'); ?></a>
                    </p>
                </div>
            </div>
        </div>

        <?php if ($can_pay): ?>
        <script>
        // jQuery-footer-race guard. Without this, the inline IIFE
        // throws ReferenceError at parse time on installs where
        // jQuery is deferred to the footer, and the pay-subscription
        // submit handler never binds. That matters here more than
        // anywhere else on the dashboard: this is the recovery
        // surface for inactive-subscription users — if the form
        // can't submit, the user is stranded (their account is
        // locked out of every other tab, and the only way back in
        // is through this one form). Same polling pattern as
        // class-matrix-user-wallet.php's render_scripts_no_wallet
        // and the airtime form in class-matrix-user-billing.php —
        // see that airtime <script> for the full historical context.
        //
        // The matrixMLM-undefined defensive check that was already
        // here pre-empts a separate failure mode (matrix-public.js
        // stripped by an asset optimizer); it is preserved INSIDE
        // the polled callback because that's the only context where
        // matrixMLM is meaningfully checkable. Pre-fix, that check
        // never ran when jQuery itself was undefined because the
        // IIFE threw before reaching it.
        (function() {
            var attempts = 0;
            var maxAttempts = 200; // 200 * 50ms = 10s ceiling

            function whenJQueryReady(cb) {
                if (typeof window.jQuery !== 'undefined' && typeof window.jQuery.fn !== 'undefined') {
                    // Synchronous dispatch — see the matching comment
                    // in class-matrix-user-billing.php's airtime
                    // whenJQueryReady for the full rationale. The
                    // pay-subscription form is the recovery path for
                    // inactive-status users (every other tab is
                    // locked out behind that gate), so a click that
                    // silently no-ops here strands them entirely.
                    cb(window.jQuery);
                    return;
                }
                if (++attempts > maxAttempts) {
                    if (window.console && console.error) {
                        console.error('[Matrix MLM] jQuery not loaded after 10s; pay-subscription handler not bound.');
                    }
                    return;
                }
                setTimeout(function() { whenJQueryReady(cb); }, 50);
            }

            whenJQueryReady(function($) {
            // Defensive guard against caching plugins / asset
            // optimizers that strip matrix-public.js — same idiom
            // used by the wallet and bank-payout surfaces. Without
            // matrixMLM the form has no AJAX endpoint to post to,
            // so disable the button rather than surface a confusing
            // silent failure.
            if (typeof matrixMLM === 'undefined' || !matrixMLM.ajaxUrl) {
                $('#matrix-pay-subscription-btn').prop('disabled', true)
                    .text('<?php echo esc_js(__('Page assets failed to load — please refresh.', 'matrix-mlm')); ?>');
                return;
            }

            // Delegated on document for the same DOM-timing reason
            // documented in class-matrix-user-wallet.php's render_scripts():
            // direct binding races the matched form's DOM arrival on
            // stacks with deferred jQuery / Rocket Loader / WP Rocket /
            // FlyingPress / Astra / GeneratePress / OceanWP, etc., and
            // silently no-op's. Critical here because this form is the
            // ONLY recovery path for users whose subscription has lapsed
            // — every other dashboard tab is locked out behind the
            // inactive-status gate. A failed bind here means the user
            // has to refresh (and may give up first).
            $(document).on('submit', '#matrix-pay-subscription-form', function(e) {
                e.preventDefault();

                var $form = $(this);
                var $btn  = $('#matrix-pay-subscription-btn');
                $btn.prop('disabled', true).text('<?php echo esc_js(__('Processing…', 'matrix-mlm')); ?>');

                $.post(matrixMLM.ajaxUrl, {
                    action: 'matrix_mlm_action',
                    matrix_action: 'pay_subscription',
                    nonce: matrixMLM.nonce,
                    // Always-post-empty contract: the server-side
                    // require_pin_for_request gate decides whether
                    // to enforce, so the JS doesn't branch on the
                    // same predicate. If render_field() emitted
                    // nothing (ungated / no PIN set), .val()
                    // returns undefined and || '' coalesces to ''
                    // — the server treats empty as "PIN gate not
                    // active for this caller" and proceeds.
                    transaction_pin: ($form.find('[name=transaction_pin]').val() || '')
                }, function(res) {
                    if (res && res.success) {
                        alert(res.data && res.data.message
                            ? res.data.message
                            : '<?php echo esc_js(__('Subscription paid successfully.', 'matrix-mlm')); ?>');
                        // Hard reload — manual_pay() flipped status
                        // back to 'active', so the next render of
                        // render_dashboard() will skip this view
                        // entirely and show the full dashboard.
                        (typeof matrixMLMReload === 'function'
                            ? matrixMLMReload
                            : function(){ window.location.reload(); })();
                    } else {
                        var msg = (res && res.data && res.data.message)
                            ? res.data.message
                            : '<?php echo esc_js(__('Payment failed.', 'matrix-mlm')); ?>';
                        alert(msg);
                        $btn.prop('disabled', false)
                            .text('<?php echo esc_js(__('Pay Subscription', 'matrix-mlm')); ?>');
                    }
                }).fail(function() {
                    alert('<?php echo esc_js(__('Network error. Please try again.', 'matrix-mlm')); ?>');
                    $btn.prop('disabled', false)
                        .text('<?php echo esc_js(__('Pay Subscription', 'matrix-mlm')); ?>');
                });
            });
            }); // whenJQueryReady
        })(); // poll-for-jQuery IIFE
        </script>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Build the inline banner that prompts un-enrolled users to set
     * a transaction PIN, when the admin has enabled enforcement on
     * at least one path. Returns the empty string in the steady-
     * state case (user has a PIN, OR no path is gated, OR master
     * toggle off), so users who have already enrolled — and
     * installs that haven't enabled enforcement — see no UI change.
     *
     * Design notes:
     *
     * - The dashboard banner is the discovery surface: a user
     *   logging in for the first time after the admin enables
     *   enforcement gets an immediate "you need to set a PIN"
     *   nudge instead of finding out only when they try to
     *   transact and hit the form-side render_enrolment_callout().
     *
     * - Dismissal is sessionStorage-scoped, not durable. A user
     *   can dismiss to clear the visual real-estate during a
     *   session, but the banner reappears on the next login. The
     *   only way to make it permanent is to actually set a PIN,
     *   which is the desired outcome. A durable per-user dismiss
     *   would let users opt out of the prompt forever and never
     *   discover the gate, which is exactly the failure mode this
     *   banner exists to close.
     *
     * - Self-contained <style>+<script> block (no external CSS or
     *   matrix-public.js dependency) for the same reason the
     *   level-completion toast is self-contained: caching plugins
     *   and asset optimisers strip plugin JS from the page on
     *   some installs, and the banner must work regardless. Pure
     *   DOM APIs, no jQuery, no fetch — sessionStorage is the
     *   only browser API used.
     *
     * - Banner is suppressed when the user is already on the
     *   Security tab (the "Set PIN" CTA the banner points at is
     *   already visible on that page; showing the banner there
     *   would be redundant and the user would have to scroll past
     *   it to reach the actual control).
     *
     * @param int $user_id Current user id.
     * @return string HTML to inline ahead of the dashboard wrapper.
     *                Empty string when no enrolment is required.
     */
    private function build_pin_enrolment_banner($user_id) {
        if (!class_exists('Matrix_MLM_Transaction_Pin')) {
            return '';
        }
        if (!Matrix_MLM_Transaction_Pin::any_path_requires_enrolment((int) $user_id)) {
            return '';
        }

        // Suppress on the Security tab — the "Set PIN" control the
        // banner CTA points at is the entire focus of that page,
        // so the banner above it would be visual noise.
        $current_tab = $this->resolve_active_tab();
        if ($current_tab === 'security') {
            return '';
        }

        $security_url = self::tab_url('security');
        $title        = __('Set a transaction PIN', 'matrix-mlm');
        $body         = __('Your administrator now requires a transaction PIN to authorise wallet transfers, bill payments, and other fund-movement actions. Set a 4 to 6 digit PIN now to keep using these features without interruption.', 'matrix-mlm');
        $cta          = __('Set Transaction PIN', 'matrix-mlm');
        $dismiss      = __('Remind me later', 'matrix-mlm');

        $css = '<style>
            .matrix-pin-enrol-banner {
                display: flex; gap: 16px; align-items: flex-start;
                padding: 16px 20px;
                margin: 0 0 20px 0;
                background: #fff7ed; border: 1px solid #fed7aa;
                border-left: 4px solid #ea580c; border-radius: 8px;
                color: #7c2d12;
            }
            .matrix-pin-enrol-banner-icon {
                flex: 0 0 36px; width: 36px; height: 36px; border-radius: 50%;
                display: flex; align-items: center; justify-content: center;
                background: #fed7aa; color: #9a3412;
            }
            .matrix-pin-enrol-banner-icon .dashicons { font-size: 20px; width: 20px; height: 20px; }
            .matrix-pin-enrol-banner-body { flex: 1 1 auto; min-width: 0; }
            .matrix-pin-enrol-banner-title { font-weight: 700; font-size: 15px; color: #7c2d12; margin-bottom: 4px; }
            .matrix-pin-enrol-banner-msg { font-size: 13.5px; color: #7c2d12; line-height: 1.5; margin-bottom: 10px; }
            .matrix-pin-enrol-banner-actions { display: flex; gap: 10px; flex-wrap: wrap; }
            .matrix-pin-enrol-banner-cta {
                display: inline-block; padding: 8px 16px; border-radius: 6px;
                background: #ea580c; color: #fff; text-decoration: none;
                font-weight: 600; font-size: 13.5px;
            }
            .matrix-pin-enrol-banner-cta:hover { background: #c2410c; color: #fff; }
            .matrix-pin-enrol-banner-dismiss {
                background: transparent; border: 0; padding: 8px 12px;
                color: #9a3412; cursor: pointer; font-size: 13.5px;
                text-decoration: underline;
            }
            .matrix-pin-enrol-banner-dismiss:hover { color: #7c2d12; }
        </style>';

        $html = '<div class="matrix-pin-enrol-banner" id="matrix-pin-enrol-banner" role="alert">'
            . '<div class="matrix-pin-enrol-banner-icon" aria-hidden="true">'
            . '<span class="dashicons dashicons-shield-alt"></span>'
            . '</div>'
            . '<div class="matrix-pin-enrol-banner-body">'
            . '<div class="matrix-pin-enrol-banner-title">' . esc_html($title) . '</div>'
            . '<div class="matrix-pin-enrol-banner-msg">' . esc_html($body) . '</div>'
            . '<div class="matrix-pin-enrol-banner-actions">'
            . '<a href="' . esc_url($security_url) . '" class="matrix-pin-enrol-banner-cta">'
            . esc_html($cta)
            . '</a>'
            . '<button type="button" class="matrix-pin-enrol-banner-dismiss" id="matrix-pin-enrol-banner-dismiss">'
            . esc_html($dismiss)
            . '</button>'
            . '</div>'
            . '</div>'
            . '</div>';

        // Pure-DOM JS, no jQuery. sessionStorage-scoped dismiss so
        // the banner reappears on a fresh session — see method
        // docblock for the rationale on rejecting durable
        // per-user dismiss.
        $js = '<script>(function(){
            try {
                var KEY = "matrix_pin_enrol_banner_dismissed";
                var banner = document.getElementById("matrix-pin-enrol-banner");
                if (!banner) return;
                if (window.sessionStorage && sessionStorage.getItem(KEY) === "1") {
                    banner.style.display = "none";
                    return;
                }
                var btn = document.getElementById("matrix-pin-enrol-banner-dismiss");
                if (btn) {
                    btn.addEventListener("click", function(){
                        try { if (window.sessionStorage) sessionStorage.setItem(KEY, "1"); } catch (e) {}
                        if (banner && banner.parentNode) banner.parentNode.removeChild(banner);
                    });
                }
            } catch (e) { /* never let a banner break the dashboard */ }
        })();</script>';

        return $css . $html . $js;
    }

    /**
     * Build the inline toast stack rendered at the top of every
     * dashboard page when the current user has level-completion
     * milestones they haven't seen yet. Returns a string (the caller
     * inlines it ahead of the dashboard wrapper) and is the only
     * place toast_seen_at gets stamped — running this once per
     * pageload means a milestone surfaces exactly once per user.
     *
     * Why a fully self-contained <style>+<script> block instead of
     * reusing matrix-public.js's showNotification()? Two reasons:
     *
     *   1. showNotification() is declared inside an IIFE in
     *      public/js/matrix-public.js, so it is *not* on window and
     *      can't be called from inline markup without exposing it
     *      first — and changing matrix-public.js is out of scope here.
     *
     *   2. Several existing dashboard surfaces (Bank Payout, Wallet,
     *      CUG) already carry long defensive comments about caching
     *      plugins / asset optimizers that strip matrix-public.js
     *      from the page; they fall back to inline handlers for the
     *      same reason. The level-completion toast must be visible
     *      even on those installs, so it ships with its own CSS,
     *      its own JS, and zero external dependencies (jQuery
     *      included — pure DOM APIs).
     *
     * Auto-dismiss after 8s gives the user enough time to read a
     * line of copy without the toast becoming sticky page furniture.
     * A close (×) button stays available for users who want to
     * dismiss it sooner. Either way, the row is marked seen *now*
     * (server-side) — we don't wait for the user to acknowledge,
     * because if they navigate away before doing so we still don't
     * want to re-toast on the next load. The email is the durable
     * record; the toast is just the nudge.
     *
     * @param int $user_id Current user id.
     * @return string HTML to inline before the dashboard wrapper.
     *                Empty string when there are no unread milestones.
     */
    private function build_level_completion_toasts($user_id) {
        if (!class_exists('Matrix_MLM_Plan_Engine')) {
            return '';
        }
        $rows = Matrix_MLM_Plan_Engine::get_unread_level_completions($user_id);
        if (empty($rows)) {
            return '';
        }

        $items = [];
        $ids   = [];
        foreach ($rows as $row) {
            $ids[]    = (int) $row->id;
            $level    = (int) $row->level;
            $width    = (int) ($row->plan_width ?? 0);
            $name     = (string) ($row->plan_name ?? '');
            $positions = ($width > 0 && $level > 0) ? (int) pow($width, $level) : 0;

            // No "Matrix Master" / final-level branch on the toast,
            // for the same reason as the email path: the engine's
            // existing matrix_completion bonus already announces the
            // whole-matrix event with its own commission notification
            // and wallet credit. Per-level toasts stay scoped to a
            // single level so the two surfaces don't fight over the
            // user's attention when the deepest level happens to also
            // close out the matrix.
            $title = sprintf(
                /* translators: %d: level number */
                __('Level %d Completed', 'matrix-mlm'),
                $level
            );
            $body = sprintf(
                /* translators: 1: position count, 2: plan name */
                __('All %1$s positions at this depth of your %2$s matrix are filled.', 'matrix-mlm'),
                number_format($positions),
                $name
            );

            $items[] = sprintf(
                '<div class="matrix-level-toast" role="status" aria-live="polite">'
                . '<button type="button" class="matrix-level-toast-close" aria-label="%s">&times;</button>'
                . '<div class="matrix-level-toast-icon" aria-hidden="true"><span class="dashicons dashicons-awards"></span></div>'
                . '<div class="matrix-level-toast-body">'
                . '<div class="matrix-level-toast-title">%s</div>'
                . '<div class="matrix-level-toast-msg">%s</div>'
                . '</div>'
                . '</div>',
                esc_attr__('Dismiss', 'matrix-mlm'),
                esc_html($title),
                esc_html($body)
            );
        }

        // Mark seen *before* returning HTML — see method docblock for
        // why we don't wait for the user to acknowledge.
        Matrix_MLM_Plan_Engine::mark_level_completions_seen($ids);

        $css = '<style>
            .matrix-level-toast-stack {
                position: fixed; top: 24px; right: 24px;
                z-index: 99999; display: flex; flex-direction: column;
                gap: 10px; max-width: calc(100vw - 48px); width: 360px;
            }
            .matrix-level-toast {
                position: relative; display: flex; gap: 12px;
                align-items: flex-start; padding: 14px 36px 14px 14px;
                background: #fff; border: 1px solid #e5e7eb; border-left: 4px solid #8b5cf6;
                border-radius: 10px; box-shadow: 0 18px 38px -12px rgba(0,0,0,0.25);
                opacity: 0; transform: translateX(20px);
                transition: opacity .25s ease, transform .25s ease;
            }
            .matrix-level-toast.is-visible { opacity: 1; transform: translateX(0); }
            .matrix-level-toast-icon {
                flex: 0 0 36px; width: 36px; height: 36px; border-radius: 50%;
                display: flex; align-items: center; justify-content: center;
                background: #ddd6fe; color: #5b21b6;
            }
            .matrix-level-toast-icon .dashicons { font-size: 20px; width: 20px; height: 20px; }
            .matrix-level-toast-body { flex: 1 1 auto; min-width: 0; }
            .matrix-level-toast-title { font-weight: 700; font-size: 14px; color: #111827; line-height: 1.2; margin-bottom: 4px; }
            .matrix-level-toast-msg { font-size: 13px; color: #4b5563; line-height: 1.4; }
            .matrix-level-toast-close {
                position: absolute; top: 8px; right: 8px; width: 24px; height: 24px;
                display: flex; align-items: center; justify-content: center;
                border: 0; background: transparent; cursor: pointer;
                color: #9ca3af; font-size: 18px; line-height: 1; padding: 0;
            }
            .matrix-level-toast-close:hover { color: #4b5563; }
        </style>';

        // Pure-DOM JS, no jQuery. Animates each toast in on load and
        // auto-dismisses after 8s; the close button is a simple
        // remove(). All listeners are scoped to this fragment, so
        // there is no risk of leaking handlers into the rest of the
        // dashboard.
        $js = '<script>(function(){
            try {
                var stack = document.getElementById("matrix-level-toast-stack");
                if (!stack) return;
                var toasts = stack.querySelectorAll(".matrix-level-toast");
                var i = 0;
                toasts.forEach(function(t){
                    setTimeout(function(){ t.classList.add("is-visible"); }, 80 + (i * 120));
                    setTimeout(function(){ dismiss(t); }, 8000 + (i * 120));
                    var btn = t.querySelector(".matrix-level-toast-close");
                    if (btn) { btn.addEventListener("click", function(){ dismiss(t); }); }
                    i++;
                });
                function dismiss(t){
                    t.classList.remove("is-visible");
                    setTimeout(function(){ if (t && t.parentNode) t.parentNode.removeChild(t); }, 300);
                }
            } catch (e) { /* never let a toast break the dashboard */ }
        })();</script>';

        return $css
            . '<div class="matrix-level-toast-stack" id="matrix-level-toast-stack">'
            . implode('', $items)
            . '</div>'
            . $js;
    }

    private function render_tab($tab, $user_id) {
        // Defense-in-depth for the admin's per-menu visibility toggle:
        // a user typing /matrix-dashboard/deposits/ directly (or hitting
        // a stale bookmark, or a page-cached link from before the menu
        // was hidden) bypasses the sidebar render-time filter. Re-check
        // visibility here and fall through to overview for hidden slugs.
        // Locked slugs (overview / profile / security) always pass.
        if (!self::is_menu_visible($tab)) {
            $tab = 'overview';
        }

        switch ($tab) {
            case 'overview':
                $this->render_overview($user_id);
                break;
            case 'deposits':
                $deposits_handler = new Matrix_MLM_User_Deposits();
                // Check if returning from payment gateway
                $verify_result = $deposits_handler->maybe_verify_payment();
                if ($verify_result) {
                    $alert_class = $verify_result['status'] === 'success' ? 'success' : ($verify_result['status'] === 'error' ? 'danger' : 'info');
                    echo '<div class="matrix-alert matrix-alert-' . $alert_class . '">' . esc_html($verify_result['message']) . '</div>';
                }
                $deposits_handler->render_deposit_form($user_id);
                break;
            case 'deposit-history':
                (new Matrix_MLM_User_Deposits())->render_history($user_id);
                break;
            // 'withdraw' / 'withdraw-history' cases removed — see the
            // comment on $valid_tabs. The "Transfer to Bank" pane on
            // the Wallet tab is the user-facing replacement.
            //
            // 'transactions' case likewise removed: the ledger now
            // lives in the "Transaction History" pane on the Wallet
            // tab, which is the single canonical home for all wallet
            // activity (balances, actions, and history together).
            case 'referrals':
                (new Matrix_MLM_User_Referrals())->render($user_id);
                break;
            case 'genealogy':
                (new Matrix_MLM_User_Genealogy())->render($user_id);
                break;
            case 'commissions':
                $this->render_commissions($user_id);
                break;
            case 'plans':
                $this->render_plans($user_id);
                break;
            case 'epin':
                (new Matrix_MLM_User_Epin())->render($user_id);
                break;
            case 'wallet':
                // Consolidated wallet page — replaces the old separate
                // 'transfer', 'bank-payout', and 'virtual-wallet' tabs.
                // The legacy slugs are no longer in $valid_tabs so any
                // bookmark or email link to them now falls through to
                // overview; the underlying classes are kept on disk and
                // continue to back this consolidated page (Bank_Payout
                // is embedded directly, Virtual_Wallet's create-form
                // helper is invoked for first-time onboarding).
                (new Matrix_MLM_User_Wallet())->render($user_id);
                break;
            case 'card':
                (new Matrix_MLM_User_Card())->render($user_id);
                break;
            case 'billing':
                (new Matrix_MLM_User_Billing())->render($user_id);
                break;
            case 'benefits':
                (new Matrix_MLM_User_Benefits())->render($user_id);
                break;
            case 'tickets':
                (new Matrix_MLM_User_Tickets())->render($user_id);
                break;
            case 'profile':
                (new Matrix_MLM_User_Profile())->render($user_id);
                break;
            case 'security':
                $this->render_security($user_id);
                break;
            case 'notifications':
                $this->render_notifications_tab($user_id);
                break;
            default:
                $this->render_overview($user_id);
        }
    }

    private function render_overview($user_id) {
        $wallet = new Matrix_MLM_Wallet();
        $balance = $wallet->get_balance($user_id);
        $total_earnings = $wallet->get_total_earnings($user_id);
        $total_withdrawals = $wallet->get_total_withdrawals($user_id);
        $commissions = Matrix_MLM_Commission::get_summary($user_id);
        $referral_count = Matrix_MLM_User::get_referral_count($user_id);
        $referral_link = Matrix_MLM_User::get_referral_link($user_id);
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $recent_transactions = $wallet->get_transactions($user_id, 5);
        ?>
        <div class="matrix-overview">
            <h2><?php _e('Dashboard Overview', 'matrix-mlm'); ?></h2>

            <div class="matrix-stats-grid">
                <div class="matrix-stat-card primary">
                    <div class="stat-value"><?php echo $currency . number_format($balance, 2); ?></div>
                    <div class="stat-label"><?php _e('Current Balance', 'matrix-mlm'); ?></div>
                </div>
                <div class="matrix-stat-card success">
                    <div class="stat-value"><?php echo $currency . number_format($total_earnings, 2); ?></div>
                    <div class="stat-label"><?php _e('Total Earnings', 'matrix-mlm'); ?></div>
                </div>
                <div class="matrix-stat-card warning">
                    <div class="stat-value"><?php echo $currency . number_format($total_withdrawals, 2); ?></div>
                    <div class="stat-label"><?php _e('Total Withdrawn', 'matrix-mlm'); ?></div>
                </div>
                <div class="matrix-stat-card info">
                    <div class="stat-value"><?php echo $referral_count; ?></div>
                    <div class="stat-label"><?php _e('Total Referrals', 'matrix-mlm'); ?></div>
                </div>
                <div class="matrix-stat-card purple">
                    <div class="stat-value"><?php echo $currency . number_format($commissions['referral'], 2); ?></div>
                    <div class="stat-label"><?php _e('Referral Commission', 'matrix-mlm'); ?></div>
                </div>
                <div class="matrix-stat-card danger">
                    <div class="stat-value"><?php echo $currency . number_format($commissions['level'], 2); ?></div>
                    <div class="stat-label"><?php _e('Level Commission', 'matrix-mlm'); ?></div>
                </div>
            </div>

            <div class="matrix-referral-box">
                <h3><?php _e('Your Referral Link', 'matrix-mlm'); ?></h3>
                <div class="matrix-referral-link">
                    <input type="text" id="referral-link" value="<?php echo esc_url($referral_link); ?>" readonly>
                    <button onclick="navigator.clipboard.writeText(document.getElementById('referral-link').value); this.textContent='Copied!';" class="matrix-btn matrix-btn-primary"><?php _e('Copy', 'matrix-mlm'); ?></button>
                </div>
            </div>

            <div class="matrix-recent-transactions">
                <h3><?php _e('Recent Transactions', 'matrix-mlm'); ?></h3>
                <table class="matrix-table">
                    <thead><tr><th><?php _e('Date', 'matrix-mlm'); ?></th><th><?php _e('Type', 'matrix-mlm'); ?></th><th><?php _e('Amount', 'matrix-mlm'); ?></th><th><?php _e('Description', 'matrix-mlm'); ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($recent_transactions as $tx): ?>
                        <tr>
                            <td><?php echo date('M d, Y', strtotime($tx->created_at)); ?></td>
                            <td><span class="matrix-badge matrix-badge-<?php echo $tx->type; ?>"><?php echo ucfirst($tx->type); ?></span></td>
                            <td class="<?php echo $tx->type === 'credit' ? 'text-success' : 'text-danger'; ?>"><?php echo ($tx->type === 'credit' ? '+' : '-') . $currency . number_format($tx->amount, 2); ?></td>
                            <td><?php echo esc_html($tx->description); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    private function render_commissions($user_id) {
        $commissions = Matrix_MLM_Commission::get_user_commissions($user_id, null, 50);
        $summary = Matrix_MLM_Commission::get_summary($user_id);
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        ?>
        <h2><?php _e('Commission History', 'matrix-mlm'); ?></h2>
        <div class="matrix-stats-grid">
            <div class="matrix-stat-card success"><div class="stat-value"><?php echo $currency . number_format($summary['total'], 2); ?></div><div class="stat-label"><?php _e('Total', 'matrix-mlm'); ?></div></div>
            <div class="matrix-stat-card info"><div class="stat-value"><?php echo $currency . number_format($summary['referral'], 2); ?></div><div class="stat-label"><?php _e('Referral', 'matrix-mlm'); ?></div></div>
            <div class="matrix-stat-card warning"><div class="stat-value"><?php echo $currency . number_format($summary['level'], 2); ?></div><div class="stat-label"><?php _e('Level', 'matrix-mlm'); ?></div></div>
            <div class="matrix-stat-card purple"><div class="stat-value"><?php echo $currency . number_format($summary['matrix_completion'], 2); ?></div><div class="stat-label"><?php _e('Completion Bonus', 'matrix-mlm'); ?></div></div>
        </div>
        <table class="matrix-table">
            <thead><tr><th><?php _e('Date', 'matrix-mlm'); ?></th><th><?php _e('Type', 'matrix-mlm'); ?></th><th><?php _e('From', 'matrix-mlm'); ?></th><th><?php _e('Plan', 'matrix-mlm'); ?></th><th><?php _e('Level', 'matrix-mlm'); ?></th><th><?php _e('Amount', 'matrix-mlm'); ?></th></tr></thead>
            <tbody>
                <?php foreach ($commissions as $c): ?>
                <tr>
                    <td><?php echo date('M d, Y', strtotime($c->created_at)); ?></td>
                    <td><span class="matrix-badge"><?php echo ucfirst(str_replace('_', ' ', $c->type)); ?></span></td>
                    <td><?php echo esc_html($c->from_username ?? '-'); ?></td>
                    <td><?php echo esc_html($c->plan_name ?? '-'); ?></td>
                    <td><?php echo $c->level ?: '-'; ?></td>
                    <td class="text-success">+<?php echo $currency . number_format($c->amount, 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_plans($user_id) {
        $active_plans = Matrix_MLM_User::get_active_plans($user_id);
        $plan_engine = new Matrix_MLM_Plan_Engine();
        $all_plans = $plan_engine->get_plans('active');
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        ?>
        <h2><?php _e('My Active Plans', 'matrix-mlm'); ?></h2>
        <?php if (empty($active_plans)): ?>
        <div class="matrix-alert matrix-alert-info"><?php _e('You have not joined any plan yet.', 'matrix-mlm'); ?></div>
        <?php else: ?>
        <div class="matrix-plans-grid">
            <?php foreach ($active_plans as $plan): ?>
            <div class="matrix-plan-card active">
                <h3><?php echo esc_html($plan->name); ?></h3>
                <div class="plan-matrix"><?php echo $plan->width . ' x ' . $plan->depth; ?></div>
                <div class="plan-details">
                    <p><?php _e('Downline:', 'matrix-mlm'); ?> <strong><?php echo $plan->total_downline; ?></strong></p>
                    <p><?php _e('Joined:', 'matrix-mlm'); ?> <?php echo date('M d, Y', strtotime($plan->joined_at)); ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <h2><?php _e('Available Plans', 'matrix-mlm'); ?></h2>
        <div class="matrix-plans-grid">
            <?php foreach ($all_plans as $plan): ?>
            <div class="matrix-plan-card">
                <h3><?php echo esc_html($plan->name); ?></h3>
                <div class="plan-price"><?php echo $currency . number_format($plan->price, 2); ?></div>
                <div class="plan-matrix"><?php echo $plan->width . ' x ' . $plan->depth; ?> Matrix</div>
                <ul class="plan-features">
                    <li><?php echo sprintf(__('Referral: %s', 'matrix-mlm'), $currency . number_format($plan->referral_commission, 2)); ?></li>
                    <li><?php echo sprintf(__('Completion Bonus: %s', 'matrix-mlm'), $currency . number_format($plan->matrix_completion_bonus, 2)); ?></li>
                </ul>
                <button class="matrix-btn matrix-btn-primary" onclick="matrixJoinPlan(<?php echo $plan->id; ?>)"><?php _e('Join Plan', 'matrix-mlm'); ?></button>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function render_security($user_id) {
        $two_factor = new Matrix_MLM_Two_Factor();
        $is_enabled = $two_factor->is_enabled($user_id);
        $remaining_codes = $is_enabled ? (int) $two_factor->remaining_recovery_codes($user_id) : 0;
        ?>
        <h2><?php _e('Two-Factor Authentication', 'matrix-mlm'); ?></h2>
        <div class="matrix-security-section">
            <p><?php _e('Two-factor authentication adds an extra layer of security to your account.', 'matrix-mlm'); ?></p>
            <div class="matrix-2fa-status">
                <strong><?php _e('Status:', 'matrix-mlm'); ?></strong>
                <?php if ($is_enabled): ?>
                <span class="matrix-badge matrix-badge-active"><?php _e('Enabled', 'matrix-mlm'); ?></span>
                <button class="matrix-btn matrix-btn-danger" onclick="matrixToggle2FAForm('disable')"><?php _e('Disable 2FA', 'matrix-mlm'); ?></button>
                <button class="matrix-btn matrix-btn-secondary" onclick="matrixToggle2FAForm('regen')"><?php _e('Regenerate recovery codes', 'matrix-mlm'); ?></button>
                <?php else: ?>
                <span class="matrix-badge matrix-badge-inactive"><?php _e('Disabled', 'matrix-mlm'); ?></span>
                <button class="matrix-btn matrix-btn-primary" onclick="matrixToggle2FAForm('enable')"><?php _e('Enable 2FA', 'matrix-mlm'); ?></button>
                <?php endif; ?>
            </div>

            <?php if ($is_enabled): ?>
                <p class="matrix-2fa-recovery-status">
                    <?php
                    /* translators: %d: number of unused recovery codes remaining on the account. */
                    printf(
                        _n(
                            'You have %d recovery code remaining.',
                            'You have %d recovery codes remaining.',
                            $remaining_codes,
                            'matrix-mlm'
                        ),
                        $remaining_codes
                    );
                    ?>
                    <?php if ($remaining_codes <= 2): ?>
                        <strong><?php _e('Generate a new batch soon to avoid being locked out if you lose your authenticator.', 'matrix-mlm'); ?></strong>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <!--
                Inline reauth form for enable / disable / regenerate.
                Only one of the three is rendered at a time;
                matrixToggle2FAForm() shows the right inputs and wires
                the submit button to the right AJAX action. The
                password is type=password so masking + autofill behave
                normally, and the form is rendered server-side rather
                than built in JS so the password field gets the same
                browser security treatment (autofill flags,
                enterprise password-fill rules, etc.) as a regular
                login form.

                The form intentionally does NOT carry the WP nonce —
                matrixAjax() injects matrixMLM.nonce on every request,
                so adding it here would just be a duplicate field that
                drifts out of sync if the nonce is ever rotated
                mid-session.
            -->
            <div id="matrix-2fa-form" style="display: none;" class="matrix-2fa-form">
                <h3 id="matrix-2fa-form-title"></h3>
                <p id="matrix-2fa-form-help" class="matrix-2fa-help"></p>

                <div class="matrix-form-row">
                    <label for="matrix-2fa-password"><?php _e('Current password', 'matrix-mlm'); ?></label>
                    <input type="password" id="matrix-2fa-password" autocomplete="current-password">
                </div>

                <div class="matrix-form-row" id="matrix-2fa-code-row" style="display: none;">
                    <label for="matrix-2fa-code"><?php _e('Authenticator code or recovery code', 'matrix-mlm'); ?></label>
                    <input type="text" id="matrix-2fa-code" autocomplete="one-time-code" inputmode="text" maxlength="20">
                </div>

                <div class="matrix-form-actions">
                    <button class="matrix-btn matrix-btn-primary" id="matrix-2fa-submit"></button>
                    <button class="matrix-btn matrix-btn-secondary" type="button" onclick="matrixCancel2FAForm()"><?php _e('Cancel', 'matrix-mlm'); ?></button>
                </div>
            </div>

            <!--
                Post-enrolment surface. Holds the QR + secret AND the
                one-time recovery codes. Hidden until matrixEnable2FA()
                or matrixRegenerateRecoveryCodes() succeeds.
            -->
            <div id="matrix-2fa-setup" style="display: none;">
                <div id="matrix-2fa-setup-qr-block">
                    <p><?php _e('Scan this QR code with your authenticator app (Google Authenticator, Authy, 1Password, etc.):', 'matrix-mlm'); ?></p>
                    <img id="matrix-2fa-qr" src="" alt="QR Code">
                    <p><?php _e('Secret Key:', 'matrix-mlm'); ?> <code id="matrix-2fa-secret"></code></p>
                </div>

                <div id="matrix-2fa-recovery-block" class="matrix-2fa-recovery-block">
                    <h3><?php _e('Recovery codes', 'matrix-mlm'); ?></h3>
                    <p>
                        <strong><?php _e('Save these codes somewhere safe.', 'matrix-mlm'); ?></strong>
                        <?php _e('Each code can be used once if you lose access to your authenticator. They will not be shown again.', 'matrix-mlm'); ?>
                    </p>
                    <ul id="matrix-2fa-recovery-codes" class="matrix-2fa-codes"></ul>
                    <button class="matrix-btn matrix-btn-secondary" type="button" onclick="matrixCopyRecoveryCodes()"><?php _e('Copy to clipboard', 'matrix-mlm'); ?></button>
                    <button class="matrix-btn matrix-btn-primary" type="button" onclick="matrixDismissRecoveryCodes()"><?php _e('I have saved them', 'matrix-mlm'); ?></button>
                </div>
            </div>
        </div>

        <?php $this->render_security_transaction_pin($user_id); ?>
        <?php
    }

    /**
     * Transaction PIN sub-section on the user dashboard's Security
     * tab. Rendered as a sibling of the 2FA block so the two
     * primitives sit side-by-side; visually identical wrapper
     * (.matrix-security-section) so the page styling stays
     * consistent.
     *
     * Hidden entirely when the admin has turned the master
     * "Transaction PIN" toggle off on Settings → Security — there's
     * no value showing a feature the admin has disabled, and a
     * defence-in-depth probe in the AJAX handlers refuses set/change
     * operations on a master-disabled site so a stale dashboard
     * page can't sneak past this UI gate either.
     *
     * The form is the same inline reauth widget the 2FA section
     * uses (server-rendered, password-autofill-friendly, JS-driven
     * mode toggling). Three modes:
     *   - 'set'     — first-time set: password + new + confirm
     *   - 'change'  — rotate: password + current + new + confirm
     *   - 'disable' — clear: password + current
     *
     * matrixTogglePinForm() in matrix-public.js shows the right
     * input rows for each mode and dispatches the right AJAX action.
     */
    private function render_security_transaction_pin($user_id) {
        if (!Matrix_MLM_Transaction_Pin::is_master_enabled()) {
            return;
        }
        $is_set      = Matrix_MLM_Transaction_Pin::is_set($user_id);
        $is_locked   = Matrix_MLM_Transaction_Pin::is_locked($user_id);
        $set_at      = Matrix_MLM_Transaction_Pin::set_at($user_id);
        $last_used   = Matrix_MLM_Transaction_Pin::last_used_at($user_id);
        $lockout     = Matrix_MLM_Transaction_Pin::lockout_info($user_id);
        ?>
        <h2><?php _e('Transaction PIN', 'matrix-mlm'); ?></h2>
        <div class="matrix-security-section">
            <p>
                <?php
                printf(
                    /* translators: 1: minimum digits, 2: maximum digits */
                    esc_html__('A %1$d-to-%2$d-digit numeric PIN that authorises sensitive fund-movement actions (withdrawals, transfers, bill payments). Stored as a one-way hash &mdash; only you know the PIN itself.', 'matrix-mlm'),
                    Matrix_MLM_Transaction_Pin::MIN_LEN,
                    Matrix_MLM_Transaction_Pin::MAX_LEN
                );
                ?>
            </p>

            <div class="matrix-2fa-status">
                <strong><?php _e('Status:', 'matrix-mlm'); ?></strong>
                <?php if ($is_locked): ?>
                    <span class="matrix-badge matrix-badge-danger"><?php _e('Locked', 'matrix-mlm'); ?></span>
                    <button class="matrix-btn matrix-btn-primary" onclick="matrixTogglePinForm('forgot')"><?php _e('Forgot PIN', 'matrix-mlm'); ?></button>
                <?php elseif ($is_set): ?>
                    <span class="matrix-badge matrix-badge-active"><?php _e('Set', 'matrix-mlm'); ?></span>
                    <button class="matrix-btn matrix-btn-secondary" onclick="matrixTogglePinForm('change')"><?php _e('Change PIN', 'matrix-mlm'); ?></button>
                    <button class="matrix-btn matrix-btn-secondary" onclick="matrixTogglePinForm('forgot')"><?php _e('Forgot PIN', 'matrix-mlm'); ?></button>
                    <button class="matrix-btn matrix-btn-danger" onclick="matrixTogglePinForm('disable')"><?php _e('Disable PIN', 'matrix-mlm'); ?></button>
                <?php else: ?>
                    <span class="matrix-badge matrix-badge-inactive"><?php _e('Not set', 'matrix-mlm'); ?></span>
                    <button class="matrix-btn matrix-btn-primary" onclick="matrixTogglePinForm('set')"><?php _e('Set PIN', 'matrix-mlm'); ?></button>
                <?php endif; ?>
            </div>

            <?php if ($is_locked && !empty($lockout['unlock_at'])): ?>
                <p class="description" style="margin-top:8px; color:#b32d2e;">
                    <?php
                    printf(
                        /* translators: %s: human time at which the lock auto-expires */
                        esc_html__('PIN locked after too many wrong attempts. Auto-unlocks at %s, or use Forgot PIN to reset it now.', 'matrix-mlm'),
                        esc_html(date_i18n('Y-m-d H:i', $lockout['unlock_at']))
                    );
                    ?>
                </p>
            <?php endif; ?>

            <?php if ($is_set && !$is_locked): ?>
                <p class="description" style="margin-top:8px;">
                    <?php if ($set_at): ?>
                        <?php
                        printf(
                            /* translators: %s: date the PIN was last set */
                            esc_html__('Set on %s.', 'matrix-mlm'),
                            esc_html(date_i18n('Y-m-d H:i', strtotime($set_at)))
                        );
                        ?>
                    <?php endif; ?>
                    <?php if ($last_used): ?>
                        <?php
                        printf(
                            /* translators: %s: human time of the last successful PIN verification */
                            esc_html__('Last used %s.', 'matrix-mlm'),
                            esc_html(human_time_diff(strtotime($last_used), current_time('timestamp')) . ' ' . __('ago', 'matrix-mlm'))
                        );
                        ?>
                    <?php else: ?>
                        <?php esc_html_e('Not yet used to authorise a transaction.', 'matrix-mlm'); ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php if (!$is_set && !$is_locked): ?>
                <p class="description" style="margin-top:8px;">
                    <?php _e('Until you set a PIN, fund-movement actions configured to require one will fall through ungated. The admin may still require a PIN for some paths &mdash; setting one now means a single configuration change away from a UI prompt won\'t lock you out mid-transaction.', 'matrix-mlm'); ?>
                </p>
            <?php endif; ?>

            <!--
                Inline reauth form. Same shape and rationale as the
                2FA form above — server-rendered so the password
                field gets the regular browser autofill / enterprise
                password-fill treatment, no nonce field (matrixAjax
                injects matrixMLM.nonce on every request).

                The PIN inputs are type=password (masking) with
                inputmode=numeric (mobile numeric keypad),
                pattern=[0-9]{4,6} (HTML5 client-side hint, server
                normaliser is the security gate), maxlength=6
                (hard cap so a paste can't accidentally submit a
                100-digit string the server then has to bcrypt).
                autocomplete="off" because PIN is not a password
                managers should ever propose to autofill.
            -->
            <div id="matrix-pin-form" style="display: none;" class="matrix-2fa-form">
                <h3 id="matrix-pin-form-title"></h3>
                <p id="matrix-pin-form-help" class="matrix-2fa-help"></p>

                <div class="matrix-form-row">
                    <label for="matrix-pin-password"><?php _e('Current password', 'matrix-mlm'); ?></label>
                    <input type="password" id="matrix-pin-password" autocomplete="current-password">
                </div>

                <div class="matrix-form-row" id="matrix-pin-current-row" style="display: none;">
                    <label for="matrix-pin-current"><?php _e('Current PIN', 'matrix-mlm'); ?></label>
                    <input type="password" id="matrix-pin-current" inputmode="numeric" pattern="[0-9]{4,6}" maxlength="6" autocomplete="off">
                </div>

                <div class="matrix-form-row" id="matrix-pin-new-row" style="display: none;">
                    <label for="matrix-pin-new"><?php _e('New PIN', 'matrix-mlm'); ?></label>
                    <input type="password" id="matrix-pin-new" inputmode="numeric" pattern="[0-9]{4,6}" maxlength="6" autocomplete="off">
                </div>

                <div class="matrix-form-row" id="matrix-pin-confirm-row" style="display: none;">
                    <label for="matrix-pin-confirm"><?php _e('Confirm new PIN', 'matrix-mlm'); ?></label>
                    <input type="password" id="matrix-pin-confirm" inputmode="numeric" pattern="[0-9]{4,6}" maxlength="6" autocomplete="off">
                </div>

                <div class="matrix-form-actions">
                    <button class="matrix-btn matrix-btn-primary" id="matrix-pin-submit"></button>
                    <button class="matrix-btn matrix-btn-secondary" type="button" onclick="matrixCancelPinForm()"><?php _e('Cancel', 'matrix-mlm'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Build the notification bell + dropdown shell rendered in the
     * sidebar, just below the user info block. Self-contained
     * markup; the live behaviour (open/close, fetch, mark-read,
     * polling) is bound by the external `matrix-mlm-notifications`
     * JS file. No inline scripts on this file — strict CSP
     * compatible.
     *
     * Server-side, we render the unread count once (so the badge
     * appears immediately without waiting for the first poll) and
     * pre-render up to 20 of the most recent notifications inside
     * the dropdown so the panel content is visible the moment the
     * user clicks the bell, even on a slow first AJAX round-trip.
     *
     * The dropdown is hidden by default (display:none) and toggled
     * by a click handler on the bell button. We do not pre-fetch a
     * fresh list on render — the inline copy is good enough to
     * unblock first interaction and the polling cycle in
     * matrix-mlm-notifications.js refreshes both the badge and the
     * list on a 60-second cadence.
     *
     * @param int $user_id Current user.
     * @return string HTML to inline in the sidebar.
     */
    private function build_notification_bell($user_id) {
        if (!class_exists('Matrix_MLM_In_App_Notifications')) {
            return '';
        }
        $unread = Matrix_MLM_In_App_Notifications::unread_count($user_id);
        $rows   = Matrix_MLM_In_App_Notifications::get_for_user($user_id, 20);
        $see_all_url = self::tab_url('notifications');

        ob_start();
        ?>
        <div class="matrix-notif-bell-wrapper" data-matrix-notif-bell>
            <button type="button"
                    class="matrix-notif-bell-btn"
                    aria-label="<?php esc_attr_e('Notifications', 'matrix-mlm'); ?>"
                    aria-expanded="false"
                    aria-haspopup="true"
                    data-matrix-notif-trigger>
                <span class="dashicons dashicons-bell" aria-hidden="true"></span>
                <span class="matrix-notif-bell-label"><?php esc_html_e('Notifications', 'matrix-mlm'); ?></span>
                <span class="matrix-notif-bell-badge<?php echo $unread > 0 ? ' is-visible' : ''; ?>"
                      data-matrix-notif-badge
                      aria-hidden="<?php echo $unread > 0 ? 'false' : 'true'; ?>"><?php
                    echo $unread > 99 ? '99+' : (int) $unread;
                ?></span>
            </button>

            <div class="matrix-notif-dropdown"
                 hidden
                 role="dialog"
                 aria-label="<?php esc_attr_e('Recent notifications', 'matrix-mlm'); ?>"
                 data-matrix-notif-dropdown>
                <div class="matrix-notif-dropdown-header">
                    <h4><?php esc_html_e('Notifications', 'matrix-mlm'); ?></h4>
                    <button type="button"
                            class="matrix-notif-mark-all"
                            data-matrix-notif-mark-all
                            <?php echo $unread > 0 ? '' : 'disabled'; ?>>
                        <?php esc_html_e('Mark all as read', 'matrix-mlm'); ?>
                    </button>
                </div>
                <ul class="matrix-notif-list" data-matrix-notif-list>
                    <?php if (empty($rows)): ?>
                        <li class="matrix-notif-empty" data-matrix-notif-empty>
                            <?php esc_html_e('No notifications yet. We\'ll let you know here when something happens.', 'matrix-mlm'); ?>
                        </li>
                    <?php else: foreach ($rows as $row):
                        $is_read = !empty($row->read_at);
                        $href    = !empty($row->link_url) ? $row->link_url : '#';
                        $created = strtotime($row->created_at . ' UTC');
                        $time_ago = $created
                            ? sprintf(
                                /* translators: %s: e.g. "3 minutes" */
                                __('%s ago', 'matrix-mlm'),
                                human_time_diff($created, time())
                            )
                            : '';
                    ?>
                        <li class="matrix-notif-item<?php echo $is_read ? ' is-read' : ' is-unread'; ?>"
                            data-matrix-notif-item
                            data-id="<?php echo (int) $row->id; ?>"
                            data-link="<?php echo esc_attr($href); ?>">
                            <span class="matrix-notif-icon dashicons <?php echo esc_attr($this->notification_icon_class((string) $row->type)); ?>" aria-hidden="true"></span>
                            <div class="matrix-notif-body">
                                <div class="matrix-notif-title"><?php echo esc_html($row->title); ?></div>
                                <?php if (!empty($row->body)): ?>
                                    <div class="matrix-notif-text"><?php echo esc_html(wp_trim_words((string) $row->body, 24)); ?></div>
                                <?php endif; ?>
                                <div class="matrix-notif-time"><?php echo esc_html($time_ago); ?></div>
                            </div>
                            <?php if (!$is_read): ?>
                                <span class="matrix-notif-dot" aria-label="<?php esc_attr_e('Unread', 'matrix-mlm'); ?>"></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; endif; ?>
                </ul>
                <div class="matrix-notif-dropdown-footer">
                    <a href="<?php echo esc_url($see_all_url); ?>"><?php esc_html_e('See all notifications', 'matrix-mlm'); ?></a>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Map a notification type slug to the dashicons class the
     * bell dropdown should render. Unknown slugs fall through to
     * a generic info icon. Mirrored verbatim by the JS-side
     * iconClassFor() in matrix-mlm-notifications.js so server
     * render and client polled updates stay aligned.
     */
    private function notification_icon_class($type) {
        $map = [
            'transfer_received'           => 'dashicons-money-alt',
            'transfer_sent'               => 'dashicons-share',
            'commission_referral'         => 'dashicons-groups',
            'commission_level'            => 'dashicons-chart-area',
            'commission_matrix_completion' => 'dashicons-awards',
            'commission'                  => 'dashicons-chart-area',
            'level_completion'            => 'dashicons-awards',
            'deposit'                     => 'dashicons-download',
            'withdrawal_approved'         => 'dashicons-yes-alt',
            'withdrawal_rejected'         => 'dashicons-no-alt',
            'withdrawal_completed'        => 'dashicons-yes-alt',
            'withdrawal'                  => 'dashicons-bank',
            'bank_payout'                 => 'dashicons-bank',
            'bank_payout_failed'          => 'dashicons-warning',
            'bill_payment'                => 'dashicons-smartphone',
            'bill_payment_airtime'        => 'dashicons-smartphone',
            'bill_payment_data'           => 'dashicons-smartphone',
            'bill_payment_cable'          => 'dashicons-format-video',
            'bill_payment_electricity'    => 'dashicons-lightbulb',
            'bill_refund'                 => 'dashicons-image-rotate',
            'card_status'                 => 'dashicons-id-alt',
            'epin_redeemed'               => 'dashicons-tickets-alt',
            'subscription'                => 'dashicons-calendar-alt',
            'subscription_deactivation'   => 'dashicons-warning',
            'password_changed'            => 'dashicons-shield',
            'loan_approved'               => 'dashicons-yes-alt',
            'loan_rejected'               => 'dashicons-no-alt',
            'loan'                        => 'dashicons-money-alt',
            'healthcare_approved'         => 'dashicons-heart',
            'healthcare_rejected'         => 'dashicons-no-alt',
            'healthcare'                  => 'dashicons-heart',
            'admin_announcement'          => 'dashicons-megaphone',
        ];
        return $map[$type] ?? 'dashicons-info-outline';
    }

    /**
     * Render the full Notifications dashboard tab. Paginated server
     * side at 20 per page; the bell dropdown is for the most recent
     * 20, this surface is for the complete history.
     *
     * Uses ?notif_page=N for paging — different query var from the
     * dashboard's ?tab= so the two don't collide. Mark-as-read and
     * mark-all-read on this view share the same AJAX endpoints the
     * bell dropdown uses (matrix_in_app_mark_read /
     * matrix_in_app_mark_all_read), so a user can act on
     * notifications from either surface and the read state stays
     * consistent across both.
     */
    private function render_notifications_tab($user_id) {
        if (!class_exists('Matrix_MLM_In_App_Notifications')) {
            echo '<div class="matrix-alert matrix-alert-info">'
               . esc_html__('Notifications are not available on this install.', 'matrix-mlm')
               . '</div>';
            return;
        }
        $per_page = 20;
        $page     = isset($_GET['notif_page']) ? max(1, (int) $_GET['notif_page']) : 1;
        $offset   = ($page - 1) * $per_page;
        $total    = Matrix_MLM_In_App_Notifications::total_count($user_id);
        $pages    = max(1, (int) ceil($total / $per_page));
        $rows     = Matrix_MLM_In_App_Notifications::get_for_user($user_id, $per_page, $offset);
        $unread   = Matrix_MLM_In_App_Notifications::unread_count($user_id);
        $base_url = self::tab_url('notifications');
        ?>
        <h2><?php esc_html_e('Notifications', 'matrix-mlm'); ?></h2>
        <p class="matrix-subtitle">
            <?php
            if ($total > 0) {
                printf(
                    /* translators: 1: unread, 2: total */
                    esc_html__('%1$d unread of %2$d total. Older read notifications are removed automatically after 90 days.', 'matrix-mlm'),
                    (int) $unread,
                    (int) $total
                );
            } else {
                esc_html_e('You have no notifications yet.', 'matrix-mlm');
            }
            ?>
        </p>

        <?php if ($unread > 0): ?>
            <div class="matrix-notif-page-toolbar">
                <button type="button"
                        class="matrix-btn matrix-btn-secondary matrix-btn-sm"
                        data-matrix-notif-mark-all-page>
                    <?php esc_html_e('Mark all as read', 'matrix-mlm'); ?>
                </button>
            </div>
        <?php endif; ?>

        <?php if (empty($rows)): ?>
            <div class="matrix-info-box">
                <p><?php esc_html_e('Nothing here yet. As soon as you receive a transfer, earn a commission, or pay a bill, it\'ll show up on this page.', 'matrix-mlm'); ?></p>
            </div>
        <?php else: ?>
            <ul class="matrix-notif-page-list">
                <?php foreach ($rows as $row):
                    $is_read = !empty($row->read_at);
                    $created = strtotime($row->created_at . ' UTC');
                    $human_time = $created
                        ? sprintf(
                            /* translators: %s: human-readable diff */
                            __('%s ago', 'matrix-mlm'),
                            human_time_diff($created, time())
                        )
                        : '';
                    $exact_time = $created ? date_i18n('M j, Y g:i a', $created) : '';
                ?>
                    <li class="matrix-notif-page-item<?php echo $is_read ? ' is-read' : ' is-unread'; ?>"
                        data-matrix-notif-page-item
                        data-id="<?php echo (int) $row->id; ?>">
                        <span class="matrix-notif-icon dashicons <?php echo esc_attr($this->notification_icon_class((string) $row->type)); ?>" aria-hidden="true"></span>
                        <div class="matrix-notif-page-body">
                            <div class="matrix-notif-page-title">
                                <?php if (!empty($row->link_url)): ?>
                                    <a href="<?php echo esc_url($row->link_url); ?>"
                                       data-matrix-notif-page-link
                                       data-id="<?php echo (int) $row->id; ?>"><?php echo esc_html($row->title); ?></a>
                                <?php else: ?>
                                    <?php echo esc_html($row->title); ?>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($row->body)): ?>
                                <div class="matrix-notif-page-text"><?php echo esc_html($row->body); ?></div>
                            <?php endif; ?>
                            <div class="matrix-notif-page-time" title="<?php echo esc_attr($exact_time); ?>"><?php echo esc_html($human_time . ' · ' . $exact_time); ?></div>
                        </div>
                        <?php if (!$is_read): ?>
                            <button type="button"
                                    class="matrix-notif-page-mark-read"
                                    data-matrix-notif-page-mark-read
                                    data-id="<?php echo (int) $row->id; ?>"
                                    aria-label="<?php esc_attr_e('Mark as read', 'matrix-mlm'); ?>">
                                <?php esc_html_e('Mark as read', 'matrix-mlm'); ?>
                            </button>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($pages > 1): ?>
                <nav class="matrix-notif-pagination" aria-label="<?php esc_attr_e('Notifications pagination', 'matrix-mlm'); ?>">
                    <?php if ($page > 1): ?>
                        <a class="matrix-btn matrix-btn-secondary matrix-btn-sm"
                           href="<?php echo esc_url(add_query_arg('notif_page', $page - 1, $base_url)); ?>">
                            &larr; <?php esc_html_e('Previous', 'matrix-mlm'); ?>
                        </a>
                    <?php endif; ?>
                    <span class="matrix-notif-page-indicator">
                        <?php
                        printf(
                            /* translators: 1: current page, 2: total pages */
                            esc_html__('Page %1$d of %2$d', 'matrix-mlm'),
                            (int) $page,
                            (int) $pages
                        );
                        ?>
                    </span>
                    <?php if ($page < $pages): ?>
                        <a class="matrix-btn matrix-btn-secondary matrix-btn-sm"
                           href="<?php echo esc_url(add_query_arg('notif_page', $page + 1, $base_url)); ?>">
                            <?php esc_html_e('Next', 'matrix-mlm'); ?> &rarr;
                        </a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
        <?php
    }
}

