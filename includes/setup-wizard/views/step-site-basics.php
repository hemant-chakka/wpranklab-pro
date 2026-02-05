<?php
$settings = get_option( WPRANKLAB_OPTION_SETTINGS, array() );
if ( ! is_array($settings) ) { $settings = array(); }
$key   = isset($settings['openai_api_key']) ? (string)$settings['openai_api_key'] : '';
$mode  = isset($settings['ai_scan_mode']) ? (string)$settings['ai_scan_mode'] : 'full';
$cache = isset($settings['ai_cache_minutes']) ? (int)$settings['ai_cache_minutes'] : 0;
?>
<div class="wprl-wiz-titleRow">
  <div class="wprl-wiz-num">2</div>
  <div>
    <h2 class="wprl-wiz-h1">AI Integrations</h2>
    <div class="wprl-wiz-desc">WPRankLab integrates with OpenAI. Please enter your details to start getting detailed reports on your website. For advanced AI integration, <a href="#" onclick="return false;">upgrade to PRO</a>.</div>
  </div>
</div>

<div class="wprl-field">
  <label for="wprl_openai_api_key">OpenAI API Key</label>
  <input type="password" id="wprl_openai_api_key" name="wprl_openai_api_key" value="<?php echo esc_attr($key); ?>">
</div>

<div class="wprl-field">
  <label for="wprl_ai_scan_mode">AI Scan Mode</label>
  <select id="wprl_ai_scan_mode" name="wprl_ai_scan_mode">
    <option value="full" <?php selected('full',$mode); ?>>Full (Best Quality)</option>
    <option value="quick" <?php selected('quick',$mode); ?>>Quick (Lower tokens)</option>
  </select>
</div>

<div class="wprl-field">
  <label for="wprl_ai_cache_minutes">AI Response Cache (minutes)</label>
  <input type="number" id="wprl_ai_cache_minutes" name="wprl_ai_cache_minutes" value="<?php echo esc_attr($cache); ?>" min="0" max="10080" step="1">
</div>
