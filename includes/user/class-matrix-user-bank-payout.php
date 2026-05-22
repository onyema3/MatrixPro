<?php
/**
 * User Bank Payout (via Fintava)
 * Allows users to transfer funds from their Matrix wallet directly to their bank account
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_User_Bank_Payout {

    public function render($user_id) {
        $wallet = new Matrix_MLM_Wallet();
        $balance = $wallet->get_balance($user_id);
        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $min_payout = get_option('matrix_mlm_fintava_min_payout', 1000);
        $max_payout = get_option('matrix_mlm_fintava_max_payout', 5000000);
        $charge_type = get_option('matrix_mlm_fintava_charge_type', 'fixed');
        $charge_value = get_option('matrix_mlm_fintava_charge_value', 50);
        $fintava = new Matrix_MLM_Fintava();
        $is_active = $fintava->is_active();

        // Get payout history
        $payouts = $fintava->get_user_payouts($user_id, 20);
        ?>
        <h2><?php _e('Bank Transfer (Instant Payout)', 'matrix-mlm'); ?></h2>
        <p class="matrix-subtitle"><?php _e('Transfer funds directly from your wallet to your bank account via Fintava.', 'matrix-mlm'); ?></p>

        <?php if (!$is_active): ?>
        <div class="matrix-alert matrix-alert-warning">
            <?php _e('Bank payout is currently unavailable. Please contact support or use the regular withdrawal method.', 'matrix-mlm'); ?>
        </div>
        <?php else: ?>

        <div class="matrix-info-box">
            <p><strong><?php _e('Available Balance:', 'matrix-mlm'); ?></strong> <?php echo $currency . number_format($balance, 2); ?></p>
            <p><strong><?php _e('Transfer Limits:', 'matrix-mlm'); ?></strong> <?php echo $currency . number_format($min_payout) . ' - ' . $currency . number_format($max_payout); ?></p>
            <p><strong><?php _e('Service Charge:', 'matrix-mlm'); ?></strong> <?php echo $charge_type === 'percent' ? $charge_value . '%' : $currency . number_format($charge_value, 2); ?></p>
        </div>

        <div class="matrix-form-card">
            <form id="matrix-bank-payout-form" class="matrix-form">
                <!-- Bank Selection -->
                <div class="matrix-form-group">
                    <label><?php _e('Select Bank', 'matrix-mlm'); ?></label>
                    <select name="bank_code" id="fintava-bank-select" required>
                        <option value=""><?php _e('-- Loading banks...', 'matrix-mlm'); ?></option>
                    </select>
                </div>

                <!-- Account Number -->
                <div class="matrix-form-group">
                    <label><?php _e('Account Number', 'matrix-mlm'); ?></label>
                    <input type="text" name="account_number" id="fintava-account-number" 
                           maxlength="10" pattern="\d{10}" required 
                           placeholder="<?php _e('Enter 10-digit account number', 'matrix-mlm'); ?>">
                </div>

                <!-- Account Name (auto-resolved) -->
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

                <!-- Amount -->
                <div class="matrix-form-group">
                    <label><?php _e('Amount', 'matrix-mlm'); ?> (<?php echo $currency; ?>)</label>
                    <input type="number" name="amount" id="fintava-amount"
                           min="<?php echo $min_payout; ?>" 
                           max="<?php echo min($max_payout, $balance); ?>" 
                           step="0.01" required
                           placeholder="<?php echo sprintf(__('Min %s, Max %s', 'matrix-mlm'), number_format($min_payout), number_format($max_payout)); ?>">
                    <div class="matrix-charge-info" id="fintava-charge-info" style="display:none;">
                        <small>
                            <?php _e('Charge:', 'matrix-mlm'); ?> <span id="fintava-charge-amount">-</span> | 
                            <?php _e('Total Debit:', 'matrix-mlm'); ?> <span id="fintava-total-debit">-</span>
                        </small>
                    </div>
                </div>

                <!-- Narration (Optional) -->
                <div class="matrix-form-group">
                    <label><?php _e('Narration (Optional)', 'matrix-mlm'); ?></label>
                    <input type="text" name="narration" maxlength="100" placeholder="<?php _e('e.g. Salary, Savings, etc.', 'matrix-mlm'); ?>">
                </div>

                <!-- Hidden fields -->
                <input type="hidden" name="bank_name" id="fintava-bank-name" value="">

                <!-- Submit -->
                <button type="submit" class="matrix-btn matrix-btn-primary matrix-btn-block" id="fintava-submit-btn" disabled>
                    <?php _e('Transfer to Bank', 'matrix-mlm'); ?>
                </button>
            </form>
        </div>

        <?php endif; ?>

        <!-- Payout History -->
        <h3><?php _e('Bank Payout History', 'matrix-mlm'); ?></h3>
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
        .matrix-verify-badge { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #10b981; font-weight: 600; font-size: 13px; }
        .matrix-loading-text { padding: 8px 0; color: #6b7280; font-size: 13px; display: flex; align-items: center; gap: 8px; }
        .matrix-spinner { width: 16px; height: 16px; border: 2px solid #e5e7eb; border-top-color: #4f46e5; border-radius: 50%; animation: matrix-spin 0.6s linear infinite; display: inline-block; }
        @keyframes matrix-spin { to { transform: rotate(360deg); } }
        .matrix-charge-info { margin-top: 6px; padding: 8px 12px; background: #fefce8; border-radius: 4px; border: 1px solid #fde68a; }
        .matrix-charge-info small { color: #92400e; }
        .matrix-badge-processing { background: #eff6ff; color: #1e40af; }
        .matrix-badge-refunded { background: #f5f3ff; color: #7c3aed; }
        </style>

        <script>
        // Defer to DOM-ready so the matrixMLM global (localized against the
        // footer-loaded matrix-mlm-public.js handle) is guaranteed to exist
        // before the bank-loading AJAX fires. The original IIFE
        // `(function($){...})(jQuery)` ran synchronously while the body was
        // still being parsed — at that point matrix-mlm-public.js had not
        // been printed yet, so `matrixMLM` was undefined and reading
        // `matrixMLM.ajaxUrl` threw a ReferenceError that aborted the IIFE
        // before $.ajax() could be invoked. The result was a dropdown stuck
        // on "-- Loading banks..." forever, with no `error:` handler firing
        // because the throw preceded the ajax call. The other handlers in
        // this script (resolve_account, initiate_transfer) are bound to user
        // input and only fire after the footer has rendered, so they were
        // never affected and the bug appeared isolated to the bank dropdown.
        jQuery(function($) {
            'use strict';

            const currency = '<?php echo esc_js(get_option("matrix_mlm_currency_symbol", "₦")); ?>';
            const chargeType = '<?php echo esc_js($charge_type); ?>';
            const chargeValue = <?php echo floatval($charge_value); ?>;
            const balance = <?php echo floatval($balance); ?>;
            let accountVerified = false;

            // Defensive: surface a clear, debuggable failure mode if the
            // matrixMLM global is somehow still missing when DOM-ready fires
            // (e.g. another plugin dequeued matrix-mlm-public, or a JS error
            // earlier in the page broke wp_footer). Without this the
            // dropdown stays at "Loading banks..." with no console signal.
            if (typeof matrixMLM === 'undefined' || !matrixMLM.ajaxUrl) {
                $('#fintava-bank-select').empty().append(
                    '<option value=""><?php _e("Cannot reach server (matrixMLM missing)", "matrix-mlm"); ?></option>'
                );
                if (window.console && console.error) {
                    console.error('[Matrix MLM] matrixMLM global is not defined — matrix-mlm-public.js was not enqueued on this page.');
                }
                return;
            }

            // Load banks on page load
            $.ajax({
                url: matrixMLM.ajaxUrl,
                type: 'POST',
                data: { action: 'matrix_fintava_get_banks', nonce: matrixMLM.nonce },
                success: function(response) {
                    const select = $('#fintava-bank-select');
                    select.empty().append('<option value=""><?php _e("-- Select Bank --", "matrix-mlm"); ?></option>');
                    if (response && response.success && response.data && Array.isArray(response.data.banks) && response.data.banks.length) {
                        response.data.banks.forEach(function(bank) {
                            if (!bank || !bank.code || !bank.name) { return; }
                            const opt = $('<option/>')
                                .attr('value', bank.code)
                                .attr('data-name', bank.name)
                                .text(bank.name);
                            select.append(opt);
                        });
                    } else {
                        const serverMsg = response && response.data && response.data.message
                            ? response.data.message
                            : '<?php echo esc_js(__("Failed to load banks", "matrix-mlm")); ?>';
                        select.append($('<option/>').attr('value', '').text(serverMsg));
                        if (window.console && console.warn) {
                            console.warn('[Matrix MLM] Bank list load failed:', response);
                        }
                    }
                },
                error: function(xhr, status, err) {
                    $('#fintava-bank-select').empty().append(
                        '<option value=""><?php _e("Error loading banks", "matrix-mlm"); ?></option>'
                    );
                    if (window.console && console.error) {
                        console.error('[Matrix MLM] Bank list AJAX error:', status, err, xhr && xhr.responseText);
                    }
                }
            });

            // Auto-resolve account when account number is 10 digits and bank is selected
            let resolveTimeout;
            function tryResolveAccount() {
                const accNum = $('#fintava-account-number').val();
                const bankCode = $('#fintava-bank-select').val();

                if (accNum.length === 10 && bankCode) {
                    clearTimeout(resolveTimeout);
                    resolveTimeout = setTimeout(function() {
                        resolveAccount(accNum, bankCode);
                    }, 500);
                } else {
                    $('#fintava-account-name-group').hide();
                    accountVerified = false;
                    updateSubmitButton();
                }
            }

            $('#fintava-account-number').on('input', tryResolveAccount);
            $('#fintava-bank-select').on('change', function() {
                $('#fintava-bank-name').val($(this).find(':selected').data('name') || '');
                tryResolveAccount();
            });

            function resolveAccount(accountNumber, bankCode) {
                $('#fintava-resolving').show();
                $('#fintava-account-name-group').hide();
                accountVerified = false;
                updateSubmitButton();

                $.ajax({
                    url: matrixMLM.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'matrix_fintava_resolve_account',
                        nonce: matrixMLM.nonce,
                        account_number: accountNumber,
                        bank_code: bankCode
                    },
                    success: function(response) {
                        $('#fintava-resolving').hide();
                        if (response.success) {
                            $('#fintava-account-name').val(response.data.account_name);
                            $('#fintava-account-name-group').show();
                            accountVerified = true;
                        } else {
                            alert(response.data.message || '<?php _e("Account verification failed", "matrix-mlm"); ?>');
                            accountVerified = false;
                        }
                        updateSubmitButton();
                    },
                    error: function() {
                        $('#fintava-resolving').hide();
                        alert('<?php _e("Network error during verification", "matrix-mlm"); ?>');
                        accountVerified = false;
                        updateSubmitButton();
                    }
                });
            }

            // Calculate charges on amount input
            $('#fintava-amount').on('input', function() {
                const amount = parseFloat($(this).val()) || 0;
                if (amount > 0) {
                    let charge = chargeType === 'percent' ? (amount * chargeValue / 100) : chargeValue;
                    charge = Math.round(charge * 100) / 100;
                    const total = amount + charge;
                    $('#fintava-charge-amount').text(currency + charge.toLocaleString());
                    $('#fintava-total-debit').text(currency + total.toLocaleString());
                    $('#fintava-charge-info').show();
                } else {
                    $('#fintava-charge-info').hide();
                }
                updateSubmitButton();
            });

            // Enable/disable submit button
            function updateSubmitButton() {
                const amount = parseFloat($('#fintava-amount').val()) || 0;
                const bankCode = $('#fintava-bank-select').val();
                const charge = chargeType === 'percent' ? (amount * chargeValue / 100) : chargeValue;
                const total = amount + charge;

                const enabled = accountVerified && amount > 0 && bankCode && total <= balance;
                $('#fintava-submit-btn').prop('disabled', !enabled);
            }

            // Submit transfer
            $('#matrix-bank-payout-form').on('submit', function(e) {
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
                        narration: $('[name="narration"]').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            location.reload();
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

        });
        </script>
        <?php
    }
}
