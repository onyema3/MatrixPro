<?php
/**
 * User Bank Payout (via Fintava)
 *
 * Allows users to transfer funds from their Fintava virtual wallet
 * directly to any Nigerian bank account.
 *
 * Source of funds: the user's Fintava virtual wallet, NOT the Matrix
 * wallet. The server-side handler is the source of truth — see
 * Matrix_MLM_Fintava::ajax_initiate_transfer(), which calls Fintava's
 * /bank/credit with sourceId = the user's Fintava customer UUID and
 * never debits Matrix_MLM_Wallet. Funds therefore have to already be
 * on the Fintava side before this form will work; the user gets them
 * there via "Transfer to Own Wallet" (Matrix → Fintava) on the
 * consolidated Wallet page, or by receiving deposits directly to
 * their virtual account number.
 *
 * Fintava deducts its own transfer fee from the same Fintava wallet at
 * the gateway level. The legacy matrix_mlm_fintava_charge_type /
 * matrix_mlm_fintava_charge_value options are ignored on this path —
 * we do NOT show a plugin-side service-charge line in this form, and
 * the submit-state gate uses a small fee buffer (mirroring the
 * backend's preflight in ajax_initiate_transfer) instead of a local
 * charge calculation.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_User_Bank_Payout {

    /**
     * Render the Bank Payout page (or just its body when embedded).
     *
     * @param int  $user_id      The user being rendered for.
     * @param bool $skip_header  When true, suppress the H2 + subtitle so
     *                           the form body can be embedded inside the
     *                           consolidated Wallet page (which renders
     *                           its own page-level header and uses tabs
     *                           to label this section). Defaults to
     *                           false so the legacy ?tab=bank-payout
     *                           URL still renders a complete standalone
     *                           page if any old link or bookmark hits
     *                           it. The whitelist in the dashboard
     *                           dispatcher rejects that slug today, but
     *                           the class is still callable directly
     *                           and we don't want a silent regression
     *                           if the slug is ever re-enabled.
     */
    public function render($user_id, $skip_header = false) {
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $min_payout = get_option('matrix_mlm_fintava_min_payout', 1000);
        $max_payout = get_option('matrix_mlm_fintava_max_payout', 5000000);
        $fintava = new Matrix_MLM_Fintava();
        $is_active = $fintava->is_active();

        // Source of funds for this form is the user's Fintava virtual
        // wallet, not the Matrix wallet (see class-level docblock and
        // Matrix_MLM_Fintava::ajax_initiate_transfer for the full
        // rationale). Pull the live virtual-wallet balance now so the
        // info-box, the amount field's max attribute, and the JS
        // submit-state gate all compare against the right number.
        //
        // Failure modes are surfaced inline rather than blocking the
        // form, mirroring the resilience pattern in the consolidated
        // Wallet page header:
        //
        //   - Fintava integration disabled → handled below by the
        //     existing $is_active branch; we never reach the balance
        //     fetch.
        //   - User has no Fintava wallet row → balance shown as
        //     "Unavailable" with a message pointing to the Create
        //     Fintava Wallet flow. Submit will also fail server-side
        //     with the same error, so this is purely a UX hint.
        //   - Live API call errored → fall through to a 0 placeholder
        //     and surface the gateway's normalised message inline so
        //     the operator can see why the balance is stale.
        //
        // No legacy plugin-side charge calculation: Fintava deducts its
        // own transfer fee directly from the user's Fintava wallet at
        // the gateway level, and the matrix_mlm_fintava_charge_*
        // options are ignored by ajax_initiate_transfer. The "Service
        // Charge" row that used to live in the info-box is replaced by
        // a one-line note explaining the gateway-side fee.
        $user_wallet                = $is_active ? $fintava->get_user_wallet($user_id) : null;
        $balance                    = 0.0;
        $balance_unavailable_reason = '';
        if ($is_active) {
            if ($user_wallet && !empty($user_wallet->wallet_id)) {
                $bal_result = $fintava->get_virtual_wallet_balance(
                    $user_wallet->wallet_id,
                    $user_wallet->account_number,
                    $user_wallet->customer_id ?? null
                );
                if (is_wp_error($bal_result)) {
                    $balance_unavailable_reason = Matrix_MLM_Fintava::normalize_api_message(
                        $bal_result->get_error_message(),
                        __('Live Fintava balance is unavailable; refresh in a moment.', 'matrix-mlm')
                    );
                } elseif (is_array($bal_result) && isset($bal_result['available_balance'])) {
                    $balance = floatval($bal_result['available_balance']);
                }
            } else {
                $balance_unavailable_reason = __('You do not have a Fintava virtual wallet yet. Create one before you can transfer to a bank.', 'matrix-mlm');
            }
        }

        // Pre-render the bank list server-side. The previous flow loaded
        // banks with a $.ajax({ action: 'matrix_fintava_get_banks' }) call
        // fired from an inline <script> at DOM-ready, which had three
        // distinct failure modes that all surfaced as the dropdown stuck
        // on "-- Loading banks..." until the user refreshed:
        //
        //   1. Cold-transient first load — get_banks() walks up to three
        //      Fintava hosts on a cache miss, and a slow chain plus the
        //      15s frontend timeout meant the user typically refreshed
        //      before the AJAX resolved. On the second load the transient
        //      was warm and the call returned instantly, which is exactly
        //      the "only loads when I hit refresh" pattern reported.
        //
        //   2. SPA-style navigation by themes/optimizers (FlyingPress,
        //      Astra "instant click", WP Rocket prefetch, etc.) that
        //      swap body content via fetch instead of doing a full page
        //      reload. Inline <script> blocks inside the body do NOT
        //      re-execute when the content is swapped that way — only a
        //      hard refresh re-runs them, which matches the observed UX.
        //
        //   3. Footer-script ordering races — both jQuery and the
        //      matrixMLM localize variable are emitted in the footer,
        //      and aggressive optimizer plugins occasionally reorder or
        //      defer them in ways that left the polling wrapper
        //      executing before matrixMLM was defined.
        //
        // Doing the lookup at render time eliminates all three classes
        // of failure: the <select> is part of the static HTML, no AJAX
        // is needed to populate it, and the 24-hour transient in
        // get_banks() means this only adds latency on the first call
        // after cache expiry. Subsequent loads reuse the transient and
        // render instantly.
        //
        // Mirrors the AJAX handler's fallback rule: when the live call
        // fails (WP_Error from get_banks()), fall back to the bundled
        // CBN/NIBSS list and surface the underlying reason inline so
        // the operator still has the diagnostic that the old AJAX path
        // emitted via response.data.reason.
        $banks_list = [];
        $banks_fallback_reason = '';
        if ($is_active) {
            $banks_result = $fintava->get_banks();
            if (is_wp_error($banks_result)) {
                $banks_list = Matrix_MLM_Fintava::get_static_banks_fallback();
                $banks_fallback_reason = $banks_result->get_error_message();
            } elseif (is_array($banks_result)) {
                $banks_list = $banks_result;
            }
        }

        // Get payout history
        $payouts = $fintava->get_user_payouts($user_id, 20);
        ?>
        <?php if (!$skip_header): ?>
        <h2><?php _e('Transfer to Bank', 'matrix-mlm'); ?></h2>
        <p class="matrix-subtitle"><?php _e('Transfer funds from your Fintava virtual wallet directly to any Nigerian bank account.', 'matrix-mlm'); ?></p>
        <?php endif; ?>

        <?php if (!$is_active): ?>
        <div class="matrix-alert matrix-alert-warning">
            <?php _e('Bank transfer is currently unavailable. Please contact support.', 'matrix-mlm'); ?>
        </div>
        <?php else: ?>

        <div class="matrix-info-box">
            <p><strong><?php _e('Source:', 'matrix-mlm'); ?></strong> <?php _e('Fintava Virtual Wallet', 'matrix-mlm'); ?></p>
            <p>
                <strong><?php _e('Available Balance:', 'matrix-mlm'); ?></strong>
                <?php echo $currency . number_format($balance, 2); ?>
                <?php if ($balance_unavailable_reason !== ''): ?>
                <small style="display:block;margin-top:4px;color:#92400e;">
                    <?php echo esc_html($balance_unavailable_reason); ?>
                </small>
                <?php endif; ?>
            </p>
            <p><strong><?php _e('Transfer Limits:', 'matrix-mlm'); ?></strong> <?php echo $currency . number_format($min_payout) . ' – ' . $currency . number_format($max_payout); ?></p>
            <p><small><?php _e('Note: Fintava deducts its own transfer fee from your Fintava wallet on top of the amount you send. The fee is set by the gateway, not this plugin.', 'matrix-mlm'); ?></small></p>
        </div>

        <div class="matrix-form-card">
            <form id="matrix-bank-payout-form" class="matrix-form">
                <!-- Bank Selection -->
                <div class="matrix-form-group">
                    <label><?php _e('Select Bank', 'matrix-mlm'); ?></label>
                    <select name="bank_code" id="fintava-bank-select" required>
                        <?php if (empty($banks_list)): ?>
                        <option value=""><?php _e('-- No banks available, please contact support --', 'matrix-mlm'); ?></option>
                        <?php else: ?>
                        <option value=""><?php _e('-- Select Bank --', 'matrix-mlm'); ?></option>
                        <?php foreach ($banks_list as $bank):
                            if (empty($bank['code']) || empty($bank['name'])) { continue; }
                        ?>
                        <option value="<?php echo esc_attr($bank['code']); ?>" data-name="<?php echo esc_attr($bank['name']); ?>"><?php echo esc_html($bank['name']); ?></option>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <?php if ($banks_fallback_reason !== ''): ?>
                    <!--
                        Same diagnostic the previous AJAX path emitted via
                        response.data.reason — surfaced inline so an
                        operator without DevTools open can still see *why*
                        Fintava /banks failed and we're on the bundled
                        CBN/NIBSS list. The form is fully usable in this
                        state; this note exists so a later transfer
                        failing with an unknown-bank error has a visible
                        cause attached.
                    -->
                    <div class="matrix-bank-fallback-note" style="margin-top:6px;font-size:12px;line-height:1.4;color:#92400e;">
                        <div><?php _e('Note: using built-in Nigerian banks list. Fintava /banks is unreachable from your account.', 'matrix-mlm'); ?></div>
                        <div style="margin-top:4px;font-style:italic;word-break:break-word;"><?php _e('Reason:', 'matrix-mlm'); ?> <?php echo esc_html($banks_fallback_reason); ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Account Number -->
                <div class="matrix-form-group">
                    <label><?php _e('Account Number', 'matrix-mlm'); ?></label>
                    <input type="text" name="account_number" id="fintava-account-number" 
                           maxlength="10" pattern="\d{10}" required 
                           placeholder="<?php _e('Enter 10-digit account number', 'matrix-mlm'); ?>">
                </div>

                <!-- Account Name (auto-resolved or manually entered) -->
                <div class="matrix-form-group" id="fintava-account-name-group" style="display:none;">
                    <label><?php _e('Account Name', 'matrix-mlm'); ?></label>
                    <div class="matrix-resolved-account">
                        <input type="text" name="account_name" id="fintava-account-name" readonly class="matrix-input-success">
                        <span class="matrix-verify-badge" id="fintava-verify-badge">&#10003; Verified</span>
                    </div>
                </div>

                <!-- Resolving indicator -->
                <div id="fintava-resolving" style="display:none;" class="matrix-loading-text">
                    <span class="matrix-spinner"></span> <?php _e('Verifying account...', 'matrix-mlm'); ?>
                </div>

                <!-- Verification-failed inline notice with a manual-entry escape hatch.
                     Hidden by default; populated by the resolveAccount error
                     handler when Fintava can't auto-verify the account. -->
                <div id="fintava-verify-failed" style="display:none;" class="matrix-alert matrix-alert-warning" role="alert" aria-live="polite">
                    <div id="fintava-verify-failed-msg" style="margin-bottom:8px;"></div>
                    <button type="button" class="matrix-btn matrix-btn-secondary matrix-btn-sm" id="fintava-manual-override">
                        <?php _e('Continue without verification &rarr;', 'matrix-mlm'); ?>
                    </button>
                </div>

                <!-- Amount -->
                <div class="matrix-form-group">
                    <label><?php _e('Amount', 'matrix-mlm'); ?> (<?php echo $currency; ?>)</label>
                    <?php
                    // Compute the practical ceiling for the amount field
                    // up-front so the gateway-side fee buffer is already
                    // factored into what the user is allowed to type.
                    //
                    // The backend's preflight in
                    // Matrix_MLM_Fintava::ajax_initiate_transfer requires
                    //     fintava_balance >= amount + max(₦100, 1.5% * amount)
                    // before sending the /bank/credit call (Fintava
                    // returns a generic HTTP 500 if the source wallet
                    // can't fund both the amount and its own transfer
                    // fee, so we reserve a buffer to surface a clean
                    // "insufficient" error instead). Inverting that
                    // inequality gives the maximum amount the user can
                    // type:
                    //     amount <= (balance - 100) / 1.015
                    // Floor to two decimals to stay aligned with
                    // step="0.01"; never go below zero so a user with
                    // an unfunded Fintava wallet sees a sane max=0
                    // rather than a negative value.
                    $fee_buffer_min  = 100.0;
                    $balance_ceiling = max(0, ((float) $balance - $fee_buffer_min) / 1.015);
                    $balance_ceiling = floor($balance_ceiling * 100) / 100;
                    $effective_max   = min((float) $max_payout, $balance_ceiling);
                    ?>
                    <input type="number" name="amount" id="fintava-amount"
                           min="<?php echo $min_payout; ?>"
                           max="<?php echo $effective_max; ?>"
                           step="0.01" required
                           placeholder="<?php echo sprintf(__('Min %s, Max %s', 'matrix-mlm'), number_format($min_payout), number_format($effective_max, 2)); ?>">
                </div>

                <!-- Narration (Optional) -->
                <div class="matrix-form-group">
                    <label><?php _e('Narration (Optional)', 'matrix-mlm'); ?></label>
                    <input type="text" name="narration" maxlength="100" placeholder="<?php _e('e.g. Salary, Savings, etc.', 'matrix-mlm'); ?>">
                </div>

                <!-- Hidden fields -->
                <input type="hidden" name="bank_name" id="fintava-bank-name" value="">

                <?php
                // Transaction PIN field (PR 2). Path key 'bank' maps
                // to the matrix_mlm_pin_required_for_bank admin
                // toggle on Settings → Financial. Helper returns
                // '' for ungated paths or users with no PIN, so
                // installs that haven't opted in see no UI change.
                // The matching require_pin_for_request() gate sits
                // in Matrix_MLM_Fintava::ajax_initiate_transfer.
                echo Matrix_MLM_Transaction_Pin::render_field($user_id, 'bank');
                ?>

                <!-- Submit -->
                <button type="submit" class="matrix-btn matrix-btn-primary matrix-btn-block" id="fintava-submit-btn" disabled>
                    <?php _e('Transfer to Bank', 'matrix-mlm'); ?>
                </button>
                <!--
                    Hint slot: when the submit button is disabled, the JS
                    populates this with the specific reason (no bank
                    picked, no 10-digit account, account not verified,
                    no amount, amount + charge > balance, etc.). Without
                    this slot users had no way to tell which of the four
                    submit-gate conditions was failing, which led to the
                    "transfer button is not active" reports — the form
                    silently waited on a verification step or balance
                    check the user couldn't see.

                    Always present in the DOM (no display:none) so the
                    JS can render it without reflows; it stays visually
                    empty whenever the button is enabled.
                -->
                <div id="fintava-submit-status" class="matrix-submit-status" role="status" aria-live="polite"></div>
            </form>
        </div>

        <?php endif; ?>

        <!-- Payout History -->
        <h3><?php _e('Bank Payout History', 'matrix-mlm'); ?></h3>
        <?php
        // Count failed rows so the toolbar only renders when there's
        // something to clear. Cheaper than re-querying since we already
        // have the list in memory above.
        $failed_count = 0;
        foreach ($payouts as $_p) {
            if (isset($_p->status) && $_p->status === 'failed') {
                $failed_count++;
            }
        }
        ?>
        <?php if ($failed_count > 0): ?>
        <div class="matrix-payout-toolbar" style="margin: 0 0 12px; display: flex; justify-content: flex-end;">
            <button type="button" id="fintava-clear-failed-btn" class="matrix-btn matrix-btn-secondary matrix-btn-sm" data-failed-count="<?php echo esc_attr($failed_count); ?>">
                <?php
                echo esc_html(sprintf(
                    /* translators: %d: number of failed transactions */
                    _n('Clear %d failed transaction', 'Clear %d failed transactions', $failed_count, 'matrix-mlm'),
                    $failed_count
                ));
                ?>
            </button>
        </div>
        <?php endif; ?>
        <?php if (empty($payouts)): ?>
        <div class="matrix-alert matrix-alert-info"><?php _e('No bank payouts yet.', 'matrix-mlm'); ?></div>
        <?php else: ?>
        <table class="matrix-table">
            <thead>
                <tr>
                    <th><?php _e('Date', 'matrix-mlm'); ?></th>
                    <th><?php _e('Bank', 'matrix-mlm'); ?></th>
                    <th><?php _e('Account', 'matrix-mlm'); ?></th>
                    <th><?php _e('Amount', 'matrix-mlm'); ?></th>
                    <th><?php _e('Charge', 'matrix-mlm'); ?></th>
                    <th><?php _e('Status', 'matrix-mlm'); ?></th>
                    <th><?php _e('Reference', 'matrix-mlm'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payouts as $payout): ?>
                <tr>
                    <td><?php echo date('M d, Y H:i', strtotime($payout->created_at)); ?></td>
                    <td><?php echo esc_html($payout->bank_name); ?></td>
                    <td>
                        <?php echo esc_html($payout->account_name); ?><br>
                        <small><?php echo esc_html($payout->account_number); ?></small>
                    </td>
                    <td><?php echo $currency . number_format($payout->amount, 2); ?></td>
                    <td><?php echo $currency . number_format($payout->charge, 2); ?></td>
                    <td>
                        <span class="matrix-badge matrix-badge-<?php echo esc_attr($payout->status); ?>">
                            <?php echo ucfirst($payout->status); ?>
                        </span>
                        <?php if (!empty($payout->failure_reason) && in_array($payout->status, ['failed', 'refunded'], true)): ?>
                        <div class="matrix-payout-reason" title="<?php echo esc_attr($payout->failure_reason); ?>"><?php echo esc_html($payout->failure_reason); ?></div>
                        <?php endif; ?>
                    </td>
                    <td><small><code><?php echo esc_html($payout->reference); ?></code></small></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <style>
        .matrix-subtitle { color: #6b7280; margin: -10px 0 20px; font-size: 14px; }
        .matrix-resolved-account { position: relative; }
        .matrix-input-success { background: #ecfdf5 !important; border-color: #10b981 !important; }
        .matrix-input-warning { background: #fffbeb !important; border-color: #f59e0b !important; }
        .matrix-verify-badge { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #10b981; font-weight: 600; font-size: 13px; }
        .matrix-loading-text { padding: 8px 0; color: #6b7280; font-size: 13px; display: flex; align-items: center; gap: 8px; }
        .matrix-spinner { width: 16px; height: 16px; border: 2px solid #e5e7eb; border-top-color: #4f46e5; border-radius: 50%; animation: matrix-spin 0.6s linear infinite; display: inline-block; }
        @keyframes matrix-spin { to { transform: rotate(360deg); } }
        .matrix-badge-processing { background: #eff6ff; color: #1e40af; }
        .matrix-badge-refunded { background: #f5f3ff; color: #7c3aed; }
        .matrix-payout-reason { margin-top:6px; padding:6px 8px; background:#fef2f2; border-left:3px solid #dc2626; border-radius:3px; font-size:11px; line-height:1.4; color:#991b1b; word-break:break-word; max-width:280px; }
        .matrix-submit-status { margin-top:8px; min-height:18px; font-size:13px; line-height:1.4; color:#92400e; text-align:center; }
        .matrix-submit-status:empty { display:none; }
        </style>

        <script>
        // Wait for jQuery to load before binding the DOM-ready handler.
        //
        // Calling `jQuery(function($){...})` directly here used to be the
        // fix for an even older "matrixMLM is undefined" bug, but it has
        // its own failure mode: this inline <script> is printed in the
        // body while the page is still parsing, so it can run BEFORE the
        // <script src=".../jquery.js"> tag has executed when a theme or
        // performance plugin moves jQuery to the footer (Astra,
        // GeneratePress, OceanWP, WP Rocket, SG Optimizer, FlyingPress,
        // Perfmatters and most of the popular optimizers all do this).
        // In that window, `jQuery` is undefined → calling `jQuery(...)`
        // throws ReferenceError → the script aborts before any of the
        // handlers register → the bank <select> keeps its initial
        // "-- Loading banks..." option forever, with no `error:` branch
        // and no console output to point at the cause.
        //
        // Polling window.jQuery up to ~10s handles both the head-loaded
        // case (the very first tick passes the typeof check and the
        // DOM-ready callback fires immediately) and the footer-loaded
        // case (a few ms after wp_footer prints, the footer <script>
        // tags execute, jQuery becomes defined, and the next poll tick
        // fires the callback). If jQuery never arrives — e.g. another
        // plugin dequeued it entirely — we surface that explicitly in
        // the dropdown so the operator gets a debuggable failure mode
        // instead of an indefinitely-spinning select.
        (function() {
            var attempts = 0;
            var maxAttempts = 200; // 200 * 50ms = 10s ceiling

            function whenJQueryReady(cb) {
                if (typeof window.jQuery !== 'undefined' && typeof window.jQuery.fn !== 'undefined') {
                    // Synchronous dispatch — call cb the moment jQuery
                    // is detected, instead of going through
                    // `window.jQuery(cb)` (== `$(document).ready(cb)`)
                    // which queues cb for after DOMContentLoaded.
                    //
                    // The DCL deferral was a contributor to the
                    // "buttons don't work until I refresh" symptom on
                    // Wallet, Verve Card, Bills Payment, and Benefits:
                    // even after PR #289 head-loaded jQuery, this
                    // inline <script> still wouldn't bind handlers
                    // until the entire body finished parsing. In that
                    // window, document-delegated handlers on
                    // .matrix-bank-payout-form (and its sibling
                    // controls) weren't bound, so an early click /
                    // submit silently no-op'd. Calling cb synchronously
                    // closes the gap: by the time the user can see and
                    // click the form, document delegation is already
                    // in place.
                    //
                    // Same contract as the old jQuery(cb) wrapper:
                    // jQuery passed in as the first argument, so the
                    // inner code below doesn't need to change.
                    cb(window.jQuery);
                    return;
                }
                if (++attempts > maxAttempts) {
                    // jQuery never arrived — surface a non-destructive
                    // notice above the form rather than wiping the
                    // <select>'s pre-rendered bank options. The form is
                    // not usable in this state (account verification,
                    // submit, etc. all depend on jQuery), so the inputs
                    // are disabled, but the bank list itself stays
                    // visible so the operator/user can still tell that
                    // the rest of the page rendered correctly.
                    var form = document.getElementById('matrix-bank-payout-form');
                    if (form) {
                        var inputs = form.querySelectorAll('input, select, button, textarea');
                        for (var i = 0; i < inputs.length; i++) {
                            inputs[i].disabled = true;
                        }
                        var notice = document.createElement('div');
                        notice.className = 'matrix-alert matrix-alert-danger';
                        notice.textContent = '<?php echo esc_js(__("jQuery not loaded — please refresh the page or contact support.", "matrix-mlm")); ?>';
                        form.parentNode.insertBefore(notice, form);
                    }
                    if (window.console && console.error) {
                        console.error('[Matrix MLM] jQuery not loaded after 10s; bank dropdown init aborted.');
                    }
                    return;
                }
                setTimeout(function() { whenJQueryReady(cb); }, 50);
            }

            whenJQueryReady(function($) {
                'use strict';

            const currency = '<?php echo esc_js(get_option("matrix_mlm_currency_symbol", "₦")); ?>';
            // Source-of-funds is the Fintava virtual wallet (see file
            // docblock). The submit-state gate below mirrors the
            // backend's preflight in
            // Matrix_MLM_Fintava::ajax_initiate_transfer:
            //     fintava_balance >= amount + max(₦100, 1.5% * amount)
            // No plugin-side charge is applied — Fintava deducts its
            // own transfer fee from the same Fintava wallet at the
            // gateway level, and the legacy matrix_mlm_fintava_charge_*
            // options are ignored on this path.
            const balance = <?php echo floatval($balance); ?>;
            const balanceUnavailable = <?php echo $balance_unavailable_reason !== '' ? 'true' : 'false'; ?>;
            const feeBufferMin = 100;
            const feeBufferRate = 0.015;
            let accountVerified = false;
            // Tracks whether accountVerified was set by the user picking the
            // manual-entry path after Fintava /name/enquiry failed, so the
            // submit handler and the per-keystroke listener can honour the
            // looser empty-name check on this branch without weakening the
            // checks on the verified branch.
            let manualOverride = false;

            // Defensive: surface a clear, debuggable failure mode if the
            // matrixMLM global is somehow still missing when DOM-ready fires
            // (e.g. another plugin dequeued matrix-mlm-public, or a JS error
            // earlier in the page broke wp_footer). The bank dropdown is
            // pre-rendered server-side now so it stays usable for visual
            // selection, but the rest of the form (account verification,
            // charge calculation, transfer submit) all depend on
            // matrixMLM.ajaxUrl/nonce, so the form has to be disabled when
            // those are unreachable. We surface the error inline above
            // the form rather than wiping the <select>'s pre-rendered
            // options, which would have been the worse of the two
            // failure modes.
            if (typeof matrixMLM === 'undefined' || !matrixMLM.ajaxUrl) {
                var $form = $('#matrix-bank-payout-form');
                $form.find(':input').prop('disabled', true);
                $form.before(
                    $('<div/>')
                        .addClass('matrix-alert matrix-alert-danger')
                        .text('<?php echo esc_js(__("Cannot reach server (matrixMLM missing). Please refresh the page or contact support.", "matrix-mlm")); ?>')
                );
                if (window.console && console.error) {
                    console.error('[Matrix MLM] matrixMLM global is not defined — matrix-mlm-public.js was not enqueued on this page.');
                }
                return;
            }

            // Bank list is now pre-rendered server-side as part of the
            // <select> markup (see render() in this file). The earlier
            // $.ajax({ action: 'matrix_fintava_get_banks' }) call that
            // used to live here was the source of the "dropdown stuck on
            // -- Loading banks... until I refresh" bug — see the comment
            // block in render() for the three failure modes that drove
            // the move to server-side rendering.
            //
            // The matrix_fintava_get_banks AJAX handler is intentionally
            // left registered server-side; it's still useful as a
            // diagnostic endpoint (the admin migration tools and any
            // future "refresh banks" UI can call it without a page
            // reload), and removing it would be a breaking change for
            // anything that pokes at it directly.

            // Auto-resolve account when account number is 10 digits and bank is selected
            let resolveTimeout;
            function tryResolveAccount() {
                const accNum = $('#fintava-account-number').val();
                const bankCode = $('#fintava-bank-select').val();

                if (accNum.length === 10 && bankCode) {
                    clearTimeout(resolveTimeout);
                    // Render an interim "Waiting on account verification…"
                    // hint immediately so the 500ms debounce doesn't leave
                    // the status line stuck on the previous reason (e.g.
                    // "Enter a 10-digit account number." right after the
                    // user types the 10th digit).
                    updateSubmitButton();
                    resolveTimeout = setTimeout(function() {
                        resolveAccount(accNum, bankCode);
                    }, 500);
                } else {
                    // User is still typing or hasn't picked a bank yet.
                    // Tear down both the verified and the manual-override
                    // states so a stale account name from a previous
                    // resolution can't ride into a transfer for a different
                    // account number.
                    $('#fintava-account-name-group').hide();
                    $('#fintava-verify-failed').hide();
                    accountVerified = false;
                    manualOverride  = false;
                    updateSubmitButton();
                }
            }

            $(document).on('input', '#fintava-account-number', tryResolveAccount);
            $(document).on('change', '#fintava-bank-select', function() {
                $('#fintava-bank-name').val($(this).find(':selected').data('name') || '');
                tryResolveAccount();
            });

            // Render the initial hint so the user sees "Select a bank to
            // continue." immediately on page load instead of staring at a
            // disabled button with no explanation. updateSubmitButton()
            // is also called from every state-changing handler below, so
            // this is the only "initial" call needed.
            updateSubmitButton();            function resolveAccount(accountNumber, bankCode) {
                $('#fintava-resolving').show();
                $('#fintava-verify-failed').hide();
                $('#fintava-account-name-group').hide();
                accountVerified = false;
                manualOverride = false;
                updateSubmitButton();

                $.ajax({
                    url: matrixMLM.ajaxUrl,
                    type: 'POST',
                    // Mirror the /banks AJAX timeout so the verification step
                    // also has a definite end state. 35s sits just above the
                    // 30s server-side Fintava timeout in make_request() so a
                    // hung upstream call surfaces as a real error rather
                    // than a silently-pending request.
                    timeout: 35000,
                    data: {
                        action: 'matrix_fintava_resolve_account',
                        nonce: matrixMLM.nonce,
                        account_number: accountNumber,
                        bank_code: bankCode,
                        // The bank's display name (from the dropdown
                        // option we already populated above) lets the
                        // server's Paystack-side bank-code translator
                        // do a name-match fallback for fintech banks
                        // not in the static NIBSS<->CBN map. Optional;
                        // empty string is fine.
                        bank_name: ($('#fintava-bank-select').find(':selected').data('name') || '')
                    },
                    success: function(response) {
                        $('#fintava-resolving').hide();
                        // Treat an empty response.data.account_name on a
                        // success:true response as a verification failure
                        // with manual override available. Fintava can ack
                        // /name/enquiry with HTTP 200 but no usable
                        // accountName on certain merchant tiers; the
                        // server-side handler is now expected to convert
                        // that into a resolve_error, but we re-check here
                        // so the form never lands in the "verified, but
                        // the readonly box is blank" state regardless of
                        // server version (older builds can still hit this
                        // page until the plugin update lands).
                        var resolvedName = (response && response.success && response.data && typeof response.data.account_name === 'string')
                            ? response.data.account_name.trim()
                            : '';
                        if (response.success && resolvedName !== '') {
                            // Reset to the verified-readonly state in case
                            // the user is re-verifying after a previous
                            // manual override on the same form load.
                            const nameField = $('#fintava-account-name');
                            nameField
                                .val(response.data.account_name)
                                .prop('readonly', true)
                                .removeClass('matrix-input-warning')
                                .addClass('matrix-input-success');
                            $('#fintava-verify-badge')
                                .text('\u2713 <?php echo esc_js(__("Verified", "matrix-mlm")); ?>')
                                .css('color', '');
                            $('#fintava-account-name-group').show();
                            accountVerified = true;
                            manualOverride  = false;
                        } else {
                            // Verification failed (or succeeded with an
                            // empty name). If the server flagged the
                            // failure as one where manual override is safe
                            // — or we synthesised an empty-name failure
                            // here on the client — surface the inline
                            // notice + override button instead of a
                            // blocking alert(). The form remains
                            // submittable via the manual path.
                            const data = response && response.data ? response.data : {};
                            var msg = data.message || '<?php echo esc_js(__("Account verification failed", "matrix-mlm")); ?>';
                            var allowOverride = !!data.allow_manual_override;

                            // Synthesised empty-name failure on a
                            // success:true response — server didn't tell
                            // us it's safe to override, but we know it is
                            // (we have nothing else to display either
                            // way), so unconditionally offer manual entry.
                            if (response.success) {
                                msg = '<?php echo esc_js(__("Could not auto-verify the account name. Please continue without verification and type the holder\'s name.", "matrix-mlm")); ?>';
                                allowOverride = true;
                            }

                            if (allowOverride) {
                                $('#fintava-verify-failed-msg').text(msg);
                                $('#fintava-verify-failed').show();
                            } else {
                                alert(msg);
                            }
                            accountVerified = false;
                            manualOverride  = false;
                        }
                        updateSubmitButton();
                    },
                    error: function(xhr, status) {
                        $('#fintava-resolving').hide();
                        // Surface the failure inline (with the manual
                        // override) for transport errors too — including the
                        // 35s timeout — so a slow Fintava upstream doesn't
                        // strand the user. Network errors are the same
                        // class of "we can't tell from here whether the
                        // account is real" failure that the server-side
                        // resolve_error path already declares safe to
                        // override.
                        const msg = status === 'timeout'
                            ? '<?php echo esc_js(__("Verification timed out — please try again or continue without verification.", "matrix-mlm")); ?>'
                            : '<?php echo esc_js(__("Network error during verification.", "matrix-mlm")); ?>';
                        $('#fintava-verify-failed-msg').text(msg);
                        $('#fintava-verify-failed').show();
                        accountVerified = false;
                        manualOverride  = false;
                        updateSubmitButton();
                    }
                });
            }

            // Manual override: when Fintava /name/enquiry can't verify the
            // account (wrong sortCode shape on this merchant tier, endpoint
            // disabled, or any other failure mode), allow the operator to
            // type the account name themselves and proceed. The actual
            // /bank/credit/merchant call still validates account ownership
            // on Fintava's side at transfer time, so manual entry only
            // shifts the responsibility for the displayed name — it can't
            // route money to a wrong account just because the user typed
            // the wrong name into the box.
            $(document).on('click', '#fintava-manual-override', function() {
                $('#fintava-verify-failed').hide();

                const nameField = $('#fintava-account-name');
                nameField
                    .val('')
                    .prop('readonly', false)
                    .removeClass('matrix-input-success')
                    .addClass('matrix-input-warning')
                    .attr('placeholder', '<?php echo esc_js(__("Type the account holder name", "matrix-mlm")); ?>')
                    .focus();

                $('#fintava-verify-badge')
                    .text('<?php echo esc_js(__("Manual entry", "matrix-mlm")); ?>')
                    .css('color', '#92400e');

                $('#fintava-account-name-group').show();

                // Stay disabled until the operator actually types a name —
                // the per-keystroke `input` handler below flips
                // accountVerified the moment the field has any non-empty
                // content, so submit is gated on visible user intent
                // rather than the click alone.
                accountVerified = false;
                manualOverride  = true;
                updateSubmitButton();
            });

            // Re-disable submit on every keystroke until the manual-entry
            // field actually has a non-empty name, so an empty box can't
            // sneak through with manualOverride=true set.
            $(document).on('input', '#fintava-account-name', function() {
                if (manualOverride) {
                    accountVerified = $(this).val().trim().length > 0;
                    updateSubmitButton();
                }
            });

            // Per-keystroke amount handler — used to compute and display
            // a plugin-side service charge, but that charge is no longer
            // applied (Fintava deducts its own fee from the Fintava
            // wallet directly, see file docblock). All we need now is
            // to re-evaluate the submit-state gate so the "amount +
            // gateway fee buffer exceeds Fintava balance" reason can
            // appear/disappear as the user types.
            $(document).on('input', '#fintava-amount', function() {
                updateSubmitButton();
            });

            // Enable/disable submit button + render the "why disabled?"
            // hint. The gate conditions are checked in order from
            // top-of-form to bottom so the message points to the field
            // the user should fix next, rather than e.g. shouting about
            // amount when no bank is even picked yet. Once everything
            // passes, the hint clears and the button enables.
            //
            // Balance check: this form debits the user's Fintava virtual
            // wallet (not the Matrix wallet — see file docblock). The
            // backend's preflight reserves max(₦100, 1.5% * amount) on
            // top of the requested amount to cover Fintava's own
            // transfer fee, so we apply the same buffer here to keep
            // the client-side rejection consistent with what the server
            // would reject anyway.
            function updateSubmitButton() {
                const amount     = parseFloat($('#fintava-amount').val()) || 0;
                const bankCode   = $('#fintava-bank-select').val();
                const feeBuffer  = Math.max(feeBufferMin, amount * feeBufferRate);
                const totalNeeded = amount + feeBuffer;
                const accNum     = ($('#fintava-account-number').val() || '').trim();

                const $status = $('#fintava-submit-status');
                let reason = '';

                if (!bankCode) {
                    reason = '<?php echo esc_js(__("Select a bank to continue.", "matrix-mlm")); ?>';
                } else if (accNum.length !== 10) {
                    reason = '<?php echo esc_js(__("Enter a 10-digit account number.", "matrix-mlm")); ?>';
                } else if (!accountVerified) {
                    // Two distinct sub-states here, surfaced separately
                    // because the recovery action is different. If the
                    // manual-entry box is open the user just needs to
                    // type a name; if it isn't, verification is either
                    // still in flight or the inline notice with the
                    // override button is already showing above.
                    if (manualOverride) {
                        reason = '<?php echo esc_js(__("Type the account holder name to continue.", "matrix-mlm")); ?>';
                    } else if ($('#fintava-resolving').is(':visible')) {
                        reason = '<?php echo esc_js(__("Verifying account…", "matrix-mlm")); ?>';
                    } else if ($('#fintava-verify-failed').is(':visible')) {
                        reason = '<?php echo esc_js(__("Click \"Continue without verification\" above to proceed.", "matrix-mlm")); ?>';
                    } else {
                        reason = '<?php echo esc_js(__("Waiting on account verification…", "matrix-mlm")); ?>';
                    }
                } else if (amount <= 0) {
                    reason = '<?php echo esc_js(__("Enter the amount to transfer.", "matrix-mlm")); ?>';
                } else if (balanceUnavailable) {
                    // Couldn't resolve the live Fintava balance at
                    // render time. Don't pretend to gate against zero —
                    // surface the unavailability so the user knows to
                    // refresh, and let the server's own preflight be
                    // the authoritative check on submit.
                    reason = '<?php echo esc_js(__("Fintava balance is currently unavailable; refresh in a moment.", "matrix-mlm")); ?>';
                } else if (totalNeeded > balance) {
                    // Mirrors the backend's "insufficient Fintava
                    // balance" preflight — keeps the client-side gate
                    // consistent so users see the same rejection in
                    // both places. The amount field's max attribute
                    // should already prevent this for typed values,
                    // but pasted values can bypass the max attribute,
                    // so the runtime guard stays.
                    reason = '<?php echo esc_js(__("Amount plus the Fintava transfer fee exceeds your Fintava wallet balance.", "matrix-mlm")); ?>';
                }

                $status.text(reason);
                $('#fintava-submit-btn').prop('disabled', reason !== '');
            }

            // Submit transfer
            $(document).on('submit', '#matrix-bank-payout-form', function(e) {
                e.preventDefault();

                if (!accountVerified) {
                    alert('<?php _e("Please verify your account first", "matrix-mlm"); ?>');
                    return;
                }

                const amount = parseFloat($('#fintava-amount').val());
                const accountName = $('#fintava-account-name').val();
                const bankName = $('#fintava-bank-name').val();

                if (!confirm('<?php _e("Are you sure you want to transfer", "matrix-mlm"); ?> ' + currency + amount.toLocaleString() + ' <?php _e("to", "matrix-mlm"); ?> ' + accountName + ' (' + bankName + ')?')) {
                    return;
                }

                const btn = $('#fintava-submit-btn');
                btn.prop('disabled', true).text('<?php _e("Processing...", "matrix-mlm"); ?>');

                $.ajax({
                    url: matrixMLM.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'matrix_fintava_initiate_transfer',
                        nonce: matrixMLM.nonce,
                        amount: amount,
                        account_number: $('#fintava-account-number').val(),
                        bank_code: $('#fintava-bank-select').val(),
                        bank_name: bankName,
                        account_name: accountName,
                        narration: $('[name="narration"]').val(),
                        // Transaction PIN (PR 2). Always-post-empty
                        // contract: when render_field() didn't emit
                        // the input the selector returns undefined,
                        // we send '', and the server-side gate
                        // treats that as "PIN gate not active" and
                        // proceeds. No client-side branching needed.
                        transaction_pin: ($('#matrix-bank-payout-form [name=transaction_pin]').val() || '')
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            (typeof matrixMLMReload === "function" ? matrixMLMReload : function(){ window.location.reload(); })();
                        } else {
                            alert(response.data.message || '<?php _e("Transfer failed", "matrix-mlm"); ?>');
                            btn.prop('disabled', false).text('<?php _e("Transfer to Bank", "matrix-mlm"); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php _e("Network error. Please try again.", "matrix-mlm"); ?>');
                        btn.prop('disabled', false).text('<?php _e("Transfer to Bank", "matrix-mlm"); ?>');
                    }
                });
            });

            // "Clear failed transactions" toolbar button — bulk-deletes
            // the current user's failed payout history rows. Server-side
            // filters to user_id + status='failed' so this can only ever
            // remove the user's own failures; completed/processing/refunded
            // rows are untouched (they carry money-movement audit trail).
            //
            // The button is only rendered server-side when there is at
            // least one failed row, but the click handler is registered
            // unconditionally — document delegation on a non-existent
            // element is a harmless no-op and keeps the JS branchless,
            // and (importantly) lets the handler still fire if the
            // pane is re-rendered or the button arrives in the DOM
            // after this script runs (the same DOM-timing race that
            // affected the wallet action buttons before they were
            // converted to delegation).
            $(document).on('click', '#fintava-clear-failed-btn', function() {
                var btn = $(this);
                var count = btn.data('failedCount') || 0;
                var prompt = count === 1
                    ? '<?php echo esc_js(__("Permanently delete this failed transaction from your history?", "matrix-mlm")); ?>'
                    : '<?php echo esc_js(__("Permanently delete %d failed transactions from your history?", "matrix-mlm")); ?>'
                          .replace('%d', count);

                if (!confirm(prompt)) { return; }

                var originalText = btn.text();
                btn.prop('disabled', true).text('<?php echo esc_js(__("Clearing...", "matrix-mlm")); ?>');

                $.ajax({
                    url: matrixMLM.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'matrix_fintava_clear_failed_payouts',
                        nonce: matrixMLM.nonce
                    },
                    success: function(response) {
                        if (response && response.success) {
                            // Reload so the table re-renders without the
                            // cleared rows AND the toolbar button hides
                            // itself (server-side $failed_count drops to 0).
                            (typeof matrixMLMReload === "function" ? matrixMLMReload : function(){ window.location.reload(); })();
                        } else {
                            var msg = (response && response.data && response.data.message)
                                ? response.data.message
                                : '<?php echo esc_js(__("Could not clear failed transactions.", "matrix-mlm")); ?>';
                            alert(msg);
                            btn.prop('disabled', false).text(originalText);
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__("Network error. Please try again.", "matrix-mlm")); ?>');
                        btn.prop('disabled', false).text(originalText);
                    }
                });
            });

            }); // whenJQueryReady
        })(); // poll-for-jQuery IIFE
        </script>
        <?php
    }
}
