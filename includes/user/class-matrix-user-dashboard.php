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
            return '<div class="matrix-alert matrix-alert-danger">' . __('Your account has been suspended. Please contact support.', 'matrix-mlm') . '</div>';
        }

        $tab = $this->resolve_active_tab();

        // Surface any unread level-completion milestones for this user
        // as a top-of-page toast stack. Buffered output, since
        // render_dashboard's caller wraps the whole shortcode in
        // ob_start()/ob_get_clean() — emitting the toast HTML before
        // the dashboard wrapper is what we want for a fixed-position
        // overlay, but it also has to flow through the same buffer.
        $level_toasts_html = $this->build_level_completion_toasts($user_id);

        ob_start();
        ?>
        <?php echo $level_toasts_html; ?>
        <div class="matrix-dashboard">
            <div class="matrix-dashboard-sidebar">
                <div class="matrix-user-info">
                    <div class="matrix-avatar"><?php echo get_avatar($user_id, 60); ?></div>
                    <h4><?php echo esc_html(wp_get_current_user()->display_name); ?></h4>
                    <p class="matrix-balance"><?php echo get_option('matrix_mlm_currency_symbol', '₦'); ?><?php echo number_format((new Matrix_MLM_Wallet())->get_balance($user_id), 2); ?></p>
                </div>
                <nav class="matrix-dashboard-nav">
                    <a href="<?php echo self::tab_url('overview'); ?>" class="<?php echo $tab === 'overview' ? 'active' : ''; ?>"><span class="dashicons dashicons-dashboard"></span> <?php _e('Dashboard', 'matrix-mlm'); ?></a>
                    <a href="<?php echo self::tab_url('deposits'); ?>" class="<?php echo $tab === 'deposits' ? 'active' : ''; ?>"><span class="dashicons dashicons-download"></span> <?php _e('Deposit', 'matrix-mlm'); ?></a>
                    <a href="<?php echo self::tab_url('deposit-history'); ?>" class="<?php echo $tab === 'deposit-history' ? 'active' : ''; ?>"><span class="dashicons dashicons-list-view"></span> <?php _e('Deposit History', 'matrix-mlm'); ?></a>
                    <a href="<?php echo self::tab_url('genealogy'); ?>" class="<?php echo $tab === 'genealogy' ? 'active' : ''; ?>"><span class="dashicons dashicons-networking"></span> <?php _e('Genealogy Tree', 'matrix-mlm'); ?></a>
                    <a href="<?php echo self::tab_url('referrals'); ?>" class="<?php echo $tab === 'referrals' ? 'active' : ''; ?>"><span class="dashicons dashicons-groups"></span> <?php _e('Referrals', 'matrix-mlm'); ?></a>
                    <a href="<?php echo self::tab_url('commissions'); ?>" class="<?php echo $tab === 'commissions' ? 'active' : ''; ?>"><span class="dashicons dashicons-chart-area"></span> <?php _e('Commissions', 'matrix-mlm'); ?></a>
                    <a href="<?php echo self::tab_url('plans'); ?>" class="<?php echo $tab === 'plans' ? 'active' : ''; ?>"><span class="dashicons dashicons-networking"></span> <?php _e('My Plans', 'matrix-mlm'); ?></a>
                    <a href="<?php echo self::tab_url('epin'); ?>" class="<?php echo $tab === 'epin' ? 'active' : ''; ?>"><span class="dashicons dashicons-tickets-alt"></span> <?php _e('E-Pin Recharge', 'matrix-mlm'); ?></a>
                    <a href="<?php echo self::tab_url('wallet'); ?>" class="<?php echo $tab === 'wallet' ? 'active' : ''; ?>"><span class="dashicons dashicons-bank"></span> <?php _e('Wallet', 'matrix-mlm'); ?></a>
                    <a href="<?php echo self::tab_url('card'); ?>" class="<?php echo $tab === 'card' ? 'active' : ''; ?>"><span class="dashicons dashicons-id-alt"></span> <?php _e('Verve Card', 'matrix-mlm'); ?></a>
                    <a href="<?php echo self::tab_url('billing'); ?>" class="<?php echo $tab === 'billing' ? 'active' : ''; ?>"><span class="dashicons dashicons-smartphone"></span> <?php _e('Bill Payments', 'matrix-mlm'); ?></a>
                    <a href="<?php echo self::tab_url('benefits'); ?>" class="<?php echo $tab === 'benefits' ? 'active' : ''; ?>"><span class="dashicons dashicons-awards"></span> <?php _e('Benefits', 'matrix-mlm'); ?></a>
                    <a href="<?php echo self::tab_url('tickets'); ?>" class="<?php echo $tab === 'tickets' ? 'active' : ''; ?>"><span class="dashicons dashicons-sos"></span> <?php _e('Support', 'matrix-mlm'); ?></a>
                    <a href="<?php echo self::tab_url('profile'); ?>" class="<?php echo $tab === 'profile' ? 'active' : ''; ?>"><span class="dashicons dashicons-admin-users"></span> <?php _e('Profile', 'matrix-mlm'); ?></a>
                    <a href="<?php echo self::tab_url('security'); ?>" class="<?php echo $tab === 'security' ? 'active' : ''; ?>"><span class="dashicons dashicons-shield"></span> <?php _e('2FA Security', 'matrix-mlm'); ?></a>
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
        ?>
        <h2><?php _e('Two-Factor Authentication', 'matrix-mlm'); ?></h2>
        <div class="matrix-security-section">
            <p><?php _e('Two-factor authentication adds an extra layer of security to your account.', 'matrix-mlm'); ?></p>
            <div class="matrix-2fa-status">
                <strong><?php _e('Status:', 'matrix-mlm'); ?></strong>
                <?php if ($is_enabled): ?>
                <span class="matrix-badge matrix-badge-active"><?php _e('Enabled', 'matrix-mlm'); ?></span>
                <button class="matrix-btn matrix-btn-danger" onclick="matrixDisable2FA()"><?php _e('Disable 2FA', 'matrix-mlm'); ?></button>
                <?php else: ?>
                <span class="matrix-badge matrix-badge-inactive"><?php _e('Disabled', 'matrix-mlm'); ?></span>
                <button class="matrix-btn matrix-btn-primary" onclick="matrixEnable2FA()"><?php _e('Enable 2FA', 'matrix-mlm'); ?></button>
                <?php endif; ?>
            </div>
            <div id="matrix-2fa-setup" style="display: none;">
                <p><?php _e('Scan this QR code with your authenticator app (Google Authenticator, Authy, etc.):', 'matrix-mlm'); ?></p>
                <img id="matrix-2fa-qr" src="" alt="QR Code">
                <p><?php _e('Secret Key:', 'matrix-mlm'); ?> <code id="matrix-2fa-secret"></code></p>
            </div>
        </div>
        <?php
    }
}
