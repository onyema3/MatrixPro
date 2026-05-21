<?php if (!defined('ABSPATH')) exit; 
$referral_code = isset($_GET['ref']) ? sanitize_text_field($_GET['ref']) : '';
?>
<div class="matrix-auth-wrapper">
    <div class="matrix-auth-card">
        <div class="matrix-auth-header">
            <h2><?php _e('Create Your Account', 'matrix-mlm'); ?></h2>
            <p><?php _e('Join our matrix network and start earning today', 'matrix-mlm'); ?></p>
        </div>
        <form id="matrix-register-form" class="matrix-form">
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label><?php _e('First Name', 'matrix-mlm'); ?></label>
                    <input type="text" name="first_name" required>
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Last Name', 'matrix-mlm'); ?></label>
                    <input type="text" name="last_name" required>
                </div>
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Username', 'matrix-mlm'); ?></label>
                <input type="text" name="username" required minlength="3">
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Email Address', 'matrix-mlm'); ?></label>
                <input type="email" name="email" required>
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Phone Number', 'matrix-mlm'); ?></label>
                <input type="tel" name="phone" required placeholder="+234 xxx xxx xxxx">
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Password', 'matrix-mlm'); ?></label>
                <input type="password" name="password" required minlength="8">
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Confirm Password', 'matrix-mlm'); ?></label>
                <input type="password" name="password_confirm" required>
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Referral Code', 'matrix-mlm'); ?> <span class="matrix-required">*</span></label>
                <input type="text" name="referral_code" value="<?php echo esc_attr($referral_code); ?>" <?php echo $referral_code ? 'readonly' : ''; ?> required>
                <small class="matrix-form-hint"><?php _e('Enter the referral code of the person who invited you.', 'matrix-mlm'); ?></small>
            </div>
            <?php if (get_option('matrix_mlm_captcha_enabled')): ?>
            <div class="g-recaptcha" data-sitekey="<?php echo esc_attr(get_option('matrix_mlm_captcha_site_key')); ?>"></div>
            <?php endif; ?>
            <div class="matrix-form-group matrix-form-check">
                <label><input type="checkbox" name="agree" required> <?php printf(__('I agree to the %sTerms of Service%s and %sPrivacy Policy%s', 'matrix-mlm'), '<a href="' . home_url('/terms') . '">', '</a>', '<a href="' . home_url('/privacy-policy') . '">', '</a>'); ?></label>
            </div>
            <button type="submit" class="matrix-btn matrix-btn-primary matrix-btn-block"><?php _e('Create Account', 'matrix-mlm'); ?></button>
        </form>
        <div class="matrix-auth-footer">
            <p><?php _e('Already have an account?', 'matrix-mlm'); ?> <a href="<?php echo home_url('/matrix-login'); ?>"><?php _e('Login', 'matrix-mlm'); ?></a></p>
        </div>
    </div>
</div>
