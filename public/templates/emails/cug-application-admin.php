<?php
/**
 * CUG Application — Admin notification template
 *
 * Sent to the configured Application Review address (Settings →
 * Notifications → Application Review Notifications) every time a
 * member submits or resubmits the CUG enrolment form. Fired by
 * Matrix_MLM_Notifications::send_admin_cug_application_notification()
 * after the row is persisted in matrix_cug_requests.
 *
 * Goal: reviewers should be able to read the entire application from
 * their inbox and decide whether it looks legitimate before logging
 * in to the admin to approve. Every submitted field is rendered in a
 * labelled key/value table; the admin URL deep-links straight to the
 * triage detail page.
 *
 * Variables: $site_name, $tag, $is_resubmission, $applicant_label,
 *            $user (WP_User|null), $row (stdClass), $admin_url
 */
if (!defined('ABSPATH')) exit;

$banner_color = $is_resubmission ? '#f59e0b' : '#4f46e5';
$banner_bg    = $is_resubmission ? '#fffbeb' : '#eef2ff';
$banner_brd   = $is_resubmission ? '#fde68a' : '#c7d2fe';

$rows = [
    __('First Name', 'matrix-mlm') => $row->first_name ?? '',
    __('Last Name', 'matrix-mlm')  => $row->last_name ?? '',
    __('NIN', 'matrix-mlm')        => isset($row->nin) ? '<code>' . esc_html((string) $row->nin) . '</code>' : '',
    __('Existing Airtel Number', 'matrix-mlm') => !empty($row->airtel_number)
        ? '<code>' . esc_html((string) $row->airtel_number) . '</code>'
        : '<em style="color:#9ca3af;">' . esc_html__('not provided', 'matrix-mlm') . '</em>',
    __('Member', 'matrix-mlm') => $user
        ? '<strong>' . esc_html($user->display_name ?: $user->user_login) . '</strong> &mdash; ' . esc_html($user->user_email)
        : sprintf(
            /* translators: %d: user id */
            esc_html__('user #%d (deleted)', 'matrix-mlm'),
            intval($row->user_id ?? 0)
        ),
    __('Submitted', 'matrix-mlm') => isset($row->created_at) ? esc_html((string) $row->created_at) : '',
    __('Last Updated', 'matrix-mlm') => isset($row->updated_at) ? esc_html((string) $row->updated_at) : '',
    __('Application Status', 'matrix-mlm') => '<span style="background:#fffbeb;color:#92400e;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600;text-transform:uppercase;">' . esc_html(ucwords(str_replace('_', ' ', (string) ($row->status ?? 'pending')))) . '</span>',
];

ob_start();
?>
<h2 style="color:#1f2937;font-size:20px;margin:0 0 16px;font-weight:600;">
    <?php echo esc_html($tag); ?>
</h2>

<div style="background:<?php echo $banner_bg; ?>;border:1px solid <?php echo $banner_brd; ?>;border-left:4px solid <?php echo $banner_color; ?>;border-radius:6px;padding:14px 16px;margin:0 0 20px;">
    <p style="margin:0;color:#374151;font-size:14px;line-height:1.5;">
        <?php
        if ($is_resubmission) {
            printf(
                /* translators: %s: applicant name */
                esc_html__('%s has updated their CUG enrolment application. The previous version has been replaced. Please re-review the details below before approving.', 'matrix-mlm'),
                '<strong>' . esc_html($applicant_label) . '</strong>'
            );
        } else {
            printf(
                /* translators: %s: applicant name */
                esc_html__('%s has submitted a new CUG enrolment application. Please review the details below before approving.', 'matrix-mlm'),
                '<strong>' . esc_html($applicant_label) . '</strong>'
            );
        }
        ?>
    </p>
</div>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 24px;">
    <?php foreach ($rows as $label => $value): ?>
    <tr>
        <td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#6b7280;width:35%;vertical-align:top;">
            <?php echo esc_html($label); ?>
        </td>
        <td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:14px;color:#1f2937;">
            <?php
            // Values are pre-escaped by the builder above (some are
            // wrapped in <code> / <strong> / <span>), so emit raw.
            echo $value;
            ?>
        </td>
    </tr>
    <?php endforeach; ?>
</table>

<p style="text-align:center;margin:0 0 8px;">
    <a href="<?php echo esc_url($admin_url); ?>"
       style="background:#4f46e5;color:#ffffff;padding:12px 28px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600;font-size:14px;">
        <?php esc_html_e('Open in Admin', 'matrix-mlm'); ?>
    </a>
</p>
<p style="text-align:center;color:#9ca3af;font-size:12px;margin:0;">
    <?php esc_html_e('You are receiving this because your address is configured under Matrix MLM → Settings → Notifications.', 'matrix-mlm'); ?>
</p>
<?php
$content = ob_get_clean();
$footer_text = '';
include __DIR__ . '/base.php';
