<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles AI-powered generation (summaries, Q&A blocks) via OpenAI.
 */
class WPRankLab_AI {
    
    /**
     * Singleton instance.
     *
     * @var WPRankLab_AI|null
     */
    protected static $instance = null;
    
    /**
     * Get singleton instance.
     *
     * @return WPRankLab_AI
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * Get OpenAI API key from plugin settings.
     *
     * @return string
     */
    public function get_api_key() {
        $settings = get_option( WPRANKLAB_OPTION_SETTINGS, array() );
        return isset( $settings['openai_api_key'] ) ? trim( (string) $settings['openai_api_key'] ) : '';
    }

    /**
     * Dev Mode: when enabled, NEVER call OpenAI.
     * Useful for UI + postmeta testing without burning tokens.
     *
     * @return bool
     */
    protected function is_dev_mode() {
        $settings = get_option( WPRANKLAB_OPTION_SETTINGS, array() );
        return ! empty( $settings['dev_mode'] );
    }

    /**
     * @return string 'quick'|'full'
     */
    protected function get_scan_mode() {
        $settings = get_option( WPRANKLAB_OPTION_SETTINGS, array() );
        $mode = isset( $settings['ai_scan_mode'] ) ? (string) $settings['ai_scan_mode'] : 'full';
        return in_array( $mode, array( 'quick', 'full' ), true ) ? $mode : 'full';
    }

    /**
     * @return int cache TTL in minutes (0 disables caching)
     */
    protected function get_cache_minutes() {
        $settings = get_option( WPRANKLAB_OPTION_SETTINGS, array() );
        $m = isset( $settings['ai_cache_minutes'] ) ? (int) $settings['ai_cache_minutes'] : 0;
        return max( 0, $m );
    }
    
    /**
     * Check whether AI generation is available (API key present).
     *
     * @return bool
     */
    public function is_available() {
        return '' !== $this->get_api_key();
    }
    
