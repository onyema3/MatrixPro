<?php if (!defined('ABSPATH')) exit; ?>
<div class="matrix-auth-wrapper">
    <div class="matrix-auth-card">
        <div class="matrix-auth-header">
            <?php $matrix_login_logo = get_option('matrix_mlm_login_logo_url', ''); ?>
            <?php if (!empty($matrix_login_logo)): ?>
                <div class="matrix-auth-logo">
                    <img src="<?php echo esc_url($matrix_login_logo); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>">
                </div>
            <?php endif; ?>
            <h2><?php _e('Login to Your Account', 'matrix-mlm'); ?></h2>
            <p><?php _e('Enter your credentials to access your dashboard', 'matrix-mlm'); ?></p>
        </div>
        <?php
        // Post-registration / post-verification banners.
        // - ?registered=verify  -> "Account created, check your email" (set by
        //   the registration handler when the require-email-verification
        //   option is on; audit H13).
        // - ?verified=1         -> "Email verified, please sign in" (set by
        //   handle_email_verification() when the link is clicked).
        $matrix_login_state = isset($_GET['registered']) ? sanitize_key($_GET['registered']) : '';
        $matrix_verified    = !empty($_GET['verified']);
        if ($matrix_login_state === 'verify'):
        ?>
            <div class="matrix-form-notice matrix-form-notice-info" style="background:#eef6ff;border:1px solid #b6dcff;border-left:4px solid #2271b1;color:#0a4b78;padding:12px 14px;border-radius:6px;margin-bottom:16px;font-size:13px;line-height:1.5;">
                <strong><?php _e('Check your email.', 'matrix-mlm'); ?></strong>
                <?php _e('Your account was created. We sent a verification link — please click it before signing in.', 'matrix-mlm'); ?>
            </div>
        <?php elseif ($matrix_verified): ?>
            <div class="matrix-form-notice matrix-form-notice-success" style="background:#ecfdf5;border:1px solid #a7f3d0;border-left:4px solid #059669;color:#065f46;padding:12px 14px;border-radius:6px;margin-bottom:16px;font-size:13px;line-height:1.5;">
                <strong><?php _e('Email verified.', 'matrix-mlm'); ?></strong>
                <?php _e('You can now sign in below.', 'matrix-mlm'); ?>
            </div>
        <?php endif; ?>
        <form id="matrix-login-form" class="matrix-form">
            <div class="matrix-form-group">
                <label><?php _e('Username or Email', 'matrix-mlm'); ?></label>
                <input type="text" name="username" required autofocus>
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Password', 'matrix-mlm'); ?></label>
                <input type="password" name="password" required>
            </div>
            <div class="matrix-form-group matrix-form-check">
                <label><input type="checkbox" name="remember"> <?php _e('Remember me', 'matrix-mlm'); ?></label>
            </div>
            <?php if (get_option('matrix_mlm_captcha_enabled')): ?>
            <div class="g-recaptcha" data-sitekey="<?php echo esc_attr(get_option('matrix_mlm_captcha_site_key')); ?>"></div>
            <?php endif; ?>
            <button type="submit" class="matrix-btn matrix-btn-primary matrix-btn-block"><?php _e('Login', 'matrix-mlm'); ?></button>
        </form>
        <div class="matrix-auth-footer">
            <p><?php _e("Don't have an account?", 'matrix-mlm'); ?> <a href="<?php echo home_url('/matrix-register'); ?>"><?php _e('Register', 'matrix-mlm'); ?></a></p>
            <p><a href="<?php echo wp_lostpassword_url(); ?>"><?php _e('Forgot Password?', 'matrix-mlm'); ?></a></p>
        </div>
    </div>
</div>
