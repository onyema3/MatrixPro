<?php
/**
 * Welcome Email Template (sent after email verification)
 * Variables: $username, $dashboard_url, $site_name
 */
if (!defined('ABSPATH')) exit;

ob_start();
?>
<h2 style="color:#1f2937;font-size:20px;margin:0 0 16px;font-weight:600;"><?php _e('Welcome Aboard!', 'matrix-mlm'); ?></h2>
<p style="color:#4b5563;font-size:15px;line-height:1.6;margin:0 0 12px;">
    <?php printf(__('Hello <strong>%s</strong>,', 'matrix-mlm'), esc_html($username)); ?>
</p>
<p style="color:#4b5563;font-size:15px;line-height:1.6;margin:0 0 24px;">
    <?php printf(__('Your email has been verified and your %s account is now fully active. Welcome to the community!', 'matrix-mlm'), esc_html($site_name)); ?>
</p>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef2ff;border:1px solid #c7d2fe;border-radius:8px;margin:0 0 24px;">
<tr><td style="padding:24px;">
    <h3 style="color:#4338ca;font-size:15px;margin:0 0 12px;font-weight:600;"><?php _e('Here is what you can do next:', 'matrix-mlm'); ?></h3>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
    <tr><td style="padding:4px 0;color:#4b5563;font-size:14px;">&#10003; <?php _e('Fund your wallet and join a plan', 'matrix-mlm'); ?></td></tr>
    <tr><td style="padding:4px 0;color:#4b5563;font-size:14px;">&#10003; <?php _e('Share your referral code to build your network', 'matrix-mlm'); ?></td></tr>
    <tr><td style="padding:4px 0;color:#4b5563;font-size:14px;">&#10003; <?php _e('Earn commissions as your team grows', 'matrix-mlm'); ?></td></tr>
    <tr><td style="padding:4px 0;color:#4b5563;font-size:14px;">&#10003; <?php _e('Set up your Fintava virtual wallet for easy payouts', 'matrix-mlm'); ?></td></tr>
    </table>
</td></tr>
</table>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0">
<tr><td align="center" style="padding:8px 0 16px;">
    <a href="<?php echo esc_url($dashboard_url); ?>" style="background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#ffffff;padding:14px 36px;border-radius:8px;text-decoration:none;display:inline-block;font-size:15px;font-weight:600;box-shadow:0 4px 6px rgba(79,70,229,0.25);"><?php _e('Go to Dashboard', 'matrix-mlm'); ?></a>
</td></tr>
</table>

<p style="color:#6b7280;font-size:13px;line-height:1.5;margin:0;">
    <?php _e('If you have any questions, feel free to open a support ticket from your dashboard. We are here to help!', 'matrix-mlm'); ?>
</p>
<?php
$content = ob_get_clean();
$footer_text = __('Welcome to the team! We are excited to have you.', 'matrix-mlm');
include __DIR__ . '/base.php';
