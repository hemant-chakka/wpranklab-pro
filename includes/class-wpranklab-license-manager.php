<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles license validation, status, and kill-switch behavior.
 */
class WPRankLab_License_Manager {

    /**
     * Singleton instance.
     *
     * @var WPRankLab_License_Manager
     */
    protected static $instance = null;

    /**
     * Current license data.
     *
     * @var array
     */
    protected $license;

    /**
     * Get singleton instance.
     *
     * @return WPRankLab_License_Manager
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor.
     */
    protected function __construct() {
        $this->license = get_option( WPRANKLAB_OPTION_LICENSE, array() );
    }

    /**
     * Initialize hooks.
     */
    public function init() {
        // Refresh license cache.
        $this->license = get_option( WPRANKLAB_OPTION_LICENSE, array() );

        // Daily cron license check.
        add_action( 'wpranklab_daily_license_check', array( $this, 'cron_daily_check' ) );

        // Maybe show admin notices.
        add_action( 'admin_notices', array( $this, 'maybe_show_license_notice' ), 1 );

        // Best-effort hardening: make the kill-switch notice difficult to hide.
        add_action( 'admin_head', array( $this, 'output_kill_switch_notice_styles' ) );
        add_action( 'admin_footer', array( $this, 'output_kill_switch_notice_watchdog' ) );
    }

    /**
     * Output admin CSS that helps keep the kill-switch notice visible.
     */
    public function output_kill_switch_notice_styles() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $license = $this->license;
        $status  = isset( $license['status'] ) ? $license['status'] : 'inactive';
        $kill    = ! empty( $license['kill_switch_active'] );

