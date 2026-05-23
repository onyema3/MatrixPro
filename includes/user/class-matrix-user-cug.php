<?php
/**
 * CUG (Closed User Group) enrolment form
 *
 * Renders the application form shown when a member clicks the CUG
 * card on the dashboard's Benefits tab, and processes the submission
 * via an authenticated AJAX endpoint.
 *
 * Form fields (operator-defined):
 *   - First Name   (required)
 *   - Last Name    (required)
 *   - NIN          (required, 11-digit Nigerian National Identification Number)
 *   - Existing Airtel Number  (optional)
 *
 * Persisted to wp_matrix_cug_requests with a UNIQUE KEY on user_id so
 * a re-submission UPDATEs the same row instead of inserting a
 * duplicate. The default 'pending' status lets admins triage
 * applications later without complicating the initial rollout.
 *
 * Auth + plan gating mirrors the Benefits panel itself: the user must
 * be logged in AND have at least one active position (an entry in
 * matrix_positions with status='active'). This is the same check
 * used by Matrix_MLM_User_Benefits::render(), keeping a single source
 * of truth for "who is eligible to see/apply for member benefits".
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_User_CUG {

    /** AJAX action name — referenced from the JS handler in
     *  class-matrix-user-benefits.php. Kept as a constant so the two
     *  ends never drift if it's renamed. */
    const AJAX_ACTION = 'matrix_submit_cug';

    public function __construct() {
        // Authenticated-only; no nopriv counterpart on purpose — an
        // anonymous visitor has no business submitting a CUG
        // application, and rejecting them at the routing layer is
        // cheaper than running the full handler just to deny.
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajax_submit']);
    }

    /**
     * Handle the submission.
     *
     * Response shape:
     *   success: { message: string, status: 'pending', request: {...} }
     *   error:   { message: string, field?: string }
     *
     * The optional `field` on errors lets the JS highlight the
     * specific input that failed validation instead of just showing
     * the message at the top of the form.
     */
    public function ajax_submit() {
        check_ajax_referer('matrix_mlm_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error([
                'message' => __('You must be logged in to apply.', 'matrix-mlm'),
            ]);
        }

        // Active-plan gate. Re-checked server-side even though the UI
        // doesn't render the form for ineligible users — never trust
        // a button that's only hidden client-side.
        if (!self::user_is_eligible($user_id)) {
            wp_send_json_error([
                'message' => __('Subscribe to a plan to apply for the CUG benefit.', 'matrix-mlm'),
            ]);
        }

        $first_name    = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '';
        $last_name     = isset($_POST['last_name'])  ? sanitize_text_field(wp_unslash($_POST['last_name']))  : '';
        $nin_raw       = isset($_POST['nin'])        ? sanitize_text_field(wp_unslash($_POST['nin']))        : '';
        $airtel_raw    = isset($_POST['airtel_number']) ? sanitize_text_field(wp_unslash($_POST['airtel_number'])) : '';

        // First name: 2-60 visible chars after trim. mb_strlen so a
        // user who types in Yoruba/Igbo/Hausa diacritics isn't
        // rejected by a byte-count check.
        $first_name = trim($first_name);
        if (mb_strlen($first_name) < 2 || mb_strlen($first_name) > 60) {
            wp_send_json_error([
                'message' => __('First name must be between 2 and 60 characters.', 'matrix-mlm'),
                'field'   => 'first_name',
            ]);
        }

        $last_name = trim($last_name);
        if (mb_strlen($last_name) < 2 || mb_strlen($last_name) > 60) {
            wp_send_json_error([
                'message' => __('Last name must be between 2 and 60 characters.', 'matrix-mlm'),
                'field'   => 'last_name',
            ]);
        }

        // NIN: Nigerian National Identification Number is exactly 11
        // digits. Strip whitespace/dashes the user may have typed
        // before validating so "1234 5678 901" or "12345-678901" both
        // pass — but the stored value is digits-only.
        $nin = preg_replace('/\D+/', '', $nin_raw);
        if (!preg_match('/^\d{11}$/', $nin)) {
            wp_send_json_error([
                'message' => __('NIN must be 11 digits.', 'matrix-mlm'),
                'field'   => 'nin',
            ]);
        }

        // Airtel number is optional. When supplied, normalise to
        // digits-only and accept either local (10 digits without the
        // leading 0, e.g. 8021234567), national (11 digits with the
        // leading 0, e.g. 08021234567) or international (13 digits
        // with the country code, e.g. 2348021234567). We don't
        // enforce that the prefix is an Airtel range — telco prefix
        // ownership shifts via portability and we don't want a
        // legitimate ported number rejected at the form.
        $airtel = '';
        if ($airtel_raw !== '') {
            $airtel_digits = preg_replace('/\D+/', '', $airtel_raw);
            $len = strlen($airtel_digits);
            if ($len < 10 || $len > 14) {
                wp_send_json_error([
                    'message' => __('Existing Airtel number must be a valid Nigerian phone number.', 'matrix-mlm'),
                    'field'   => 'airtel_number',
                ]);
            }
            $airtel = $airtel_digits;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'matrix_cug_requests';

        // Defensive: if the table is missing (older install where
        // maybe_upgrade hasn't run yet on this request), nudge the
        // schema before the write. Mirrors the pattern used by the
        // Fintava extension tables.
        if (!self::table_exists()) {
            if (class_exists('Matrix_MLM_Database')) {
                Matrix_MLM_Database::maybe_upgrade();
            }
            if (!self::table_exists()) {
                wp_send_json_error([
                    'message' => __('CUG enrolment is not available right now. Please try again in a moment.', 'matrix-mlm'),
                ]);
            }
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM {$table} WHERE user_id = %d",
            $user_id
        ));

        // Don't let a user overwrite an already-approved enrolment by
        // resubmitting — at that point the next change is an
        // operator concern (cancel/replace) not a self-service one.
        if ($existing && $existing->status === 'approved') {
            wp_send_json_error([
                'message' => __('Your CUG enrolment is already approved. Contact support to make changes.', 'matrix-mlm'),
            ]);
        }

        $data = [
            'user_id'       => $user_id,
            'first_name'    => $first_name,
            'last_name'     => $last_name,
            'nin'           => $nin,
            'airtel_number' => $airtel !== '' ? $airtel : null,
            'status'        => 'pending',
            'updated_at'    => current_time('mysql'),
        ];
        $formats = ['%d', '%s', '%s', '%s', '%s', '%s', '%s'];

        if ($existing) {
            $result = $wpdb->update($table, $data, ['id' => $existing->id], $formats, ['%d']);
            if ($result === false) {
                error_log('Matrix CUG submit (update) failed: ' . $wpdb->last_error);
                wp_send_json_error([
                    'message' => __('Could not save your application. Please try again.', 'matrix-mlm'),
                ]);
            }
            $message = __('Your CUG application has been updated and is pending review.', 'matrix-mlm');
        } else {
            $data['created_at'] = current_time('mysql');
            $formats[] = '%s';
            $result = $wpdb->insert($table, $data, $formats);
            if ($result === false) {
                error_log('Matrix CUG submit (insert) failed: ' . $wpdb->last_error);
                wp_send_json_error([
                    'message' => __('Could not submit your application. Please try again.', 'matrix-mlm'),
                ]);
            }
            $message = __('Your CUG application has been submitted and is pending review.', 'matrix-mlm');
        }

        wp_send_json_success([
            'message' => $message,
            'status'  => 'pending',
            'request' => [
                'first_name'    => $first_name,
                'last_name'     => $last_name,
                'nin'           => $nin,
                'airtel_number' => $airtel,
            ],
        ]);
    }

    /**
     * Whether the user is eligible to apply for benefits (has at
     * least one active matrix position). Single source of truth so
     * the Benefits tab gate and the AJAX handler can't drift apart.
     */
    public static function user_is_eligible($user_id) {
        $user_id = intval($user_id);
        if ($user_id <= 0) {
            return false;
        }
        if (!class_exists('Matrix_MLM_User')) {
            return false;
        }
        $plans = Matrix_MLM_User::get_active_plans($user_id);
        return !empty($plans);
    }

    /**
     * Fetch the user's current CUG request (if any) for prefill.
     * Returns null if the table or row is absent — callers should
     * tolerate either case and render an empty form.
     */
    public static function get_user_request($user_id) {
        $user_id = intval($user_id);
        if ($user_id <= 0) {
            return null;
        }
        if (!self::table_exists()) {
            return null;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_cug_requests';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT id, first_name, last_name, nin, airtel_number, status, created_at, updated_at
               FROM {$table}
              WHERE user_id = %d",
            $user_id
        ));
    }

    /**
     * Render the CUG form modal scaffold + the JS that wires it up.
     *
     * Designed to be emitted once per page (after the benefits grid)
     * by Matrix_MLM_User_Benefits::render_grid(). The trigger button
     * on the CUG card carries data-cug-trigger="1" and the JS below
     * binds to that selector.
     *
     * Prefill order (best signal first):
     *   1. The user's existing CUG request row, if any.
     *   2. The user's WordPress profile first/last name.
     *   3. Empty.
     */
    public static function render_form_modal($user_id, $card_title = '') {
        $user_id = intval($user_id);
        if ($user_id <= 0) {
            return;
        }

        $existing = self::get_user_request($user_id);
        $user     = get_userdata($user_id);

        $first = $existing && $existing->first_name !== ''
            ? $existing->first_name
            : ($user ? (string) $user->first_name : '');
        $last  = $existing && $existing->last_name !== ''
            ? $existing->last_name
            : ($user ? (string) $user->last_name : '');
        $nin    = $existing ? (string) $existing->nin : '';
        $airtel = $existing && $existing->airtel_number !== null ? (string) $existing->airtel_number : '';
        $status = $existing ? (string) $existing->status : '';

        // The intro line gives the user one-glance context for what
        // the form is and what status their application is in if they
        // already submitted one.
        $title = $card_title !== '' ? $card_title : __('Apply for CUG', 'matrix-mlm');
        ?>
        <?php
        // hidden + inline style="display:none" + aria-hidden together
        // are deliberate belt-and-braces. The CSS rule
        // `.matrix-cug-modal { display: none; }` would normally do the
        // job, but it failed in the wild on installs where the cached
        // matrix-public.css predated the CUG release (the plugin
        // version stamp wasn't bumped, so wp_enqueue_style didn't
        // cache-bust) and the modal scaffold rendered as a giant
        // inline form on the dashboard. The HTML `hidden` attribute
        // is honoured by every supported browser and is the strongest
        // guarantee that the modal is invisible until JS opens it;
        // the inline display:none survives older browsers that ignore
        // hidden when an !important rule is fighting it; aria-hidden
        // keeps assistive tech in sync with the visual state. The JS
        // open/close handlers below toggle all three.
        ?>
        <div class="matrix-cug-modal" id="matrix-cug-modal"
             hidden
             style="display:none;"
             aria-hidden="true" role="dialog" aria-modal="true">
            <div class="matrix-cug-modal-backdrop" data-cug-modal-close></div>
            <div class="matrix-cug-modal-dialog" role="document">
                <button type="button" class="matrix-cug-modal-close" data-cug-modal-close
                        aria-label="<?php esc_attr_e('Close', 'matrix-mlm'); ?>">&times;</button>

                <h3 class="matrix-cug-modal-title"><?php echo esc_html($title); ?></h3>
                <p class="matrix-cug-modal-intro">
                    <?php _e('Fill in your details to enrol in the Closed User Group. Your application will be reviewed by an administrator.', 'matrix-mlm'); ?>
                </p>

                <?php if ($existing): ?>
                <div class="matrix-cug-status matrix-cug-status-<?php echo esc_attr($status); ?>"
                     data-cug-status="<?php echo esc_attr($status); ?>">
                    <?php
                    $status_labels = [
                        'pending'   => __('Your previous application is pending review. Resubmitting will replace it.', 'matrix-mlm'),
                        'approved'  => __('Your CUG enrolment is approved. Contact support to make changes.', 'matrix-mlm'),
                        'rejected'  => __('Your previous application was rejected. You can update and resubmit it below.', 'matrix-mlm'),
                        'cancelled' => __('Your previous application was cancelled. You can resubmit it below.', 'matrix-mlm'),
                    ];
                    echo esc_html($status_labels[$status] ?? '');
                    ?>
                </div>
                <?php endif; ?>

                <form class="matrix-cug-form" id="matrix-cug-form" novalidate>
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::AJAX_ACTION); ?>">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('matrix_mlm_nonce')); ?>">

                    <div class="matrix-form-row">
                        <div class="matrix-form-group">
                            <label for="matrix-cug-first-name"><?php _e('First Name', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                            <input type="text" id="matrix-cug-first-name" name="first_name"
                                   value="<?php echo esc_attr($first); ?>"
                                   minlength="2" maxlength="60" autocomplete="given-name" required>
                        </div>
                        <div class="matrix-form-group">
                            <label for="matrix-cug-last-name"><?php _e('Last Name', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                            <input type="text" id="matrix-cug-last-name" name="last_name"
                                   value="<?php echo esc_attr($last); ?>"
                                   minlength="2" maxlength="60" autocomplete="family-name" required>
                        </div>
                    </div>

                    <div class="matrix-form-group">
                        <label for="matrix-cug-nin"><?php _e('NIN', 'matrix-mlm'); ?> <span aria-hidden="true">*</span></label>
                        <input type="text" id="matrix-cug-nin" name="nin"
                               value="<?php echo esc_attr($nin); ?>"
                               inputmode="numeric" pattern="\d{11}" maxlength="11"
                               autocomplete="off" required>
                        <p class="matrix-form-hint"><?php _e('Your 11-digit National Identification Number.', 'matrix-mlm'); ?></p>
                    </div>

                    <div class="matrix-form-group">
                        <label for="matrix-cug-airtel"><?php _e('Existing Airtel Number', 'matrix-mlm'); ?>
                            <span class="matrix-form-optional"><?php _e('(if any)', 'matrix-mlm'); ?></span>
                        </label>
                        <input type="tel" id="matrix-cug-airtel" name="airtel_number"
                               value="<?php echo esc_attr($airtel); ?>"
                               inputmode="tel" maxlength="20" autocomplete="tel">
                        <p class="matrix-form-hint"><?php _e('Leave blank if you do not have an existing Airtel line.', 'matrix-mlm'); ?></p>
                    </div>

                    <div class="matrix-cug-feedback" role="status" aria-live="polite"></div>

                    <div class="matrix-cug-actions">
                        <button type="button" class="matrix-btn" data-cug-modal-close>
                            <?php _e('Cancel', 'matrix-mlm'); ?>
                        </button>
                        <button type="submit" class="matrix-btn matrix-btn-primary matrix-cug-submit">
                            <?php echo $existing ? esc_html__('Update Application', 'matrix-mlm') : esc_html__('Submit Application', 'matrix-mlm'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <script>
        (function() {
            var modal = document.getElementById('matrix-cug-modal');
            var form  = document.getElementById('matrix-cug-form');
            if (!modal || !form) return;

            // matrixMLM is localized for every dashboard pageload by
            // Matrix_MLM_Core::enqueue_public_assets, but we fall back
            // to the per-form hidden nonce if the global isn't there
            // for any reason (edge case: shortcode rendered outside
            // the normal enqueue context).
            var ajaxUrl = (window.matrixMLM && window.matrixMLM.ajaxUrl) || (window.ajaxurl || '');

            var triggers = document.querySelectorAll('[data-cug-trigger]');
            var submit   = form.querySelector('.matrix-cug-submit');
            var feedback = form.querySelector('.matrix-cug-feedback');

            function openModal() {
                clearFeedback();
                clearFieldErrors();
                // Remove every layer that's hiding the modal: the
                // HTML hidden attribute, the inline display:none, and
                // the aria-hidden flag. Adding the is-open class is
                // kept so existing CSS that hooks off it (animations,
                // backdrop styles) keeps working.
                modal.hidden = false;
                modal.style.display = '';
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('matrix-cug-modal-lock');
                // Focus the first empty input for one-tap typing.
                var firstEmpty = form.querySelector('input[name="first_name"], input[name="last_name"], input[name="nin"]');
                if (firstEmpty) {
                    setTimeout(function() { firstEmpty.focus(); }, 30);
                }
            }

            function closeModal() {
                modal.classList.remove('is-open');
                // Re-apply every layer of hide so the modal is gone
                // even if the stylesheet has been swapped, removed, or
                // is still cached without the .matrix-cug-modal rule.
                modal.hidden = true;
                modal.style.display = 'none';
                modal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('matrix-cug-modal-lock');
            }

            function clearFeedback() {
                if (!feedback) return;
                feedback.textContent = '';
                feedback.className = 'matrix-cug-feedback';
            }

            function setFeedback(type, msg) {
                if (!feedback) return;
                feedback.textContent = msg;
                feedback.className = 'matrix-cug-feedback matrix-cug-feedback-' + type;
            }

            function clearFieldErrors() {
                form.querySelectorAll('.matrix-form-group.has-error').forEach(function(el) {
                    el.classList.remove('has-error');
                });
            }

            function flagField(name) {
                if (!name) return;
                var input = form.querySelector('[name="' + name + '"]');
                if (input && input.parentNode) {
                    input.parentNode.classList.add('has-error');
                    input.focus();
                }
            }

            triggers.forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    openModal();
                });
            });

            modal.querySelectorAll('[data-cug-modal-close]').forEach(function(el) {
                el.addEventListener('click', closeModal);
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.classList.contains('is-open')) {
                    closeModal();
                }
            });

            form.addEventListener('submit', function(e) {
                e.preventDefault();
                if (!ajaxUrl) {
                    setFeedback('error', '<?php echo esc_js(__('Cannot reach the server. Please refresh the page and try again.', 'matrix-mlm')); ?>');
                    return;
                }

                clearFeedback();
                clearFieldErrors();
                submit.disabled = true;
                var originalLabel = submit.textContent;
                submit.textContent = '<?php echo esc_js(__('Submitting…', 'matrix-mlm')); ?>';

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
                        setFeedback('success', (payload.data && payload.data.message) || '<?php echo esc_js(__('Application submitted.', 'matrix-mlm')); ?>');
                        // After a successful submit the form's prefill
                        // is now the source of truth — disable inputs
                        // briefly so the user sees the success state
                        // and the button label reflects "update" next
                        // time the modal opens.
                        submit.textContent = '<?php echo esc_js(__('Update Application', 'matrix-mlm')); ?>';
                    } else {
                        var msg = (payload && payload.data && payload.data.message) || '<?php echo esc_js(__('Submission failed. Please check the form and try again.', 'matrix-mlm')); ?>';
                        setFeedback('error', msg);
                        if (payload && payload.data && payload.data.field) {
                            flagField(payload.data.field);
                        }
                    }
                }).catch(function() {
                    submit.disabled = false;
                    submit.textContent = originalLabel;
                    setFeedback('error', '<?php echo esc_js(__('Network error. Please try again.', 'matrix-mlm')); ?>');
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * Cheap INFORMATION_SCHEMA probe so a missing-table install
     * (older schema, repair pending) doesn't bomb on first read or
     * write. Mirrors the same pattern used by the Benefits panel.
     */
    private static function table_exists() {
        global $wpdb;
        $table = $wpdb->prefix . 'matrix_cug_requests';
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $table
        ));
        return $exists > 0;
    }
}