    /**
     * Generate an AI-friendly summary for a post.
     *
     * @param int $post_id
     *
     * @return string|WP_Error
     */
    public function generate_summary_for_post( $post_id ) {
        
        if ( ! wpranklab_is_pro_active() ) {
            return new WP_Error( 'pro_required', __( 'This is a Pro feature.', 'wpranklab' ) );
        }
        
        
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'wpranklab_ai_no_post', __( 'Post not found.', 'wpranklab' ) );
        }
        
        $title   = (string) $post->post_title;
        $content = (string) $post->post_content;
        
        $prompt = sprintf(
            "You are an assistant that writes concise, neutral summaries of web pages, optimized for AI search engines such as ChatGPT, Perplexity, Gemini, and Claude.\n\n" .
            "Write a clear summary of the following page in 3–6 sentences. Focus on:\n" .
            "- Main topic\n" .
            "- Key points\n" .
            "- Who it is for\n" .
            "- Why it is useful\n\n" .
            "Do not use headings, bullet lists, or HTML. Just plain text.\n\n" .
            "Title: %s\n\nContent:\n%s",
            $title,
            wp_strip_all_tags( $content )
            );
        
        return $this->call_chat_api( $prompt );
    }
    
    /**
     * Generate an AI Q&A / FAQ block for a post.
     *
     * @param int $post_id
     *
     * @return string|WP_Error
     */
    public function generate_qa_for_post( $post_id ) {
        
        if ( ! wpranklab_is_pro_active() ) {
            return new WP_Error( 'pro_required', __( 'This is a Pro feature.', 'wpranklab' ) );
        }
        
        
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'wpranklab_ai_no_post', __( 'Post not found.', 'wpranklab' ) );
        }
        
        $title   = (string) $post->post_title;
        $content = (string) $post->post_content;
        
        $prompt = sprintf(
            "You are an assistant that creates FAQ-style Q&A blocks to help AI search engines understand a page.\n\n" .
            "Based on the page below, create 3–6 of the most important question-and-answer pairs a user might ask.\n\n" .
            "Format your response exactly like this (plain text):\n" .
            "Q: Question 1\nA: Answer 1\nQ: Question 2\nA: Answer 2\n...\n\n" .
            "Do not add any extra commentary.\n\n" .
            "Title: %s\n\nContent:\n%s",
            $title,
            wp_strip_all_tags( $content )
            );
        
        return $this->call_chat_api( $prompt );
    }
    
    /**
     * Pro: Generate missing topic / coverage suggestions for a post.
     *
     * Returns an array:
     * [
     *   'missing_topics' => [
     *      ['topic' => '...', 'reason' => '...', 'priority' => 'high|medium|low']
     *   ],
     *   'suggested_questions' => ['...','...']
     * ]
     *
     * @param int   $post_id
     * @param array $entities_for_post
     *
     * @return array|WP_Error
     */
    public function generate_missing_topics_for_post( $post_id, $entities_for_post = array() ) {
        
        if ( ! wpranklab_is_pro_active() ) {
            return new WP_Error( 'pro_required', __( 'This is a Pro feature.', 'wpranklab' ) );
        }
        
        
        
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'wpranklab_ai_no_post', __( 'Post not found.', 'wpranklab' ) );
        }
        
        $title   = (string) $post->post_title;
        $content = (string) $post->post_content;
        
        // Keep prompt size reasonable.
        $plain = wp_strip_all_tags( $content );
        if ( strlen( $plain ) > 6000 ) {
            $plain = substr( $plain, 0, 6000 );
        }
        
        // Normalize entities into a compact list.
        $entity_labels = array();
        if ( is_array( $entities_for_post ) ) {
            foreach ( $entities_for_post as $e ) {
                $name = isset( $e['name'] ) ? (string) $e['name'] : '';
                $type = isset( $e['type'] ) ? (string) $e['type'] : '';
                if ( '' === $name ) {
                    continue;
                }
                $entity_labels[] = $type ? ( $name . ' (' . $type . ')' ) : $name;
            }
        }
        $entity_text = empty( $entity_labels ) ? 'None detected' : implode( ', ', array_slice( $entity_labels, 0, 25 ) );
        
        $prompt = sprintf(
            "You are an AI visibility auditor for AI search engines (ChatGPT, Gemini, Claude, Perplexity).\n\n" .
            "Task: Identify missing topical coverage for the page below. Suggest key missing subtopics / concepts that would improve AI understanding and answer completeness.\n\n" .
            "Rules:\n" .
            "- Output ONLY valid JSON.\n" .
            "- JSON schema:\n" .
            "{\n" .
            "  \"missing_topics\": [\n" .
            "    {\"topic\": \"...\", \"reason\": \"...\", \"priority\": \"high|medium|low\"}\n" .
            "  ],\n" .
            "  \"suggested_questions\": [\"...\", \"...\", \"...\"]\n" .
            "}\n" .
            "- Provide 4 to 8 missing_topics.\n" .
            "- Reasons should be short (max 1 sentence).\n" .
            "- Priorities should reflect impact on AI visibility.\n\n" .
            "Title: %s\n\n" .
            "Detected entities: %s\n\n" .
            "Content:\n%s",
            $title,
            $entity_text,
            $plain
            );
        
        $raw = $this->call_chat_api( $prompt );
        if ( is_wp_error( $raw ) ) {
            return $raw;
        }
        
        // Attempt to parse JSON safely (sometimes models wrap in text).
        $json = $this->extract_first_json_object( $raw );
        $data = json_decode( $json, true );
        
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'wpranklab_ai_bad_json', __( 'OpenAI returned invalid JSON for missing topics.', 'wpranklab' ) );
        }
        
        // Minimal normalization.
        if ( ! isset( $data['missing_topics'] ) || ! is_array( $data['missing_topics'] ) ) {
            $data['missing_topics'] = array();
        }
        if ( ! isset( $data['suggested_questions'] ) || ! is_array( $data['suggested_questions'] ) ) {
            $data['suggested_questions'] = array();
        }
        
        return $data;
    }
    
    /**
     * Generate an H2 section for a missing topic.
     *
     * @param int    $post_id
     * @param string $topic
     * @return string|WP_Error
     */
    public function generate_missing_topic_section( $post_id, $topic ) {
        
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'wpranklab_no_post', __( 'Post not found.', 'wpranklab' ) );
        }
        
        $title = (string) $post->post_title;
        
        $prompt = sprintf(
            "Write a concise, AI-friendly content section for the topic below.\n\n" .
            "Rules:\n" .
            "- Start with a clear H2 heading\n" .
            "- Follow with 1–2 short paragraphs\n" .
            "- Be factual, neutral, and helpful\n" .
            "- Do NOT mention AI, ChatGPT, or models\n\n" .
            "Post title: %s\n\n" .
            "Missing topic to cover: %s",
            $title,
            $topic
            );
        
        $text = $this->call_chat_api( $prompt );
        if ( is_wp_error( $text ) ) {
            return $text;
        }
        
        return trim( $text );
    }
    
    
    
    /**
     * Call OpenAI chat completion API (gpt-5.1-mini) with a simple text prompt.
     *
     * @param string $prompt
     *
     * @return string|WP_Error
     */
    protected function call_chat_api( $prompt ) {
        // Dev Mode: return deterministic fixtures and skip the network.
        if ( $this->is_dev_mode() ) {
            return $this->fixture_for_prompt( (string) $prompt );
        }

        // Cache identical prompts to reduce accidental token burn.
        $cache_minutes = $this->get_cache_minutes();
        $scan_mode     = $this->get_scan_mode();
        if ( $cache_minutes > 0 ) {
            $key = 'wpranklab_ai_' . md5( $scan_mode . '|' . (string) $prompt );
            $hit = get_transient( $key );
            if ( is_string( $hit ) && '' !== $hit ) {
                return $hit;
            }
        }

        $api_key = $this->get_api_key();
        if ( '' === $api_key ) {
            return new WP_Error( 'wpranklab_ai_no_key', __( 'OpenAI API key is not configured.', 'wpranklab' ) );
        }
        
        $endpoint = 'https://api.openai.com/v1/chat/completions';
        
        // Token-saver defaults for development.
        $scan_mode   = $this->get_scan_mode();
        $max_tokens  = ( 'quick' === $scan_mode ) ? 250 : 700;
        $temperature = ( 'quick' === $scan_mode ) ? 0.3 : 0.6;

        $body = array(
            'model'    => 'gpt-4.1-mini',
            'messages' => array(
                array(
                    'role'    => 'system',
                    'content' => 'You are a helpful assistant that writes concise, SEO-aware content for websites, optimized for AI-driven search engines.',
                ),
                array(
                    'role'    => 'user',
                    'content' => $prompt,
                ),
            ),
            'temperature' => $temperature,
            'max_tokens'  => $max_tokens,
        );
        
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 30,
        );
        
        $response = wp_remote_post( $endpoint, $args );
        
        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'wpranklab_ai_http_error',
                sprintf(
                    /* translators: %s: error message */
                    __( 'Error calling OpenAI API: %s', 'wpranklab' ),
                    $response->get_error_message()
                    )
                );
        }
        
        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );
        
        // If HTTP code is not 200, try to show the real OpenAI error.
        if ( 200 !== $code ) {
            $msg = 'HTTP ' . $code;
            
            if ( is_array( $data ) && isset( $data['error']['message'] ) ) {
                $msg .= ' - ' . $data['error']['message'];
            } elseif ( ! empty( $raw ) ) {
                // Fallback: show first part of the raw body for debugging.
                $msg .= ' - ' . substr( $raw, 0, 300 );
            }
            
            return new WP_Error(
                'wpranklab_ai_bad_response',
                $msg
                );
        }
        
        if ( empty( $data['choices'][0]['message']['content'] ) ) {
            return new WP_Error(
                'wpranklab_ai_bad_response',
                __( 'OpenAI API returned an empty response.', 'wpranklab' )
                );
        }
        
        $text = trim( (string) $data['choices'][0]['message']['content'] );

        // Save to cache if enabled.
        if ( isset( $key ) && $cache_minutes > 0 ) {
            set_transient( $key, $text, $cache_minutes * MINUTE_IN_SECONDS );
        }
        
        return $text;
        
    }

    /**
     * Generate a fixture response for a given prompt.
     *
     * @param string $prompt
     * @return string
     */
    protected function fixture_for_prompt( $prompt ) {
        $p = strtolower( $prompt );

        // Missing topics JSON fixture.
        if ( false !== strpos( $p, '"missing_topics"' ) || false !== strpos( $p, 'missing_topics' ) ) {
            return wp_json_encode(
                array(
                    'missing_topics' => array(
                        array( 'topic' => 'Pricing / cost breakdown', 'reason' => 'Users often ask cost-related questions.', 'priority' => 'high' ),
                        array( 'topic' => 'Step-by-step usage', 'reason' => 'Explicit steps improve clarity for assistants.', 'priority' => 'medium' ),
                        array( 'topic' => 'Common mistakes', 'reason' => 'Addresses frequent confusion points.', 'priority' => 'medium' ),
                        array( 'topic' => 'Alternatives & comparisons', 'reason' => 'Helps answer “vs” queries.', 'priority' => 'low' ),
                    ),
                    'suggested_questions' => array(
                        'What is this used for?',
                        'How do I set it up?',
                        'How much does it cost?',
                    ),
                )
            );
        }

        // QA block fixture.
        if ( false !== strpos( $p, 'format your response exactly like this' ) || false !== strpos( $p, 'q:' ) ) {
            return "Q: What is this page about?\nA: It explains the topic in a clear, structured way.\nQ: Who is it for?\nA: Readers looking for practical guidance and quick answers.\nQ: What are the key takeaways?\nA: The main points, steps, and common questions are covered.";
        }

        // Default: short summary fixture.
        return 'Fixture Mode: This is a placeholder AI response so you can test the UI, storage, and workflows without making any OpenAI API calls.';
    }
    
    /**
     * Extract the first JSON object from a string (best-effort).
     *
     * @param string $text
     * @return string
     */
    protected function extract_first_json_object( $text ) {
        $text = trim( (string) $text );
        
        // If it already starts with {, assume JSON.
        if ( '' !== $text && '{' === $text[0] ) {
            return $text;
        }
        
        $start = strpos( $text, '{' );
        $end   = strrpos( $text, '}' );
        
        if ( false !== $start && false !== $end && $end > $start ) {
            return substr( $text, $start, $end - $start + 1 );
        }
        
        return $text;
    }
    
}
