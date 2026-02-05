<?php
/**
 * Plugin Name:       WPRankLab Pro
 * Plugin URI:        https://wpranklab.com/
 * Description:       Optimize your website for AI search engines.
 * Version:           0.4.0
 * Author:            DigitalMe
 * Author URI:        https://digitalme.me/
 * Text Domain:       wpranklab
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

define( 'WPRANKLAB_VERSION', '0.4.0' );
define( 'WPRANKLAB_EDITION', 'pro' );
define( 'WPRANKLAB_PLUGIN_FILE', __FILE__ );
define( 'WPRANKLAB_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'WPRANKLAB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPRANKLAB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WPRANKLAB_PLUGIN_DIR . 'includes/class-wpranklab-ai.php';

require_once WPRANKLAB_PLUGIN_DIR . 'includes/class-wpranklab-history.php';

// License server base (stub – can be updated later).
define( 'WPRANKLAB_LICENSE_API_BASE', 'http://wpranklab.com' );

// Stub endpoints – update these when real endpoints exist.
define( 'WPRANKLAB_LICENSE_VALIDATE_ENDPOINT', WPRANKLAB_LICENSE_API_BASE . '/api/license/validate' );
define( 'WPRANKLAB_LICENSE_STATUS_ENDPOINT',   WPRANKLAB_LICENSE_API_BASE . '/api/license/status' );
define( 'WPRANKLAB_LICENSE_DEACTIVATE_ENDPOINT', WPRANKLAB_LICENSE_API_BASE . '/api/license/deactivate' );

// Option keys.
define( 'WPRANKLAB_OPTION_SETTINGS', 'wpranklab_settings' );
define( 'WPRANKLAB_OPTION_LICENSE',  'wpranklab_license' );

// Custom DB tables (names will be prefixed at runtime).
define( 'WPRANKLAB_TABLE_HISTORY',   'wpranklab_visibility_history' );
define( 'WPRANKLAB_TABLE_AUDIT_Q',   'wpranklab_audit_queue' );

define( 'WPRANKLAB_TABLE_ENTITIES',      'wpranklab_entities' );
define( 'WPRANKLAB_TABLE_ENTITY_POST',   'wpranklab_entity_post' );


/**
 * Autoload simple plugin classes.
 */
function wpranklab_autoload( $class ) {
    if ( strpos( $class, 'WPRankLab' ) !== 0 ) {
        return;
    }

    // Map class name like WPRankLab_Admin to class-wpranklab-admin.php, etc.
    $class_slug = strtolower( str_replace( '_', '-', $class ) );

    $paths = array(
        WPRANKLAB_PLUGIN_DIR . 'includes/class-' . $class_slug . '.php',
        WPRANKLAB_PLUGIN_DIR . 'includes/admin/class-' . $class_slug . '.php',
    );

    foreach ( $paths as $path ) {
        if ( file_exists( $path ) ) {
            require_once $path;
            return;
        }
    }
}
spl_autoload_register( 'wpranklab_autoload' );

/**
 * The code that runs during plugin activation.
 */
function wpranklab_activate() {
    require_once WPRANKLAB_PLUGIN_DIR . 'includes/class-wpranklab-activator.php';
    WPRankLab_Activator::activate();
}
register_activation_hook( __FILE__, 'wpranklab_activate' );

/**
 * The code that runs during plugin deactivation.
 */
function wpranklab_deactivate() {
    require_once WPRANKLAB_PLUGIN_DIR . 'includes/class-wpranklab-deactivator.php';
    WPRankLab_Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, 'wpranklab_deactivate' );

/**
 * Begins execution of the plugin.
 */
function wpranklab_run() {
    $plugin = new WPRankLab();
    $plugin->run();
}
wpranklab_run();

/**
 * Helper: check if Pro features are currently active (license valid and no kill switch).
 *
 * @return bool
 */
function wpranklab_is_pro_active() {
    
    // Demo override (admin enabled, auto-expire)
    $settings = get_option( WPRANKLAB_OPTION_SETTINGS, array() );
    
    $demo_on   = ! empty( $settings['demo_force_pro'] );
    $demo_until= isset( $settings['demo_force_pro_until'] ) ? (int) $settings['demo_force_pro_until'] : 0;
    
    if ( $demo_on && $demo_until && time() <= $demo_until ) {
        return true;
    }
    
    
    // Pro is enabled only via a valid license (Free users do not need a key).
    if ( class_exists( 'WPRankLab_License_Manager' ) ) {
        $license_manager = WPRankLab_License_Manager::get_instance();
        return $license_manager->is_pro_active();
    }
    
    return false;
}

/**
 * Hard block Pro-only entry points.
 * - AJAX: returns JSON 403
 * - Non-AJAX: wp_die 403
 */
function wpranklab_require_pro() {
    if ( wpranklab_is_pro_active() ) {
        return;
    }
    
    if ( wp_doing_ajax() ) {
        wp_send_json_error(
            array( 'message' => __( 'This is a Pro feature.', 'wpranklab' ) ),
            403
            );
    }
    
    wp_die( esc_html__( 'This is a Pro feature.', 'wpranklab' ), 403 );
}




// Setup Wizard bootstrap
if ( is_admin() ) {
    require_once WPRANKLAB_PLUGIN_DIR . 'includes/setup-wizard/class-wprl-setup-wizard.php';
    ( new WPRL_Setup_Wizard() )->init();
}


function wprl_on_activate() {
    update_option('wprl_setup_complete', 0);
    set_transient('wprl_do_setup_redirect', true, 30);
}
register_activation_hook( __FILE__, 'wprl_on_activate' );
