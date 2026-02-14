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

        $now        = time();
        $last_check = isset( $license['last_check'] ) ? (int) $license['last_check'] : 0;

        // If not forcing and last check was within 12 hours, skip.
        if ( ! $force && $last_check && ( $now - $last_check ) < 12 * HOUR_IN_SECONDS ) {
            return $license;
        }

        if ( empty( $license['license_key'] ) ) {
            // No key: don't mutate status on background checks.
            return $license;
        }

        $license_key = trim( (string) $license['license_key'] );

        // Determine domain for SLM "registered_domain".
        $registered_domain = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( empty( $registered_domain ) ) {
            $registered_domain = home_url();
        }

        // Secret key is configured in Software License Manager settings on wpranklab.com.
        // Prefer a wp-config constant, else allow a filter.
        $secret_key = '';
        if ( defined( 'WPRANKLAB_SLM_SECRET_KEY' ) ) {
            $secret_key = (string) WPRANKLAB_SLM_SECRET_KEY;
        }
        $secret_key = apply_filters( 'wpranklab_slm_secret_key', $secret_key );

        // Item reference should match the Product Name / Item Reference in SLM.
        $item_reference = defined( 'WPRANKLAB_SLM_ITEM_REFERENCE' ) ? (string) WPRANKLAB_SLM_ITEM_REFERENCE : 'WPRankLab Pro';
        $item_reference = apply_filters( 'wpranklab_slm_item_reference', $item_reference );

        $base_url = defined( 'WPRANKLAB_SLM_API_URL' ) ? WPRANKLAB_SLM_API_URL : ( defined( 'WPRANKLAB_LICENSE_API_BASE' ) ? trailingslashit( WPRANKLAB_LICENSE_API_BASE ) : home_url( '/' ) );

        // 1) Check status.
        $args = array(
            'slm_action'        => 'slm_check',
            'license_key'       => $license_key,
            'registered_domain' => $registered_domain,
            'item_reference'    => $item_reference,
        );
        if ( ! empty( $secret_key ) ) {
            $args['secret_key'] = $secret_key;
        }

        $url      = add_query_arg( $args, $base_url );
        $response = wp_remote_get( $url, array( 'timeout' => 20 ) );

        $license['last_check'] = $now;

        if ( is_wp_error( $response ) ) {
            // Keep existing status on transport error; record message.
            $license['last_error'] = $response->get_error_message();
            update_option( WPRANKLAB_OPTION_LICENSE, $license );
            $this->license = $license;
            return $license;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );

        // Always store last raw response for debugging (trimmed).
        $license['last_raw'] = substr( $body, 0, 4000 );

        $data = json_decode( $body, true );
        if ( 200 !== $code || ! is_array( $data ) ) {
            // Keep existing status if response isn't parseable.
            $license['last_error'] = 'Bad response from license server (HTTP ' . $code . ').';
            update_option( WPRANKLAB_OPTION_LICENSE, $license );
            $this->license = $license;
            return $license;
        }

        // Normalize SLM fields.
        $result  = isset( $data['result'] ) ? sanitize_text_field( $data['result'] ) : '';
        $status  = isset( $data['status'] ) ? sanitize_text_field( $data['status'] ) : '';
        $message = isset( $data['message'] ) ? sanitize_text_field( $data['message'] ) : '';

        // Some SLM setups return "license_status" instead of "status".
        if ( empty( $status ) && isset( $data['license_status'] ) ) {
            $status = sanitize_text_field( $data['license_status'] );
        }
        // Additional fallbacks seen in some SLM customizations.
        if ( empty( $status ) ) {
            foreach ( array( 'lic_status', 'status_code', 'licenseState', 'state' ) as $k ) {
                if ( isset( $data[ $k ] ) && is_scalar( $data[ $k ] ) ) {
                    $status = sanitize_text_field( $data[ $k ] );
                    break;
                }
            }
        }

        $license['last_result'] = $result;
        $license['last_status_raw'] = $status;

        // Some SLM responses use "success" / "error".
        // We treat ACTIVE only when status == active.
        $normalized = 'invalid';
        if ( 'active' === strtolower( $status ) ) {
            $normalized = 'active';
        } elseif ( in_array( strtolower( $status ), array( 'expired', 'blocked', 'inactive' ), true ) ) {
            $normalized = strtolower( $status );
        } elseif ( 'success' === strtolower( $result ) && ! empty( $status ) ) {
            // Fallback: trust explicit status.
            $normalized = strtolower( $status );
        } elseif ( 'error' === strtolower( $result ) ) {
            $normalized = 'invalid';
        }

        $license['status']      = $normalized;
        $license['last_error']  = '';
        $license['last_message']= $message;
        // Keep last_raw on success for debugging

        // Optional values if present.
        if ( isset( $data['expire_date'] ) ) {
            $license['expires_at'] = sanitize_text_field( $data['expire_date'] );
        } elseif ( isset( $data['expires_at'] ) ) {
            $license['expires_at'] = sanitize_text_field( $data['expires_at'] );
        }

        // Kill switch: only allow server-driven kill switch via a custom field.
        $license['kill_switch_active'] = ! empty( $data['kill_switch'] ) ? 1 : 0;

        // 2) If license is valid but not activated for this domain, try activation once.
        // Many SLM installs include "registered_domains" or domain-related messages.
        $needs_activation = false;
        if ( 'active' === $license['status'] ) {
            if ( isset( $data['registered_domains'] ) && is_array( $data['registered_domains'] ) ) {
                $domains = array();
                foreach ( $data['registered_domains'] as $d ) {
                    // Some SLM responses may include nested arrays; only accept scalars.
                    if ( is_scalar( $d ) ) {
                        $domains[] = strtolower( trim( (string) $d ) );
                    } elseif ( is_array( $d ) && isset( $d['registered_domain'] ) && is_scalar( $d['registered_domain'] ) ) {
                        $domains[] = strtolower( trim( (string) $d['registered_domain'] ) );
                    }
                }
                $domains = array_filter( array_unique( $domains ) );

                if ( ! in_array( strtolower( $registered_domain ), $domains, true ) ) {
                    $needs_activation = true;
                }
            } elseif ( isset( $data['registered_domain'] ) ) {
                $rd = strtolower( trim( (string) $data['registered_domain'] ) );
                if ( $rd && $rd !== strtolower( $registered_domain ) ) {
                    $needs_activation = true;
                }
            } elseif ( $message && false !== stripos( $message, 'not activated' ) ) {
                $needs_activation = true;
            }
        }

        if ( $needs_activation ) {
            $act_args = array(
                'slm_action'        => 'slm_activate',
                'license_key'       => $license_key,
                'registered_domain' => $registered_domain,
                'item_reference'    => $item_reference,
            );
            if ( ! empty( $secret_key ) ) {
                $act_args['secret_key'] = $secret_key;
            }
            $act_url  = add_query_arg( $act_args, $base_url );
            $act_resp = wp_remote_get( $act_url, array( 'timeout' => 20 ) );

            if ( ! is_wp_error( $act_resp ) && 200 === (int) wp_remote_retrieve_response_code( $act_resp ) ) {
                $act_body = (string) wp_remote_retrieve_body( $act_resp );
                $act_data = json_decode( $act_body, true );
                if ( is_array( $act_data ) ) {
                    $act_result = isset( $act_data['result'] ) ? strtolower( sanitize_text_field( $act_data['result'] ) ) : '';
                    $act_status = isset( $act_data['status'] ) ? strtolower( sanitize_text_field( $act_data['status'] ) ) : '';
                    if ( 'success' === $act_result && 'active' === $act_status ) {
                        $license['status'] = 'active';
                        $license['last_message'] = isset( $act_data['message'] ) ? sanitize_text_field( $act_data['message'] ) : $license['last_message'];
                    } else {
                        // Activation failed; store message but don't lie about activation.
                        $license['last_message'] = isset( $act_data['message'] ) ? sanitize_text_field( $act_data['message'] ) : $license['last_message'];
                    }
                }
            }
        }

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
