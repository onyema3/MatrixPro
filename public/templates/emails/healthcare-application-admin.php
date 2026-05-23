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
 * Mirrors the 1.0.7 admin detail-page layout: the new sections
 * (Application Type / Parent's Information / Hospital Selection)
 * are always shown for current-form rows; the legacy sections
 * (Plan & Coverage, Medical Profile, Next of Kin, Documents) only
 * render when the row actually has data in those columns, so the
 * email stays readable for both pre-1.0.7 historical applications
 * and post-1.0.7 submissions.
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

$placeholder = static function ($label = null) {
    $text = $label ?: __('not provided', 'matrix-mlm');
    return '<em style="color:#9ca3af;">' . esc_html($text) . '</em>';
};

// ---------------------------------------------------------------
// Decode the row into the same conceptual sections as the admin
// detail page so the email lays out 1:1 with what reviewers see
// when they click through.
// ---------------------------------------------------------------

$applicant_type = isset($row->applicant_type) && $row->applicant_type !== ''
    ? (string) $row->applicant_type
    : 'adult';
$is_dependant = $applicant_type === 'dependant';

$type_rows = [
    __('Applying as', 'matrix-mlm') => '<strong>' . esc_html($enum_label($applicant_type)) . '</strong>',
];

$personal_label = $is_dependant
    ? __("Child's Information", 'matrix-mlm')
    : __('Personal Information', 'matrix-mlm');

$personal_rows = [
    ($is_dependant ? __('Child First Name', 'matrix-mlm') : __('First Name', 'matrix-mlm'))
        => esc_html((string) ($row->first_name ?? '')),
    ($is_dependant ? __('Child Last Name', 'matrix-mlm') : __('Last Name', 'matrix-mlm'))
        => esc_html((string) ($row->last_name ?? '')),
];
if (!empty($row->middle_name)) {
    $personal_rows[__('Middle Name', 'matrix-mlm')] = esc_html((string) $row->middle_name);
}
$personal_rows[__('Email', 'matrix-mlm')] = esc_html((string) ($row->email ?? ''));
$personal_rows[__('Phone', 'matrix-mlm')] = !empty($row->phone)
    ? '<code>' . esc_html((string) $row->phone) . '</code>'
    : $placeholder();
$personal_rows[__('WhatsApp', 'matrix-mlm')] = !empty($row->whatsapp)
    ? '<code>' . esc_html((string) $row->whatsapp) . '</code>'
    : $placeholder();
$personal_rows[__('Date of Birth', 'matrix-mlm')] = esc_html((string) ($row->date_of_birth ?? ''));
$personal_rows[($is_dependant ? __('Sex', 'matrix-mlm') : __('Gender', 'matrix-mlm'))]
    = esc_html($enum_label($row->gender ?? ''));
if (!empty($row->marital_status)) {
    $personal_rows[__('Marital Status', 'matrix-mlm')] = esc_html($enum_label($row->marital_status));
}
$personal_rows[__('Address', 'matrix-mlm')] = esc_html($compose_address(
    $row->address_line1 ?? '', $row->address_line2 ?? '',
    $row->city ?? '', $row->state ?? '',
    $row->zip_code ?? '', $row->country ?? ''
));

$has_parent = !empty($row->parent_first_name)
    || !empty($row->parent_last_name)
    || !empty($row->parent_phone)
    || !empty($row->parent_whatsapp);

$parent_rows = $has_parent ? [
    __('First Name', 'matrix-mlm') => esc_html((string) ($row->parent_first_name ?? '')),
    __('Last Name', 'matrix-mlm')  => esc_html((string) ($row->parent_last_name ?? '')),
    __('Phone', 'matrix-mlm')      => !empty($row->parent_phone)
        ? '<code>' . esc_html((string) $row->parent_phone) . '</code>'
        : $placeholder(),
    __('WhatsApp', 'matrix-mlm')   => !empty($row->parent_whatsapp)
        ? '<code>' . esc_html((string) $row->parent_whatsapp) . '</code>'
        : $placeholder(),
] : [];

$hospital_rows = [
    __('Hospital State', 'matrix-mlm') => !empty($row->hospital_state)
        ? esc_html((string) $row->hospital_state)
        : $placeholder(),
    __('Hospital of Choice', 'matrix-mlm') => !empty($row->preferred_hospital)
        ? esc_html((string) $row->preferred_hospital)
            . (!empty($row->hospital_id)
                ? ' <span style="color:#6b7280;font-size:11px;">(#' . intval($row->hospital_id) . ')</span>'
                : '')
        : $placeholder(),
];
if (!empty($row->policy_number)) {
    $hospital_rows[__('Policy Number', 'matrix-mlm')] = '<code>' . esc_html((string) $row->policy_number) . '</code>';
}

$has_identification = !empty($row->nin) || !empty($row->occupation);
$identification_rows = [];
if ($has_identification) {
    if (!empty($row->nin)) {
        $identification_rows[__('NIN', 'matrix-mlm')] = '<code>' . esc_html((string) $row->nin) . '</code>';
    }
    if (!empty($row->occupation)) {
        $identification_rows[__('Occupation', 'matrix-mlm')] = esc_html((string) $row->occupation);
    }
}

$has_plan = !empty($row->plan_tier)
    || !empty($row->coverage_type)
    || (int) ($row->dependants_count ?? 0) > 0;
$plan_rows = [];
if ($has_plan) {
    if (!empty($row->plan_tier)) {
        $plan_rows[__('Plan Tier', 'matrix-mlm')] = '<strong>' . esc_html($enum_label($row->plan_tier)) . '</strong>';
    }
    if (!empty($row->coverage_type)) {
        $plan_rows[__('Coverage Type', 'matrix-mlm')] = esc_html($enum_label($row->coverage_type));
    }
    if ((int) ($row->dependants_count ?? 0) > 0) {
        $plan_rows[__('Dependants', 'matrix-mlm')] = intval($row->dependants_count);
    }
}

$blood = (string) ($row->blood_group ?? 'unknown');
$geno  = (string) ($row->genotype ?? 'unknown');
$has_medical = ($blood !== '' && $blood !== 'unknown')
    || ($geno !== '' && $geno !== 'unknown')
    || !empty($row->height_cm)
    || !empty($row->weight_kg)
    || !empty($row->pre_existing_conditions)
    || !empty($row->allergies)
    || !empty($row->current_medications)
    || (int) ($row->is_smoker ?? 0) === 1
    || (int) ($row->is_pregnant ?? 0) === 1;
$medical_rows = $has_medical ? [
    __('Blood Group', 'matrix-mlm')             => esc_html($blood !== '' ? $blood : 'unknown'),
    __('Genotype', 'matrix-mlm')                => esc_html($geno !== '' ? $geno : 'unknown'),
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
] : [];

$has_nok = !empty($row->nok_name) || !empty($row->nok_relationship) || !empty($row->nok_phone);
$nok_rows = $has_nok ? [
    __('Name', 'matrix-mlm')         => esc_html((string) ($row->nok_name ?? '')),
    __('Relationship', 'matrix-mlm') => esc_html((string) ($row->nok_relationship ?? '')),
    __('Phone', 'matrix-mlm')        => !empty($row->nok_phone)
        ? '<code>' . esc_html((string) $row->nok_phone) . '</code>'
        : $placeholder(),
] : [];

$documents = [
    'passport_photo_url'  => __('Passport Photo', 'matrix-mlm'),
    'nin_slip_url'        => __('NIN Slip / Card', 'matrix-mlm'),
    'utility_bill_url'    => __('Utility Bill', 'matrix-mlm'),
    'medical_history_url' => __('Medical History (optional)', 'matrix-mlm'),
];
$has_documents = false;
foreach ($documents as $col => $label) {
    if (!empty($row->{$col})) {
        $has_documents = true;
        break;
    }
}

$render_section = static function ($title, array $rows, $hint = '') {
    if (empty($rows)) return;
    ?>
    <h3 style="margin:24px 0 8px;color:#1f2937;font-size:15px;font-weight:600;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">
        <?php echo esc_html($title); ?>
    </h3>
    <?php if ($hint !== ''): ?>
        <p style="margin:0 0 6px;font-size:11px;color:#6b7280;font-style:italic;">
            <?php echo esc_html($hint); ?>
        </p>
    <?php endif; ?>
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

$legacy_hint = __('Legacy field collected by the pre-1.0.7 form — kept on this row so historical applications still display in full.', 'matrix-mlm');

ob_start();
?>
<h2 style="color:#1f2937;font-size:20px;margin:0 0 16px;font-weight:600;">
    <?php echo esc_html($tag); ?>
</h2>

<div style="background:<?php echo $banner_bg; ?>;border:1px solid <?php echo $banner_brd; ?>;border-left:4px solid <?php echo $banner_color; ?>;border-radius:6px;padding:14px 16px;margin:0 0 20px;">
    <p style="margin:0;color:#374151;font-size:14px;line-height:1.5;">
        <?php
        $type_word = $is_dependant
            ? esc_html__('Dependant', 'matrix-mlm')
            : esc_html__('Adult', 'matrix-mlm');
        if ($is_resubmission) {
            printf(
                /* translators: 1: applicant name, 2: applicant type (Adult or Dependant) */
                esc_html__('%1$s has updated their healthcare enrolment application (%2$s). The previous version has been replaced. Please re-review the details below before approving.', 'matrix-mlm'),
                '<strong>' . esc_html($applicant_label) . '</strong>',
                '<strong>' . $type_word . '</strong>'
            );
        } else {
            printf(
                /* translators: 1: applicant name, 2: applicant type (Adult or Dependant) */
                esc_html__('%1$s has submitted a new healthcare enrolment application (%2$s). Please review the details below before approving.', 'matrix-mlm'),
                '<strong>' . esc_html($applicant_label) . '</strong>',
                '<strong>' . $type_word . '</strong>'
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

<?php $render_section(__('Application Type', 'matrix-mlm'), $type_rows); ?>
<?php $render_section($personal_label, $personal_rows); ?>
<?php if (!empty($parent_rows)) $render_section(__("Parent's Information", 'matrix-mlm'), $parent_rows); ?>
<?php $render_section(__('Hospital Selection', 'matrix-mlm'), $hospital_rows); ?>
<?php if (!empty($identification_rows)) $render_section(__('Identification', 'matrix-mlm'), $identification_rows); ?>
<?php if (!empty($plan_rows)) $render_section(__('Plan & Coverage', 'matrix-mlm'), $plan_rows, $legacy_hint); ?>
<?php if (!empty($medical_rows)) $render_section(__('Medical Profile', 'matrix-mlm'), $medical_rows, $legacy_hint); ?>
<?php if (!empty($nok_rows)) $render_section(__('Next of Kin', 'matrix-mlm'), $nok_rows, $legacy_hint); ?>

<?php if ($has_documents): ?>
<h3 style="margin:24px 0 8px;color:#1f2937;font-size:15px;font-weight:600;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">
    <?php esc_html_e('Documents', 'matrix-mlm'); ?>
</h3>
<p style="margin:0 0 6px;font-size:11px;color:#6b7280;font-style:italic;">
    <?php echo esc_html($legacy_hint); ?>
</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 16px;">
    <?php foreach ($documents as $col => $label):
        $url = isset($row->{$col}) ? (string) $row->{$col} : '';
        if ($url === '') continue;
    ?>
    <tr>
        <td style="padding:6px 12px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#6b7280;width:35%;vertical-align:top;">
            <?php echo esc_html($label); ?>
        </td>
        <td style="padding:6px 12px;border-bottom:1px solid #f3f4f6;font-size:14px;color:#1f2937;">
            <a href="<?php echo esc_url($url); ?>" style="color:#4f46e5;text-decoration:none;font-weight:500;">
                <?php esc_html_e('View document', 'matrix-mlm'); ?> &rarr;
            </a>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>

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
