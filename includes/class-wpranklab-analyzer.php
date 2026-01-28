<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Computes a simple AI Visibility score and stores metrics per post.
 */
class WPRankLab_Analyzer {
    
    /**
     * Singleton instance.
     *
     * @var WPRankLab_Analyzer|null
     */
    protected static $instance = null;
    
    /**
     * Get singleton instance.
     *
     * @return WPRankLab_Analyzer
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * Handle save_post to auto-analyze supported post types.
     *
     * @param int     $post_id
     * @param WP_Post $post
     */
    public function handle_save_post( $post_id, $post ) {
        // Skip autosaves and revisions.
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }
        
        // Only analyze certain post types (filterable).
        $allowed_types = apply_filters(
            'wpranklab_analyzer_post_types',
            array( 'post', 'page' )
            );
        
        if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
            return;
        }
        
        if ( in_array( $post->post_status, array( 'auto-draft', 'trash' ), true ) ) {
            return;
        }
        
        $this->analyze_post( $post_id );
    }
    
    /**
     * Analyze a post and store AI visibility metrics.
     *
     * @param int $post_id
     *
     * @return array|null
     */
    public function analyze_post( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return null;
        }
        
        $content = (string) $post->post_content;
        $title   = (string) $post->post_title;
        
        $metrics = array();
        
        // Combined plain text for some metrics.
        $text = wp_strip_all_tags( $title . ' ' . $content );
        
        // Word count.
        $words      = preg_split( '/\s+/', trim( $text ) );
        $word_count = ( ! empty( $words ) && '' !== $words[0] ) ? count( $words ) : 0;
        $metrics['word_count'] = $word_count;
        
        // Headings count (H2 / H3).
        $metrics['h2_count'] = preg_match_all( '/<h2\b[^>]*>/i', $content, $dummy1 );
        $metrics['h3_count'] = preg_match_all( '/<h3\b[^>]*>/i', $content, $dummy2 );
        
        // Internal links.
        // preserve existing helper usage (returns int)
        $metrics['internal_links'] = $this->count_internal_links( $content );
        
        // External links (best-effort).
        if ( preg_match_all( '/<a\b[^>]+href=["\']https?:\/\/[^"\']+["\'][^>]*>/i', $content, $ext_matches ) ) {
            $metrics['external_links'] = count( $ext_matches[0] );
        } else {
            $metrics['external_links'] = 0;
        }
        
        // Q&A / FAQ signals.
        $metrics['question_marks']    = substr_count( $content, '?' );
        $metrics['has_faq_keyword']   = (int) ( false !== stripos( $content, 'FAQ' ) || false !== stripos( $content, 'Frequently Asked Questions' ) );
        
        // Rough readability proxy.
        $metrics['avg_sentence_length'] = $this->estimate_avg_sentence_length( $text );
        
        // AI Summary presence (postmeta key used elsewhere: _wpranklab_ai_summary)
        $summary_meta = get_post_meta( $post_id, '_wpranklab_ai_summary', true );
        $metrics['has_ai_summary'] = ! empty( $summary_meta );
        
        // AI Q&A block presence (postmeta key used elsewhere: _wpranklab_ai_qa_block)
        $qa_meta = get_post_meta( $post_id, '_wpranklab_ai_qa_block', true );
        $metrics['has_ai_qa'] = ! empty( $qa_meta );
        
        // Compute score 0–100.
        $score            = $this->compute_score( $metrics );
        $metrics['score'] = $score;
        
        update_post_meta( $post_id, '_wpranklab_visibility_score', $score );
        update_post_meta( $post_id, '_wpranklab_visibility_last_run', current_time( 'mysql' ) );
        update_post_meta( $post_id, '_wpranklab_visibility_data', $metrics );

        // Maintain per-post score history for the weekly trend + delta UI.
        // Stored as an array of {date: Y-m-d, score: int}.
        $this->append_visibility_history( $post_id, (int) $score, current_time( 'Y-m-d' ) );
        
        // Pro-only: entity extraction & graph base layer.
        if ( class_exists( 'WPRankLab_Entities' ) && function_exists( 'wpranklab_is_pro_active' ) && wpranklab_is_pro_active() ) {
            WPRankLab_Entities::get_instance()->analyze_post_entities( $post_id, $metrics );
        }
        
        
        
        /**
         * Fires after a post has been analyzed by WPRankLab.
         *
         * @param int   $post_id
         * @param array $metrics
         */
        do_action( 'wpranklab_after_analyze_post', $post_id, $metrics );
        
        return $metrics;
    }

    /**
     * Append (or replace) a per-post daily score snapshot.
     * Stored in postmeta key _wpranklab_visibility_history.
     *
     * @param int    $post_id Post ID.
     * @param int    $score   Score 0-100.
     * @param string $date    Date in Y-m-d.
     */
    protected function append_visibility_history( $post_id, $score, $date ) {
        $post_id = (int) $post_id;
        $score   = (int) $score;
        $date    = (string) $date;

        if ( $post_id <= 0 || '' === $date ) {
            return;
        }

        $key = '_wpranklab_visibility_history';
        $raw = get_post_meta( $post_id, $key, true );

        // WordPress may return an unserialized array, or a JSON string in some edge cases.
        $history = array();
        if ( is_array( $raw ) ) {
            $history = $raw;
        } elseif ( is_string( $raw ) && '' !== $raw ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                $history = $decoded;
            }
        }

        // Reindex by date so we keep 1 entry per day.
        $by_date = array();
        foreach ( (array) $history as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $d = isset( $row['date'] ) ? (string) $row['date'] : '';
            $s = isset( $row['score'] ) ? (int) $row['score'] : null;
            if ( '' === $d || null === $s ) {
                continue;
            }
            $by_date[ $d ] = array( 'date' => $d, 'score' => $s );
        }

        $by_date[ $date ] = array( 'date' => $date, 'score' => $score );

        ksort( $by_date );
        $history = array_values( $by_date );

        // Keep last 60 entries.
        if ( count( $history ) > 60 ) {
            $history = array_slice( $history, -60 );
        }

        update_post_meta( $post_id, $key, $history );
    }

    
    /**
     * Count internal links in HTML content.
     *
     * @param string $content
     *
     * @return int
     */
    protected function count_internal_links( $content ) {
        $count = 0;
        
        if ( empty( $content ) ) {
            return 0;
        }
        
        $home = home_url();
        $host = wp_parse_url( $home, PHP_URL_HOST );
        
        if ( preg_match_all( '/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches ) ) {
            foreach ( $matches[1] as $href ) {
                $href = trim( $href );
                if ( '' === $href ) {
                    continue;
                }
                
                // Relative URLs count as internal.
                if ( 0 === strpos( $href, '/' ) ) {
                    $count++;
                    continue;
                }
                
                $h = wp_parse_url( $href, PHP_URL_HOST );
                if ( $h && $host && strtolower( $h ) === strtolower( $host ) ) {
                    $count++;
                }
            }
        }
        
        return $count;
    }
    
    /**
     * Estimate average sentence length as a rough readability proxy.
     *
     * @param string $text
     *
     * @return float
     */
    protected function estimate_avg_sentence_length( $text ) {
        $text = trim( $text );
        if ( '' === $text ) {
            return 0;
        }
        
        // Split by ., !, ? boundaries.
        $parts     = preg_split( '/[.!?]+/', $text );
        $sentences = array_filter( array_map( 'trim', (array) $parts ) );
        
        if ( empty( $sentences ) ) {
            return 0;
        }
        
        $wc               = str_word_count( $text );
        $sentence_count   = count( $sentences );
        if ( 0 === $sentence_count ) {
            return 0;
        }
        
        return $wc / $sentence_count;
    }
    
    /**
     * Compute a simple heuristic score from metrics.
     *
     * @param array $m
     *
     * @return int
     */
    protected function compute_score( $m ) {
        $score = 0;
        
        // 1) Word count (content depth).
        $wc = isset( $m['word_count'] ) ? (int) $m['word_count'] : 0;
        if ( $wc >= 300 ) {
            $score += 30;
        } elseif ( $wc >= 150 ) {
            $score += 20;
        } elseif ( $wc >= 80 ) {
            $score += 10;
        }
        
        // 2) Headings (structure).
        $headings = (int) ( $m['h2_count'] ?? 0 ) + (int) ( $m['h3_count'] ?? 0 );
        if ( $headings >= 4 ) {
            $score += 20;
        } elseif ( $headings >= 2 ) {
            $score += 12;
        } elseif ( $headings >= 1 ) {
            $score += 6;
        }
        
        // 3) Internal links (context & crawlability).
        $internal = (int) ( $m['internal_links'] ?? 0 );
        if ( $internal >= 8 ) {
            $score += 20;
        } elseif ( $internal >= 4 ) {
            $score += 12;
        } elseif ( $internal >= 2 ) {
            $score += 6;
        }
        
        // 4) Q&A / FAQ signals.
        $questions = (int) ( $m['question_marks'] ?? 0 );
        $has_faq   = ! empty( $m['has_faq_keyword'] );
        if ( $questions >= 3 || $has_faq ) {
            $score += 15;
        } elseif ( $questions >= 1 ) {
            $score += 8;
        }
        
        // 5) Readability via avg sentence length (Goldilocks zone).
        $asl = (float) ( $m['avg_sentence_length'] ?? 0 );
        if ( $asl > 0 ) {
            if ( $asl >= 12 && $asl <= 25 ) {
                $score += 15;
            } elseif ( $asl >= 8 && $asl <= 30 ) {
                $score += 8;
            }
        }
        
        if ( $score > 100 ) {
            $score = 100;
        }
        
        /**
         * Filter the final visibility score.
         *
         * @param int   $score
         * @param array $m
         */
        $score = (int) apply_filters( 'wpranklab_visibility_score', $score, $m );
        
        return max( 0, min( 100, $score ) );
    }
    
    /**
     * Convert metrics into human-friendly “signals” for the checklist.
     *
     * Backwards compatible:
     * - Old call:  get_signals_for_post( $metrics )
     * - New call:  get_signals_for_post( $post_id, $metrics )
     *
     * @param mixed $arg1  Either $metrics (array) or $post_id (int)
     * @param mixed $arg2  Optional $metrics (array) when $arg1 is post_id
     *
     * @return array[]
     */
    public static function get_signals_for_post( $arg1, $arg2 = null ) {
        
        // --- Determine $post_id and $metrics based on how this was called ---
        if ( is_array( $arg1 ) && null === $arg2 ) {
            // Old style: get_signals_for_post( $metrics )
            $post_id = 0;
            $metrics = $arg1;
        } else {
            // New style: get_signals_for_post( $post_id, $metrics )
            $post_id = (int) $arg1;
            $metrics = is_array( $arg2 ) ? $arg2 : array();
        }
        
        $defaults = array(
            'word_count'      => 0,
            'h2_count'        => 0,
            'internal_links'  => 0,
            'has_ai_qa'       => false,
            'has_ai_summary'  => false,
        );
        $metrics = wp_parse_args( $metrics, $defaults );
        
        $signals = array();
        
        // 1) Word count
        if ( $metrics['word_count'] < 200 ) {
            $signals[] = array(
                'status' => 'red',
                'text'   => __( 'Content is too short for AI to understand well.', 'wpranklab' ),
            );
        } elseif ( $metrics['word_count'] < 500 ) {
            $signals[] = array(
                'status' => 'orange',
                'text'   => __( 'Content is a bit short; consider adding more detail.', 'wpranklab' ),
            );
        } else {
            $signals[] = array(
                'status' => 'green',
                'text'   => __( 'Content length is good.', 'wpranklab' ),
            );
        }
        
        // 2) H2 headings
        if ( ! empty( $metrics['h2_count'] ) && $metrics['h2_count'] > 0 ) {
            $signals[] = array(
                'status' => 'green',
                'text'   => __( 'Good use of H2 headings.', 'wpranklab' ),
            );
        } else {
            $signals[] = array(
                'status' => 'orange',
                'text'   => __( 'Add at least one H2 heading to structure your content.', 'wpranklab' ),
            );
        }
        
        // 3) Internal links
        if ( $metrics['internal_links'] < 1 ) {
            $signals[] = array(
                'status' => 'red',
                'text'   => __( 'No internal links found. Add links to related posts.', 'wpranklab' ),
            );
        } else {
            $signals[] = array(
                'status' => 'green',
                'text'   => __( 'Internal linking looks good.', 'wpranklab' ),
            );
        }
        
        // 4) Q&A presence
        if ( empty( $metrics['has_ai_qa'] ) ) {
            $signals[] = array(
                'status' => 'orange',
                'text'   => __( 'No Q&A / FAQ content detected. AI prefers FAQ-style signals.', 'wpranklab' ),
            );
        } else {
            $signals[] = array(
                'status' => 'green',
                'text'   => __( 'Q&A content detected.', 'wpranklab' ),
            );
        }
        
        // 5) AI summary presence
        if ( empty( $metrics['has_ai_summary'] ) ) {
            $signals[] = array(
                'status' => 'orange',
                'text'   => __( 'No AI summary yet. AI summaries boost visibility.', 'wpranklab' ),
            );
        } else {
            $signals[] = array(
                'status' => 'green',
                'text'   => __( 'AI summary exists.', 'wpranklab' ),
            );
        }
        
        // 6) Entity clarity (Pro-only, only when we know $post_id).
        if ( $post_id > 0
            && class_exists( 'WPRankLab_Entities' )
            && function_exists( 'wpranklab_is_pro_active' )
            && wpranklab_is_pro_active()
            ) {
                $entities_service  = WPRankLab_Entities::get_instance();
                $entities_for_post = $entities_service->get_entities_for_post( $post_id );
                
                $entity_count  = is_array( $entities_for_post ) ? count( $entities_for_post ) : 0;
                $main_entities = 0;
                
                if ( $entity_count > 0 ) {
                    foreach ( $entities_for_post as $entity ) {
                        if ( isset( $entity['role'] ) && 'main' === $entity['role'] ) {
                            $main_entities++;
                        }
                    }
                }
                
                if ( 0 === $entity_count ) {
                    $signals[] = array(
                        'status' => 'red',
                        'text'   => __( 'No clear entities detected. Focus the content on a primary topic or entity.', 'wpranklab' ),
                    );
                } elseif ( $entity_count > 0 && $entity_count <= 6 && $main_entities >= 1 ) {
                    $signals[] = array(
                        'status' => 'green',
                        'text'   => __( 'Entity focus looks good. AI can clearly identify the main topic.', 'wpranklab' ),
                    );
                } elseif ( $entity_count > 10 ) {
                    $signals[] = array(
                        'status' => 'orange',
                        'text'   => __( 'Many entities detected. Consider tightening focus on 1–3 main topics.', 'wpranklab' ),
                    );
                } else {
                    $signals[] = array(
                        'status' => 'orange',
                        'text'   => __( 'Entities detected, but the main topic could be clearer. Emphasize your primary entity.', 'wpranklab' ),
                    );
                }
            }
            
            return $signals;
    }
    

}
