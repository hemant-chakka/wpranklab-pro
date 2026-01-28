<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Pro: Schema recommendation engine (deterministic).
 *
 * Generates schema recommendations and JSON-LD templates.
 * Runs only on manual scans (flagged by a transient).
 */
class WPRankLab_Schema {
    
    protected static $instance = null;
    
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function init() {
        add_action( 'wpranklab_after_analyze_post', array( $this, 'maybe_generate_recommendations' ), 30, 2 );
        add_action( 'wp_head', array( $this, 'output_enabled_schema' ), 20 );
        
    }
    
    /**
     * Only run on manual scans (to avoid doing heavier work on every save).
     */
    public function maybe_generate_recommendations( $post_id, $metrics ) {
        
        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) {
            return;
        }
        
        if ( ! function_exists( 'wpranklab_is_pro_active' ) || ! wpranklab_is_pro_active() ) {
            return;
        }
        
        // Manual scan flag (use the same style as Missing Topics).
        $flag_key = 'wpranklab_force_schema_' . $post_id;
        $forced   = (bool) get_transient( $flag_key );
        
        if ( ! $forced ) {
            return;
        }
        
        delete_transient( $flag_key );
        
        $post = get_post( $post_id );
        if ( ! $post ) {
            return;
        }
        
        $content = (string) $post->post_content;
        $title   = (string) $post->post_title;
        
        $existing = $this->detect_existing_schema( $content );
        $reco     = $this->build_recommendations( $post, $content, $metrics, $existing );
        
