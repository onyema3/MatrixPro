/**
 * Matrix MLM Pro - Public JavaScript
 */
(function($) {
    'use strict';

    // Cache-busting reload helper.
    //
    // Reported symptom: after a successful Wallet→Wallet transfer
    // or e-pin recharge the database is correct but the user sees
    // the old balance on the dashboard until they manually clear
    // their app cache. Root cause is HTTP-level page caching
    // (browser cache, server-side full-page cache, Cloudflare APO,
    // hosting-provider edge cache) serving the pre-transaction
    // HTML when location.reload() requests the dashboard.
    //
    // Plain location.reload() in modern browsers usually does
    // revalidate against Cache-Control headers, but a few cache
    // layers — Cloudflare APO with "Cache by device type" enabled,
    // some shared-hosting object caches, certain WAF edge rules —
    // ignore those headers for logged-in users and serve a stored
    // copy regardless. Appending a unique query parameter
    // (`?_mlmts=<timestamp>`) makes the reload URL distinct from
    // any cached entry, so even non-compliant caches treat it as
    // a fresh request.
    //
    // Exposed on `window` so inline scripts in PHP-rendered
    // dashboard partials can call matrixMLMReload() instead of
    // location.reload() after a wallet-changing action lands.
    // Pairs with the nocache_headers() hook in
    // Matrix_MLM_Core::nocache_dashboard_pages(): headers stop
    // compliant caches from storing the page in the first place,
    // and the cache-busting URL is the belt-and-braces fallback.
    window.matrixMLMReload = function() {
        try {
            var url = new URL(window.location.href);
            url.searchParams.set('_mlmts', String(Date.now()));
            window.location.href = url.toString();
        } catch (e) {
            // URL constructor not available (very old browser) or
            // the page URL is unusual enough that the constructor
            // throws. Fall back to a plain reload — better than no
            // reload, and the nocache_headers() server-side hint
            // will handle most modern stacks anyway.
            window.location.reload();
        }
    };

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
    //
    // Two-step flow when 2FA is enabled on the account:
    //   1. Submit username + password.
    //   2. If the server responds with `requires_2fa: true`, swap the
    //      form's UI for the OTP prompt and re-post with the
    //      challenge_token + 6-digit code.
    //   3. The server replies with a redirect URL only after the OTP
    //      verifies, so the auth cookie is never issued on password
    //      alone for 2FA-protected accounts.
    $(document).on('submit', '#matrix-login-form', function(e) {
        e.preventDefault();
        const form = $(this);
        const btn = form.find('button[type="submit"]');

        // Submitting with the OTP step active? Re-post with the challenge token.
        const challengeToken = form.data('matrixChallengeToken');
        if (challengeToken) {
            const code = form.find('[name="code"]').val();
            btn.prop('disabled', true).text('Verifying...');
            matrixAjax({
                action: 'matrix_mlm_action',
                matrix_action: 'login',
                challenge_token: challengeToken,
                code: code
            }, function(data) {
                showNotification('Login successful! Redirecting...', 'success');
                window.location.href = data.redirect;
            }, function(err) {
                // If the server says "restart", drop back to step 1.
                if (err && err.restart) {
                    form.removeData('matrixChallengeToken');
                    form.find('.matrix-login-2fa-step').remove();
                    form.find('.matrix-login-creds-step').show();
                    btn.prop('disabled', false).text('Login');
                } else {
                    btn.prop('disabled', false).text('Verify');
                }
            });
            return;
        }

        // Step 1: credentials.
        btn.prop('disabled', true).text('Logging in...');
        matrixAjax({
            action: 'matrix_mlm_action',
            matrix_action: 'login',
            username: form.find('[name="username"]').val(),
            password: form.find('[name="password"]').val()
        }, function(data) {
            if (data.requires_2fa && data.challenge_token) {
                form.data('matrixChallengeToken', data.challenge_token);
                form.find('.matrix-login-creds-step').hide();

                // Render the OTP prompt. We append rather than replace
                // so the form's nonce-bearing inputs (and any tracking
                // hidden fields rendered by themes) remain intact.
                const otpHtml =
                    '<div class="matrix-login-2fa-step">' +
                        '<p>' + (data.message || 'Enter the 6-digit code from your authenticator app.') + '</p>' +
                        '<input type="text" name="code" inputmode="numeric" pattern="[0-9]*" ' +
                        'maxlength="6" autocomplete="one-time-code" required ' +
                        'placeholder="123456" class="matrix-input" />' +
                    '</div>';
                form.find('button[type="submit"]').before(otpHtml);
                btn.prop('disabled', false).text('Verify');
                form.find('input[name="code"]').focus();
                return;
            }

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
            // Two server responses:
            //  - {redirect: '/matrix-dashboard'}  -> auto-login flow
            //    (matrix_mlm_email_verification disabled site-wide)
            //  - {verify_pending: true, message: '...'}  -> H13 gate:
            //    user must click the verification link in their email
            //    before logging in. Show the message and route to the
            //    login page so the verified flag is the next thing
            //    they see after clicking through.
            if (data && data.verify_pending) {
                showNotification(data.message || 'Account created. Check your email to verify.', 'success');
                window.location.href = '/matrix-login?registered=1';
                return;
            }
            showNotification(data && data.message ? data.message : 'Registration successful!', 'success');
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

    // Withdraw form handler removed — see feat/admin-controlled-withdrawals
    // and refactor/withdrawal-controls-five-toggles. The Withdraw tab,
    // #matrix-withdraw-form, and the standalone "Matrix Transfers" pane
    // are all retired; the matrix_action=withdraw endpoint they posted
    // to has been removed too. Money out of the platform goes through
    // matrix_fintava_initiate_transfer (Fintava virtual → external bank,
    // the "Transfer to Bank" pane) and matrix_transfer_matrix_to_virtual
    // (Matrix wallet → Fintava virtual, the "Transfer to Own Wallet"
    // pane). Both honour the five-toggle Withdrawal Controls panel on
    // Settings → Financial via Matrix_MLM_User::can_move_funds().

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
            setTimeout(function() { matrixMLMReload(); }, 2000);
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
            setTimeout(function() { matrixMLMReload(); }, 2000);
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
            setTimeout(function() { matrixMLMReload(); }, 1500);
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
            setTimeout(function() { matrixMLMReload(); }, 2000);
        });
    };

    // 2FA functions.
    //
    // Audit M2: enable / disable / regenerate-recovery-codes all go
    // through the same inline reauth form, which captures a
    // current-password (and an OTP or recovery code on the disable
    // path) before submitting. The dashboard template renders the
    // form hidden — these helpers swap the visible inputs and wire
    // the submit button to the right action.
    //
    // matrixToggle2FAForm(mode) where mode is 'enable' | 'disable' | 'regen'.
    var matrix2FAState = { mode: null };

    window.matrixToggle2FAForm = function(mode) {
        matrix2FAState.mode = mode;
        var $form    = $('#matrix-2fa-form');
        var $title   = $('#matrix-2fa-form-title');
        var $help    = $('#matrix-2fa-form-help');
        var $codeRow = $('#matrix-2fa-code-row');
        var $submit  = $('#matrix-2fa-submit');

        // Reset inputs every time so a previous half-finished attempt
        // doesn't leak into the next one.
        $('#matrix-2fa-password').val('');
        $('#matrix-2fa-code').val('');

        if (mode === 'enable') {
            $title.text('Enable two-factor authentication');
            $help.text('Confirm your password to start enrolment.');
            $codeRow.hide();
            $submit.text('Continue');
        } else if (mode === 'disable') {
            $title.text('Disable two-factor authentication');
            $help.text('Confirm your password and a current authenticator code (or one of your recovery codes).');
            $codeRow.show();
            $submit.text('Disable 2FA');
        } else if (mode === 'regen') {
            $title.text('Regenerate recovery codes');
            $help.text('Confirm your password. Your previous recovery codes will stop working immediately.');
            $codeRow.hide();
            $submit.text('Generate new codes');
        }

        // Hide any previously-rendered post-enrolment block — the user
        // should never see old codes when starting a new flow.
        $('#matrix-2fa-setup').hide();

        $form.show();
        $('#matrix-2fa-password').trigger('focus');
    };

    window.matrixCancel2FAForm = function() {
        matrix2FAState.mode = null;
        $('#matrix-2fa-form').hide();
        $('#matrix-2fa-password').val('');
        $('#matrix-2fa-code').val('');
    };

    // Wire the single submit button — it dispatches based on state.
    $(document).on('click', '#matrix-2fa-submit', function(e) {
        e.preventDefault();
        var password = $('#matrix-2fa-password').val();
        var code     = $('#matrix-2fa-code').val();

        if (!password) {
            showNotification('Enter your current password.', 'error');
            return;
        }

        var mode = matrix2FAState.mode;
        if (mode === 'enable') {
            matrixAjax({
                action: 'matrix_mlm_action',
                matrix_action: 'enable_2fa',
                current_password: password
            }, function(data) {
                $('#matrix-2fa-form').hide();
                $('#matrix-2fa-setup').show();
                $('#matrix-2fa-setup-qr-block').show();
                $('#matrix-2fa-qr').attr('src', data.qr_url);
                $('#matrix-2fa-secret').text(data.secret);
                renderRecoveryCodes(data.recovery_codes);
                showNotification(data.message, 'success');
            });
        } else if (mode === 'disable') {
            if (!code) {
                showNotification('Enter a current authenticator code or a recovery code.', 'error');
                return;
            }
            matrixAjax({
                action: 'matrix_mlm_action',
                matrix_action: 'disable_2fa',
                current_password: password,
                code: code
            }, function(data) {
                showNotification(data.message || '2FA disabled.', 'success');
                setTimeout(function() { matrixMLMReload(); }, 1200);
            });
        } else if (mode === 'regen') {
            matrixAjax({
                action: 'matrix_mlm_action',
                matrix_action: 'regenerate_recovery_codes',
                current_password: password
            }, function(data) {
                $('#matrix-2fa-form').hide();
                // Same display surface as enrol but without the QR
                // block — only the codes are new.
                $('#matrix-2fa-setup').show();
                $('#matrix-2fa-setup-qr-block').hide();
                renderRecoveryCodes(data.recovery_codes);
                showNotification(data.message, 'success');
            });
        }
    });

    function renderRecoveryCodes(codes) {
        var $list = $('#matrix-2fa-recovery-codes');
        $list.empty();
        if (!Array.isArray(codes)) return;
        codes.forEach(function(code) {
            $list.append($('<li>').append($('<code>').text(code)));
        });
    }

    window.matrixCopyRecoveryCodes = function() {
        var codes = $('#matrix-2fa-recovery-codes code').map(function() {
            return $(this).text();
        }).get().join('\n');
        if (!codes) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(codes).then(function() {
                showNotification('Recovery codes copied to clipboard.', 'success');
            }, function() {
                showNotification('Could not copy automatically — please select and copy manually.', 'error');
            });
        } else {
            showNotification('Clipboard not available — please select and copy manually.', 'info');
        }
    };

    window.matrixDismissRecoveryCodes = function() {
        if (!confirm('Have you stored your recovery codes somewhere safe? They will not be shown again.')) {
            return;
        }
        // Reload so the dashboard reflects the new "remaining: N"
        // counter and the re-enrolment buttons.
        matrixMLMReload();
    };

    // Legacy aliases — older inline onclick handlers from cached
    // dashboard pages may still call these names; route them through
    // the new flow instead of letting them post empty (and now-rejected)
    // requests against the hardened endpoints.
    window.matrixEnable2FA  = function() { matrixToggle2FAForm('enable'); };
    window.matrixDisable2FA = function() { matrixToggle2FAForm('disable'); };

    // Transaction PIN form. Same shape and rationale as the 2FA form
    // above — server-rendered hidden form, JS swaps the visible
    // input rows and dispatches the right AJAX action based on mode.
    //
    // matrixTogglePinForm(mode) where mode is 'set' | 'change' | 'disable' | 'forgot'.
    var matrixPinState = { mode: null };

    window.matrixTogglePinForm = function(mode) {
        matrixPinState.mode = mode;
        var $form        = $('#matrix-pin-form');
        var $title       = $('#matrix-pin-form-title');
        var $help        = $('#matrix-pin-form-help');
        var $currentRow  = $('#matrix-pin-current-row');
        var $newRow      = $('#matrix-pin-new-row');
        var $confirmRow  = $('#matrix-pin-confirm-row');
        var $submit      = $('#matrix-pin-submit');

        // Reset every input on each open so a previous half-finished
        // attempt doesn't leak into the next one.
        $('#matrix-pin-password').val('');
        $('#matrix-pin-current').val('');
        $('#matrix-pin-new').val('');
        $('#matrix-pin-confirm').val('');

        if (mode === 'set') {
            $title.text('Set transaction PIN');
            $help.text('Confirm your password and choose a 4–6 digit numeric PIN.');
            $currentRow.hide();
            $newRow.show();
            $confirmRow.show();
            $submit.text('Set PIN');
        } else if (mode === 'change') {
            $title.text('Change transaction PIN');
            $help.text('Confirm your password, your current PIN, and choose a new 4–6 digit numeric PIN.');
            $currentRow.show();
            $newRow.show();
            $confirmRow.show();
            $submit.text('Change PIN');
        } else if (mode === 'disable') {
            $title.text('Disable transaction PIN');
            $help.text('Confirm your password and your current PIN to remove the PIN gate.');
            $currentRow.show();
            $newRow.hide();
            $confirmRow.hide();
            $submit.text('Disable PIN');
        } else if (mode === 'forgot') {
            // Forgot-PIN flow: the user has, by definition, lost the
            // current PIN — so we don't show the current-PIN row.
            // Password reauth is the integrity gate; the server-side
            // process_forgot_transaction_pin handler wipes the hash
            // and the lockout atomically.
            $title.text('Reset transaction PIN');
            $help.text('Confirm your password to clear your transaction PIN. You will be able to set a new PIN afterwards.');
            $currentRow.hide();
            $newRow.hide();
            $confirmRow.hide();
            $submit.text('Reset PIN');
        }

        $form.show();
        $('#matrix-pin-password').trigger('focus');
    };

    window.matrixCancelPinForm = function() {
        matrixPinState.mode = null;
        $('#matrix-pin-form').hide();
        $('#matrix-pin-password').val('');
        $('#matrix-pin-current').val('');
        $('#matrix-pin-new').val('');
        $('#matrix-pin-confirm').val('');
    };

    // Single submit button — dispatches based on state. Client-side
    // checks here are UX hints (catch the obvious typo before the
    // round-trip); the server normaliser is the security gate, so a
    // client that bypasses these checks still hits the same 4–6
    // digit + bcrypt verify on the back end.
    $(document).on('click', '#matrix-pin-submit', function(e) {
        e.preventDefault();
        var password    = $('#matrix-pin-password').val();
        var currentPin  = $('#matrix-pin-current').val();
        var newPin      = $('#matrix-pin-new').val();
        var confirmPin  = $('#matrix-pin-confirm').val();

        if (!password) {
            showNotification('Enter your current password.', 'error');
            return;
        }

        var mode = matrixPinState.mode;
        var pinPattern = /^[0-9]{4,6}$/;

        if (mode === 'set') {
            if (!pinPattern.test(newPin)) {
                showNotification('PIN must be 4 to 6 digits.', 'error');
                return;
            }
            if (newPin !== confirmPin) {
                showNotification('PIN and confirmation do not match.', 'error');
                return;
            }
            matrixAjax({
                action: 'matrix_mlm_action',
                matrix_action: 'set_transaction_pin',
                current_password: password,
                pin: newPin
            }, function(data) {
                showNotification(data.message || 'PIN set.', 'success');
                setTimeout(function() { matrixMLMReload(); }, 1200);
            });
        } else if (mode === 'change') {
            if (!pinPattern.test(currentPin)) {
                showNotification('Enter your current PIN.', 'error');
                return;
            }
            if (!pinPattern.test(newPin)) {
                showNotification('New PIN must be 4 to 6 digits.', 'error');
                return;
            }
            if (newPin !== confirmPin) {
                showNotification('PIN and confirmation do not match.', 'error');
                return;
            }
            matrixAjax({
                action: 'matrix_mlm_action',
                matrix_action: 'change_transaction_pin',
                current_password: password,
                current_pin: currentPin,
                new_pin: newPin
            }, function(data) {
                showNotification(data.message || 'PIN updated.', 'success');
                setTimeout(function() { matrixMLMReload(); }, 1200);
            });
        } else if (mode === 'disable') {
            if (!pinPattern.test(currentPin)) {
                showNotification('Enter your current PIN.', 'error');
                return;
            }
            matrixAjax({
                action: 'matrix_mlm_action',
                matrix_action: 'disable_transaction_pin',
                current_password: password,
                current_pin: currentPin
            }, function(data) {
                showNotification(data.message || 'PIN disabled.', 'success');
                setTimeout(function() { matrixMLMReload(); }, 1200);
            });
        } else if (mode === 'forgot') {
            // No PIN to validate client-side — the server clears the
            // hash + lockout under the password reauth gate alone.
            matrixAjax({
                action: 'matrix_mlm_action',
                matrix_action: 'forgot_transaction_pin',
                current_password: password
            }, function(data) {
                showNotification(data.message || 'PIN reset.', 'success');
                setTimeout(function() { matrixMLMReload(); }, 1200);
            });
        }
    });

    // Notification styles
    $('<style>')
        .text('.matrix-notification{position:fixed;top:20px;right:20px;padding:14px 24px;border-radius:8px;font-size:14px;font-weight:500;z-index:99999;transform:translateX(120%);transition:transform 0.3s ease;box-shadow:0 10px 15px -3px rgba(0,0,0,0.1);}.matrix-notification.show{transform:translateX(0);}.matrix-notification-success{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;}.matrix-notification-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}.matrix-notification-info{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;}')
        .appendTo('head');

})(jQuery);
