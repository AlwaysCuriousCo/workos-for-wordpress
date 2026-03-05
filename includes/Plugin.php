<?php

namespace AlwaysCurious\WorkOSWP;

defined('ABSPATH') || exit;

class Plugin {

    private static ?self $instance = null;

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks(): void {
        register_activation_hook(WORKOS_WP_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(WORKOS_WP_PLUGIN_FILE, [$this, 'deactivate']);

        add_action('admin_menu', [$this, 'register_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * Get a configured WorkOS client instance.
     */
    public function get_client(): ?\WorkOS\WorkOS {
        $api_key = get_option('workos_api_key');
        if (empty($api_key)) {
            return null;
        }

        return new \WorkOS\WorkOS($api_key);
    }

    public function activate(): void {
        // Placeholder for activation tasks.
    }

    public function deactivate(): void {
        // Placeholder for deactivation tasks.
    }

    public function register_settings_page(): void {
        add_options_page(
            __('WorkOS Settings', 'workos-for-wordpress'),
            __('WorkOS', 'workos-for-wordpress'),
            'manage_options',
            'workos-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings(): void {
        register_setting('workos_settings', 'workos_api_key', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting('workos_settings', 'workos_client_id', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        add_settings_section(
            'workos_general',
            __('WorkOS Configuration', 'workos-for-wordpress'),
            '__return_null',
            'workos-settings'
        );

        add_settings_field(
            'workos_api_key',
            __('API Key', 'workos-for-wordpress'),
            [$this, 'render_field_api_key'],
            'workos-settings',
            'workos_general'
        );

        add_settings_field(
            'workos_client_id',
            __('Client ID', 'workos-for-wordpress'),
            [$this, 'render_field_client_id'],
            'workos-settings',
            'workos_general'
        );
    }

    public function render_settings_page(): void {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('workos_settings');
                do_settings_sections('workos-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function render_field_api_key(): void {
        $value = get_option('workos_api_key', '');
        echo '<input type="password" name="workos_api_key" value="' . esc_attr($value) . '" class="regular-text" />';
    }

    public function render_field_client_id(): void {
        $value = get_option('workos_client_id', '');
        echo '<input type="text" name="workos_client_id" value="' . esc_attr($value) . '" class="regular-text" />';
    }
}
