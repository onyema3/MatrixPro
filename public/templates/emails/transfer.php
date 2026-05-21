<?php
/**
 * Transfer Received Notification Template
 * Variables: $username, $sender, $amount, $site_name
 */
if (!defined('ABSPATH')) exit;

ob_start();
?>
<h2 style="color:#1f2937;font-size:20px;margin:0 0 16px;font-weight:600;"><?php _e('You Received a Transfer!', 'matrix-mlm'); ?></h2>
<p style="color:#4b5563;font-size:15px;line-height:1.6;margin:0 0 12px;">
    <?php printf(__('Hello <strong>%s</strong>,', 'matrix-mlm'), esc_html($username)); ?>
</p>
<p style="color:#4b5563;font-size:15px;line-height:1.6;margin:0 0 24px;">
    <?php _e('Someone has sent you money! Here are the details:', 'matrix-mlm'); ?>
</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;margin:0 0 24px;">
<tr><td style="padding:20px 24px;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
    <tr>
        <td style="color:#6b7280;font-size:13px;padding:6px 0;"><?php _e('From', 'matrix-mlm'); ?></td>
        <td align="right" style="color:#1f2937;font-size:14px;font-weight:600;padding:6px 0;"><?php echo esc_html($sender); ?></td>
    </tr>
    <tr>
        <td style="color:#6b7280;font-size:13px;padding:6px 0;"><?php _e('Amount', 'matrix-mlm'); ?></td>
        <td align="right" style="color:#059669;font-size:20px;font-weight:700;padding:6px 0;"><?php echo esc_html($amount); ?></td>
    </tr>
    <tr>
        <td style="color:#6b7280;font-size:13px;padding:6px 0;"><?php _e('Credited To', 'matrix-mlm'); ?></td>
        <td align="right" style="color:#1f2937;font-size:14px;font-weight:600;padding:6px 0;"><?php _e('Matrix Wallet', 'matrix-mlm'); ?></td>
    </tr>
    </table>
</td></tr>
</table>
<p style="color:#4b5563;font-size:14px;line-height:1.5;margin:0;">
    <?php _e('The funds have been credited to your Matrix wallet. Log in to your dashboard to view your updated balance.', 'matrix-mlm'); ?>
</p>
<?php
$content = ob_get_clean();
$footer_text = '';
include __DIR__ . '/base.php';
