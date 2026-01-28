<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPRankLab_Internal_Links {
    
    protected static $instance = null;
    
    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }
    
    public function init() {
        add_action( 'wpranklab_after_analyze_post', array( $this, 'maybe_generate_suggestions' ), 40, 2 );
    }
    
    public function maybe_generate_suggestions( $post_id, $metrics ) {
        
        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) return;
        
        if ( ! function_exists( 'wpranklab_is_pro_active' ) || ! wpranklab_is_pro_active() ) return;
        
        // Manual scan only.
        $flag_key = 'wpranklab_force_internal_links_' . $post_id;
        if ( ! get_transient( $flag_key ) ) return;
        delete_transient( $flag_key );
        
        $suggestions = $this->build_suggestions( $post_id );
        
        update_post_meta( $post_id, '_wpranklab_internal_link_suggestions', $suggestions );
        update_post_meta( $post_id, '_wpranklab_internal_links_last_run', current_time( 'mysql' ) );
    }
    
    protected function build_suggestions( $post_id ) {
        
        $post = get_post( $post_id );
        $already_linked = $this->get_already_linked_post_ids( $post_id );
        
        if ( ! $post ) return array();
        
        $suggestions = array();
        
        // 1) Try entities-based suggestions (best).
        $entity_names = array();
        if ( class_exists( 'WPRankLab_Entities' ) ) {
            $entities = WPRankLab_Entities::get_instance()->get_entities_for_post( $post_id );
            if ( is_array( $entities ) ) {
                foreach ( $entities as $e ) {
                    if ( empty( $e['name'] ) ) continue;
                    $entity_names[] = sanitize_text_field( (string) $e['name'] );
                    if ( count( $entity_names ) >= 6 ) break;
                }
            }
        }
        
        if ( ! empty( $entity_names ) ) {
            $suggestions = $this->suggest_by_title_match( $post_id, $entity_names, 6, 'entity' );
        }
        
        // 2) Fallback: title keywords if we didn’t get enough.
        if ( count( $suggestions ) < 4 ) {
            $title = wp_strip_all_tags( (string) get_the_title( $post_id ) );
            $words = preg_split( '/\s+/', strtolower( $title ) );
            $words = array_values( array_filter( $words, function( $w ) {
                return strlen( $w ) >= 5;
            } ) );
                $words = array_slice( array_unique( $words ), 0, 6 );
                
                if ( ! empty( $words ) ) {
                    $more = $this->suggest_by_title_match( $post_id, $words, 6, 'keyword' );
                    $suggestions = $this->merge_unique_suggestions( $suggestions, $more, $already_linked, 8 );
                    
                }
        }
        
        return $suggestions;
    }
    
    protected function suggest_by_title_match( $post_id, $terms, $limit = 6, $mode = 'entity' ) {
        
        $terms = array_values( array_filter( array_map( 'trim', (array) $terms ) ) );
        if ( empty( $terms ) ) return array();
        
        // Build an OR query of terms against post titles.
        $like = array();
        global $wpdb;
        
        foreach ( $terms as $t ) {
            $t = sanitize_text_field( $t );
            if ( '' === $t ) continue;
            $like[] = $wpdb->prepare( "post_title LIKE %s", '%' . $wpdb->esc_like( $t ) . '%' );
        }
        if ( empty( $like ) ) return array();
        
        $sql = "
            SELECT ID, post_title
            FROM {$wpdb->posts}
            WHERE post_status = 'publish'
              AND post_type IN ('post','page')
              AND ID <> %d
              AND ( " . implode( ' OR ', $like ) . " )
            ORDER BY post_date DESC
            LIMIT %d
        ";
        
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $post_id, (int) $limit ), ARRAY_A );
        if ( ! is_array( $rows ) ) return array();
        
        $out = array();
        foreach ( $rows as $r ) {
            $id = (int) $r['ID'];
            $out[] = array(
                'target_id' => $id,
                'url'       => get_permalink( $id ),
                'title'     => get_the_title( $id ),
                'anchor'    => get_the_title( $id ),
                'reason'    => ( 'entity' === $mode )
                ? 'Shares key entities: ' . implode( ', ', array_slice( $terms, 0, 3 ) )
                : 'Similar topic keywords',
            );
        }
        
        return $out;
    }
    
    protected function merge_unique_suggestions( $a, $b, $already_linked, $max = 8 ) {
        $seen = array();
        $out  = array();
        
        foreach ( array_merge( (array) $a, (array) $b ) as $s ) {
            $tid = isset( $s['target_id'] ) ? (int) $s['target_id'] : 0;
            if ( $tid <= 0 || isset( $seen[ $tid ] ) || in_array( $tid, $already_linked, true ) ) {
                continue;
            }
            $seen[ $tid ] = true;
            $out[] = $s;
            if ( count( $out ) >= $max ) break;
        }
        return $out;
    }
    
    /**
     * Get post IDs already linked from this post.
     */
    protected function get_already_linked_post_ids( $post_id ) {
        
        $post = get_post( $post_id );
        if ( ! $post ) return array();
        
        $content = (string) $post->post_content;
        if ( '' === $content ) return array();
        
        $linked_ids = array();
        
        // Match href URLs
        if ( preg_match_all( '/href=["\']([^"\']+)["\']/i', $content, $matches ) ) {
            foreach ( $matches[1] as $url ) {
                $linked_post_id = url_to_postid( $url );
                if ( $linked_post_id ) {
                    $linked_ids[ (int) $linked_post_id ] = true;
                }
            }
        }
        
        return array_keys( $linked_ids );
    }
    
}
