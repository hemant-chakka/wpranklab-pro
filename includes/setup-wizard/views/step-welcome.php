<?php
$org = (string) get_option('wprl_org_type','');
$biz = (string) get_option('wprl_business_name','');
$web = (string) get_option('wprl_website_name','');
?>
<div class="wprl-wiz-titleRow">
  <div class="wprl-wiz-num">1</div>
  <div>
    <h2 class="wprl-wiz-h1">Start Optimizing</h2>
    <div class="wprl-wiz-desc">Let’s get started. In order to improve your SEO we’ll need some details about your business.</div>
  </div>
</div>

<div class="wprl-field">
  <label for="wprl_org_type">Organization Type</label>
  <select id="wprl_org_type" name="wprl_org_type">
    <option value=""><?php echo esc_html__('Full (Best Quality)','wpranklab'); ?></option>
    <option value="agency" <?php selected($org,'agency'); ?>>Agency</option>
    <option value="local" <?php selected($org,'local'); ?>>Local Business</option>
    <option value="ecom" <?php selected($org,'ecom'); ?>>eCommerce</option>
  </select>
</div>

<div class="wprl-field">
  <label for="wprl_business_name">Business Name</label>
  <input type="text" id="wprl_business_name" name="wprl_business_name" value="<?php echo esc_attr($biz); ?>">
</div>

<div class="wprl-field">
  <label for="wprl_website_name">Website Name</label>
  <input type="text" id="wprl_website_name" name="wprl_website_name" value="<?php echo esc_attr($web); ?>">
</div>
