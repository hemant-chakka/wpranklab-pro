<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles history snapshots and weekly emails.
 */
class WPRankLab_History {

    /**
     * Singleton.
     *
     * @var WPRankLab_History|null
     */
    protected static $instance = null;

    /**
     * Get instance.
     *
     * @return WPRankLab_History
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Init hooks.
     */
    public function init() {
        // Ensure history table exists.
        $this->maybe_create_table();

        // Hook weekly report event (already scheduled by activator).
        add_action( 'wpranklab_weekly_report', array( $this, 'handle_weekly_event' ) );
        
        add_action( 'init', array( $this, 'ensure_weekly_event' ) );
        
    }

    /**
     * Create history table if it does not exist.
     */
    protected function maybe_create_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wpranklab_history';

        // Check if table exists.
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        if ( $exists === $table_name ) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "
        CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            snapshot_date DATE NOT NULL,
            avg_score FLOAT NULL,
            scanned_count INT(11) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY snapshot_date (snapshot_date)
        ) {$charset_collate};
        ";

        dbDelta( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
    }

    /**
     * Handle weekly cron: record snapshot + send email.
     */
    public function handle_weekly_event() {
        
        // Prevent duplicate weekly sends if WP-Cron triggers twice.
        if ( get_transient( 'wpranklab_weekly_report_lock' ) ) {
            return;
        }
        set_transient( 'wpranklab_weekly_report_lock', 1, 15 * MINUTE_IN_SECONDS );
        
        $snapshot = $this->record_snapshot();
        
        // Email (should already exist)
        $this->send_weekly_email( $snapshot );
        
        // Webhook (you already have this method)
        $this->send_webhook();
    }
    
    

    /**
     * Record a history snapshot for the current site state.
     *
     * @return array Snapshot data.
     */
    public function record_snapshot() {
        global $wpdb;

        $history_table = $wpdb->prefix . 'wpranklab_history';

        // Get all posts/pages with a visibility score.
        $post_types = apply_filters(
            'wpranklab_analyzer_post_types',
            array( 'post', 'page' )
        );

        $meta_key = '_wpranklab_visibility_score';

        if ( empty( $post_types ) || ! is_array( $post_types ) ) {
            $post_types = array( 'post', 'page' );
        }

        $placeholders = implode(
            ', ',
            array_fill( 0, count( $post_types ), '%s' )
        );

        $sql = $wpdb->prepare(
            "
            SELECT pm.meta_value AS score
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = %s
              AND p.post_type IN ($placeholders)
              AND p.post_status = 'publish'
            ",
            array_merge( array( $meta_key ), $post_types )
        );

        $rows = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $scores = array();
        if ( $rows ) {
            foreach ( $rows as $row ) {
                if ( is_numeric( $row ) ) {
                    $scores[] = (float) $row;
                }
            }
        }

        $scanned_count = count( $scores );
        $avg_score     = $scanned_count > 0 ? array_sum( $scores ) / $scanned_count : null;

        $today = current_time( 'Y-m-d' );

        $snapshot = array(
            'snapshot_date' => $today,
            'avg_score'     => $avg_score,
            'scanned_count' => $scanned_count,
        );

        // Insert into history table.
        $wpdb->insert(
            $history_table,
            array(
                'snapshot_date' => $today,
                'avg_score'     => $avg_score,
                'scanned_count' => $scanned_count,
            ),
            array( '%s', '%f', '%d' )
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        return $snapshot;
    }

    /**
     * Get last N snapshots.
     *
     * @param int $limit
     *
     * @return array
     */
    public function get_recent_snapshots( $limit = null ) {
        global $wpdb;

        $history_table = $wpdb->prefix . 'wpranklab_history';

        $sql = "SELECT snapshot_date, avg_score, scanned_count
             FROM {$history_table}
             ORDER BY snapshot_date DESC";

        // Apply LIMIT only when explicitly requested (> 0). null/0 means unlimited.
        if ( is_int( $limit ) && $limit > 0 ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $sql .= $wpdb->prepare( ' LIMIT %d', $limit );
        }

        $rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        return $rows ? $rows : array();
    }

    /**
     * Send weekly email based on latest snapshot.
     *
     * @param array $snapshot
     */
    public function send_weekly_email( $snapshot ) {
        $settings = get_option( 'wpranklab_settings', array() );
        if ( empty( $settings['weekly_email'] ) ) {
            return;
        }
        
        
        // If there is no data yet, do not send.
        if ( empty( $snapshot ) ) {
            return;
        }
        
        $settings = get_option( WPRANKLAB_OPTION_SETTINGS, array() );
        if ( empty( $settings['weekly_email'] ) ) {
            return;
        }
        

        $is_pro  = function_exists( 'wpranklab_is_pro_active' ) && wpranklab_is_pro_active();
        $site    = get_bloginfo( 'name' );
        $to      = get_option( 'admin_email' );
        $subject = sprintf(
            /* translators: %s: site name. */
            __( 'Your Weekly AI Visibility Update — %s', 'wpranklab' ),
            $site
        );

        $avg_score     = is_null( $snapshot['avg_score'] ) ? __( 'N/A', 'wpranklab' ) : round( $snapshot['avg_score'], 1 );
        $scanned_count = (int) $snapshot['scanned_count'];
        $date          = $snapshot['snapshot_date'];

        // Compare with previous snapshot to determine up/down.
        $trend_arrow = '';
        $trend_label = __( 'No previous data', 'wpranklab' );

        $recent = $this->get_recent_snapshots( 2 );
        if ( count( $recent ) >= 2 ) {
            $current = $recent[0];
            $prev    = $recent[1];

            if ( ! is_null( $current['avg_score'] ) && ! is_null( $prev['avg_score'] ) ) {
                if ( $current['avg_score'] > $prev['avg_score'] ) {
                    $trend_arrow = '↑';
                    $trend_label = __( 'Visibility improved since last week.', 'wpranklab' );
                } elseif ( $current['avg_score'] < $prev['avg_score'] ) {
                    $trend_arrow = '↓';
                    $trend_label = __( 'Visibility decreased since last week.', 'wpranklab' );
                } else {
                    $trend_arrow = '→';
                    $trend_label = __( 'Visibility is stable compared to last week.', 'wpranklab' );
                }
            }
        }

        if ( ! $is_pro ) {
            // Free email: simple.
            $body  = '';
            $body .= sprintf( __( "Date: %s
", 'wpranklab' ), $date );
            $body .= sprintf( __( "AI Visibility Score: %s %s
", 'wpranklab' ), $avg_score, $trend_arrow );
            $body .= sprintf( __( "Scanned items: %d

", 'wpranklab' ), $scanned_count );
            $body .= $trend_label . "\n\n";
            $body .= __( 'Upgrade to WPRankLab Pro to unlock full AI visibility insights, historical charts, and detailed recommendations.', 'wpranklab' ) . "\n";
            $body .= "https://wpranklab.com/\n";
        } else {
            // Pro email: richer content (still plain text for now).
            $body  = '';
            $body .= sprintf( __( "Date: %s
", 'wpranklab' ), $date );
            $body .= sprintf( __( "AI Visibility Score: %s %s
", 'wpranklab' ), $avg_score, $trend_arrow );
            $body .= sprintf( __( "Scanned items: %d

", 'wpranklab' ), $scanned_count );
            $body .= $trend_label . "\n\n";
            $body .= __( "In future versions, this email will also include:\n- Citation rank\n- AI / crawler visits\n- Detailed week summary\n- Top recommendations for next week\n", 'wpranklab' );
            $body .= "\n";
            $body .= __( 'Open your full AI Visibility report in WordPress:', 'wpranklab' ) . "\n";
            $body .= admin_url( 'admin.php?page=wpranklab' ) . "\n";
        }

        /**
         * Filter the email before sending.
         *
         * @param array  $email {'to','subject','body','headers'}
         * @param array  $snapshot
         * @param bool   $is_pro
         */
        $email = apply_filters(
            'wpranklab_weekly_email',
            array(
                'to'      => $to,
                'subject' => $subject,
                'body'    => $body,
                'headers' => array(
                    'Content-Type: text/plain; charset=UTF-8',
                    'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
                ),
            ),
            $snapshot,
            $is_pro
        );

        if ( ! empty( $email['to'] ) && ! empty( $email['subject'] ) && ! empty( $email['body'] ) ) {
            $headers = array(
                'Content-Type: text/plain; charset=UTF-8',
                'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
            );
            
            wp_mail( $email['to'], $email['subject'], $email['body'], $headers );
        }
    }
    
    /**
     * Ensure weekly cron event is scheduled.
     */
    public function ensure_weekly_event() {
        if ( ! wp_next_scheduled( 'wpranklab_weekly_report' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'wpranklab_weekly_report' );
        }
    }
    
    
    public function send_webhook( $snapshot = array() ) {
        
        $settings = get_option( 'wpranklab_settings', array() );
        
        if ( empty( $settings['webhook_enabled'] ) || empty( $settings['webhook_url'] ) ) {
            return;
        }
        
        $payload = array(
            'event'          => 'weekly_report',
            'site_url'       => site_url(),
            'site_name'      => get_bloginfo( 'name' ),
            'snapshot_date'  => isset( $snapshot['snapshot_date'] ) ? $snapshot['snapshot_date'] : current_time( 'mysql' ),
            'avg_score'      => isset( $snapshot['avg_score'] ) ? $snapshot['avg_score'] : null,
            'scanned_count'  => isset( $snapshot['scanned_count'] ) ? (int) $snapshot['scanned_count'] : 0,
            'plugin_version' => defined( 'WPRANKLAB_VERSION' ) ? WPRANKLAB_VERSION : '',
            'timestamp'      => time(),
        );
        
        
        $response = wp_remote_post( $settings['webhook_url'], array(
            'timeout' => 10,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $payload ),
        ) );
        
        $settings['webhook_last_sent'] = current_time( 'mysql' );
        
        if ( is_wp_error( $response ) ) {
            $settings['webhook_last_code']  = 0;
            $settings['webhook_last_error'] = $response->get_error_message();
        } else {
            $settings['webhook_last_code']  = (int) wp_remote_retrieve_response_code( $response );
            $settings['webhook_last_error'] = '';
        }
        
        update_option( 'wpranklab_settings', $settings );
    }
    
    
    
}
