<?php
/**
 * Monthly Subscription Management
 * 
 * Handles automatic monthly charges and user deactivation for non-payment.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Subscription {

    public function __construct() {
        // Register cron hook
        add_action('matrix_mlm_monthly_subscription', [$this, 'process_monthly_subscriptions']);

        // Schedule monthly cron if enabled and not scheduled
        if (get_option('matrix_mlm_subscription_enabled', 0) && !wp_next_scheduled('matrix_mlm_monthly_subscription')) {
            wp_schedule_event(time(), 'daily', 'matrix_mlm_monthly_subscription');
        }
    }

    /**
     * Process monthly subscriptions (runs daily via cron)
     * Checks if today is the billing day, charges users, deactivates non-payers after grace period
     */
    public function process_monthly_subscriptions() {
        if (!get_option('matrix_mlm_subscription_enabled', 0)) {
            return;
        }

        $billing_day = intval(get_option('matrix_mlm_subscription_billing_day', 1));
        $grace_days = intval(get_option('matrix_mlm_subscription_grace_days', 3));
        $amount = floatval(get_option('matrix_mlm_subscription_amount', 0));

        if ($amount <= 0) {
            return;
        }

        $today = intval(date('j'));
        $current_month = date('Y-m');

        // On billing day: attempt to charge all active users
        if ($today === $billing_day) {
            $this->charge_active_users($amount, $current_month);
        }

        // After grace period: deactivate users who haven't paid
        $grace_deadline_day = $billing_day + $grace_days;
        // Handle month overflow (e.g., billing day 28 + grace 5 = day 33 → next month day 2-3)
        $days_in_month = intval(date('t'));
        if ($grace_deadline_day > $days_in_month) {
            $grace_deadline_day = $days_in_month;
        }

        if ($today === $grace_deadline_day) {
            $this->deactivate_unpaid_users($current_month);
        }
    }

    /**
     * Charge all active users for the monthly subscription
     */
    private function charge_active_users($amount, $billing_month) {
        global $wpdb;

        $active_users = $wpdb->get_results(
            "SELECT user_id FROM {$wpdb->prefix}matrix_user_meta WHERE status = 'active'"
        );

        $wallet = new Matrix_MLM_Wallet();
        $currency = get_option('matrix_mlm_currency_symbol', '₦');

        foreach ($active_users as $user) {
            // Check if already paid for this month
            if ($this->has_paid_for_month($user->user_id, $billing_month)) {
                continue;
            }

            $balance = $wallet->get_balance($user->user_id);

            if ($balance >= $amount) {
                // Auto-debit from Matrix wallet
                $wallet->debit(
                    $user->user_id,
                    $amount,
                    'subscription',
                    sprintf(__('Monthly subscription fee - %s', 'matrix-mlm'), date('F Y'))
                );

                // Record payment
                $this->record_payment($user->user_id, $amount, $billing_month, 'paid');
            } else {
                // Record as unpaid (will be deactivated after grace period)
                $this->record_payment($user->user_id, $amount, $billing_month, 'unpaid');
            }
        }
    }

    /**
     * Deactivate users who haven't paid after grace period
     */
    private function deactivate_unpaid_users($billing_month) {
        global $wpdb;

        $unpaid_users = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}matrix_subscriptions 
             WHERE billing_month = %s AND status = 'unpaid'",
            $billing_month
        ));

        foreach ($unpaid_users as $user) {
            // Try one more time to charge
            $amount = floatval(get_option('matrix_mlm_subscription_amount', 0));
            $wallet = new Matrix_MLM_Wallet();
            $balance = $wallet->get_balance($user->user_id);

            if ($balance >= $amount) {
                // They have funds now — charge them
                $wallet->debit(
                    $user->user_id,
                    $amount,
                    'subscription',
                    sprintf(__('Monthly subscription fee - %s (late)', 'matrix-mlm'), date('F Y'))
                );

                $wpdb->update(
                    $wpdb->prefix . 'matrix_subscriptions',
                    ['status' => 'paid', 'paid_at' => current_time('mysql')],
                    ['user_id' => $user->user_id, 'billing_month' => $billing_month]
                );
            } else {
                // Deactivate the user
                $wpdb->update(
                    $wpdb->prefix . 'matrix_user_meta',
                    ['status' => 'inactive'],
                    ['user_id' => $user->user_id]
                );

                $wpdb->update(
                    $wpdb->prefix . 'matrix_subscriptions',
                    ['status' => 'overdue'],
                    ['user_id' => $user->user_id, 'billing_month' => $billing_month]
                );

                // Notify the user — this is the channel the user actually
                // sees, and they need it to know why the dashboard
                // suddenly refuses to load. The email lays out the cure
                // (top up + click Pay Subscription on the dashboard) so
                // the user can self-heal without contacting support.
                Matrix_MLM_Notifications::send_subscription_deactivation_notification(
                    (int) $user->user_id,
                    $amount,
                    $billing_month
                );

                // Notify admin too — kept from the original flow so
                // operators don't lose visibility into who lapsed.
                Matrix_MLM_Notifications::send_admin_notification(
                    'subscription_deactivation',
                    sprintf('User ID %d has been deactivated due to unpaid monthly subscription.', $user->user_id)
                );
            }
        }
    }

    /**
     * Check if user has paid for a specific month
     */
    public function has_paid_for_month($user_id, $billing_month) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}matrix_subscriptions 
             WHERE user_id = %d AND billing_month = %s AND status = 'paid'",
            $user_id, $billing_month
        ));
    }

    /**
     * Record a subscription payment attempt
     */
    private function record_payment($user_id, $amount, $billing_month, $status) {
        global $wpdb;

        // Check if record exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}matrix_subscriptions 
             WHERE user_id = %d AND billing_month = %s",
            $user_id, $billing_month
        ));

        if ($existing) {
            $wpdb->update(
                $wpdb->prefix . 'matrix_subscriptions',
                [
                    'status' => $status,
                    'paid_at' => $status === 'paid' ? current_time('mysql') : null,
                ],
                ['id' => $existing]
            );
        } else {
            $wpdb->insert($wpdb->prefix . 'matrix_subscriptions', [
                'user_id' => $user_id,
                'amount' => $amount,
                'billing_month' => $billing_month,
                'status' => $status,
                'paid_at' => $status === 'paid' ? current_time('mysql') : null,
                'created_at' => current_time('mysql'),
            ]);
        }
    }

    /**
     * Manually pay subscription (user action from dashboard)
     */
    public function manual_pay($user_id) {
        $amount = floatval(get_option('matrix_mlm_subscription_amount', 0));
        if ($amount <= 0) {
            return ['success' => false, 'message' => __('Subscription is not configured.', 'matrix-mlm')];
        }

        $current_month = date('Y-m');

        if ($this->has_paid_for_month($user_id, $current_month)) {
            return ['success' => false, 'message' => __('You have already paid for this month.', 'matrix-mlm')];
        }

        $wallet = new Matrix_MLM_Wallet();
        $balance = $wallet->get_balance($user_id);

        if ($balance < $amount) {
            return ['success' => false, 'message' => __('Insufficient Matrix wallet balance.', 'matrix-mlm')];
        }

        $wallet->debit(
            $user_id,
            $amount,
            'subscription',
            sprintf(__('Monthly subscription fee - %s', 'matrix-mlm'), date('F Y'))
        );

        $this->record_payment($user_id, $amount, $current_month, 'paid');

        // Reactivate user if they were inactive due to subscription
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'matrix_user_meta',
            ['status' => 'active'],
            ['user_id' => $user_id, 'status' => 'inactive']
        );

        return ['success' => true, 'message' => __('Subscription paid successfully! Your account is active.', 'matrix-mlm')];
    }

    /**
     * Get user's subscription history
     */
    public function get_user_history($user_id, $limit = 12) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_subscriptions 
             WHERE user_id = %d ORDER BY billing_month DESC LIMIT %d",
            $user_id, $limit
        ));
    }

    /**
     * Create the subscriptions table
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
