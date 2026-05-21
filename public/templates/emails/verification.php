<?php
/**
 * Email Verification Template
 * Variables: $username, $verify_url, $site_name
 */
if (!defined('ABSPATH')) exit;

ob_start();
?>
<h2 style="color:#1f2937;font-size:20px;margin:0 0 16px;font-weight:600;"><?php _e('Verify Your Email Address', 'matrix-mlm'); ?></h2>
<p style="color:#4b5563;font-size:15px;line-height:1.6;margin:0 0 12px;">
    <?php printf(__('Hello <strong>%s</strong>,', 'matrix-mlm'), esc_html($username)); ?>
</p>
<p style="color:#4b5563;font-size:15px;line-height:1.6;margin:0 0 24px;">
    <?php _e('Thank you for registering! Please verify your email address by clicking the button below to activate your account.', 'matrix-mlm'); ?>
</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0">
<tr><td align="center" style="padding:8px 0 24px;">
    <a href="<?php echo esc_url($verify_url); ?>" style="background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#ffffff;padding:14px 36px;border-radius:8px;text-decoration:none;display:inline-block;font-size:15px;font-weight:600;box-shadow:0 4px 6px rgba(79,70,229,0.25);"><?php _e('Verify Email Address', 'matrix-mlm'); ?></a>
</td></tr>
</table>
<p style="color:#6b7280;font-size:13px;line-height:1.5;margin:0 0 8px;">
    <?php _e('If the button does not work, copy and paste this link into your browser:', 'matrix-mlm'); ?>
</p>
<p style="color:#4f46e5;font-size:12px;word-break:break-all;margin:0 0 16px;"><?php echo esc_url($verify_url); ?></p>
<p style="color:#9ca3af;font-size:12px;margin:0;">
    <?php _e('This link expires in 1 hour. If you did not create an account, please ignore this email.', 'matrix-mlm'); ?>
</p>
<?php
$content = ob_get_clean();
$footer_text = __('You received this email because you registered on our platform.', 'matrix-mlm');
include __DIR__ . '/base.php';
