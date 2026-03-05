<?php

namespace AlwaysCurious\WorkOSWP;

defined('ABSPATH') || exit;

class Plugin {

    private static ?self $instance = null;
    private ?AuthKit $authkit = null;

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->configure_workos();
        $this->init_hooks();
    }

    /**
     * Configure the WorkOS SDK with stored credentials.
     */
    private function configure_workos(): void {
        $api_key = get_option('workos_api_key');
        $client_id = get_option('workos_client_id');

        if (!empty($api_key)) {
            \WorkOS\WorkOS::setApiKey($api_key);
        }
        if (!empty($client_id)) {
            \WorkOS\WorkOS::setClientId($client_id);
        }
    }

    /**
     * Check if the plugin is fully configured.
     */
    public function is_configured(): bool {
        return !empty(get_option('workos_api_key')) && !empty(get_option('workos_client_id'));
    }

    private function init_hooks(): void {
        register_activation_hook(WORKOS_WP_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(WORKOS_WP_PLUGIN_FILE, [$this, 'deactivate']);

        add_action('admin_menu', [$this, 'register_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);

        // Initialize AuthKit if configured.
        if ($this->is_configured()) {
            $this->authkit = new AuthKit();
            $this->authkit->register_hooks();
        }

        add_action('init', [$this, 'register_rewrite_rules']);
    }

    /**
     * Register rewrite rules for the callback endpoint.
     */
    public function register_rewrite_rules(): void {
        add_rewrite_rule('^workos/callback/?$', 'index.php?workos_callback=1', 'top');
        add_filter('query_vars', function (array $vars): array {
            $vars[] = 'workos_callback';
            return $vars;
        });
    }

    public function activate(): void {
        $this->register_rewrite_rules();
        flush_rewrite_rules();
    }

    public function deactivate(): void {
        flush_rewrite_rules();
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

        register_setting('workos_settings', 'workos_organization_id', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting('workos_settings', 'workos_role_map', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_role_map'],
            'default'           => [],
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

        add_settings_field(
            'workos_organization_id',
            __('Organization ID', 'workos-for-wordpress'),
            [$this, 'render_field_organization_id'],
            'workos-settings',
            'workos_general'
        );

        add_settings_field(
            'workos_redirect_uri',
            __('Redirect URI', 'workos-for-wordpress'),
            [$this, 'render_field_redirect_uri'],
            'workos-settings',
            'workos_general'
        );
    }

    public function render_settings_page(): void {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <?php if (!$this->is_configured()): ?>
                <div class="notice notice-warning inline">
                    <p><?php esc_html_e('Please configure your WorkOS API Key and Client ID to enable AuthKit login.', 'workos-for-wordpress'); ?></p>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['diagnostics'])): ?>
                <?php $this->render_diagnostics(); ?>
            <?php else: ?>
                <p><a href="<?php echo esc_url(add_query_arg('diagnostics', '1')); ?>"><?php esc_html_e('Show Diagnostics', 'workos-for-wordpress'); ?></a></p>
            <?php endif; ?>
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

    public function render_field_organization_id(): void {
        $value = get_option('workos_organization_id', '');
        echo '<input type="text" name="workos_organization_id" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('Organization ID from WorkOS (starts with org_). Add your site domain to this Organization in the WorkOS Dashboard to bind them together.', 'workos-for-wordpress') . '</p>';
    }

    public function render_diagnostics(): void {
        if (!$this->is_configured()) {
            return;
        }

        $client_id = get_option('workos_client_id');
        $api_key = get_option('workos_api_key');
        $org_id = get_option('workos_organization_id');

        echo '<div class="card" style="max-width:800px;margin-top:10px;">';
        echo '<h3>' . esc_html__('Diagnostics', 'workos-for-wordpress') . '</h3>';
        echo '<table class="widefat striped"><tbody>';

        echo '<tr><td><strong>Client ID</strong></td><td><code>' . esc_html($client_id) . '</code> (' . strlen($client_id) . ' chars)</td></tr>';
        echo '<tr><td><strong>API Key</strong></td><td><code>' . esc_html(substr($api_key, 0, 7)) . '...' . esc_html(substr($api_key, -4)) . '</code> (' . strlen($api_key) . ' chars)</td></tr>';
        echo '<tr><td><strong>Organization ID</strong></td><td>' . ($org_id ? '<code>' . esc_html($org_id) . '</code>' : '<em>Not set</em>') . '</td></tr>';

        try {
            $um = new \WorkOS\UserManagement();
            $auth_url = $um->getAuthorizationUrl(
                AuthKit::get_callback_url(),
                null,
                \WorkOS\UserManagement::AUTHORIZATION_PROVIDER_AUTHKIT,
                null,
                $org_id ?: null
            );
            echo '<tr><td><strong>Auth URL</strong></td><td style="word-break:break-all;"><code>' . esc_html($auth_url) . '</code></td></tr>';
        } catch (\Exception $e) {
            echo '<tr><td><strong>Auth URL Error</strong></td><td style="color:red;">' . esc_html($e->getMessage()) . '</td></tr>';
        }

        echo '</tbody></table></div>';
    }

    public function render_field_redirect_uri(): void {
        $url = AuthKit::get_callback_url();
        echo '<code>' . esc_html($url) . '</code>';
        echo '<p class="description">' . esc_html__('Add this URL as a Redirect URI in your WorkOS Dashboard.', 'workos-for-wordpress') . '</p>';
    }
}
