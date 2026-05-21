<?php if (!defined('ABSPATH')) exit; ?>
<div class="matrix-auth-wrapper">
    <div class="matrix-auth-card">
        <div class="matrix-auth-header">
            <h2><?php _e('Login to Your Account', 'matrix-mlm'); ?></h2>
            <p><?php _e('Enter your credentials to access your dashboard', 'matrix-mlm'); ?></p>
        </div>
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
