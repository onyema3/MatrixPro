<?php
/**
 * Plugin Deactivator
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Deactivator {

    public static function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('matrix_mlm_daily_cron');
        wp_clear_scheduled_hook('matrix_mlm_hourly_cron');

        // Clear the weekly database-backup cron explicitly. Routed
        // through the backup class so the hook name stays defined in
        // exactly one place — if we ever rename it we don't have to
        // remember to update the deactivator too.
        if (class_exists('Matrix_MLM_Admin_Backup')) {
            Matrix_MLM_Admin_Backup::clear_cron();
        } else {
            // Fallback if the class file isn't loaded for any reason
            // (e.g. partial uninstall): clear the hook by literal
            // name so a stale event can't survive the deactivation.
            wp_clear_scheduled_hook('matrix_mlm_weekly_backup');
        }

        // Flush rewrite rules
        flush_rewrite_rules();

        // Note: We don't drop tables on deactivation to preserve data
        update_option('matrix_mlm_deactivated_at', current_time('mysql'));
    }
}