        if ( empty( $license['license_key'] ) || ( ! $kill && ! in_array( $status, array( 'expired', 'invalid', 'blocked' ), true ) ) ) {
            return;
        }
        ?>
        <style>
            .wpranklab-license-notice{display:block !important;padding:14px 18px;border-left-width:6px;}
            .wpranklab-license-notice p{font-size:14px;line-height:1.4;}
        </style>
        <?php
    }

    /**
     * JS watchdog: if the kill-switch notice is removed from DOM, re-insert a minimal one.
     * (Best-effort; can't fully prevent malicious admin CSS/JS.)
     */
    public function output_kill_switch_notice_watchdog() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $license = $this->license;
        $status  = isset( $license['status'] ) ? $license['status'] : 'inactive';
        $kill    = ! empty( $license['kill_switch_active'] );

        if ( empty( $license['license_key'] ) || ( ! $kill && ! in_array( $status, array( 'expired', 'invalid', 'blocked' ), true ) ) ) {
            return;
        }

        $msg = $kill
            ? __( 'WPRankLab Pro has been kill-switched by the license server. All Pro functionality is disabled.', 'wpranklab' )
            : __( 'WPRankLab Pro is disabled due to an invalid/expired license.', 'wpranklab' );
        $link = admin_url( 'admin.php?page=wpranklab-license' );
        ?>
        <script>
        (function(){
            function ensureNotice(){
                if (document.querySelector('.wpranklab-license-notice')) return;
                var wrap = document.querySelector('.wrap') || document.body;
                var div = document.createElement('div');
                div.className = 'notice notice-error wpranklab-license-notice';
                div.innerHTML = '<p><strong><?php echo esc_js( $msg ); ?></strong></p><p><a class="button button-primary" href="<?php echo esc_js( $link ); ?>"><?php echo esc_js( __( 'Manage WPRankLab License', 'wpranklab' ) ); ?></a></p>';
                wrap.parentNode.insertBefore(div, wrap);
            }
            setTimeout(ensureNotice, 250);
            setTimeout(ensureNotice, 1500);
        })();
        </script>
        <?php
    }

    /**
     * Check if Pro is currently active and allowed.
     *
     * @return bool
     */
    public function is_pro_active() {
        if ( empty( $this->license['license_key'] ) ) {
            return false;
        }

        if ( ! isset( $this->license['status'] ) || 'active' !== $this->license['status'] ) {
            return false;
        }

        if ( ! empty( $this->license['kill_switch_active'] ) ) {
            return false;
        }

        // Optional: check expiration date.
        if ( ! empty( $this->license['expires_at'] ) ) {
            $expires = strtotime( $this->license['expires_at'] );
            if ( $expires && $expires < time() ) {
                return false;
            }
        }

        // Optional: check allowed version.
        if ( ! empty( $this->license['allowed_version'] ) && version_compare( WPRANKLAB_VERSION, $this->license['allowed_version'], '>' ) ) {
            return false;
        }

        return true;
    }

    /**
     * Cron: daily license status check.
     */
    public function cron_daily_check() {
        // Only check if a license key exists.
        if ( empty( $this->license['license_key'] ) ) {
            return;
        }

        $this->validate_license( false );
    }

    /**
     * Validate license with remote server.
     *
     * @param bool $force Whether to force validation even if recently checked.
     *
     * @return array Updated license data.
     */
    public function validate_license( $force = true ) {
        $license = $this->license;

        $now = time();
        $last_check = isset( $license['last_check'] ) ? (int) $license['last_check'] : 0;

        // If not forcing and last check was within 12 hours, skip.
        if ( ! $force && $last_check && ( $now - $last_check ) < 12 * HOUR_IN_SECONDS ) {
            return $license;
        }

        if ( empty( $license['license_key'] ) ) {
            return $license;
        }

        $body = array(
            'license_key' => $license['license_key'],
            'site_url'    => home_url(),
            'version'     => WPRANKLAB_VERSION,
        );

        $response = wp_remote_post(
            WPRANKLAB_LICENSE_VALIDATE_ENDPOINT,
            array(
                'timeout' => 15,
                'body'    => $body,
            )
        );

        if ( is_wp_error( $response ) ) {
            // On error, keep existing status but update last_check.
            $license['last_check'] = $now;
            update_option( WPRANKLAB_OPTION_LICENSE, $license );
            $this->license = $license;

            return $license;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $code || ! is_array( $data ) ) {
            $license['last_check'] = $now;
            update_option( WPRANKLAB_OPTION_LICENSE, $license );
            $this->license = $license;

            return $license;
        }

        $license['status']          = isset( $data['status'] ) ? sanitize_text_field( $data['status'] ) : 'invalid';
        $license['expires_at']      = isset( $data['expires_at'] ) ? sanitize_text_field( $data['expires_at'] ) : '';
        $license['allowed_version'] = isset( $data['allowed_version'] ) ? sanitize_text_field( $data['allowed_version'] ) : '';
        $license['bound_domain']    = isset( $data['domain'] ) ? sanitize_text_field( $data['domain'] ) : '';
        $license['kill_switch_active'] = ! empty( $data['kill_switch'] ) ? 1 : 0;
        $license['last_check']      = $now;

        update_option( WPRANKLAB_OPTION_LICENSE, $license );
        $this->license = $license;

        return $license;
    }

    /**
     * Show a prominent notice if license is invalid, expired, or kill-switched.
     */
    public function maybe_show_license_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $license = $this->license;

        $status = isset( $license['status'] ) ? $license['status'] : 'inactive';
        $kill   = ! empty( $license['kill_switch_active'] );

        if ( empty( $license['license_key'] ) ) {
            // No license entered – only show on WPRankLab pages to avoid being too intrusive.
            $screen = get_current_screen();
            if ( $screen && strpos( $screen->id, 'wpranklab' ) !== false ) {
                ?>
                <div class="notice notice-warning">
                    <p><strong><?php esc_html_e( 'WPRankLab Pro is not activated.', 'wpranklab' ); ?></strong>
                        <?php esc_html_e( 'Enter a valid license key to unlock Pro features.', 'wpranklab' ); ?>
                    </p>
                </div>
                <?php
            }
            return;
        }


        // If we're on the main dashboard page, we render a custom banner there instead of a global admin notice.
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && 'toplevel_page_wpranklab' === $screen->id ) {
            // Still allow the "not activated" warning above when no key is entered.
            if ( $kill || in_array( $status, array( 'expired', 'invalid', 'blocked' ), true ) ) {
                return;
            }
        }

        // Kill switch or non-active status – show persistent large notice everywhere in admin.
        if ( $kill || in_array( $status, array( 'expired', 'invalid', 'blocked' ), true ) ) {
            $message = '';

            if ( $kill ) {
                $message = __( 'WPRankLab Pro has been kill-switched by the license server. All Pro functionality is disabled.', 'wpranklab' );
            } elseif ( 'expired' === $status ) {
                $message = __( 'WPRankLab: Your license has expired. Pro features have been disabled until you renew your license.', 'wpranklab' );
            } elseif ( 'invalid' === $status ) {
                $message = __( 'WPRankLab: Your license is invalid. Pro features have been disabled.', 'wpranklab' );
            } elseif ( 'blocked' === $status ) {
                $message = __( 'WPRankLab: Your license has been blocked. Pro features have been disabled.', 'wpranklab' );
            }

            ?>
            <div class="notice notice-error wpranklab-license-notice">
                <p><strong><?php echo esc_html( $message ); ?></strong></p>
                <p>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpranklab-license' ) ); ?>" class="button button-primary">
                        <?php esc_html_e( 'Manage WPRankLab License', 'wpranklab' ); ?>
                    </a>
                </p>
            </div>
            <?php
        }
    }
}
