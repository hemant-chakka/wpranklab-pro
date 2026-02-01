<?php
defined( 'ABSPATH' ) || exit;

/**
 * Throttled, resume-safe batch scanner.
 * - Stores queue + progress in options
 * - Runs in chunks via wp_schedule_single_event
 * - Prevents overlap using transient lock
 */
class WPRankLab_Batch_Scan {
    
    const CRON_HOOK = 'wpranklab_run_batch_scan';
    
    const OPT_QUEUE    = 'wpranklab_batch_queue';
    const OPT_PROGRESS = 'wpranklab_batch_progress';
    const OPT_STATUS   = 'wpranklab_batch_status';   // idle|running|complete|cancelled
    const OPT_LASTRUN  = 'wpranklab_batch_last_run';
    
    const POSTS_PER_RUN  = 3;
    const NEXT_RUN_DELAY = 10; // seconds
    const LOCK_TTL       = 30; // seconds
    
    private static $instance = null;
    
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function init() {
        add_action( self::CRON_HOOK, array( $this, 'run_batch' ) );
    }
    
    public function start_scan( array $post_types = array( 'post', 'page' ) ) {
        $ids = get_posts( array(
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ) );
        
        update_option( self::OPT_QUEUE, array_values( $ids ), false );
        update_option( self::OPT_PROGRESS, 0, false );
        update_option( self::OPT_STATUS, 'running', false );
        update_option( self::OPT_LASTRUN, time(), false );
        
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_single_event( time() + 5, self::CRON_HOOK );
        }
    }
    
    public function cancel_scan() {
        update_option( self::OPT_STATUS, 'cancelled', false );
        
        $ts = wp_next_scheduled( self::CRON_HOOK );
        while ( $ts ) {
            wp_unschedule_event( $ts, self::CRON_HOOK );
            $ts = wp_next_scheduled( self::CRON_HOOK );
        }
    }
    
    public function get_state() {
        $queue    = get_option( self::OPT_QUEUE, array() );
        $progress = (int) get_option( self::OPT_PROGRESS, 0 );
        $status   = (string) get_option( self::OPT_STATUS, 'idle' );
        
        return array(
            'status'   => $status,
            'total'    => is_array( $queue ) ? count( $queue ) : 0,
            'progress' => $progress,
            'last_run' => (int) get_option( self::OPT_LASTRUN, 0 ),
        );
    }
    
    public function run_batch() {
        // Overlap guard
        $lock_key = 'wpranklab_batch_lock';
        if ( get_transient( $lock_key ) ) {
            return;
        }
        set_transient( $lock_key, 1, self::LOCK_TTL );
        
        try {
            if ( 'running' !== (string) get_option( self::OPT_STATUS, 'idle' ) ) {
                return;
            }
            
            $queue = get_option( self::OPT_QUEUE, array() );
            if ( ! is_array( $queue ) || empty( $queue ) ) {
                update_option( self::OPT_STATUS, 'complete', false );
                set_transient( 'wpranklab_batch_complete_notice', 1, 60 );
                return;
            }
            
            $progress = (int) get_option( self::OPT_PROGRESS, 0 );
            
            if ( $progress >= count( $queue ) ) {
                update_option( self::OPT_STATUS, 'complete', false );
                return;
            }
            
            $slice = array_slice( $queue, $progress, self::POSTS_PER_RUN );
            
            foreach ( $slice as $post_id ) {
                $post_id = (int) $post_id;
                if ( $post_id <= 0 ) {
                    continue;
                }
                
                // Call the existing per-post scan pipeline via a hook:
                do_action( 'wpranklab_scan_single_post', $post_id );
            }
            
            $progress += count( $slice );
            update_option( self::OPT_PROGRESS, $progress, false );
            update_option( self::OPT_LASTRUN, time(), false );
            
            if ( $progress >= count( $queue ) ) {
                update_option( self::OPT_STATUS, 'complete', false );
                return;
            }
            
            wp_schedule_single_event( time() + self::NEXT_RUN_DELAY, self::CRON_HOOK );
        } finally {
            delete_transient( $lock_key );
        }
    }
}
