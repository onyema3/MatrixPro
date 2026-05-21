<?php
/**
 * Commission Management
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Commission {

    /**
     * Get all commissions for a user
     */
    public static function get_user_commissions($user_id, $type = null, $limit = 20, $offset = 0) {
        global $wpdb;

        $where = "WHERE c.user_id = %d";
        $params = [$user_id];

        if ($type) {
            $where .= " AND c.type = %s";
            $params[] = $type;
        }

        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, u.user_login as from_username, p.name as plan_name 
             FROM {$wpdb->prefix}matrix_commissions c 
             LEFT JOIN {$wpdb->users} u ON c.from_user_id = u.ID 
             LEFT JOIN {$wpdb->prefix}matrix_plans p ON c.plan_id = p.id 
             $where ORDER BY c.created_at DESC LIMIT %d OFFSET %d",
            $params
        ));
    }

    /**
     * Get commission summary
     */
    public static function get_summary($user_id) {
        global $wpdb;

        return [
            'total' => floatval($wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_commissions WHERE user_id = %d AND status = 'paid'",
                $user_id
            ))),
            'referral' => floatval($wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_commissions WHERE user_id = %d AND type = 'referral' AND status = 'paid'",
                $user_id
            ))),
            'level' => floatval($wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_commissions WHERE user_id = %d AND type = 'level' AND status = 'paid'",
                $user_id
            ))),
            'matrix_completion' => floatval($wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_commissions WHERE user_id = %d AND type = 'matrix_completion' AND status = 'paid'",
                $user_id
            ))),
        ];
    }

    /**
     * Get total platform commissions
     */
    public static function get_platform_total() {
        global $wpdb;
        return floatval($wpdb->get_var(
            "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_commissions WHERE status = 'paid'"
        ));
    }
}
