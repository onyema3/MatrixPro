<?php
/**
 * Healthcare Application — Admin notification template
 *
 * Sent to the configured Application Review address (Settings →
 * Notifications → Application Review Notifications) every time a
 * member submits or resubmits the healthcare form. Fired by
 * Matrix_MLM_Notifications::send_admin_healthcare_application_notification()
 * after the row is persisted in matrix_healthcare_applications.
 *
 * Goal: reviewers can read the entire application from their inbox
 * and decide whether it warrants logging in to approve. Every
 * submitted field is reproduced in a labelled section, matching
 * the admin triage detail page's structure.
 *
 * Variables: $site_name, $tag, $is_resubmission, $applicant_label,
 *            $user (WP_User|null), $row (stdClass), $admin_url
 */
if (!defined('ABSPATH')) exit;

$banner_color = $is_resubmission ? '#f59e0b' : '#4f46e5';
$banner_bg    = $is_resubmission ? '#fffbeb' : '#eef2ff';
$banner_brd   = $is_resubmission ? '#fde68a' : '#c7d2fe';

$compose_address = static function (...$parts) {
    $clean = array_filter(array_map(static function ($p) {
        return trim((string) $p);
    }, $parts), static function ($p) {
        return $p !== '';
    });
    return implode(', ', $clean);
};

$enum_label = static function ($value) {
    $value = (string) $value;
    if ($value === '') return '';
    return ucwords(str_replace('_', ' ', $value));
};

$personal_rows = [
    __('First Name', 'matrix-mlm')     => esc_html((string) ($row->first_name ?? '')),
    __('Last Name', 'matrix-mlm')      => esc_html((string) ($row->last_name ?? '')),
    __('Middle Name', 'matrix-mlm')    => !empty($row->middle_name)
        ? esc_html((string) $row->middle_name)
        : '<em style="color:#9ca3af;">' . esc_html__('not provided', 'matrix-mlm') . '</em>',
    __('Email', 'matrix-mlm')          => esc_html((string) ($row->email ?? '')),
    __('Phone', 'matrix-mlm')          => '<code>' . esc_html((string) ($row->phone ?? '')) . '</code>',
    __('Date of Birth', 'matrix-mlm')  => esc_html((string) ($row->date_of_birth ?? '')),
    __('Gender', 'matrix-mlm')         => esc_html($enum_label($row->gender ?? '')),
    __('Marital Status', 'matrix-mlm') => esc_html($enum_label($row->marital_status ?? '')),
    __('Address', 'matrix-mlm')        => esc_html($compose_address(
        $row->address_line1 ?? '', $row->address_line2 ?? '',
        $row->city ?? '', $row->state ?? '',
        $row->zip_code ?? '', $row->country ?? ''
    )),
];

$identification_rows = [
    __('NIN', 'matrix-mlm')        => '<code>' . esc_html((string) ($row->nin ?? '')) . '</code>',
    __('Occupation', 'matrix-mlm') => !empty($row->occupation)
        ? esc_html((string) $row->occupation)
        : '<em style="color:#9ca3af;">' . esc_html__('not provided', 'matrix-mlm') . '</em>',
];

$plan_rows = [
    __('Plan Tier', 'matrix-mlm')          => '<strong>' . esc_html($enum_label($row->plan_tier ?? '')) . '</strong>',
    __('Coverage Type', 'matrix-mlm')      => esc_html($enum_label($row->coverage_type ?? '')),
    __('Preferred Hospital', 'matrix-mlm') => esc_html((string) ($row->preferred_hospital ?? '')),
    __('Dependants', 'matrix-mlm')         => intval($row->dependants_count ?? 0),
];

$medical_rows = [
    __('Blood Group', 'matrix-mlm')             => esc_html((string) ($row->blood_group ?? 'unknown')),
    __('Genotype', 'matrix-mlm')                => esc_html((string) ($row->genotype ?? 'unknown')),
    __('Height', 'matrix-mlm')                  => !empty($row->height_cm) ? esc_html($row->height_cm . ' cm') : '<em style="color:#9ca3af;">—</em>',
    __('Weight', 'matrix-mlm')                  => !empty($row->weight_kg) ? esc_html($row->weight_kg . ' kg') : '<em style="color:#9ca3af;">—</em>',
    __('Pre-existing Conditions', 'matrix-mlm') => !empty($row->pre_existing_conditions)
        ? nl2br(esc_html((string) $row->pre_existing_conditions))
        : '<em style="color:#9ca3af;">' . esc_html__('none reported', 'matrix-mlm') . '</em>',
    __('Allergies', 'matrix-mlm')               => !empty($row->allergies)
        ? nl2br(esc_html((string) $row->allergies))
        : '<em style="color:#9ca3af;">' . esc_html__('none reported', 'matrix-mlm') . '</em>',
    __('Current Medications', 'matrix-mlm')     => !empty($row->current_medications)
        ? nl2br(esc_html((string) $row->current_medications))
        : '<em style="color:#9ca3af;">' . esc_html__('none reported', 'matrix-mlm') . '</em>',
    __('Smoker', 'matrix-mlm')                  => !empty($row->is_smoker) ? esc_html__('Yes', 'matrix-mlm') : esc_html__('No', 'matrix-mlm'),
    __('Pregnant', 'matrix-mlm')                => !empty($row->is_pregnant) ? esc_html__('Yes', 'matrix-mlm') : esc_html__('No', 'matrix-mlm'),
];

$nok_rows = [
    __('Name', 'matrix-mlm')         => esc_html((string) ($row->nok_name ?? '')),
    __('Relationship', 'matrix-mlm') => esc_html((string) ($row->nok_relationship ?? '')),
    __('Phone', 'matrix-mlm')        => '<code>' . esc_html((string) ($row->nok_phone ?? '')) . '</code>',
];

$documents = [
    'passport_photo_url'  => __('Passport Photo', 'matrix-mlm'),
    'nin_slip_url'        => __('NIN Slip / Card', 'matrix-mlm'),
    'utility_bill_url'    => __('Utility Bill', 'matrix-mlm'),
    'medical_history_url' => __('Medical History (optional)', 'matrix-mlm'),
];

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
                /* translators: 1: applicant name, 2: plan tier */
                esc_html__('%1$s has updated their healthcare enrolment application (%2$s plan). The previous version has been replaced. Please re-review the details below before approving.', 'matrix-mlm'),
                '<strong>' . esc_html($applicant_label) . '</strong>',
                '<strong>' . esc_html($enum_label($row->plan_tier ?? '')) . '</strong>'
            );
        } else {
            printf(
                /* translators: 1: applicant name, 2: plan tier */
                esc_html__('%1$s has submitted a new healthcare enrolment application (%2$s plan). Please review the details below before approving.', 'matrix-mlm'),
                '<strong>' . esc_html($applicant_label) . '</strong>',
                '<strong>' . esc_html($enum_label($row->plan_tier ?? '')) . '</strong>'
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
                <?php echo esc_html($enum_label($row->status ?? 'pending')); ?>
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
<?php $render_section(__('Identification', 'matrix-mlm'), $identification_rows); ?>
<?php $render_section(__('Plan & Coverage', 'matrix-mlm'), $plan_rows); ?>
<?php $render_section(__('Medical Profile', 'matrix-mlm'), $medical_rows); ?>
<?php $render_section(__('Next of Kin', 'matrix-mlm'), $nok_rows); ?>

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
