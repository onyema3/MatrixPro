<?php
/**
 * User Zebra Wallet Payout (via Bibimoney /Remit and /Dispense).
 *
 * Unified user-facing form for the two vendor->customer rails the
 * Zebra Wallet (Bibimoney / Nazmo) gateway exposes:
 *
 *   - "To a Zebra Wallet" — settles to the customer's Zebra wallet
 *     (IWAN / MSISDN / local reference). method='zebra'. The Approve
 *     dispatcher in Matrix_MLM_Admin::approve_withdrawal() routes
 *     these to Matrix_MLM_Zebra::remit_to_account() (POST /Remit).
 *
 *   - "To a Bank Account" — settles to a Nigerian bank account
 *     (account number + sortCode). method='zebra_bank' AND
 *     account_details is a structured JSON envelope. Dispatched to
 *     Matrix_MLM_Zebra::dispense_to_account() (POST /Dispense).
 *
 * Source of funds: the user's *Matrix wallet*. This is intentionally
 * different from the existing Fintava bank-payout flow, which sources
 * from the user's Fintava virtual wallet. The reasons for the split:
 *
 *   - Bibimoney's /Remit and /Dispense settle from the merchant's
 *     vendor wallet on Bibimoney's side, not from a per-user
 *     virtual wallet. There is no Bibimoney equivalent of a Fintava
 *     virtual NUBAN per user. So a "your Zebra balance" pre-flight
 *     would be meaningless — the merchant balance is shared across
 *     all users.
 *
 *   - The matrix_withdrawals admin-approval flow that PRs #304/#305
 *     wired the Zebra rails into is fundamentally a Matrix-wallet
 *     ledger flow: rows are pending until an operator approves,
 *     and rejecting a row refunds amount+charge to the *Matrix*
 *     wallet via the existing WD-REFUND-{id} reference scheme.
 *     Sourcing this form from anything other than the Matrix
 *     wallet would put the debit and the refund on different
 *     ledgers, which would either silently lose money on reject
 *     or require a brand-new refund path.
 *
 * Debit-on-submit: this form pre-debits the Matrix wallet at row
 * INSERT time. By the time the row is in matrix_withdrawals with
 * status='pending', the user's Matrix balance has already moved
 * by amount+charge. That's the contract reject_withdrawal()
 * already assumes (it credits amount+charge back on reject), and
 * the contract approve_withdrawal() implicitly relies on
 * (because Approve is what releases the funds off the platform
 * via /Remit or /Dispense — it doesn't debit the Matrix wallet
 * a second time).
 *
 * Charge: filterable via matrix_zebra_payout_charge, defaults to 0.
 * Bibimoney's vendor->customer rails don't carry a per-transfer
 * fee on the merchant side, so the default is no plugin-side
 * service charge either. An operator who wants to add a flat or
 * proportional charge can hook the filter without forking.
 *
 * Bank-account auto-resolve (zebra_bank rail only): reuses the
 * existing matrix_fintava_resolve_account AJAX endpoint, which
 * already runs a 3-leg Paystack/Flutterwave/Fintava parallel
 * race. The endpoint doesn't care which gateway will eventually
 * settle the row — it just verifies the NUBAN against NIBSS
 * directly. No new network code on the resolver side.
 *
 * Bank dropdown: server-rendered on first paint via
 * Matrix_MLM_Fintava::get_banks() with fallback to the bundled
 * CBN/NIBSS list. Same fallback behaviour as the Fintava bank-
 * payout form, so a Fintava /banks outage does not block the
 * Zebra bank-payout rail.
 *
 * @package MatrixMLM
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_User_Zebra_Payout {

    /**
     * Render the Zebra payout pane.
     *
     * Always called from inside the consolidated Wallet page
     * (class-matrix-user-wallet.php), wrapped in a <section
     * data-pane="zebra"> hidden by default. The page-level H2
     * is owned by the Wallet page so this method emits no
     * top-level header.
     *
     * @param int $user_id The user being rendered for.
     */
    public function render($user_id) {
        $currency_symbol = get_option('matrix_mlm_currency_symbol', '₦');

        $zebra = new Matrix_MLM_Zebra();
        if (!$zebra->is_configured()) {
            // Defense in depth: the wallet page already gates the
            // pane behind is_configured(), so reaching this branch
            // means an operator cleared credentials between the
            // page render and a pane re-render. Fail visibly
            // rather than offering a form that the server-side
            // handler will reject anyway.
            ?>
            <div class="matrix-alert matrix-alert-warning">
                <?php esc_html_e('Zebra Wallet payouts are currently unavailable. Please contact support.', 'matrix-mlm'); ?>
            </div>
            <?php
            return;
        }

        $matrix_wallet  = new Matrix_MLM_Wallet();
        $matrix_balance = $matrix_wallet->get_balance($user_id);

        // Min/max cap on a single payout. Defaults to the same
        // option keys Fintava uses so an operator who already
        // tuned them on Settings -> Financial doesn't need to
        // re-tune for this rail. The matrix_mlm_zebra_min_payout
        // and matrix_mlm_zebra_max_payout filters let an operator
        // set Zebra-specific ceilings without touching the
        // Fintava ones.
        $min_payout = (float) apply_filters(
            'matrix_mlm_zebra_min_payout',
            (float) get_option('matrix_mlm_fintava_min_payout', 1000)
        );
        $max_payout = (float) apply_filters(
            'matrix_mlm_zebra_max_payout',
            (float) get_option('matrix_mlm_fintava_max_payout', 5000000)
        );

        // Plugin-side charge. Default 0 — Bibimoney's vendor-side
        // rails don't carry a per-transfer fee, so the platform
        // doesn't need to pass one through. Operators who want
        // to add a flat or proportional service charge can hook
        // the filter (it gets the amount + the rail string so
        // both flat and percentage shapes are expressible).
        $charge_default = 0.0;

        // Bank dropdown: pre-rendered at page load time so the
        // user can pick a destination bank without waiting on a
        // Fintava /banks AJAX round-trip. Same fallback rule as
        // class-matrix-user-bank-payout.php: a /banks outage
        // drops to the bundled CBN/NIBSS list and the user can
        // still proceed.
        $banks_list            = [];
        $banks_fallback_reason = '';
        if (class_exists('Matrix_MLM_Fintava')) {
            $fintava       = new Matrix_MLM_Fintava();
            $banks_result  = $fintava->is_active() ? $fintava->get_banks() : null;
            if (is_wp_error($banks_result)) {
                $banks_list            = Matrix_MLM_Fintava::get_static_banks_fallback();
                $banks_fallback_reason = $banks_result->get_error_message();
            } elseif (is_array($banks_result)) {
                $banks_list = $banks_result;
            } else {
                // Fintava integration not active at all — still
                // render the bundled list so an admin who has
                // configured Zebra but not Fintava can still
                // process bank-payout rails.
                $banks_list = Matrix_MLM_Fintava::get_static_banks_fallback();
            }
        }

        // Existing payout history scoped to this user + Zebra.
        global $wpdb;
        $history = $wpdb->get_results($wpdb->prepare(
            "SELECT id, method, amount, charge, currency, account_details,
                    transaction_id, status, admin_note, created_at
               FROM {$wpdb->prefix}matrix_withdrawals
              WHERE user_id = %d AND gateway = %s
              ORDER BY created_at DESC
              LIMIT 20",
            (int) $user_id,
            'zebra'
        ));
        ?>
        <h3 style="margin-top:0;"><?php esc_html_e('Transfer via Zebra Wallet', 'matrix-mlm'); ?></h3>
        <p class="matrix-subtitle">
            <?php esc_html_e('Send funds to a Zebra Wallet (IWAN or MSISDN) or to a Nigerian bank account. Debited from your Matrix wallet on submit; the operator approves the row to release the payout.', 'matrix-mlm'); ?>
        </p>

        <div class="matrix-info-box">
            <p>
                <strong><?php esc_html_e('Source:', 'matrix-mlm'); ?></strong>
                <?php esc_html_e('Matrix Wallet', 'matrix-mlm'); ?>
            </p>
            <p>
                <strong><?php esc_html_e('Available Balance:', 'matrix-mlm'); ?></strong>
                <?php echo esc_html($currency_symbol . number_format($matrix_balance, 2)); ?>
            </p>
            <p>
                <strong><?php esc_html_e('Transfer Limits:', 'matrix-mlm'); ?></strong>
                <?php
                echo esc_html(sprintf(
                    '%s%s – %s%s',
                    $currency_symbol,
                    number_format($min_payout),
                    $currency_symbol,
                    number_format($max_payout)
                ));
                ?>
            </p>
        </div>

        <div class="matrix-form-card">
            <form id="matrix-zebra-payout-form" class="matrix-form">

                <!-- Rail picker -->
                <div class="matrix-form-group">
                    <label><?php esc_html_e('Send to', 'matrix-mlm'); ?></label>
                    <div class="matrix-zebra-rail-picker">
                        <label class="matrix-zebra-rail-option">
                            <input type="radio" name="zebra_rail" value="zebra" checked>
                            <span class="matrix-zebra-rail-title"><?php esc_html_e('Zebra Wallet', 'matrix-mlm'); ?></span>
                            <span class="matrix-zebra-rail-sub"><?php esc_html_e('IWAN or phone number', 'matrix-mlm'); ?></span>
                        </label>
                        <label class="matrix-zebra-rail-option">
                            <input type="radio" name="zebra_rail" value="zebra_bank">
                            <span class="matrix-zebra-rail-title"><?php esc_html_e('Bank Account', 'matrix-mlm'); ?></span>
                            <span class="matrix-zebra-rail-sub"><?php esc_html_e('Any Nigerian bank', 'matrix-mlm'); ?></span>
                        </label>
                    </div>
                </div>

                <!-- ===== Wallet rail (zebra) ===== -->
                <div class="matrix-zebra-rail-fields" data-rail="zebra">
                    <div class="matrix-form-group">
                        <label><?php esc_html_e('Customer Zebra Wallet', 'matrix-mlm'); ?></label>
                        <input type="text" name="wallet_identifier" id="zebra-wallet-identifier"
                               minlength="3" maxlength="64"
                               placeholder="BIBI500000054** or +234XXXXXXXXXX"
                               autocomplete="off">
                        <small class="description">
                            <?php esc_html_e('IWAN, MSISDN, or local reference for the destination Zebra wallet.', 'matrix-mlm'); ?>
                        </small>
                    </div>
                </div>

                <!-- ===== Bank rail (zebra_bank) ===== -->
                <div class="matrix-zebra-rail-fields" data-rail="zebra_bank" hidden>
                    <div class="matrix-form-group">
                        <label><?php esc_html_e('Select Bank', 'matrix-mlm'); ?></label>
                        <select name="bank_code" id="zebra-bank-select">
                            <?php if (empty($banks_list)): ?>
                                <option value=""><?php esc_html_e('-- No banks available, please contact support --', 'matrix-mlm'); ?></option>
                            <?php else: ?>
                                <option value=""><?php esc_html_e('-- Select Bank --', 'matrix-mlm'); ?></option>
                                <?php foreach ($banks_list as $bank):
                                    if (empty($bank['code']) || empty($bank['name'])) { continue; }
                                ?>
                                    <option value="<?php echo esc_attr($bank['code']); ?>" data-name="<?php echo esc_attr($bank['name']); ?>">
                                        <?php echo esc_html($bank['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <?php if ($banks_fallback_reason !== ''): ?>
                            <div class="matrix-bank-fallback-note" style="margin-top:6px;font-size:12px;line-height:1.4;color:#92400e;">
                                <div><?php esc_html_e('Note: using built-in Nigerian banks list. /banks lookup is unreachable from your account.', 'matrix-mlm'); ?></div>
                                <div style="margin-top:4px;font-style:italic;word-break:break-word;">
                                    <?php esc_html_e('Reason:', 'matrix-mlm'); ?> <?php echo esc_html($banks_fallback_reason); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="matrix-form-group">
                        <label><?php esc_html_e('Account Number', 'matrix-mlm'); ?></label>
                        <input type="text" name="account_number" id="zebra-account-number"
                               minlength="6" maxlength="20" pattern="\d{6,20}"
                               placeholder="<?php esc_attr_e('Enter account number (6-20 digits)', 'matrix-mlm'); ?>"
                               autocomplete="off">
                    </div>

                    <div class="matrix-form-group" id="zebra-account-name-group" style="display:none;">
                        <label><?php esc_html_e('Account Name', 'matrix-mlm'); ?></label>
                        <div class="matrix-resolved-account">
                            <input type="text" name="account_name" id="zebra-account-name" readonly class="matrix-input-success">
                            <span class="matrix-verify-badge" id="zebra-verify-badge">&#10003; <?php esc_html_e('Verified', 'matrix-mlm'); ?></span>
                        </div>
                    </div>

                    <div id="zebra-resolving" style="display:none;" class="matrix-loading-text">
                        <span class="matrix-spinner"></span> <?php esc_html_e('Verifying account...', 'matrix-mlm'); ?>
                    </div>

                    <div id="zebra-verify-failed" style="display:none;" class="matrix-alert matrix-alert-warning" role="alert" aria-live="polite">
                        <div id="zebra-verify-failed-msg" style="margin-bottom:8px;"></div>
                        <button type="button" class="matrix-btn matrix-btn-secondary matrix-btn-sm" id="zebra-manual-override">
                            <?php esc_html_e('Continue without verification &rarr;', 'matrix-mlm'); ?>
                        </button>
                    </div>

                    <input type="hidden" name="bank_name" id="zebra-bank-name" value="">
                </div>

                <!-- ===== Shared fields (both rails) ===== -->
                <div class="matrix-form-group">
                    <label><?php esc_html_e('Amount', 'matrix-mlm'); ?> (<?php echo esc_html($currency_symbol); ?>)</label>
                    <input type="number" name="amount" id="zebra-amount"
                           min="<?php echo esc_attr($min_payout); ?>"
                           max="<?php echo esc_attr(min($max_payout, $matrix_balance)); ?>"
                           step="0.01" required
                           placeholder="<?php echo esc_attr(sprintf(
                               /* translators: 1: minimum amount, 2: maximum amount */
                               __('Min %1$s, Max %2$s', 'matrix-mlm'),
                               number_format($min_payout),
                               number_format(min($max_payout, $matrix_balance), 2)
                           )); ?>">
                </div>

                <div class="matrix-form-group">
                    <label><?php esc_html_e('Narration (Optional)', 'matrix-mlm'); ?></label>
                    <input type="text" name="narration" maxlength="100"
                           placeholder="<?php esc_attr_e('e.g. Salary, Savings, etc.', 'matrix-mlm'); ?>">
                </div>

                <?php
                // Transaction PIN — same path key 'bank' as the
                // Fintava bank-payout form so admin gating applies
                // uniformly across external-rail withdrawals.
                // render_field returns '' when the gate isn't
                // active, so this is a no-op for installs that
                // haven't opted in.
                echo Matrix_MLM_Transaction_Pin::render_field((int) $user_id, 'bank', 'zebra');
                ?>

                <button type="submit" class="matrix-btn matrix-btn-primary matrix-btn-block" id="zebra-submit-btn" disabled>
                    <?php esc_html_e('Submit Payout', 'matrix-mlm'); ?>
                </button>
                <div id="zebra-submit-status" class="matrix-submit-status" role="status" aria-live="polite"></div>
            </form>
        </div>

        <!-- History -->
        <h4 style="margin-top:24px;"><?php esc_html_e('Zebra Payout History', 'matrix-mlm'); ?></h4>
        <?php if (empty($history)): ?>
            <div class="matrix-alert matrix-alert-info">
                <?php esc_html_e('No Zebra payouts yet.', 'matrix-mlm'); ?>
            </div>
        <?php else: ?>
            <table class="matrix-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Date', 'matrix-mlm'); ?></th>
                        <th><?php esc_html_e('Rail', 'matrix-mlm'); ?></th>
                        <th><?php esc_html_e('Destination', 'matrix-mlm'); ?></th>
                        <th><?php esc_html_e('Amount', 'matrix-mlm'); ?></th>
                        <th><?php esc_html_e('Status', 'matrix-mlm'); ?></th>
                        <th><?php esc_html_e('Reference', 'matrix-mlm'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $row): ?>
                        <?php
                        $rail        = strtolower((string) $row->method);
                        $rail_label  = ($rail === 'zebra_bank')
                            ? __('Bank', 'matrix-mlm')
                            : __('Zebra Wallet', 'matrix-mlm');
                        $destination = self::format_destination_for_display($row);
                        ?>
                        <tr>
                            <td><?php echo esc_html(date('M d, Y H:i', strtotime($row->created_at))); ?></td>
                            <td><?php echo esc_html($rail_label); ?></td>
                            <td><?php echo esc_html($destination); ?></td>
                            <td><?php echo esc_html($currency_symbol . number_format((float) $row->amount, 2)); ?></td>
                            <td>
                                <span class="matrix-badge matrix-badge-<?php echo esc_attr($row->status); ?>">
                                    <?php echo esc_html(ucwords(str_replace('_', ' ', $row->status))); ?>
                                </span>
                                <?php if ($row->status === 'rejected' && !empty($row->admin_note)): ?>
                                    <div class="matrix-payout-reason" title="<?php echo esc_attr($row->admin_note); ?>">
                                        <?php echo esc_html($row->admin_note); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row->transaction_id)): ?>
                                    <small><code><?php echo esc_html($row->transaction_id); ?></code></small>
                                <?php else: ?>
                                    <small style="color:#6b7280;"><?php esc_html_e('— not set —', 'matrix-mlm'); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <style>
        .matrix-zebra-rail-picker { display:flex; gap:10px; flex-wrap:wrap; }
        .matrix-zebra-rail-option {
            flex:1 1 220px; min-width:220px;
            display:flex; flex-direction:column; gap:2px;
            padding:10px 12px; border:1px solid #d1d5db; border-radius:8px;
            cursor:pointer; background:#fff;
        }
        .matrix-zebra-rail-option:has(input:checked) { border-color:#4f46e5; background:#eef2ff; }
        .matrix-zebra-rail-title { font-weight:600; }
        .matrix-zebra-rail-sub { font-size:12px; color:#6b7280; }
        .matrix-resolved-account { position:relative; }
        .matrix-input-success { background:#ecfdf5 !important; border-color:#10b981 !important; }
        .matrix-input-warning { background:#fffbeb !important; border-color:#f59e0b !important; }
        .matrix-verify-badge { position:absolute; right:12px; top:50%; transform:translateY(-50%); color:#10b981; font-weight:600; font-size:13px; }
        .matrix-loading-text { padding:8px 0; color:#6b7280; font-size:13px; display:flex; align-items:center; gap:8px; }
        .matrix-spinner { width:16px; height:16px; border:2px solid #e5e7eb; border-top-color:#4f46e5; border-radius:50%; animation:matrix-spin 0.6s linear infinite; display:inline-block; }
        @keyframes matrix-spin { to { transform: rotate(360deg); } }
        .matrix-payout-reason { margin-top:6px; padding:6px 8px; background:#fef2f2; border-left:3px solid #dc2626; border-radius:3px; font-size:11px; line-height:1.4; color:#991b1b; word-break:break-word; max-width:280px; }
        .matrix-submit-status { margin-top:8px; min-height:18px; font-size:13px; line-height:1.4; color:#92400e; text-align:center; }
        .matrix-submit-status:empty { display:none; }
        .matrix-badge-processing { background:#eff6ff; color:#1e40af; }
        </style>

        <script>
        // Same poll-for-jQuery wrapper class-matrix-user-bank-payout.php
        // uses (see the long comment block there for the rationale).
        // Keeps this pane usable on themes that footer-load jQuery
        // and on optimisers that swap body content via fetch.
        (function() {
            var attempts = 0;
            var maxAttempts = 200;

            function whenJQueryReady(cb) {
                if (typeof window.jQuery !== 'undefined' && typeof window.jQuery.fn !== 'undefined') {
                    cb(window.jQuery);
                    return;
                }
                if (++attempts > maxAttempts) {
                    var form = document.getElementById('matrix-zebra-payout-form');
                    if (form) {
                        var inputs = form.querySelectorAll('input, select, button, textarea');
                        for (var i = 0; i < inputs.length; i++) { inputs[i].disabled = true; }
                    }
                    if (window.console && console.error) {
                        console.error('[Matrix MLM] jQuery not loaded after 10s; Zebra payout pane init aborted.');
                    }
                    return;
                }
                setTimeout(function() { whenJQueryReady(cb); }, 50);
            }

            whenJQueryReady(function($) {
                'use strict';

                if (typeof matrixMLM === 'undefined' || !matrixMLM.ajaxUrl) {
                    var $f = $('#matrix-zebra-payout-form');
                    $f.find(':input').prop('disabled', true);
                    return;
                }

                var currency       = '<?php echo esc_js($currency_symbol); ?>';
                var matrixBalance  = <?php echo (float) $matrix_balance; ?>;
                var minPayout      = <?php echo (float) $min_payout; ?>;
                var maxPayout      = <?php echo (float) $max_payout; ?>;
                var chargeDefault  = <?php echo (float) $charge_default; ?>;

                var accountVerified = false;
                var manualOverride  = false;

                function getRail() {
                    return $('#matrix-zebra-payout-form input[name="zebra_rail"]:checked').val() || 'zebra';
                }

                function showRailFields() {
                    var rail = getRail();
                    $('#matrix-zebra-payout-form .matrix-zebra-rail-fields').each(function() {
                        var match = $(this).attr('data-rail') === rail;
                        if (match) { $(this).removeAttr('hidden'); }
                        else       { $(this).attr('hidden', 'hidden'); }
                    });
                    // Switching rails resets verification state so a
                    // stale "verified Bank" doesn't ride into a Zebra
                    // Wallet submit (or vice versa).
                    accountVerified = (rail === 'zebra'); // wallet rail has no auto-verify, gate on identifier presence
                    manualOverride  = false;
                    $('#zebra-account-name-group').hide();
                    $('#zebra-verify-failed').hide();
                    updateSubmitButton();
                }

                $(document).on('change', '#matrix-zebra-payout-form input[name="zebra_rail"]', showRailFields);

                // ----- Wallet rail (identifier presence only) -----
                $(document).on('input', '#zebra-wallet-identifier', function() {
                    if (getRail() === 'zebra') {
                        var v = ($(this).val() || '').trim();
                        // accountVerified for the wallet rail is "is
                        // there a non-empty identifier of plausible
                        // length" — the platform itself validates the
                        // shape on dispatch, we just gate the submit
                        // button so the UX matches the bank rail.
                        accountVerified = v.length >= 3 && v.length <= 64;
                        updateSubmitButton();
                    }
                });

                // ----- Bank rail (auto-resolve via existing 3-leg race) -----
                var resolveTimeout;
                function tryResolveAccount() {
                    if (getRail() !== 'zebra_bank') { return; }
                    var accNum   = ($('#zebra-account-number').val() || '').trim();
                    var bankCode = $('#zebra-bank-select').val();
                    if (accNum.length >= 10 && bankCode) {
                        clearTimeout(resolveTimeout);
                        updateSubmitButton();
                        resolveTimeout = setTimeout(function() {
                            resolveAccount(accNum, bankCode);
                        }, 500);
                    } else {
                        $('#zebra-account-name-group').hide();
                        $('#zebra-verify-failed').hide();
                        accountVerified = false;
                        manualOverride  = false;
                        updateSubmitButton();
                    }
                }

                $(document).on('input', '#zebra-account-number', tryResolveAccount);
                $(document).on('change', '#zebra-bank-select', function() {
                    $('#zebra-bank-name').val($(this).find(':selected').data('name') || '');
                    tryResolveAccount();
                });

                function resolveAccount(accountNumber, bankCode) {
                    $('#zebra-resolving').show();
                    $('#zebra-verify-failed').hide();
                    $('#zebra-account-name-group').hide();
                    accountVerified = false;
                    manualOverride  = false;
                    updateSubmitButton();

                    $.ajax({
                        url: matrixMLM.ajaxUrl,
                        type: 'POST',
                        timeout: 35000,
                        data: {
                            action: 'matrix_fintava_resolve_account',
                            nonce: matrixMLM.nonce,
                            account_number: accountNumber,
                            bank_code: bankCode,
                            bank_name: ($('#zebra-bank-select').find(':selected').data('name') || '')
                        },
                        success: function(response) {
                            $('#zebra-resolving').hide();
                            var resolvedName = (response && response.success && response.data && typeof response.data.account_name === 'string')
                                ? response.data.account_name.trim()
                                : '';
                            if (response.success && resolvedName !== '') {
                                $('#zebra-account-name')
                                    .val(response.data.account_name)
                                    .prop('readonly', true)
                                    .removeClass('matrix-input-warning')
                                    .addClass('matrix-input-success');
                                $('#zebra-verify-badge')
                                    .text('\u2713 <?php echo esc_js(__('Verified', 'matrix-mlm')); ?>')
                                    .css('color', '');
                                $('#zebra-account-name-group').show();
                                accountVerified = true;
                                manualOverride  = false;
                            } else {
                                var data = response && response.data ? response.data : {};
                                var msg = data.message || '<?php echo esc_js(__('Account verification failed', 'matrix-mlm')); ?>';
                                var allowOverride = !!data.allow_manual_override;
                                if (response.success) {
                                    msg = '<?php echo esc_js(__('Could not auto-verify the account name. Continue without verification and type the holder name.', 'matrix-mlm')); ?>';
                                    allowOverride = true;
                                }
                                $('#zebra-verify-failed-msg').text(msg);
                                if (allowOverride) {
                                    $('#zebra-verify-failed').show();
                                } else {
                                    $('#zebra-verify-failed').show();
                                }
                                accountVerified = false;
                                manualOverride  = false;
                            }
                            updateSubmitButton();
                        },
                        error: function(xhr, status) {
                            $('#zebra-resolving').hide();
                            var msg = status === 'timeout'
                                ? '<?php echo esc_js(__('Verification timed out — please try again or continue without verification.', 'matrix-mlm')); ?>'
                                : '<?php echo esc_js(__('Network error during verification.', 'matrix-mlm')); ?>';
                            $('#zebra-verify-failed-msg').text(msg);
                            $('#zebra-verify-failed').show();
                            accountVerified = false;
                            manualOverride  = false;
                            updateSubmitButton();
                        }
                    });
                }

                $(document).on('click', '#zebra-manual-override', function() {
                    $('#zebra-verify-failed').hide();
                    $('#zebra-account-name')
                        .val('')
                        .prop('readonly', false)
                        .removeClass('matrix-input-success')
                        .addClass('matrix-input-warning')
                        .attr('placeholder', '<?php echo esc_js(__('Type the account holder name', 'matrix-mlm')); ?>')
                        .focus();
                    $('#zebra-verify-badge')
                        .text('<?php echo esc_js(__('Manual entry', 'matrix-mlm')); ?>')
                        .css('color', '#92400e');
                    $('#zebra-account-name-group').show();
                    accountVerified = false;
                    manualOverride  = true;
                    updateSubmitButton();
                });

                $(document).on('input', '#zebra-account-name', function() {
                    if (manualOverride) {
                        accountVerified = $(this).val().trim().length > 0;
                        updateSubmitButton();
                    }
                });

                $(document).on('input', '#zebra-amount', updateSubmitButton);

                function updateSubmitButton() {
                    var rail   = getRail();
                    var amount = parseFloat($('#zebra-amount').val()) || 0;
                    var charge = chargeDefault;
                    var total  = amount + charge;
                    var $status = $('#zebra-submit-status');
                    var reason = '';

                    if (rail === 'zebra') {
                        var ident = ($('#zebra-wallet-identifier').val() || '').trim();
                        if (ident.length < 3) {
                            reason = '<?php echo esc_js(__('Enter the destination Zebra wallet (IWAN or phone).', 'matrix-mlm')); ?>';
                        }
                    } else {
                        var bankCode = $('#zebra-bank-select').val();
                        var accNum   = ($('#zebra-account-number').val() || '').trim();
                        if (!bankCode) {
                            reason = '<?php echo esc_js(__('Select a bank to continue.', 'matrix-mlm')); ?>';
                        } else if (accNum.length < 6) {
                            reason = '<?php echo esc_js(__('Enter an account number (6+ digits).', 'matrix-mlm')); ?>';
                        } else if (!accountVerified) {
                            if (manualOverride) {
                                reason = '<?php echo esc_js(__('Type the account holder name to continue.', 'matrix-mlm')); ?>';
                            } else if ($('#zebra-resolving').is(':visible')) {
                                reason = '<?php echo esc_js(__('Verifying account…', 'matrix-mlm')); ?>';
                            } else if ($('#zebra-verify-failed').is(':visible')) {
                                reason = '<?php echo esc_js(__('Click "Continue without verification" above to proceed.', 'matrix-mlm')); ?>';
                            } else {
                                reason = '<?php echo esc_js(__('Waiting on account verification…', 'matrix-mlm')); ?>';
                            }
                        }
                    }

                    if (reason === '') {
                        if (amount <= 0) {
                            reason = '<?php echo esc_js(__('Enter the amount to transfer.', 'matrix-mlm')); ?>';
                        } else if (amount < minPayout) {
                            reason = '<?php echo esc_js(__('Amount is below the minimum.', 'matrix-mlm')); ?>';
                        } else if (amount > maxPayout) {
                            reason = '<?php echo esc_js(__('Amount exceeds the per-transfer maximum.', 'matrix-mlm')); ?>';
                        } else if (total > matrixBalance) {
                            reason = '<?php echo esc_js(__('Amount exceeds your Matrix wallet balance.', 'matrix-mlm')); ?>';
                        }
                    }

                    $status.text(reason);
                    $('#zebra-submit-btn').prop('disabled', reason !== '');
                }

                // Initial paint.
                showRailFields();
                updateSubmitButton();

                $(document).on('submit', '#matrix-zebra-payout-form', function(e) {
                    e.preventDefault();

                    var rail   = getRail();
                    var amount = parseFloat($('#zebra-amount').val());
                    var label, dest;

                    if (rail === 'zebra') {
                        dest  = ($('#zebra-wallet-identifier').val() || '').trim();
                        label = dest;
                    } else {
                        var name = $('#zebra-account-name').val();
                        var bank = $('#zebra-bank-name').val();
                        var num  = $('#zebra-account-number').val();
                        label = name + ' (' + bank + ' · ' + num + ')';
                    }

                    if (!confirm('<?php echo esc_js(__('Send', 'matrix-mlm')); ?> ' +
                                 currency + amount.toLocaleString() +
                                 ' <?php echo esc_js(__('to', 'matrix-mlm')); ?> ' + label + '?')) {
                        return;
                    }

                    var btn = $('#zebra-submit-btn');
                    var originalText = btn.text();
                    btn.prop('disabled', true).text('<?php echo esc_js(__('Processing...', 'matrix-mlm')); ?>');

                    var payload = {
                        action: 'matrix_zebra_initiate_payout',
                        nonce: matrixMLM.nonce,
                        rail: rail,
                        amount: amount,
                        narration: $('#matrix-zebra-payout-form [name="narration"]').val() || '',
                        transaction_pin: ($('#matrix-zebra-payout-form [name="transaction_pin"]').val() || '')
                    };

                    if (rail === 'zebra') {
                        payload.wallet_identifier = ($('#zebra-wallet-identifier').val() || '').trim();
                    } else {
                        payload.bank_code      = $('#zebra-bank-select').val();
                        payload.bank_name      = $('#zebra-bank-name').val();
                        payload.account_number = $('#zebra-account-number').val();
                        payload.account_name   = $('#zebra-account-name').val();
                    }

                    $.ajax({
                        url: matrixMLM.ajaxUrl,
                        type: 'POST',
                        data: payload,
                        success: function(response) {
                            if (response && response.success) {
                                alert((response.data && response.data.message) ||
                                      '<?php echo esc_js(__('Payout submitted for approval.', 'matrix-mlm')); ?>');
                                (typeof matrixMLMReload === 'function' ? matrixMLMReload : function(){ window.location.reload(); })();
                            } else {
                                alert((response && response.data && response.data.message) ||
                                      '<?php echo esc_js(__('Payout failed. Please try again.', 'matrix-mlm')); ?>');
                                btn.prop('disabled', false).text(originalText);
                            }
                        },
                        error: function() {
                            alert('<?php echo esc_js(__('Network error. Please try again.', 'matrix-mlm')); ?>');
                            btn.prop('disabled', false).text(originalText);
                        }
                    });
                });

            }); // whenJQueryReady
        })();
        </script>
        <?php
    }

    /**
     * Render the destination column for the history table.
     *
     * Wallet rows show the raw identifier; bank rows decode the
     * structured envelope and present "BANKNAME · ACCNO · NAME"
     * the same way the admin Withdrawals listing does (see
     * Matrix_MLM_Admin_Withdrawals::format_account_details).
     * Falls back to the raw column when the row predates the
     * envelope shape (defensive — there is no path that produces
     * an unstructured zebra_bank row today, but the table is
     * shared with admin-tooling-created rows that may diverge).
     */
    private static function format_destination_for_display($row) {
        $raw    = (string) ($row->account_details ?? '');
        $method = strtolower((string) ($row->method ?? ''));
        if ($method !== 'zebra_bank' || $raw === '' || $raw[0] !== '{') {
            return $raw;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || ($decoded['type'] ?? '') !== 'bank') {
            return $raw;
        }
        $parts = [];
        if (!empty($decoded['bank_name']))      { $parts[] = (string) $decoded['bank_name']; }
        if (!empty($decoded['account_number'])) { $parts[] = (string) $decoded['account_number']; }
        if (!empty($decoded['account_name']))   { $parts[] = (string) $decoded['account_name']; }
        return implode(' · ', $parts);
    }
}
