<?php
/**
 * Plugin Name: Show Support
 * Description: Adds a sticky emoji button that triggers a celebratory burst and counts user support.
 * Version: 1.1.5
 * Author: Angela Blake
 * Text Domain: show-support
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'SHOW_SUPPORT_PLUGIN_BASENAME' ) ) {
    define( 'SHOW_SUPPORT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

if ( ! defined( 'SHOW_SUPPORT_VERSION' ) ) {
    define( 'SHOW_SUPPORT_VERSION', '1.1.5' );
}

if ( ! defined( 'SHOWSUPPORT_OPTION_DELETE_ON_UNINSTALL' ) ) {
	define( 'SHOWSUPPORT_OPTION_DELETE_ON_UNINSTALL', 'showsupport_delete_data_on_uninstall' );
}

// Include the Plugin Update Checker library.
require_once plugin_dir_path( __FILE__ ) . 'includes/plugin-update-checker/plugin-update-checker.php';

// Set up the update checker.
$show_support_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/angelablake/Show-Support', // Repo URL
    __FILE__,                                                 // Main plugin file
    'show-support'                                            // Plugin slug (folder name)
);

// Default branch is "main" instead of "master".
$show_support_update_checker->setBranch( 'main' );

class Show_Support {

    public function __construct() {
        // Front-end assets.
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Render button + count.
        add_action( 'wp_footer', [ $this, 'render_support_button' ] );

        // REST API.
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

        // Admin.
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_init', [ $this, 'maybe_redirect_to_settings' ] );

        // Reset count handler.
        add_action( 'admin_post_show_support_reset_count', [ $this, 'handle_reset_count' ] );

        // "Settings" link in Plugins list.
        add_filter(
            'plugin_action_links_' . SHOW_SUPPORT_PLUGIN_BASENAME,
            [ $this, 'add_plugin_action_links' ]
        );

        // Admin styles for settings page.
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // For translations.
        add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );
    }

    /**
     * Load plugin text domain for translations.
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'show-support',
            false,
            dirname( SHOW_SUPPORT_PLUGIN_BASENAME ) . '/languages'
        );
    }


    /**
     * Enqueue front-end assets.
     */
    public function enqueue_assets() {
        $handle = 'show-support';

        wp_enqueue_style(
            $handle,
            plugin_dir_url( __FILE__ ) . 'assets/show-support.css',
            [],
            SHOW_SUPPORT_VERSION
        );

        wp_enqueue_script(
            $handle,
            plugin_dir_url( __FILE__ ) . 'assets/show-support.js',
            [],
            SHOW_SUPPORT_VERSION,
            true
        );

        $settings = get_option( 'show_support_settings', [] );
        $emoji = $this->get_current_emoji();
        
        wp_localize_script(
            'show-support',
            'ShowSupport',
            [
                'restUrl'      => esc_url_raw( rest_url( 'show-support/v1/click' ) ),
                'nonce'        => wp_create_nonce( 'wp_rest' ),
                'emoji'        => $emoji,
                'contentId'    => is_singular() ? get_queried_object_id() : 0,
                'soundEnabled' => ! empty( $settings['sound_enabled'] ),
                'soundUrl'     => plugin_dir_url( __FILE__ ) . 'assets/sounds/bubble-pop-alert.mp3',
                ]
                );
    }

    /**
     * Render the support UI (count + emoji button).
     */
    public function render_support_button() {
        $count    = (int) get_option( 'show_support_clicks', 0 );
        $settings = get_option( 'show_support_settings', [] );

        // Default styles.
        $emoji_size    = isset( $settings['emoji_size'] ) ? (int) $settings['emoji_size'] : 32;
        $padding       = isset( $settings['padding'] ) ? (int) $settings['padding'] : 10;
        $background    = isset( $settings['background_color'] ) ? $settings['background_color'] : '#ffffff';
        $border_color  = isset( $settings['border_color'] ) ? $settings['border_color'] : '#dddddd';
        $border_width  = isset( $settings['border_width'] ) ? (int) $settings['border_width'] : 1;
        $border_radius = isset( $settings['border_radius'] ) ? (int) $settings['border_radius'] : 50;

        // Clamp numeric values.
        $emoji_size    = max( 8, min( 200, $emoji_size ) );
        $padding       = max( 0, min( 100, $padding ) );
        $border_width  = max( 0, min( 20, $border_width ) );
        $border_radius = max( 0, min( 999, $border_radius ) );

        $emoji = $this->get_current_emoji();

        // Build inline style.
        $styles = [];
        $styles[] = 'font-size:' . $emoji_size . 'px';
        $styles[] = 'padding:' . $padding . 'px';

        if ( ! empty( $background ) ) {
            $styles[] = 'background-color:' . $background;
        }

        if ( $border_width > 0 && ! empty( $border_color ) ) {
            $styles[] = 'border-width:' . $border_width . 'px';
            $styles[] = 'border-style:solid';
            $styles[] = 'border-color:' . $border_color;
        } else {
            $styles[] = 'border:none';
        }

        $styles[] = 'border-radius:' . $border_radius . 'px';

        $style_attr = esc_attr( implode( ';', $styles ) );
        ?>
        <div class="show-support-wrapper" aria-live="polite" aria-atomic="true">
            <div class="show-support-count">
                <?php echo number_format_i18n( $count ); ?>
            </div>
            <button
                class="show-support-btn"
                aria-label="<?php esc_attr_e( 'Show support', 'show-support' ); ?>"
                style="<?php echo $style_attr; ?>"
            >
                <?php echo esc_html( $emoji ); ?>
            </button>
        </div>
        <?php
    }

    /**
     * Register REST routes.
     */
    public function register_rest_routes() {
        register_rest_route(
            'show-support/v1',
            '/click',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'handle_click' ],
                'permission_callback' => [ $this, 'rest_permission' ],
            ]
        );
    }

    /**
     * REST permission check.
     *
     * @param WP_REST_Request $request Request instance.
     * @return bool
     */
    public function rest_permission( WP_REST_Request $request ) {
        $nonce = $request->get_header( 'x_wp_nonce' );
        return wp_verify_nonce( $nonce, 'wp_rest' );
    }

    /**
     * Handle support clicks.
     *
     * @param WP_REST_Request $request Request instance.
     * @return WP_REST_Response
     */
    public function handle_click( WP_REST_Request $request ) {
        $count = (int) get_option( 'show_support_clicks', 0 );
        $count++;

        update_option( 'show_support_clicks', $count );

        $stored_stats = get_option( 'show_support_stats', false );
        $stats = $stored_stats;
        if ( ! is_array( $stats ) ) {
            $stats = [];
        }

        if ( ! isset( $stats['daily'] ) || ! is_array( $stats['daily'] ) ) {
            $stats['daily'] = [];
        }

        if ( ! isset( $stats['content'] ) || ! is_array( $stats['content'] ) ) {
            $stats['content'] = [];
        }

        $today = current_datetime()->format( 'Y-m-d' );
        $stats['daily'][ $today ] = isset( $stats['daily'][ $today ] )
            ? (int) $stats['daily'][ $today ] + 1
            : 1;

        if ( empty( $stats['started_at'] ) ) {
            $stats['started_at'] = $today;
            $stats['legacy_total'] = max( 0, $count - 1 );
        }

        // Keep a little more than 12 months of daily data for the dashboard chart.
        $cutoff = current_datetime()->modify( '-400 days' )->format( 'Y-m-d' );
        foreach ( $stats['daily'] as $date => $daily_count ) {
            if ( $date < $cutoff ) {
                unset( $stats['daily'][ $date ] );
            }
        }

        $content_id = absint( $request->get_param( 'contentId' ) );
        if ( $content_id && get_post( $content_id ) ) {
            $stats['content'][ $content_id ] = isset( $stats['content'][ $content_id ] )
                ? (int) $stats['content'][ $content_id ] + 1
                : 1;
        }

        if ( false === $stored_stats ) {
            // Stats are only needed for clicks and the admin dashboard, so don't autoload them.
            add_option( 'show_support_stats', $stats, '', false );
        } else {
            update_option( 'show_support_stats', $stats );
        }

        return new WP_REST_Response(
            [
                'success' => true,
                'count'   => $count,
            ],
            200
        );
    }

    /**
     * Add settings page under Appearance.
     */
    public function add_admin_menu() {
        add_theme_page(
            __( 'Show Support', 'show-support' ),
            __( 'Show Support', 'show-support' ),
            'manage_options',
            'show-support',
            [ $this, 'render_settings_page' ]
        );
    }

    /**
     * Register settings, section, and fields.
     */
    public function register_settings() {
        register_setting(
            'show_support_settings_group',
            'show_support_settings',
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize_settings' ],
                'default'           => [],
            ]
        );

        add_settings_section(
            'show_support_style_section',
            __( 'Button Style', 'show-support' ),
            function () {
                echo '<p>' . esc_html__(
                    'Adjust the appearance of the Show Support button.',
                    'show-support'
                ) . '</p>';
            },
            'show-support'
        );

        // Utilities tab section.
        add_settings_section(
            'show_support_utilities_section',
            __( 'Utilities', 'show-support' ),
            '__return_null',
            'show-support-utilities'
    );

        // === Fields for Appearance Tab ===

        // Emoji choice.
        add_settings_field(
            'show_support_emoji',
            __( 'Emoji', 'show-support' ),
            [ $this, 'render_emoji_field' ],
            'show-support',
            'show_support_style_section'
);

        // Enable sound effect
        add_settings_field(
            'show_support_sound_enabled',
            __( 'Sound effects', 'show-support' ),
            [ $this, 'render_sound_enabled_field' ],
            'show-support',
            'show_support_style_section'
            );
        
        // Emoji size (px).
        add_settings_field(
            'show_support_emoji_size',
            __( 'Emoji size (px)', 'show-support' ),
            [ $this, 'render_emoji_size_field' ],
            'show-support',
            'show_support_style_section'
        );

        // Padding (px).
        add_settings_field(
            'show_support_padding',
            __( 'Button padding (px)', 'show-support' ),
            [ $this, 'render_padding_field' ],
            'show-support',
            'show_support_style_section'
        );

        // Background color.
        add_settings_field(
            'show_support_background_color',
            __( 'Background color', 'show-support' ),
            [ $this, 'render_background_color_field' ],
            'show-support',
            'show_support_style_section'
        );

        // Border color.
        add_settings_field(
            'show_support_border_color',
            __( 'Border color', 'show-support' ),
            [ $this, 'render_border_color_field' ],
            'show-support',
            'show_support_style_section'
        );

        // Border width.
        add_settings_field(
            'show_support_border_width',
            __( 'Border width (px)', 'show-support' ),
            [ $this, 'render_border_width_field' ],
            'show-support',
            'show_support_style_section'
        );

        // Border radius.
        add_settings_field(
            'show_support_border_radius',
            __( 'Border radius (px)', 'show-support' ),
            [ $this, 'render_border_radius_field' ],
            'show-support',
            'show_support_style_section'
        );

        // === Fields for Utilities Tab ===

        // Delete data on unintall.
        add_settings_field(
            'show_support_delete_data_on_uninstall',
            __( 'Delete data on uninstall', 'show-support' ),
            [ $this, 'render_delete_data_on_uninstall_field' ],
            'show-support-utilities',
            'show_support_utilities_section'
            );
    }

    /**
     * Render the emoji selection field.
     */
    public function render_emoji_field() {
        $settings      = get_option( 'show_support_settings', [] );
        $current_value = isset( $settings['emoji'] ) ? $settings['emoji'] : 'heart';

        $options = [
            'heart' => [ 'emoji' => '❤️', 'label' => __( 'Heart', 'show-support' ) ],
            'star'  => [ 'emoji' => '⭐', 'label' => __( 'Star', 'show-support' ) ],
            'cat'   => [ 'emoji' => '😺', 'label' => __( 'Grinning cat', 'show-support' ) ],
            'beer'  => [ 'emoji' => '🍻', 'label' => __( 'Clinking beer mugs', 'show-support' ) ],
        ];
        ?>
        <select
            name="show_support_settings[emoji]"
            id="show_support_emoji"
        >
            <?php foreach ( $options as $key => $data ) : ?>
                <option
                    value="<?php echo esc_attr( $key ); ?>"
                    <?php selected( $current_value, $key ); ?>
                >
                    <?php echo esc_html( $data['emoji'] . ' ' . $data['label'] ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php esc_html_e( 'Choose the emoji used for the button and confetti.', 'show-support' ); ?>
        </p>
        <?php
    }

    /**
     * Render the sound effect field.
     */
    public function render_sound_enabled_field() {
        $settings = get_option( 'show_support_settings', [] );
        $enabled  = ! empty( $settings['sound_enabled'] );
        ?>
        <label>
            <input
            type="checkbox"
            name="show_support_settings[sound_enabled]"
            value="1"
            <?php checked( $enabled ); ?>
            />
            <?php esc_html_e( 'Play a sound when the button is clicked.', 'show-support' ); ?>
        </label>
        <p class="description">
            <?php esc_html_e( 'Disabled by default.', 'show-support' ); ?>
        </p>
        <?php
        }
    
    /**
     * Render the emoji size field.
     */
    public function render_emoji_size_field() {
        $settings   = get_option( 'show_support_settings', [] );
        $emoji_size = isset( $settings['emoji_size'] )
            ? (int) $settings['emoji_size']
            : 32;
        ?>
        <input
            type="number"
            name="show_support_settings[emoji_size]"
            id="show_support_emoji_size"
            value="<?php echo esc_attr( $emoji_size ); ?>"
            min="8"
            max="200"
            step="1"
            class="small-text"
        />
        <p class="description">
            <?php esc_html_e( 'Set the emoji size for the button (in pixels).', 'show-support' ); ?>
        </p>
        <?php
    }
    
        /**
     * Render the padding field.
     */
    public function render_padding_field() {
        $settings = get_option( 'show_support_settings', [] );
        $padding  = isset( $settings['padding'] )
            ? (int) $settings['padding']
            : 10;
        ?>
        <input
            type="number"
            name="show_support_settings[padding]"
            id="show_support_padding"
            value="<?php echo esc_attr( $padding ); ?>"
            min="0"
            max="100"
            step="1"
            class="small-text"
        />
        <p class="description">
            <?php esc_html_e( 'Padding inside the button around the emoji (in pixels).', 'show-support' ); ?>
        </p>
        <?php
    }

    /**
     * Render background color field.
     */
    public function render_background_color_field() {
        $settings         = get_option( 'show_support_settings', [] );
        $background_color = isset( $settings['background_color'] )
            ? $settings['background_color']
            : '#ffffff';
        ?>
        <input
            type="text"
            name="show_support_settings[background_color]"
            id="show_support_background_color"
            value="<?php echo esc_attr( $background_color ); ?>"
            class="regular-text"
            placeholder="#ffffff"
        />
        <p class="description">
            <?php esc_html_e( 'Background color for the button (hex, e.g. #ffffff).', 'show-support' ); ?>
        </p>
        <?php
    }

    /**
     * Render border color field.
     */
    public function render_border_color_field() {
        $settings     = get_option( 'show_support_settings', [] );
        $border_color = isset( $settings['border_color'] )
            ? $settings['border_color']
            : '#dddddd';
        ?>
        <input
            type="text"
            name="show_support_settings[border_color]"
            id="show_support_border_color"
            value="<?php echo esc_attr( $border_color ); ?>"
            class="regular-text"
            placeholder="#dddddd"
        />
        <p class="description">
            <?php esc_html_e( 'Border color for the button (hex, e.g. #dddddd). Leave empty for no border.', 'show-support' ); ?>
        </p>
        <?php
    }

    /**
     * Render border width field.
     */
    public function render_border_width_field() {
        $settings     = get_option( 'show_support_settings', [] );
        $border_width = isset( $settings['border_width'] )
            ? (int) $settings['border_width']
            : 1;
        ?>
        <input
            type="number"
            name="show_support_settings[border_width]"
            id="show_support_border_width"
            value="<?php echo esc_attr( $border_width ); ?>"
            min="0"
            max="20"
            step="1"
            class="small-text"
        />
        <p class="description">
            <?php esc_html_e( 'Border width in pixels. Set to 0 for no border.', 'show-support' ); ?>
        </p>
        <?php
    }

    /**
     * Render border radius field.
     */
    public function render_border_radius_field() {
        $settings      = get_option( 'show_support_settings', [] );
        $border_radius = isset( $settings['border_radius'] )
            ? (int) $settings['border_radius']
            : 50;
        ?>
        <input
            type="number"
            name="show_support_settings[border_radius]"
            id="show_support_border_radius"
            value="<?php echo esc_attr( $border_radius ); ?>"
            min="0"
            max="999"
            step="1"
            class="small-text"
        />
        <p class="description">
            <?php esc_html_e( 'Border radius in pixels. Higher values create a more pill-shaped button.', 'show-support' ); ?>
        </p>
        <?php
    }

    // Render delete data on uninstall field.
    public function render_delete_data_on_uninstall_field() {
        $settings = get_option( 'show_support_settings', [] );
        $value    = isset( $settings['delete_data_on_uninstall'] )
        ? $settings['delete_data_on_uninstall']
        : 'no';
        ?>
        <label>
            <input
            type="checkbox"
            name="show_support_settings[delete_data_on_uninstall]"
            value="yes"
            <?php checked( $value, 'yes' ); ?>
            />
            <?php esc_html_e( 'Delete all plugin data (settings, custom data) when the plugin is deleted.', 'show-support' ); ?>
        </label>
        <p class="description">
            <?php esc_html_e( 'This runs only when you click “Delete” on the Plugins page, not when you just deactivate the plugin.', 'show-support' ); ?>
        </p>
        <?php
        }

    /**
     * Add a "Settings" link to the plugin row in the Plugins screen.
     *
     * @param array $links Existing action links.
     * @return array
     */
    public function add_plugin_action_links( $links ) {
        $settings_url = add_query_arg(
            [
                'page' => 'show-support',
                'tab'  => 'appearance',
            ],
            admin_url( 'themes.php' )
        );
        
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url( $settings_url ),
            esc_html__( 'Settings', 'show-support' )
        );
        
        // Prepend our link so it appears first.
        array_unshift( $links, $settings_link );
        
        return $links;
    }

    /**
     * Enqueue admin-only styles for the settings page.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_admin_assets( $hook ) {
        // Our settings page hook is "appearance_page_show-support".
        if ( 'appearance_page_show-support' !== $hook ) {
            return;
        }
        
        wp_enqueue_style(
            'show-support-admin',
            plugin_dir_url( __FILE__ ) . 'assets/show-support-admin.css',
            [],
            SHOW_SUPPORT_VERSION
        );
    }
    
    /**
     * Sanitize settings array.
     *
     * @param array $input Raw input from the settings form.
     * @return array Sanitized settings.
     */
    public function sanitize_settings( $input ) {
        $output = [];
        
        if ( ! is_array( $input ) ) {
            $input = [];
            }
            
        // Emoji.
        if ( isset( $input['emoji'] ) ) {
            $emoji_key = sanitize_key( $input['emoji'] );
                
        // Safe default.
        $output['emoji'] = 'heart';
                
        // Only call get_emoji_map() if it exists and returns an array.
        $allowed = [];
                
        if ( method_exists( $this, 'get_emoji_map' ) ) {
            $map = $this->get_emoji_map();
            
            if ( is_array( $map ) ) {
                $allowed = array_keys( $map );
                }
                }
                if ( ! empty( $allowed ) && in_array( $emoji_key, $allowed, true ) ) {
                    $output['emoji'] = $emoji_key;
                    }
                    }
                            
        // Sound effect.
        $output['sound_enabled'] = ! empty( $input['sound_enabled'] );
            
        // Heart/emoji size (px).
        if ( isset( $input['emoji_size'] ) ) {
            $size = absint( $input['emoji_size'] );
            if ( $size < 8 ) {
                $size = 8;
                } elseif ( $size > 200 ) {
                    $size = 200;
                    }
                    $output['emoji_size'] = $size;
                    }

        // Padding (px).
        if ( isset( $input['padding'] ) ) {
            $padding = absint( $input['padding'] );
            if ( $padding < 0 ) {
                $padding = 0;
                } elseif ( $padding > 100 ) {
                    $padding = 100;
                    }
                    $output['padding'] = $padding;
                    }

        // Background color.
        if ( isset( $input['background_color'] ) ) {
            $bg = $this->sanitize_hex_maybe_empty( $input['background_color'] );
            $output['background_color'] = $bg;
            }

        // Border color.
        if ( isset( $input['border_color'] ) ) {
            $border_color = $this->sanitize_hex_maybe_empty( $input['border_color'] );
            $output['border_color'] = $border_color;
            }

        // Border width.
        if ( isset( $input['border_width'] ) ) {
            $width = absint( $input['border_width'] );
            if ( $width < 0 ) {
                $width = 0;
                } elseif ( $width > 20 ) {
                    $width = 20;
                    }
                    $output['border_width'] = $width;
                    }
            
        // Border radius.
        if ( isset( $input['border_radius'] ) ) {
            $radius = absint( $input['border_radius'] );
            if ( $radius < 0 ) {
                $radius = 0;
                } elseif ( $radius > 999 ) {
                    $radius = 999;
                    }
                    $output['border_radius'] = $radius;
                    }

        // Delete data on uninstall (stored in the same settings array).
        $output['delete_data_on_uninstall'] =
        ( isset( $input['delete_data_on_uninstall'] ) && 'yes' === $input['delete_data_on_uninstall'] )
        ? 'yes'
        : 'no';
        
        return $output;
        }

    /**
     * Sanitize a hex color string or allow empty.
     *
     * @param string $value Raw value.
     * @return string Sanitized hex color or empty string.
     */
    private function sanitize_hex_maybe_empty( $value ) {
        $value = trim( (string) $value );
        if ( $value === '' ) {
            return '';
        }

        $sanitized = sanitize_hex_color( $value );
        return $sanitized ? $sanitized : '';
    }

    /**
     * Map of available emojis.
     *
     * @return array
     */
    private function get_emoji_map() {
        return [
            'heart' => '❤️',
            'star'  => '⭐',
            'cat'   => '😺', // grinning cat with smiling eyes
            'beer'  => '🍻', // clinking beer mugs
        ];
    }

    /**
     * Get the emoji to use for the button/confetti.
     *
     * @return string
     */
    private function get_current_emoji() {
        $map      = $this->get_emoji_map();
        $settings = get_option( 'show_support_settings', [] );

        $key = isset( $settings['emoji'] ) ? $settings['emoji'] : 'heart';

        if ( ! isset( $map[ $key ] ) ) {
            $key = 'heart';
        }

        return $map[ $key ];
    }

    /**
     * Handle reset of the support count from the settings page.
     */
    public function handle_reset_count() {
        // Permission check.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to do this.', 'show-support' ) );
        }
        
        // Nonce check.
        if (
            ! isset( $_POST['show_support_reset_nonce'] ) ||
            ! wp_verify_nonce( $_POST['show_support_reset_nonce'], 'show_support_reset_count' )
            ) {
                wp_die( esc_html__( 'Nonce verification failed.', 'show-support' ) );
            }
            
            // Reset the count.
            update_option( 'show_support_clicks', 0 );
            delete_option( 'show_support_stats' );
            
            // Redirect back to the settings page.
            $redirect_url = admin_url( 'themes.php?page=show-support' );
            
            wp_safe_redirect( $redirect_url );
            exit;
        }
    
    /**
     * Render the Stats dashboard.
     */
    private function render_stats_dashboard() {
        $total = (int) get_option( 'show_support_clicks', 0 );
        $stats = get_option( 'show_support_stats', [] );

        if ( ! is_array( $stats ) ) {
            $stats = [];
        }

        $daily = isset( $stats['daily'] ) && is_array( $stats['daily'] ) ? $stats['daily'] : [];
        $content_counts = isset( $stats['content'] ) && is_array( $stats['content'] ) ? $stats['content'] : [];

        $now = current_datetime();
        $today_key = $now->format( 'Y-m-d' );
        $today_count = isset( $daily[ $today_key ] ) ? (int) $daily[ $today_key ] : 0;

        $start_of_week = (int) get_option( 'start_of_week', 0 );
        $weekday = (int) $now->format( 'w' );
        $days_since_week_start = ( $weekday - $start_of_week + 7 ) % 7;
        $week_start = $now->modify( '-' . $days_since_week_start . ' days' )->format( 'Y-m-d' );
        $month_start = $now->format( 'Y-m-01' );

        $week_count = 0;
        $month_count = 0;
        foreach ( $daily as $date => $daily_count ) {
            $daily_count = (int) $daily_count;

            if ( $date >= $week_start && $date <= $today_key ) {
                $week_count += $daily_count;
            }

            if ( $date >= $month_start && $date <= $today_key ) {
                $month_count += $daily_count;
            }
        }

        $month_labels = [];
        $month_values = [];
        $first_month = $now->modify( 'first day of this month' );

        for ( $i = 11; $i >= 0; $i-- ) {
            $month = $first_month->modify( '-' . $i . ' months' );
            $key = $month->format( 'Y-m' );
            $month_labels[] = wp_date( 'M', $month->getTimestamp(), wp_timezone() );
            $month_values[ $key ] = 0;
        }

        foreach ( $daily as $date => $daily_count ) {
            $month_key = substr( $date, 0, 7 );
            if ( isset( $month_values[ $month_key ] ) ) {
                $month_values[ $month_key ] += (int) $daily_count;
            }
        }

        $chart_values = array_values( $month_values );
        $largest_value = ! empty( $chart_values ) ? max( $chart_values ) : 0;
        $chart_step = max( 1, (int) ceil( $largest_value / 4 ) );
        $chart_max = $chart_step * 4;

        $chart_left = 54;
        $chart_top = 20;
        $chart_width = 916;
        $chart_height = 220;
        $chart_bottom = $chart_top + $chart_height;
        $points = [];

        foreach ( $chart_values as $index => $value ) {
            $x = $chart_left + ( $index * ( $chart_width / 11 ) );
            $y = $chart_bottom - ( ( $value / $chart_max ) * $chart_height );
            $points[] = [
                'x' => $x,
                'y' => $y,
                'value' => $value,
            ];
        }

        arsort( $content_counts, SORT_NUMERIC );
        $top_content = array_slice( $content_counts, 0, 10, true );

        $legacy_total = isset( $stats['legacy_total'] )
            ? (int) $stats['legacy_total']
            : ( empty( $stats['started_at'] ) ? $total : 0 );
        ?>
        <div class="show-support-tab-panel show-support-tab-panel-stats">
            <div class="show-support-stat-grid">
                <?php
                $cards = [
                    __( 'Total', 'show-support' ) => $total,
                    __( 'Today', 'show-support' ) => $today_count,
                    __( 'This Week', 'show-support' ) => $week_count,
                    __( 'This Month', 'show-support' ) => $month_count,
                ];

                foreach ( $cards as $label => $value ) :
                    ?>
                    <div class="show-support-stat-card">
                        <div class="show-support-stat-label"><?php echo esc_html( $label ); ?></div>
                        <div class="show-support-stat-value"><?php echo esc_html( number_format_i18n( $value ) ); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="show-support-settings-card show-support-chart-card">
                <h2><?php esc_html_e( 'Support Over Time', 'show-support' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Past 12 months', 'show-support' ); ?></p>

                <div class="show-support-chart-wrap">
                    <svg class="show-support-chart" viewBox="0 0 1000 290" role="img" aria-labelledby="show-support-chart-title">
                        <title id="show-support-chart-title"><?php esc_html_e( 'Monthly support over the past 12 months', 'show-support' ); ?></title>

                        <?php for ( $grid_index = 0; $grid_index <= 4; $grid_index++ ) : ?>
                            <?php
                            $grid_y = $chart_top + ( $grid_index * ( $chart_height / 4 ) );
                            $grid_value = $chart_max - ( $grid_index * $chart_step );
                            ?>
                            <line class="show-support-chart-gridline" x1="<?php echo esc_attr( $chart_left ); ?>" y1="<?php echo esc_attr( $grid_y ); ?>" x2="970" y2="<?php echo esc_attr( $grid_y ); ?>"></line>
                            <text class="show-support-chart-y-label" x="42" y="<?php echo esc_attr( $grid_y + 4 ); ?>" text-anchor="end"><?php echo esc_html( number_format_i18n( $grid_value ) ); ?></text>
                        <?php endfor; ?>

                        <polyline
                            class="show-support-chart-line"
                            points="<?php echo esc_attr( implode( ' ', array_map( function ( $point ) { return $point['x'] . ',' . $point['y']; }, $points ) ) ); ?>"
                        ></polyline>

                        <?php foreach ( $points as $index => $point ) : ?>
                            <circle class="show-support-chart-point" cx="<?php echo esc_attr( $point['x'] ); ?>" cy="<?php echo esc_attr( $point['y'] ); ?>" r="4"></circle>
                            <text class="show-support-chart-value" x="<?php echo esc_attr( $point['x'] ); ?>" y="<?php echo esc_attr( max( 11, $point['y'] - 10 ) ); ?>" text-anchor="middle"><?php echo esc_html( number_format_i18n( $point['value'] ) ); ?></text>
                            <text class="show-support-chart-x-label" x="<?php echo esc_attr( $point['x'] ); ?>" y="270" text-anchor="middle"><?php echo esc_html( $month_labels[ $index ] ); ?></text>
                        <?php endforeach; ?>
                    </svg>

                    <p class="screen-reader-text">
                        <?php
                        $accessible_values = [];
                        foreach ( $chart_values as $index => $value ) {
                            $accessible_values[] = sprintf(
                                /* translators: 1: month label, 2: support count. */
                                __( '%1$s: %2$s supports', 'show-support' ),
                                $month_labels[ $index ],
                                number_format_i18n( $value )
                            );
                        }
                        echo esc_html( implode( '; ', $accessible_values ) );
                        ?>
                    </p>
                </div>
            </div>

            <div class="show-support-settings-card show-support-content-card">
                <h2><?php esc_html_e( 'Most-Supported Content', 'show-support' ); ?></h2>
                <table class="widefat striped show-support-content-table">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e( 'Content', 'show-support' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Type', 'show-support' ); ?></th>
                            <th scope="col" class="show-support-count-column"><?php esc_html_e( 'Supports', 'show-support' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $top_content ) ) : ?>
                            <tr>
                                <td colspan="3"><?php esc_html_e( 'No support has been recorded on individual content yet.', 'show-support' ); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $top_content as $content_id => $support_count ) : ?>
                                <?php
                                $post = get_post( $content_id );
                                $title = $post ? get_the_title( $post ) : sprintf( __( 'Deleted content #%d', 'show-support' ), $content_id );
                                $edit_link = $post ? get_edit_post_link( $post->ID ) : '';
                                $post_type = $post ? get_post_type_object( $post->post_type ) : null;
                                $type_label = $post_type ? $post_type->labels->singular_name : __( 'Deleted', 'show-support' );
                                ?>
                                <tr>
                                    <td>
                                        <?php if ( $edit_link ) : ?>
                                            <a href="<?php echo esc_url( $edit_link ); ?>"><strong><?php echo esc_html( $title ); ?></strong></a>
                                        <?php else : ?>
                                            <strong><?php echo esc_html( $title ); ?></strong>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html( $type_label ); ?></td>
                                    <td class="show-support-count-column"><?php echo esc_html( number_format_i18n( (int) $support_count ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ( $legacy_total > 0 ) : ?>
                <p class="description show-support-stats-note">
                    <?php esc_html_e( 'Total includes support recorded before detailed stats were introduced. Time-based and content stats begin with this version.', 'show-support' ); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the settings page.
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        
        // Determine active tab.
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'stats';
        if ( ! in_array( $active_tab, [ 'stats', 'appearance', 'utilities', 'how-to' ], true ) ) {
            $active_tab = 'stats';
        }
        
        $base_url = admin_url( 'themes.php?page=show-support' );
        ?>
        <div class="wrap show-support-settings-wrap">
            <div class="show-support-admin-header">
                <div class="show-support-admin-header-inner">
                    <h1><?php esc_html_e( 'Show Support', 'show-support' ); ?></h1>
                    <h2 class="nav-tab-wrapper show-support-tabs">
                <a
                href="<?php echo esc_url( add_query_arg( [ 'tab' => 'stats' ], $base_url ) ); ?>"
                class="nav-tab <?php echo ( 'stats' === $active_tab ) ? 'nav-tab-active' : ''; ?>"
                >
                <?php esc_html_e( 'Stats', 'show-support' ); ?>
            </a>
                <a
                href="<?php echo esc_url( add_query_arg( [ 'tab' => 'appearance' ], $base_url ) ); ?>"
                class="nav-tab <?php echo ( 'appearance' === $active_tab ) ? 'nav-tab-active' : ''; ?>"
                >
                <?php esc_html_e( 'Appearance', 'show-support' ); ?>
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'tab', 'utilities', $base_url ) ); ?>"
               class="nav-tab <?php echo $active_tab === 'utilities' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e( 'Utilities', 'show-support' ); ?>
            </a>
            <a
            href="<?php echo esc_url( add_query_arg( [ 'tab' => 'how-to' ], $base_url ) ); ?>"
            class="nav-tab <?php echo ( 'how-to' === $active_tab ) ? 'nav-tab-active' : ''; ?>"
            >
            <?php esc_html_e( 'How to Use', 'show-support' ); ?>
        </a>
                    </h2>
                </div>
            </div>

            <div class="show-support-admin-content">
    
    <?php if ( 'stats' === $active_tab ) : ?>

        <?php $this->render_stats_dashboard(); ?>

    <?php elseif ( 'appearance' === $active_tab ) : ?>
        
        <div class="show-support-tab-panel show-support-tab-panel-appearance">
            <div class="show-support-settings-card">
                <form action="options.php" method="post">
                    <?php
                    settings_fields( 'show_support_settings_group' );
                    do_settings_sections( 'show-support' );
                    submit_button();
                    ?>
                    </form>
                </div>

            <?php elseif ( 'utilities' === $active_tab ) : ?>

            <div class="show-support-tab-panel show-support-tab-panel-utilities">
                <div class="show-support-settings-card">
                    <form action="options.php" method="post">
                        <?php
                        settings_fields( 'show_support_settings_group' );
                        do_settings_sections( 'show-support-utilities' );
                        submit_button();
                        ?>
                    </form>
                </div>

                <!-- Reset count button -->
                <div class="show-support-settings-card show-support-reset-card">
                    <h2><?php esc_html_e( 'Support Count', 'show-support' ); ?></h2>
                    <p>
                        <?php esc_html_e( 'If you want to start over, you can reset the total Show Support count and all detailed stats.', 'show-support' ); ?>
                    </p>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'show_support_reset_count', 'show_support_reset_nonce' ); ?>
                        <input type="hidden" name="action" value="show_support_reset_count" />
                        <?php submit_button( __( 'Reset Support Count', 'show-support' ), 'delete' ); ?>
                    </form>
                </div>
            </div>

            
            <?php else : ?>
                
                <div class="show-support-tab-panel show-support-tab-panel-howto">
                    <div class="show-support-settings-card">
                        <h2><?php esc_html_e( 'How to Use Show Support', 'show-support' ); ?></h2>
                        
                        <h3><?php esc_html_e( 'Basic usage', 'show-support' ); ?></h3>
                        <p>
                            <?php esc_html_e( 'Once the plugin is activated, a sticky emoji button appears in the bottom corner of your site. Visitors can click it to show support and trigger an animated burst.', 'show-support' ); ?>
                        </p>
                        
                        <h3><?php esc_html_e( 'Change the emoji', 'show-support' ); ?></h3>
                        <p>
                            <?php esc_html_e( 'To change the emoji (heart, star, cat, or beer), go to the Appearance tab and use the Emoji dropdown. The button and confetti will both use the selected emoji.', 'show-support' ); ?>
                        </p>
                        
                        <h3><?php esc_html_e( 'Add more emojis (developer)', 'show-support' ); ?></h3>
                        <p>
                            <?php esc_html_e( 'Developers can add more emoji options by editing the get_emoji_map() method in the plugin PHP. Each key represents an option in the dropdown; each value is the emoji that will be displayed.', 'show-support' ); ?>
                        </p>
                        <pre><code><?php echo esc_html(
                            'private function get_emoji_map() {
                            return [
                            \'heart\' => \'❤️\',
                            \'star\'  => \'⭐\',
                            \'cat\'   => \'😺\',
                            \'beer\'  => \'🍻\',
                            \'sparkles\' => \'✨\', // example of adding another option
                            ];
                            }'
                            ); ?></code></pre>
                            
                            <h3><?php esc_html_e( 'Customize via CSS', 'show-support' ); ?></h3>
                            <p>
                                <?php esc_html_e( 'You can further customize the appearance with your own CSS in your theme or a custom plugin.', 'show-support' ); ?>
                            </p>
                            <p><?php esc_html_e( 'Useful selectors:', 'show-support' ); ?></p>
                            <ul>
                                <li><code>.show-support-wrapper</code> – <?php esc_html_e( 'outer container for the button and count', 'show-support' ); ?></li>
                                <li><code>.show-support-btn</code> – <?php esc_html_e( 'the sticky emoji button', 'show-support' ); ?></li>
                                <li><code>.show-support-count</code> – <?php esc_html_e( 'the support count label', 'show-support' ); ?></li>
                                <li><code>.show-support-confetti</code> – <?php esc_html_e( 'individual emoji used in the burst animation', 'show-support' ); ?></li>
                            </ul>
                            <pre><code><?php echo esc_html(
                                '.show-support-btn {
                                box-shadow: 0 4px 10px rgba(0,0,0,0.12);
                                }
                                
                                .show-support-count {
                                font-weight: 600;
                                opacity: 0.8;
                                }'
                                ); ?></code></pre>
                                </div>
                            </div>
                            
                            <?php endif; ?>
                        </div>
                        </div>
                        <?php
                        }

    /**
     * Redirect to the Show Support settings page once after activation.
     */
    public function maybe_redirect_to_settings() {
        $do_redirect = get_option( 'show_support_do_activation_redirect', 0 );

        if ( ! $do_redirect ) {
            return;
        }

        // Delete the flag so this only happens once.
        delete_option( 'show_support_do_activation_redirect' );

        // Don’t redirect in network admin or during bulk activation.
        if ( is_network_admin() || isset( $_GET['activate-multi'] ) ) {
            return;
        }

        wp_safe_redirect( admin_url( 'themes.php?page=show-support' ) );
        exit;
    }
}

new Show_Support();

/**
 * Set a flag on plugin activation so we can redirect once.
 */
function show_support_activate() {
    add_option( 'show_support_do_activation_redirect', 1 );
}

register_activation_hook( __FILE__, 'show_support_activate' );
