<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Fired during plugin deactivation.
 */
class WPRankLab_Deactivator {

    /**
     * Deactivation hook.
     */
    public static function deactivate() {
        self::clear_cron_events();
        // Note: We DO NOT delete tables or options here to avoid data loss.
    }

    /**
     * Clear cron events.
     */
    protected static function clear_cron_events() {
        $timestamp = wp_next_scheduled( 'wpranklab_daily_license_check' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'wpranklab_daily_license_check' );
        }

        $timestamp = wp_next_scheduled( 'wpranklab_weekly_report' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'wpranklab_weekly_report' );
        }
    }
}
