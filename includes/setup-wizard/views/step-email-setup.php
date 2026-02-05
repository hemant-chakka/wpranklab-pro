<?php
$enabled = (int) get_option('wprl_weekly_reports_enabled', 0);
$email = (string) get_option('wprl_report_email','');
?>
<div class="wprl-wiz-titleRow">
  <div class="wprl-wiz-num">4</div>
  <div>
    <h2 class="wprl-wiz-h1">Email Reports</h2>
    <div class="wprl-wiz-desc">Enable weekly AI Visibility basic report emails. These will be directly sent week by week to your inbox. For advanced reports <a href="#" onclick="return false;">upgrade to PRO</a>.</div>
  </div>
</div>

<div class="wprl-field">
  <label for="wprl_weekly_reports_enabled">Enable Weekly Reports</label>
  <select id="wprl_weekly_reports_enabled" name="wprl_weekly_reports_enabled">
    <option value="1" <?php selected($enabled,1); ?>>Send weekly AI Visibility report emails.</option>
    <option value="0" <?php selected($enabled,0); ?>>Do not send weekly reports.</option>
  </select>
</div>

<div class="wprl-field">
  <label for="wprl_report_email">Your email address</label>
  <input type="email" id="wprl_report_email" name="wprl_report_email" value="<?php echo esc_attr($email); ?>" placeholder="johndoe@example.com">
</div>
