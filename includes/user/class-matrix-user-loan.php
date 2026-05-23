<?php
/**
 * Business Loan application form (Benefits tab)
 *
 * Renders the multi-section Apply-for-Loan modal triggered from the
 * loans card on the Benefits tab and processes submissions into
 * wp_matrix_loan_applications. Mirrors Matrix_MLM_User_CUG's shape:
 * authenticated AJAX endpoint, server-side validation per field,
 * UPSERT semantics on user_id, lazy-resolved admin-ajax URL, and
 * an auto-closing modal on success.
 *
 * The form has four sections (Personal Information / Customer
 * Account Details / Project Details / Guarantor's Details) plus a
 * Terms & Conditions agreement. Eight file uploads are persisted
 * via wp_handle_upload to the WordPress uploads directory; the
 * resulting URLs are stored on the row.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_User_Loan {

    /** AJAX action name — referenced from the inline JS submit
     *  handler. Kept as a constant so the two ends never drift. */
    const AJAX_ACTION = 'matrix_submit_loan';

    const APPLYING_AS    = ['sole_proprietor', 'partnership', 'corporation'];
    const LOAN_REASONS   = ['sme', 'farming', 'refinancing', 'other'];
    const REPAYMENT_PLANS = ['daily', 'weekly', 'monthly'];

    /** Loan amount bounds from the operator's published T&Cs.
     *  Server-side enforcement protects the upper bound even if a
     *  user hand-edits the input's max attribute. */
    const MIN_LOAN_AMOUNT = 50000;
    const MAX_LOAN_AMOUNT = 5000000;

    /** Age window. 18 is the legal minimum for a contract; 65 is
     *  the operator's documented eligibility ceiling. Computed in
     *  full years from date_of_birth, not partial years, so a
     *  member who turns 18 today passes. */
    const MIN_AGE = 18;
    const MAX_AGE = 65;

    /** 5 MB cap per upload. Matches the avatar uploader elsewhere
     *  in this plugin and stays well under typical PHP
     *  upload_max_filesize defaults so we don't trip silent
     *  truncation in shared hosting. */
    const MAX_FILE_BYTES = 5242880;


    /** Document uploads (NIN, utility bill, ID cards, marketing,
     *  project info, guarantor ID) accept PDF + common image
     *  formats. Photo-only uploads (passport photos) are stricter. */
    const DOC_MIMES = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg|jpeg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
    ];
    const PHOTO_MIMES = [
        'image/jpeg' => 'jpg|jpeg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct() {
        // Authenticated-only — anonymous loan submissions are nonsense
        // and we want the upload pipeline gated on a real user_id.
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajax_submit']);
    }

    /**
     * Handle the submission. Mirrors CUG's flow but the field set is
     * 10x larger so validation is split into per-section helpers.
     *
     * Response shape:
     *   success: { message: string, status: 'pending' }
     *   error:   { message: string, field?: string }
     *
     * Validation order is deliberately section-by-section so a user
     * with a single bad field doesn't have to scroll past unrelated
     * sections to find what's wrong — the field key in the error
     * payload tells the JS which input to scroll to and outline.
     */
    public function ajax_submit() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            self::error(__('You must be logged in to apply.', 'matrix-mlm'));
        }
        if (!self::user_is_eligible($user_id)) {
            self::error(__('Subscribe to a plan to apply for a loan.', 'matrix-mlm'));
        }
        if (!self::table_exists()) {
            if (class_exists('Matrix_MLM_Database')) {
                Matrix_MLM_Database::maybe_upgrade();
            }
            if (!self::table_exists()) {
                self::error(__('Loan applications are not available right now. Please try again in a moment.', 'matrix-mlm'));
            }
        }


        $data = [];
        $data += self::validate_personal($_POST);
        $data += self::validate_account($_POST);
        $data += self::validate_project($_POST);
        $data += self::validate_guarantor($_POST);
        self::validate_terms($_POST);
        $data['agreed_terms'] = 1;

        // File uploads run last so we don't write any documents to
        // disk for a submission that would have been rejected by a
        // text-field validator anyway.
        $existing = self::get_user_request($user_id);
        $data += self::handle_uploads($user_id, $existing);

        // Persist with UPSERT semantics on user_id (UNIQUE KEY).
        $data['user_id']    = $user_id;
        $data['status']     = 'pending';
        $data['updated_at'] = current_time('mysql');
        $message = self::save($user_id, $existing, $data);

        // Notify the configured review team. Fetched fresh via
        // get_user_request() (UNIQUE on user_id) rather than reusing
        // $data, so the email reflects exactly what was persisted
        // — including DB-default columns, the canonical timestamps,
        // and the resolved upload URLs from wp_handle_upload.
        // $existing is the row state BEFORE this save, so its
        // truthiness is the correct signal for the resubmission flag.
        // Failures inside the notifier are swallowed so a
        // misconfigured SMTP can't break the submission flow.
        if (class_exists('Matrix_MLM_Notifications')) {
            $persisted = self::get_user_request($user_id);
            if ($persisted) {
                Matrix_MLM_Notifications::send_admin_loan_application_notification(
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

    /**
     * Personal-information section validation. Returns the
     * validated columns ready for $wpdb->insert/update.
     */
    private static function validate_personal($post) {
        $first = self::txt($post, 'first_name');
        self::require_len($first, 2, 60, __('First name must be between 2 and 60 characters.', 'matrix-mlm'), 'first_name');

        $last = self::txt($post, 'last_name');
        self::require_len($last, 2, 60, __('Last name must be between 2 and 60 characters.', 'matrix-mlm'), 'last_name');

        $email = sanitize_email(self::raw($post, 'email'));
        if ($email === '' || !is_email($email) || mb_strlen($email) > 120) {
            self::error(__('Enter a valid email address.', 'matrix-mlm'), 'email');
        }

        $phone = self::digits(self::raw($post, 'phone'));
        if (strlen($phone) < 10 || strlen($phone) > 14) {
            self::error(__('Enter a valid phone number.', 'matrix-mlm'), 'phone');
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

        $dob_raw = self::raw($post, 'date_of_birth');
        $dob_ts  = strtotime($dob_raw);
        if (!$dob_raw || $dob_ts === false) {
            self::error(__('Enter a valid date of birth.', 'matrix-mlm'), 'date_of_birth');
        }
        $age = (int) date('Y') - (int) date('Y', $dob_ts) - (date('md') < date('md', $dob_ts) ? 1 : 0);
        if ($age < self::MIN_AGE || $age > self::MAX_AGE) {
            self::error(sprintf(
                /* translators: 1: min age, 2: max age */
                __('Applicant age must be between %1$d and %2$d years.', 'matrix-mlm'),
                self::MIN_AGE, self::MAX_AGE
            ), 'date_of_birth');
        }

        $trade = self::txt($post, 'trade_name');
        if (mb_strlen($trade) > 120) {
            self::error(__('Known-as name is too long.', 'matrix-mlm'), 'trade_name');
        }


        return [
            'first_name'    => $first,
            'last_name'     => $last,
            'email'         => $email,
            'phone'         => $phone,
            'address_line1' => $address1,
            'address_line2' => $address2 !== '' ? $address2 : null,
            'city'          => $city,
            'state'         => $state,
            'zip_code'      => $zip !== '' ? $zip : null,
            'country'       => $country,
            'date_of_birth' => date('Y-m-d', $dob_ts),
            'trade_name'    => $trade !== '' ? $trade : null,
        ];
    }

    /**
     * Customer Account Details section. Bank name is whitelisted
     * against the Fintava-aware bank list so a tampered POST can't
     * sneak in an arbitrary string. NUBAN is exactly 10 digits.
     */
    private static function validate_account($post) {
        $bank = self::txt($post, 'bank_name');
        $allowed_banks = self::banks();
        if (!in_array($bank, $allowed_banks, true)) {
            self::error(__('Choose a bank from the list.', 'matrix-mlm'), 'bank_name');
        }

        $account_no = self::digits(self::raw($post, 'account_number'));
        if (!preg_match('/^\d{10}$/', $account_no)) {
            self::error(__('Account number must be 10 digits.', 'matrix-mlm'), 'account_number');
        }

        $account_name = self::txt($post, 'account_name');
        self::require_len($account_name, 2, 200, __('Account name is required.', 'matrix-mlm'), 'account_name');

        return [
            'bank_name'      => $bank,
            'account_number' => $account_no,
            'account_name'   => $account_name,
        ];
    }


    /**
     * Project Details — applying-as, business address, BVN, and the
     * various yes/no + enum + amount fields. File uploads happen
     * separately in handle_uploads(). Loan amount is hard-bounded
     * server-side per the operator's T&C ranges.
     */
    private static function validate_project($post) {
        $applying_as = self::txt($post, 'applying_as');
        if (!in_array($applying_as, self::APPLYING_AS, true)) {
            self::error(__('Choose how you are applying.', 'matrix-mlm'), 'applying_as');
        }

        $b_addr1 = self::txt($post, 'business_address_line1');
        self::require_len($b_addr1, 5, 200, __('Business address line 1 is required.', 'matrix-mlm'), 'business_address_line1');

        $b_addr2 = self::txt($post, 'business_address_line2');
        if (mb_strlen($b_addr2) > 200) {
            self::error(__('Business address line 2 is too long.', 'matrix-mlm'), 'business_address_line2');
        }

        $b_city = self::txt($post, 'business_city');
        self::require_len($b_city, 2, 100, __('Business city is required.', 'matrix-mlm'), 'business_city');

        $b_state = self::txt($post, 'business_state');
        self::require_len($b_state, 2, 100, __('Business state is required.', 'matrix-mlm'), 'business_state');

        $b_zip = self::txt($post, 'business_zip');
        if (mb_strlen($b_zip) > 20) {
            self::error(__('Business zip code is too long.', 'matrix-mlm'), 'business_zip');
        }

        $b_country = self::txt($post, 'business_country');
        if (!in_array($b_country, self::countries(), true)) {
            self::error(__('Choose the business country from the list.', 'matrix-mlm'), 'business_country');
        }

        $bvn = self::digits(self::raw($post, 'bvn'));
        if (!preg_match('/^\d{11}$/', $bvn)) {
            self::error(__('BVN must be 11 digits.', 'matrix-mlm'), 'bvn');
        }


        $has_assets = self::raw($post, 'has_assets_statement');
        if ($has_assets !== '0' && $has_assets !== '1') {
            self::error(__('Tell us whether you have an up-to-date assets and liabilities statement.', 'matrix-mlm'), 'has_assets_statement');
        }

        $prev_financed = self::raw($post, 'previously_financed');
        if ($prev_financed !== '0' && $prev_financed !== '1') {
            self::error(__('Tell us whether you have been previously financed.', 'matrix-mlm'), 'previously_financed');
        }

        $reason = self::txt($post, 'loan_reason');
        if (!in_array($reason, self::LOAN_REASONS, true)) {
            self::error(__('Choose a loan reason.', 'matrix-mlm'), 'loan_reason');
        }

        $gross = (float) self::raw($post, 'project_gross_value');
        if ($gross <= 0 || $gross > 999999999999.99) {
            self::error(__('Enter a valid project gross value.', 'matrix-mlm'), 'project_gross_value');
        }

        $loan_amt = (float) self::raw($post, 'loan_amount');
        if ($loan_amt < self::MIN_LOAN_AMOUNT || $loan_amt > self::MAX_LOAN_AMOUNT) {
            self::error(sprintf(
                /* translators: 1: currency-formatted min, 2: currency-formatted max */
                __('Loan amount must be between %1$s and %2$s.', 'matrix-mlm'),
                number_format(self::MIN_LOAN_AMOUNT),
                number_format(self::MAX_LOAN_AMOUNT)
            ), 'loan_amount');
        }

        $repay = self::txt($post, 'repayment_plan');
        if (!in_array($repay, self::REPAYMENT_PLANS, true)) {
            self::error(__('Choose a repayment plan.', 'matrix-mlm'), 'repayment_plan');
        }


        return [
            'applying_as'            => $applying_as,
            'business_address_line1' => $b_addr1,
            'business_address_line2' => $b_addr2 !== '' ? $b_addr2 : null,
            'business_city'          => $b_city,
            'business_state'         => $b_state,
            'business_zip'           => $b_zip !== '' ? $b_zip : null,
            'business_country'       => $b_country,
            'bvn'                    => $bvn,
            'has_assets_statement'   => $has_assets === '1' ? 1 : 0,
            'previously_financed'    => $prev_financed === '1' ? 1 : 0,
            'loan_reason'            => $reason,
            'project_gross_value'    => round($gross, 2),
            'loan_amount'            => round($loan_amt, 2),
            'repayment_plan'         => $repay,
        ];
    }

    /**
     * Guarantor section. The guarantor's documents are file uploads
     * handled in handle_uploads(); this method only validates the
     * text fields.
     */
    private static function validate_guarantor($post) {
        $first = self::txt($post, 'guarantor_first_name');
        self::require_len($first, 2, 60, __('Guarantor first name is required.', 'matrix-mlm'), 'guarantor_first_name');

        $last = self::txt($post, 'guarantor_last_name');
        self::require_len($last, 2, 60, __('Guarantor last name is required.', 'matrix-mlm'), 'guarantor_last_name');

        $phone = self::digits(self::raw($post, 'guarantor_phone'));
        if (strlen($phone) < 10 || strlen($phone) > 14) {
            self::error(__('Enter a valid guarantor phone number.', 'matrix-mlm'), 'guarantor_phone');
        }

        return [
            'guarantor_first_name' => $first,
            'guarantor_last_name'  => $last,
            'guarantor_phone'      => $phone,
        ];
    }


    /**
     * Terms & Conditions agreement. Must be the literal '1' from the
     * checkbox; any other value is treated as not-agreed and rejected.
     */
    private static function validate_terms($post) {
        $agreed = self::raw($post, 'agreed_terms');
        if ($agreed !== '1') {
            self::error(__('You must agree to the Terms & Conditions to submit your application.', 'matrix-mlm'), 'agreed_terms');
        }
    }

    /**
     * Process all eight file uploads. Required uploads on a fresh
     * application are NIN, Utility Bill, Valid ID, Passport Photo,
     * Guarantor's Valid ID, Guarantor's Passport Photo. Marketing
     * Material and Project Information are optional.
     *
     * On a re-submission ($existing != null), every file becomes
     * optional — leaving an upload empty keeps the previously stored
     * URL untouched. This lets a member fix a typo in their NIN
     * field without re-uploading the seven other documents.
     */
    private static function handle_uploads($user_id, $existing = null) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        $is_resubmission = $existing !== null;

        $required = [
            'nin_file'         => ['col' => 'nin_file_url',         'mimes' => self::DOC_MIMES,   'label' => __('NIN', 'matrix-mlm')],
            'utility_bill'     => ['col' => 'utility_bill_url',     'mimes' => self::DOC_MIMES,   'label' => __('Utility Bill', 'matrix-mlm')],
            'valid_id'         => ['col' => 'valid_id_url',         'mimes' => self::DOC_MIMES,   'label' => __('Valid ID', 'matrix-mlm')],
            'passport_photo'   => ['col' => 'passport_photo_url',   'mimes' => self::PHOTO_MIMES, 'label' => __('Passport Photo', 'matrix-mlm')],
            'guarantor_id'     => ['col' => 'guarantor_valid_id_url', 'mimes' => self::DOC_MIMES, 'label' => __('Guarantor Valid ID', 'matrix-mlm')],
            'guarantor_photo'  => ['col' => 'guarantor_passport_url', 'mimes' => self::PHOTO_MIMES, 'label' => __('Guarantor Passport Photo', 'matrix-mlm')],
        ];
        $optional = [
            'marketing_material' => ['col' => 'marketing_material_url', 'mimes' => self::DOC_MIMES, 'label' => __('Project Marketing Material', 'matrix-mlm')],
            'project_info'       => ['col' => 'project_info_url',       'mimes' => self::DOC_MIMES, 'label' => __('Project Information', 'matrix-mlm')],
        ];


        $out = [];
        foreach ($required as $field => $spec) {
            $url = self::upload_one($field, $spec, $user_id);
            if ($url === null) {
                if ($is_resubmission && !empty($existing->{$spec['col']})) {
                    // Keep the previously stored URL.
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

    /**
     * Run a single $_FILES entry through wp_handle_upload with our
     * mime/size constraints. Returns:
     *   - null if the upload slot is empty (caller decides whether
     *     that's OK based on required/optional + resubmission state)
     *   - the resulting URL on success
     *   - never returns false; size/mime/upload errors raise via
     *     self::error() so the JS can highlight the field.
     */
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

        if ((int) $file['size'] > self::MAX_FILE_BYTES) {
            self::error(sprintf(
                /* translators: 1: document label, 2: max MB */
                __('%1$s is too large. Maximum size is %2$d MB.', 'matrix-mlm'),
                $spec['label'],
                (int) (self::MAX_FILE_BYTES / 1048576)
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
                // Namespace by user id + field so two members or two
                // fields can never collide on a shared filename, and
                // so an admin reviewing the uploads folder can tell
                // which row a file belongs to without joining the DB.
                $base = sanitize_file_name(pathinfo($name, PATHINFO_FILENAME));
                return sprintf('matrix-loan-%d-%s-%s%s', $user_id, $field, substr(md5($base . microtime(true)), 0, 8), $ext);
            },
        ]);
        if (isset($upload['error'])) {
            self::error($upload['error'], $field);
        }
        return $upload['url'];
    }

    /**
     * UPSERT into matrix_loan_applications. Approved applications
     * cannot be overwritten by a self-service resubmission — at that
     * point any change is an admin/operator concern. Returns the
     * user-facing success message.
     */
    private static function save($user_id, $existing, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_loan_applications';


        if ($existing && $existing->status === 'approved') {
            self::error(__('Your loan is already approved. Contact support to make changes.', 'matrix-mlm'));
        }

        if ($existing) {
            $result = $wpdb->update($table, $data, ['id' => $existing->id]);
            if ($result === false) {
                error_log('Matrix Loan submit (update) failed: ' . $wpdb->last_error);
                self::error(__('Could not save your application. Please try again.', 'matrix-mlm'));
            }
            return __('Your loan application has been updated and is pending review.', 'matrix-mlm');
        }

        $data['created_at'] = current_time('mysql');
        $result = $wpdb->insert($table, $data);
        if ($result === false) {
            error_log('Matrix Loan submit (insert) failed: ' . $wpdb->last_error);
            self::error(__('Could not submit your application. Please try again.', 'matrix-mlm'));
        }
        return __('Your loan application has been submitted and is pending review.', 'matrix-mlm');
    }

    // ============================================================
    // Helpers — small validators kept local to this class so the
    // form's input handling is self-contained and easy to audit.
    // ============================================================

    private static function raw($post, $key) {
        return isset($post[$key]) ? (string) wp_unslash($post[$key]) : '';
    }

    private static function txt($post, $key) {
        return trim(sanitize_text_field(self::raw($post, $key)));
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

    /**
     * Active-plan gate. Re-checked server-side because the UI's
     * eligibility check is only advisory — never trust a button.
     */
    public static function user_is_eligible($user_id) {
        $user_id = intval($user_id);
        if ($user_id <= 0 || !class_exists('Matrix_MLM_User')) {
            return false;
        }
        $plans = Matrix_MLM_User::get_active_plans($user_id);
        return !empty($plans);
    }

    /**
     * Return the user's existing application row (if any) for
     * resubmission UPDATE semantics and for prefill on re-open.
     */
    public static function get_user_request($user_id) {
        $user_id = intval($user_id);
        if ($user_id <= 0 || !self::table_exists()) {
            return null;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_loan_applications';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d",
            $user_id
        ));
    }

    /**
     * INFORMATION_SCHEMA probe so a missing-table install (older
     * schema, repair pending) doesn't bomb on first read or write.
     */
    private static function table_exists() {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_loan_applications';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME, $table
        )) > 0;
    }


    /**
     * Return the bank list to render in the dropdown. Uses the
     * Fintava-aware Nigerian banks fallback list that the bank-payout
     * form already uses, keeping the available banks consistent
     * across the dashboard. Names only — the loan form doesn't need
     * the CBN sortCode that Fintava transfers do.
     */
    public static function banks() {
        if (class_exists('Matrix_MLM_Fintava')) {
            $banks = Matrix_MLM_Fintava::get_static_banks_fallback();
            if (is_array($banks) && !empty($banks)) {
                $names = array_map(function ($b) {
                    return is_array($b) ? ($b['name'] ?? '') : (string) $b;
                }, $banks);
                $names = array_filter(array_unique($names));
                sort($names);
                return array_values($names);
            }
        }
        // Belt-and-braces fallback when the gateway helper is absent.
        return [
            'Access Bank', 'Citibank Nigeria', 'Ecobank Nigeria', 'Fidelity Bank',
            'First Bank of Nigeria', 'First City Monument Bank (FCMB)', 'Globus Bank',
            'Guaranty Trust Bank (GTBank)', 'Heritage Bank', 'Jaiz Bank', 'Keystone Bank',
            'Optimus Bank', 'Parallex Bank', 'Polaris Bank', 'PremiumTrust Bank',
            'Providus Bank', 'Stanbic IBTC Bank', 'Standard Chartered Bank',
            'Sterling Bank', 'SunTrust Bank', 'Titan Trust Bank', 'Union Bank',
            'United Bank for Africa (UBA)', 'Unity Bank', 'Wema Bank', 'Zenith Bank',
        ];
    }

    /**
     * Country list used by the address dropdowns. Mirrors the list
     * the operator pasted in the form spec and is rendered into
     * both the personal address and the business address selects.
     * Static to avoid building this 200-entry array per request.
     */
    public static function countries() {
        static $list = null;
        if ($list !== null) {
            return $list;
        }

        $list = [
            'Afghanistan', 'Aland Islands', 'Albania', 'Algeria', 'American Samoa',
            'Andorra', 'Angola', 'Anguilla', 'Antarctica', 'Antigua and Barbuda',
            'Argentina', 'Armenia', 'Aruba', 'Australia', 'Austria', 'Azerbaijan',
            'Bahamas', 'Bahrain', 'Bangladesh', 'Barbados', 'Belarus', 'Belgium',
            'Belize', 'Benin', 'Bermuda', 'Bhutan', 'Bolivia',
            'Bonaire, Saint Eustatius and Saba', 'Bosnia and Herzegovina',
            'Botswana', 'Bouvet Island', 'Brazil', 'British Indian Ocean Territory',
            'British Virgin Islands', 'Brunei', 'Bulgaria', 'Burkina Faso',
            'Burundi', 'Cabo Verde', 'Cambodia', 'Cameroon', 'Canada',
            'Cayman Islands', 'Central African Republic', 'Chad', 'Chile', 'China',
            'Christmas Island', 'Cocos (Keeling) Islands', 'Colombia', 'Comoros',
            'Cook Islands', 'Costa Rica', 'Croatia', 'Cuba', 'Curaçao', 'Cyprus',
            'Czech Republic',
            'Democratic Republic of the Congo (Kinshasa)', 'Denmark', 'Djibouti',
            'Dominica', 'Dominican Republic', 'Ecuador', 'Egypt', 'El Salvador',
            'Equatorial Guinea', 'Eritrea', 'Estonia', 'Eswatini', 'Ethiopia',
            'Falkland Islands', 'Faroe Islands', 'Fiji', 'Finland', 'France',
            'French Guiana', 'French Polynesia', 'French Southern Territories',
            'Gabon', 'Gambia', 'Georgia', 'Germany', 'Ghana', 'Gibraltar',
            'Greece', 'Greenland', 'Grenada', 'Guadeloupe', 'Guam', 'Guatemala',
            'Guernsey', 'Guinea', 'Guinea-Bissau', 'Guyana', 'Haiti',
            'Heard Island and McDonald Islands', 'Honduras', 'Hong Kong',
            'Hungary', 'Iceland', 'India', 'Indonesia', 'Iran', 'Iraq', 'Ireland',
            'Isle of Man', 'Israel', 'Italy', 'Ivory Coast', 'Jamaica', 'Japan',
            'Jersey', 'Jordan', 'Kazakhstan', 'Kenya', 'Kiribati', 'Kosovo',
            'Kuwait', 'Kyrgyzstan', 'Laos', 'Latvia', 'Lebanon', 'Lesotho',
            'Liberia', 'Libya', 'Liechtenstein', 'Lithuania', 'Luxembourg',
            'Macao S.A.R., China', 'Madagascar', 'Malawi', 'Malaysia', 'Maldives',
            'Mali', 'Malta', 'Marshall Islands', 'Martinique', 'Mauritania',
            'Mauritius', 'Mayotte', 'Mexico', 'Micronesia', 'Moldova', 'Monaco',
            'Mongolia', 'Montenegro', 'Montserrat', 'Morocco', 'Mozambique',
            'Myanmar', 'Namibia', 'Nauru', 'Nepal', 'Netherlands', 'New Caledonia',
            'New Zealand', 'Nicaragua', 'Niger', 'Nigeria', 'Niue', 'Norfolk Island',
            'North Korea', 'North Macedonia', 'Northern Mariana Islands', 'Norway',
            'Oman', 'Pakistan', 'Palau', 'Palestinian Territory', 'Panama',
            'Papua New Guinea', 'Paraguay', 'Peru', 'Philippines', 'Pitcairn',
            'Poland', 'Portugal', 'Puerto Rico', 'Qatar',
            'Republic of the Congo (Brazzaville)', 'Romania', 'Russia', 'Rwanda',
        ];

        $list = array_merge($list, [
            'Réunion', 'Saint Barthélemy', 'Saint Helena', 'Saint Kitts and Nevis',
            'Saint Lucia', 'Saint Martin (Dutch part)', 'Saint Martin (French part)',
            'Saint Pierre and Miquelon', 'Saint Vincent and the Grenadines',
            'Samoa', 'San Marino', 'Sao Tome and Principe', 'Saudi Arabia',
            'Senegal', 'Serbia', 'Seychelles', 'Sierra Leone', 'Singapore',
            'Slovakia', 'Slovenia', 'Solomon Islands', 'Somalia', 'South Africa',
            'South Georgia/Sandwich Islands', 'South Korea', 'South Sudan', 'Spain',
            'Sri Lanka', 'Sudan', 'Suriname', 'Svalbard and Jan Mayen', 'Sweden',
            'Switzerland', 'Syria', 'Taiwan', 'Tajikistan', 'Tanzania', 'Thailand',
            'Timor-Leste', 'Togo', 'Tokelau', 'Tonga', 'Trinidad and Tobago',
            'Tunisia', 'Turkmenistan', 'Turks and Caicos Islands', 'Tuvalu',
            'Türkiye', 'Uganda', 'Ukraine', 'United Arab Emirates',
            'United Kingdom (UK)', 'United States (US)',
            'United States (US) Minor Outlying Islands',
            'United States (US) Virgin Islands', 'Uruguay', 'Uzbekistan',
            'Vanuatu', 'Vatican', 'Venezuela', 'Vietnam', 'Wallis and Futuna',
            'Western Sahara', 'Yemen', 'Zambia', 'Zimbabwe',
        ]);
        return $list;
    }

    /**
     * Render the loan-application modal scaffold + the inline JS
     * that wires it up. Emitted once per page (after the benefits
     * grid) by Matrix_MLM_User_Benefits when a loans card is on the
     * page. The trigger button on the loans card carries
     * data-loan-trigger="1" and the JS below binds to that selector.
     *
     * The form is long (30+ inputs across four sections) so the
     * modal dialog is taller and scrolls vertically on overflow —
     * the same hidden-by-default belt-and-braces (HTML hidden +
     * inline display:none + aria-hidden) used by the CUG modal
     * keeps it invisible until the trigger fires.
     */
    public static function render_form_modal($user_id, $card_title = '') {
        $user_id = intval($user_id);
        if ($user_id <= 0) {
            return;
        }
        $existing = self::get_user_request($user_id);
        $user     = get_userdata($user_id);
        $title    = $card_title !== '' ? $card_title : __('Business Loan Application', 'matrix-mlm');

        // Prefill order: existing application row first, then WP
        // profile, then empty.
        $f = function ($col, $fallback = '') use ($existing) {
            return $existing && isset($existing->{$col}) && $existing->{$col} !== null ? (string) $existing->{$col} : $fallback;
        };
        $first  = $f('first_name', $user ? (string) $user->first_name : '');
        $last   = $f('last_name', $user ? (string) $user->last_name : '');
        $email  = $f('email', $user ? (string) $user->user_email : '');

        self::render_modal_open($title, $existing);
        self::render_section_personal($f, $first, $last, $email);
        self::render_section_account($f);
        self::render_section_project($f, $existing);
        self::render_section_guarantor($f, $existing);
        self::render_section_terms();
        self::render_modal_close($existing);
        self::render_inline_js();
    }

    // === HELPER_METHODS_GO_HERE ===

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
                <label><?php _e('E-mail of Applicant', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                <input type="email" name="email" value="<?php echo esc_attr($email); ?>" maxlength="120" required>
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Phone / Mobile No', 'matrix-mlm'); ?> <span aria-hidden="true">*</span> <span class="matrix-form-optional">(<?php _e('Nigeria +234', 'matrix-mlm'); ?>)</span></label>
                <input type="tel" name="phone" value="<?php echo esc_attr($f('phone')); ?>" inputmode="tel" maxlength="20" required>
            </div>
            <h4 class="matrix-loan-subhead"><?php _e('Address of Applicant', 'matrix-mlm'); ?></h4>
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
                    <select name="country" required><?php self::render_country_options($f('country', 'Nigeria')); ?></select>
                </div>
            </div>
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label><?php _e('Date of Birth', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="date" name="date_of_birth" value="<?php echo esc_attr($f('date_of_birth')); ?>" required>
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Known-As Name (Trade Name)', 'matrix-mlm'); ?></label>
                    <input type="text" name="trade_name" value="<?php echo esc_attr($f('trade_name')); ?>" maxlength="120" placeholder="<?php esc_attr_e('e.g. Mummy Kate', 'matrix-mlm'); ?>">
                </div>
            </div>
        </fieldset>
        <?php
    }

    private static function render_modal_open($title, $existing) {
        $status = $existing ? (string) $existing->status : '';
        $status_labels = [
            'pending'      => __('Your previous application is pending review. Resubmitting will replace it.', 'matrix-mlm'),
            'under_review' => __('Your application is under review. Resubmitting will replace it.', 'matrix-mlm'),
            'approved'     => __('Your loan is approved. Contact support to make changes.', 'matrix-mlm'),
            'rejected'     => __('Your previous application was rejected. You can update and resubmit it below.', 'matrix-mlm'),
            'cancelled'    => __('Your previous application was cancelled. You can resubmit it below.', 'matrix-mlm'),
        ];
        ?>
        <div class="matrix-loan-modal" id="matrix-loan-modal"
             hidden style="display:none;"
             aria-hidden="true" role="dialog" aria-modal="true">
            <div class="matrix-loan-modal-backdrop" data-loan-modal-close></div>
            <div class="matrix-loan-modal-dialog" role="document">
                <button type="button" class="matrix-loan-modal-close" data-loan-modal-close
                        aria-label="<?php esc_attr_e('Close', 'matrix-mlm'); ?>">&times;</button>
                <h3 class="matrix-loan-modal-title"><?php echo esc_html($title); ?></h3>
                <p class="matrix-loan-modal-intro"><?php
                    _e('Complete every section to apply for a Liberty Hub business loan. Required documents must be PDF, JPG, PNG, or WebP files (5 MB max).', 'matrix-mlm');
                ?></p>
                <?php if ($existing): ?>
                <div class="matrix-loan-status matrix-loan-status-<?php echo esc_attr($status); ?>">
                    <?php echo esc_html($status_labels[$status] ?? ''); ?>
                </div>
                <?php endif; ?>
                <form class="matrix-loan-form" id="matrix-loan-form" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::AJAX_ACTION); ?>">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('matrix_mlm_nonce')); ?>">
        <?php
    }

    // === MORE_HELPERS_HERE ===

    private static function render_section_account($f) {
        ?>
        <fieldset class="matrix-loan-section">
            <legend><?php _e('Customer Account Details', 'matrix-mlm'); ?></legend>
            <div class="matrix-form-group">
                <label><?php _e('Bank', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                <select name="bank_name" required>
                    <option value=""><?php esc_html_e('- Select -', 'matrix-mlm'); ?></option>
                    <?php
                    $current = $f('bank_name');
                    foreach (self::banks() as $bank) {
                        printf('<option value="%s" %s>%s</option>',
                            esc_attr($bank), selected($current, $bank, false), esc_html($bank));
                    }
                    ?>
                </select>
            </div>
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label><?php _e('Account Number', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="text" name="account_number" value="<?php echo esc_attr($f('account_number')); ?>" inputmode="numeric" pattern="\d{10}" maxlength="10" required>
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Account Name', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="text" name="account_name" value="<?php echo esc_attr($f('account_name')); ?>" maxlength="200" required>
                </div>
            </div>
        </fieldset>
        <?php
    }
    // === MORE2 ===

    private static function render_section_project($f, $existing) {
        $applying_as = $f('applying_as');
        $reason      = $f('loan_reason');
        $repay       = $f('repayment_plan');
        $has_assets  = $existing ? (string) (int) $existing->has_assets_statement : '';
        $prev_fin    = $existing ? (string) (int) $existing->previously_financed : '';
        ?>
        <fieldset class="matrix-loan-section">
            <legend><?php _e('Project Details', 'matrix-mlm'); ?></legend>
            <div class="matrix-form-group">
                <label><?php _e('Applying as', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                <div class="matrix-loan-radios">
                    <?php foreach ([
                        'sole_proprietor' => __('Sole Proprietor', 'matrix-mlm'),
                        'partnership'     => __('Partnership', 'matrix-mlm'),
                        'corporation'     => __('Corporation', 'matrix-mlm'),
                    ] as $val => $label): ?>
                    <label class="matrix-loan-radio">
                        <input type="radio" name="applying_as" value="<?php echo esc_attr($val); ?>" <?php checked($applying_as, $val); ?> required>
                        <span><?php echo esc_html($label); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <h4 class="matrix-loan-subhead"><?php _e('Planned Business Location', 'matrix-mlm'); ?></h4>
            <div class="matrix-form-group">
                <label><?php _e('Address Line 1', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                <input type="text" name="business_address_line1" value="<?php echo esc_attr($f('business_address_line1')); ?>" maxlength="200" required>
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Address Line 2', 'matrix-mlm'); ?></label>
                <input type="text" name="business_address_line2" value="<?php echo esc_attr($f('business_address_line2')); ?>" maxlength="200">
            </div>
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label><?php _e('City', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="text" name="business_city" value="<?php echo esc_attr($f('business_city')); ?>" maxlength="100" required>
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('State', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="text" name="business_state" value="<?php echo esc_attr($f('business_state')); ?>" maxlength="100" required>
                </div>
            </div>
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label><?php _e('Zip Code', 'matrix-mlm'); ?></label>
                    <input type="text" name="business_zip" value="<?php echo esc_attr($f('business_zip')); ?>" maxlength="20">
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Country', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <select name="business_country" required><?php self::render_country_options($f('business_country', 'Nigeria')); ?></select>
                </div>
            </div>
            <div class="matrix-form-group">
                <label><?php _e('BVN', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                <input type="text" name="bvn" value="<?php echo esc_attr($f('bvn')); ?>" inputmode="numeric" pattern="\d{11}" maxlength="11" required>
                <p class="matrix-form-hint"><?php _e('Your 11-digit Bank Verification Number.', 'matrix-mlm'); ?></p>
            </div>
            <h4 class="matrix-loan-subhead"><?php _e('Documents', 'matrix-mlm'); ?></h4>
            <p class="matrix-loan-hint"><?php _e('PDF, JPG, PNG, or WebP. Max 5 MB each.', 'matrix-mlm'); ?></p>
            <?php
            $required_files = [
                'nin_file'        => ['label' => __('NIN', 'matrix-mlm'),                  'col' => 'nin_file_url',         'photo' => false],
                'utility_bill'    => ['label' => __('Utility Bill', 'matrix-mlm'),         'col' => 'utility_bill_url',     'photo' => false],
                'valid_id'        => ['label' => __('Valid ID', 'matrix-mlm'),             'col' => 'valid_id_url',         'photo' => false],
                'passport_photo'  => ['label' => __('Passport Photo', 'matrix-mlm'),       'col' => 'passport_photo_url',   'photo' => true],
            ];
            $optional_files = [
                'marketing_material' => ['label' => __('Project Marketing Material', 'matrix-mlm'), 'col' => 'marketing_material_url', 'photo' => false],
                'project_info'       => ['label' => __('Project Information', 'matrix-mlm'),       'col' => 'project_info_url',       'photo' => false],
            ];
            foreach ($required_files as $name => $spec) self::render_file_input($name, $spec, $existing, true);
            foreach ($optional_files as $name => $spec) self::render_file_input($name, $spec, $existing, false);
            ?>
            <div class="matrix-form-group">
                <label><?php _e('Does the borrower have an up to date assets and liabilities statement?', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                <?php self::render_yes_no('has_assets_statement', $has_assets); ?>
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Have you previously been financed?', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                <?php self::render_yes_no('previously_financed', $prev_fin); ?>
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Loan Reason', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                <div class="matrix-loan-radios">
                    <?php foreach ([
                        'sme'         => __('SME', 'matrix-mlm'),
                        'farming'     => __('Farming', 'matrix-mlm'),
                        'refinancing' => __('Refinancing', 'matrix-mlm'),
                        'other'       => __('Other', 'matrix-mlm'),
                    ] as $val => $label): ?>
                    <label class="matrix-loan-radio">
                        <input type="radio" name="loan_reason" value="<?php echo esc_attr($val); ?>" <?php checked($reason, $val); ?> required>
                        <span><?php echo esc_html($label); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label><?php _e('Project Gross Value (₦)', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="number" name="project_gross_value" value="<?php echo esc_attr($f('project_gross_value')); ?>" min="1" step="0.01" required>
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Loan Amount (₦)', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="number" name="loan_amount" value="<?php echo esc_attr($f('loan_amount')); ?>" min="<?php echo esc_attr(self::MIN_LOAN_AMOUNT); ?>" max="<?php echo esc_attr(self::MAX_LOAN_AMOUNT); ?>" step="0.01" required>
                    <p class="matrix-form-hint"><?php
                        printf(
                            esc_html__('Between %1$s and %2$s.', 'matrix-mlm'),
                            number_format(self::MIN_LOAN_AMOUNT),
                            number_format(self::MAX_LOAN_AMOUNT)
                        );
                    ?></p>
                </div>
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Repayment Plan', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                <p class="matrix-form-hint"><?php _e('Choose the schedule that suits your business plan.', 'matrix-mlm'); ?></p>
                <div class="matrix-loan-radios">
                    <?php foreach ([
                        'daily'   => __('Daily', 'matrix-mlm'),
                        'weekly'  => __('Weekly', 'matrix-mlm'),
                        'monthly' => __('Monthly', 'matrix-mlm'),
                    ] as $val => $label): ?>
                    <label class="matrix-loan-radio">
                        <input type="radio" name="repayment_plan" value="<?php echo esc_attr($val); ?>" <?php checked($repay, $val); ?> required>
                        <span><?php echo esc_html($label); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </fieldset>
        <?php
    }
    // === MORE3 ===

    private static function render_section_guarantor($f, $existing) {
        ?>
        <fieldset class="matrix-loan-section">
            <legend><?php _e("Guarantor's Details", 'matrix-mlm'); ?></legend>
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label><?php _e('First Name', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="text" name="guarantor_first_name" value="<?php echo esc_attr($f('guarantor_first_name')); ?>" minlength="2" maxlength="60" required>
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Last Name', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="text" name="guarantor_last_name" value="<?php echo esc_attr($f('guarantor_last_name')); ?>" minlength="2" maxlength="60" required>
                </div>
            </div>
            <div class="matrix-form-group">
                <label><?php _e('Phone / Mobile No', 'matrix-mlm'); ?> <span aria-hidden="true">*</span> <span class="matrix-form-optional">(<?php _e('Nigeria +234', 'matrix-mlm'); ?>)</span></label>
                <input type="tel" name="guarantor_phone" value="<?php echo esc_attr($f('guarantor_phone')); ?>" inputmode="tel" maxlength="20" required>
            </div>
            <?php
            self::render_file_input('guarantor_id', [
                'label' => __("Guarantor's Valid ID", 'matrix-mlm'),
                'col'   => 'guarantor_valid_id_url',
                'photo' => false,
            ], $existing, true);
            self::render_file_input('guarantor_photo', [
                'label' => __("Guarantor's Passport Photo", 'matrix-mlm'),
                'col'   => 'guarantor_passport_url',
                'photo' => true,
            ], $existing, true);
            ?>
        </fieldset>
        <?php
    }

    private static function render_section_terms() {
        ?>
        <fieldset class="matrix-loan-section">
            <legend><?php _e('Terms &amp; Conditions', 'matrix-mlm'); ?></legend>
            <div class="matrix-loan-terms" tabindex="0">
                <p><strong><?php _e('1. Eligibility', 'matrix-mlm'); ?></strong> <?php _e('Applicant must be a fully registered and active member of Liberty Hub Cooperative for a minimum of 6 months, must have completed KYC and have an active Matrix Wallet account, must not be in default on any existing Liberty Hub loan, and must be 18-65 years of age.', 'matrix-mlm'); ?></p>
                <p><strong><?php _e('2. Loan Application &amp; Approval', 'matrix-mlm'); ?></strong> <?php _e('All applications must be submitted through the official Liberty Hub portal. Approval is subject to credit assessment, available loan fund, member contribution history, and matrix level. Liberty Hub reserves the right to approve, decline, or adjust loan amount and tenor without assigning reason.', 'matrix-mlm'); ?></p>
                <p><strong><?php _e('3. Loan Amount, Tenor &amp; Interest', 'matrix-mlm'); ?></strong> <?php _e('Loan amounts range from ₦50,000 to ₦5,000,000. Interest rate is 5%-6.5% per month flat. Tenor is 1 to 12 months; longer tenors require additional collateral or guarantors.', 'matrix-mlm'); ?></p>
                <p><strong><?php _e('4. Disbursement &amp; Repayment', 'matrix-mlm'); ?></strong> <?php _e('Approved loans are disbursed within 3-5 working days. Repayment is via monthly deductions, standing order, or direct payment. Early repayment is allowed without penalty; current-month interest remains payable.', 'matrix-mlm'); ?></p>
                <p><strong><?php _e('5. Fees &amp; Charges', 'matrix-mlm'); ?></strong> <?php _e('A one-time processing fee of 1%-3% applies. Late payment attracts 5% per week on the overdue amount. Legal and recovery costs in default are borne by the borrower.', 'matrix-mlm'); ?></p>
                <p><strong><?php _e('6. Default &amp; Recovery', 'matrix-mlm'); ?></strong> <?php _e('A loan is in default if overdue by 10 days. Liberty Hub may offset balances against member matrix balance, savings, shares, or benefits. Persistent default may result in legal action and credit-bureau reporting.', 'matrix-mlm'); ?></p>
                <p><strong><?php _e('7. Data Consent', 'matrix-mlm'); ?></strong> <?php _e('You consent to Liberty Hub collecting, storing, and sharing credit data with credit bureaus and regulatory authorities as required by law. Personal data is handled per Nigeria\'s Data Protection Act 2023.', 'matrix-mlm'); ?></p>
            </div>
            <label class="matrix-loan-agree">
                <input type="checkbox" name="agreed_terms" value="1" required>
                <span><?php _e('I have read, understood, and agree to be bound by these Terms &amp; Conditions, and confirm the information herein is true and correct.', 'matrix-mlm'); ?></span>
            </label>
        </fieldset>
        <?php
    }
    // === MORE4 ===

    private static function render_modal_close($existing) {
        $label = $existing
            ? __('Update Application', 'matrix-mlm')
            : __('Submit Application', 'matrix-mlm');
        ?>
                    <div class="matrix-loan-feedback" role="status" aria-live="polite"></div>
                    <div class="matrix-loan-actions">
                        <button type="button" class="matrix-btn" data-loan-modal-close>
                            <?php _e('Cancel', 'matrix-mlm'); ?>
                        </button>
                        <button type="submit" class="matrix-btn matrix-btn-primary matrix-loan-submit">
                            <?php echo esc_html($label); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    private static function render_country_options($selected = '') {
        foreach (self::countries() as $country) {
            printf('<option value="%s" %s>%s</option>',
                esc_attr($country),
                selected($selected, $country, false),
                esc_html($country));
        }
    }

    private static function render_yes_no($name, $current) {
        ?>
        <div class="matrix-loan-radios">
            <label class="matrix-loan-radio">
                <input type="radio" name="<?php echo esc_attr($name); ?>" value="1" <?php checked($current, '1'); ?> required>
                <span><?php _e('Yes', 'matrix-mlm'); ?></span>
            </label>
            <label class="matrix-loan-radio">
                <input type="radio" name="<?php echo esc_attr($name); ?>" value="0" <?php checked($current, '0'); ?> required>
                <span><?php _e('No', 'matrix-mlm'); ?></span>
            </label>
        </div>
        <?php
    }
    // === MORE5 ===

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
    // === MORE6 ===

    private static function render_inline_js() {
        $ajax_url = wp_json_encode(admin_url('admin-ajax.php'));
        $err_unreachable = esc_js(__('Cannot reach the server. Please refresh the page and try again.', 'matrix-mlm'));
        $err_default     = esc_js(__('Submission failed. Please check the form and try again.', 'matrix-mlm'));
        $err_network     = esc_js(__('Network error. Please try again.', 'matrix-mlm'));
        $msg_submitting  = esc_js(__('Submitting…', 'matrix-mlm'));
        $msg_default_ok  = esc_js(__('Application submitted.', 'matrix-mlm'));
        $msg_update_btn  = esc_js(__('Update Application', 'matrix-mlm'));
        ?>
        <script>
        (function() {
            var modal = document.getElementById('matrix-loan-modal');
            var form  = document.getElementById('matrix-loan-form');
            if (!modal || !form) return;

            // PHP-injected admin-ajax URL — same defensive pattern as
            // the CUG modal. The IIFE runs inline before footer
            // scripts so window.matrixMLM is unreliable here; the
            // hardcoded URL guarantees the submit handler always
            // knows where to POST.
            var DEFAULT_AJAX_URL = <?php echo $ajax_url; ?>;
            function getAjaxUrl() {
                if (window.matrixMLM && window.matrixMLM.ajaxUrl) return window.matrixMLM.ajaxUrl;
                if (window.ajaxurl) return window.ajaxurl;
                return DEFAULT_AJAX_URL;
            }

            var triggers = document.querySelectorAll('[data-loan-trigger]');
            var submit   = form.querySelector('.matrix-loan-submit');
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
    // === JS_PART2 ===
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
            modal.querySelectorAll('[data-loan-modal-close]').forEach(function(el) {
                el.addEventListener('click', closeModal);
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
            });
    // === JS_PART3 ===
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
