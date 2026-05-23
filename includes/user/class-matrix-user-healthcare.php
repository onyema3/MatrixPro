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
 * Form shape (rewritten in 1.0.7) — a single form with two
 * branches selected at the top:
 *
 *   - Adult       : Basic Information → Address → Hospital Selection
 *   - Dependant   : Parent's Information → Child's Information →
 *                   Address → Hospital Selection
 *
 * "Choice of Hospital" reads from the admin-managed
 * wp_matrix_hospitals table (CRUD on the Healthcare → Hospitals
 * admin page) and is filtered client-side by the selected
 * "Hospital State" — the full active list is dumped into the
 * inline JS so picking a state filters the dropdown without an
 * extra round trip.
 *
 * The class still references reusable helpers from
 * Matrix_MLM_User_Loan (countries()) so the country dropdown
 * stays in lockstep with the loan form's 200-entry list without
 * duplicating it here.
 *
 * Pre-1.0.7 columns (plan tier, coverage type, medical profile,
 * next of kin, document URLs) are kept on the table so existing
 * applications still render correctly in the admin UI; the new
 * form just doesn't write to them.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_User_Healthcare {

    /** AJAX action name — mirrored in the inline JS submit handler.
     *  Kept as a constant so the two ends never drift, and held
     *  unchanged across the 1.0.7 rewrite so a stale page still
     *  posts to a valid endpoint. */
    const AJAX_ACTION = 'matrix_submit_healthcare';

    /** Top-level branch selector. Anything else short-circuits the
     *  form with a "choose adult or dependant" error. */
    const APPLICANT_TYPES = ['adult', 'dependant'];

    /** Form-side gender options. The DB enum still includes 'other'
     *  for legacy rows but the new form only offers Male/Female per
     *  the operator's spec — submitting 'other' from the dashboard
     *  is rejected. */
    const GENDERS = ['male', 'female'];

    /** Nigerian states (36 + FCT). Used both for the address State
     *  dropdown and for the Hospital State filter. Keep this list
     *  in alphabetical order so operators / accessibility tools see
     *  a predictable layout. */
    const NIGERIAN_STATES = [
        'Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa',
        'Benue', 'Borno', 'Cross River', 'Delta', 'Ebonyi', 'Edo',
        'Ekiti', 'Enugu', 'FCT - Abuja', 'Gombe', 'Imo', 'Jigawa',
        'Kaduna', 'Kano', 'Katsina', 'Kebbi', 'Kogi', 'Kwara',
        'Lagos', 'Nasarawa', 'Niger', 'Ogun', 'Ondo', 'Osun', 'Oyo',
        'Plateau', 'Rivers', 'Sokoto', 'Taraba', 'Yobe', 'Zamfara',
    ];

    /** Age window. 0 isn't allowed for the Adult branch (prevents
     *  accidental bare-default date input submissions); 120 is a
     *  generous upper bound. Dependants can be any age — newborns
     *  are routine for HMO family plans — so the lower bound is 0. */
    const MIN_AGE = 0;
    const MAX_AGE = 120;

    public function __construct() {
        // Authenticated-only — anonymous healthcare submissions are
        // nonsense, and the eligibility check (active plan) further
        // gates submission to LibertyMatrix members. We deliberately
        // dropped the "Are you a LibertyMatrix Member?" question
        // from the form because reaching this code path already
        // implies membership.
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajax_submit']);
    }

    /**
     * Handle the submission.
     *
     * Response shape (matches loan/CUG):
     *   success: { message: string, status: 'pending' }
     *   error:   { message: string, field?: string }
     *
     * Top-level dispatch on `applicant_type`. Each branch composes
     * a column-keyed payload that maps directly to wp_matrix_healthcare_applications;
     * the two branches share the Address and Hospital validators
     * because those sections are identical between Adult and
     * Dependant.
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

        $applicant_type = self::validate_applicant_type($_POST);

        $data = ['applicant_type' => $applicant_type];
        if ($applicant_type === 'adult') {
            $data += self::validate_adult_personal($_POST);
            // Adult flow does not collect parent info — null the
            // columns out explicitly so an UPDATE on a previous
            // dependant submission doesn't leave stale parent
            // fields hanging.
            $data += [
                'parent_first_name' => null,
                'parent_last_name'  => null,
                'parent_phone'      => null,
                'parent_whatsapp'   => null,
            ];
        } else {
            $data += self::validate_parent($_POST);
            $data += self::validate_child($_POST);
        }
        $data += self::validate_address($_POST);
        $data += self::validate_hospital($_POST);

        $existing = self::get_user_request($user_id);

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

    private static function validate_applicant_type($post) {
        $type = self::txt($post, 'applicant_type');
        if (!in_array($type, self::APPLICANT_TYPES, true)) {
            self::error(__('Choose whether you are applying as an Adult or for a Dependant.', 'matrix-mlm'), 'applicant_type');
        }
        return $type;
    }

    /**
     * Adult branch — Basic Information block.
     *
     * Required fields: first/last name, email, sex, DOB, phone,
     * whatsapp, NIN. The DOB age-window check is symmetric to the
     * dependant child's check; the gender enum is restricted to
     * Male/Female per the operator's spec.
     */
    private static function validate_adult_personal($post) {
        $first = self::txt($post, 'first_name');
        self::require_len($first, 2, 60, __('First name must be between 2 and 60 characters.', 'matrix-mlm'), 'first_name');

        $last = self::txt($post, 'last_name');
        self::require_len($last, 2, 60, __('Last name must be between 2 and 60 characters.', 'matrix-mlm'), 'last_name');

        $email = sanitize_email(self::raw($post, 'email'));
        if ($email === '' || !is_email($email) || mb_strlen($email) > 120) {
            self::error(__('Enter a valid email address.', 'matrix-mlm'), 'email');
        }

        $gender = self::txt($post, 'gender');
        if (!in_array($gender, self::GENDERS, true)) {
            self::error(__('Choose your sex.', 'matrix-mlm'), 'gender');
        }

        $dob_iso = self::validate_dob($post, 'date_of_birth', __('Enter a valid date of birth.', 'matrix-mlm'));

        $phone = self::digits(self::raw($post, 'phone'));
        if (strlen($phone) < 10 || strlen($phone) > 14) {
            self::error(__('Enter a valid phone number.', 'matrix-mlm'), 'phone');
        }

        $whatsapp = self::digits(self::raw($post, 'whatsapp'));
        if (strlen($whatsapp) < 10 || strlen($whatsapp) > 14) {
            self::error(__('Enter a valid WhatsApp number.', 'matrix-mlm'), 'whatsapp');
        }

        $nin = self::digits(self::raw($post, 'nin'));
        if (!preg_match('/^\d{11}$/', $nin)) {
            self::error(__('NIN must be 11 digits.', 'matrix-mlm'), 'nin');
        }

        return [
            'first_name'    => $first,
            'last_name'     => $last,
            'email'         => $email,
            'gender'        => $gender,
            'date_of_birth' => $dob_iso,
            'phone'         => $phone,
            'whatsapp'      => $whatsapp,
            'nin'           => $nin,
        ];
    }

    /**
     * Dependant branch — Parent's Information block.
     *
     * The parent is the contactable adult on the policy; their
     * phone/whatsapp are required because the HMO partner needs a
     * reachable adult for everything from card delivery to hospital
     * pre-auth calls. Email is collected on the child's section
     * (because the policy correspondence goes there), so we don't
     * also collect a parent email.
     */
    private static function validate_parent($post) {
        $first = self::txt($post, 'parent_first_name');
        self::require_len($first, 2, 60, __("Parent's first name must be between 2 and 60 characters.", 'matrix-mlm'), 'parent_first_name');

        $last = self::txt($post, 'parent_last_name');
        self::require_len($last, 2, 60, __("Parent's last name must be between 2 and 60 characters.", 'matrix-mlm'), 'parent_last_name');

        $phone = self::digits(self::raw($post, 'parent_phone'));
        if (strlen($phone) < 10 || strlen($phone) > 14) {
            self::error(__("Enter a valid parent phone number.", 'matrix-mlm'), 'parent_phone');
        }

        $whatsapp = self::digits(self::raw($post, 'parent_whatsapp'));
        if (strlen($whatsapp) < 10 || strlen($whatsapp) > 14) {
            self::error(__("Enter a valid parent WhatsApp number.", 'matrix-mlm'), 'parent_whatsapp');
        }

        return [
            'parent_first_name' => $first,
            'parent_last_name'  => $last,
            'parent_phone'      => $phone,
            'parent_whatsapp'   => $whatsapp,
        ];
    }

    /**
     * Dependant branch — Child's Information block.
     *
     * The child fields land in the same DB columns as the adult
     * branch (first_name, last_name, email, gender, date_of_birth,
     * phone, whatsapp, nin) — both branches describe "the policy
     * holder", just with different upstream UI labels.
     *
     * Phone/WhatsApp/NIN are *optional* for dependants because
     * minors frequently don't have any of those — the parent
     * fields above are the contactable channel. Anything submitted
     * still has to pass the format check though, so a malformed
     * 5-digit phone gets rejected rather than silently accepted.
     */
    private static function validate_child($post) {
        $first = self::txt($post, 'first_name');
        self::require_len($first, 2, 60, __("Child's first name must be between 2 and 60 characters.", 'matrix-mlm'), 'first_name');

        $last = self::txt($post, 'last_name');
        self::require_len($last, 2, 60, __("Child's last name must be between 2 and 60 characters.", 'matrix-mlm'), 'last_name');

        $email = sanitize_email(self::raw($post, 'email'));
        if ($email === '' || !is_email($email) || mb_strlen($email) > 120) {
            self::error(__('Enter a valid email address.', 'matrix-mlm'), 'email');
        }

        $gender = self::txt($post, 'gender');
        if (!in_array($gender, self::GENDERS, true)) {
            self::error(__("Choose the child's sex.", 'matrix-mlm'), 'gender');
        }

        $dob_iso = self::validate_dob($post, 'date_of_birth', __("Enter a valid date of birth for the child.", 'matrix-mlm'));

        // Optional contact fields — accept blank but format-check
        // anything provided.
        $phone_raw = self::digits(self::raw($post, 'phone'));
        $phone = null;
        if ($phone_raw !== '') {
            if (strlen($phone_raw) < 10 || strlen($phone_raw) > 14) {
                self::error(__('Enter a valid phone number for the child, or leave it blank.', 'matrix-mlm'), 'phone');
            }
            $phone = $phone_raw;
        }

        $whatsapp_raw = self::digits(self::raw($post, 'whatsapp'));
        $whatsapp = null;
        if ($whatsapp_raw !== '') {
            if (strlen($whatsapp_raw) < 10 || strlen($whatsapp_raw) > 14) {
                self::error(__('Enter a valid WhatsApp number for the child, or leave it blank.', 'matrix-mlm'), 'whatsapp');
            }
            $whatsapp = $whatsapp_raw;
        }

        $nin_raw = self::digits(self::raw($post, 'nin'));
        $nin = null;
        if ($nin_raw !== '') {
            if (!preg_match('/^\d{11}$/', $nin_raw)) {
                self::error(__('NIN must be 11 digits, or leave it blank for a child without one.', 'matrix-mlm'), 'nin');
            }
            $nin = $nin_raw;
        }

        return [
            'first_name'    => $first,
            'last_name'     => $last,
            'email'         => $email,
            'gender'        => $gender,
            'date_of_birth' => $dob_iso,
            'phone'         => $phone,
            'whatsapp'      => $whatsapp,
            'nin'           => $nin,
        ];
    }

    /**
     * Shared Address validator — used by both Adult and Dependant
     * branches because the layout is identical. Country defaults
     * to "Nigeria" client-side; rejects anything not on the loan
     * form's curated country list to keep both forms in lockstep.
     */
    private static function validate_address($post) {
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
            'address_line1' => $address1,
            'address_line2' => $address2 !== '' ? $address2 : null,
            'city'          => $city,
            'state'         => $state,
            'zip_code'      => $zip !== '' ? $zip : null,
            'country'       => $country,
        ];
    }

    /**
     * Shared Hospital Selection validator. Server-side guards
     * against:
     *
     *   - hospital_state outside the curated Nigerian-states list
     *     (a malicious or stale POST can't smuggle "Atlantis" in)
     *   - hospital_id pointing at a missing or inactive row (the
     *     admin Hospitals page may have just disabled it)
     *   - hospital_state mismatching the hospital's stored state
     *     (the form's client-side filter prevents this in the UI;
     *     this is the suspenders to its belt)
     *
     * The hospital's display name is mirrored into the legacy
     * `preferred_hospital` column so the admin triage UI's old
     * "Plan & Coverage" card still shows something sensible if it
     * ever falls back to that column for a new-form row.
     */
    private static function validate_hospital($post) {
        $hospital_state = self::txt($post, 'hospital_state');
        if (!in_array($hospital_state, self::NIGERIAN_STATES, true)) {
            self::error(__('Choose your hospital state.', 'matrix-mlm'), 'hospital_state');
        }

        $hospital_id = (int) self::raw($post, 'hospital_id');
        if ($hospital_id <= 0) {
            self::error(__('Choose your hospital of choice.', 'matrix-mlm'), 'hospital_id');
        }

        $hospital = self::get_hospital($hospital_id);
        if (!$hospital || $hospital->status !== 'active') {
            self::error(__('That hospital is no longer available. Please pick another.', 'matrix-mlm'), 'hospital_id');
        }
        if ((string) $hospital->state !== $hospital_state) {
            self::error(__('The selected hospital is not in the chosen state. Please pick again.', 'matrix-mlm'), 'hospital_id');
        }

        return [
            'hospital_id'        => $hospital_id,
            'hospital_state'     => $hospital_state,
            'preferred_hospital' => $hospital->name,
        ];
    }

    /**
     * Parse + age-bound a YYYY-MM-DD date input. Returns the ISO
     * string ready to insert into a DATE column. Calls self::error
     * (which short-circuits) on any failure so callers don't need
     * to branch.
     */
    private static function validate_dob($post, $field, $message) {
        $raw = self::raw($post, $field);
        $ts  = strtotime($raw);
        if (!$raw || $ts === false) {
            self::error($message, $field);
        }
        $age = (int) date('Y') - (int) date('Y', $ts) - (date('md') < date('md', $ts) ? 1 : 0);
        if ($age < self::MIN_AGE || $age > self::MAX_AGE) {
            self::error(sprintf(
                /* translators: 1: min age, 2: max age */
                __('Date of birth implies an age outside the allowed range (%1$d-%2$d years).', 'matrix-mlm'),
                self::MIN_AGE, self::MAX_AGE
            ), $field);
        }
        return date('Y-m-d', $ts);
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

    /**
     * Active hospitals grouped by state — produced once per page
     * load and embedded as JSON in the inline JS so the Hospital
     * dropdown can filter live without a round trip when the user
     * picks a Hospital State.
     *
     * Shape:  [ 'Lagos' => [ ['id'=>1,'name'=>'Reddington'], ... ], ... ]
     *
     * Inactive hospitals are excluded — once an admin disables one,
     * the dropdown reflects that on the next pageload. Hospitals
     * with an unknown state (e.g. seed data with a typo) are
     * silently dropped from the result, mirroring the validator's
     * NIGERIAN_STATES whitelist.
     */
    public static function get_hospitals_grouped() {
        if (!self::hospitals_table_exists()) {
            return [];
        }
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_hospitals';
        $rows = $wpdb->get_results(
            "SELECT id, name, state, address
               FROM {$table}
              WHERE status = 'active'
              ORDER BY state ASC, display_order ASC, name ASC"
        );
        $whitelist = array_flip(self::NIGERIAN_STATES);
        $out = [];
        foreach ((array) $rows as $row) {
            $state = (string) $row->state;
            if (!isset($whitelist[$state])) {
                continue;
            }
            if (!isset($out[$state])) {
                $out[$state] = [];
            }
            $out[$state][] = [
                'id'      => (int) $row->id,
                'name'    => (string) $row->name,
                'address' => (string) ($row->address ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Look up a single hospital row by id. Returns null when the
     * row is missing — callers should treat that as "no longer
     * available" and reject the submission.
     */
    private static function get_hospital($id) {
        $id = (int) $id;
        if ($id <= 0 || !self::hospitals_table_exists()) {
            return null;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_hospitals';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, state, status FROM {$table} WHERE id = %d",
            $id
        ));
    }

    private static function hospitals_table_exists() {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_hospitals';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME, $table
        )) > 0;
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
        $f = static function ($col, $fallback = '') use ($existing) {
            return $existing && isset($existing->{$col}) && $existing->{$col} !== null ? (string) $existing->{$col} : $fallback;
        };
        $first = $f('first_name', $user ? (string) $user->first_name : '');
        $last  = $f('last_name', $user ? (string) $user->last_name : '');
        $email = $f('email', $user ? (string) $user->user_email : '');

        $hospitals_by_state = self::get_hospitals_grouped();

        self::render_modal_open($title, $existing);
        self::render_section_top($f);
        self::render_section_adult_personal($f, $first, $last, $email);
        self::render_section_parent($f);
        self::render_section_child($f, $first, $last, $email);
        self::render_section_address($f);
        self::render_section_hospital($f, $existing);
        self::render_modal_close($existing);
        self::render_inline_js($hospitals_by_state, $existing);
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
                    _e('Choose whether you are applying for yourself (Adult) or for a Dependant, then complete the rest of the form.', 'matrix-mlm');
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
                <form class="matrix-loan-form matrix-healthcare-form" id="matrix-healthcare-form" novalidate>
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::AJAX_ACTION); ?>">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('matrix_mlm_nonce')); ?>">
        <?php
    }

    /** Top-level Adult/Dependant selector. */
    private static function render_section_top($f) {
        $current = $f('applicant_type');
        ?>
        <fieldset class="matrix-loan-section" data-healthcare-section="top">
            <legend><?php _e('Make a Selection', 'matrix-mlm'); ?></legend>
            <div class="matrix-form-group">
                <label for="matrix-healthcare-applicant-type"><?php _e('Applying as', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                <select name="applicant_type" id="matrix-healthcare-applicant-type" required data-healthcare-applicant-type>
                    <option value=""><?php esc_html_e('- Make a Choice -', 'matrix-mlm'); ?></option>
                    <option value="adult" <?php selected($current, 'adult'); ?>><?php esc_html_e('Adult', 'matrix-mlm'); ?></option>
                    <option value="dependant" <?php selected($current, 'dependant'); ?>><?php esc_html_e('Dependant', 'matrix-mlm'); ?></option>
                </select>
                <p class="matrix-form-hint">
                    <?php _e('Adult: enrolling yourself. Dependant: enrolling a child or other dependant under your care.', 'matrix-mlm'); ?>
                </p>
            </div>
        </fieldset>
        <?php
    }

    /** Adult branch — Basic Information. Hidden until applicant_type === 'adult'. */
    private static function render_section_adult_personal($f, $first, $last, $email) {
        $applicant_type = $f('applicant_type');
        $hidden_attr = $applicant_type === 'adult' ? '' : ' hidden';
        ?>
        <fieldset class="matrix-loan-section matrix-healthcare-branch matrix-healthcare-branch-adult"
                  data-healthcare-branch="adult"<?php echo $hidden_attr; ?>>
            <legend><?php _e('Basic Information', 'matrix-mlm'); ?></legend>
            <p class="matrix-loan-hint"><?php _e('Basic Information for your policy.', 'matrix-mlm'); ?></p>
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label><?php _e('First Name', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="text" name="first_name" data-branch-field="adult" value="<?php echo esc_attr($applicant_type === 'adult' ? $first : ''); ?>" minlength="2" maxlength="60">
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Last Name', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="text" name="last_name" data-branch-field="adult" value="<?php echo esc_attr($applicant_type === 'adult' ? $last : ''); ?>" minlength="2" maxlength="60">
                </div>
            </div>
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label><?php _e('Email', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="email" name="email" data-branch-field="adult" value="<?php echo esc_attr($applicant_type === 'adult' ? $email : ''); ?>" maxlength="120">
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Sex', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <select name="gender" data-branch-field="adult">
                        <option value=""><?php esc_html_e('- Select -', 'matrix-mlm'); ?></option>
                        <?php
                        $g = $applicant_type === 'adult' ? $f('gender') : '';
                        foreach ([
                            'male'   => __('Male', 'matrix-mlm'),
                            'female' => __('Female', 'matrix-mlm'),
                        ] as $val => $label) {
                            printf('<option value="%s" %s>%s</option>', esc_attr($val), selected($g, $val, false), esc_html($label));
                        }
                        ?>
                    </select>
                </div>
            </div>
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label><?php _e('Date of Birth', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="date" name="date_of_birth" data-branch-field="adult" value="<?php echo esc_attr($applicant_type === 'adult' ? $f('date_of_birth') : ''); ?>">
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('National Identity Number (NIN)', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="text" name="nin" data-branch-field="adult" value="<?php echo esc_attr($applicant_type === 'adult' ? $f('nin') : ''); ?>" inputmode="numeric" pattern="\d{11}" maxlength="11">
                    <p class="matrix-form-hint"><?php _e('Your 11-digit National Identification Number.', 'matrix-mlm'); ?></p>
                </div>
            </div>
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label><?php _e('Phone / Mobile', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <?php self::render_ng_phone_input('phone', $applicant_type === 'adult' ? $f('phone') : '', 'adult'); ?>
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('WhatsApp Number', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <?php self::render_ng_phone_input('whatsapp', $applicant_type === 'adult' ? $f('whatsapp') : '', 'adult'); ?>
                </div>
            </div>
        </fieldset>
        <?php
    }

    /** Dependant branch — Parent's Information. Hidden until applicant_type === 'dependant'. */
    private static function render_section_parent($f) {
        $applicant_type = $f('applicant_type');
        $hidden_attr = $applicant_type === 'dependant' ? '' : ' hidden';
        ?>
        <fieldset class="matrix-loan-section matrix-healthcare-branch matrix-healthcare-branch-dependant"
                  data-healthcare-branch="dependant"<?php echo $hidden_attr; ?>>
            <legend><?php _e("Parent's Information", 'matrix-mlm'); ?></legend>
            <p class="matrix-loan-hint"><?php _e("Parents' Information.", 'matrix-mlm'); ?></p>
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label><?php _e('First Name', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="text" name="parent_first_name" data-branch-field="dependant" value="<?php echo esc_attr($f('parent_first_name')); ?>" minlength="2" maxlength="60">
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Last Name', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="text" name="parent_last_name" data-branch-field="dependant" value="<?php echo esc_attr($f('parent_last_name')); ?>" minlength="2" maxlength="60">
                </div>
            </div>
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label><?php _e('Phone Number', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <?php self::render_ng_phone_input('parent_phone', $f('parent_phone'), 'dependant'); ?>
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('WhatsApp Number', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <?php self::render_ng_phone_input('parent_whatsapp', $f('parent_whatsapp'), 'dependant'); ?>
                </div>
            </div>
        </fieldset>
        <?php
    }

    /** Dependant branch — Child's Information. Hidden until applicant_type === 'dependant'. */
    private static function render_section_child($f, $first, $last, $email) {
        $applicant_type = $f('applicant_type');
        $hidden_attr = $applicant_type === 'dependant' ? '' : ' hidden';
        ?>
        <fieldset class="matrix-loan-section matrix-healthcare-branch matrix-healthcare-branch-dependant"
                  data-healthcare-branch="dependant"<?php echo $hidden_attr; ?>>
            <legend><?php _e("Child's Information", 'matrix-mlm'); ?></legend>
            <p class="matrix-loan-hint"><?php _e('Basic Information for your policy.', 'matrix-mlm'); ?></p>
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label><?php _e('Child First Name', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="text" name="first_name" data-branch-field="dependant" value="<?php echo esc_attr($applicant_type === 'dependant' ? $first : ''); ?>" minlength="2" maxlength="60">
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Child Last Name', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="text" name="last_name" data-branch-field="dependant" value="<?php echo esc_attr($applicant_type === 'dependant' ? $last : ''); ?>" minlength="2" maxlength="60">
                </div>
            </div>
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label><?php _e('Email', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="email" name="email" data-branch-field="dependant" value="<?php echo esc_attr($applicant_type === 'dependant' ? $email : ''); ?>" maxlength="120">
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('Sex', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <select name="gender" data-branch-field="dependant">
                        <option value=""><?php esc_html_e('- Select -', 'matrix-mlm'); ?></option>
                        <?php
                        $g = $applicant_type === 'dependant' ? $f('gender') : '';
                        foreach ([
                            'male'   => __('Male', 'matrix-mlm'),
                            'female' => __('Female', 'matrix-mlm'),
                        ] as $val => $label) {
                            printf('<option value="%s" %s>%s</option>', esc_attr($val), selected($g, $val, false), esc_html($label));
                        }
                        ?>
                    </select>
                </div>
            </div>
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label><?php _e('Date of Birth', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <input type="date" name="date_of_birth" data-branch-field="dependant" value="<?php echo esc_attr($applicant_type === 'dependant' ? $f('date_of_birth') : ''); ?>">
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('National Identity Number (NIN)', 'matrix-mlm'); ?></label>
                    <input type="text" name="nin" data-branch-field="dependant" value="<?php echo esc_attr($applicant_type === 'dependant' ? $f('nin') : ''); ?>" inputmode="numeric" pattern="\d{11}" maxlength="11">
                    <p class="matrix-form-hint"><?php _e('Optional for minors. 11 digits if provided.', 'matrix-mlm'); ?></p>
                </div>
            </div>
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label><?php _e('Phone / Mobile', 'matrix-mlm'); ?></label>
                    <?php self::render_ng_phone_input('phone', $applicant_type === 'dependant' ? $f('phone') : '', 'dependant'); ?>
                </div>
                <div class="matrix-form-group">
                    <label><?php _e('WhatsApp Number', 'matrix-mlm'); ?></label>
                    <?php self::render_ng_phone_input('whatsapp', $applicant_type === 'dependant' ? $f('whatsapp') : '', 'dependant'); ?>
                </div>
            </div>
        </fieldset>
        <?php
    }

    /** Shared Address section — visible regardless of branch. */
    private static function render_section_address($f) {
        ?>
        <fieldset class="matrix-loan-section" data-healthcare-section="address">
            <legend><?php _e('Address', 'matrix-mlm'); ?></legend>
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

    /** Shared Hospital Selection — visible regardless of branch. */
    private static function render_section_hospital($f, $existing) {
        $current_state = $f('hospital_state');
        $current_id    = $f('hospital_id');
        ?>
        <fieldset class="matrix-loan-section" data-healthcare-section="hospital">
            <legend><?php _e('Hospital Selection', 'matrix-mlm'); ?></legend>
            <div class="matrix-form-row">
                <div class="matrix-form-group">
                    <label for="matrix-healthcare-hospital-state"><?php _e('Hospital State', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <select name="hospital_state" id="matrix-healthcare-hospital-state" required data-healthcare-hospital-state>
                        <option value=""><?php esc_html_e('- Select your State -', 'matrix-mlm'); ?></option>
                        <?php foreach (self::NIGERIAN_STATES as $state): ?>
                            <option value="<?php echo esc_attr($state); ?>" <?php selected($current_state, $state); ?>>
                                <?php echo esc_html($state); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="matrix-form-group">
                    <label for="matrix-healthcare-hospital-id"><?php _e('Choice of Hospital', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                    <select name="hospital_id" id="matrix-healthcare-hospital-id" required
                            data-healthcare-hospital-id
                            data-current-id="<?php echo esc_attr($current_id); ?>">
                        <option value=""><?php esc_html_e('Select your hospital state first', 'matrix-mlm'); ?></option>
                    </select>
                    <p class="matrix-form-hint">
                        <?php _e('Hospitals are filtered by the state above.', 'matrix-mlm'); ?>
                    </p>
                </div>
            </div>
            <div class="matrix-loan-disclaimer" style="margin-top:8px;padding:12px 14px;background:#fffbeb;border:1px solid #fde68a;border-left:4px solid #f59e0b;border-radius:6px;font-size:13px;line-height:1.5;color:#78350f;">
                <strong><?php _e('Disclaimer — LibertyHub International Limited', 'matrix-mlm'); ?></strong><br>
                <?php _e("LibertyHub International Limited strives to provide a comprehensive list of hospitals for our users. If your preferred hospital is not currently listed, we can enrol you in your hospital of choice, provided you refer a minimum of ten (10) users to sign up for the same hospital. This offer is subject to the terms and conditions of LibertyHub International Limited and may be modified or discontinued at the company's discretion. For further details, please contact our support team.", 'matrix-mlm'); ?>
                <br><br>
                <?php _e('Also note that after registering, there is a 30-day processing period that includes documentation and verification before the HMO issues your ID card, allowing hospital access. You will be notified if the process is completed earlier.', 'matrix-mlm'); ?>
            </div>
        </fieldset>
        <?php
    }

    private static function render_modal_close($existing) {
        $label = $existing
            ? __('Update Application', 'matrix-mlm')
            : __('Submit Form', 'matrix-mlm');
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

    /**
     * Render a Nigeria-prefixed phone input. The "+234" is a
     * non-editable visual prefix; the `<input>` collects digits
     * only and the validator strips non-digits server-side, so
     * users can type "0801…" or "+234 801…" or "234-801-…" and
     * all three normalise to the same column value.
     *
     * $branch_label is one of 'adult' or 'dependant' — used to
     * tag the field so the JS branch-toggler can clear it when
     * the user switches applicant_type, preventing stale parent
     * fields from being submitted on an Adult application.
     */
    private static function render_ng_phone_input($name, $value, $branch_label) {
        ?>
        <div class="matrix-healthcare-phone" style="display:flex;align-items:stretch;gap:0;">
            <span class="matrix-healthcare-phone-prefix" aria-hidden="true"
                  style="display:inline-flex;align-items:center;padding:0 12px;background:#f3f4f6;border:1px solid #d1d5db;border-right:none;border-radius:6px 0 0 6px;font-size:13px;color:#374151;white-space:nowrap;">
                <?php esc_html_e('Nigeria +234', 'matrix-mlm'); ?>
            </span>
            <input type="tel" name="<?php echo esc_attr($name); ?>"
                   data-branch-field="<?php echo esc_attr($branch_label); ?>"
                   value="<?php echo esc_attr($value); ?>"
                   inputmode="tel" maxlength="20"
                   style="flex:1 1 auto;border-radius:0 6px 6px 0;">
        </div>
        <?php
    }

    private static function render_inline_js($hospitals_by_state, $existing) {
        $ajax_url        = wp_json_encode(admin_url('admin-ajax.php'));
        $err_unreachable = esc_js(__('Cannot reach the server. Please refresh the page and try again.', 'matrix-mlm'));
        $err_default     = esc_js(__('Submission failed. Please check the form and try again.', 'matrix-mlm'));
        $err_network     = esc_js(__('Network error. Please try again.', 'matrix-mlm'));
        $err_choose_type = esc_js(__('Choose whether you are applying as an Adult or for a Dependant.', 'matrix-mlm'));
        $msg_submitting  = esc_js(__('Submitting…', 'matrix-mlm'));
        $msg_default_ok  = esc_js(__('Application submitted.', 'matrix-mlm'));
        $msg_update_btn  = esc_js(__('Update Application', 'matrix-mlm'));
        $select_state    = esc_js(__('Select your hospital state first', 'matrix-mlm'));
        $select_hospital = esc_js(__('- Select your Hospital -', 'matrix-mlm'));
        $no_hospitals    = esc_js(__('No hospitals listed for that state yet — contact support.', 'matrix-mlm'));

        $hospitals_json = wp_json_encode($hospitals_by_state ?: new stdClass());
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

            // Active-hospitals dataset, grouped by state. Keys are
            // the same Nigerian-state strings used by the State
            // dropdown so a direct lookup populates the hospital
            // dropdown without any normalisation. Empty object
            // means the operator hasn't seeded any hospitals yet —
            // we show a friendly "contact support" placeholder
            // instead of an empty dropdown.
            var HOSPITALS_BY_STATE = <?php echo $hospitals_json; ?> || {};

            var triggers   = document.querySelectorAll('[data-healthcare-trigger]');
            var submit     = form.querySelector('.matrix-healthcare-submit');
            var feedback   = form.querySelector('.matrix-loan-feedback');
            var typeSelect = form.querySelector('[data-healthcare-applicant-type]');
            var stateSel   = form.querySelector('[data-healthcare-hospital-state]');
            var hospSel    = form.querySelector('[data-healthcare-hospital-id]');
            var branches   = form.querySelectorAll('[data-healthcare-branch]');

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

            // Toggle Adult/Dependant section visibility AND disable
            // inputs in the inactive branch so the browser doesn't
            // submit stale values from the hidden side. We rely on
            // disabled fields being omitted from FormData per the
            // HTML spec — that's what makes the UPSERT clean when
            // a member switches branches mid-edit.
            function applyBranch(value) {
                branches.forEach(function(section) {
                    var match = section.getAttribute('data-healthcare-branch') === value;
                    section.hidden = !match;
                });
                form.querySelectorAll('[data-branch-field]').forEach(function(field) {
                    field.disabled = field.getAttribute('data-branch-field') !== value;
                });
            }
            function onTypeChange() {
                if (!typeSelect) return;
                var v = typeSelect.value || '';
                applyBranch(v);
            }
            if (typeSelect) {
                typeSelect.addEventListener('change', onTypeChange);
                // Initial sync — handles the prefill case where an
                // existing application already has applicant_type
                // set, so the right branch is visible on open.
                onTypeChange();
            }

            // Hospital state → Hospital dropdown filtering. We
            // rebuild the hospital dropdown from scratch on each
            // state change so stale options never linger. The
            // currently-saved hospital_id (if any) is preserved
            // when it belongs to the freshly-selected state, which
            // makes prefill-on-edit work without a round trip.
            function rebuildHospitalDropdown() {
                if (!hospSel) return;
                var state = stateSel ? stateSel.value : '';
                var preserveId = hospSel.getAttribute('data-current-id') || hospSel.value || '';
                while (hospSel.firstChild) hospSel.removeChild(hospSel.firstChild);
                if (!state) {
                    var opt = document.createElement('option');
                    opt.value = '';
                    opt.textContent = '<?php echo $select_state; ?>';
                    hospSel.appendChild(opt);
                    return;
                }
                var list = HOSPITALS_BY_STATE[state] || [];
                if (!list.length) {
                    var emptyOpt = document.createElement('option');
                    emptyOpt.value = '';
                    emptyOpt.textContent = '<?php echo $no_hospitals; ?>';
                    hospSel.appendChild(emptyOpt);
                    return;
                }
                var head = document.createElement('option');
                head.value = '';
                head.textContent = '<?php echo $select_hospital; ?>';
                hospSel.appendChild(head);
                list.forEach(function(h) {
                    var opt = document.createElement('option');
                    opt.value = String(h.id);
                    opt.textContent = h.name;
                    if (preserveId && String(h.id) === String(preserveId)) {
                        opt.selected = true;
                    }
                    hospSel.appendChild(opt);
                });
            }
            if (stateSel) {
                stateSel.addEventListener('change', function() {
                    // Once the user picks a different state we no
                    // longer want the prefill-id to override their
                    // choice, so wipe data-current-id so the next
                    // rebuild starts clean.
                    if (hospSel) hospSel.setAttribute('data-current-id', '');
                    rebuildHospitalDropdown();
                });
                // Initial sync — populate based on prefill state.
                rebuildHospitalDropdown();
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
                if (typeSelect && !typeSelect.value) {
                    setFeedback('error', '<?php echo $err_choose_type; ?>');
                    flagField('applicant_type');
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
