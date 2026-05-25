<?php
/**
 * User Virtual Wallet (via Fintava)
 * 
 * The Fintava virtual wallet is a separate external bank account.
 * Users can ONLY transfer funds FROM their Matrix wallet TO their Fintava wallet.
 * This is a cash-out mechanism - moving earnings to a real bank account.
 * 
 * Flow: Matrix Wallet (internal) --> Fintava Wallet (external bank account)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_User_Virtual_Wallet {

    public function render($user_id) {
        $fintava = new Matrix_MLM_Fintava();
        $is_active = $fintava->is_active();
        $wallet = $fintava->get_user_wallet($user_id);
        $user = get_userdata($user_id);
        $meta = Matrix_MLM_User::get_meta($user_id);
        $matrix_wallet = new Matrix_MLM_Wallet();
        $matrix_balance = $matrix_wallet->get_balance($user_id);
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        ?>
        <h2><?php _e('Fintava Virtual Wallet', 'matrix-mlm'); ?></h2>
        <p class="matrix-subtitle"><?php _e('Your external bank account powered by Fintava. Transfer funds from your Matrix wallet to your Fintava wallet to cash out.', 'matrix-mlm'); ?></p>

        <?php if (!$is_active): ?>
        <div class="matrix-alert matrix-alert-warning">
            <?php _e('Fintava wallet service is currently unavailable. Please contact support.', 'matrix-mlm'); ?>
        </div>
        <?php elseif ($wallet): ?>
            <!-- Show wallet details + transfer form -->
            <?php $this->render_wallet_with_transfer($wallet, $user_id, $matrix_balance, $currency); ?>
        <?php else: ?>
            <!-- Create wallet form -->
            <?php $this->render_create_form($user, $meta); ?>
        <?php endif; ?>

        <style>
        .matrix-subtitle { color: #6b7280; margin: -10px 0 20px; font-size: 14px; }
        .matrix-two-wallets { display: grid; grid-template-columns: 1fr auto 1fr; gap: 16px; align-items: center; margin-bottom: 24px; }
        .matrix-wallet-box { background: #fff; border: 2px solid #e5e7eb; border-radius: 12px; padding: 20px; text-align: center; }
        .matrix-wallet-box.source { border-color: #4f46e5; background: #eef2ff; }
        .matrix-wallet-box.destination { border-color: #10b981; background: #ecfdf5; }
        .matrix-wallet-box h4 { margin: 0 0 4px; font-size: 14px; color: #6b7280; }
        .matrix-wallet-box .wallet-name { font-size: 18px; font-weight: 700; margin: 0 0 8px; }
        .matrix-wallet-box.source .wallet-name { color: #4f46e5; }
        .matrix-wallet-box.destination .wallet-name { color: #059669; }
        .matrix-wallet-box .wallet-balance { font-size: 24px; font-weight: 700; }
        .matrix-arrow-indicator { font-size: 32px; color: #9ca3af; text-align: center; }
        .matrix-virtual-wallet-card {
            background: linear-gradient(135deg, #064e3b 0%, #065f46 100%);
            border-radius: 16px;
            padding: 28px 32px;
            color: #fff;
            position: relative;
            overflow: hidden;
            margin-bottom: 24px;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.15);
        }
        .matrix-virtual-wallet-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -30%;
            width: 250px;
            height: 250px;
            background: rgba(16, 185, 129, 0.15);
            border-radius: 50%;
        }
        .matrix-wallet-label { font-size: 11px; text-transform: uppercase; letter-spacing: 1.5px; color: #a7f3d0; margin-bottom: 4px; }
        .matrix-wallet-account-number { font-size: 24px; font-weight: 700; letter-spacing: 2px; margin-bottom: 16px; font-family: 'Courier New', monospace; }
        .matrix-wallet-details-row { display: flex; justify-content: space-between; gap: 20px; position: relative; z-index: 1; }
        .matrix-wallet-detail { flex: 1; }
        .matrix-wallet-detail-value { font-size: 14px; font-weight: 600; }
        .matrix-wallet-status { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; text-transform: uppercase; }
        .matrix-wallet-status.active { background: rgba(16, 185, 129, 0.3); color: #34d399; }
        .matrix-wallet-copy-btn { background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3); color: #fff; padding: 4px 12px; border-radius: 6px; cursor: pointer; font-size: 11px; margin-left: 10px; }
        .matrix-wallet-copy-btn:hover { background: rgba(255,255,255,0.25); }
        .matrix-fintava-balance-row { margin: 4px 0 18px; padding: 12px 0 14px; border-top: 1px solid rgba(255,255,255,0.15); border-bottom: 1px solid rgba(255,255,255,0.15); position: relative; z-index: 1; }
        .matrix-fintava-balance-value { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; font-size: 22px; font-weight: 700; letter-spacing: 0.5px; margin-top: 2px; }
        #matrix-fintava-refresh-balance { font-size: 11px; padding: 4px 10px; }
        .matrix-transfer-section { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 24px; margin-top: 24px; }
        .matrix-transfer-section h3 { margin-top: 0; color: #1f2937; }
        .matrix-transfer-note { background: #fefce8; border: 1px solid #fde68a; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; font-size: 13px; color: #92400e; }
        .matrix-create-wallet-intro { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 20px 24px; margin-bottom: 24px; }
        .matrix-create-wallet-intro h3 { color: #1e40af; margin: 0 0 8px; }
        .matrix-create-wallet-intro p { color: #1e40af; font-size: 14px; margin: 4px 0; }
        @media (max-width: 768px) {
            .matrix-two-wallets { grid-template-columns: 1fr; }
            .matrix-arrow-indicator { transform: rotate(90deg); }
        }
        </style>
        <?php
    }

    /**
     * Render wallet details with transfer form
     */
    private function render_wallet_with_transfer($wallet, $user_id, $matrix_balance, $currency) {
        $min_transfer = floatval(get_option('matrix_mlm_fintava_min_payout', 1000));
        $max_transfer = floatval(get_option('matrix_mlm_fintava_max_payout', 5000000));
        $charge_type = get_option('matrix_mlm_fintava_charge_type', 'fixed');
        $charge_value = floatval(get_option('matrix_mlm_fintava_charge_value', 50));

        // Get transfer history to this wallet
        $fintava = new Matrix_MLM_Fintava();
        $payouts = $fintava->get_user_payouts($user_id, 10);

        // Pull the live Fintava balance for this wallet. If Fintava is unreachable
        // or the response is malformed, fall through gracefully with a placeholder
        // and a hint explaining how to fix it — never break the page.
        //
        // Two distinct failure modes:
        //   - missing_wallet_id: the local row never recorded the Fintava wallet ID
        //     (typical when the wallet was linked manually with only the account
        //     number filled in). Only an admin can fix this via Migration → Link.
        //   - api_error: wallet_id is on file but Fintava returned an error.
        $fintava_balance_value    = null;
        $fintava_balance_currency = $wallet->currency ?? 'NGN';
        $fintava_balance_error    = '';
        $fintava_balance_reason   = ''; // 'missing_wallet_id' | 'api_error' | ''

        // Auto-resolve is disabled: the /wallet/details endpoint is not
        // available on all Fintava tiers. Users can enter their wallet ID
        // manually via the "Verify & Save" input below.

        if (!empty($wallet->wallet_id)) {
            $balance_result = $fintava->get_virtual_wallet_balance($wallet->wallet_id, $wallet->account_number, $wallet->customer_id ?? null);
            if (is_wp_error($balance_result)) {
                // Defense-in-depth: although Matrix_MLM_Fintava already normalizes
                // API messages to strings before storing them in WP_Error, a
                // third-party plugin or filter could still inject an array here.
                // Run the message through the same normalizer so we never hand an
                // array to esc_attr() / sprintf() downstream.
                $fintava_balance_error  = Matrix_MLM_Fintava::normalize_api_message(
                    $balance_result->get_error_message(),
                    __('Balance is unavailable.', 'matrix-mlm')
                );
                $fintava_balance_reason = 'api_error';
            } else {
                $fintava_balance_value    = $balance_result['available_balance'];
                $fintava_balance_currency = $balance_result['currency'];
            }
        } else {
            $fintava_balance_error  = __('Balance is unavailable.', 'matrix-mlm');
            $fintava_balance_reason = 'missing_wallet_id';
        }
        $is_admin_viewing = current_user_can('manage_matrix_mlm');

        // TEMPORARY admin-only diagnostic. Renders the raw Fintava balance
        // response in a visible-on-page collapsible block so an operator can
        // compare what Fintava actually returns against what we display.
        //
        // We deliberately use a <details>/<pre> element instead of an HTML
        // comment because aggressive caching/minification plugins (LiteSpeed
        // Cache, Autoptimize, WP Rocket) strip HTML comments by default —
        // which would silently swallow our diagnostic. A real DOM element
        // survives every HTML minifier we've seen.
        //
        // Gating on capability + wallet_id keeps the payload out of the
        // public source for ordinary users, and the <details> is closed by
        // default so admins viewing their own dashboard aren't visually
        // disrupted. Remove once the displayed balance is verified correct
        // in production. Pairs with Matrix_MLM_Fintava::debug_balance_raw().
        if ($is_admin_viewing && !empty($wallet->wallet_id)) {
            $debug_raw  = $fintava->debug_balance_raw($wallet->wallet_id);
            $debug_json = wp_json_encode($debug_raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            ?>
            <details class="matrix-fintava-balance-debug" style="margin:16px 0;padding:12px 16px;background:#fef3c7;border:1px solid #f59e0b;border-radius:6px;font-size:12px;color:#92400e;">
                <summary style="cursor:pointer;font-weight:600;"><?php esc_html_e('FINTAVA_BALANCE_DEBUG (admin only — temporary diagnostic)', 'matrix-mlm'); ?></summary>
                <p style="margin:8px 0 4px;font-size:11px;color:#78350f;">
                    <?php esc_html_e('Raw response from GET /customer/wallet/balance/{walletId}. Copy this entire block and send it to support so we can correct the displayed balance.', 'matrix-mlm'); ?>
                </p>
                <pre id="matrix-fintava-balance-debug-pre" style="margin:8px 0 0;padding:10px;background:#fff;border:1px solid #fcd34d;border-radius:4px;overflow-x:auto;white-space:pre-wrap;word-break:break-all;font-family:Menlo,Consolas,monospace;font-size:11px;color:#1f2937;line-height:1.4;"><?php echo esc_html($debug_json); ?></pre>
            </details>
            <?php
        }
        ?>

        <!-- Visual: Matrix Wallet → Fintava Wallet -->
        <div class="matrix-two-wallets">
            <div class="matrix-wallet-box source">
                <h4><?php _e('Source', 'matrix-mlm'); ?></h4>
                <p class="wallet-name"><?php _e('Matrix Wallet', 'matrix-mlm'); ?></p>
                <div class="wallet-balance"><?php echo $currency . number_format($matrix_balance, 2); ?></div>
                <small style="color: #6366f1;"><?php _e('Your internal earnings wallet', 'matrix-mlm'); ?></small>
            </div>
            <div class="matrix-arrow-indicator">&rarr;</div>
            <div class="matrix-wallet-box destination">
                <h4><?php _e('Destination', 'matrix-mlm'); ?></h4>
                <p class="wallet-name"><?php _e('Fintava Wallet', 'matrix-mlm'); ?></p>
                <div class="wallet-balance" style="font-size: 16px; color: #059669;"><?php echo esc_html($wallet->account_number); ?></div>
                <small style="color: #059669;"><?php echo esc_html($wallet->bank_name); ?></small>
            </div>
        </div>

        <!-- Fintava Wallet Card -->
        <div class="matrix-virtual-wallet-card">
            <div class="matrix-wallet-label"><?php _e('Fintava Wallet Account', 'matrix-mlm'); ?></div>
            <div class="matrix-wallet-account-number">
                <?php echo esc_html($wallet->account_number); ?>
                <button class="matrix-wallet-copy-btn" onclick="navigator.clipboard.writeText('<?php echo esc_js($wallet->account_number); ?>'); this.textContent='Copied!';">
                    <?php _e('Copy', 'matrix-mlm'); ?>
                </button>
            </div>
            <div class="matrix-fintava-balance-row">
                <div class="matrix-wallet-label"><?php _e('Account Balance', 'matrix-mlm'); ?></div>
                <div class="matrix-fintava-balance-value">
                    <span id="matrix-fintava-balance-amount">
                        <?php
                        if ($fintava_balance_value !== null) {
                            echo esc_html(($fintava_balance_currency === 'NGN' ? '₦' : ($fintava_balance_currency . ' ')) . number_format($fintava_balance_value, 2));
                        } else {
                            echo '<span title="' . esc_attr($fintava_balance_error) . '">&mdash;</span>';
                        }
                        ?>
                    </span>
                    <button type="button" class="matrix-wallet-copy-btn" id="matrix-fintava-refresh-balance" title="<?php esc_attr_e('Refresh from Fintava', 'matrix-mlm'); ?>">
                        <?php _e('Refresh', 'matrix-mlm'); ?>
                    </button>
                    <?php if ($fintava_balance_error): ?>
                        <?php if ($fintava_balance_reason === 'missing_wallet_id'): ?>
                            <small style="display:block;margin-top:6px;color:#fde68a;">
                                <?php _e('Balance lookup needs your Fintava Wallet ID. Add it below — we\'ll verify it against the live API and only save it if the account number matches yours.', 'matrix-mlm'); ?>
                            </small>
                            <div class="matrix-set-wallet-id-form" style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                                <input type="text" id="matrix-fintava-wallet-id-input" placeholder="<?php esc_attr_e('Fintava Wallet ID', 'matrix-mlm'); ?>" style="flex:1;min-width:220px;padding:6px 10px;border:1px solid rgba(255,255,255,0.4);background:rgba(255,255,255,0.1);color:#fff;border-radius:6px;font-size:13px;">
                                <button type="button" class="matrix-wallet-copy-btn" id="matrix-fintava-set-wallet-id-btn" style="font-size:12px;padding:6px 12px;">
                                    <?php _e('Verify & Save', 'matrix-mlm'); ?>
                                </button>
                                <span id="matrix-fintava-set-wallet-id-status" style="display:block;width:100%;font-size:12px;margin-top:4px;"></span>
                            </div>
                        <?php else: ?>
                            <small style="display:block;margin-top:4px;color:#fecaca;">
                                <?php echo esc_html($fintava_balance_error); ?>
                            </small>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="matrix-wallet-details-row">
                <div class="matrix-wallet-detail">
                    <div class="matrix-wallet-label"><?php _e('Account Name', 'matrix-mlm'); ?></div>
                    <div class="matrix-wallet-detail-value"><?php echo esc_html($wallet->account_name); ?></div>
                </div>
                <div class="matrix-wallet-detail">
                    <div class="matrix-wallet-label"><?php _e('Bank', 'matrix-mlm'); ?></div>
                    <div class="matrix-wallet-detail-value"><?php echo esc_html($wallet->bank_name); ?></div>
                </div>
                <div class="matrix-wallet-detail">
                    <div class="matrix-wallet-label"><?php _e('Status', 'matrix-mlm'); ?></div>
                    <div><span class="matrix-wallet-status <?php echo esc_attr($wallet->status); ?>"><?php echo esc_html(ucfirst($wallet->status)); ?></span></div>
                </div>
            </div>
        </div>

        <!-- Transfer Form: Matrix Wallet → Fintava Wallet -->
        <div class="matrix-transfer-section">
            <h3><?php _e('Transfer to Fintava Wallet', 'matrix-mlm'); ?></h3>
            <div class="matrix-transfer-note">
                <?php _e('Funds will be deducted from your Matrix wallet and sent to your Fintava virtual bank account. From there, you can spend or withdraw via any Nigerian bank channel.', 'matrix-mlm'); ?>
            </div>

            <div class="matrix-info-box" style="margin-bottom: 16px;">
                <p><strong><?php _e('Matrix Wallet Balance:', 'matrix-mlm'); ?></strong> <?php echo $currency . number_format($matrix_balance, 2); ?></p>
                <p><strong><?php _e('Transfer Limits:', 'matrix-mlm'); ?></strong> <?php echo $currency . number_format($min_transfer) . ' - ' . $currency . number_format($max_transfer); ?></p>
                <p><strong><?php _e('Service Charge:', 'matrix-mlm'); ?></strong> <?php echo $charge_type === 'percent' ? $charge_value . '%' : $currency . number_format($charge_value, 2); ?></p>
            </div>

            <form id="matrix-transfer-to-fintava-form" class="matrix-form">
                <div class="matrix-form-group">
                    <label><?php _e('Amount to Transfer', 'matrix-mlm'); ?> (<?php echo $currency; ?>)</label>
                    <input type="number" name="amount" id="fintava-transfer-amount"
                           min="<?php echo $min_transfer; ?>" 
                           max="<?php echo min($max_transfer, $matrix_balance); ?>" 
                           step="0.01" required
                           placeholder="<?php echo sprintf(__('Min %s, Max %s', 'matrix-mlm'), number_format($min_transfer), number_format(min($max_transfer, $matrix_balance))); ?>">
                    <div id="fintava-transfer-charge-info" style="display:none; margin-top: 6px; padding: 8px 12px; background: #f0fdf4; border-radius: 4px; border: 1px solid #bbf7d0;">
                        <small style="color: #166534;">
                            <?php _e('Charge:', 'matrix-mlm'); ?> <span id="ftv-charge">-</span> |
                            <?php _e('Total Debit:', 'matrix-mlm'); ?> <span id="ftv-total">-</span> |
                            <?php _e('You Receive:', 'matrix-mlm'); ?> <span id="ftv-receive">-</span>
                        </small>
                    </div>
                </div>
                <?php
                // Transaction PIN field (PR 2). This form's submit
                // hits matrix_fintava_initiate_transfer (the same
                // endpoint the bank-payout form uses), so it shares
                // the 'bank' path key. Helper returns '' for ungated
                // paths or users with no PIN.
                echo Matrix_MLM_Transaction_Pin::render_field($user_id, 'bank');
                ?>

                <button type="submit" class="matrix-btn matrix-btn-primary matrix-btn-block" id="fintava-transfer-btn">
                    <?php _e('Transfer to Fintava Wallet', 'matrix-mlm'); ?>
                </button>
            </form>
        </div>

        <!-- Transfer History -->
        <?php if (!empty($payouts)): ?>
        <h3 style="margin-top: 30px;"><?php _e('Transfer History (Matrix → Fintava)', 'matrix-mlm'); ?></h3>
        <table class="matrix-table">
            <thead>
                <tr>
                    <th><?php _e('Date', 'matrix-mlm'); ?></th>
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
                    <td><?php echo $currency . number_format($payout->amount, 2); ?></td>
                    <td><?php echo $currency . number_format($payout->charge, 2); ?></td>
                    <td><span class="matrix-badge matrix-badge-<?php echo esc_attr($payout->status); ?>"><?php echo ucfirst($payout->status); ?></span></td>
                    <td><small><code><?php echo esc_html($payout->reference); ?></code></small></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <script>
        // jQuery-footer-race guard. Without this, the inline IIFE
        // throws ReferenceError at parse time on installs where a
        // theme or performance plugin defers jQuery to the footer
        // (Astra, GeneratePress, OceanWP, WP Rocket, SG Optimizer,
        // FlyingPress, Perfmatters, etc., AND this plugin's own
        // matrix-mlm-public.js enqueue is $in_footer=true with
        // jQuery as a dep). When the IIFE throws, none of the
        // handlers below bind, and users see exactly the symptom
        // that drove the audit: 'on some pages I have to do a
        // manual refresh before things work'. Refresh / Verify-
        // Wallet-ID / Calculate-Charge / Submit-Transfer all rely
        // on these bindings. Same polling pattern as the OTHER
        // <script> block lower down in this file, and as
        // class-matrix-user-wallet.php's render_scripts_no_wallet,
        // class-matrix-user-bank-payout.php, and the airtime form
        // in class-matrix-user-billing.php — see that airtime
        // <script> for the full historical context.
        (function() {
            var attempts = 0;
            var maxAttempts = 200; // 200 * 50ms = 10s ceiling

            function whenJQueryReady(cb) {
                if (typeof window.jQuery !== 'undefined' && typeof window.jQuery.fn !== 'undefined') {
                    window.jQuery(cb);
                    return;
                }
                if (++attempts > maxAttempts) {
                    if (window.console && console.error) {
                        console.error('[Matrix MLM] jQuery not loaded after 10s; Fintava virtual-wallet transfer handlers not bound.');
                    }
                    return;
                }
                setTimeout(function() { whenJQueryReady(cb); }, 50);
            }

            whenJQueryReady(function($) {
            'use strict';

            // Live-refresh the Fintava wallet balance from the server without reloading.
            //
            // Delegated on document (not direct .on against the matched
            // element) for the same DOM-timing reason documented in
            // class-matrix-user-wallet.php's render_scripts(): direct
            // binding fires the moment whenJQueryReady resolves, but
            // on stacks with deferred jQuery / Rocket Loader / WP
            // Rocket / FlyingPress / Astra / GeneratePress the matched
            // element may not be in the DOM yet. Delegation walks up
            // from the click target each time so the handler always
            // fires regardless of arrival order. A refresh shifts the
            // parse/execute timing enough to mask the bug, which is
            // why "doesn't work until I refresh" was the reported
            // symptom on the legacy Virtual Wallet page.
            $(document).on('click', '#matrix-fintava-refresh-balance', function() {
                var btn = $(this);
                var amountEl = $('#matrix-fintava-balance-amount');
                var originalLabel = btn.text();
                btn.prop('disabled', true).text('<?php echo esc_js(__('…', 'matrix-mlm')); ?>');

                $.ajax({
                    url: matrixMLM.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'matrix_fintava_wallet_balance',
                        nonce: matrixMLM.nonce
                    },
                    success: function(res) {
                        btn.prop('disabled', false).text(originalLabel);
                        if (res && res.success && res.data && res.data.available_balance_formatted) {
                            amountEl.text(res.data.available_balance_formatted);
                        } else {
                            var msg = (res && res.data && res.data.message) ? res.data.message : '<?php echo esc_js(__('Could not refresh balance.', 'matrix-mlm')); ?>';
                            alert(msg);
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text(originalLabel);
                        alert('<?php echo esc_js(__('Network error refreshing balance.', 'matrix-mlm')); ?>');
                    }
                });
            });

            // Verify & save a missing Fintava Wallet ID inline. The server will only
            // accept the wallet_id if Fintava confirms it belongs to the same
            // account_number that's already stored on the local row.
            // Delegated on document for the DOM-timing reason above.
            $(document).on('click', '#matrix-fintava-set-wallet-id-btn', function() {
                var btn = $(this);
                var input = $('#matrix-fintava-wallet-id-input');
                var statusEl = $('#matrix-fintava-set-wallet-id-status');
                var walletId = (input.val() || '').trim();
                if (!walletId) {
                    statusEl.css('color', '#fecaca').text('<?php echo esc_js(__('Enter your Fintava Wallet ID first.', 'matrix-mlm')); ?>');
                    return;
                }

                var originalLabel = btn.text();
                btn.prop('disabled', true).text('<?php echo esc_js(__('Verifying…', 'matrix-mlm')); ?>');
                statusEl.css('color', '#a7f3d0').text('<?php echo esc_js(__('Calling Fintava…', 'matrix-mlm')); ?>');

                $.ajax({
                    url: matrixMLM.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'matrix_fintava_set_my_wallet_id',
                        nonce: matrixMLM.nonce,
                        wallet_id: walletId
                    },
                    success: function(res) {
                        btn.prop('disabled', false).text(originalLabel);
                        if (res && res.success) {
                            statusEl.css('color', '#a7f3d0').text(res.data && res.data.message ? res.data.message : '<?php echo esc_js(__('Saved.', 'matrix-mlm')); ?>');
                            // Reload so the server-side balance fetch runs with the new wallet_id.
                            setTimeout(function() { (typeof matrixMLMReload === "function" ? matrixMLMReload : function(){ window.location.reload(); })(); }, 600);
                        } else {
                            var err = (res && res.data && res.data.message) ? res.data.message : '<?php echo esc_js(__('Could not save Wallet ID.', 'matrix-mlm')); ?>';
                            statusEl.css('color', '#fecaca').text('✗ ' + err);
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text(originalLabel);
                        statusEl.css('color', '#fecaca').text('<?php echo esc_js(__('Network error.', 'matrix-mlm')); ?>');
                    }
                });
            });

            const currency = '<?php echo esc_js($currency); ?>';
            const chargeType = '<?php echo esc_js($charge_type); ?>';
            const chargeValue = <?php echo floatval($charge_value); ?>;
            const balance = <?php echo floatval($matrix_balance); ?>;
            const accountNumber = '<?php echo esc_js($wallet->account_number); ?>';
            const bankName = '<?php echo esc_js($wallet->bank_name); ?>';
            const accountName = '<?php echo esc_js($wallet->account_name); ?>';
            const bankCode = '<?php echo esc_js($wallet->bank_code ?? ''); ?>';

            // Calculate charges on amount input. Delegated for the DOM-timing reason above.
            $(document).on('input', '#fintava-transfer-amount', function() {
                const amount = parseFloat($(this).val()) || 0;
                if (amount > 0) {
                    let charge = chargeType === 'percent' ? (amount * chargeValue / 100) : chargeValue;
                    charge = Math.round(charge * 100) / 100;
                    const total = amount + charge;
                    $('#ftv-charge').text(currency + charge.toLocaleString());
                    $('#ftv-total').text(currency + total.toLocaleString());
                    $('#ftv-receive').text(currency + amount.toLocaleString());
                    $('#fintava-transfer-charge-info').show();
                } else {
                    $('#fintava-transfer-charge-info').hide();
                }
            });

            // Submit transfer. Delegated for the DOM-timing reason above.
            $(document).on('submit', '#matrix-transfer-to-fintava-form', function(e) {
                e.preventDefault();

                const amount = parseFloat($('#fintava-transfer-amount').val());
                if (!amount || amount <= 0) {
                    alert('<?php _e("Please enter a valid amount", "matrix-mlm"); ?>');
                    return;
                }

                const charge = chargeType === 'percent' ? (amount * chargeValue / 100) : chargeValue;
                const total = amount + charge;

                if (total > balance) {
                    alert('<?php _e("Insufficient Matrix wallet balance", "matrix-mlm"); ?>');
                    return;
                }

                if (!confirm('<?php _e("Transfer", "matrix-mlm"); ?> ' + currency + amount.toLocaleString() + ' <?php _e("from your Matrix wallet to your Fintava wallet?", "matrix-mlm"); ?>\n\n<?php _e("Charge:", "matrix-mlm"); ?> ' + currency + charge.toFixed(2) + '\n<?php _e("Total Debit:", "matrix-mlm"); ?> ' + currency + total.toFixed(2))) {
                    return;
                }

                const btn = $('#fintava-transfer-btn');
                btn.prop('disabled', true).text('<?php _e("Processing...", "matrix-mlm"); ?>');

                $.ajax({
                    url: matrixMLM.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'matrix_fintava_initiate_transfer',
                        nonce: matrixMLM.nonce,
                        amount: amount,
                        account_number: accountNumber,
                        bank_code: bankCode,
                        bank_name: bankName,
                        account_name: accountName,
                        narration: '<?php _e("Matrix to Fintava wallet transfer", "matrix-mlm"); ?>',
                        // Transaction PIN (PR 2). See class-matrix-
                        // user-bank-payout.php for the always-post-
                        // empty rationale; this form uses the same
                        // path key ('bank') because the AJAX action
                        // is the same.
                        transaction_pin: ($('#matrix-transfer-to-fintava-form [name=transaction_pin]').val() || '')
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            (typeof matrixMLMReload === "function" ? matrixMLMReload : function(){ window.location.reload(); })();
                        } else {
                            alert(response.data.message || '<?php _e("Transfer failed", "matrix-mlm"); ?>');
                            btn.prop('disabled', false).text('<?php _e("Transfer to Fintava Wallet", "matrix-mlm"); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php _e("Network error. Please try again.", "matrix-mlm"); ?>');
                        btn.prop('disabled', false).text('<?php _e("Transfer to Fintava Wallet", "matrix-mlm"); ?>');
                    }
                });
            });

        }); // whenJQueryReady
        })(); // poll-for-jQuery IIFE
        </script>
        <?php
    }

    /**
     * Render create wallet form.
     *
     * Public so the consolidated Wallet page (Matrix_MLM_User_Wallet)
     * can delegate to this same first-time-onboarding flow without
     * forking the markup. The legacy /virtual-wallet/ tab is gone from
     * the sidebar but the create form's HTML, JS handler, and AJAX
     * action (matrix_fintava_create_virtual_wallet) all still live
     * here as the canonical implementation. Visibility is the only
     * change — there are no callers passing extra args, and no
     * security-relevant side effects in this method (it just renders
     * markup; the actual create call is gated server-side in
     * Matrix_MLM_Fintava::ajax_create_virtual_wallet).
     */
    public function render_create_form($user, $meta) {
        ?>
        <div class="matrix-create-wallet-intro">
            <h3><?php _e('Create Your Fintava Wallet', 'matrix-mlm'); ?></h3>
            <p><?php _e('A Fintava wallet gives you a dedicated virtual bank account number. You can transfer funds from your Matrix wallet to your Fintava wallet to cash out your earnings. Your Fintava wallet works like a real bank account — spend, transfer, or withdraw from it anytime.', 'matrix-mlm'); ?></p>
            <p style="margin-top: 8px;"><strong><?php _e('How it works:', 'matrix-mlm'); ?></strong></p>
            <ol style="margin: 8px 0; padding-left: 20px; font-size: 13px; color: #1e40af;">
                <li><?php _e('Create your Fintava wallet below (one-time setup)', 'matrix-mlm'); ?></li>
                <li><?php _e('Earn commissions into your Matrix wallet', 'matrix-mlm'); ?></li>
                <li><?php _e('Transfer from Matrix wallet → Fintava wallet to cash out', 'matrix-mlm'); ?></li>
                <li><?php _e('Spend or withdraw from your Fintava wallet like any bank account', 'matrix-mlm'); ?></li>
            </ol>
        </div>

        <div class="matrix-form-card">
            <h3><?php _e('Create Fintava Wallet', 'matrix-mlm'); ?></h3>
            <p style="margin: 0 0 16px; padding: 12px 14px; background: #fffbeb; border: 1px solid #fde68a; border-left: 4px solid #f59e0b; border-radius: 6px; font-size: 13px; line-height: 1.5; color: #78350f;">
                <strong><?php _e('KYC required.', 'matrix-mlm'); ?></strong>
                <?php _e('Fintava (the bank partner) needs your BVN, date of birth, and home address before they can open a virtual account in your own name. Without these details the account can only be opened under the merchant\'s company name, which is confusing for senders. Your BVN is never used for transactions — only to verify your identity with the bank.', 'matrix-mlm'); ?>
            </p>
            <form id="matrix-create-virtual-wallet-form" class="matrix-form">
                <div class="matrix-form-row">
                    <div class="matrix-form-group">
                        <label><?php _e('First Name', 'matrix-mlm'); ?> <span style="color:red;">*</span></label>
                        <input type="text" name="first_name" required value="<?php echo esc_attr(get_user_meta($user->ID, 'first_name', true)); ?>">
                    </div>
                    <div class="matrix-form-group">
                        <label><?php _e('Last Name', 'matrix-mlm'); ?> <span style="color:red;">*</span></label>
                        <input type="text" name="last_name" required value="<?php echo esc_attr(get_user_meta($user->ID, 'last_name', true)); ?>">
                    </div>
                </div>
                <div class="matrix-form-row">
                    <div class="matrix-form-group">
                        <label><?php _e('Email Address', 'matrix-mlm'); ?> <span style="color:red;">*</span></label>
                        <input type="email" name="email" required value="<?php echo esc_attr($user->user_email); ?>">
                    </div>
                    <div class="matrix-form-group">
                        <label><?php _e('Phone Number', 'matrix-mlm'); ?> <span style="color:red;">*</span></label>
                        <input type="tel" name="phone" required value="<?php echo esc_attr($meta->phone ?? ''); ?>" placeholder="08012345678">
                    </div>
                </div>
                <div class="matrix-form-row">
                    <div class="matrix-form-group">
                        <label><?php _e('Bank Verification Number (BVN)', 'matrix-mlm'); ?> <span style="color:red;">*</span></label>
                        <input type="text" name="bvn" required pattern="\d{11}" maxlength="11" inputmode="numeric" autocomplete="off" placeholder="11-digit BVN">
                        <small style="display:block;margin-top:4px;color:#6b7280;font-size:12px;"><?php _e('Dial *565*0# on the phone tied to your bank account to retrieve your BVN.', 'matrix-mlm'); ?></small>
                    </div>
                    <div class="matrix-form-group">
                        <label><?php _e('Date of Birth', 'matrix-mlm'); ?> <span style="color:red;">*</span></label>
                        <input type="date" name="date_of_birth" required max="<?php echo esc_attr(date('Y-m-d')); ?>">
                    </div>
                </div>
                <div class="matrix-form-row">
                    <div class="matrix-form-group" style="flex: 1 1 100%;">
                        <label><?php _e('Residential or Business Address', 'matrix-mlm'); ?> <span style="color:red;">*</span></label>
                        <input type="text" name="address" required maxlength="255" placeholder="<?php esc_attr_e('e.g. 12 Awolowo Road, Ikoyi, Lagos', 'matrix-mlm'); ?>">
                    </div>
                </div>

                <button type="submit" class="matrix-btn matrix-btn-primary matrix-btn-block" id="create-wallet-btn">
                    <?php _e('Create Fintava Wallet', 'matrix-mlm'); ?>
                </button>
            </form>
        </div>

        <script>
        // Same jQuery-polling guard the wallet page uses on its inline
        // scripts. Previous revision called `(function($){...})(jQuery)`
        // directly, which throws ReferenceError on the *very first
        // parse* on any site that defers jQuery to the footer (the
        // matrix-mlm-public enqueue declares jQuery as a dep but is
        // itself enqueued $in_footer=true, so jQuery loads in the
        // footer too). The thrown error is contained to this script
        // tag — it doesn't break the rest of the page — but in
        // hosting environments where this script runs *inside* a
        // section that's a sibling to the wallet page's toggle
        // script, the early ReferenceError would surface in the
        // console as a noisy red error and made it harder to spot
        // the actual binding state of subsequent scripts. Polling
        // window.jQuery removes the noise and lets this submit
        // handler bind cleanly once jQuery is available.
        (function() {
            var attempts = 0;
            var maxAttempts = 200; // 200 * 50ms = 10s ceiling

            function whenJQueryReady(cb) {
                if (typeof window.jQuery !== 'undefined' && typeof window.jQuery.fn !== 'undefined') {
                    window.jQuery(cb);
                    return;
                }
                if (++attempts > maxAttempts) {
                    if (window.console && console.error) {
                        console.error('[Matrix MLM] jQuery not loaded after 10s; create-wallet submit not bound.');
                    }
                    return;
                }
                setTimeout(function() { whenJQueryReady(cb); }, 50);
            }

            whenJQueryReady(function($) {
            'use strict';

            // Delegated on document so the handler always fires regardless
            // of when this form arrives in the DOM relative to whenJQueryReady.
            // Same rationale as the other bindings in this file.
            $(document).on('submit', '#matrix-create-virtual-wallet-form', function(e) {
                e.preventDefault();

                const form = $(this);
                const btn = $('#create-wallet-btn');

                if (!confirm('<?php _e("Create your Fintava wallet? Once created, you can transfer funds from your Matrix wallet to this account.", "matrix-mlm"); ?>')) {
                    return;
                }

                btn.prop('disabled', true).text('<?php _e("Creating wallet...", "matrix-mlm"); ?>');

                $.ajax({
                    url: matrixMLM.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'matrix_fintava_create_virtual_wallet',
                        nonce: matrixMLM.nonce,
                        first_name: form.find('[name="first_name"]').val(),
                        last_name: form.find('[name="last_name"]').val(),
                        email: form.find('[name="email"]').val(),
                        phone: form.find('[name="phone"]').val(),
                        // KYC fields — Fintava's /create/customer DTO
                        // requires all three. The PHP handler validates
                        // server-side; sending them here is the only
                        // way the primary path produces a wallet bound
                        // to the user's name (vs. the merchant master).
                        bvn: form.find('[name="bvn"]').val(),
                        date_of_birth: form.find('[name="date_of_birth"]').val(),
                        address: form.find('[name="address"]').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message + '\n\n<?php _e("Account Number:", "matrix-mlm"); ?> ' + response.data.wallet.account_number + '\n<?php _e("Bank:", "matrix-mlm"); ?> ' + response.data.wallet.bank_name);
                            (typeof matrixMLMReload === "function" ? matrixMLMReload : function(){ window.location.reload(); })();
                        } else {
                            alert(response.data.message || '<?php _e("Failed to create wallet", "matrix-mlm"); ?>');
                            btn.prop('disabled', false).text('<?php _e("Create Fintava Wallet", "matrix-mlm"); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php _e("Network error. Please try again.", "matrix-mlm"); ?>');
                        btn.prop('disabled', false).text('<?php _e("Create Fintava Wallet", "matrix-mlm"); ?>');
                    }
                });
            });
        }); // whenJQueryReady
        })(); // poll-for-jQuery IIFE
        </script>
        <?php
    }
}
