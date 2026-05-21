<?php
/**
 * Deposit Notification Template
 * Variables: $username, $amount, $status, $site_name
 */
if (!defined('ABSPATH')) exit;

$status_color = strtolower($status) === 'completed' ? '#059669' : (strtolower($status) === 'pending' ? '#d97706' : '#dc2626');
$status_bg = strtolower($status) === 'completed' ? '#f0fdf4' : (strtolower($status) === 'pending' ? '#fffbeb' : '#fef2f2');
$status_border = strtolower($status) === 'completed' ? '#bbf7d0' : (strtolower($status) === 'pending' ? '#fde68a' : '#fecaca');

ob_start();
?>
<h2 style="color:#1f2937;font-size:20px;margin:0 0 16px;font-weight:600;"><?php _e('Deposit Update', 'matrix-mlm'); ?></h2>
<p style="color:#4b5563;font-size:15px;line-height:1.6;margin:0 0 12px;">
    <?php printf(__('Hello <strong>%s</strong>,', 'matrix-mlm'), esc_html($username)); ?>
</p>
<p style="color:#4b5563;font-size:15px;line-height:1.6;margin:0 0 24px;">
    <?php _e('Here is an update on your deposit:', 'matrix-mlm'); ?>
</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:<?php echo $status_bg; ?>;border:1px solid <?php echo $status_border; ?>;border-radius:8px;margin:0 0 24px;">
<tr><td style="padding:20px 24px;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
    <tr>
        <td style="color:#6b7280;font-size:13px;padding:6px 0;"><?php _e('Amount', 'matrix-mlm'); ?></td>
        <td align="right" style="color:#1f2937;font-size:18px;font-weight:700;padding:6px 0;"><?php echo esc_html($amount); ?></td>
    </tr>
    <tr>
        <td style="color:#6b7280;font-size:13px;padding:6px 0;"><?php _e('Status', 'matrix-mlm'); ?></td>
        <td align="right" style="padding:6px 0;">
            <span style="background:<?php echo $status_color; ?>;color:#ffffff;padding:4px 12px;border-radius:12px;font-size:12px;font-weight:600;"><?php echo esc_html($status); ?></span>
        </td>
    </tr>
    </table>
</td></tr>
</table>
<?php if (strtolower($status) === 'completed'): ?>
<p style="color:#4b5563;font-size:14px;line-height:1.5;margin:0;">
    <?php _e('Your funds have been credited to your Matrix wallet. You can now use them for transfers, plan purchases, or withdrawals.', 'matrix-mlm'); ?>
</p>
<?php endif; ?>
<?php
$content = ob_get_clean();
$footer_text = '';
include __DIR__ . '/base.php';
