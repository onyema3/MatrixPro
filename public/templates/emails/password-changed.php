<?php
/**
 * Password Changed Confirmation Template
 * Variables: $username, $site_name
 */
if (!defined('ABSPATH')) exit;

ob_start();
?>
<h2 style="color:#1f2937;font-size:20px;margin:0 0 16px;font-weight:600;"><?php _e('Password Changed Successfully', 'matrix-mlm'); ?></h2>
<p style="color:#4b5563;font-size:15px;line-height:1.6;margin:0 0 12px;">
    <?php printf(__('Hello <strong>%s</strong>,', 'matrix-mlm'), esc_html($username)); ?>
</p>
<p style="color:#4b5563;font-size:15px;line-height:1.6;margin:0 0 24px;">
    <?php _e('Your password has been successfully changed. You can now log in with your new password.', 'matrix-mlm'); ?>
</p>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;margin:0 0 24px;">
<tr><td style="padding:16px 20px;text-align:center;">
    <span style="font-size:32px;">&#9989;</span>
    <p style="color:#059669;font-size:14px;font-weight:600;margin:8px 0 0;"><?php _e('Password updated successfully', 'matrix-mlm'); ?></p>
</td></tr>
</table>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;margin:0 0 16px;">
<tr><td style="padding:16px 20px;">
    <p style="color:#991b1b;font-size:13px;margin:0;font-weight:600;"><?php _e('Did not make this change?', 'matrix-mlm'); ?></p>
    <p style="color:#7f1d1d;font-size:12px;margin:6px 0 0;line-height:1.4;">
        <?php _e('If you did not change your password, your account may be compromised. Please reset your password immediately and contact our support team.', 'matrix-mlm'); ?>
    </p>
</td></tr>
</table>
<?php
$content = ob_get_clean();
$footer_text = __('This is a security notification. Please keep your credentials safe.', 'matrix-mlm');
include __DIR__ . '/base.php';