        update_post_meta( $post_id, '_wpranklab_schema_recommendations', $reco );
        update_post_meta( $post_id, '_wpranklab_schema_last_run', current_time( 'mysql' ) );
    }
    
    /**
     * Best-effort schema detection from content.
     */
    protected function detect_existing_schema( $content ) {
        $found = array(
            'faq'    => false,
            'howto'  => false,
            'article'=> false,
        );
        
        // Very rough heuristics:
        // - JSON-LD presence
        if ( preg_match( '/application\/ld\+json/i', $content ) ) {
            // Not enough to classify, but indicates some schema may already exist.
            // We'll still recommend if our specific types aren't detected.
        }
        
        // - FAQ block / FAQ keywords
        if ( preg_match( '/FAQPage/i', $content ) || preg_match( '/wp:yoast\/faq-block|schema\.org\/FAQPage/i', $content ) ) {
            $found['faq'] = true;
        }
        
        // - HowTo schema markers or step-like patterns
        if ( preg_match( '/HowTo/i', $content ) || preg_match( '/schema\.org\/HowTo/i', $content ) ) {
            $found['howto'] = true;
        }
        
        // - Article schema markers
        if ( preg_match( '/schema\.org\/Article|NewsArticle|BlogPosting/i', $content ) ) {
            $found['article'] = true;
        }
        
        return $found;
    }
    
    /**
     * Build recommendations + JSON-LD templates.
     */
    protected function build_recommendations( $post, $content, $metrics, $existing ) {
        
        $post_id = (int) $post->ID;
        
        $reco = array(
            'existing' => $existing,
            'recommended' => array(), // list of items: type, reason, jsonld
        );
        
        // Always recommend Article (unless already present).
        if ( empty( $existing['article'] ) ) {
            $reco['recommended'][] = array(
                'type'   => 'Article',
                'reason' => __( 'Most AI search engines benefit from clear Article metadata (headline, author, dates).', 'wpranklab' ),
                'jsonld' => $this->jsonld_article( $post ),
            );
        }
        
        // Recommend FAQPage if we detect Q&A signals (from metrics or content) and not already present.
        $has_qa_signal = ! empty( $metrics['has_ai_qa'] ) || ( isset( $metrics['question_marks'] ) && (int) $metrics['question_marks'] >= 2 );
        $looks_like_faq = ( false !== stripos( $content, 'faq' ) ) || preg_match( '/<h2[^>]*>.*\?/i', $content );
        
        if ( empty( $existing['faq'] ) && ( $has_qa_signal || $looks_like_faq ) ) {
            $reco['recommended'][] = array(
                'type'   => 'FAQPage',
                'reason' => __( 'FAQ schema helps AI extract Q&A pairs and improves answer-style visibility.', 'wpranklab' ),
                'jsonld' => ( $this->jsonld_faq_autofill( $post_id ) ?: $this->jsonld_faq_template( $post_id ) ),
                
            );
        }
        
        // Recommend HowTo if we detect steps.
        $looks_like_steps =
        preg_match( '/\bstep\s*1\b/i', $content ) ||
        preg_match( '/<ol\b[^>]*>/i', $content ) ||
        preg_match( '/\bhow to\b/i', $content );
        
        if ( empty( $existing['howto'] ) && $looks_like_steps ) {
            $reco['recommended'][] = array(
                'type'   => 'HowTo',
                'reason' => __( 'HowTo schema makes step-by-step instructions explicit for AI and rich results.', 'wpranklab' ),
                'jsonld' => ( $this->jsonld_howto_autofill( $post_id ) ?: $this->jsonld_howto_template( $post_id ) ),
                
            );
        }
        
        return $reco;
    }
    
    protected function jsonld_article( $post ) {
        $post_id = (int) $post->ID;
        
        $data = array(
            '@context' => 'https://schema.org',
            '@type'    => 'Article',
            'headline' => get_the_title( $post_id ),
            'datePublished' => get_the_date( 'c', $post_id ),
            'dateModified'  => get_the_modified_date( 'c', $post_id ),
            'mainEntityOfPage' => get_permalink( $post_id ),
            'author' => array(
                '@type' => 'Person',
                'name'  => get_the_author_meta( 'display_name', $post->post_author ),
            ),
            'publisher' => array(
                '@type' => 'Organization',
                'name'  => get_bloginfo( 'name' ),
            ),
        );
        
        return wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
    }
    
    /**
     * FAQ template: user can replace placeholders with real Q&A or we can auto-fill later.
     */
    protected function jsonld_faq_template( $post_id ) {
        $data = array(
            '@context' => 'https://schema.org',
            '@type'    => 'FAQPage',
            'mainEntity' => array(
                array(
                    '@type' => 'Question',
                    'name'  => 'QUESTION_1',
                    'acceptedAnswer' => array(
                        '@type' => 'Answer',
                        'text'  => 'ANSWER_1',
                    ),
                ),
                array(
                    '@type' => 'Question',
                    'name'  => 'QUESTION_2',
                    'acceptedAnswer' => array(
                        '@type' => 'Answer',
                        'text'  => 'ANSWER_2',
                    ),
                ),
            ),
        );
        
        return wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
    }
    
    protected function jsonld_howto_template( $post_id ) {
        $data = array(
            '@context' => 'https://schema.org',
            '@type'    => 'HowTo',
            'name'     => get_the_title( $post_id ),
            'step'     => array(
                array(
                    '@type' => 'HowToStep',
                    'name'  => 'Step 1',
                    'text'  => 'Describe step 1',
                ),
                array(
                    '@type' => 'HowToStep',
                    'name'  => 'Step 2',
                    'text'  => 'Describe step 2',
                ),
            ),
        );
        
        return wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
    }
    
    /**
     * Frontend: output enabled JSON-LD for the current singular post.
     */
    public function output_enabled_schema() {
        
        // Pro gate + kill-switch safety
        if ( ! function_exists( 'wpranklab_is_pro_active' ) || ! wpranklab_is_pro_active() ) {
            return;
        }
        
        if ( ! is_singular() ) {
            return;
        }
        
        $post_id = get_queried_object_id();
        if ( ! $post_id ) {
            return;
        }
        
        $enabled = get_post_meta( $post_id, '_wpranklab_schema_enabled', true );
        if ( ! is_array( $enabled ) || empty( $enabled ) ) {
            return;
        }
        
        foreach ( $enabled as $type => $jsonld ) {
            $type  = sanitize_text_field( (string) $type );
            $json  = (string) $jsonld;
            
            if ( '' === $type || '' === $json ) {
                continue;
            }
            
            // Validate JSON before output
            $decoded = json_decode( $json, true );
            if ( ! is_array( $decoded ) ) {
                continue;
            }
            
            echo "\n" . '<script type="application/ld+json" data-wpranklab-schema="' . esc_attr( $type ) . '">';
            echo wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES );
            echo '</script>' . "\n";
        }
    }
    
    /**
     * Enable a schema item (stores JSON-LD under postmeta).
     */
    public function enable_schema_for_post( $post_id, $type, $jsonld ) {
        
        $post_id = (int) $post_id;
        $type    = sanitize_text_field( (string) $type );
        
        // If enabling HowTo, prefer auto-filled steps if available.
        if ( 'HowTo' === $type ) {
            $auto = $this->jsonld_howto_autofill( $post_id );
            if ( '' !== $auto ) {
                $jsonld = $auto;
            }
        }
        
        
        $jsonld  = (string) $jsonld;
        
        if ( $post_id <= 0 || '' === $type || '' === $jsonld ) {
            return false;
        }
        
        $decoded = json_decode( $jsonld, true );
        if ( ! is_array( $decoded ) ) {
            return false;
        }
        
        $enabled = get_post_meta( $post_id, '_wpranklab_schema_enabled', true );
        if ( ! is_array( $enabled ) ) {
            $enabled = array();
        }
        
        $enabled[ $type ] = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
        update_post_meta( $post_id, '_wpranklab_schema_enabled', $enabled );
        
        return true;
    }
    
    /**
     * Disable a schema item.
     */
    public function disable_schema_for_post( $post_id, $type ) {
        
        $post_id = (int) $post_id;
        $type    = sanitize_text_field( (string) $type );
        
        if ( $post_id <= 0 || '' === $type ) {
            return false;
        }
        
        $enabled = get_post_meta( $post_id, '_wpranklab_schema_enabled', true );
        if ( ! is_array( $enabled ) ) {
            return false;
        }
        
        if ( isset( $enabled[ $type ] ) ) {
            unset( $enabled[ $type ] );
            update_post_meta( $post_id, '_wpranklab_schema_enabled', $enabled );
        }
        
        return true;
    }
    
    /**
     * Try to extract FAQ Q&A pairs from postmeta or content.
     *
     * Returns array of pairs:
     * [
     *   ['q' => 'Question?', 'a' => 'Answer...'],
     *   ...
     * ]
     */
    protected function extract_faq_pairs_for_post( $post_id ) {
        
        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) {
            return array();
        }
        
        // --- 1) Try postmeta keys (robust: supports different versions) ---
        $candidate_keys = array(
            '_wpranklab_ai_qa',           // common
            '_wpranklab_ai_qna',
            '_wpranklab_ai_qa_data',
            '_wpranklab_qa',
            '_wpranklab_qna',
            '_wpranklab_ai_qa_block',     // if saved block payload
            'wpranklab_ai_qa',            // non-underscored
        );
        
        foreach ( $candidate_keys as $key ) {
            $raw = get_post_meta( $post_id, $key, true );
            $pairs = $this->normalize_qa_storage_to_pairs( $raw );
            if ( ! empty( $pairs ) ) {
                return $pairs;
            }
        }
        
        // --- 2) Fallback: parse content (best effort) ---
        $post = get_post( $post_id );
        if ( ! $post ) {
            return array();
        }
        
        $content = (string) $post->post_content;
        $pairs   = $this->extract_qa_pairs_from_content( $content );
        
        return $pairs;
    }
    
    /**
     * Normalize different stored QA formats into pairs.
     *
     * Accepts:
     * - array of ['question'=>..,'answer'=>..]
     * - array of ['q'=>..,'a'=>..]
     * - array of arrays/objects
     * - JSON string of above
     * - string containing Q:/A: patterns
     */
    protected function normalize_qa_storage_to_pairs( $raw ) {
        
        if ( empty( $raw ) ) {
            return array();
        }
        
        // If JSON string, decode it.
        if ( is_string( $raw ) ) {
            $trim = trim( $raw );
            if ( '' !== $trim && ( '{' === $trim[0] || '[' === $trim[0] ) ) {
                $decoded = json_decode( $trim, true );
                if ( is_array( $decoded ) ) {
                    $raw = $decoded;
                }
            }
        }
        
        $pairs = array();
        
        // Case: array already
        if ( is_array( $raw ) ) {
            
            // Some formats wrap in ['items'=>[...]] or ['qa'=>[...]]
            if ( isset( $raw['items'] ) && is_array( $raw['items'] ) ) {
                $raw = $raw['items'];
            } elseif ( isset( $raw['qa'] ) && is_array( $raw['qa'] ) ) {
                $raw = $raw['qa'];
            } elseif ( isset( $raw['questions'] ) && is_array( $raw['questions'] ) ) {
                $raw = $raw['questions'];
            }
            
            foreach ( $raw as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                
                $q = '';
                $a = '';
                
                if ( isset( $item['question'] ) ) $q = (string) $item['question'];
                if ( isset( $item['answer'] ) )   $a = (string) $item['answer'];
                
                if ( '' === $q && isset( $item['q'] ) ) $q = (string) $item['q'];
                if ( '' === $a && isset( $item['a'] ) ) $a = (string) $item['a'];
                
                // Sometimes: ['title'=>..., 'content'=>...]
                if ( '' === $q && isset( $item['title'] ) )   $q = (string) $item['title'];
                if ( '' === $a && isset( $item['content'] ) ) $a = (string) $item['content'];
                
                $q = trim( wp_strip_all_tags( $q ) );
                $a = trim( wp_strip_all_tags( $a ) );
                
                if ( '' !== $q && '' !== $a ) {
                    $pairs[] = array( 'q' => $q, 'a' => $a );
                }
            }
        }
        
        // Case: string with Q:/A:
        if ( empty( $pairs ) && is_string( $raw ) ) {
            $pairs = $this->extract_qa_pairs_from_text( $raw );
        }
        
        // Limit to a reasonable count for schema.
        if ( count( $pairs ) > 20 ) {
            $pairs = array_slice( $pairs, 0, 20 );
        }
        
        return $pairs;
    }
    
    /**
     * Extract Q/A from raw text patterns like:
     * Q: ...
     * A: ...
     */
    protected function extract_qa_pairs_from_text( $text ) {
        
        $text = (string) $text;
        $lines = preg_split( "/\r\n|\n|\r/", $text );
        
        $pairs = array();
        $q = '';
        $a = '';
        
        foreach ( $lines as $line ) {
            $line = trim( $line );
            
            if ( preg_match( '/^(Q:|Question:)\s*(.+)$/i', $line, $m ) ) {
                if ( '' !== $q && '' !== $a ) {
                    $pairs[] = array( 'q' => trim( $q ), 'a' => trim( $a ) );
                }
                $q = trim( $m[2] );
                $a = '';
                continue;
            }
            
            if ( preg_match( '/^(A:|Answer:)\s*(.+)$/i', $line, $m ) ) {
                $a = trim( $m[2] );
                continue;
            }
            
            // Continuations
            if ( '' !== $a ) {
                $a .= ' ' . $line;
            } elseif ( '' !== $q ) {
                $q .= ' ' . $line;
            }
        }
        
        if ( '' !== $q && '' !== $a ) {
            $pairs[] = array( 'q' => trim( $q ), 'a' => trim( $a ) );
        }
        
        return $pairs;
    }
    
    /**
     * Extract Q/A pairs from post content by looking for headings with '?' and following paragraphs.
     * Best-effort only.
     */
    protected function extract_qa_pairs_from_content( $content ) {
        
        $content = (string) $content;
        
        // Simple approach: split by headings and find question headings.
        // Works for many "FAQ" layouts.
        $pairs = array();
        
        // Normalize to plain text around headings.
        // We'll detect <h2>/<h3> etc with question marks.
        if ( preg_match_all( '/<(h2|h3|h4)[^>]*>(.*?)<\/\1>/is', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
            
            $headings = $matches[2]; // [ [text, offset], ... ]
            foreach ( $headings as $i => $h ) {
                $q_html = $h[0];
                $q      = trim( wp_strip_all_tags( $q_html ) );
                
                if ( '' === $q || false === strpos( $q, '?' ) ) {
                    continue;
                }
                
                // Take slice after this heading until next heading.
                $start = $matches[0][ $i ][1] + strlen( $matches[0][ $i ][0] );
                $end   = isset( $matches[0][ $i + 1 ] ) ? $matches[0][ $i + 1 ][1] : strlen( $content );
                
                $slice = substr( $content, $start, max( 0, $end - $start ) );
                $a     = trim( wp_strip_all_tags( $slice ) );
                
                // Keep first ~400 chars as answer (schema shouldn't be huge)
                if ( strlen( $a ) > 450 ) {
                    $a = substr( $a, 0, 450 );
                }
                
                $a = trim( preg_replace( '/\s+/', ' ', $a ) );
                
                if ( '' !== $a ) {
                    $pairs[] = array( 'q' => $q, 'a' => $a );
                }
                
                if ( count( $pairs ) >= 12 ) {
                    break;
                }
            }
        }
        
        return $pairs;
    }
    
    /**
     * Build real FAQPage JSON-LD from extracted pairs. Returns empty string if none.
     */
    protected function jsonld_faq_autofill( $post_id ) {
        
        $pairs = $this->extract_faq_pairs_for_post( $post_id );
        if ( empty( $pairs ) ) {
            return '';
        }
        
        $entities = array();
        
        foreach ( $pairs as $p ) {
            $q = isset( $p['q'] ) ? trim( (string) $p['q'] ) : '';
            $a = isset( $p['a'] ) ? trim( (string) $p['a'] ) : '';
            
            if ( '' === $q || '' === $a ) {
                continue;
            }
            
            $entities[] = array(
                '@type' => 'Question',
                'name'  => $q,
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text'  => $a,
                ),
            );
            
            if ( count( $entities ) >= 12 ) {
                break;
            }
        }
        
        if ( empty( $entities ) ) {
            return '';
        }
        
        $data = array(
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $entities,
        );
        
        return wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
    }
    
    /**
     * Extract HowTo steps from post content (best effort).
     *
     * Returns array of strings (step texts).
     */
    protected function extract_howto_steps_for_post( $post_id ) {
        
        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) {
            return array();
        }
        
        $post = get_post( $post_id );
        if ( ! $post ) {
            return array();
        }
        
        $content = (string) $post->post_content;
        
        // 1) Prefer ordered lists (<ol><li>...)
        $steps = $this->extract_steps_from_ordered_lists( $content );
        if ( ! empty( $steps ) ) {
            return $steps;
        }
        
        // 2) Fall back to "Step 1: ..." patterns
        $steps = $this->extract_steps_from_step_patterns( $content );
        if ( ! empty( $steps ) ) {
            return $steps;
        }
        
        return array();
    }
    
    /**
     * Extract steps from ordered lists in HTML content.
     */
    protected function extract_steps_from_ordered_lists( $content ) {
        
        $content = (string) $content;
        
        // Grab the first reasonably-sized <ol>...</ol>
        if ( ! preg_match_all( '/<ol\b[^>]*>(.*?)<\/ol>/is', $content, $ols ) ) {
            return array();
        }
        
        foreach ( $ols[1] as $ol_inner ) {
            
            if ( ! preg_match_all( '/<li\b[^>]*>(.*?)<\/li>/is', $ol_inner, $lis ) ) {
                continue;
            }
            
            $steps = array();
            
            foreach ( $lis[1] as $li_html ) {
                $text = trim( wp_strip_all_tags( $li_html ) );
                $text = preg_replace( '/\s+/', ' ', $text );
                $text = trim( $text );
                
                // Skip tiny bullets
                if ( strlen( $text ) < 8 ) {
                    continue;
                }
                
                // Keep each step compact for schema
                if ( strlen( $text ) > 280 ) {
                    $text = substr( $text, 0, 280 );
                    $text = rtrim( $text );
                }
                
                $steps[] = $text;
                
                if ( count( $steps ) >= 12 ) {
                    break;
                }
            }
            
            // Accept only if it looks like real steps
            if ( count( $steps ) >= 2 ) {
                return $steps;
            }
        }
        
        return array();
    }
    
    /**
     * Extract steps from plain text patterns like:
     * Step 1: ...
     * Step 2: ...
     */
    protected function extract_steps_from_step_patterns( $content ) {
        
        $plain = trim( wp_strip_all_tags( (string) $content ) );
        $plain = preg_replace( '/\s+/', ' ', $plain );
        
        // Split on Step N markers
        if ( ! preg_match_all( '/\bStep\s*(\d{1,2})\s*[:.\-]\s*/i', $plain, $m, PREG_OFFSET_CAPTURE ) ) {
            return array();
        }
        
        $markers = $m[0]; // each has [text, offset]
        $steps   = array();
        
        for ( $i = 0; $i < count( $markers ); $i++ ) {
            $start = $markers[$i][1] + strlen( $markers[$i][0] );
            $end   = isset( $markers[$i + 1] ) ? $markers[$i + 1][1] : strlen( $plain );
            
            $slice = trim( substr( $plain, $start, max( 0, $end - $start ) ) );
            if ( '' === $slice ) {
                continue;
            }
            
            // Trim to a reasonable length
            if ( strlen( $slice ) > 280 ) {
                $slice = substr( $slice, 0, 280 );
                $slice = rtrim( $slice );
            }
            
            $steps[] = $slice;
            
            if ( count( $steps ) >= 12 ) {
                break;
            }
        }
        
        return ( count( $steps ) >= 2 ) ? $steps : array();
    }
    
    /**
     * Build real HowTo JSON-LD from extracted steps. Returns empty string if none.
     */
    protected function jsonld_howto_autofill( $post_id ) {
        
        $steps = $this->extract_howto_steps_for_post( $post_id );
        if ( empty( $steps ) || count( $steps ) < 2 ) {
            return '';
        }
        
        $step_items = array();
        $n = 1;
        
        foreach ( $steps as $text ) {
            $text = trim( (string) $text );
            if ( '' === $text ) {
                continue;
            }
            
            $step_items[] = array(
                '@type' => 'HowToStep',
                'name'  => 'Step ' . $n,
                'text'  => $text,
            );
            
            $n++;
            
            if ( count( $step_items ) >= 12 ) {
                break;
            }
        }
        
        if ( count( $step_items ) < 2 ) {
            return '';
        }
        
        $data = array(
            '@context' => 'https://schema.org',
            '@type'    => 'HowTo',
            'name'     => get_the_title( $post_id ),
            'step'     => $step_items,
        );
        
        return wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
    }
    
    
    
}
