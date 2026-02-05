<?php
if ( ! defined('ABSPATH') ) exit;

class WPRL_Setup_Wizard {

  public function init() {
    add_action('admin_menu', [$this, 'register_page']);
    add_action('admin_init', [$this, 'maybe_redirect_to_wizard']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('admin_post_wprl_setup_save', [$this, 'handle_save']);
    add_action('admin_post_wprl_wizard_start_scan', [$this, 'handle_start_scan']);
    add_action('wp_ajax_wprl_wizard_scan_state', [$this, 'ajax_scan_state']);
  }

  public function register_page() {
    add_submenu_page(
    'wpranklab',
    __( 'Setup Wizard', 'wpranklab' ),
    __( 'Setup Wizard', 'wpranklab' ),
    'manage_options',
    'wprl-setup-wizard',
    [ $this, 'render' ]
);
  }

  public function maybe_redirect_to_wizard() {
    if ( (int) get_option('wprl_setup_complete', 0) === 1 ) {
      return;
    }

    if ( is_admin()
      && current_user_can('manage_options')
      && get_transient('wprl_do_setup_redirect')
    ) {
      delete_transient('wprl_do_setup_redirect');
      wp_safe_redirect( admin_url('admin.php?page=wprl-setup-wizard') );
      exit;
    }
  }


  public function enqueue_assets( $hook ) {
    // Only load on our wizard page.
    if ( ! isset($_GET['page']) || $_GET['page'] !== 'wprl-setup-wizard' ) {
      return;
    }
    if ( defined('WPRANKLAB_PLUGIN_URL') ) {
      wp_enqueue_style( 'wprl-setup-wizard', WPRANKLAB_PLUGIN_URL . 'assets/css/setup-wizard.css', [], '1.0.1' );
      wp_enqueue_script( 'wprl-setup-wizard-scan', WPRANKLAB_PLUGIN_URL . 'assets/js/setup-wizard-scan.js', [], '1.0.0', true );
}
  }


  public function handle_start_scan() {
    if ( ! current_user_can('manage_options') ) { wp_die('forbidden'); }
    check_admin_referer('wprl_wizard_start_scan');

    if ( class_exists('WPRankLab_Batch_Scan') ) {
      $bs = WPRankLab_Batch_Scan::get_instance();
      if ( $bs && method_exists($bs, 'start_scan') ) {
        $bs->start_scan( array('post','page') );
      }
    }

    wp_safe_redirect( admin_url('admin.php?page=wprl-setup-wizard&step=3') );
    exit;
  }

  public function ajax_scan_state() {
    if ( ! current_user_can('manage_options') ) { wp_send_json_error(); }
    if ( class_exists('WPRankLab_Batch_Scan') ) {
      $bs = WPRankLab_Batch_Scan::get_instance();
      if ( $bs && method_exists($bs,'get_state') ) {
        wp_send_json_success( $bs->get_state() );
      }
    }
    wp_send_json_success( array('status'=>'idle','total'=>0,'progress'=>0) );
  }

  public function render() {
    $step = isset($_GET['step']) ? absint($_GET['step']) : 1;
    require WPRANKLAB_PLUGIN_DIR . 'includes/setup-wizard/views/wizard-wrapper.php';
  }

  public function handle_save() {
    $step = absint($_POST['wprl_step'] ?? 0);
    check_admin_referer('wprl_setup_step_' . $step);

    // Step 1: Start Optimizing
    if ( $step === 1 ) {
      update_option('wprl_org_type', sanitize_text_field($_POST['wprl_org_type'] ?? ''));
      update_option('wprl_business_name', sanitize_text_field($_POST['wprl_business_name'] ?? ''));
      update_option('wprl_website_name', sanitize_text_field($_POST['wprl_website_name'] ?? ''));
    }

    // Step 2: AI Integrations (stored in main settings array)
    if ( $step === 2 ) {
      $settings = get_option( WPRANKLAB_OPTION_SETTINGS, array() );
      if ( ! is_array($settings) ) { $settings = array(); }

      $settings['openai_api_key']   = sanitize_text_field($_POST['wprl_openai_api_key'] ?? '');
      $mode = sanitize_text_field($_POST['wprl_ai_scan_mode'] ?? 'full');
      if ( ! in_array($mode, array('quick','full'), true) ) { $mode = 'full'; }
      $settings['ai_scan_mode'] = $mode;

      $cache = isset($_POST['wprl_ai_cache_minutes']) ? (int) $_POST['wprl_ai_cache_minutes'] : 0;
      if ( $cache < 0 ) { $cache = 0; }
      if ( $cache > 10080 ) { $cache = 10080; }
      $settings['ai_cache_minutes'] = $cache;

      update_option( WPRANKLAB_OPTION_SETTINGS, $settings );
    }

    // Step 4: Email Reports
    if ( $step === 4 ) {
      $email = isset($_POST['wprl_report_email']) ? sanitize_email($_POST['wprl_report_email']) : '';
      update_option('wprl_report_email', $email);
      update_option('wprl_weekly_reports_enabled', isset($_POST['wprl_weekly_reports_enabled']) ? 1 : 0 );

      // Mark setup as complete (Step 5 is a success screen).
      update_option('wprl_setup_complete', 1);

    }

    // Step 5: Complete
    if ( $step === 5 ) {
      update_option('wprl_setup_complete', 1);
      wp_safe_redirect( admin_url('admin.php?page=wpranklab') );
      exit;
    }

    wp_safe_redirect( admin_url('admin.php?page=wprl-setup-wizard&step=' . ($step + 1)) );
    exit;
  }
}