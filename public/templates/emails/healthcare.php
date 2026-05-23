<?php
/**
 * Healthcare Application — User notification template
 *
 * Sent by Matrix_MLM_Notifications::send_healthcare_notification()
 * when an admin transitions a row in matrix_healthcare_applications
 * to a user-visible decision state (approved / rejected /
 * cancelled). The internal under_review and pending statuses do
 * NOT call this template — see the admin healthcare triage class
 * for that gating.
 *
 * Variables: $username, $status, $site_name, $policy_number
 */
if (!defined('ABSPATH')) exit;

$status_lc = strtolower((string) $status);
$policy_number = isset($policy_number) ? (string) $policy_number : '';

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
        $status_color  = '#d97706';
        $status_bg     = '#fffbeb';
        $status_border = '#fde68a';
        break;
}

ob_start();
?>
<h2 style="color:#1f2937;font-size:20px;margin:0 0 16px;font-weight:600;"><?php _e('Healthcare Application Update', 'matrix-mlm'); ?></h2>
<p style="color:#4b5563;font-size:15px;line-height:1.6;margin:0 0 12px;">
    <?php printf(__('Hello <strong>%s</strong>,', 'matrix-mlm'), esc_html($username)); ?>
</p>
<p style="color:#4b5563;font-size:15px;line-height:1.6;margin:0 0 24px;">
    <?php _e('Here is an update on your HMO enrolment application:', 'matrix-mlm'); ?>
</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:<?php echo $status_bg; ?>;border:1px solid <?php echo $status_border; ?>;border-radius:8px;margin:0 0 24px;">
<tr><td style="padding:20px 24px;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
    <tr>
        <td style="color:#6b7280;font-size:13px;padding:6px 0;"><?php _e('Status', 'matrix-mlm'); ?></td>
        <td align="right" style="padding:6px 0;">
            <span style="background:<?php echo $status_color; ?>;color:#ffffff;padding:4px 12px;border-radius:12px;font-size:12px;font-weight:600;"><?php echo esc_html($status); ?></span>
        </td>
    </tr>
    <?php if ($policy_number !== '' && $status_lc === 'approved'): ?>
    <tr>
        <td style="color:#6b7280;font-size:13px;padding:6px 0;"><?php _e('Policy Number', 'matrix-mlm'); ?></td>
        <td align="right" style="color:#1f2937;font-size:16px;font-weight:700;padding:6px 0;font-family:'Courier New',Courier,monospace;"><?php echo esc_html($policy_number); ?></td>
    </tr>
    <?php endif; ?>
    </table>
</td></tr>
</table>
<?php if ($status_lc === 'approved'): ?>
<p style="color:#4b5563;font-size:14px;line-height:1.5;margin:0;">
    <?php
    if ($policy_number !== '') {
        printf(
            /* translators: %s: policy number */
            esc_html__('Your healthcare enrolment has been approved. Quote your policy number (%s) when visiting your preferred hospital. Our partner HMO will be in touch shortly with your benefits handbook and any onboarding details.', 'matrix-mlm'),
            '<strong>' . esc_html($policy_number) . '</strong>'
        );
    } else {
        esc_html_e('Your healthcare enrolment has been approved. Our partner HMO will be in touch shortly with your policy number, benefits handbook, and any onboarding details.', 'matrix-mlm');
    }
    ?>
</p>
<?php elseif ($status_lc === 'rejected'): ?>
<p style="color:#4b5563;font-size:14px;line-height:1.5;margin:0;">
    <?php _e('Unfortunately your healthcare enrolment could not be approved at this time. You can submit a new application from the Benefits tab once you have addressed the reason for rejection. Please contact support if you have questions.', 'matrix-mlm'); ?>
</p>
<?php elseif ($status_lc === 'cancelled'): ?>
<p style="color:#4b5563;font-size:14px;line-height:1.5;margin:0;">
    <?php _e('Your healthcare application has been cancelled. You can submit a new application at any time from the Benefits tab.', 'matrix-mlm'); ?>
</p>
<?php endif; ?>
<?php
$content = ob_get_clean();
$footer_text = '';
include __DIR__ . '/base.php';
