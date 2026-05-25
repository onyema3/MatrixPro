<?php
/**
 * Email and SMS Notifications
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Notifications {

    /**
     * Send a "your account has been deactivated for non-payment of the
     * monthly subscription" notification to the user. Fired from
     * Matrix_MLM_Subscription::deactivate_unpaid_users() the moment the
     * status flip lands.
     *
     * Distinct from the admin-facing send_admin_notification() that has
     * always been part of that flow — admin keeps getting the
     * subscription_deactivation alert, and now the user also gets a
     * clear, actionable email so they don't discover the deactivation
     * by trying to log in and finding the dashboard refusing to render
     * with no explanation.
     *
     * The body explains the reason (unpaid subscription), the cure
     * (top up the wallet and click "Pay subscription" on the dashboard),
     * and the deep link to the dashboard's subscription page so the
     * user doesn't have to hunt for it. SMS twin under the same
     * matrix_mlm_sms_verification gate as commission_notification, so
     * the channel mix matches the rest of the plugin.
     *
     * @param int   $user_id   The user whose account just flipped to inactive.
     * @param float $amount    The monthly fee that went unpaid (already known
     *                         to the caller — passed in to avoid a second
     *                         get_option round trip and to keep this helper
     *                         honest about exactly what was billed).
     * @param string $billing_month  YYYY-MM of the missed period.
     * @return void
     */
    public static function send_subscription_deactivation_notification($user_id, $amount, $billing_month) {
        $user = get_userdata((int) $user_id);
        if (!$user) {
            return;
        }

        $currency   = get_option('matrix_mlm_currency_symbol', '₦');
        $amount_str = $currency . number_format((float) $amount, 2);
        // Period label like "November 2026" rather than the raw "2026-11"
        // so the inbox preview reads naturally.
        $period_label = $billing_month;
        $ts = strtotime($billing_month . '-01');
        if ($ts) {
            $period_label = date_i18n('F Y', $ts);
        }

        $subject = sprintf(
            /* translators: 1: site name, 2: billing period, e.g. "November 2026" */
            __('[%1$s] Your account has been deactivated — %2$s subscription unpaid', 'matrix-mlm'),
            get_bloginfo('name'),
            $period_label
        );

        $dashboard_url = home_url('/matrix-dashboard/');

        $message = self::get_email_template('subscription-deactivation', [
            'username'      => $user->user_login,
            'amount'        => $amount_str,
            'period'        => $period_label,
            'dashboard_url' => $dashboard_url,
            'site_name'     => get_bloginfo('name'),
        ]);

        self::send_email($user->user_email, $subject, $message);

        // SMS twin — same gating as send_commission_notification(): only
        // when SMS verification is on and the user has a phone on file.
        if (get_option('matrix_mlm_sms_verification')) {
            $phone = self::get_user_phone((int) $user_id);
            if ($phone) {
                self::send_sms($phone, sprintf(
                    /* translators: 1: amount, 2: billing period */
                    __('Your account was deactivated: monthly subscription of %1$s for %2$s is unpaid. Top up your wallet and pay from your dashboard to reactivate.', 'matrix-mlm'),
                    $amount_str,
                    $period_label
                ));
            }
        }

        // In-app notification (1.0.15). Surfaces in the dashboard
        // sidebar bell so a user logging in to a deactivated account
        // sees the same "you're inactive — pay now" prompt without
        // having to check their email. The link goes straight to
        // the recovery view rendered by Matrix_MLM_User_Dashboard::
        // render_inactive_account_view(), which is the only path
        // that can self-heal a subscription-driven deactivation.
        if (class_exists('Matrix_MLM_In_App_Notifications')) {
            Matrix_MLM_In_App_Notifications::enqueue(
                (int) $user_id,
                'subscription_deactivation',
                __('Account deactivated — subscription unpaid', 'matrix-mlm'),
                sprintf(
                    /* translators: 1: amount, 2: period */
                    __('Your %1$s subscription for %2$s is unpaid. Pay from your wallet to reactivate.', 'matrix-mlm'),
                    $amount_str,
                    $period_label
                ),
                '/matrix-dashboard/',
                ['amount' => (float) $amount, 'period' => $billing_month]
            );
        }
    }

    /**
     * Send verification email
     */
    public static function send_verification_email($user_id) {
        $user = get_userdata($user_id);
        if (!$user) return;

        $token = wp_generate_password(32, false);
        update_user_meta($user_id, 'matrix_email_verify_token', $token);
        update_user_meta($user_id, 'matrix_email_verify_expiry', time() + 3600);

        $verify_url = add_query_arg([
            'action' => 'matrix_verify_email',
            'token' => $token,
            'user' => $user_id
        ], home_url());

        $subject = sprintf(__('[%s] Verify Your Email Address', 'matrix-mlm'), get_bloginfo('name'));
        $message = self::get_email_template('verification', [
            'username' => $user->user_login,
            'verify_url' => $verify_url,
            'site_name' => get_bloginfo('name')
        ]);

        self::send_email($user->user_email, $subject, $message);
    }

    /**
     * Send a "level completed" notification to a member who just
     * filled an entire level of their matrix. Fired idempotently from
     * Matrix_MLM_Plan_Engine::record_level_completions() — that method
     * INSERT IGNOREs a (user_id, plan_id, level) row first and only
     * calls this on the first insert, so a member can't be emailed
     * twice for the same milestone.
     *
     * Distinct from send_commission_notification(matrix_completion):
     * matrix completion fires once when *every* level is filled and is
     * paired with a wallet credit; this fires when *each* level is
     * filled, with no wallet impact, and is purely a recognition email
     * (with optional SMS, mirroring the commission helper). The two
     * are intentionally non-overlapping: when the deepest reachable
     * level happens to also complete the matrix, the user receives
     * both messages — one per event — rather than one merged "Matrix
     * Master" email that would conflate two distinct concerns.
     *
     * @param int    $user_id   Recipient (the ancestor whose level filled).
     * @param object $plan      Row from wp_matrix_plans (uses id, name, depth).
     * @param int    $level     Relative level under the ancestor that just filled.
     * @param int    $width     Matrix width — surfaces "you now have W^L members" copy.
     */
    public static function send_level_completion_notification($user_id, $plan, $level, $width) {
        $user = get_userdata((int) $user_id);
        if (!$user || empty($plan)) {
            return;
        }

        $level     = (int) $level;
        $width     = (int) $width;
        $positions = $level > 0 && $width > 0 ? (int) pow($width, $level) : 0;
        $plan_name = isset($plan->name) ? (string) $plan->name : '';
        $depth     = isset($plan->depth) ? (int) $plan->depth : 0;

        $subject = sprintf(
            /* translators: 1: site name, 2: level number, 3: plan name */
            __('[%1$s] Level %2$d Completed in %3$s!', 'matrix-mlm'),
            get_bloginfo('name'),
            $level,
            $plan_name
        );

        $dashboard_url = home_url('/matrix-dashboard/genealogy/');

        $message = self::get_email_template('level-completion', [
            'username'      => $user->user_login,
            'plan_name'     => $plan_name,
            'level'         => $level,
            'depth'         => $depth,
            'positions'     => $positions,
            'dashboard_url' => $dashboard_url,
            'site_name'     => get_bloginfo('name'),
        ]);

        self::send_email($user->user_email, $subject, $message);

        // SMS twin — same gating as send_commission_notification(): only
        // fires when the operator has SMS verification enabled and the
        // user has a phone number on file. Keeps the channel mix
        // consistent with how commissions are announced.
        if (get_option('matrix_mlm_sms_verification')) {
            $phone = self::get_user_phone((int) $user_id);
            if ($phone) {
                $sms = sprintf(
                    /* translators: 1: level number, 2: plan name */
                    __('You completed level %1$d of your %2$s matrix! Check your dashboard for details.', 'matrix-mlm'),
                    $level,
                    $plan_name
                );
                self::send_sms($phone, $sms);
            }
        }

        // In-app notification (1.0.15). Surfaces the same milestone
        // in the bell dropdown alongside the email — durable beyond
        // the 8s level-completion toast in
        // Matrix_MLM_User_Dashboard::build_level_completion_toasts(),
        // which only catches the user once.
        if (class_exists('Matrix_MLM_In_App_Notifications')) {
            Matrix_MLM_In_App_Notifications::enqueue(
                (int) $user_id,
                'level_completion',
                sprintf(
                    /* translators: 1: level number, 2: plan name */
                    __('Level %1$d completed — %2$s', 'matrix-mlm'),
                    $level,
                    $plan_name
                ),
                $positions > 0
                    ? sprintf(
                        /* translators: 1: position count, 2: plan name */
                        __('All %1$s positions at this depth of your %2$s matrix are filled.', 'matrix-mlm'),
                        number_format($positions),
                        $plan_name
                    )
                    : sprintf(
                        /* translators: 1: level number, 2: plan name */
                        __('Level %1$d of your %2$s matrix is complete.', 'matrix-mlm'),
                        $level,
                        $plan_name
                    ),
                '/matrix-dashboard/genealogy/',
                ['level' => $level, 'plan_id' => isset($plan->id) ? (int) $plan->id : 0, 'positions' => $positions]
            );
        }
    }

    /**
     * Send commission notification
     */
    public static function send_commission_notification($user_id, $amount, $type) {
        $user = get_userdata($user_id);
        if (!$user) return;

        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $type_label = ucfirst(str_replace('_', ' ', $type));

        $subject = sprintf(__('[%s] Commission Received!', 'matrix-mlm'), get_bloginfo('name'));
        $message = self::get_email_template('commission', [
            'username' => $user->user_login,
            'amount' => $currency . number_format($amount, 2),
            'type' => $type_label,
            'site_name' => get_bloginfo('name')
        ]);

        self::send_email($user->user_email, $subject, $message);

        // Send SMS if enabled
        if (get_option('matrix_mlm_sms_verification')) {
            $phone = self::get_user_phone($user_id);
            if ($phone) {
                self::send_sms($phone, sprintf(
                    __('You received %s%s %s commission. Login to your dashboard for details.', 'matrix-mlm'),
                    $currency, number_format($amount, 2), $type_label
                ));
            }
        }

        // In-app notification (1.0.15). Surfaces the credit in the
        // bell dropdown so a member sees the unread badge bump the
        // moment a commission lands, without having to refresh into
        // the Commissions tab. Type slug differentiates referral /
        // level / matrix_completion in the JS-side icon registry.
        if (class_exists('Matrix_MLM_In_App_Notifications')) {
            $amount_str = $currency . number_format((float) $amount, 2);
            Matrix_MLM_In_App_Notifications::enqueue(
                (int) $user_id,
                'commission_' . preg_replace('/[^a-z0-9_]/', '_', strtolower((string) $type)),
                sprintf(
                    /* translators: 1: commission amount, 2: commission type label */
                    __('Commission received: %1$s (%2$s)', 'matrix-mlm'),
                    $amount_str,
                    $type_label
                ),
                sprintf(
                    /* translators: 1: amount, 2: type */
                    __('Your wallet has been credited with %1$s as a %2$s commission.', 'matrix-mlm'),
                    $amount_str,
                    $type_label
                ),
                '/matrix-dashboard/commissions/',
                ['amount' => (float) $amount, 'type' => (string) $type]
            );
        }
    }

    /**
     * Send deposit confirmation
     */
    public static function send_deposit_notification($user_id, $amount, $status) {
        $user = get_userdata($user_id);
        if (!$user) return;

        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $subject = sprintf(__('[%s] Deposit %s', 'matrix-mlm'), get_bloginfo('name'), ucfirst($status));
        $message = self::get_email_template('deposit', [
            'username' => $user->user_login,
            'amount' => $currency . number_format($amount, 2),
            'status' => ucfirst($status),
            'site_name' => get_bloginfo('name')
        ]);

        self::send_email($user->user_email, $subject, $message);

        // In-app notification (1.0.15).
        if (class_exists('Matrix_MLM_In_App_Notifications')) {
            $amount_str = $currency . number_format((float) $amount, 2);
            Matrix_MLM_In_App_Notifications::enqueue(
                (int) $user_id,
                'deposit',
                sprintf(
                    /* translators: 1: amount, 2: status */
                    __('Deposit %2$s: %1$s', 'matrix-mlm'),
                    $amount_str,
                    ucfirst((string) $status)
                ),
                sprintf(
                    /* translators: 1: amount, 2: status */
                    __('Your deposit of %1$s is %2$s.', 'matrix-mlm'),
                    $amount_str,
                    strtolower((string) $status)
                ),
                '/matrix-dashboard/deposit-history/',
                ['amount' => (float) $amount, 'status' => (string) $status]
            );
        }
    }

    /**
     * Send withdrawal notification
     */
    public static function send_withdrawal_notification($user_id, $amount, $status) {
        $user = get_userdata($user_id);
        if (!$user) return;

        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $subject = sprintf(__('[%s] Withdrawal %s', 'matrix-mlm'), get_bloginfo('name'), ucfirst($status));
        $message = self::get_email_template('withdrawal', [
            'username' => $user->user_login,
            'amount' => $currency . number_format($amount, 2),
            'status' => ucfirst($status),
            'site_name' => get_bloginfo('name')
        ]);

        self::send_email($user->user_email, $subject, $message);

        // In-app notification (1.0.15).
        if (class_exists('Matrix_MLM_In_App_Notifications')) {
            $amount_str = $currency . number_format((float) $amount, 2);
            Matrix_MLM_In_App_Notifications::enqueue(
                (int) $user_id,
                'withdrawal_' . preg_replace('/[^a-z0-9_]/', '_', strtolower((string) $status)),
                sprintf(
                    /* translators: 1: amount, 2: status */
                    __('Withdrawal %2$s: %1$s', 'matrix-mlm'),
                    $amount_str,
                    ucfirst((string) $status)
                ),
                sprintf(
                    /* translators: 1: amount, 2: status */
                    __('Your withdrawal request of %1$s has been %2$s.', 'matrix-mlm'),
                    $amount_str,
                    strtolower((string) $status)
                ),
                '/matrix-dashboard/wallet/',
                ['amount' => (float) $amount, 'status' => (string) $status]
            );
        }
    }

    /**
     * Send loan-application status notification.
     *
     * Fired by the admin loan triage UI when an operator transitions
     * a row to a user-visible decision state (approved / rejected /
     * cancelled). Pending and under_review do not call this method —
     * they're internal workflow noise from the applicant's POV.
     *
     * Subject prefix uses the formatted status ("Under Review" rather
     * than "under_review") so the inbox preview reads naturally; the
     * underscore-form is preserved in the body via the template's
     * own status-aware copy.
     */
    public static function send_loan_notification($user_id, $amount, $status) {
        $user = get_userdata($user_id);
        if (!$user) return;

        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $status_label = ucwords(str_replace('_', ' ', (string) $status));
        $subject = sprintf(__('[%s] Loan Application %s', 'matrix-mlm'), get_bloginfo('name'), $status_label);
        $message = self::get_email_template('loan', [
            'username'  => $user->user_login,
            'amount'    => $currency . number_format((float) $amount, 2),
            'status'    => $status_label,
            'site_name' => get_bloginfo('name')
        ]);

        self::send_email($user->user_email, $subject, $message);

        // In-app notification (1.0.15).
        if (class_exists('Matrix_MLM_In_App_Notifications')) {
            $amount_str = $currency . number_format((float) $amount, 2);
            Matrix_MLM_In_App_Notifications::enqueue(
                (int) $user_id,
                'loan_' . preg_replace('/[^a-z0-9_]/', '_', strtolower((string) $status)),
                sprintf(
                    /* translators: 1: status label */
                    __('Loan application %s', 'matrix-mlm'),
                    $status_label
                ),
                sprintf(
                    /* translators: 1: amount, 2: status */
                    __('Your loan application for %1$s has been %2$s.', 'matrix-mlm'),
                    $amount_str,
                    strtolower($status_label)
                ),
                '/matrix-dashboard/benefits/',
                ['amount' => (float) $amount, 'status' => (string) $status]
            );
        }
    }

    /**
     * Send healthcare-application status notification.
     *
     * Fired by the admin healthcare triage UI when an operator
     * transitions a row to a user-visible decision state (approved
     * / rejected / cancelled). Pending and under_review do not call
     * this method — they're internal workflow noise from the
     * applicant's POV.
     *
     * The optional $policy_number is quoted in the body when a row
     * is approved with a policy stamped — gives the user a single
     * piece of information they need to start using the benefit
     * without waiting for a follow-up from the HMO partner.
     */
    public static function send_healthcare_notification($user_id, $status, $policy_number = '') {
        $user = get_userdata($user_id);
        if (!$user) return;

        $status_label = ucwords(str_replace('_', ' ', (string) $status));
        $subject = sprintf(__('[%s] Healthcare Application %s', 'matrix-mlm'), get_bloginfo('name'), $status_label);
        $message = self::get_email_template('healthcare', [
            'username'      => $user->user_login,
            'status'        => $status_label,
            'policy_number' => (string) $policy_number,
            'site_name'     => get_bloginfo('name'),
        ]);

        self::send_email($user->user_email, $subject, $message);

        // In-app notification (1.0.15).
        if (class_exists('Matrix_MLM_In_App_Notifications')) {
            $body = sprintf(
                /* translators: %s: status label */
                __('Your healthcare application has been %s.', 'matrix-mlm'),
                strtolower($status_label)
            );
            if (!empty($policy_number)) {
                $body .= ' ' . sprintf(
                    /* translators: %s: policy number */
                    __('Policy number: %s.', 'matrix-mlm'),
                    (string) $policy_number
                );
            }
            Matrix_MLM_In_App_Notifications::enqueue(
                (int) $user_id,
                'healthcare_' . preg_replace('/[^a-z0-9_]/', '_', strtolower((string) $status)),
                sprintf(
                    /* translators: %s: status label */
                    __('Healthcare application %s', 'matrix-mlm'),
                    $status_label
                ),
                $body,
                '/matrix-dashboard/benefits/',
                ['status' => (string) $status, 'policy_number' => (string) $policy_number]
            );
        }
    }

    /**
     * Send admin-side notification when a CUG application is
     * submitted (or resubmitted). Fired by Matrix_MLM_User_CUG's
     * ajax_submit() right after the row is persisted, so reviewers
     * receive a full copy of the application by email and can read
     * it before logging in to triage. Does not block the user-facing
     * AJAX response — wp_mail failures are swallowed so a misconfigured
     * SMTP doesn't surface as a "submission failed" to the applicant.
     *
     * Recipients are taken from the matrix_mlm_application_notification_email
     * option (comma-separated). Empty/unset falls back to admin_email,
     * matching the placeholder shown on the Settings → Notifications tab.
     *
     * $request_row should be the wp_matrix_cug_requests row as a
     * stdClass (or array). $is_resubmission flips the subject prefix
     * so reviewers can distinguish a new application from an amendment.
     */
    public static function send_admin_cug_application_notification($request_row, $is_resubmission = false) {
        $recipients = self::get_benefit_notification_recipients('cug');
        if (empty($recipients)) return;

        $row = is_array($request_row) ? (object) $request_row : $request_row;
        if (!is_object($row)) return;

        $user = isset($row->user_id) ? get_userdata((int) $row->user_id) : null;
        $applicant_label = trim((string) ($row->first_name ?? '') . ' ' . (string) ($row->last_name ?? ''));
        if ($applicant_label === '' && $user) {
            $applicant_label = $user->display_name ?: $user->user_login;
        }
        if ($applicant_label === '') {
            $applicant_label = __('a member', 'matrix-mlm');
        }

        $tag = $is_resubmission
            ? __('Updated CUG Application', 'matrix-mlm')
            : __('New CUG Application', 'matrix-mlm');
        $subject = sprintf(
            /* translators: 1: site name, 2: tag, 3: applicant name */
            __('[%1$s] %2$s — %3$s', 'matrix-mlm'),
            get_bloginfo('name'),
            $tag,
            $applicant_label
        );

        $admin_url = admin_url('admin.php?page=matrix-mlm-cug&action=view&id=' . intval($row->id ?? 0));

        $message = self::get_email_template('cug-application-admin', [
            'site_name'       => get_bloginfo('name'),
            'tag'             => $tag,
            'is_resubmission' => (bool) $is_resubmission,
            'applicant_label' => $applicant_label,
            'user'            => $user,
            'row'             => $row,
            'admin_url'       => $admin_url,
        ]);

        self::send_email($recipients, $subject, $message);
    }

    /**
     * Send admin-side notification when a Loan application is
     * submitted (or resubmitted). Same shape as the CUG variant
     * above — the templates differ but the mechanics, recipients,
     * and gating are identical.
     */
    public static function send_admin_loan_application_notification($application_row, $is_resubmission = false) {
        $recipients = self::get_benefit_notification_recipients('loan');
        if (empty($recipients)) return;

        $row = is_array($application_row) ? (object) $application_row : $application_row;
        if (!is_object($row)) return;

        $user = isset($row->user_id) ? get_userdata((int) $row->user_id) : null;
        $applicant_label = trim((string) ($row->first_name ?? '') . ' ' . (string) ($row->last_name ?? ''));
        if ($applicant_label === '' && $user) {
            $applicant_label = $user->display_name ?: $user->user_login;
        }
        if ($applicant_label === '') {
            $applicant_label = __('a member', 'matrix-mlm');
        }

        $currency = get_option('matrix_mlm_currency_symbol', '₦');
        $amount_str = $currency . number_format((float) ($row->loan_amount ?? 0), 2);

        $tag = $is_resubmission
            ? __('Updated Loan Application', 'matrix-mlm')
            : __('New Loan Application', 'matrix-mlm');
        $subject = sprintf(
            /* translators: 1: site name, 2: tag, 3: applicant name, 4: loan amount */
            __('[%1$s] %2$s — %3$s — %4$s', 'matrix-mlm'),
            get_bloginfo('name'),
            $tag,
            $applicant_label,
            $amount_str
        );

        $admin_url = admin_url('admin.php?page=matrix-mlm-loans&action=view&id=' . intval($row->id ?? 0));

        $message = self::get_email_template('loan-application-admin', [
            'site_name'       => get_bloginfo('name'),
            'tag'             => $tag,
            'is_resubmission' => (bool) $is_resubmission,
            'applicant_label' => $applicant_label,
            'user'            => $user,
            'row'             => $row,
            'currency'        => $currency,
            'amount_str'      => $amount_str,
            'admin_url'       => $admin_url,
        ]);

        self::send_email($recipients, $subject, $message);
    }

    /**
     * Send admin-side notification when a Healthcare application is
     * submitted (or resubmitted). Same shape as the CUG/Loan
     * variants — the template differs but the mechanics, recipients,
     * and gating are identical. No amount/currency in the subject
     * because HMO premiums are tier-derived, not user-entered.
     */
    public static function send_admin_healthcare_application_notification($application_row, $is_resubmission = false) {
        $recipients = self::get_benefit_notification_recipients('healthcare');
        if (empty($recipients)) return;

        $row = is_array($application_row) ? (object) $application_row : $application_row;
        if (!is_object($row)) return;

        $user = isset($row->user_id) ? get_userdata((int) $row->user_id) : null;
        $applicant_label = trim((string) ($row->first_name ?? '') . ' ' . (string) ($row->last_name ?? ''));
        if ($applicant_label === '' && $user) {
            $applicant_label = $user->display_name ?: $user->user_login;
        }
        if ($applicant_label === '') {
            $applicant_label = __('a member', 'matrix-mlm');
        }

        $tag = $is_resubmission
            ? __('Updated Healthcare Application', 'matrix-mlm')
            : __('New Healthcare Application', 'matrix-mlm');
        $subject = sprintf(
            /* translators: 1: site name, 2: tag, 3: applicant name */
            __('[%1$s] %2$s — %3$s', 'matrix-mlm'),
            get_bloginfo('name'),
            $tag,
            $applicant_label
        );

        $admin_url = admin_url('admin.php?page=matrix-mlm-healthcare&action=view&id=' . intval($row->id ?? 0));

        $message = self::get_email_template('healthcare-application-admin', [
            'site_name'       => get_bloginfo('name'),
            'tag'             => $tag,
            'is_resubmission' => (bool) $is_resubmission,
            'applicant_label' => $applicant_label,
            'user'            => $user,
            'row'             => $row,
            'admin_url'       => $admin_url,
        ]);

        self::send_email($recipients, $subject, $message);
    }

    /**
     * Resolve the comma-separated reviewer list for a given benefit.
     * Each benefit has a dedicated email setting on the Settings →
     * Notifications tab so different reviewers can triage CUG, Loan,
     * and Healthcare applications without seeing each other's traffic.
     *
     * Resolution chain (first non-empty wins):
     *
     *   1. matrix_mlm_{benefit}_notification_email
     *      — the per-benefit override the operator configured.
     *
     *   2. matrix_mlm_application_notification_email
     *      — the legacy shared field. Pre-existing installs already
     *      have this populated; keeping it in the chain means an
     *      upgrade does not silently stop notifications for any
     *      benefit whose dedicated field is still blank. The
     *      Settings tab labels it "Shared fallback" so operators
     *      know its role.
     *
     *   3. admin_email
     *      — last-resort default so a brand-new install still
     *      delivers to *somebody* even if the operator hasn't
     *      visited the Notifications tab yet.
     *
     * Per address: split on whitespace/comma/semicolon, sanitise,
     * is_email()-validate, dedupe (case-insensitive). One typo on
     * the Settings page can't blow up wp_mail for the entire flow
     * — bad addresses are silently dropped and the rest are sent.
     *
     * Returns an array of valid email addresses (possibly empty if
     * even admin_email is malformed — caller should treat empty as
     * "skip notification" rather than fail loudly).
     *
     * $benefit_slug is one of 'cug', 'loan', 'healthcare'. An empty
     * or unknown slug skips step 1 and goes straight to the legacy
     * fallback, which preserves the behaviour of any caller that
     * has not yet been wired up to pass a slug.
     */
    private static function get_benefit_notification_recipients($benefit_slug = '') {
        $benefit_slug = strtolower(trim((string) $benefit_slug));
        $valid_slugs = ['cug', 'loan', 'healthcare'];

        $raw = '';
        if (in_array($benefit_slug, $valid_slugs, true)) {
            $raw = (string) get_option('matrix_mlm_' . $benefit_slug . '_notification_email', '');
        }
        if (trim($raw) === '') {
            $raw = (string) get_option('matrix_mlm_application_notification_email', '');
        }
        if (trim($raw) === '') {
            $raw = (string) get_option('admin_email', '');
        }
        if (trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[\s,;]+/', $raw);
        $out = [];
        foreach ((array) $parts as $part) {
            $email = sanitize_email(trim((string) $part));
            if ($email !== '' && is_email($email)) {
                $out[strtolower($email)] = $email;
            }
        }
        return array_values($out);
    }

    /**
     * Send welcome email after verification
     */
    public static function send_welcome_email($user_id) {
        $user = get_userdata($user_id);
        if (!$user) return;

        $subject = sprintf(__('[%s] Welcome! Your Account is Active', 'matrix-mlm'), get_bloginfo('name'));
        $message = self::get_email_template('welcome', [
            'username' => $user->user_login,
            'dashboard_url' => home_url('/matrix-dashboard'),
            'site_name' => get_bloginfo('name')
        ]);

        self::send_email($user->user_email, $subject, $message);
    }

    /**
     * Send password reset email
     */
    public static function send_password_reset_email($user_id, $reset_url) {
        $user = get_userdata($user_id);
        if (!$user) return;

        $subject = sprintf(__('[%s] Password Reset Request', 'matrix-mlm'), get_bloginfo('name'));
        $message = self::get_email_template('password-reset', [
            'username' => $user->user_login,
            'reset_url' => $reset_url,
            'site_name' => get_bloginfo('name')
        ]);

        self::send_email($user->user_email, $subject, $message);
    }

    /**
     * Send password changed confirmation
     */
    public static function send_password_changed_email($user_id) {
        $user = get_userdata($user_id);
        if (!$user) return;

        $subject = sprintf(__('[%s] Your Password Has Been Changed', 'matrix-mlm'), get_bloginfo('name'));
        $message = self::get_email_template('password-changed', [
            'username' => $user->user_login,
            'site_name' => get_bloginfo('name')
        ]);

        self::send_email($user->user_email, $subject, $message);

        // In-app notification (1.0.15). Security event — surface
        // it prominently so a user who didn't initiate the change
        // sees the alert on their next visit and can react quickly.
        if (class_exists('Matrix_MLM_In_App_Notifications')) {
            Matrix_MLM_In_App_Notifications::enqueue(
                (int) $user_id,
                'password_changed',
                __('Password changed', 'matrix-mlm'),
                __('Your account password was just changed. If this was not you, contact support immediately and reset your password.', 'matrix-mlm'),
                '/matrix-dashboard/security/'
            );
        }
    }

    /**
     * Hook into WordPress password reset flow
     * Call this from plugin init to register filters
     */
    public static function register_password_reset_hooks() {
        // Override WordPress default password reset email
        add_filter('retrieve_password_message', [__CLASS__, 'custom_reset_password_message'], 10, 4);
        add_filter('retrieve_password_title', [__CLASS__, 'custom_reset_password_title'], 10, 3);

        // Send confirmation when password is actually changed
        add_action('after_password_reset', [__CLASS__, 'on_password_reset'], 10, 2);
    }

    /**
     * Customize password reset email subject
     */
    public static function custom_reset_password_title($title, $user_login, $user_data) {
        return sprintf(__('[%s] Password Reset Request', 'matrix-mlm'), get_bloginfo('name'));
    }

    /**
     * Customize password reset email body (return HTML)
     */
    public static function custom_reset_password_message($message, $key, $user_login, $user_data) {
        $reset_url = network_site_url("wp-login.php?action=rp&key={$key}&login=" . rawurlencode($user_login), 'login');

        // Set content type to HTML for this email
        add_filter('wp_mail_content_type', [__CLASS__, 'set_html_content_type']);

        $html_message = self::get_email_template('password-reset', [
            'username' => $user_login,
            'reset_url' => $reset_url,
            'site_name' => get_bloginfo('name')
        ]);

        // Remove the filter after use to avoid affecting other emails
        add_action('wp_mail_succeeded', function() {
            remove_filter('wp_mail_content_type', [Matrix_MLM_Notifications::class, 'set_html_content_type']);
        });
        add_action('wp_mail_failed', function() {
            remove_filter('wp_mail_content_type', [Matrix_MLM_Notifications::class, 'set_html_content_type']);
        });

        return $html_message;
    }

    /**
     * Set HTML content type for wp_mail
     */
    public static function set_html_content_type() {
        return 'text/html';
    }

    /**
     * Send confirmation after password is reset
     */
    public static function on_password_reset($user, $new_pass) {
        self::send_password_changed_email($user->ID);
    }

    /**
     * Send transfer received notification
     */
    public static function send_transfer_notification($recipient_id, $sender_id, $amount) {
        $recipient = get_userdata($recipient_id);
        $sender = get_userdata($sender_id);
        if (!$recipient || !$sender) return;

        $currency = get_option('matrix_mlm_currency_symbol', '₦');

        $subject = sprintf(__('[%s] You Received a Transfer!', 'matrix-mlm'), get_bloginfo('name'));
        $message = self::get_email_template('transfer', [
            'username' => $recipient->user_login,
            'sender' => $sender->user_login,
            'amount' => $currency . number_format($amount, 2),
            'site_name' => get_bloginfo('name')
        ]);

        self::send_email($recipient->user_email, $subject, $message);

        // Send SMS if enabled
        if (get_option('matrix_mlm_sms_verification')) {
            $phone = self::get_user_phone($recipient_id);
            if ($phone) {
                self::send_sms($phone, sprintf(
                    __('You received %s%s from %s. Log in to view your balance.', 'matrix-mlm'),
                    $currency, number_format($amount, 2), $sender->user_login
                ));
            }
        }

        // In-app notification (1.0.15) for the recipient. The
        // sender gets their own confirmation toast at the action
        // site (Matrix_MLM_Core::process_transfer / wallet AJAX
        // handler), so we don't duplicate that here — this method
        // is the recipient-only path.
        if (class_exists('Matrix_MLM_In_App_Notifications')) {
            $amount_str = $currency . number_format((float) $amount, 2);
            Matrix_MLM_In_App_Notifications::enqueue(
                (int) $recipient_id,
                'transfer_received',
                sprintf(
                    /* translators: 1: amount, 2: sender username */
                    __('Received %1$s from %2$s', 'matrix-mlm'),
                    $amount_str,
                    $sender->user_login
                ),
                sprintf(
                    /* translators: 1: amount, 2: sender */
                    __('Your wallet was credited with %1$s from %2$s.', 'matrix-mlm'),
                    $amount_str,
                    $sender->user_login
                ),
                '/matrix-dashboard/wallet/',
                ['amount' => (float) $amount, 'sender_id' => (int) $sender_id, 'sender_login' => (string) $sender->user_login]
            );
        }
    }

    /**
     * Send a "we refunded your bill purchase" notification to the
     * user. Fired by Matrix_MLM_Admin_Billing_History::ajax_refund()
     * after the wallet credit and the audit row both land — at
     * which point the refund is final from the user's POV and they
     * deserve a clear, in-channel record of what was returned and
     * why.
     *
     * Email-only by default; SMS twin sent when the operator has
     * SMS verification enabled and the user has a phone on file,
     * matching the existing send_commission_notification gating.
     * No dedicated email template — uses the lightweight inline
     * HTML body (matches send_deposit_notification on installs that
     * don't have a deposit.php template either, defending against
     * a missing template silently dropping the notice).
     *
     * Wallet-only: this notification does NOT claim Fintava
     * reversed the underlying telco purchase. The body says "your
     * Matrix wallet has been credited" because that is what
     * actually happened — Fintava's billing endpoints do not
     * expose a reversal API, so the platform absorbs the loss or
     * recovers off-platform.
     *
     * @param int    $user_id        Recipient.
     * @param string $type           One of airtime|data|cable|electricity (used in subject + body).
     * @param float  $amount         Refund amount (in major units, e.g. naira).
     * @param string $reason         Operator-entered reason. Surfaced verbatim
     *                               to the user so the message is honest about
     *                               why the refund happened.
     * @param int    $transaction_id The matrix_billing_transactions row id; surfaced
     *                               in the body so a user opening a support
     *                               ticket can quote it back.
     * @return void
     */
    public static function send_billing_refund_notification($user_id, $type, $amount, $reason, $transaction_id) {
        $user = get_userdata((int) $user_id);
        if (!$user) return;

        $currency  = get_option('matrix_mlm_currency_symbol', '₦');
        $amount_str = $currency . number_format((float) $amount, 2);
        $type_label = ucfirst(strtolower((string) $type));

        $subject = sprintf(
            /* translators: 1: site name, 2: bill category, e.g. "Airtime" */
            __('[%1$s] %2$s Bill Payment Refunded', 'matrix-mlm'),
            get_bloginfo('name'),
            $type_label
        );

        $dashboard_url = home_url('/matrix-dashboard/?tab=billing');

        // Inline HTML body. We don't ship a refund-specific email
        // template; building one would mean a new file in
        // public/templates/emails/ for a single notification, and
        // the deposit / withdrawal helpers above use the templated
        // path because those landed earlier — newer surfaces
        // (level-completion etc.) build the body inline. Same
        // approach here.
        $body  = '<p>' . sprintf(
            /* translators: 1: username */
            esc_html__('Hi %1$s,', 'matrix-mlm'),
            esc_html($user->user_login)
        ) . '</p>';
        $body .= '<p>' . sprintf(
            /* translators: 1: amount string, 2: bill type, 3: transaction id */
            esc_html__('Your Matrix wallet has been credited with %1$s as a refund for %2$s purchase #%3$d.', 'matrix-mlm'),
            esc_html($amount_str),
            esc_html($type_label),
            (int) $transaction_id
        ) . '</p>';
        $body .= '<p><strong>' . esc_html__('Reason:', 'matrix-mlm') . '</strong> ' . esc_html((string) $reason) . '</p>';
        $body .= '<p>' . esc_html__('You can review the refund on your dashboard:', 'matrix-mlm') . ' <a href="' . esc_url($dashboard_url) . '">' . esc_html($dashboard_url) . '</a></p>';
        $body .= '<p>' . esc_html__('If you believe this refund was issued in error, reply to this email or open a support ticket.', 'matrix-mlm') . '</p>';
        $body .= '<p>— ' . esc_html(get_bloginfo('name')) . '</p>';

        self::send_email($user->user_email, $subject, $body);

        // SMS twin — same gating as send_commission_notification.
        if (get_option('matrix_mlm_sms_verification')) {
            $phone = self::get_user_phone((int) $user_id);
            if ($phone) {
                self::send_sms($phone, sprintf(
                    /* translators: 1: amount string, 2: bill type */
                    __('Your Matrix wallet was credited with %1$s as a refund for your %2$s purchase. Log in to your dashboard for details.', 'matrix-mlm'),
                    $amount_str,
                    strtolower($type_label)
                ));
            }
        }

        // In-app notification (1.0.15).
        if (class_exists('Matrix_MLM_In_App_Notifications')) {
            Matrix_MLM_In_App_Notifications::enqueue(
                (int) $user_id,
                'bill_refund',
                sprintf(
                    /* translators: 1: amount, 2: bill type */
                    __('%2$s refund: %1$s', 'matrix-mlm'),
                    $amount_str,
                    $type_label
                ),
                sprintf(
                    /* translators: 1: amount, 2: type, 3: transaction id */
                    __('Your wallet was credited with %1$s as a refund for %2$s purchase #%3$d.', 'matrix-mlm'),
                    $amount_str,
                    $type_label,
                    (int) $transaction_id
                ),
                '/matrix-dashboard/billing/',
                ['amount' => (float) $amount, 'type' => (string) $type, 'transaction_id' => (int) $transaction_id, 'reason' => (string) $reason]
            );
        }
    }

    /**
     * Send admin notification
     */
    public static function send_admin_notification($type, $message) {
        $admin_email = get_option('admin_email');
        $subject = sprintf(__('[%s] Admin Alert: %s', 'matrix-mlm'), get_bloginfo('name'), ucfirst(str_replace('_', ' ', $type)));

        self::send_email($admin_email, $subject, $message);
    }

    /**
     * Send email using WordPress mail
     */
    private static function send_email($to, $subject, $message) {
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        ];

        wp_mail($to, $subject, $message, $headers);
    }

    /**
     * Send SMS (supports multiple providers)
     */
    public static function send_sms($phone, $message) {
        $sms_provider = get_option('matrix_mlm_sms_provider', 'twilio');
        $sms_api_key = get_option('matrix_mlm_sms_api_key', '');
        $sms_api_secret = get_option('matrix_mlm_sms_api_secret', '');
        $sms_sender_id = get_option('matrix_mlm_sms_sender_id', '');

        if (empty($sms_api_key)) {
            return false;
        }

        switch ($sms_provider) {
            case 'twilio':
                return self::send_twilio_sms($phone, $message, $sms_api_key, $sms_api_secret, $sms_sender_id);
            case 'nexmo':
                return self::send_nexmo_sms($phone, $message, $sms_api_key, $sms_api_secret, $sms_sender_id);
            case 'termii':
                return self::send_termii_sms($phone, $message, $sms_api_key, $sms_sender_id);
            case 'bulksmsnigeria':
                return self::send_bulksmsnigeria_sms($phone, $message, $sms_api_key, $sms_sender_id);
            case 'kudisms':
                return self::send_kudisms_sms($phone, $message, $sms_api_key, $sms_sender_id);
            default:
                return false;
        }
    }

    private static function send_twilio_sms($phone, $message, $sid, $token, $from) {
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
        $response = wp_remote_post($url, [
            'headers' => ['Authorization' => 'Basic ' . base64_encode("{$sid}:{$token}")],
            'body' => ['From' => $from, 'To' => $phone, 'Body' => $message]
        ]);
        return !is_wp_error($response);
    }

    private static function send_nexmo_sms($phone, $message, $api_key, $api_secret, $from) {
        $response = wp_remote_post('https://rest.nexmo.com/sms/json', [
            'body' => [
                'api_key' => $api_key,
                'api_secret' => $api_secret,
                'from' => $from,
                'to' => $phone,
                'text' => $message
            ]
        ]);
        return !is_wp_error($response);
    }

    private static function send_termii_sms($phone, $message, $api_key, $sender_id) {
        $response = wp_remote_post('https://api.ng.termii.com/api/sms/send', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'api_key' => $api_key,
                'to' => $phone,
                'from' => $sender_id,
                'sms' => $message,
                'type' => 'plain',
                'channel' => 'generic'
            ])
        ]);
        return !is_wp_error($response);
    }

    /**
     * BulkSMS Nigeria — REST API v2.
     *
     * Endpoint: POST https://www.bulksmsnigeria.com/api/v2/sms
     * Auth:     Bearer token in the Authorization header. The dashboard
     *           API token is what the operator pastes into the
     *           "API Key / SID" field on the SMS settings tab; "API
     *           Secret" is unused for this provider.
     * Body:     JSON with from / to / body keys.
     *
     * Sender ID must be pre-registered on the BulkSMS Nigeria dashboard
     * before a send will succeed; the API rejects unregistered IDs at
     * submit time. We surface that as a generic boolean here for parity
     * with the other providers in this class — the deeper diagnostic
     * path is the operator inspecting their BulkSMS Nigeria dashboard
     * (delivery reports + usage logs are visible there).
     *
     * The response is success-shaped (HTTP 200 with a JSON body that
     * has a `data` envelope on success) but for consistency with the
     * existing Twilio / Nexmo / Termii helpers we just gate on
     * !is_wp_error(); a structured response check would tighten the
     * error model uniformly across all providers, which is out of
     * scope for adding the provider itself.
     */
    private static function send_bulksmsnigeria_sms($phone, $message, $api_token, $sender_id) {
        $response = wp_remote_post('https://www.bulksmsnigeria.com/api/v2/sms', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body' => wp_json_encode([
                'from' => $sender_id,
                'to'   => $phone,
                'body' => $message,
            ])
        ]);
        return !is_wp_error($response);
    }

    /**
     * KudiSMS — REST API.
     *
     * Endpoint: POST https://my.kudisms.net/api/sms
     * Auth:     dashboard token passed as the `token` form field. The
     *           operator pastes the token into the "API Key / SID"
     *           field on the SMS settings tab; "API Secret" is unused
     *           for this provider.
     * Body:     application/x-www-form-urlencoded with token / senderID
     *           / message / recipient. Multiple recipients in one call
     *           are comma-separated; we only ever send to one recipient
     *           from the notification helper, so no comma handling is
     *           needed here.
     *
     * Sender ID must be pre-registered on the KudiSMS dashboard. The
     * `gateway` parameter (corporate vs generic routing) is omitted
     * here so KudiSMS uses the account's default route — operators
     * who need a specific gateway can configure it on the dashboard
     * side.
     *
     * Same is_wp_error() boolean shape as the other providers; the
     * KudiSMS response carries a JSON `status` field (success/error)
     * and an `error_code` that a tighter implementation could parse,
     * but that's out of scope for adding the provider itself.
     */
    private static function send_kudisms_sms($phone, $message, $token, $sender_id) {
        $response = wp_remote_post('https://my.kudisms.net/api/sms', [
            'body' => [
                'token'     => $token,
                'senderID'  => $sender_id,
                'recipient' => $phone,
                'message'   => $message,
            ]
        ]);
        return !is_wp_error($response);
    }

    /**
     * Get user phone
     */
    private static function get_user_phone($user_id) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT phone FROM {$wpdb->prefix}matrix_user_meta WHERE user_id = %d",
            $user_id
        ));
    }

    /**
     * Get email template
     */
    private static function get_email_template($template, $vars) {
        $template_file = MATRIX_MLM_PLUGIN_DIR . "public/templates/emails/{$template}.php";

        if (file_exists($template_file)) {
            ob_start();
            extract($vars);
            include $template_file;
            return ob_get_clean();
        }

        // Fallback simple template
        $site_name = $vars['site_name'] ?? get_bloginfo('name');
        $content = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">';
        $content .= '<div style="background: linear-gradient(135deg, #4f46e5, #7c3aed); padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">';
        $content .= '<h1 style="color: #fff; margin: 0;">' . esc_html($site_name) . '</h1></div>';
        $content .= '<div style="background: #fff; padding: 30px; border: 1px solid #e5e7eb; border-radius: 0 0 8px 8px;">';

        switch ($template) {
            case 'verification':
                $content .= '<p>' . sprintf(__('Hello %s,', 'matrix-mlm'), $vars['username']) . '</p>';
                $content .= '<p>' . __('Please verify your email address by clicking the button below:', 'matrix-mlm') . '</p>';
                $content .= '<p style="text-align: center;"><a href="' . esc_url($vars['verify_url']) . '" style="background: #4f46e5; color: #fff; padding: 12px 30px; border-radius: 6px; text-decoration: none; display: inline-block;">' . __('Verify Email', 'matrix-mlm') . '</a></p>';
                break;
            case 'commission':
                $content .= '<p>' . sprintf(__('Hello %s,', 'matrix-mlm'), $vars['username']) . '</p>';
                $content .= '<p>' . sprintf(__('You have received a %s commission of %s.', 'matrix-mlm'), $vars['type'], $vars['amount']) . '</p>';
                break;
            case 'level-completion':
                // Inline fallback for installs without a dedicated
                // public/templates/emails/level-completion.php on disk.
                // No "Matrix Master" branch — the engine's existing
                // matrix_completion bonus email is the legitimate
                // "you finished the whole matrix" message; this
                // template stays focused on a single level.
                $content .= '<p>' . sprintf(__('Hello %s,', 'matrix-mlm'), $vars['username']) . '</p>';
                $content .= '<p>' . sprintf(
                    /* translators: 1: level number, 2: plan name, 3: number of positions */
                    __('You just completed <strong>Level %1$d</strong> of your <strong>%2$s</strong> matrix — every one of the %3$s positions at this depth in your downline is now filled.', 'matrix-mlm'),
                    intval($vars['level'] ?? 0),
                    esc_html($vars['plan_name'] ?? ''),
                    number_format(intval($vars['positions'] ?? 0))
                ) . '</p>';
                if (!empty($vars['dashboard_url'])) {
                    $content .= '<p style="text-align: center; margin-top: 20px;"><a href="' . esc_url($vars['dashboard_url']) . '" style="background: #4f46e5; color: #fff; padding: 12px 30px; border-radius: 6px; text-decoration: none; display: inline-block;">' . __('View Your Genealogy', 'matrix-mlm') . '</a></p>';
                }
                break;
            case 'subscription-deactivation':
                // Inline fallback for installs without a dedicated
                // public/templates/emails/subscription-deactivation.php
                // on disk. Kept short and actionable — the user needs
                // to know (a) they're locked out, (b) why, and (c)
                // exactly what to do to fix it.
                $content .= '<p>' . sprintf(__('Hello %s,', 'matrix-mlm'), $vars['username']) . '</p>';
                $content .= '<p>' . sprintf(
                    /* translators: 1: amount, 2: billing period */
                    __('Your account has been deactivated because the monthly subscription of <strong>%1$s</strong> for <strong>%2$s</strong> is unpaid.', 'matrix-mlm'),
                    esc_html($vars['amount'] ?? ''),
                    esc_html($vars['period'] ?? '')
                ) . '</p>';
                $content .= '<p>' . __('To reactivate your account, top up your Matrix wallet to cover the amount above and click <strong>Pay Subscription</strong> on your dashboard. Once the payment lands, your account is restored immediately.', 'matrix-mlm') . '</p>';
                if (!empty($vars['dashboard_url'])) {
                    $content .= '<p style="text-align: center; margin-top: 20px;"><a href="' . esc_url($vars['dashboard_url']) . '" style="background: #dc2626; color: #fff; padding: 12px 30px; border-radius: 6px; text-decoration: none; display: inline-block;">' . __('Go to Dashboard', 'matrix-mlm') . '</a></p>';
                }
                $content .= '<p style="font-size: 12px; color: #6b7280; margin-top: 20px;">' . __('If you believe this is a mistake or you have already paid, please contact support.', 'matrix-mlm') . '</p>';
                break;
            case 'deposit':
                $content .= '<p>' . sprintf(__('Hello %s,', 'matrix-mlm'), $vars['username']) . '</p>';
                $content .= '<p>' . sprintf(__('Your deposit of %s has been %s.', 'matrix-mlm'), $vars['amount'], strtolower($vars['status'])) . '</p>';
                break;
            case 'withdrawal':
                $content .= '<p>' . sprintf(__('Hello %s,', 'matrix-mlm'), $vars['username']) . '</p>';
                $content .= '<p>' . sprintf(__('Your withdrawal of %s has been %s.', 'matrix-mlm'), $vars['amount'], strtolower($vars['status'])) . '</p>';
                break;
            case 'loan':
                // Inline fallback so installs without the dedicated
                // public/templates/emails/loan.php still send a sensible
                // email body when the admin triages a row.
                $content .= '<p>' . sprintf(__('Hello %s,', 'matrix-mlm'), $vars['username']) . '</p>';
                $content .= '<p>' . sprintf(__('Your loan application for %s has been %s.', 'matrix-mlm'), $vars['amount'], strtolower($vars['status'])) . '</p>';
                break;
            case 'healthcare':
                // Inline fallback for installs without a dedicated
                // public/templates/emails/healthcare.php on disk. No
                // amount/currency line — HMO premiums are tier-derived
                // and quoted by the partner HMO, not by the dashboard.
                $content .= '<p>' . sprintf(__('Hello %s,', 'matrix-mlm'), $vars['username']) . '</p>';
                $content .= '<p>' . sprintf(__('Your healthcare application has been %s.', 'matrix-mlm'), strtolower($vars['status'])) . '</p>';
                if (!empty($vars['policy_number'])) {
                    $content .= '<p>' . sprintf(__('Policy number: %s', 'matrix-mlm'), '<code>' . esc_html($vars['policy_number']) . '</code>') . '</p>';
                }
                break;
            case 'transfer':
                $content .= '<p>' . sprintf(__('Hello %s,', 'matrix-mlm'), $vars['username']) . '</p>';
                $content .= '<p>' . sprintf(__('You received a transfer of %s from %s.', 'matrix-mlm'), $vars['amount'], $vars['sender']) . '</p>';
                $content .= '<p>' . __('The funds have been credited to your Matrix wallet.', 'matrix-mlm') . '</p>';
                break;
            case 'welcome':
                $content .= '<p>' . sprintf(__('Hello %s,', 'matrix-mlm'), $vars['username']) . '</p>';
                $content .= '<p>' . sprintf(__('Welcome to %s! Your email has been verified and your account is now active.', 'matrix-mlm'), $vars['site_name']) . '</p>';
                $content .= '<p style="text-align: center;"><a href="' . esc_url($vars['dashboard_url']) . '" style="background: #4f46e5; color: #fff; padding: 12px 30px; border-radius: 6px; text-decoration: none; display: inline-block;">' . __('Go to Dashboard', 'matrix-mlm') . '</a></p>';
                break;
            case 'password-reset':
                $content .= '<p>' . sprintf(__('Hello %s,', 'matrix-mlm'), $vars['username']) . '</p>';
                $content .= '<p>' . __('We received a request to reset your password. Click the button below:', 'matrix-mlm') . '</p>';
                $content .= '<p style="text-align: center;"><a href="' . esc_url($vars['reset_url']) . '" style="background: #dc2626; color: #fff; padding: 12px 30px; border-radius: 6px; text-decoration: none; display: inline-block;">' . __('Reset Password', 'matrix-mlm') . '</a></p>';
                $content .= '<p style="font-size: 12px; color: #6b7280;">' . __('This link expires in 1 hour. If you did not request this, ignore this email.', 'matrix-mlm') . '</p>';
                break;
            case 'password-changed':
                $content .= '<p>' . sprintf(__('Hello %s,', 'matrix-mlm'), $vars['username']) . '</p>';
                $content .= '<p>' . __('Your password has been successfully changed. You can now log in with your new password.', 'matrix-mlm') . '</p>';
                $content .= '<p style="font-size: 12px; color: #dc2626;">' . __('If you did not make this change, please reset your password immediately.', 'matrix-mlm') . '</p>';
                break;
        }

        $content .= '</div></div>';
        return $content;
    }
}
