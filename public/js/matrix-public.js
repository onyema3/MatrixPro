/**
 * Matrix MLM Pro - Public JavaScript
 */
(function($) {
    'use strict';

    // Helper: Show notification
    function showNotification(message, type) {
        const notification = $('<div class="matrix-notification matrix-notification-' + type + '">' + message + '</div>');
        $('body').append(notification);
        setTimeout(function() { notification.addClass('show'); }, 10);
        setTimeout(function() { notification.removeClass('show'); setTimeout(function() { notification.remove(); }, 300); }, 4000);
    }

    // Helper: AJAX request
    function matrixAjax(data, successCallback, errorCallback) {
        data.nonce = matrixMLM.nonce;
        $.ajax({
            url: matrixMLM.ajaxUrl,
            type: 'POST',
            data: data,
            success: function(response) {
                if (response.success) {
                    if (successCallback) successCallback(response.data);
                } else {
                    showNotification(response.data.message || 'An error occurred', 'error');
                    if (errorCallback) errorCallback(response.data);
                }
            },
            error: function() {
                showNotification('Network error. Please try again.', 'error');
            }
        });
    }

    // Login form
    $(document).on('submit', '#matrix-login-form', function(e) {
        e.preventDefault();
        const form = $(this);
        const btn = form.find('button[type="submit"]');
        btn.prop('disabled', true).text('Logging in...');

        matrixAjax({
            action: 'matrix_mlm_action',
            matrix_action: 'login',
            username: form.find('[name="username"]').val(),
            password: form.find('[name="password"]').val()
        }, function(data) {
            showNotification('Login successful! Redirecting...', 'success');
            window.location.href = data.redirect;
        }, function() {
            btn.prop('disabled', false).text('Login');
        });
    });

    // Register form
    $(document).on('submit', '#matrix-register-form', function(e) {
        e.preventDefault();
        const form = $(this);
        const btn = form.find('button[type="submit"]');

        if (form.find('[name="password"]').val() !== form.find('[name="password_confirm"]').val()) {
            showNotification('Passwords do not match', 'error');
            return;
        }

        btn.prop('disabled', true).text('Creating account...');

        matrixAjax({
            action: 'matrix_mlm_action',
            matrix_action: 'register',
            first_name: form.find('[name="first_name"]').val(),
            last_name: form.find('[name="last_name"]').val(),
            username: form.find('[name="username"]').val(),
            email: form.find('[name="email"]').val(),
            phone: form.find('[name="phone"]').val(),
            password: form.find('[name="password"]').val(),
            referral_code: form.find('[name="referral_code"]').val()
        }, function(data) {
            showNotification('Registration successful!', 'success');
            window.location.href = data.redirect;
        }, function() {
            btn.prop('disabled', false).text('Create Account');
        });
    });

    // Deposit form
    $(document).on('submit', '#matrix-deposit-form', function(e) {
        e.preventDefault();
        const form = $(this);
        const btn = form.find('button[type="submit"]');
        btn.prop('disabled', true).text('Processing...');

        matrixAjax({
            action: 'matrix_mlm_action',
            matrix_action: 'deposit',
            amount: form.find('[name="amount"]').val(),
            gateway: form.find('[name="gateway"]:checked').val()
        }, function(data) {
            if (data.authorization_url) {
                window.location.href = data.authorization_url;
            } else {
                showNotification('Deposit initiated!', 'success');
            }
        }, function() {
            btn.prop('disabled', false).text('Proceed to Payment');
        });
    });

    // Withdraw form
    $(document).on('submit', '#matrix-withdraw-form', function(e) {
        e.preventDefault();
        const form = $(this);
        const btn = form.find('button[type="submit"]');
        btn.prop('disabled', true).text('Submitting...');

        matrixAjax({
            action: 'matrix_mlm_action',
            matrix_action: 'withdraw',
            amount: form.find('[name="amount"]').val(),
            method: form.find('[name="method"]').val(),
            account_details: form.find('[name="account_details"]').val()
        }, function(data) {
            showNotification(data.message, 'success');
            setTimeout(function() { location.reload(); }, 2000);
        }, function() {
            btn.prop('disabled', false).text('Submit Withdrawal');
        });
    });

    // Transfer form
    $(document).on('submit', '#matrix-transfer-form', function(e) {
        e.preventDefault();
        const form = $(this);
        const btn = form.find('button[type="submit"]');

        if (!confirm('Are you sure you want to transfer this amount?')) return;

        btn.prop('disabled', true).text('Transferring...');

        matrixAjax({
            action: 'matrix_mlm_action',
            matrix_action: 'transfer',
            recipient: form.find('[name="recipient"]').val(),
            amount: form.find('[name="amount"]').val()
        }, function(data) {
            showNotification(data.message, 'success');
            setTimeout(function() { location.reload(); }, 2000);
        }, function() {
            btn.prop('disabled', false).text('Transfer');
        });
    });

    // E-Pin form
    $(document).on('submit', '#matrix-epin-form', function(e) {
        e.preventDefault();
        const form = $(this);
        const btn = form.find('button[type="submit"]');
        btn.prop('disabled', true).text('Redeeming...');

        matrixAjax({
            action: 'matrix_mlm_action',
            matrix_action: 'redeem_epin',
            pin_code: form.find('[name="pin_code"]').val()
        }, function(data) {
            showNotification(data.message, 'success');
            setTimeout(function() { location.reload(); }, 2000);
        }, function() {
            btn.prop('disabled', false).text('Redeem Pin');
        });
    });

    // Ticket form
    $(document).on('submit', '#matrix-ticket-form', function(e) {
        e.preventDefault();
        const form = $(this);
        const btn = form.find('button[type="submit"]');
        btn.prop('disabled', true).text('Submitting...');

        matrixAjax({
            action: 'matrix_mlm_action',
            matrix_action: 'submit_ticket',
            subject: form.find('[name="subject"]').val(),
            message: form.find('[name="message"]').val(),
            priority: form.find('[name="priority"]').val()
        }, function(data) {
            showNotification(data.message, 'success');
            setTimeout(function() { location.reload(); }, 1500);
        }, function() {
            btn.prop('disabled', false).text('Submit Ticket');
        });
    });

    // Profile form
    $(document).on('submit', '#matrix-profile-form', function(e) {
        e.preventDefault();
        const form = $(this);
        const btn = form.find('button[type="submit"]');
        btn.prop('disabled', true).text('Updating...');

        const data = {
            action: 'matrix_mlm_action',
            matrix_action: 'update_profile',
            first_name: form.find('[name="first_name"]').val(),
            last_name: form.find('[name="last_name"]').val(),
            phone: form.find('[name="phone"]').val(),
            address: form.find('[name="address"]').val(),
            city: form.find('[name="city"]').val(),
            state: form.find('[name="state"]').val(),
            country: form.find('[name="country"]').val(),
            zip_code: form.find('[name="zip_code"]').val()
        };

        matrixAjax(data, function(response) {
            showNotification(response.message, 'success');
            btn.prop('disabled', false).text('Update Profile');
        }, function() {
            btn.prop('disabled', false).text('Update Profile');
        });
    });

    // Join Plan - Global function
    window.matrixJoinPlan = function(planId) {
        if (!confirm('Are you sure you want to join this plan? The amount will be deducted from your wallet.')) return;

        matrixAjax({
            action: 'matrix_mlm_action',
            matrix_action: 'join_plan',
            plan_id: planId
        }, function(data) {
            showNotification(data.message, 'success');
            setTimeout(function() { location.reload(); }, 2000);
        });
    };

    // 2FA functions
    window.matrixEnable2FA = function() {
        matrixAjax({
            action: 'matrix_mlm_action',
            matrix_action: 'enable_2fa'
        }, function(data) {
            $('#matrix-2fa-setup').show();
            $('#matrix-2fa-qr').attr('src', data.qr_url);
            $('#matrix-2fa-secret').text(data.secret);
            showNotification(data.message, 'success');
        });
    };

    window.matrixDisable2FA = function() {
        if (!confirm('Are you sure you want to disable 2FA?')) return;
        matrixAjax({
            action: 'matrix_mlm_action',
            matrix_action: 'disable_2fa'
        }, function() {
            showNotification('2FA disabled successfully', 'success');
            setTimeout(function() { location.reload(); }, 1500);
        });
    };

    // Notification styles
    $('<style>')
        .text('.matrix-notification{position:fixed;top:20px;right:20px;padding:14px 24px;border-radius:8px;font-size:14px;font-weight:500;z-index:99999;transform:translateX(120%);transition:transform 0.3s ease;box-shadow:0 10px 15px -3px rgba(0,0,0,0.1);}.matrix-notification.show{transform:translateX(0);}.matrix-notification-success{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;}.matrix-notification-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}.matrix-notification-info{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;}')
        .appendTo('head');

})(jQuery);
