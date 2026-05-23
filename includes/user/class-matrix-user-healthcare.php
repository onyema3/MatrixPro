<?php
/**
 * Healthcare (HMO) enrolment application form (Benefits tab)
 *
 * Renders the multi-section Apply-for-Healthcare modal triggered
 * from the healthcare card on the Benefits tab and processes
 * submissions into wp_matrix_healthcare_applications. Sibling of
 * Matrix_MLM_User_Loan and Matrix_MLM_User_CUG: same authenticated
 * AJAX endpoint pattern, server-side validation per field, UPSERT
 * semantics on user_id, lazy-resolved admin-ajax URL, and an
 * auto-closing modal on success.
 *
 * The form has five sections (Personal Information / Identification /
 * Plan & Coverage / Medical Profile / Next of Kin) plus a Documents
 * panel for four uploads. Lighter than the loan form by design — no
 * guarantor block, no T&Cs legal copy, no bank-account section. HMO
 * partners typically collect bank/billing details at the policy-issue
 * stage rather than at enrolment intake, and T&Cs are accepted at
 * the policy-document stage with the partner directly.
 *
 * Reusable helpers from Matrix_MLM_User_Loan are referenced directly
 * (countries(), DOC_MIMES, PHOTO_MIMES, MAX_FILE_BYTES) so the two
 * forms stay in lockstep on shared concerns without duplicating the
 * 200-entry country list or the upload constraints.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_User_Healthcare {

    /** AJAX action name — mirrored in the inline JS submit handler.
     *  Kept as a constant so the two ends never drift. */
    const AJAX_ACTION = 'matrix_submit_healthcare';

    const GENDERS         = ['male', 'female', 'other'];
    const MARITAL_STATUSES = ['single', 'married', 'divorced', 'widowed'];
    const PLAN_TIERS      = ['basic', 'standard', 'premium', 'family'];
    const COVERAGE_TYPES  = ['individual', 'family'];
    const BLOOD_GROUPS    = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'unknown'];
    const GENOTYPES       = ['AA', 'AS', 'SS', 'AC', 'SC', 'unknown'];

    /** Age window. 0 isn't allowed (prevents accidental bare-default
     *  date input submissions); 100 is a generous upper bound that
     *  covers every realistic HMO enrolee. Operators who need to
     *  enrol minors should still pass — newborns are routine for
     *  family plans. */
    const MIN_AGE = 0;
    const MAX_AGE = 100;

    /** Reasonable bounds for self-reported vitals. Out-of-range
     *  values are almost certainly typos (cm vs in, kg vs lb) and
     *  rejecting them at the form is friendlier than letting a
     *  reviewer wonder why a 350cm-tall enrolee just applied. */
    const MIN_HEIGHT_CM = 30;
    const MAX_HEIGHT_CM = 250;
    const MIN_WEIGHT_KG = 1;
    const MAX_WEIGHT_KG = 400;

    /** Family-plan dependant ceiling. Zero is allowed (a family
     *  plan with no dependants today, but the user expects to add
     *  some later). 12 is a generous upper bound that covers every
     *  realistic Nigerian HMO family plan. */
    const MAX_DEPENDANTS = 12;

    public function __construct() {
        // Authenticated-only — anonymous healthcare submissions are
        // nonsense, and we want the upload pipeline gated on a real
        // user_id so the unique-filename callback can namespace files
        // without the leading-zero collision risk a guest user_id
        // would introduce.
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajax_submit']);
    }

    /**
     * Handle the submission.
     *
     * Response shape (matches loan/CUG):
     *   success: { message: string, status: 'pending' }
     *   error:   { message: string, field?: string }
     *
     * Validation order is section-by-section and each field
     * validator returns the column subset ready for $wpdb. The
     * `field` key on errors lets the JS scroll-to and outline the
     * exact failing input.
     */
    public function ajax_submit() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            self::error(__('You must be logged in to apply.', 'matrix-mlm'));
        }
        if (!self::user_is_eligible($user_id)) {
            self::error(__('Subscribe to a plan to apply for the healthcare benefit.', 'matrix-mlm'));
        }
        if (!self::table_exists()) {
            if (class_exists('Matrix_MLM_Database')) {
                Matrix_MLM_Database::maybe_upgrade();
            }
            if (!self::table_exists()) {
                self::error(__('Healthcare enrolment is not available right now. Please try again in a moment.', 'matrix-mlm'));
            }
        }

        $data = [];
        $data += self::validate_personal($_POST);
        $data += self::validate_identification($_POST);
        $data += self::validate_plan($_POST);
        $data += self::validate_medical($_POST, $data['gender'] ?? '');
        $data += self::validate_nok($_POST);

        // File uploads run last so we don't write any documents to
        // disk for a submission that would have been rejected by a
        // text-field validator anyway. Resubmissions reuse stored
        // URLs when the operator left the upload slot empty.
        $existing = self::get_user_request($user_id);
        $data += self::handle_uploads($user_id, $existing);

        $data['user_id']    = $user_id;
        $data['status']     = 'pending';
        $data['updated_at'] = current_time('mysql');
        $message = self::save($user_id, $existing, $data);

        // Notify the configured review team. Failures inside the
        // notifier are swallowed so a misconfigured SMTP can't break
        // the user-facing submission flow.
        if (class_exists('Matrix_MLM_Notifications')) {
            $persisted = self::get_user_request($user_id);
            if ($persisted) {
                Matrix_MLM_Notifications::send_admin_healthcare_application_notification(
                    $persisted,
                    $existing !== null
                );
            }
        }

        wp_send_json_success([
            'message' => $message,
            'status'  => 'pending',
        ]);
    }

    // ============================================================
    // Section validators — each returns a column-keyed array ready
    // to merge into $data, or short-circuits via self::error() with
    // a per-field key so the JS can highlight the failing input.
    // ============================================================

    private static function validate_personal($post) {
        $first = self::txt($post, 'first_name');
        self::require_len($first, 2, 60, __('First name must be between 2 and 60 characters.', 'matrix-mlm'), 'first_name');

        $last = self::txt($post, 'last_name');
        self::require_len($last, 2, 60, __('Last name must be between 2 and 60 characters.', 'matrix-mlm'), 'last_name');

        $middle = self::txt($post, 'middle_name');
        if (mb_strlen($middle) > 60) {
            self::error(__('Middle name is too long.', 'matrix-mlm'), 'middle_name');
        }

        $email = sanitize_email(self::raw($post, 'email'));
        if ($email === '' || !is_email($email) || mb_strlen($email) > 120) {
            self::error(__('Enter a valid email address.', 'matrix-mlm'), 'email');
        }

        $phone = self::digits(self::raw($post, 'phone'));
        if (strlen($phone) < 10 || strlen($phone) > 14) {
            self::error(__('Enter a valid phone number.', 'matrix-mlm'), 'phone');
        }

        $dob_raw = self::raw($post, 'date_of_birth');
        $dob_ts  = strtotime($dob_raw);
        if (!$dob_raw || $dob_ts === false) {
            self::error(__('Enter a valid date of birth.', 'matrix-mlm'), 'date_of_birth');
        }
        $age = (int) date('Y') - (int) date('Y', $dob_ts) - (date('md') < date('md', $dob_ts) ? 1 : 0);
        if ($age < self::MIN_AGE || $age > self::MAX_AGE) {
            self::error(sprintf(
                /* translators: 1: min age, 2: max age */
                __('Date of birth implies an age outside the allowed range (%1$d-%2$d years).', 'matrix-mlm'),
                self::MIN_AGE, self::MAX_AGE
            ), 'date_of_birth');
        }

        $gender = self::txt($post, 'gender');
        if (!in_array($gender, self::GENDERS, true)) {
            self::error(__('Choose your gender.', 'matrix-mlm'), 'gender');
        }

        $marital = self::txt($post, 'marital_status');
        if (!in_array($marital, self::MARITAL_STATUSES, true)) {
            self::error(__('Choose your marital status.', 'matrix-mlm'), 'marital_status');
        }

        $address1 = self::txt($post, 'address_line1');
        self::require_len($address1, 5, 200, __('Address Line 1 is required.', 'matrix-mlm'), 'address_line1');

        $address2 = self::txt($post, 'address_line2');
        if (mb_strlen($address2) > 200) {
            self::error(__('Address Line 2 is too long.', 'matrix-mlm'), 'address_line2');
        }

        $city = self::txt($post, 'city');
        self::require_len($city, 2, 100, __('City is required.', 'matrix-mlm'), 'city');

        $state = self::txt($post, 'state');
        self::require_len($state, 2, 100, __('State is required.', 'matrix-mlm'), 'state');

        $zip = self::txt($post, 'zip_code');
        if (mb_strlen($zip) > 20) {
            self::error(__('Zip code is too long.', 'matrix-mlm'), 'zip_code');
        }

        $country = self::txt($post, 'country');
        if (!in_array($country, self::countries(), true)) {
            self::error(__('Choose a country from the list.', 'matrix-mlm'), 'country');
        }

        return [
            'first_name'     => $first,
            'last_name'      => $last,
            'middle_name'    => $middle !== '' ? $middle : null,
            'email'          => $email,
            'phone'          => $phone,
            'date_of_birth'  => date('Y-m-d', $dob_ts),
            'gender'         => $gender,
            'marital_status' => $marital,
            'address_line1'  => $address1,
            'address_line2'  => $address2 !== '' ? $address2 : null,
            'city'           => $city,
            'state'          => $state,
            'zip_code'       => $zip !== '' ? $zip : null,
            'country'        => $country,
        ];
    }

    private static function validate_identification($post) {
        $nin = self::digits(self::raw($post, 'nin'));
        if (!preg_match('/^\d{11}$/', $nin)) {
            self::error(__('NIN must be 11 digits.', 'matrix-mlm'), 'nin');
        }

        $occ = self::txt($post, 'occupation');
        if (mb_strlen($occ) > 120) {
            self::error(__('Occupation is too long.', 'matrix-mlm'), 'occupation');
        }

        return [
            'nin'        => $nin,
            'occupation' => $occ !== '' ? $occ : null,
        ];
    }

    private static function validate_plan($post) {
        $tier = self::txt($post, 'plan_tier');
        if (!in_array($tier, self::PLAN_TIERS, true)) {
            self::error(__('Choose a plan tier.', 'matrix-mlm'), 'plan_tier');
        }

        $coverage = self::txt($post, 'coverage_type');
        if (!in_array($coverage, self::COVERAGE_TYPES, true)) {
            self::error(__('Choose a coverage type.', 'matrix-mlm'), 'coverage_type');
        }

        $hospital = self::txt($post, 'preferred_hospital');
        self::require_len($hospital, 2, 200, __('Preferred hospital is required.', 'matrix-mlm'), 'preferred_hospital');

        $dependants = (int) self::raw($post, 'dependants_count');
        if ($dependants < 0 || $dependants > self::MAX_DEPENDANTS) {
            self::error(sprintf(
                /* translators: %d: dependant cap */
                __('Dependants count must be between 0 and %d.', 'matrix-mlm'),
                self::MAX_DEPENDANTS
            ), 'dependants_count');
        }

        // Family/individual coverage cross-check. An individual
        // policy with dependants is contradictory; family coverage
        // with zero dependants today is fine (operator may add them
        // later) but warn-via-rejection if the tier is also family
        // and dependants are zero — that's almost certainly a
        // dropdown the user forgot. We allow it though, because some
        // family plans cover spouse-only and dependants_count = 0
        // is the legitimate spouse-only signal.
        if ($coverage === 'individual' && $dependants > 0) {
            self::error(__('Individual coverage cannot include dependants. Switch to family coverage to add dependants.', 'matrix-mlm'), 'coverage_type');
        }

        return [
            'plan_tier'          => $tier,
            'coverage_type'      => $coverage,
            'preferred_hospital' => $hospital,
            'dependants_count'   => $dependants,
        ];
    }

    private static function validate_medical($post, $gender) {
        $blood = self::txt($post, 'blood_group');
        if ($blood === '') {
            $blood = 'unknown';
        }
        if (!in_array($blood, self::BLOOD_GROUPS, true)) {
            self::error(__('Choose a valid blood group.', 'matrix-mlm'), 'blood_group');
        }

        $geno = self::txt($post, 'genotype');
        if ($geno === '') {
            $geno = 'unknown';
        }
        if (!in_array($geno, self::GENOTYPES, true)) {
            self::error(__('Choose a valid genotype.', 'matrix-mlm'), 'genotype');
        }

        $height_raw = self::raw($post, 'height_cm');
        $height = $height_raw !== '' ? (int) $height_raw : null;
        if ($height !== null && ($height < self::MIN_HEIGHT_CM || $height > self::MAX_HEIGHT_CM)) {
            self::error(sprintf(
                /* translators: 1: min cm, 2: max cm */
                __('Height must be between %1$d and %2$d cm.', 'matrix-mlm'),
                self::MIN_HEIGHT_CM, self::MAX_HEIGHT_CM
            ), 'height_cm');
        }

        $weight_raw = self::raw($post, 'weight_kg');
        $weight = $weight_raw !== '' ? (int) $weight_raw : null;
        if ($weight !== null && ($weight < self::MIN_WEIGHT_KG || $weight > self::MAX_WEIGHT_KG)) {
            self::error(sprintf(
                /* translators: 1: min kg, 2: max kg */
                __('Weight must be between %1$d and %2$d kg.', 'matrix-mlm'),
                self::MIN_WEIGHT_KG, self::MAX_WEIGHT_KG
            ), 'weight_kg');
        }

        $pre = self::longtxt($post, 'pre_existing_conditions');
        $allergies = self::longtxt($post, 'allergies');
        $meds = self::longtxt($post, 'current_medications');
        // 2000 chars per textarea covers "I have hypertension, diabetes,
        // a history of asthma, and the following allergies: …" with
        // headroom. Anything longer is operator-side detail that
        // belongs in the medical-history file upload, not the form.
        foreach ([
            'pre_existing_conditions' => $pre,
            'allergies'               => $allergies,
            'current_medications'     => $meds,
        ] as $field => $value) {
            if (mb_strlen($value) > 2000) {
                self::error(__('That field is too long. Use the medical history upload for detailed records.', 'matrix-mlm'), $field);
            }
        }

        $smoker = self::raw($post, 'is_smoker');
        if ($smoker !== '0' && $smoker !== '1') {
            self::error(__('Tell us whether you smoke.', 'matrix-mlm'), 'is_smoker');
        }

        $pregnant = self::raw($post, 'is_pregnant');
        // Accept a missing checkbox as "no" — pregnancy is only
        // meaningful for one gender, so we don't reject when
        // gender !== 'female' and the field is empty.
        if ($pregnant !== '0' && $pregnant !== '1') {
            $pregnant = '0';
        }
        // Defensive: reject pregnancy on non-female applications.
        // A male applicant who marks "is pregnant" is either
        // confused or malicious — either way the row shouldn't
        // persist that signal.
        if ($pregnant === '1' && $gender !== 'female') {
            $pregnant = '0';
        }

        return [
            'blood_group'             => $blood,
            'genotype'                => $geno,
            'height_cm'               => $height,
            'weight_kg'               => $weight,
            'pre_existing_conditions' => $pre !== '' ? $pre : null,
            'allergies'               => $allergies !== '' ? $allergies : null,
            'current_medications'     => $meds !== '' ? $meds : null,
            'is_smoker'               => $smoker === '1' ? 1 : 0,
            'is_pregnant'             => $pregnant === '1' ? 1 : 0,
        ];
    }

    private static function validate_nok($post) {
        $name = self::txt($post, 'nok_name');
        self::require_len($name, 2, 120, __("Next of kin name is required.", 'matrix-mlm'), 'nok_name');

        $rel = self::txt($post, 'nok_relationship');
        self::require_len($rel, 2, 50, __("Next of kin relationship is required (e.g. Spouse, Parent, Sibling).", 'matrix-mlm'), 'nok_relationship');

        $phone = self::digits(self::raw($post, 'nok_phone'));
        if (strlen($phone) < 10 || strlen($phone) > 14) {
            self::error(__("Enter a valid next of kin phone number.", 'matrix-mlm'), 'nok_phone');
        }

        return [
            'nok_name'         => $name,
            'nok_relationship' => $rel,
            'nok_phone'        => $phone,
        ];
    }

    /**
     * Process all four document uploads. Required uploads on a
     * fresh application are passport_photo, nin_slip, utility_bill;
     * medical_history is optional. On a re-submission ($existing !=
     * null) every file becomes optional — leaving an upload empty
     * keeps the previously stored URL untouched, so a member can
     * fix a typo in their NOK name without re-uploading three other
     * documents.
     *
     * MIME / size constraints are referenced from
     * Matrix_MLM_User_Loan so the rules stay in lockstep across
     * both forms; if loan tightens its allowed types, healthcare
     * follows automatically.
     */
    private static function handle_uploads($user_id, $existing = null) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        $is_resubmission = $existing !== null;

        $required = [
            'passport_photo' => ['col' => 'passport_photo_url', 'mimes' => self::photo_mimes(), 'label' => __('Passport Photo', 'matrix-mlm')],
            'nin_slip'       => ['col' => 'nin_slip_url',       'mimes' => self::doc_mimes(),   'label' => __('NIN Slip / Card', 'matrix-mlm')],
            'utility_bill'   => ['col' => 'utility_bill_url',   'mimes' => self::doc_mimes(),   'label' => __('Utility Bill (proof of address)', 'matrix-mlm')],
        ];
        $optional = [
            'medical_history' => ['col' => 'medical_history_url', 'mimes' => self::doc_mimes(), 'label' => __('Medical History (optional)', 'matrix-mlm')],
        ];

        $out = [];
        foreach ($required as $field => $spec) {
            $url = self::upload_one($field, $spec, $user_id);
            if ($url === null) {
                if ($is_resubmission && !empty($existing->{$spec['col']})) {
                    continue;
                }
                self::error(sprintf(
                    /* translators: %s: document label */
                    __('%s is required.', 'matrix-mlm'),
                    $spec['label']
                ), $field);
            }
            $out[$spec['col']] = $url;
        }
        foreach ($optional as $field => $spec) {
            $url = self::upload_one($field, $spec, $user_id);
            if ($url !== null) {
                $out[$spec['col']] = $url;
            }
        }
        return $out;
    }

    private static function upload_one($field, $spec, $user_id) {
        if (empty($_FILES[$field]) || !isset($_FILES[$field]['tmp_name'])) {
            return null;
        }
        $file = $_FILES[$field];
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || empty($file['tmp_name'])) {
            return null;
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            self::error(sprintf(
                /* translators: %s: document label */
                __('Could not upload %s. Please try again.', 'matrix-mlm'),
                $spec['label']
            ), $field);
        }

        $max = self::max_file_bytes();
        if ((int) $file['size'] > $max) {
            self::error(sprintf(
                /* translators: 1: document label, 2: max MB */
                __('%1$s is too large. Maximum size is %2$d MB.', 'matrix-mlm'),
                $spec['label'],
                (int) ($max / 1048576)
            ), $field);
        }
        if (!array_key_exists($file['type'], $spec['mimes'])) {
            self::error(sprintf(
                /* translators: %s: document label */
                __('%s must be a PDF, JPG, PNG, or WebP file.', 'matrix-mlm'),
                $spec['label']
            ), $field);
        }

        $upload = wp_handle_upload($file, [
            'test_form'                => false,
            'mimes'                    => $spec['mimes'],
            'unique_filename_callback' => function ($dir, $name, $ext) use ($field, $user_id) {
                // Namespace by user_id + field so two members can
                // never collide on a shared filename, and an admin
                // reviewing the uploads folder can tell which row
                // each file belongs to without joining the DB.
                $base = sanitize_file_name(pathinfo($name, PATHINFO_FILENAME));
                return sprintf('matrix-healthcare-%d-%s-%s%s', $user_id, $field, substr(md5($base . microtime(true)), 0, 8), $ext);
            },
        ]);
        if (isset($upload['error'])) {
            self::error($upload['error'], $field);
        }
        return $upload['url'];
    }

    /**
     * UPSERT into matrix_healthcare_applications. Approved
     * applications cannot be overwritten by a self-service
     * resubmission — at that point the policy has been issued and
     * any change is an admin/operator concern. Returns the
     * user-facing success message.
     */
    private static function save($user_id, $existing, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_healthcare_applications';

        if ($existing && $existing->status === 'approved') {
            self::error(__('Your healthcare enrolment is already approved. Contact support to make changes.', 'matrix-mlm'));
        }

        if ($existing) {
            $result = $wpdb->update($table, $data, ['id' => $existing->id]);
            if ($result === false) {
                error_log('Matrix Healthcare submit (update) failed: ' . $wpdb->last_error);
                self::error(__('Could not save your application. Please try again.', 'matrix-mlm'));
            }
            return __('Your healthcare application has been updated and is pending review.', 'matrix-mlm');
        }

        $data['created_at'] = current_time('mysql');
        $result = $wpdb->insert($table, $data);
        if ($result === false) {
            error_log('Matrix Healthcare submit (insert) failed: ' . $wpdb->last_error);
            self::error(__('Could not submit your application. Please try again.', 'matrix-mlm'));
        }
        return __('Your healthcare application has been submitted and is pending review.', 'matrix-mlm');
    }

    // ============================================================
    // Helpers — small validators kept local so the form's input
    // handling is self-contained and easy to audit.
    // ============================================================

    private static function raw($post, $key) {
        return isset($post[$key]) ? (string) wp_unslash($post[$key]) : '';
    }

    private static function txt($post, $key) {
        return trim(sanitize_text_field(self::raw($post, $key)));
    }

    /** Like txt() but allows multi-line content and preserves it. */
    private static function longtxt($post, $key) {
        return trim(sanitize_textarea_field(self::raw($post, $key)));
    }

    private static function digits($value) {
        return preg_replace('/\D+/', '', (string) $value);
    }

    private static function require_len($value, $min, $max, $message, $field) {
        $len = mb_strlen((string) $value);
        if ($len < $min || $len > $max) {
            self::error($message, $field);
        }
    }

    private static function error($message, $field = '') {
        $payload = ['message' => $message];
        if ($field !== '') {
            $payload['field'] = $field;
        }
        wp_send_json_error($payload);
    }

    /** Active-plan gate. Re-checked server-side; never trust a button. */
    public static function user_is_eligible($user_id) {
        $user_id = intval($user_id);
        if ($user_id <= 0 || !class_exists('Matrix_MLM_User')) {
            return false;
        }
        $plans = Matrix_MLM_User::get_active_plans($user_id);
        return !empty($plans);
    }

    public static function get_user_request($user_id) {
        $user_id = intval($user_id);
        if ($user_id <= 0 || !self::table_exists()) {
            return null;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_healthcare_applications';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d",
            $user_id
        ));
    }

    private static function table_exists() {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_healthcare_applications';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME, $table
        )) > 0;
    }

    /**
     * Country list — reuse the loan form's cached list directly so
     * the two forms stay in lockstep on one source of truth.
     * Falls back to a tiny stub if the loan class is somehow
     * unavailable (partial deploy / load order edge case).
     */
    public static function countries() {
        if (class_exists('Matrix_MLM_User_Loan')
            && method_exists('Matrix_MLM_User_Loan', 'countries')) {
            return Matrix_MLM_User_Loan::countries();
        }
        return ['Nigeria'];
    }

    /** Document upload MIME whitelist — references the loan class
     *  constant so any tightening there flows through here. */
    private static function doc_mimes() {
        if (defined('Matrix_MLM_User_Loan::DOC_MIMES')) {
            return Matrix_MLM_User_Loan::DOC_MIMES;
        }
        return [
            'application/pdf' => 'pdf',
            'image/jpeg'      => 'jpg|jpeg',
            'image/png'       => 'png',
            'image/webp'      => 'webp',
        ];
    }

    /** Photo-only upload MIME whitelist (passport photo). */
    private static function photo_mimes() {
        if (defined('Matrix_MLM_User_Loan::PHOTO_MIMES')) {
            return Matrix_MLM_User_Loan::PHOTO_MIMES;
        }
        return [
            'image/jpeg' => 'jpg|jpeg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];
    }

    private static function max_file_bytes() {
        if (defined('Matrix_MLM_User_Loan::MAX_FILE_BYTES')) {
            return (int) Matrix_MLM_User_Loan::MAX_FILE_BYTES;
        }
        return 5242880;
    }

    // ============================================================
    // Modal rendering — emitted once per page after the benefits
    // grid by Matrix_MLM_User_Benefits when a healthcare card is
    // present. The trigger button on the card carries
    // data-healthcare-trigger="1" and the JS below binds to it.
    // ============================================================

    public static function render_form_modal($user_id, $card_title = '') {
        $user_id = intval($user_id);
        if ($user_id <= 0) {
            return;
        }
        $existing = self::get_user_request($user_id);
        $user     = get_userdata($user_id);
        $title    = $card_title !== '' ? $card_title : __('Healthcare Enrolment', 'matrix-mlm');

        // Prefill order: existing application row first, then WP
        // profile, then empty.
        $f = function ($col, $fallback = '') use ($existing) {
            return $existing && isset($existing->{$col}) && $existing->{$col} !== null ? (string) $existing->{$col} : $fallback;
        };
        $first = $f('first_name', $user ? (string) $user->first_name : '');
        $last  = $f('last_name', $user ? (string) $user->last_name : '');
        $email = $f('email', $user ? (string) $user->user_email : '');

        self::render_modal_open($title, $existing);
        self::render_section_personal($f, $first, $last, $email);
        self::render_section_identification($f);
        self::render_section_plan($f, $existing);
        self::render_section_medical($f, $existing);
        self::render_section_nok($f);
        self::render_section_documents($f, $existing);
        self::render_modal_close($existing);
        self::render_inline_js();
    }

    private static function render_modal_open($title, $existing) {
        $status = $existing ? (string) $existing->status : '';
        $status_labels = [
            'pending'      => __('Your previous application is pending review. Resubmitting will replace it.', 'matrix-mlm'),
            'under_review' => __('Your application is under review. Resubmitting will replace it.', 'matrix-mlm'),
            'approved'     => __('Your enrolment is approved. Contact support to make changes.', 'matrix-mlm'),
            'rejected'     => __('Your previous application was rejected. You can update and resubmit it below.', 'matrix-mlm'),
            'cancelled'    => __('Your previous application was cancelled. You can resubmit it below.', 'matrix-mlm'),
        ];
        ?>
        <div class="matrix-loan-modal matrix-healthcare-modal" id="matrix-healthcare-modal"
             hidden style="display:none;"
             aria-hidden="true" role="dialog" aria-modal="true">
            <div class="matrix-loan-modal-backdrop" data-healthcare-modal-close></div>
            <div class="matrix-loan-modal-dialog" role="document">
                <button type="button" class="matrix-loan-modal-close" data-healthcare-modal-close
                        aria-label="<?php esc_attr_e('Close', 'matrix-mlm'); ?>">&times;</button>
                <h3 class="matrix-loan-modal-title"><?php echo esc_html($title); ?></h3>
                <p class="matrix-loan-modal-intro"><?php
                    _e('Complete every section to enrol in the healthcare benefit. Required documents must be PDF, JPG, PNG, or WebP files (5 MB max).', 'matrix-mlm');
                ?></p>
                <?php if ($existing): ?>
                <div class="matrix-loan-status matrix-loan-status-<?php echo esc_attr($status); ?>">
                    <?php echo esc_html($status_labels[$status] ?? ''); ?>
                </div>
                <?php if ($existing->status === 'approved' && !empty($existing->policy_number)): ?>
                <p style="margin-top:8px;font-size:13px;color:#065f46;">
                    <?php
                    /* translators: %s: HMO policy number */
                    printf(esc_html__('Your policy number: %s', 'matrix-mlm'), '<code>' . esc_html($existing->policy_number) . '</code>');
                    ?>
                </p>
                <?php endif; ?>
                <?php endif; ?>
                <form class="matrix-loan-form matrix-healthcare-form" id="matrix-healthcare-form" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::AJAX_ACTION); ?>">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('matrix_mlm_nonce')); ?>">
        <?php
    }

    private static function render_section_personal($f, $first, $last, $email) {
        ?>
        <fieldset class="matrix-loan-section">
            <legend><?php _e('Personal Information', 'matrix-mlm'); ?></legend>
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label><?php _e('First Name', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="text" name="first_name" value="<?php echo esc_attr($first); ?>" minlength="2" maxlength="60" required>
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Last Name', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="text" name="last_name" value="<?php echo esc_attr($last); ?>" minlength="2" maxlength="60" required>
                </div>
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Middle Name', 'matrix-mlm'); ?></label>
                <input type="text" name="middle_name" value="<?php echo esc_attr($f('middle_name')); ?>" maxlength="60">
            </div>
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label><?php _e('Email', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="email" name="email" value="<?php echo esc_attr($email); ?>" maxlength="120" required>
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Phone / Mobile No', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="tel" name="phone" value="<?php echo esc_attr($f('phone')); ?>" inputmode="tel" maxlength="20" required>
                </div>
            </div>
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label><?php _e('Date of Birth', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="date" name="date_of_birth" value="<?php echo esc_attr($f('date_of_birth')); ?>" required>
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Gender', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <select name="gender" required>
                        <option value=""><?php esc_html_e('- Select -', 'matrix-mlm'); ?></option>
                        <?php
                        $gender = $f('gender');
                        foreach ([
                            'male'   => __('Male', 'matrix-mlm'),
                            'female' => __('Female', 'matrix-mlm'),
                            'other'  => __('Other', 'matrix-mlm'),
                        ] as $val => $label) {
                            printf('<option value="%s" %s>%s</option>', esc_attr($val), selected($gender, $val, false), esc_html($label));
                        }
                        ?>
                    </select>
                </div>
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Marital Status', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                <select name="marital_status" required>
                    <option value=""><?php esc_html_e('- Select -', 'matrix-mlm'); ?></option>
                    <?php
                    $marital = $f('marital_status');
                    foreach ([
                        'single'   => __('Single', 'matrix-mlm'),
                        'married'  => __('Married', 'matrix-mlm'),
                        'divorced' => __('Divorced', 'matrix-mlm'),
                        'widowed'  => __('Widowed', 'matrix-mlm'),
                    ] as $val => $label) {
                        printf('<option value="%s" %s>%s</option>', esc_attr($val), selected($marital, $val, false), esc_html($label));
                    }
                    ?>
                </select>
            </div>
            <h4 class="matrix-loan-subhead"><?php _e('Address', 'matrix-mlm'); ?></h4>
            <div class="matrix-form-group">
                <label><?php _e('Address Line 1', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                <input type="text" name="address_line1" value="<?php echo esc_attr($f('address_line1')); ?>" maxlength="200" required>
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Address Line 2', 'matrix-mlm'); ?></label>
                <input type="text" name="address_line2" value="<?php echo esc_attr($f('address_line2')); ?>" maxlength="200">
            </div>
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label><?php _e('City', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="text" name="city" value="<?php echo esc_attr($f('city')); ?>" maxlength="100" required>
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('State', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="text" name="state" value="<?php echo esc_attr($f('state')); ?>" maxlength="100" required>
                </div>
            </div>
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label><?php _e('Zip Code', 'matrix-mlm'); ?></label>
                    <input type="text" name="zip_code" value="<?php echo esc_attr($f('zip_code')); ?>" maxlength="20">
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Country', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <select name="country" required>
                        <?php
                        $current = $f('country', 'Nigeria');
                        foreach (self::countries() as $country) {
                            printf('<option value="%s" %s>%s</option>',
                                esc_attr($country),
                                selected($current, $country, false),
                                esc_html($country));
                        }
                        ?>
                    </select>
                </div>
            </div>
        </fieldset>
        <?php
    }

    private static function render_section_identification($f) {
        ?>
        <fieldset class="matrix-loan-section">
            <legend><?php _e('Identification', 'matrix-mlm'); ?></legend>
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label><?php _e('NIN', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="text" name="nin" value="<?php echo esc_attr($f('nin')); ?>" inputmode="numeric" pattern="\d{11}" maxlength="11" required>
                    <p class="matrix-form-hint"><?php _e('Your 11-digit National Identification Number.', 'matrix-mlm'); ?></p>
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Occupation', 'matrix-mlm'); ?></label>
                    <input type="text" name="occupation" value="<?php echo esc_attr($f('occupation')); ?>" maxlength="120" placeholder="<?php esc_attr_e('e.g. Trader, Teacher, Engineer', 'matrix-mlm'); ?>">
                </div>
            </div>
        </fieldset>
        <?php
    }

    private static function render_section_plan($f, $existing) {
        $tier     = $f('plan_tier');
        $coverage = $f('coverage_type');
        ?>
        <fieldset class="matrix-loan-section">
            <legend><?php _e('Plan &amp; Coverage', 'matrix-mlm'); ?></legend>
            <div class="matrix-form-group">
                <label><?php _e('Plan Tier', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                <div class="matrix-loan-radios">
                    <?php foreach ([
                        'basic'    => __('Basic', 'matrix-mlm'),
                        'standard' => __('Standard', 'matrix-mlm'),
                        'premium'  => __('Premium', 'matrix-mlm'),
                        'family'   => __('Family', 'matrix-mlm'),
                    ] as $val => $label): ?>
                    <label class="matrix-loan-radio">
                        <input type="radio" name="plan_tier" value="<?php echo esc_attr($val); ?>" <?php checked($tier, $val); ?> required>
                        <span><?php echo esc_html($label); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Coverage Type', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                <div class="matrix-loan-radios">
                    <?php foreach ([
                        'individual' => __('Individual', 'matrix-mlm'),
                        'family'     => __('Family', 'matrix-mlm'),
                    ] as $val => $label): ?>
                    <label class="matrix-loan-radio">
                        <input type="radio" name="coverage_type" value="<?php echo esc_attr($val); ?>" <?php checked($coverage, $val); ?> required>
                        <span><?php echo esc_html($label); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label><?php _e('Preferred Hospital / Clinic', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="text" name="preferred_hospital" value="<?php echo esc_attr($f('preferred_hospital')); ?>" maxlength="200" required>
                    <p class="matrix-form-hint"><?php _e('Your primary care provider for routine visits.', 'matrix-mlm'); ?></p>
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Number of Dependants', 'matrix-mlm'); ?></label>
                    <input type="number" name="dependants_count" value="<?php echo esc_attr($f('dependants_count', '0')); ?>" min="0" max="<?php echo esc_attr(self::MAX_DEPENDANTS); ?>">
                    <p class="matrix-form-hint"><?php _e('Set to 0 for individual coverage. Family coverage may add dependants later.', 'matrix-mlm'); ?></p>
                </div>
            </div>
        </fieldset>
        <?php
    }

    private static function render_section_medical($f, $existing) {
        $smoker   = $existing ? (string) (int) $existing->is_smoker : '';
        $pregnant = $existing ? (string) (int) $existing->is_pregnant : '';
        ?>
        <fieldset class="matrix-loan-section">
            <legend><?php _e('Medical Profile', 'matrix-mlm'); ?></legend>
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label><?php _e('Blood Group', 'matrix-mlm'); ?></label>
                    <select name="blood_group">
                        <?php
                        $bg = $f('blood_group', 'unknown');
                        foreach (self::BLOOD_GROUPS as $val) {
                            $label = $val === 'unknown' ? __('Unknown / Prefer not to say', 'matrix-mlm') : $val;
                            printf('<option value="%s" %s>%s</option>',
                                esc_attr($val), selected($bg, $val, false), esc_html($label));
                        }
                        ?>
                    </select>
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Genotype', 'matrix-mlm'); ?></label>
                    <select name="genotype">
                        <?php
                        $gt = $f('genotype', 'unknown');
                        foreach (self::GENOTYPES as $val) {
                            $label = $val === 'unknown' ? __('Unknown / Prefer not to say', 'matrix-mlm') : $val;
                            printf('<option value="%s" %s>%s</option>',
                                esc_attr($val), selected($gt, $val, false), esc_html($label));
                        }
                        ?>
                    </select>
                </div>
            </div>
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label><?php _e('Height (cm)', 'matrix-mlm'); ?></label>
                    <input type="number" name="height_cm" value="<?php echo esc_attr($f('height_cm')); ?>" min="<?php echo esc_attr(self::MIN_HEIGHT_CM); ?>" max="<?php echo esc_attr(self::MAX_HEIGHT_CM); ?>">
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Weight (kg)', 'matrix-mlm'); ?></label>
                    <input type="number" name="weight_kg" value="<?php echo esc_attr($f('weight_kg')); ?>" min="<?php echo esc_attr(self::MIN_WEIGHT_KG); ?>" max="<?php echo esc_attr(self::MAX_WEIGHT_KG); ?>">
                </div>
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Pre-existing Conditions', 'matrix-mlm'); ?></label>
                <textarea name="pre_existing_conditions" rows="3" maxlength="2000" placeholder="<?php esc_attr_e('e.g. Hypertension, Diabetes, Asthma. Leave blank if none.', 'matrix-mlm'); ?>"><?php echo esc_textarea($f('pre_existing_conditions')); ?></textarea>
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Allergies', 'matrix-mlm'); ?></label>
                <textarea name="allergies" rows="2" maxlength="2000" placeholder="<?php esc_attr_e('e.g. Penicillin, Peanuts, Shellfish. Leave blank if none.', 'matrix-mlm'); ?>"><?php echo esc_textarea($f('allergies')); ?></textarea>
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Current Medications', 'matrix-mlm'); ?></label>
                <textarea name="current_medications" rows="2" maxlength="2000" placeholder="<?php esc_attr_e('Medications you take regularly. Leave blank if none.', 'matrix-mlm'); ?>"><?php echo esc_textarea($f('current_medications')); ?></textarea>
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Do you smoke?', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                <?php self::render_yes_no('is_smoker', $smoker); ?>
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Are you currently pregnant?', 'matrix-mlm'); ?></label>
                <p class="matrix-form-hint"><?php _e('Only meaningful for female applicants. Leave as No otherwise.', 'matrix-mlm'); ?></p>
                <?php self::render_yes_no('is_pregnant', $pregnant === '' ? '0' : $pregnant); ?>
            </div>
        </fieldset>
        <?php
    }

    private static function render_section_nok($f) {
        ?>
        <fieldset class="matrix-loan-section">
            <legend><?php _e('Next of Kin', 'matrix-mlm'); ?></legend>
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label><?php _e('Full Name', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="text" name="nok_name" value="<?php echo esc_attr($f('nok_name')); ?>" minlength="2" maxlength="120" required>
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Relationship', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="text" name="nok_relationship" value="<?php echo esc_attr($f('nok_relationship')); ?>" minlength="2" maxlength="50" placeholder="<?php esc_attr_e('e.g. Spouse, Parent, Sibling', 'matrix-mlm'); ?>" required>
                </div>
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Phone / Mobile No', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                <input type="tel" name="nok_phone" value="<?php echo esc_attr($f('nok_phone')); ?>" inputmode="tel" maxlength="20" required>
            </div>
        </fieldset>
        <?php
    }

    private static function render_section_documents($f, $existing) {
        ?>
        <fieldset class="matrix-loan-section">
            <legend><?php _e('Documents', 'matrix-mlm'); ?></legend>
            <p class="matrix-loan-hint"><?php _e('PDF, JPG, PNG, or WebP. Max 5 MB each.', 'matrix-mlm'); ?></p>
            <?php
            $required_files = [
                'passport_photo' => ['label' => __('Passport Photo', 'matrix-mlm'),                'col' => 'passport_photo_url', 'photo' => true],
                'nin_slip'       => ['label' => __('NIN Slip / Card', 'matrix-mlm'),               'col' => 'nin_slip_url',       'photo' => false],
                'utility_bill'   => ['label' => __('Utility Bill (proof of address)', 'matrix-mlm'), 'col' => 'utility_bill_url',   'photo' => false],
            ];
            foreach ($required_files as $name => $spec) self::render_file_input($name, $spec, $existing, true);
            self::render_file_input('medical_history', [
                'label' => __('Medical History', 'matrix-mlm'),
                'col'   => 'medical_history_url',
                'photo' => false,
            ], $existing, false);
            ?>
        </fieldset>
        <?php
    }

    private static function render_modal_close($existing) {
        $label = $existing
            ? __('Update Application', 'matrix-mlm')
            : __('Submit Application', 'matrix-mlm');
        ?>
                    <div class="matrix-loan-feedback" role="status" aria-live="polite"></div>
                    <div class="matrix-loan-actions">
                        <button type="button" class="matrix-btn" data-healthcare-modal-close>
                            <?php _e('Cancel', 'matrix-mlm'); ?>
                        </button>
                        <button type="submit" class="matrix-btn matrix-btn-primary matrix-healthcare-submit">
                            <?php echo esc_html($label); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    private static function render_yes_no($name, $current) {
        ?>
        <div class="matrix-loan-radios">
            <label class="matrix-loan-radio">
                <input type="radio" name="<?php echo esc_attr($name); ?>" value="1" <?php checked($current, '1'); ?>>
                <span><?php _e('Yes', 'matrix-mlm'); ?></span>
            </label>
            <label class="matrix-loan-radio">
                <input type="radio" name="<?php echo esc_attr($name); ?>" value="0" <?php checked($current, '0'); ?>>
                <span><?php _e('No', 'matrix-mlm'); ?></span>
            </label>
        </div>
        <?php
    }

    private static function render_file_input($name, $spec, $existing, $required) {
        $col = $spec['col'];
        $current_url = $existing && !empty($existing->{$col}) ? (string) $existing->{$col} : '';
        $accept = $spec['photo']
            ? 'image/jpeg,image/png,image/webp'
            : 'application/pdf,image/jpeg,image/png,image/webp';
        ?>
        <div class="matrix-form-group matrix-loan-file">
            <label>
                <?php echo esc_html($spec['label']); ?>
                <?php if ($required && !$current_url): ?>
                    <span aria-hidden="true">*</span>
                <?php endif; ?>
                <?php if ($current_url): ?>
                    <span class="matrix-loan-file-current">
                        — <a href="<?php echo esc_url($current_url); ?>" target="_blank" rel="noopener noreferrer"><?php _e('current file', 'matrix-mlm'); ?></a>
                    </span>
                <?php endif; ?>
            </label>
            <input type="file" name="<?php echo esc_attr($name); ?>" accept="<?php echo esc_attr($accept); ?>" <?php echo ($required && !$current_url) ? 'required' : ''; ?>>
            <?php if ($current_url): ?>
                <p class="matrix-form-hint"><?php _e('Leave blank to keep your existing upload.', 'matrix-mlm'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_inline_js() {
        $ajax_url        = wp_json_encode(admin_url('admin-ajax.php'));
        $err_unreachable = esc_js(__('Cannot reach the server. Please refresh the page and try again.', 'matrix-mlm'));
        $err_default     = esc_js(__('Submission failed. Please check the form and try again.', 'matrix-mlm'));
        $err_network     = esc_js(__('Network error. Please try again.', 'matrix-mlm'));
        $msg_submitting  = esc_js(__('Submitting…', 'matrix-mlm'));
        $msg_default_ok  = esc_js(__('Application submitted.', 'matrix-mlm'));
        $msg_update_btn  = esc_js(__('Update Application', 'matrix-mlm'));
        ?>
        <script>
        (function() {
            var modal = document.getElementById('matrix-healthcare-modal');
            var form  = document.getElementById('matrix-healthcare-form');
            if (!modal || !form) return;

            // PHP-injected admin-ajax URL — same defensive pattern as
            // the loan/CUG modals. The IIFE runs inline before footer
            // scripts so window.matrixMLM is unreliable here; the
            // hardcoded URL guarantees the submit handler always knows
            // where to POST.
            var DEFAULT_AJAX_URL = <?php echo $ajax_url; ?>;
            function getAjaxUrl() {
                if (window.matrixMLM && window.matrixMLM.ajaxUrl) return window.matrixMLM.ajaxUrl;
                if (window.ajaxurl) return window.ajaxurl;
                return DEFAULT_AJAX_URL;
            }

            var triggers = document.querySelectorAll('[data-healthcare-trigger]');
            var submit   = form.querySelector('.matrix-healthcare-submit');
            var feedback = form.querySelector('.matrix-loan-feedback');

            function openModal() {
                clearFeedback();
                clearFieldErrors();
                modal.hidden = false;
                modal.style.display = '';
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('matrix-loan-modal-lock');
            }
            function closeModal() {
                modal.classList.remove('is-open');
                modal.hidden = true;
                modal.style.display = 'none';
                modal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('matrix-loan-modal-lock');
            }
            function clearFeedback() {
                if (!feedback) return;
                feedback.textContent = '';
                feedback.className = 'matrix-loan-feedback';
            }
            function setFeedback(type, msg) {
                if (!feedback) return;
                feedback.textContent = msg;
                feedback.className = 'matrix-loan-feedback matrix-loan-feedback-' + type;
            }
            function clearFieldErrors() {
                form.querySelectorAll('.has-error').forEach(function(el) {
                    el.classList.remove('has-error');
                });
            }
            function flagField(name) {
                if (!name) return;
                var input = form.querySelector('[name="' + name + '"]');
                if (input) {
                    var group = input.closest('.matrix-form-group') || input.parentNode;
                    if (group) group.classList.add('has-error');
                    if (input.scrollIntoView) input.scrollIntoView({block: 'center', behavior: 'smooth'});
                    if (input.focus) input.focus();
                }
            }

            triggers.forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    openModal();
                });
            });
            modal.querySelectorAll('[data-healthcare-modal-close]').forEach(function(el) {
                el.addEventListener('click', closeModal);
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
            });

            form.addEventListener('submit', function(e) {
                e.preventDefault();
                var ajaxUrl = getAjaxUrl();
                if (!ajaxUrl) {
                    setFeedback('error', '<?php echo $err_unreachable; ?>');
                    return;
                }
                clearFeedback();
                clearFieldErrors();
                submit.disabled = true;
                var originalLabel = submit.textContent;
                submit.textContent = '<?php echo $msg_submitting; ?>';

                var fd = new FormData(form);
                fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: fd
                }).then(function(res) {
                    return res.json().catch(function() {
                        return { success: false, data: { message: 'Unexpected server response.' } };
                    });
                }).then(function(payload) {
                    submit.disabled = false;
                    submit.textContent = originalLabel;
                    if (payload && payload.success) {
                        setFeedback('success', (payload.data && payload.data.message) || '<?php echo $msg_default_ok; ?>');
                        submit.textContent = '<?php echo $msg_update_btn; ?>';
                        submit.disabled = true;
                        setTimeout(function() {
                            closeModal();
                            submit.disabled = false;
                            clearFeedback();
                        }, 1800);
                    } else {
                        var msg = (payload && payload.data && payload.data.message) || '<?php echo $err_default; ?>';
                        setFeedback('error', msg);
                        if (payload && payload.data && payload.data.field) {
                            flagField(payload.data.field);
                        }
                    }
                }).catch(function() {
                    submit.disabled = false;
                    submit.textContent = originalLabel;
                    setFeedback('error', '<?php echo $err_network; ?>');
                });
            });
        })();
        </script>
        <?php
    }
}
