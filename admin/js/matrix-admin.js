/**
 * Matrix MLM Pro - Admin JavaScript
 */
(function($) {
    'use strict';

    // Initialize color pickers
    $(document).ready(function() {
        if ($.fn.wpColorPicker) {
            $('.matrix-color-picker').wpColorPicker();
        }
    });

    // Admin AJAX action helper
    window.matrixAdminAction = function(action, extraData) {
        const data = $.extend({
            action: 'matrix_admin_action',
            matrix_action: action,
            nonce: matrixMLMAdmin.nonce
        }, extraData || {});

        $.ajax({
            url: matrixMLMAdmin.ajaxUrl,
            type: 'POST',
            data: data,
            success: function(response) {
                if (response.success) {
                    alert(response.data.message || 'Action completed successfully');
                    location.reload();
                } else {
                    alert(response.data.message || 'Action failed');
                }
            },
            error: function() {
                alert('Network error. Please try again.');
            }
        });
    };

    // Reject withdrawal with note
    window.matrixRejectWithdrawal = function(id) {
        const note = prompt('Enter rejection reason (optional):');
        if (note === null) return; // Cancelled

        matrixAdminAction('reject_withdrawal', { id: id, note: note });
    };

    // Add balance to user
    window.matrixAddBalance = function(userId) {
        const amount = prompt('Enter amount to add:');
        if (!amount || isNaN(amount) || parseFloat(amount) <= 0) {
            alert('Please enter a valid amount');
            return;
        }
        matrixAdminAction('add_balance', { user_id: userId, amount: parseFloat(amount) });
    };

    // Subtract balance from user
    window.matrixSubtractBalance = function(userId) {
        const amount = prompt('Enter amount to subtract:');
        if (!amount || isNaN(amount) || parseFloat(amount) <= 0) {
            alert('Please enter a valid amount');
            return;
        }
        if (!confirm('Are you sure you want to subtract ' + amount + ' from this user?')) return;
        matrixAdminAction('subtract_balance', { user_id: userId, amount: parseFloat(amount) });
    };

    // Dynamic level commission fields
    $('input[name="depth"]').on('change', function() {
        const depth = parseInt($(this).val());
        const container = $('#level-commissions');
        container.empty();
        for (let i = 1; i <= depth; i++) {
            container.append(
                '<div class="level-commission-row" style="margin-bottom: 5px;">' +
                '<label>Level ' + i + ': </label>' +
                '<input type="number" name="level_commission[' + i + ']" step="0.01" min="0" value="0" style="width: 120px;"> ' +
                matrixMLMAdmin.currency +
                '</div>'
            );
        }
    });

})(jQuery);
