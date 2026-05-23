<?php
/**
 * Loan Application — Admin notification template
 *
 * Sent to the configured Application Review address (Settings →
 * Notifications → Application Review Notifications) every time a
 * member submits or resubmits the business-loan form. Fired by
 * Matrix_MLM_Notifications::send_admin_loan_application_notification()
 * after the row is persisted in matrix_loan_applications.
 *
 * Goal: reviewers can read the entire 40+ field application from
 * their inbox and decide whether it warrants logging in to approve.
 * Every section of the source form (Personal / Account / Project /
 * Documents / Guarantor) is reproduced as its own labelled section,
 * matching the structure of the admin triage detail page.
 *
 * Document links point at the wp_handle_upload URLs stored on the
 * row — Reviewers click through to the original PDF / image without
 * having to log in first. (URLs are not signed, but they live under
 * wp-content/uploads which is already public; same surface area as
 * any other WP attachment.)
 *
 * Variables: $site_name, $tag, $is_resubmission, $applicant_label,
 *            $user (WP_User|null), $row (stdClass), $currency,
 *            $amount_str, $admin_url
 */
if (!defined('ABSPATH')) exit;

$banner_color = $is_resubmission ? '#f59e0b' : '#4f46e5';
$banner_bg    = $is_resubmission ? '#fffbeb' : '#eef2ff';
$banner_brd   = $is_resubmission ? '#fde68a' : '#c7d2fe';

/**
 * Compose a one-line address string from the per-line address columns,
 * skipping empty parts so we don't emit ", , Lagos" for a row with no
 * line 2 or zip.
 */
$compose_address = static function (...$parts) {
    $clean = array_filter(array_map(static function ($p) {
        return trim((string) $p);
    }, $parts), static function ($p) {
        return $p !== '';
    });
    return implode(', ', $clean);
};

$personal_rows = [
    __('First Name', 'matrix-mlm') => esc_html((string) ($row->first_name ?? '')),
    __('Last Name', 'matrix-mlm')  => esc_html((string) ($row->last_name ?? '')),
    __('Email', 'matrix-mlm')      => esc_html((string) ($row->email ?? '')),
    __('Phone', 'matrix-mlm')      => '<code>' . esc_html((string) ($row->phone ?? '')) . '</code>',
    __('Address', 'matrix-mlm')    => esc_html($compose_address(
        $row->address_line1 ?? '', $row->address_line2 ?? '',
        $row->city ?? '', $row->state ?? '',
        $row->zip_code ?? '', $row->country ?? ''
    )),
    __('Date of Birth', 'matrix-mlm') => esc_html((string) ($row->date_of_birth ?? '')),
    __('Trade Name', 'matrix-mlm') => !empty($row->trade_name)
        ? esc_html((string) $row->trade_name)
        : '<em style="color:#9ca3af;">' . esc_html__('not provided', 'matrix-mlm') . '</em>',
];

$account_rows = [
    __('Bank', 'matrix-mlm')           => esc_html((string) ($row->bank_name ?? '')),
    __('Account Number', 'matrix-mlm') => '<code>' . esc_html((string) ($row->account_number ?? '')) . '</code>',
    __('Account Name', 'matrix-mlm')   => esc_html((string) ($row->account_name ?? '')),
    __('BVN', 'matrix-mlm')            => '<code>' . esc_html((string) ($row->bvn ?? '')) . '</code>',
];

$project_rows = [
    __('Applying As', 'matrix-mlm') => esc_html(ucwords(str_replace('_', ' ', (string) ($row->applying_as ?? '')))),
    __('Business Address', 'matrix-mlm') => esc_html($compose_address(
        $row->business_address_line1 ?? '', $row->business_address_line2 ?? '',
        $row->business_city ?? '', $row->business_state ?? '',
        $row->business_zip ?? '', $row->business_country ?? ''
    )),
    __('Loan Reason', 'matrix-mlm')         => esc_html(ucwords(str_replace('_', ' ', (string) ($row->loan_reason ?? '')))),
    __('Project Gross Value', 'matrix-mlm') => '<strong>' . esc_html($currency . number_format((float) ($row->project_gross_value ?? 0), 2)) . '</strong>',
    __('Loan Amount', 'matrix-mlm')         => '<strong style="color:#4f46e5;">' . esc_html($amount_str) . '</strong>',
    __('Repayment Plan', 'matrix-mlm')      => esc_html(ucfirst((string) ($row->repayment_plan ?? ''))),
    __('Has Assets &amp; Liabilities Statement?', 'matrix-mlm') => !empty($row->has_assets_statement) ? esc_html__('Yes', 'matrix-mlm') : esc_html__('No', 'matrix-mlm'),
    __('Previously Financed?', 'matrix-mlm') => !empty($row->previously_financed) ? esc_html__('Yes', 'matrix-mlm') : esc_html__('No', 'matrix-mlm'),
    __('Agreed to Terms', 'matrix-mlm')     => !empty($row->agreed_terms)
        ? '<span style="background:#ecfdf5;color:#065f46;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600;">' . esc_html__('Yes', 'matrix-mlm') . '</span>'
        : '<span style="background:#fef2f2;color:#991b1b;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600;">' . esc_html__('No', 'matrix-mlm') . '</span>',
];

$guarantor_rows = [
    __('First Name', 'matrix-mlm') => esc_html((string) ($row->guarantor_first_name ?? '')),
    __('Last Name', 'matrix-mlm')  => esc_html((string) ($row->guarantor_last_name ?? '')),
    __('Phone', 'matrix-mlm')      => '<code>' . esc_html((string) ($row->guarantor_phone ?? '')) . '</code>',
];

