<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Core plugin class.
 */
class WPRankLab {

    /**
     * Admin instance.
     *
     * @var WPRankLab_Admin
     */
    protected $admin;

    /**
     * License manager instance.
     *
     * @var WPRankLab_License_Manager
     */
    protected $license_manager;

    /**
     * Initialize the plugin.
     */
    public function __construct() {
        // Future: init public hooks, cron, scanners, etc.
        add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
        
        
    }

    /**
     * Register all hooks.
     */
    public function run() {

        // Initialize license manager (Pro edition).
        if ( defined( 'WPRANKLAB_EDITION' ) && 'pro' === WPRANKLAB_EDITION && class_exists( 'WPRankLab_License_Manager' ) ) {
            $this->license_manager = WPRankLab_License_Manager::get_instance();
            $this->license_manager->init();
        }

        
        // Initialize analyzer and its hooks.
        if ( class_exists( 'WPRankLab_Analyzer' ) ) {
            $analyzer = WPRankLab_Analyzer::get_instance();
            add_action( 'save_post', array( $analyzer, 'handle_save_post' ), 20, 2 );
        }

        // Initialize history manager (weekly snapshots + emails).
        if ( class_exists( 'WPRankLab_History' ) ) {
            $history = WPRankLab_History::get_instance();
            $history->init();
        }
        
        // Initialize Pro missing topic detector (manual scans only).
        if ( class_exists( 'WPRankLab_Missing_Topics' ) ) {
            $mt = WPRankLab_Missing_Topics::get_instance();
            $mt->init();
        }
        
        if ( class_exists( 'WPRankLab_Schema' ) ) {
            WPRankLab_Schema::get_instance()->init();
        }
        
        if ( class_exists( 'WPRankLab_Internal_Links' ) ) {
            WPRankLab_Internal_Links::get_instance()->init();
        }
        
        // Initialize batch scan engine.
        if ( class_exists( 'WPRankLab_Batch_Scan' ) ) {
            WPRankLab_Batch_Scan::get_instance()->init();
        }
        
        // Connect batch scan hook to the existing per-post analyzer pipeline.
        add_action( 'wpranklab_scan_single_post', function( $post_id ) {
            $post_id = (int) $post_id;
            if ( $post_id <= 0 ) {
                return;
            }
            
            if ( class_exists( 'WPRankLab_Analyzer' ) ) {
                $analyzer = WPRankLab_Analyzer::get_instance();
                if ( is_object( $analyzer ) && method_exists( $analyzer, 'analyze_post' ) ) {
                    // Same call used by handle_scan_post() in admin.
                    $analyzer->analyze_post( $post_id );
                }
            }
        }, 10, 1 );
            
        
        if ( is_admin() && class_exists( 'WPRankLab_Admin' ) ) {
            $this->load_admin();
        }
        

        // Future: add more cron hooks, REST routes, etc.
    }
    
    public function add_cron_schedules( $schedules ) {
        if ( ! isset( $schedules['weekly'] ) ) {
            $schedules['weekly'] = array(
                'interval' => 7 * DAY_IN_SECONDS,
                'display'  => __( 'Once Weekly', 'wpranklab' ),
            );
        }
        return $schedules;
    }
    

    /**
     * Load admin functionality.
     */
    protected function load_admin() {
        $this->admin = new WPRankLab_Admin();
        $this->admin->init();
    }
    
}
