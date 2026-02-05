<?php
$labels = array(
  1 => 'Start Optimizing',
  2 => 'AI Integrations',
  3 => 'Run First Scan',
  4 => 'Email Reports'
);
$current = isset($step) ? (int)$step : 1;
if ( $current < 1 ) $current = 1;
if ( $current > 5 ) $current = 5;

$active_stage = ($current >= 5) ? 5 : max(1, $current);
$fill_map = array(1 => 0, 2 => 25, 3 => 50, 4 => 75, 5 => 100);
$fill = isset($fill_map[$current]) ? $fill_map[$current] : 0;
?>
<div class="wprl-wiz-container">
  <div class="wprl-wiz-logo">
    <img src="<?php echo esc_url( WPRANKLAB_PLUGIN_URL . 'assets/img/wpranklab-brand-logo.webp' ); ?>" alt="WPRankLab">
  </div>

  <div class="wprl-wiz-progress">
    <div class="wprl-wiz-progress-line"></div>
    <div class="wprl-wiz-progress-line-fill" style="width: <?php echo (int)$fill; ?>%;"></div>
    <ul class="wprl-wiz-steps">
      <?php for($i=1;$i<=4;$i++):
        $cls = ($active_stage > $i || ($active_stage === 4 && $i === 4)) ? 'done' : (($active_stage === $i) ? 'current' : '');
      ?>
      <li class="wprl-wiz-step <?php echo esc_attr($cls); ?>">
        <span class="dot"><?php echo ($active_stage > $i) ? '✓' : '•'; ?></span>
        <span class="label"><?php echo esc_html($labels[$i]); ?></span>
      </li>
      <?php endfor; ?>
    </ul>
  </div>

  <div class="wprl-wiz-card">
    <?php if ( $current === 5 ) : ?>
      <?php require WPRANKLAB_PLUGIN_DIR . 'includes/setup-wizard/views/step-finish.php'; ?>
    <?php else: ?>
    <div class="wprl-wiz-card-inner">
      <div>
        <?php
          $map = array(
            1 => 'step-welcome.php',
            2 => 'step-site-basics.php',
            3 => 'step-scan-config.php',
            4 => 'step-email-setup.php',
          );
          $step_file = WPRANKLAB_PLUGIN_DIR . 'includes/setup-wizard/views/' . $map[$current];
        ?>

        <?php if ( $current === 3 ) : ?>
          <?php require $step_file; ?>

          <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="margin:18px 0 0;">
            <?php wp_nonce_field('wprl_wizard_start_scan'); ?>
            <input type="hidden" name="action" value="wprl_wizard_start_scan">
            <button type="submit" class="wprl-scan-btn">Start Scanning</button>
          </form>

          <div class="wprl-wiz-btnbar">
            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
              <?php wp_nonce_field('wprl_setup_step_' . $current); ?>
              <input type="hidden" name="action" value="wprl_setup_save">
              <input type="hidden" name="wprl_step" value="<?php echo esc_attr($current); ?>">
              <button type="submit" class="wprl-wiz-next">NEXT STEP</button>
            </form>
          </div>

        <?php else : ?>
          <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
            <?php wp_nonce_field('wprl_setup_step_' . $current); ?>
            <input type="hidden" name="action" value="wprl_setup_save">
            <input type="hidden" name="wprl_step" value="<?php echo esc_attr($current); ?>">

            <?php require $step_file; ?>

            <div class="wprl-wiz-btnbar">
              <button type="submit" class="wprl-wiz-next"><?php echo ($current===4) ? 'FINISH SETUP' : 'NEXT STEP'; ?></button>
            </div>
          </form>
        <?php endif; ?>
      </div>

      <div>
        <div class="wprl-help-box">
          <img src="<?php echo esc_url( WPRANKLAB_PLUGIN_URL . 'assets/img/wpranklab-brand-logo.webp' ); ?>" alt="">
          <div class="t"><?php esc_html_e( 'Need Assistance?', 'wpranklab' ); ?></div>
          <div class="p"><?php esc_html_e( "If you have questions or run into issues, we're here to help. You can request support by visiting our contact page, just click the button below.", 'wpranklab' ); ?></div>
          <a class="btn" href="<?php echo esc_url( apply_filters('wprl_support_contact_url', 'https://wpranklab.com/contact/' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Contact WPRankLab', 'wpranklab' ); ?></a>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