$documents = [
    'nin_file_url'           => __('NIN', 'matrix-mlm'),
    'utility_bill_url'       => __('Utility Bill', 'matrix-mlm'),
    'valid_id_url'           => __('Valid ID', 'matrix-mlm'),
    'passport_photo_url'     => __('Passport Photo', 'matrix-mlm'),
    'guarantor_valid_id_url' => __("Guarantor's Valid ID", 'matrix-mlm'),
    'guarantor_passport_url' => __("Guarantor's Passport Photo", 'matrix-mlm'),
    'marketing_material_url' => __('Project Marketing Material (optional)', 'matrix-mlm'),
    'project_info_url'       => __('Project Information (optional)', 'matrix-mlm'),
];

/**
 * Render one labelled-table section consistently. Inlined here
 * instead of a helper so the template stays self-contained — emails
 * are rendered with extract() in the parent and we'd rather not bake
 * additional symbol dependencies into the rendering pipeline.
 */
$render_section = static function ($title, array $rows) {
    if (empty($rows)) return;
    ?>
    <h3 style="margin:24px 0 8px;color:#1f2937;font-size:15px;font-weight:600;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">
        <?php echo esc_html($title); ?>
    </h3>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 8px;">
        <?php foreach ($rows as $label => $value): ?>
        <tr>
            <td style="padding:6px 12px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#6b7280;width:35%;vertical-align:top;">
                <?php echo esc_html($label); ?>
            </td>
            <td style="padding:6px 12px;border-bottom:1px solid #f3f4f6;font-size:14px;color:#1f2937;">
                <?php echo $value; // Pre-escaped above. ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php
};

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
                /* translators: 1: applicant name, 2: loan amount */
                esc_html__('%1$s has updated their business-loan application for %2$s. The previous version has been replaced. Please re-review the details below before approving.', 'matrix-mlm'),
                '<strong>' . esc_html($applicant_label) . '</strong>',
                '<strong>' . esc_html($amount_str) . '</strong>'
            );
        } else {
            printf(
                /* translators: 1: applicant name, 2: loan amount */
                esc_html__('%1$s has submitted a new business-loan application for %2$s. Please review the details below before approving.', 'matrix-mlm'),
                '<strong>' . esc_html($applicant_label) . '</strong>',
                '<strong>' . esc_html($amount_str) . '</strong>'
            );
        }
        ?>
    </p>
</div>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 12px;">
    <tr>
        <td style="padding:6px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#6b7280;width:35%;vertical-align:top;">
            <?php esc_html_e('Member', 'matrix-mlm'); ?>
        </td>
        <td style="padding:6px 12px;border-bottom:1px solid #e5e7eb;font-size:14px;color:#1f2937;">
            <?php if ($user): ?>
                <strong><?php echo esc_html($user->display_name ?: $user->user_login); ?></strong>
                &mdash; <?php echo esc_html($user->user_email); ?>
            <?php else: ?>
                <em style="color:#9ca3af;"><?php
                    /* translators: %d: user id */
                    printf(esc_html__('user #%d (deleted)', 'matrix-mlm'), intval($row->user_id ?? 0));
                ?></em>
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <td style="padding:6px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#6b7280;vertical-align:top;">
            <?php esc_html_e('Application Status', 'matrix-mlm'); ?>
        </td>
        <td style="padding:6px 12px;border-bottom:1px solid #e5e7eb;font-size:14px;color:#1f2937;">
            <span style="background:#fffbeb;color:#92400e;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600;text-transform:uppercase;">
                <?php echo esc_html(ucwords(str_replace('_', ' ', (string) ($row->status ?? 'pending')))); ?>
            </span>
        </td>
    </tr>
    <tr>
        <td style="padding:6px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#6b7280;vertical-align:top;">
            <?php esc_html_e('Submitted', 'matrix-mlm'); ?>
        </td>
        <td style="padding:6px 12px;border-bottom:1px solid #e5e7eb;font-size:14px;color:#1f2937;">
            <?php echo esc_html((string) ($row->created_at ?? '')); ?>
        </td>
    </tr>
</table>

<?php $render_section(__('Personal Information', 'matrix-mlm'), $personal_rows); ?>
<?php $render_section(__('Customer Account Details', 'matrix-mlm'), $account_rows); ?>
<?php $render_section(__('Project Details', 'matrix-mlm'), $project_rows); ?>
<?php $render_section(__("Guarantor's Details", 'matrix-mlm'), $guarantor_rows); ?>

<h3 style="margin:24px 0 8px;color:#1f2937;font-size:15px;font-weight:600;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">
    <?php esc_html_e('Documents', 'matrix-mlm'); ?>
</h3>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 16px;">
    <?php foreach ($documents as $col => $label):
        $url = isset($row->{$col}) ? (string) $row->{$col} : '';
    ?>
    <tr>
        <td style="padding:6px 12px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#6b7280;width:35%;vertical-align:top;">
            <?php echo esc_html($label); ?>
        </td>
        <td style="padding:6px 12px;border-bottom:1px solid #f3f4f6;font-size:14px;color:#1f2937;">
            <?php if ($url !== ''): ?>
                <a href="<?php echo esc_url($url); ?>" style="color:#4f46e5;text-decoration:none;font-weight:500;">
                    <?php esc_html_e('View document', 'matrix-mlm'); ?> &rarr;
                </a>
            <?php else: ?>
                <em style="color:#9ca3af;"><?php esc_html_e('not uploaded', 'matrix-mlm'); ?></em>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
</table>

<p style="text-align:center;margin:24px 0 8px;">
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
