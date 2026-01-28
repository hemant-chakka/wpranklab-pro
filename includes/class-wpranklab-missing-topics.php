<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Pro: Missing topic / coverage detection.
 *
 * Runs only on manual scans (flagged by a transient set in admin scan handler).
 * Saves results in postmeta for fast display.
 */
class WPRankLab_Missing_Topics {
    
    protected static $instance = null;
    
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Register hooks.
     */
    public function init() {
        add_action( 'wpranklab_after_analyze_post', array( $this, 'maybe_generate_missing_topics' ), 20, 2 );
    }
    
    /**
     * Run missing topic detection only when:
     * - Pro is active
     * - AI key exists
     * - This analysis was triggered by manual scan (transient flag)
     *
     * @param int   $post_id
     * @param array $metrics
     */
    public function maybe_generate_missing_topics( $post_id, $metrics ) {
        
        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) {
            return;
        }
        
        // Pro gate + kill-switch safety.
        if ( ! function_exists( 'wpranklab_is_pro_active' ) || ! wpranklab_is_pro_active() ) {
            return;
        }
        
        // Only run on manual scans.
        $flag_key = 'wpranklab_force_missing_topics_' . $post_id;
        $forced   = (bool) get_transient( $flag_key );
        
        if ( ! $forced ) {
            // Not a manual scan. Skip to avoid API calls on every save_post.
            return;
        }
        
        // Clear flag immediately to avoid double-runs on reload.
        delete_transient( $flag_key );
        
        if ( ! class_exists( 'WPRankLab_AI' ) ) {
            return;
        }
        
        $ai = WPRankLab_AI::get_instance();
        if ( ! $ai || ! $ai->is_available() ) {
            return;
        }
        
        // Gather entities (if available).
        $entities_for_post = array();
        if ( class_exists( 'WPRankLab_Entities' ) ) {
            $entities_service  = WPRankLab_Entities::get_instance();
            $entities_for_post = $entities_service->get_entities_for_post( $post_id );
            if ( ! is_array( $entities_for_post ) ) {
                $entities_for_post = array();
            }
        }
        
        $result = $ai->generate_missing_topics_for_post( $post_id, $entities_for_post );
        
        if ( is_wp_error( $result ) ) {
            update_post_meta( $post_id, '_wpranklab_missing_topics_error', $result->get_error_message() );
            return;
        }
        
        // Store as array in postmeta.
        update_post_meta( $post_id, '_wpranklab_missing_topics', $result );
        update_post_meta( $post_id, '_wpranklab_missing_topics_last_run', current_time( 'mysql' ) );
        delete_post_meta( $post_id, '_wpranklab_missing_topics_error' );
    }
}
