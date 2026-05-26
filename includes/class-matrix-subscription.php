<?php
/**
 * Monthly Subscription Management
 *
 * Drives the recurring per-user fee that keeps an account in
 * status='active'. Three independent surfaces touch this class:
 *
 *   - Daily WP-Cron job (matrix_mlm_monthly_subscription) → calls
 *     process_monthly_subscriptions(), which fans out to the
 *     reminder, charge, and deactivation passes.
 *   - User dashboard recovery view → calls manual_pay() when a
 *     lapsed user clicks "Pay Subscription".
 *   - User dashboard sidebar badge / admin user list → calls
 *     get_user_subscription_status() for the next-billing-date,
 *     amount-due, days-until-billing summary.
 *
 * 2.0.3 reshape: per-user billing anniversaries replaced the
 * single-shared billing-day model. Every user carries their own
 * matrix_user_meta.next_billing_date which advances by exactly
 * one month on every successful charge. Why:
 *
 *   - Operationally simpler reasoning per row: "is this user
 *     overdue?" is just "next_billing_date <= today" instead of
 *     "today is the configured billing day AND no paid row yet".
 *   - Joining members are billed at consistent intervals from
 *     their own signup (was: day-of-month-collision could give
 *     a 28th-of-month signup almost-no free period and a 2nd-of-
 *     month signup nearly a full month free).
 *   - Catch-up payments after a lapse still pay for the missed
 *     period (billing_month = next_billing_date's YYYY-MM), so
 *     the matrix_subscriptions ledger keeps the same one-row-
 *     per-period invariant against the (user_id, billing_month)
 *     unique key.
 *
 * Migration of existing installs: class-matrix-database.php's
 * lazy ADD COLUMN block stamps every legacy user with a
 * next_billing_date computed from the legacy
 * matrix_mlm_subscription_billing_day option exactly once, so
 * no user is silently re-billed by the new logic.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Subscription {

    /**
     * Default lookahead for the pre-billing reminder when the
     * matrix_mlm_subscription_reminder_days option is unset.
     * 3 days mirrors the default grace-period window so a user
     * who ignores the reminder has the same short tail before
     * deactivation that the legacy single-shared-billing-day
     * model had.
     */
    const DEFAULT_REMINDER_DAYS = 3;

    public function __construct() {
        // Register cron hook
        add_action('matrix_mlm_monthly_subscription', [$this, 'process_monthly_subscriptions']);

        // Schedule daily cron if enabled and not scheduled. The hook
        // name still reads "monthly" for backward compatibility with
        // any operator who scripted around the WP-Cron event name; the
        // job runs daily and the per-user next_billing_date column
        // gates which users actually get touched on any given run.
        if (get_option('matrix_mlm_subscription_enabled', 0) && !wp_next_scheduled('matrix_mlm_monthly_subscription')) {
            wp_schedule_event(time(), 'daily', 'matrix_mlm_monthly_subscription');
        }
    }

    /**
     * Resolve the configured reminder lookahead (days before
     * next_billing_date that a low-balance reminder should fire).
     * Clamped to [1, 15] so a misconfigured option can't either
     * disable reminders entirely (0 = same-day surprise) or spam
     * a user every day for half a month.
     */
    public static function reminder_days() {
        $days = (int) get_option('matrix_mlm_subscription_reminder_days', self::DEFAULT_REMINDER_DAYS);
        if ($days < 1) {
            $days = 1;
        }
        if ($days > 15) {
            $days = 15;
        }
        return $days;
    }

    /**
     * Daily cron entry point. Fans out to three independent
     * passes in order: reminder, charge, and grace-deadline
     * deactivation. Each pass is its own SQL query so the
     * three concerns never share state and a slow pass can't
     * starve the others — important on installs with five-figure
     * member counts where the charge sweep can take a few seconds
     * even with the per-user advisory lock.
     */
    public function process_monthly_subscriptions() {
        if (!get_option('matrix_mlm_subscription_enabled', 0)) {
            return;
        }

        $amount = floatval(get_option('matrix_mlm_subscription_amount', 0));
        if ($amount <= 0) {
            return;
        }

        $grace_days    = max(0, (int) get_option('matrix_mlm_subscription_grace_days', 3));
        $reminder_days = self::reminder_days();
        $today         = current_time('Y-m-d');

        // Pass 1: pre-billing reminder for users whose wallet
        // balance is short of the amount and whose next_billing_date
        // falls inside the reminder window. One reminder per user
        // per period — tracked via wp_user_meta so a user who
        // tops up after the reminder doesn't get re-nudged.
        $this->send_pending_reminders($amount, $reminder_days, $today);

        // Pass 2: charge users whose next_billing_date is today
        // or earlier and who have funds. Per-user advisory lock
        // serialises against manual_pay() to prevent a parallel
        // dashboard click from double-debiting the wallet.
        $this->charge_due_users($amount, $today);

        // Pass 3: deactivate users whose next_billing_date plus
        // grace_days has passed and whose wallet still doesn't
        // cover the amount. Same advisory lock to serialise
        // against a last-minute manual_pay() racing the deadline
        // sweep.
        $this->deactivate_overdue_users($amount, $grace_days, $today);
    }

    /**
     * Pass 1 — pre-billing reminder.
     *
     * Sends one reminder per user per upcoming billing period when
     * the wallet balance is short of the configured amount and the
     * billing date is within the reminder lookahead window. The
     * user-meta key matrix_subscription_reminder_sent_for stores
     * the YYYY-MM-DD of the next_billing_date that was reminded
     * for, so:
     *
     *   - A user reminded at T-3 days does not get re-reminded at
     *     T-2 / T-1 / T-0 days for the same period.
     *   - After a successful charge or a manual_pay(), the new
     *     next_billing_date is one month later, so the meta key
     *     no longer matches and the next period's reminder is
     *     free to fire.
     *
     * Users whose wallet balance is already >= amount are not
     * reminded at all — there's nothing actionable for them to do
     * and a "you're about to be billed" email becomes inbox noise.
     */
    private function send_pending_reminders($amount, $reminder_days, $today) {
        global $wpdb;

        // Window: tomorrow through next_billing_date == today + reminder_days.
        // Excluding today because today's billing happens in the
        // same cron run (charge_due_users), so a "you're about to be
        // billed" email arriving after the actual debit would be
        // confusing.
        $start = date('Y-m-d', strtotime($today . ' +1 day'));
        $end   = date('Y-m-d', strtotime($today . ' +' . max(1, (int) $reminder_days) . ' day'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, next_billing_date, balance
               FROM {$wpdb->prefix}matrix_user_meta
              WHERE status = 'active'
                AND next_billing_date IS NOT NULL
                AND next_billing_date BETWEEN %s AND %s
                AND balance < %f",
            $start, $end, $amount
        ));

        if (empty($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $user_id          = (int) $row->user_id;
            $next_billing     = (string) $row->next_billing_date;
            $current_balance  = (float) $row->balance;

            $sent_for = (string) get_user_meta($user_id, 'matrix_subscription_reminder_sent_for', true);
            if ($sent_for === $next_billing) {
                // Already reminded for this period.
                continue;
            }

            Matrix_MLM_Notifications::send_subscription_reminder_notification(
                $user_id,
                (float) $amount,
                $next_billing,
                $current_balance
            );

            // Stamp the period that was reminded for, not the
            // calendar date — the gate is "is this user already
            // reminded for THIS upcoming period?" not "have they
            // ever been reminded?".
            update_user_meta($user_id, 'matrix_subscription_reminder_sent_for', $next_billing);
        }
    }

    /**
     * Pass 2 — charge every user whose next_billing_date has
     * arrived and who has funds.
     *
     * Per-user serialisation: each charge attempt acquires a MySQL
     * advisory lock keyed on user_id + period, held for the full
     * debit + record_payment + advance-date sequence. The lock
     * defends against:
     *
     *   - A manual_pay() request firing concurrently with the cron
     *     sweep — without serialisation, both could pass the
     *     has_paid_for_period guard, both could debit the wallet,
     *     and the second record_payment INSERT would collide on
     *     the (user_id, billing_month) UNIQUE index leaving the
     *     wallet double-debited.
     *
     *   - Two cron ticks racing when WP-Cron and a real system
     *     cron are both wired (operators sometimes do both for
     *     reliability on low-traffic sites).
     *
     * Users picked up here whose wallet is short are recorded
     * as 'unpaid' for the period and left for the deactivation
     * sweep to retry — the next_billing_date is NOT advanced
     * until the period is actually paid, so the same user
     * remains in this query's result set every day until their
     * balance recovers or grace_days expires.
     */
    private function charge_due_users($amount, $today) {
        global $wpdb;

        $due_users = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, next_billing_date
               FROM {$wpdb->prefix}matrix_user_meta
              WHERE status = 'active'
                AND next_billing_date IS NOT NULL
                AND next_billing_date <= %s",
            $today
        ));

        if (empty($due_users)) {
            return;
        }

        $wallet = new Matrix_MLM_Wallet();

        foreach ($due_users as $user) {
            $user_id      = (int) $user->user_id;
            $next_billing = (string) $user->next_billing_date;
            $period       = self::period_from_date($next_billing); // YYYY-MM

            $lock_name = self::lock_name($user_id, $period);
            $got_lock  = (int) $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 2)", $lock_name));
            if ($got_lock !== 1) {
                continue;
            }

            try {
                if ($this->has_paid_for_period($user_id, $period)) {
                    // Defensive: a manual_pay() may have completed
                    // between the SELECT and the lock acquisition.
                    // Advance the date so this user isn't re-picked
                    // up tomorrow on the same row, and move on.
                    self::advance_next_billing_date($user_id, $next_billing);
                    continue;
                }

                $balance = (float) $wallet->get_balance($user_id);

                if ($balance >= $amount) {
                    $debited = $wallet->debit(
                        $user_id,
                        $amount,
                        'subscription',
                        sprintf(
                            /* translators: %s: billing period label, e.g. "May 2026" */
                            __('Monthly subscription fee - %s', 'matrix-mlm'),
                            self::period_label($next_billing)
                        ),
                        'SUB-' . $user_id . '-' . $period
                    );
                    if ($debited === false) {
                        $this->record_payment($user_id, $amount, $period, 'unpaid');
                        continue;
                    }
                    $this->record_payment($user_id, $amount, $period, 'paid');
                    self::advance_next_billing_date($user_id, $next_billing);

                    // Clear any reminder stamp for this period so
                    // the wp_user_meta key reflects the live state
                    // (no stale "reminded for May" record once May
                    // has been paid and we're billing for June).
                    delete_user_meta($user_id, 'matrix_subscription_reminder_sent_for');
                } else {
                    $this->record_payment($user_id, $amount, $period, 'unpaid');
                }
            } finally {
                $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
            }
        }
    }

    /**
     * Pass 3 — deactivate users still unpaid past the grace window.
     *
     * Picks up the rows charge_due_users() recorded as 'unpaid' for
     * a period whose next_billing_date + grace_days is now in the
     * past, retries the wallet debit one more time (in case funds
     * landed since this morning), and otherwise flips status to
     * 'inactive' and the matrix_subscriptions row to 'overdue'.
     *
     * Same advisory lock as the charge sweep — a user who tops up
     * the wallet at exactly the grace deadline could otherwise see
     * a manual_pay() race the deactivation sweep and lose.
     */
    private function deactivate_overdue_users($amount, $grace_days, $today) {
        global $wpdb;

        $cutoff = date('Y-m-d', strtotime($today . ' -' . max(0, (int) $grace_days) . ' day'));

        $overdue_users = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, next_billing_date
               FROM {$wpdb->prefix}matrix_user_meta
              WHERE status = 'active'
                AND next_billing_date IS NOT NULL
                AND next_billing_date <= %s",
            $cutoff
        ));

        if (empty($overdue_users)) {
            return;
        }

        $wallet = new Matrix_MLM_Wallet();

        foreach ($overdue_users as $user) {
            $user_id      = (int) $user->user_id;
            $next_billing = (string) $user->next_billing_date;
            $period       = self::period_from_date($next_billing);

            $lock_name = self::lock_name($user_id, $period);
            $got_lock  = (int) $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 2)", $lock_name));
            if ($got_lock !== 1) {
                continue;
            }

            try {
                if ($this->has_paid_for_period($user_id, $period)) {
                    self::advance_next_billing_date($user_id, $next_billing);
                    continue;
                }

                $balance = (float) $wallet->get_balance($user_id);

                if ($balance >= $amount) {
                    $debited = $wallet->debit(
                        $user_id,
                        $amount,
                        'subscription',
                        sprintf(
                            /* translators: %s: billing period label, e.g. "May 2026" */
                            __('Monthly subscription fee - %s (late)', 'matrix-mlm'),
                            self::period_label($next_billing)
                        ),
                        'SUB-' . $user_id . '-' . $period
                    );
                    if ($debited !== false) {
                        $this->record_payment($user_id, $amount, $period, 'paid');
                        self::advance_next_billing_date($user_id, $next_billing);
                        delete_user_meta($user_id, 'matrix_subscription_reminder_sent_for');
                        continue;
                    }
                }

                // Still short — deactivate.
                $wpdb->update(
                    $wpdb->prefix . 'matrix_user_meta',
                    ['status' => 'inactive'],
                    ['user_id' => $user_id]
                );

                $wpdb->update(
                    $wpdb->prefix . 'matrix_subscriptions',
                    ['status' => 'overdue'],
                    ['user_id' => $user_id, 'billing_month' => $period]
                );

                Matrix_MLM_Notifications::send_subscription_deactivation_notification(
                    $user_id,
                    (float) $amount,
                    $period
                );

                Matrix_MLM_Notifications::send_admin_notification(
                    'subscription_deactivation',
                    sprintf('User ID %d has been deactivated due to unpaid monthly subscription.', $user_id)
                );
            } finally {
                $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
            }
        }
    }

    /**
     * Has the user paid for a specific YYYY-MM period?
     *
     * Kept as a public method for other surfaces (Admin →
     * Subscription Stats, the user-facing billing history view)
     * that want to ask the same question without re-implementing
     * the SQL. Public alias has_paid_for_month is preserved for
     * the legacy callers that haven't migrated to the
     * "period == YYYY-MM" naming yet.
     */
    public function has_paid_for_period($user_id, $period) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}matrix_subscriptions
              WHERE user_id = %d AND billing_month = %s AND status = 'paid'",
            (int) $user_id, $period
        ));
    }

    /**
     * Backward-compatible alias for has_paid_for_period(). Kept
     * so any third-party integration code that called the old
     * name continues to work after the per-user-anniversary
     * refactor.
     */
    public function has_paid_for_month($user_id, $billing_month) {
        return $this->has_paid_for_period($user_id, $billing_month);
    }

    /**
     * Record a subscription payment attempt.
     *
     * Idempotent against the (user_id, billing_month) UNIQUE
     * index — re-recording the same period flips the existing
     * row's status rather than colliding on insert.
     */
    private function record_payment($user_id, $amount, $period, $status) {
        global $wpdb;

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}matrix_subscriptions
              WHERE user_id = %d AND billing_month = %s",
            (int) $user_id, $period
        ));

        if ($existing) {
            $wpdb->update(
                $wpdb->prefix . 'matrix_subscriptions',
                [
                    'status'  => $status,
                    'paid_at' => $status === 'paid' ? current_time('mysql') : null,
                ],
                ['id' => (int) $existing]
            );
        } else {
            $wpdb->insert($wpdb->prefix . 'matrix_subscriptions', [
                'user_id'       => (int) $user_id,
                'amount'        => $amount,
                'billing_month' => $period,
                'status'        => $status,
                'paid_at'       => $status === 'paid' ? current_time('mysql') : null,
                'created_at'    => current_time('mysql'),
            ]);
        }
    }

    /**
     * Manually pay subscription (user action from the dashboard
     * recovery view).
     *
     * Pays for the OVERDUE period (next_billing_date's YYYY-MM),
     * not the current calendar month — a user whose next_billing
     * was 2026-04-15 and clicks Pay Subscription on May 10 is
     * paying for the April period that lapsed, then advances to
     * the May period as their new next_billing_date.
     *
     * If the user's next_billing_date is in the future (they
     * pre-paid via this surface — rare but legal), we still
     * accept the payment, mark the period paid, and advance the
     * date as if the cron had run on schedule.
     */
    public function manual_pay($user_id) {
        $amount = floatval(get_option('matrix_mlm_subscription_amount', 0));
        if ($amount <= 0) {
            return ['success' => false, 'message' => __('Subscription is not configured.', 'matrix-mlm')];
        }

        $user_id = (int) $user_id;
        global $wpdb;

        $current = $wpdb->get_row($wpdb->prepare(
            "SELECT next_billing_date, status
               FROM {$wpdb->prefix}matrix_user_meta
              WHERE user_id = %d",
            $user_id
        ));

        if (!$current) {
            return ['success' => false, 'message' => __('User record not found.', 'matrix-mlm')];
        }

        // Period being paid for. Falls back to the current
        // calendar month for users with a NULL next_billing_date —
        // shouldn't happen post-migration, but defends against
        // edge-case installs where the migration hasn't run yet.
        $next_billing = $current->next_billing_date
            ? (string) $current->next_billing_date
            : current_time('Y-m-d');
        $period       = self::period_from_date($next_billing);

        $lock_name = self::lock_name($user_id, $period);
        $got_lock  = (int) $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 5)", $lock_name));
        if ($got_lock !== 1) {
            return ['success' => false, 'message' => __('Another payment is in progress, please try again.', 'matrix-mlm')];
        }

        try {
            if ($this->has_paid_for_period($user_id, $period)) {
                return ['success' => false, 'message' => __('You have already paid for this period.', 'matrix-mlm')];
            }

            $wallet  = new Matrix_MLM_Wallet();
            $balance = (float) $wallet->get_balance($user_id);

            if ($balance < $amount) {
                return ['success' => false, 'message' => __('Insufficient Matrix wallet balance.', 'matrix-mlm')];
            }

            $debited = $wallet->debit(
                $user_id,
                $amount,
                'subscription',
                sprintf(
                    /* translators: %s: billing period label, e.g. "May 2026" */
                    __('Monthly subscription fee - %s', 'matrix-mlm'),
                    self::period_label($next_billing)
                ),
                'SUB-' . $user_id . '-' . $period
            );
            if ($debited === false) {
                return ['success' => false, 'message' => __('Could not debit wallet. Please try again.', 'matrix-mlm')];
            }

            $this->record_payment($user_id, $amount, $period, 'paid');
            self::advance_next_billing_date($user_id, $next_billing);
            delete_user_meta($user_id, 'matrix_subscription_reminder_sent_for');

            // Reactivate user if they were inactive due to subscription.
            // Conditional WHERE clause keeps banned/pending accounts
            // untouched — those states require operator action and
            // intentionally have no user-facing self-heal path.
            $wpdb->update(
                $wpdb->prefix . 'matrix_user_meta',
                ['status' => 'active'],
                ['user_id' => $user_id, 'status' => 'inactive']
            );

            return ['success' => true, 'message' => __('Subscription paid successfully! Your account is active.', 'matrix-mlm')];
        } finally {
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
        }
    }

    /**
     * Get user's subscription history (most recent first).
     */
    public function get_user_history($user_id, $limit = 12) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_subscriptions
              WHERE user_id = %d ORDER BY billing_month DESC LIMIT %d",
            (int) $user_id, (int) $limit
        ));
    }

    /**
     * Compact subscription summary for the user — drives the
     * dashboard sidebar status badge and any future "next billing"
     * reminder card.
     *
     * Returns a fully-resolved hash so callers don't need to know
     * about the option keys, the next_billing_date column, or the
     * wallet shortfall arithmetic. Safe to call on every dashboard
     * page render: three fast SELECTs plus one wallet balance read,
     * no advisory locks, no wallet writes.
     *
     * Returned shape:
     *   - enabled            (bool)   — admin master toggle
     *   - amount             (float)  — configured monthly fee
     *   - balance            (float)  — current Matrix wallet balance
     *   - next_billing_date  (string) — YYYY-MM-DD or '' when N/A
     *   - days_until         (int)    — days from today to billing
     *                                    date; negative when overdue;
     *                                    PHP_INT_MAX when N/A
     *   - shortfall          (float)  — amount - balance, clamped to 0
     *   - status             (string) — 'inactive' | 'shortfall'
     *                                    | 'reminder' | 'active'
     *   - reminder_window    (int)    — days configured for reminders
     */
    public static function get_user_subscription_status($user_id) {
        $user_id = (int) $user_id;
        $enabled = (int) get_option('matrix_mlm_subscription_enabled', 0) === 1;
        $amount  = (float) get_option('matrix_mlm_subscription_amount', 0);

        $wallet  = new Matrix_MLM_Wallet();
        $balance = $user_id > 0 ? (float) $wallet->get_balance($user_id) : 0.0;

        global $wpdb;
        $row = $user_id > 0
            ? $wpdb->get_row($wpdb->prepare(
                "SELECT next_billing_date, status
                   FROM {$wpdb->prefix}matrix_user_meta
                  WHERE user_id = %d",
                $user_id
            ))
            : null;

        $next_billing = ($row && $row->next_billing_date) ? (string) $row->next_billing_date : '';
        $user_status  = $row ? (string) $row->status : '';

        $days_until = PHP_INT_MAX;
        if ($next_billing !== '') {
            $today_ts = strtotime(current_time('Y-m-d'));
            $next_ts  = strtotime($next_billing);
            if ($today_ts !== false && $next_ts !== false) {
                $days_until = (int) round(($next_ts - $today_ts) / DAY_IN_SECONDS);
            }
        }

        $shortfall = max(0.0, $amount - $balance);
        $window    = self::reminder_days();

        $status = 'active';
        if ($user_status === 'inactive') {
            $status = 'inactive';
        } elseif ($enabled && $amount > 0) {
            if ($shortfall > 0 && $days_until <= 0) {
                $status = 'shortfall'; // overdue or due-today, wallet short
            } elseif ($shortfall > 0 && $days_until <= $window) {
                $status = 'reminder';
            }
        }

        return [
            'enabled'           => $enabled,
            'amount'            => $amount,
            'balance'           => $balance,
            'next_billing_date' => $next_billing,
            'days_until'        => $days_until,
            'shortfall'         => $shortfall,
            'status'            => $status,
            'reminder_window'   => $window,
        ];
    }

    /**
     * Compute the next_billing_date for a brand-new account.
     * Used by Matrix_MLM_Core's signup flow so the very first
     * billing lands exactly one month from signup, not on whatever
     * calendar day the legacy global billing-day option happened
     * to be set to.
     *
     * Edge: signup on the 31st of a 31-day month → next billing
     * lands on the 28th of the next month (clamped via the same
     * advance_next_billing_date helper used by the cron). This
     * keeps every user's subsequent bill dates stable on a
     * 28-or-fewer day-of-month, which is the same constraint the
     * admin Settings tab enforces on the (now legacy) shared
     * billing-day option.
     */
    public static function compute_initial_next_billing_date() {
        $today = current_time('Y-m-d');
        return self::add_one_month($today);
    }

    /**
     * Update matrix_user_meta.next_billing_date for a user given
     * the date that just got paid. Always advances by exactly one
     * month, clamped to day-of-month <= 28 to avoid month-end
     * drift (e.g. Jan 31 → Mar 03 → Apr 03 — drifts forward every
     * year). Idempotent against re-runs because the new date is
     * computed off the OLD next_billing_date passed in, not off
     * the current row's value.
     */
    public static function advance_next_billing_date($user_id, $from_date) {
        $next = self::add_one_month($from_date);
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'matrix_user_meta',
            ['next_billing_date' => $next],
            ['user_id' => (int) $user_id]
        );
        return $next;
    }

    /**
     * Add exactly one month to a YYYY-MM-DD date, clamped to
     * day-of-month <= 28 so the date stays valid in every
     * subsequent month and the user's billing day never drifts.
     */
    public static function add_one_month($date) {
        $ts = strtotime((string) $date);
        if ($ts === false) {
            $ts = time();
        }
        $year  = (int) date('Y', $ts);
        $month = (int) date('n', $ts);
        $day   = (int) date('j', $ts);
        if ($day > 28) {
            $day = 28;
        }
        $month++;
        if ($month > 12) {
            $month = 1;
            $year++;
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Convert a YYYY-MM-DD date to its YYYY-MM period label.
     * Defensive against malformed input — returns the current
     * calendar month so callers don't have to handle a NULL/
     * empty-string fallback themselves.
     */
    public static function period_from_date($date) {
        $ts = strtotime((string) $date);
        if ($ts === false) {
            return current_time('Y-m');
        }
        return date('Y-m', $ts);
    }

    /**
     * Render a YYYY-MM-DD or YYYY-MM string as a localised "Month Year"
     * label for use in transaction descriptions and email bodies.
     */
    public static function period_label($date) {
        $ts = strtotime((string) $date);
        if ($ts === false) {
            return date_i18n('F Y');
        }
        return date_i18n('F Y', $ts);
    }

    /**
     * Build the per-user-period MySQL advisory lock name. Centralised
     * so the cron and the manual-pay surfaces always use the exact
     * same key — a mismatch here would re-introduce the double-debit
     * race the lock exists to prevent.
     */
    private static function lock_name($user_id, $period) {
        return 'mlm_sub_' . md5((int) $user_id . ':' . (string) $period);
    }

    /**
     * Create the subscriptions table.
     */
    public static function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $table = $wpdb->prefix . 'matrix_subscriptions';
        $sql = "CREATE TABLE $table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            amount decimal(12,2) NOT NULL,
            billing_month varchar(7) NOT NULL,
            status enum('paid','unpaid','overdue') NOT NULL DEFAULT 'unpaid',
            paid_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY billing_month (billing_month),
            KEY status (status),
            UNIQUE KEY user_month (user_id, billing_month)
        ) $charset_collate;";
        dbDelta($sql);
    }
}
