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
        // Inline status banners for the post-registration / post-verify
        // landings. Both messages are static strings on intentional
        // values of the query parameter — never echoing the parameter
        // itself, so there's no XSS surface here. (audit H13)
        if (!empty($_GET['registered'])) {
            echo '<div class="matrix-alert matrix-alert-info" style="margin-bottom:16px;">'
                . esc_html__('Account created. Check your email and click the verification link before signing in.', 'matrix-mlm')
                . '</div>';
        } elseif (!empty($_GET['verified'])) {
            echo '<div class="matrix-alert matrix-alert-success" style="margin-bottom:16px;">'
                . esc_html__('Email verified. You can now sign in.', 'matrix-mlm')
                . '</div>';
        }
        ?>
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
