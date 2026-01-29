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
        // Compatibility: legacy hook name used in some installs
        add_action( 'wpranklab_weekly_event', array( $this, 'handle_weekly_event' ) );

        
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
        

        $is_pro = function_exists( 'wpranklab_is_pro_active' ) && wpranklab_is_pro_active();
        $site   = get_bloginfo( 'name' );
        $to     = get_option( 'admin_email' );

        // Match the client-facing subject line in the Figma designs / screenshots.
        $subject = __( 'Your Weekly Stats Are Here', 'wpranklab' );

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
            // Free email (Pro plugin installed but license inactive): keep simple plain text.
            $body  = '';
            $body .= sprintf( __( "Date: %s\n", 'wpranklab' ), $date );
            $body .= sprintf( __( "AI Visibility Score: %s %s\n", 'wpranklab' ), $avg_score, $trend_arrow );
            $body .= sprintf( __( "Scanned items: %d\n\n", 'wpranklab' ), $scanned_count );
            $body .= $trend_label . "\n\n";
            $body .= __( 'Upgrade to WPRankLab Pro to unlock full AI visibility insights, historical charts, and detailed recommendations.', 'wpranklab' ) . "\n";
            $body .= "https://wpranklab.com/\n";
        } else {
            // Pro email: HTML template aligned to the provided Figma frame.
            $body = $this->build_pro_weekly_email_html(
                array(
                    'site_name'     => $site,
                    'site_url'      => site_url(),
                    'avg_score'     => $avg_score,
                    'trend_arrow'   => $trend_arrow,
                    'trend_label'   => $trend_label,
                    'scanned_count' => $scanned_count,
                    'dashboard_url' => admin_url( 'admin.php?page=wpranklab' ),
                )
            );
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
                    ( $is_pro ? 'Content-Type: text/html; charset=UTF-8' : 'Content-Type: text/plain; charset=UTF-8' ),
                    'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
                ),
            ),
            $snapshot,
            $is_pro
        );

        if ( ! empty( $email['to'] ) && ! empty( $email['subject'] ) && ! empty( $email['body'] ) ) {
            $headers = ! empty( $email['headers'] ) && is_array( $email['headers'] )
                ? $email['headers']
                : array(
                    ( $is_pro ? 'Content-Type: text/html; charset=UTF-8' : 'Content-Type: text/plain; charset=UTF-8' ),
                    'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
                );

            wp_mail( $email['to'], $email['subject'], $email['body'], $headers );
        }
    }

    /**
     * Build the Pro weekly email HTML.
     *
     * NOTE: This is an email-safe, table-based layout with inline styles.
     *
     * @param array $data
     *
     * @return string
     */
    protected function build_pro_weekly_email_html( $data ) {
        $site_name     = isset( $data['site_name'] ) ? $data['site_name'] : '';
        $site_url      = isset( $data['site_url'] ) ? $data['site_url'] : '';
        $avg_score     = isset( $data['avg_score'] ) ? $data['avg_score'] : '—';
        $trend_arrow   = isset( $data['trend_arrow'] ) ? $data['trend_arrow'] : '';
        $dashboard_url = isset( $data['dashboard_url'] ) ? $data['dashboard_url'] : '';

        // Convert to percentage style used in the Figma comps.
        $visibility_percent = ( is_numeric( $avg_score ) ? (int) round( (float) $avg_score ) . '%' : $avg_score );

        // Pro-only metrics may not exist yet. Keep placeholders (matches current MVP behaviour).
        $site_rank      = '—';
        $ai_visits      = '—';
        $crawler_visits = '—';

                $yellow = '#FEB201';
        $teal   = '#19AEAD';
        $light  = '#E5F8FF';

        // Email header (pure HTML/CSS – no external images, no CID embeds).
        // This avoids broken images in Gmail/dev environments and keeps the header consistent.
        $logo_html  = '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto;">'
            . '<tr>'
            . '<td align="center" valign="middle" style="width:44px;height:44px;border-radius:999px;background:' . $light . '; font-family: Arial, sans-serif; font-size:28px; font-weight:900; color:' . $teal . '; line-height:44px;">W</td>'
            . '<td style="padding-left:10px; font-family: Arial, sans-serif; font-size:28px; font-weight:900; letter-spacing:2px; color:' . $yellow . '; text-transform:uppercase;">WPRANKLAB</td>'
            . '</tr>'
            . '</table>';

        $greeting = sprintf(
            'Hi there, here are your weekly stats on <span style="font-weight:700; color:#000;">%s</span>',
            esc_html( wp_parse_url( $site_url, PHP_URL_HOST ) ? wp_parse_url( $site_url, PHP_URL_HOST ) : $site_name )
        );

        $html  = '<!doctype html><html><head><meta charset="utf-8"></head><body style="margin:0; padding:0; background:#ffffff;">';
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#ffffff;">';
        $html .= '<tr><td align="center" style="padding: 24px 12px;">';

        // Outer container.
        $html .= '<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:600px; max-width:600px;">';

        // Header (logo).
        $html .= '<tr><td align="center" style="padding: 12px 0 6px;">' . $logo_html . '</td></tr>';

        // Greeting line.
        $html .= '<tr><td align="center" style="padding: 6px 0 18px; font-family: Arial, sans-serif; font-size: 14px; color:#6B7280;">' . $greeting . '</td></tr>';

        // Yellow stats block.
        $html .= '<tr><td align="center">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . $yellow . '; border-radius: 0px;">'
            . '<tr><td align="center" style="padding: 28px 20px 24px; font-family: Arial, sans-serif;">'
            . '<div style="font-size: 48px; line-height: 52px; font-weight: 900; color:#ffffff;">Your <span style="font-weight:900;">weekly</span> stats</div>'
            . '</td></tr>';

        // Cards row 1.
        $html .= '<tr><td align="center" style="padding: 0 20px 14px;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>'
            . $this->email_stat_card( '👍', $visibility_percent, 'Visibility Score', 'Trend: ' . esc_html( $trend_arrow ) , $light )
            . $this->email_stat_card( '👎', $site_rank, 'Site Rank', '', $light )
            . '</tr></table>'
            . '</td></tr>';

        // Cards row 2.
        $html .= '<tr><td align="center" style="padding: 0 20px 22px;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>'
            . $this->email_stat_card( '👍', $ai_visits, 'AI Visits', '', $light )
            . $this->email_stat_card( '👎', $crawler_visits, 'Crawler Visits', '', $light )
            . '</tr></table>'
            . '</td></tr>';

        // CTA.
        $html .= '<tr><td align="center" style="padding: 0 20px 10px; font-family: Arial, sans-serif; font-size: 13px; color:#1f2937;">See the full reports on your website dashboard:</td></tr>';
        $html .= '<tr><td align="center" style="padding: 0 20px 28px;">'
            . '<a href="' . esc_url( $dashboard_url ) . '" style="display:inline-block; background:' . $teal . '; color:#ffffff; text-decoration:none; font-family: Arial, sans-serif; font-weight:700; font-size: 14px; padding: 12px 28px; border-radius: 4px;">Open Dashboard</a>'
            . '</td></tr>';

        $html .= '</table>'
            . '</td></tr>';

        // End container.
        $html .= '</table>';
        $html .= '</td></tr></table>';
        $html .= '</body></html>';

        return $html;
    }

    /**
     * Render a single stat card as a table cell.
     */
    protected function email_stat_card( $icon, $value, $label, $sub, $bg ) {
        $value = esc_html( $value );
        $label = esc_html( $label );
        $sub   = trim( (string) $sub );

        $sub_html = '';
        if ( '' !== $sub ) {
            $sub_html = '<div style="font-size: 12px; color:#6B7280; margin-top: 4px;">' . esc_html( $sub ) . '</div>';
        }

        return '<td width="50%" align="center" style="padding: 10px;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . esc_attr( $bg ) . '; border-radius: 14px;">'
            . '<tr><td align="center" style="padding: 18px 10px; font-family: Arial, sans-serif;">'
            . '<div style="font-size: 26px; line-height: 26px;">' . esc_html( $icon ) . '</div>'
            . '<div style="font-size: 34px; line-height: 38px; font-weight: 900; color:#000; margin-top: 6px;">' . $value . '</div>'
            . $sub_html
            . '<div style="font-size: 14px; font-weight: 800; color:#000; margin-top: 6px;">' . $label . '</div>'
            . '</td></tr></table>'
            . '</td>';
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
