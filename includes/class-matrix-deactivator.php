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

        // Flush rewrite rules
        flush_rewrite_rules();

        // Note: We don't drop tables on deactivation to preserve data
        update_option('matrix_mlm_deactivated_at', current_time('mysql'));
    }
}
