<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles entity extraction & storage (Pro-only).
 */
class WPRankLab_Entities {
    
    /**
     * @var WPRankLab_Entities|null
     */
    protected static $instance = null;
    
    /**
     * Singleton.
     *
     * @return WPRankLab_Entities
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Analyze entities for a given post, if Pro is active.
     *
     * @param int   $post_id
     * @param array $metrics Optional metrics from analyzer (for future use).
     */
    public function analyze_post_entities( $post_id, $metrics = array() ) {
        if ( ! function_exists( 'wpranklab_is_pro_active' ) || ! wpranklab_is_pro_active() ) {
            return;
        }
        
        $post = get_post( $post_id );
        if ( ! $post || 'publish' !== $post->post_status ) {
            return;
        }
        
        $title   = (string) $post->post_title;
        $content = (string) $post->post_content;
        
        $combined = $title . "\n\n" . wp_strip_all_tags( $content );
        
        $entities = $this->extract_entities_from_content( $combined );
        
        if ( empty( $entities ) || ! is_array( $entities ) ) {
            return;
        }
        
        $this->store_entities_for_post( $post_id, $entities );
    }
    
    /**
     * Get entities for a post (for UI / reports).
     *
     * @param int $post_id
     * @return array
     */
    public function get_entities_for_post( $post_id ) {
        global $wpdb;
        
        $entity_post_table = $wpdb->prefix . WPRANKLAB_TABLE_ENTITY_POST;
        $entities_table    = $wpdb->prefix . WPRANKLAB_TABLE_ENTITIES;
        
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT e.name, e.type, ep.role, ep.confidence
                 FROM {$entity_post_table} ep
                 INNER JOIN {$entities_table} e ON ep.entity_id = e.id
                 WHERE ep.post_id = %d
                 ORDER BY ep.confidence DESC",
                 $post_id
            ),
            ARRAY_A
            );
        
        return $rows ? $rows : array();
    }
    
    /**
     * Use OpenAI to extract entities in JSON form.
     *
     * @param string $content
     * @return array
     */
    protected function extract_entities_from_content( $content ) {
        $prompt = '
You are an NLP engine inside a WordPress plugin called WPRankLab.
            
Extract the most important real-world entities from the content below.
Entities may be: person, organization, product, brand, place, topic, event, technology, keyword.
            
Return ONLY valid JSON in this exact format:
            
{
  "entities": [
    { "name": "OpenAI", "type": "organization", "role": "main", "confidence": 96 },
    { "name": "ChatGPT", "type": "product", "role": "supporting", "confidence": 92 }
  ]
}
            
Rules:
- "name" must be the human-readable entity name.
- "type" must be one of: "person", "organization", "product", "brand", "place", "topic", "event", "technology", "keyword", "other".
- "role" must be one of: "main", "supporting", "mentioned".
- "confidence" is an integer 0–100.
            
Content:
"""' . $content . '"""';
        
        $raw = $this->call_openai_chat( $prompt );
        
        if ( is_wp_error( $raw ) ) {
            return array();
        }
        
        // Try direct JSON decode.
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            // Try to salvage JSON between first { and last } if model adds text.
            $start = strpos( $raw, '{' );
            $end   = strrpos( $raw, '}' );
            if ( false !== $start && false !== $end && $end > $start ) {
                $json = substr( $raw, $start, $end - $start + 1 );
                $data = json_decode( $json, true );
            }
        }
        
        if ( ! is_array( $data ) || empty( $data['entities'] ) || ! is_array( $data['entities'] ) ) {
            return array();
        }
        
        return $data['entities'];
    }
    
    /**
     * Minimal OpenAI chat call tailored for entity extraction.
     *
     * @param string $prompt
     * @return string|WP_Error
     */
    protected function call_openai_chat( $prompt ) {
        $settings = get_option( WPRANKLAB_OPTION_SETTINGS, array() );
        $api_key  = isset( $settings['openai_api_key'] ) ? trim( $settings['openai_api_key'] ) : '';
        
        if ( '' === $api_key ) {
            return new WP_Error( 'wpranklab_entities_no_key', __( 'OpenAI API key is not configured.', 'wpranklab' ) );
        }
        
        $endpoint = 'https://api.openai.com/v1/chat/completions';
        
        $body = array(
            'model'    => 'gpt-4.1-mini',
            'messages' => array(
                array(
                    'role'    => 'system',
                    'content' => 'You are a precise NLP engine that only outputs valid JSON for entities.',
                ),
                array(
                    'role'    => 'user',
                    'content' => $prompt,
                ),
            ),
            'temperature' => 0.1,
            'max_tokens'  => 600,
        );
        
        $args = array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'body'      => wp_json_encode( $body ),
            'timeout'   => 30,
        );
        
        $response = wp_remote_post( $endpoint, $args );
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error(
                'wpranklab_entities_http_error',
                sprintf( __( 'OpenAI API error: HTTP %d', 'wpranklab' ), $code )
                );
        }
        
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        
        if ( empty( $data['choices'][0]['message']['content'] ) ) {
            return new WP_Error(
                'wpranklab_entities_empty',
                __( 'OpenAI API returned an empty response for entities.', 'wpranklab' )
                );
        }
        
        return trim( (string) $data['choices'][0]['message']['content'] );
    }
    
    /**
     * Store entities for a post (replace old mappings).
     *
     * @param int   $post_id
     * @param array $entities
     */
    protected function store_entities_for_post( $post_id, array $entities ) {
        global $wpdb;
        
        $entities_table    = $wpdb->prefix . WPRANKLAB_TABLE_ENTITIES;
        $entity_post_table = $wpdb->prefix . WPRANKLAB_TABLE_ENTITY_POST;
        
        $now = current_time( 'mysql' );
        
        // Remove old mappings first.
        $wpdb->delete(
            $entity_post_table,
            array( 'post_id' => $post_id ),
            array( '%d' )
            );
        
        foreach ( $entities as $entity ) {
            $name       = isset( $entity['name'] ) ? trim( (string) $entity['name'] ) : '';
            $type       = isset( $entity['type'] ) ? sanitize_key( $entity['type'] ) : 'other';
            $role       = isset( $entity['role'] ) ? sanitize_key( $entity['role'] ) : 'mentioned';
            $confidence = isset( $entity['confidence'] ) ? (int) $entity['confidence'] : 80;
            
            if ( '' === $name ) {
                continue;
            }
            
            $slug = sanitize_title( $name );
            
            // Insert or find entity.
            $existing_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$entities_table} WHERE slug = %s AND type = %s LIMIT 1",
                    $slug,
                    $type
                )
                );
            
            if ( $existing_id ) {
                $entity_id = (int) $existing_id;
                
                $wpdb->update(
                    $entities_table,
                    array( 'updated_at' => $now ),
                    array( 'id' => $entity_id ),
                    array( '%s' ),
                    array( '%d' )
                    );
            } else {
                $wpdb->insert(
                    $entities_table,
                    array(
                        'name'       => $name,
                        'slug'       => $slug,
                        'type'       => $type,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ),
                    array( '%s', '%s', '%s', '%s', '%s' )
                    );
                
                $entity_id = (int) $wpdb->insert_id;
            }
            
            if ( ! $entity_id ) {
                continue;
            }
            
            $wpdb->insert(
                $entity_post_table,
                array(
                    'entity_id'  => $entity_id,
                    'post_id'    => $post_id,
                    'role'       => $role,
                    'confidence' => $confidence,
                    'first_seen' => $now,
                    'last_seen'  => $now,
                ),
                array( '%d', '%d', '%s', '%d', '%s', '%s' )
                );
        }
    }
}
