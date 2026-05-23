<?php
/**
 * Loan Application Notification Template
 *
 * Sent by Matrix_MLM_Notifications::send_loan_notification() when an
 * admin transitions a row in matrix_loan_applications to a
 * user-visible decision state (approved / rejected / cancelled).
 * The internal under_review and pending statuses do NOT call this
 * template — see the admin loan triage class for that gating.
 *
 * Variables: $username, $amount, $status, $site_name
 */
if (!defined('ABSPATH')) exit;

// Status colour map — mirrors the withdrawal template's three-bucket
// scheme but extended for the loan-specific 'cancelled' state, which
// renders neutral-grey rather than the rejection red.
$status_lc = strtolower((string) $status);
switch ($status_lc) {
    case 'approved':
        $status_color  = '#059669';
        $status_bg     = '#f0fdf4';
        $status_border = '#bbf7d0';
        break;
    case 'rejected':
        $status_color  = '#dc2626';
        $status_bg     = '#fef2f2';
        $status_border = '#fecaca';
        break;
    case 'cancelled':
        $status_color  = '#6b7280';
        $status_bg     = '#f9fafb';
        $status_border = '#e5e7eb';
        break;
    default:
        // Pending / under review fallback. The notifier does not
        // currently send these, but render sensibly if a future
        // caller does.
        $status_color  = '#d97706';
        $status_bg     = '#fffbeb';
        $status_border = '#fde68a';
        break;
}

ob_start();
?>
<h2 style="color:#1f2937;font-size:20px;margin:0 0 16px;font-weight:600;"><?php _e('Loan Application Update', 'matrix-mlm'); ?></h2>
<p style="color:#4b5563;font-size:15px;line-height:1.6;margin:0 0 12px;">
    <?php printf(__('Hello <strong>%s</strong>,', 'matrix-mlm'), esc_html($username)); ?>
</p>
<p style="color:#4b5563;font-size:15px;line-height:1.6;margin:0 0 24px;">
    <?php _e('Here is an update on your business loan application:', 'matrix-mlm'); ?>
</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:<?php echo $status_bg; ?>;border:1px solid <?php echo $status_border; ?>;border-radius:8px;margin:0 0 24px;">
<tr><td style="padding:20px 24px;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
    <tr>
        <td style="color:#6b7280;font-size:13px;padding:6px 0;"><?php _e('Loan Amount', 'matrix-mlm'); ?></td>
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
<?php if ($status_lc === 'approved'): ?>
<p style="color:#4b5563;font-size:14px;line-height:1.5;margin:0;">
    <?php _e('Your loan application has been approved. Our team will be in touch shortly with disbursement details and your repayment schedule.', 'matrix-mlm'); ?>
</p>
<?php elseif ($status_lc === 'rejected'): ?>
<p style="color:#4b5563;font-size:14px;line-height:1.5;margin:0;">
    <?php _e('Unfortunately your loan application could not be approved at this time. You can submit a new application from the Benefits tab once you have addressed the reason for rejection. Please contact support if you have questions.', 'matrix-mlm'); ?>
</p>
<?php elseif ($status_lc === 'cancelled'): ?>
<p style="color:#4b5563;font-size:14px;line-height:1.5;margin:0;">
    <?php _e('Your loan application has been cancelled. You can submit a new application at any time from the Benefits tab.', 'matrix-mlm'); ?>
</p>
<?php endif; ?>
<?php
$content = ob_get_clean();
$footer_text = '';
include __DIR__ . '/base.php';
