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
        .matrix-bvn-note { background: #fefce8; border: 1px solid #fde68a; border-radius: 6px; padding: 10px 14px; margin-top: 8px; font-size: 12px; color: #92400e; }
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
        if (!empty($wallet->wallet_id)) {
            $balance_result = $fintava->get_virtual_wallet_balance($wallet->wallet_id);
            if (is_wp_error($balance_result)) {
                $fintava_balance_error  = $balance_result->get_error_message();
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
        (function($) {
            'use strict';

            // Live-refresh the Fintava wallet balance from the server without reloading.
            $('#matrix-fintava-refresh-balance').on('click', function() {
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
            $('#matrix-fintava-set-wallet-id-btn').on('click', function() {
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
                            setTimeout(function() { location.reload(); }, 600);
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

            // Calculate charges on amount input
            $('#fintava-transfer-amount').on('input', function() {
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

            // Submit transfer
            $('#matrix-transfer-to-fintava-form').on('submit', function(e) {
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
                        narration: '<?php _e("Matrix to Fintava wallet transfer", "matrix-mlm"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            location.reload();
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

        })(jQuery);
        </script>
        <?php
    }

    /**
     * Render create wallet form
     */
    private function render_create_form($user, $meta) {
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
                        <label><?php _e('BVN (Bank Verification Number)', 'matrix-mlm'); ?></label>
                        <input type="text" name="bvn" maxlength="11" pattern="\d{11}" placeholder="<?php _e('11-digit BVN', 'matrix-mlm'); ?>">
                        <div class="matrix-bvn-note"><?php _e('Your BVN is required for KYC verification. It is securely processed and not stored in plain text.', 'matrix-mlm'); ?></div>
                    </div>
                    <div class="matrix-form-group">
                        <label><?php _e('Date of Birth', 'matrix-mlm'); ?></label>
                        <input type="date" name="date_of_birth" max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>">
                    </div>
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Gender', 'matrix-mlm'); ?></label>
                    <select name="gender">
                        <option value=""><?php _e('-- Select --', 'matrix-mlm'); ?></option>
                        <option value="male"><?php _e('Male', 'matrix-mlm'); ?></option>
                        <option value="female"><?php _e('Female', 'matrix-mlm'); ?></option>
                    </select>
                </div>

                <button type="submit" class="matrix-btn matrix-btn-primary matrix-btn-block" id="create-wallet-btn">
                    <?php _e('Create Fintava Wallet', 'matrix-mlm'); ?>
                </button>
            </form>
        </div>

        <script>
        (function($) {
            'use strict';

            $('#matrix-create-virtual-wallet-form').on('submit', function(e) {
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
                        bvn: form.find('[name="bvn"]').val(),
                        date_of_birth: form.find('[name="date_of_birth"]').val(),
                        gender: form.find('[name="gender"]').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message + '\n\n<?php _e("Account Number:", "matrix-mlm"); ?> ' + response.data.wallet.account_number + '\n<?php _e("Bank:", "matrix-mlm"); ?> ' + response.data.wallet.bank_name);
                            location.reload();
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
        })(jQuery);
        </script>
        <?php
    }
}
