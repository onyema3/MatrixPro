<?php
/**
 * User Management
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_User {

    /**
     * Get user matrix meta
     */
    public static function get_meta($user_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_user_meta WHERE user_id = %d",
            $user_id
        ));
    }

    /**
     * Get user referral code
     */
    public static function get_referral_code($user_id) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT referral_code FROM {$wpdb->prefix}matrix_user_meta WHERE user_id = %d",
            $user_id
        ));
    }

    /**
     * Get referral link
     */
    public static function get_referral_link($user_id) {
        $code = self::get_referral_code($user_id);
        return home_url('/matrix-register/?ref=' . $code);
    }

    /**
     * Get direct referrals
     */
    public static function get_referrals($user_id, $limit = 20, $offset = 0) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT um.*, u.user_login, u.user_email, u.user_registered 
             FROM {$wpdb->prefix}matrix_user_meta um 
             LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID 
             WHERE um.referred_by = %d 
             ORDER BY um.created_at DESC 
             LIMIT %d OFFSET %d",
            $user_id, $limit, $offset
        ));
    }

    /**
     * Get referral count
     */
    public static function get_referral_count($user_id) {
        global $wpdb;
        return intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}matrix_user_meta WHERE referred_by = %d",
            $user_id
        )));
    }

    /**
     * Get total referral earnings
     */
    public static function get_referral_earnings($user_id) {
        global $wpdb;
        return floatval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_commissions WHERE user_id = %d AND type = 'referral'",
            $user_id
        )));
    }

    /**
     * Get level commissions
     */
    public static function get_level_commissions($user_id, $limit = 50, $offset = 0) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, u.user_login as from_username, p.name as plan_name 
             FROM {$wpdb->prefix}matrix_commissions c 
             LEFT JOIN {$wpdb->users} u ON c.from_user_id = u.ID 
             LEFT JOIN {$wpdb->prefix}matrix_plans p ON c.plan_id = p.id 
             WHERE c.user_id = %d AND c.type = 'level' 
             ORDER BY c.created_at DESC 
             LIMIT %d OFFSET %d",
            $user_id, $limit, $offset
        ));
    }

    /**
     * Get total level earnings
     */
    public static function get_level_earnings($user_id) {
        global $wpdb;
        return floatval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}matrix_commissions WHERE user_id = %d AND type = 'level'",
            $user_id
        )));
    }

    /**
     * Get user active plans
     */
    public static function get_active_plans($user_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, pos.joined_at, pos.total_downline, pos.status as position_status 
             FROM {$wpdb->prefix}matrix_positions pos 
             LEFT JOIN {$wpdb->prefix}matrix_plans p ON pos.plan_id = p.id 
             WHERE pos.user_id = %d AND pos.status = 'active'",
            $user_id
        ));
    }

    /**
     * Ban user
     */
    public static function ban($user_id) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'matrix_user_meta',
            ['status' => 'banned'],
            ['user_id' => $user_id]
        );
    }

    /**
     * Unban user
     */
    public static function unban($user_id) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'matrix_user_meta',
            ['status' => 'active'],
            ['user_id' => $user_id]
        );
    }

    /**
     * Check if user is active
     */
    public static function is_active($user_id) {
        global $wpdb;
        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}matrix_user_meta WHERE user_id = %d",
            $user_id
        ));
        return $status === 'active';
    }
}
