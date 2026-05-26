<?php
/**
 * Matrix Wallet → Virtual Account Transfer Confirmation Template
 *
 * Variables: $username, $amount, $charge, $total_debit, $account_number,
 *            $reference, $site_name, $dashboard_url
 *
 * Visually matches the deposit / transfer / withdrawal templates: the
 * same green "money landed" treatment used for incoming credits, but
 * stamped with the Matrix → Virtual context (debit on Matrix side,
 * credit on Virtual side, charge / total breakdown, the Fintava
 * reference for support escalations) so a member who has both a
 * deposit AND a Matrix → Virtual confirmation in their inbox can tell
 * them apart at a glance.
 *
 * Reference is rendered in a monospace pill at the bottom because
 * it's the single most useful field when the member needs to write
 * to support — long enough that a normal-line render risks word
 * wrapping awkwardly across mail clients.
 */
if (!defined('ABSPATH')) exit;

ob_start();
?>
<h2 style="color:#1f2937;font-size:20px;margin:0 0 16px;font-weight:600;"><?php _e('Wallet Transfer Successful', 'matrix-mlm'); ?></h2>
<p style="color:#4b5563;font-size:15px;line-height:1.6;margin:0 0 12px;">
    <?php printf(__('Hello <strong>%s</strong>,', 'matrix-mlm'), esc_html($username)); ?>
</p>
<p style="color:#4b5563;font-size:15px;line-height:1.6;margin:0 0 24px;">
    <?php _e('Your transfer from your Matrix wallet to your Virtual account has been completed. Here is a summary:', 'matrix-mlm'); ?>
</p>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;margin:0 0 24px;">
<tr><td style="padding:20px 24px;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
    <tr>
        <td style="color:#6b7280;font-size:13px;padding:6px 0;"><?php _e('Amount Transferred', 'matrix-mlm'); ?></td>
        <td align="right" style="color:#059669;font-size:20px;font-weight:700;padding:6px 0;"><?php echo esc_html($amount); ?></td>
    </tr>
    <tr>
        <td style="color:#6b7280;font-size:13px;padding:6px 0;"><?php _e('Service Charge', 'matrix-mlm'); ?></td>
        <td align="right" style="color:#1f2937;font-size:14px;font-weight:600;padding:6px 0;"><?php echo esc_html($charge); ?></td>
    </tr>
    <tr>
        <td style="color:#6b7280;font-size:13px;padding:6px 0;border-top:1px solid #bbf7d0;"><?php _e('Total Debit (Matrix Wallet)', 'matrix-mlm'); ?></td>
        <td align="right" style="color:#1f2937;font-size:16px;font-weight:700;padding:6px 0;border-top:1px solid #bbf7d0;"><?php echo esc_html($total_debit); ?></td>
    </tr>
    <tr>
        <td style="color:#6b7280;font-size:13px;padding:6px 0;"><?php _e('Credited To', 'matrix-mlm'); ?></td>
        <td align="right" style="color:#1f2937;font-size:14px;font-weight:600;padding:6px 0;font-family:'Courier New',monospace;letter-spacing:1px;"><?php echo esc_html($account_number); ?></td>
    </tr>
    </table>
</td></tr>
</table>

<p style="color:#4b5563;font-size:14px;line-height:1.5;margin:0 0 16px;">
    <?php _e('The funds are now available in your Virtual account and can be spent or withdrawn via any Nigerian bank channel.', 'matrix-mlm'); ?>
</p>

<p style="color:#6b7280;font-size:12px;margin:0 0 24px;">
    <?php _e('Reference:', 'matrix-mlm'); ?>
    <span style="background:#f3f4f6;padding:3px 8px;border-radius:4px;font-family:'Courier New',monospace;color:#1f2937;letter-spacing:0.5px;"><?php echo esc_html($reference); ?></span>
</p>

<?php if (!empty($dashboard_url)): ?>
<p style="text-align:center;margin:0 0 8px;">
    <a href="<?php echo esc_url($dashboard_url); ?>" style="background:#4f46e5;color:#ffffff;padding:12px 30px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600;font-size:14px;">
        <?php _e('View Wallet', 'matrix-mlm'); ?>
    </a>
</p>
<?php endif; ?>
<?php
$content = ob_get_clean();
$footer_text = '';
include __DIR__ . '/base.php';
