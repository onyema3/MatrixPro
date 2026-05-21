<?php
/**
 * Password Reset Email Template
 * Variables: $username, $reset_url, $site_name
 */
if (!defined('ABSPATH')) exit;

ob_start();
?>
<h2 style="color:#1f2937;font-size:20px;margin:0 0 16px;font-weight:600;"><?php _e('Password Reset Request', 'matrix-mlm'); ?></h2>
<p style="color:#4b5563;font-size:15px;line-height:1.6;margin:0 0 12px;">
    <?php printf(__('Hello <strong>%s</strong>,', 'matrix-mlm'), esc_html($username)); ?>
</p>
<p style="color:#4b5563;font-size:15px;line-height:1.6;margin:0 0 24px;">
    <?php _e('We received a request to reset your password. Click the button below to set a new password:', 'matrix-mlm'); ?>
</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0">
<tr><td align="center" style="padding:8px 0 24px;">
    <a href="<?php echo esc_url($reset_url); ?>" style="background:linear-gradient(135deg,#dc2626,#ef4444);color:#ffffff;padding:14px 36px;border-radius:8px;text-decoration:none;display:inline-block;font-size:15px;font-weight:600;box-shadow:0 4px 6px rgba(220,38,38,0.25);"><?php _e('Reset Password', 'matrix-mlm'); ?></a>
</td></tr>
</table>
<p style="color:#6b7280;font-size:13px;line-height:1.5;margin:0 0 8px;">
    <?php _e('If the button does not work, copy and paste this link into your browser:', 'matrix-mlm'); ?>
</p>
<p style="color:#4f46e5;font-size:12px;word-break:break-all;margin:0 0 24px;"><?php echo esc_url($reset_url); ?></p>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;margin:0 0 16px;">
<tr><td style="padding:16px 20px;">
    <p style="color:#991b1b;font-size:13px;margin:0;font-weight:600;"><?php _e('Security Notice', 'matrix-mlm'); ?></p>
    <p style="color:#7f1d1d;font-size:12px;margin:6px 0 0;line-height:1.4;">
        <?php _e('This link expires in 1 hour. If you did not request a password reset, please ignore this email and your password will remain unchanged. Consider enabling two-factor authentication for added security.', 'matrix-mlm'); ?>
    </p>
</td></tr>
</table>
<?php
$content = ob_get_clean();
$footer_text = __('For security reasons, this link can only be used once.', 'matrix-mlm');
include __DIR__ . '/base.php';
