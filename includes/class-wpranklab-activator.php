<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Fired during plugin activation.
 */
class WPRankLab_Activator {

    /**
     * Activation hook.
     */
    public static function activate() {
        self::create_tables();
        self::init_options();
        self::schedule_cron_events();
    }

    /**
     * Create custom DB tables for history and audit queue.
     */
    protected static function create_tables() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $history_table     = $wpdb->prefix . WPRANKLAB_TABLE_HISTORY;
        $audit_table       = $wpdb->prefix . WPRANKLAB_TABLE_AUDIT_Q;
        $entities_table    = $wpdb->prefix . WPRANKLAB_TABLE_ENTITIES;
        $entity_post_table = $wpdb->prefix . WPRANKLAB_TABLE_ENTITY_POST;
        

        // Weekly history table.
        $sql_history = "CREATE TABLE {$history_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            visibility_score float DEFAULT NULL,
            visibility_delta float DEFAULT NULL,
            week_start date NOT NULL,
            data longtext NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY week_start (week_start)
        ) {$charset_collate};";

        // Audit queue table.
        $sql_audit = "CREATE TABLE {$audit_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts int(11) NOT NULL DEFAULT 0,
            last_error text NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY status (status)
        ) {$charset_collate};";

        // Entities master table.
        $sql_entities = "CREATE TABLE {$entities_table} (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    name varchar(255) NOT NULL,
    slug varchar(255) NOT NULL,
    type varchar(64) NOT NULL DEFAULT 'thing',
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY slug_type (slug, type)
) {$charset_collate};";
        
        // Entity-to-post mapping table.
        $sql_entity_post = "CREATE TABLE {$entity_post_table} (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    entity_id bigint(20) unsigned NOT NULL,
    post_id bigint(20) unsigned NOT NULL,
    role varchar(64) NOT NULL DEFAULT 'mentioned',
    confidence tinyint(3) unsigned NOT NULL DEFAULT 80,
    first_seen datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY entity_id (entity_id),
    KEY post_id (post_id),
    KEY entity_post (entity_id, post_id)
) {$charset_collate};";
        
        
        dbDelta( $sql_history );
        dbDelta( $sql_audit );
        dbDelta( $sql_entities );
        dbDelta( $sql_entity_post );
        
    }

    /**
     * Initialize basic plugin options.
     */
    protected static function init_options() {
        $default_settings = array(
            'plan'            => 'free', // 'free' or 'pro' – actual status is license-driven.
            'openai_api_key'  => '',
            'weekly_email'    => 1,
            'email_day'       => 'monday',
            'email_time'      => '09:00',
            'webhook_enabled'   => 0,
            'webhook_url'       => '',
            'webhook_last_sent' => '',
            'webhook_last_code' => 0,
            'webhook_last_error'=> '',
            'demo_force_pro'       => 0,
            'demo_force_pro_days'  => 3,
            'demo_force_pro_until' => 0,
            
            
        );

        if ( ! get_option( WPRANKLAB_OPTION_SETTINGS ) ) {
            add_option( WPRANKLAB_OPTION_SETTINGS, $default_settings );
        }

        $default_license = array(
            'license_key'        => '',
            'status'             => 'inactive', // 'inactive', 'active', 'expired', 'invalid'
            'expires_at'         => '',
            'last_check'         => '',
            'allowed_version'    => '',
            'bound_domain'       => '',
            'kill_switch_active' => 0,
        );

        if ( ! get_option( WPRANKLAB_OPTION_LICENSE ) ) {
            add_option( WPRANKLAB_OPTION_LICENSE, $default_license );
        }
    }

    /**
     * Schedule cron events (license check, weekly email, scans).
     */
    protected static function schedule_cron_events() {
        if ( ! wp_next_scheduled( 'wpranklab_daily_license_check' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wpranklab_daily_license_check' );
        }

        if ( ! wp_next_scheduled( 'wpranklab_weekly_report' ) ) {
            // Weekly; we'll refine day/time later.
            wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', 'wpranklab_weekly_report' );
        }
    }
}
