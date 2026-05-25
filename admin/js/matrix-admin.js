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

    // Media uploader for settings (e.g. login page logo).
    // Looks for any .matrix-media-uploader container with sibling
    // .matrix-media-url, .matrix-media-id, .matrix-media-preview,
    // .matrix-media-upload, .matrix-media-remove elements.
    $(document).on('click', '.matrix-media-upload', function(e) {
        e.preventDefault();
        if (typeof wp === 'undefined' || !wp.media) {
            alert('WordPress media library is not available.');
            return;
        }
        var $btn = $(this);
        var $wrap = $btn.closest('.matrix-media-uploader');

        var frame = wp.media({
            title: $btn.data('title') || 'Select or Upload Image',
            button: { text: $btn.data('button') || 'Use this image' },
            library: { type: 'image' },
            multiple: false
        });

        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            $wrap.find('.matrix-media-url').val(attachment.url);
            $wrap.find('.matrix-media-id').val(attachment.id);
            var $preview = $wrap.find('.matrix-media-preview');
            $preview.find('img').attr('src', attachment.url);
            $preview.show();
            $wrap.find('.matrix-media-remove').show();
        });

        frame.open();
    });

    $(document).on('click', '.matrix-media-remove', function(e) {
        e.preventDefault();
        var $wrap = $(this).closest('.matrix-media-uploader');
        $wrap.find('.matrix-media-url').val('');
        $wrap.find('.matrix-media-id').val('');
        $wrap.find('.matrix-media-preview').hide().find('img').attr('src', '');
        $(this).hide();
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

    // Capture a held Zebra Wallet (Bibimoney) pre-auth deposit.
    // Confirms first because Capture credits the Matrix wallet
    // and is the irreversible side of the state machine —
    // there's no "uncapture" call on the platform. Optional
    // note is plumbed through to UserData.note in the
    // /CaptureOrCancel request so support can correlate later.
    window.matrixCaptureZebraDeposit = function(id) {
        if (!confirm('Capture this held authorisation? The customer\'s Zebra wallet will be charged and the Matrix wallet credited. This cannot be undone.')) {
            return;
        }
        const note = prompt('Optional note (visible to support, blank = skip):') || '';
        matrixAdminAction('zebra_capture_deposit', { id: id, note: note });
    };

    // Cancel a held Zebra Wallet (Bibimoney) pre-auth deposit.
    // Confirms first; no Matrix wallet credit and no refund are
    // issued on this path — the customer was never charged
    // Matrix-side, and the platform releases the hold back to
    // their Zebra wallet on success. Reason is required because
    // a cancelled hold is the kind of thing a user will write a
    // ticket about.
    window.matrixCancelZebraDeposit = function(id) {
        if (!confirm('Cancel this held authorisation? The customer\'s Zebra wallet hold will be released; no funds will move.')) {
            return;
        }
        const note = prompt('Reason for cancellation (required):');
        if (note === null) return;
        if (!note.trim()) {
            alert('A reason is required to cancel a held authorisation.');
            return;
        }
        matrixAdminAction('zebra_cancel_deposit', { id: id, note: note.trim() });
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
