<?php if (!defined('ABSPATH')) exit;

// Resolve the referral code in priority order:
//
//   1. ?ref=CODE in the current URL (a fresh click on a share-link).
//   2. matrix_mlm_ref cookie set by Matrix_MLM_Core::capture_referral_cookie
//      on a previous click within the last 7 days.
//
// The URL form is treated as locked (readonly input) — current click
// intent is unambiguous. The cookie form is rendered editable so a
// member who happens to visit /signup/ in their own browser, having
// previously clicked someone else's share-link, can still type their
// own sponsor's code without having to clear cookies first.
//
// The cookie value is validated against matrix_user_meta.referral_code
// before being trusted: a stale cookie pointing at a deleted sponsor
// or a code that never existed gets dropped on the spot, so the
// prospect doesn't see a pre-filled field that the server is going
// to reject seconds later with "Invalid referral code".

$referral_code = '';
$ref_locked    = false;

if (isset($_GET['ref']) && $_GET['ref'] !== '') {
    $referral_code = sanitize_text_field((string) wp_unslash($_GET['ref']));
    $ref_locked    = true;
} elseif (!empty($_COOKIE['matrix_mlm_ref'])) {
    $candidate = sanitize_text_field((string) wp_unslash($_COOKIE['matrix_mlm_ref']));
    if ($candidate !== '') {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}matrix_user_meta WHERE referral_code = %s LIMIT 1",
            $candidate
        ));
        if ($exists) {
            $referral_code = $candidate;
        } elseif (!headers_sent()) {
            // Self-heal: the cookie points at a non-existent code
            // (sponsor account deleted, code regenerated, attacker-
            // injected bogus value, etc.). Drop it so subsequent
            // visits don't keep replaying the same dead value.
            setcookie('matrix_mlm_ref', '', [
                'expires'  => time() - HOUR_IN_SECONDS,
                'path'     => COOKIEPATH ?: '/',
                'domain'   => COOKIE_DOMAIN ?: '',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            unset($_COOKIE['matrix_mlm_ref']);
        }
    }
}
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
                <input type="text" name="referral_code" value="<?php echo esc_attr($referral_code); ?>" <?php echo $ref_locked ? 'readonly' : ''; ?> required placeholder="<?php echo esc_attr(get_option('matrix_mlm_default_referral_code', '')); ?>">
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
            <p><?php _e('Already have an account?', 'matrix-mlm'); ?> <a href="<?php echo home_url('/matrix'); ?>"><?php _e('Login', 'matrix-mlm'); ?></a></p>
        </div>
    </div>
</div>
