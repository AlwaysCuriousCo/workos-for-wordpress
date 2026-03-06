<?php

namespace AlwaysCurious\WorkOSWP;

defined('ABSPATH') || exit;

class Plugin {

    private static ?self $instance = null;
    private ?AuthKit $authkit = null;
    private ?LearningMode $learning_mode = null;

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
        $api_key = $this->get_api_key();
        $client_id = $this->get_client_id();

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
        return !empty($this->get_api_key()) && !empty($this->get_client_id());
    }

    /**
     * Get a config value, checking wp-config.php constants first, then database options.
     */
    public function get_api_key(): string {
        return defined('WORKOS_API_KEY') ? WORKOS_API_KEY : get_option('workos_api_key', '');
    }

    public function get_client_id(): string {
        return defined('WORKOS_CLIENT_ID') ? WORKOS_CLIENT_ID : get_option('workos_client_id', '');
    }

    public function get_organization_id(): string {
        return defined('WORKOS_ORGANIZATION_ID') ? WORKOS_ORGANIZATION_ID : get_option('workos_organization_id', '');
    }

    /**
     * Check if a config value is defined via wp-config.php constant.
     */
    public function is_constant(string $name): bool {
        return defined($name);
    }

    private function init_hooks(): void {
        register_activation_hook(WORKOS_WP_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(WORKOS_WP_PLUGIN_FILE, [$this, 'deactivate']);

        add_action('admin_menu', [$this, 'register_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // Initialize AuthKit if configured.
        if ($this->is_configured()) {
            $this->authkit = new AuthKit();
            $this->authkit->register_hooks();
        }

        add_action('init', [$this, 'register_rewrite_rules']);
        add_action('admin_post_workos_clear_activity_log', [$this, 'handle_clear_activity_log']);

        // Learning Mode AJAX handlers.
        add_action('wp_ajax_workos_learning_mode_sync', [$this, 'handle_learning_mode_sync']);
        add_action('wp_ajax_workos_learning_mode_sync_single', [$this, 'handle_learning_mode_sync_single']);

        // Users table: WorkOS status column and reSync action.
        add_filter('manage_users_columns', [$this, 'add_users_workos_column']);
        add_filter('manage_users_custom_column', [$this, 'render_users_workos_column'], 10, 3);
        add_filter('user_row_actions', [$this, 'add_users_resync_action'], 10, 2);
        add_action('wp_ajax_workos_resync_user', [$this, 'handle_resync_user']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_users_table_assets']);

        // Plugin row meta: sponsor link.
        add_filter('plugin_action_links_' . plugin_basename(WORKOS_WP_PLUGIN_FILE), [$this, 'plugin_action_links']);

        // Admin bar WorkOS status indicator.
        add_action('admin_bar_menu', [$this, 'admin_bar_workos_node'], 100);
        add_action('wp_head', [$this, 'admin_bar_inline_styles']);
        add_action('admin_head', [$this, 'admin_bar_inline_styles']);

        // Show plugin version in admin footer on WorkOS pages.
        add_filter('admin_footer_text', [$this, 'admin_footer_version']);
    }

    /**
     * Add action links next to "Deactivate" on the Plugins list page.
     */
    public function plugin_action_links(array $links): array {
        $links['settings'] = sprintf(
            '<a href="%s">%s</a>',
            admin_url('admin.php?page=workos-settings'),
            __('Settings', 'workos-for-wordpress')
        );
        $links['sponsor'] = sprintf(
            '<a href="%s" target="_blank" rel="noopener" style="color:#6C47FF;font-weight:500;">%s</a>',
            'https://github.com/sponsors/AlwaysCuriousCo',
            __('Sponsor', 'workos-for-wordpress')
        );
        return $links;
    }

    /**
     * Add a WorkOS status node to the WordPress admin bar.
     */
    public function admin_bar_workos_node(\WP_Admin_Bar $admin_bar): void {
        if (!is_user_logged_in() || !is_admin_bar_showing()) {
            return;
        }

        $user_id = get_current_user_id();
        $workos_user_id = get_user_meta($user_id, '_workos_user_id', true);
        $synced_at = get_user_meta($user_id, '_workos_synced_at', true);
        $org_id = get_user_meta($user_id, '_workos_organization_id', true);
        $is_workos_session = !empty($workos_user_id);

        // Determine status.
        if (!empty(get_user_meta($user_id, '_workos_suspended', true))) {
            $status = 'suspended';
            $status_label = __('Suspended', 'workos-for-wordpress');
            $dot_color = '#E5484D';
        } elseif (!empty($synced_at)) {
            $status = 'synced';
            $status_label = __('Synced', 'workos-for-wordpress');
            $dot_color = '#30A46C';
        } elseif ($is_workos_session) {
            $status = 'linked';
            $status_label = __('Linked', 'workos-for-wordpress');
            $dot_color = '#6C47FF';
        } else {
            $status = 'none';
            $status_label = __('Not connected', 'workos-for-wordpress');
            $dot_color = '#8B8D98';
        }

        $icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 242 210" style="width:16px;height:14px;vertical-align:middle;margin-right:4px;position:relative;top:-1px;">'
            . '<path fill="currentColor" d="M1,105c0,4.56,1.2,9.12,3.52,13.04l42.08,72.88c4.32,7.44,10.88,13.52,19.04,16.24,16.08,5.36,32.72-1.52,40.64-15.28l10.16-17.6-40.08-69.28L118.68,31.64l10.16-17.6c3.04-5.28,7.12-9.6,11.92-13.04h-65.28c-11.44,0-22,6.08-27.68,16L4.52,91.96c-2.32,3.92-3.52,8.48-3.52,13.04Z"/>'
            . '<path fill="currentColor" d="M241,105c0-4.56-1.2-9.12-3.52-13.04l-42.64-73.84c-7.92-13.68-24.56-20.56-40.64-15.28-8.16,2.72-14.72,8.8-19.04,16.24l-9.6,16.56,40.08,69.36-42.32,73.36-10.16,17.6c-3.04,5.2-7.12,9.6-11.92,13.04h65.28c11.44,0,22-6.08,27.68-16l43.28-74.96c2.32-3.92,3.52-8.48,3.52-13.04Z"/>'
            . '</svg>';

        // Top-level node.
        $admin_bar->add_node([
            'id'    => 'workos',
            'title' => $icon_svg . '<span class="workos-ab-dot" style="background:' . esc_attr($dot_color) . ';"></span>',
            'href'  => current_user_can('manage_options') ? admin_url('admin.php?page=workos-settings') : false,
            'meta'  => [
                'title' => sprintf(__('WorkOS: %s', 'workos-for-wordpress'), $status_label),
            ],
        ]);

        // Sub-item: status.
        $admin_bar->add_node([
            'id'     => 'workos-status',
            'parent' => 'workos',
            'title'  => sprintf(__('Status: %s', 'workos-for-wordpress'), $status_label),
        ]);

        // Sub-item: WorkOS user ID if linked.
        if ($is_workos_session) {
            $admin_bar->add_node([
                'id'     => 'workos-user-id',
                'parent' => 'workos',
                'title'  => sprintf(__('User: %s', 'workos-for-wordpress'), substr($workos_user_id, 0, 20) . '…'),
                'meta'   => ['title' => $workos_user_id],
            ]);
        }

        // Sub-item: org ID if set.
        if (!empty($org_id)) {
            $admin_bar->add_node([
                'id'     => 'workos-org',
                'parent' => 'workos',
                'title'  => sprintf(__('Org: %s', 'workos-for-wordpress'), substr($org_id, 0, 20) . '…'),
                'meta'   => ['title' => $org_id],
            ]);
        }

        // Sub-item: link to settings (admins only).
        if (current_user_can('manage_options')) {
            $admin_bar->add_node([
                'id'     => 'workos-settings-link',
                'parent' => 'workos',
                'title'  => __('Settings', 'workos-for-wordpress'),
                'href'   => admin_url('admin.php?page=workos-settings'),
            ]);

            $admin_bar->add_node([
                'id'     => 'workos-dashboard-link',
                'parent' => 'workos',
                'title'  => __('WorkOS Dashboard', 'workos-for-wordpress'),
                'href'   => 'https://dashboard.workos.com',
                'meta'   => ['target' => '_blank', 'rel' => 'noopener'],
            ]);
        }
    }

    /**
     * Inline CSS for the admin bar WorkOS node.
     */
    public function admin_bar_inline_styles(): void {
        if (!is_user_logged_in() || !is_admin_bar_showing()) {
            return;
        }
        ?>
        <style>
            #wpadminbar #wp-admin-bar-workos > .ab-item {
                display: flex;
                align-items: center;
            }
            .workos-ab-dot {
                display: inline-block;
                width: 8px;
                height: 8px;
                border-radius: 50%;
                margin-left: 2px;
            }
        </style>
        <?php
    }

    /**
     * Show plugin version in the admin footer on WorkOS settings pages.
     */
    public function admin_footer_version(string $text): string {
        $screen = get_current_screen();
        if ($screen && str_contains($screen->id, 'workos')) {
            return sprintf(
                'WorkOS for WordPress v%s | %s',
                WORKOS_WP_VERSION,
                $text
            );
        }
        return $text;
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
        if (ActivityLog::is_enabled()) {
            ActivityLog::create_table();
        }
    }

    public function deactivate(): void {
        flush_rewrite_rules();
    }

    /**
     * Enqueue admin CSS only on our plugin pages.
     */
    public function enqueue_admin_assets(string $hook): void {
        $plugin_pages = [
            'toplevel_page_workos-settings',
            'workos_page_workos-role-mapping',
            'workos_page_workos-diagnostics',
            'workos_page_workos-usage',
            'workos_page_workos-learning-mode',
        ];

        if (!in_array($hook, $plugin_pages, true)) {
            return;
        }

        wp_enqueue_style(
            'workos-admin',
            plugins_url('assets/css/admin.css', WORKOS_WP_PLUGIN_FILE),
            [],
            filemtime(WORKOS_WP_PLUGIN_DIR . 'assets/css/admin.css')
        );
    }

    public function register_settings_page(): void {
        $icon_svg = 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 242 210">'
            . '<path fill="black" d="M1,105c0,4.56,1.2,9.12,3.52,13.04l42.08,72.88c4.32,7.44,10.88,13.52,19.04,16.24,16.08,5.36,32.72-1.52,40.64-15.28l10.16-17.6-40.08-69.28L118.68,31.64l10.16-17.6c3.04-5.28,7.12-9.6,11.92-13.04h-65.28c-11.44,0-22,6.08-27.68,16L4.52,91.96c-2.32,3.92-3.52,8.48-3.52,13.04Z"/>'
            . '<path fill="black" d="M241,105c0-4.56-1.2-9.12-3.52-13.04l-42.64-73.84c-7.92-13.68-24.56-20.56-40.64-15.28-8.16,2.72-14.72,8.8-19.04,16.24l-9.6,16.56,40.08,69.36-42.32,73.36-10.16,17.6c-3.04,5.2-7.12,9.6-11.92,13.04h65.28c11.44,0,22-6.08,27.68-16l43.28-74.96c2.32-3.92,3.52-8.48,3.52-13.04Z"/>'
            . '</svg>'
        );

        add_menu_page(
            __('WorkOS', 'workos-for-wordpress'),
            __('WorkOS', 'workos-for-wordpress'),
            'manage_options',
            'workos-settings',
            [$this, 'render_api_config_page'],
            $icon_svg,
            80
        );

        add_submenu_page(
            'workos-settings',
            __('Welcome', 'workos-for-wordpress'),
            __('Welcome', 'workos-for-wordpress'),
            'manage_options',
            'workos-settings',
            [$this, 'render_api_config_page']
        );

        // Only show additional pages when API Key and Client ID are configured.
        if ($this->is_configured()) {
            add_submenu_page(
                'workos-settings',
                __('Roles', 'workos-for-wordpress'),
                __('Roles', 'workos-for-wordpress'),
                'manage_options',
                'workos-role-mapping',
                [$this, 'render_role_mapping_page']
            );

            add_submenu_page(
                'workos-settings',
                __('Usage', 'workos-for-wordpress'),
                __('Usage', 'workos-for-wordpress'),
                'manage_options',
                'workos-usage',
                [$this, 'render_usage_page']
            );

            add_submenu_page(
                'workos-settings',
                __('Learning Mode', 'workos-for-wordpress'),
                __('Learning Mode', 'workos-for-wordpress'),
                'manage_options',
                'workos-learning-mode',
                [$this, 'render_learning_mode_page']
            );

            add_submenu_page(
                'workos-settings',
                __('Diagnostics', 'workos-for-wordpress'),
                __('Diagnostics', 'workos-for-wordpress'),
                'manage_options',
                'workos-diagnostics',
                [$this, 'render_diagnostics_page']
            );
        }
    }

    public function register_settings(): void {
        // --- API Configuration ---
        register_setting('workos_api_settings', 'workos_api_key', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting('workos_api_settings', 'workos_client_id', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        // --- Organization & Role Mapping ---
        register_setting('workos_role_settings', 'workos_organization_id', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting('workos_role_settings', 'workos_role_map', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_role_map'],
            'default'           => [],
        ]);

        // --- Learning Mode ---
        register_setting('workos_learning_mode_settings', 'workos_learning_mode', [
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => false,
        ]);

        register_setting('workos_learning_mode_settings', 'workos_org_entitlement_gate', [
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => false,
        ]);

        // --- Activity Tracking ---
        register_setting('workos_usage_settings', 'workos_activity_tracking', [
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => false,
        ]);
    }

    /* ================================================================
       Page Renderers
       ================================================================ */

    /**
     * Render the shared global header with WorkOS logo and navigation tabs.
     */
    private function render_global_header(string $current_slug): void {
        $logo_url = plugins_url('WorkOS-branding/SVG/WorkOS_Lockup_Full_Color.svg', WORKOS_WP_PLUGIN_FILE);

        $tabs = [
            'workos-settings' => __('Welcome', 'workos-for-wordpress'),
        ];

        if ($this->is_configured()) {
            $tabs['workos-role-mapping']  = __('Roles', 'workos-for-wordpress');
            $tabs['workos-learning-mode'] = __('Learning Mode', 'workos-for-wordpress');
            $tabs['workos-usage']         = __('Usage', 'workos-for-wordpress');
            $tabs['workos-diagnostics']   = __('Diagnostics', 'workos-for-wordpress');
        }
        ?>
        <div class="workos-global-header">
            <div class="workos-global-header-left">
                <div class="workos-global-header-logo">
                    <img src="<?php echo esc_url($logo_url); ?>" alt="WorkOS" />
                </div>
                <div class="workos-global-header-separator"></div>
                <nav class="workos-global-header-nav">
                    <?php foreach ($tabs as $slug => $label): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . $slug)); ?>"
                           class="<?php echo $slug === $current_slug ? 'workos-nav-active' : ''; ?>">
                            <?php echo esc_html($label); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>
            <div class="workos-global-header-links">
                <a href="https://workos.com/" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e('WorkOS Dashboard', 'workos-for-wordpress'); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                    <span><?php esc_html_e('WorkOS', 'workos-for-wordpress'); ?></span>
                </a>
                <a href="https://www.authkit.com/" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e('AuthKit', 'workos-for-wordpress'); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <span><?php esc_html_e('AuthKit', 'workos-for-wordpress'); ?></span>
                </a>
            </div>
        </div>
        <?php
    }

    public function render_api_config_page(): void {
        $api_key = $this->get_api_key();
        $client_id = $this->get_client_id();
        $api_key_locked = $this->is_constant('WORKOS_API_KEY');
        $client_id_locked = $this->is_constant('WORKOS_CLIENT_ID');
        $all_locked = $api_key_locked && $client_id_locked;
        $redirect_uri = AuthKit::get_callback_url();
        ?>
        <div class="wrap workos-admin">
            <?php $this->render_global_header('workos-settings'); ?>

            <div class="workos-welcome-hero">
                <div class="workos-welcome-hero-content">
                    <h1><?php esc_html_e('Welcome to WorkOS', 'workos-for-wordpress'); ?></h1>
                    <p><?php esc_html_e('WorkOS provides a complete user management platform powered by AuthKit — a drop-in authentication UI with support for passwords, social logins, passkeys, SSO, and MFA. Enterprise-ready identity, without the complexity.', 'workos-for-wordpress'); ?></p>
                    <div class="workos-welcome-features">
                        <div class="workos-welcome-feature">
                            <div class="workos-welcome-feature-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            </div>
                            <div>
                                <strong><?php esc_html_e('AuthKit', 'workos-for-wordpress'); ?></strong>
                                <span><?php esc_html_e('Beautiful, hosted login UI that replaces wp-login.php with a modern authentication experience.', 'workos-for-wordpress'); ?></span>
                            </div>
                        </div>
                        <div class="workos-welcome-feature">
                            <div class="workos-welcome-feature-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            </div>
                            <div>
                                <strong><?php esc_html_e('Organizations & Roles', 'workos-for-wordpress'); ?></strong>
                                <span><?php esc_html_e('Bind your site to a WorkOS organization and automatically sync user roles on login.', 'workos-for-wordpress'); ?></span>
                            </div>
                        </div>
                        <div class="workos-welcome-feature">
                            <div class="workos-welcome-feature-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            </div>
                            <div>
                                <strong><?php esc_html_e('Enterprise SSO & MFA', 'workos-for-wordpress'); ?></strong>
                                <span><?php esc_html_e('Support SAML, OIDC, and multi-factor authentication out of the box — no code required.', 'workos-for-wordpress'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="workos-page-header">
                <h1><?php esc_html_e('API Configuration', 'workos-for-wordpress'); ?></h1>
                <p class="workos-page-description"><?php esc_html_e('Connect your WordPress site to WorkOS by entering your API credentials.', 'workos-for-wordpress'); ?></p>
            </div>

            <?php if (!$this->is_configured()): ?>
                <div class="workos-alert workos-alert-warning">
                    <?php esc_html_e('Enter your WorkOS API Key and Client ID to enable AuthKit login.', 'workos-for-wordpress'); ?>
                </div>
            <?php endif; ?>

            <?php if ($api_key_locked || $client_id_locked): ?>
                <div class="workos-alert workos-alert-info">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <?php
                    if ($all_locked) {
                        esc_html_e('API Key and Client ID are defined in wp-config.php and cannot be changed here. To modify, update the WORKOS_API_KEY and WORKOS_CLIENT_ID constants.', 'workos-for-wordpress');
                    } elseif ($api_key_locked) {
                        esc_html_e('API Key is defined in wp-config.php via the WORKOS_API_KEY constant and cannot be changed here.', 'workos-for-wordpress');
                    } else {
                        esc_html_e('Client ID is defined in wp-config.php via the WORKOS_CLIENT_ID constant and cannot be changed here.', 'workos-for-wordpress');
                    }
                    ?>
                </div>
            <?php endif; ?>

            <form action="options.php" method="post">
                <?php settings_fields('workos_api_settings'); ?>

                <div class="workos-card">
                    <div class="workos-card-header">
                        <div class="workos-card-header-row">
                            <h2><?php esc_html_e('Credentials', 'workos-for-wordpress'); ?></h2>
                            <?php if (!$all_locked): ?>
                                <button type="button" class="workos-tooltip-toggle" aria-expanded="false" aria-controls="workos-const-tip" title="<?php esc_attr_e('wp-config.php usage', 'workos-for-wordpress'); ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                                </button>
                            <?php endif; ?>
                        </div>
                        <p><?php esc_html_e('Your API Key and Client ID can be found in the WorkOS Dashboard under API Keys.', 'workos-for-wordpress'); ?></p>
                        <?php if (!$all_locked): ?>
                            <div class="workos-tooltip-content" id="workos-const-tip" hidden>
                                <p><?php esc_html_e('For multi-site or managed deployments, you can define credentials as constants in wp-config.php instead of storing them in the database:', 'workos-for-wordpress'); ?></p>
                                <pre><code>define( 'WORKOS_API_KEY', 'sk_test_...' );
define( 'WORKOS_CLIENT_ID', 'client_...' );
define( 'WORKOS_ORGANIZATION_ID', 'org_...' );</code></pre>
                                <p><?php esc_html_e('When constants are defined, the corresponding fields become read-only and values are sourced from wp-config.php. This is recommended for version-controlled or environment-based configurations.', 'workos-for-wordpress'); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="workos-field<?php echo $api_key_locked ? ' workos-field-locked' : ''; ?>">
                        <label for="workos_api_key"><?php esc_html_e('API Key', 'workos-for-wordpress'); ?>
                            <?php if ($api_key_locked): ?>
                                <span class="workos-badge workos-badge-info"><?php esc_html_e('wp-config.php', 'workos-for-wordpress'); ?></span>
                            <?php endif; ?>
                        </label>
                        <?php if ($api_key_locked): ?>
                            <input type="password" id="workos_api_key" value="<?php echo esc_attr($api_key); ?>" disabled />
                            <p class="workos-field-description"><code>define( 'WORKOS_API_KEY', '...' );</code></p>
                        <?php else: ?>
                            <input type="password" id="workos_api_key" name="workos_api_key" value="<?php echo esc_attr($api_key); ?>" placeholder="sk_test_..." />
                        <?php endif; ?>
                    </div>

                    <div class="workos-field<?php echo $client_id_locked ? ' workos-field-locked' : ''; ?>">
                        <label for="workos_client_id"><?php esc_html_e('Client ID', 'workos-for-wordpress'); ?>
                            <?php if ($client_id_locked): ?>
                                <span class="workos-badge workos-badge-info"><?php esc_html_e('wp-config.php', 'workos-for-wordpress'); ?></span>
                            <?php endif; ?>
                        </label>
                        <?php if ($client_id_locked): ?>
                            <input type="text" id="workos_client_id" value="<?php echo esc_attr($client_id); ?>" disabled />
                            <p class="workos-field-description"><code>define( 'WORKOS_CLIENT_ID', '...' );</code></p>
                        <?php else: ?>
                            <input type="text" id="workos_client_id" name="workos_client_id" value="<?php echo esc_attr($client_id); ?>" placeholder="client_..." />
                        <?php endif; ?>
                    </div>
                </div>

                <div class="workos-card">
                    <div class="workos-card-header">
                        <h2><?php esc_html_e('Redirect URI', 'workos-for-wordpress'); ?></h2>
                        <p><?php esc_html_e('Add this URL as a Redirect URI in your WorkOS Dashboard under Redirects.', 'workos-for-wordpress'); ?></p>
                    </div>
                    <div class="workos-code"><?php echo esc_html($redirect_uri); ?></div>
                </div>

                <?php if (!$all_locked): ?>
                    <button type="submit" class="workos-btn workos-btn-primary"><?php esc_html_e('Save Changes', 'workos-for-wordpress'); ?></button>
                <?php endif; ?>
            </form>
        </div>

        <script>
        (function() {
            var btn = document.querySelector('.workos-tooltip-toggle');
            if (!btn) return;
            btn.addEventListener('click', function() {
                var tip = document.getElementById(btn.getAttribute('aria-controls'));
                var expanded = btn.getAttribute('aria-expanded') === 'true';
                btn.setAttribute('aria-expanded', String(!expanded));
                tip.hidden = expanded;
            });
        })();
        </script>
        <?php
    }

    public function render_role_mapping_page(): void {
        $org_id = $this->get_organization_id();
        $org_locked = $this->is_constant('WORKOS_ORGANIZATION_ID');
        $organizations = $this->fetch_workos_organizations();

        $role_map = get_option('workos_role_map', []);
        if (!is_array($role_map)) {
            $role_map = [];
        }

        $wp_roles = wp_roles()->get_names();
        $workos_roles = $this->fetch_workos_roles();

        // Build option HTML for JS-injected rows.
        $wp_options_html = '<option value="">' . esc_html__('-- Select --', 'workos-for-wordpress') . '</option>';
        foreach ($wp_roles as $role_slug => $role_name) {
            $wp_options_html .= '<option value="' . esc_attr($role_slug) . '">' . esc_html(translate_user_role($role_name)) . '</option>';
        }

        $workos_options_html = '<option value="">' . esc_html__('-- Select --', 'workos-for-wordpress') . '</option>';
        foreach ($workos_roles as $role) {
            $label = $role['name'] . ' (' . $role['slug'] . ')';
            if ($role['type'] === 'EnvironmentRole') {
                $label .= ' [global]';
            }
            $workos_options_html .= '<option value="' . esc_attr($role['slug']) . '">' . esc_html($label) . '</option>';
        }
        ?>
        <div class="wrap workos-admin">
            <?php $this->render_global_header('workos-role-mapping'); ?>

            <div class="workos-page-header">
                <h1><?php esc_html_e('Roles', 'workos-for-wordpress'); ?></h1>
                <p class="workos-page-description"><?php esc_html_e('Select which WorkOS organization this site belongs to and map its roles to WordPress roles.', 'workos-for-wordpress'); ?></p>
            </div>

            <?php if (!$this->is_configured()): ?>
                <div class="workos-alert workos-alert-warning">
                    <?php esc_html_e('API credentials must be configured before organization and role settings can be used.', 'workos-for-wordpress'); ?>
                </div>
            <?php endif; ?>

            <form action="options.php" method="post">
                <?php settings_fields('workos_role_settings'); ?>

                <div class="workos-card">
                    <div class="workos-card-header">
                        <h2><?php esc_html_e('Organization', 'workos-for-wordpress'); ?></h2>
                        <p><?php esc_html_e('Choose the WorkOS organization to bind to this WordPress site. Users who authenticate through this organization will be synced.', 'workos-for-wordpress'); ?></p>
                    </div>

                    <?php if ($org_locked): ?>
                        <div class="workos-alert workos-alert-info" style="margin-bottom: 16px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <?php esc_html_e('Organization ID is defined in wp-config.php via the WORKOS_ORGANIZATION_ID constant and cannot be changed here.', 'workos-for-wordpress'); ?>
                        </div>
                        <div class="workos-field workos-field-locked">
                            <label for="workos_organization_id"><?php esc_html_e('Organization', 'workos-for-wordpress'); ?>
                                <span class="workos-badge workos-badge-info"><?php esc_html_e('wp-config.php', 'workos-for-wordpress'); ?></span>
                            </label>
                            <?php
                            // Try to find the org name for a friendly display.
                            $org_display = $org_id;
                            foreach ($organizations as $org) {
                                if ($org['id'] === $org_id) {
                                    $org_display = $org['name'] . ' (' . $org_id . ')';
                                    break;
                                }
                            }
                            ?>
                            <input type="text" id="workos_organization_id" value="<?php echo esc_attr($org_display); ?>" disabled />
                            <p class="workos-field-description"><code>define( 'WORKOS_ORGANIZATION_ID', '...' );</code></p>
                        </div>
                    <?php else: ?>
                        <div class="workos-field">
                            <label for="workos_organization_id"><?php esc_html_e('Organization', 'workos-for-wordpress'); ?></label>
                            <?php if (!empty($organizations)): ?>
                                <select id="workos_organization_id" name="workos_organization_id">
                                    <option value=""><?php esc_html_e('-- Select Organization --', 'workos-for-wordpress'); ?></option>
                                    <?php
                                    $org_found = false;
                                    foreach ($organizations as $org):
                                        $is_selected = $org_id === $org['id'];
                                        if ($is_selected) { $org_found = true; }
                                    ?>
                                        <option value="<?php echo esc_attr($org['id']); ?>" <?php selected($is_selected); ?>>
                                            <?php echo esc_html($org['name']); ?> (<?php echo esc_html($org['id']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                    <?php if (!$org_found && !empty($org_id)): ?>
                                        <option value="<?php echo esc_attr($org_id); ?>" selected><?php echo esc_html($org_id); ?> [not found]</option>
                                    <?php endif; ?>
                                </select>
                            <?php else: ?>
                                <input type="text" id="workos_organization_id" name="workos_organization_id" value="<?php echo esc_attr($org_id); ?>" placeholder="org_..." />
                                <p class="workos-field-description"><?php esc_html_e('Configure your API credentials to load organizations from WorkOS.', 'workos-for-wordpress'); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($this->is_configured() && !empty($org_id) && empty($workos_roles)): ?>
                    <div class="workos-alert workos-alert-error">
                        <?php esc_html_e('Could not fetch roles for this organization. Verify the organization is correct and your API key has access.', 'workos-for-wordpress'); ?>
                        <?php
                        // Show the actual error for debugging.
                        $role_error = $this->fetch_workos_roles_error($org_id);
                        if ($role_error) {
                            echo '<br><small>' . esc_html($role_error) . '</small>';
                        }
                        ?>
                    </div>
                <?php endif; ?>

                <div class="workos-card">
                    <div class="workos-card-header">
                        <h2><?php esc_html_e('Role Mapping', 'workos-for-wordpress'); ?></h2>
                        <p><?php esc_html_e('Users without a matching role keep their current WordPress role (or the site default for new users).', 'workos-for-wordpress'); ?></p>
                    </div>

                    <table class="workos-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('WorkOS Role', 'workos-for-wordpress'); ?></th>
                                <th><?php esc_html_e('WordPress Role', 'workos-for-wordpress'); ?></th>
                                <th class="workos-table-actions"></th>
                            </tr>
                        </thead>
                        <tbody id="workos-role-map-rows">
                            <?php foreach ($role_map as $i => $row): ?>
                                <tr>
                                    <td>
                                        <select name="workos_role_map[<?php echo $i; ?>][workos_role]">
                                            <option value=""><?php esc_html_e('-- Select --', 'workos-for-wordpress'); ?></option>
                                            <?php
                                            $found = false;
                                            foreach ($workos_roles as $role):
                                                $is_selected = ($row['workos_role'] ?? '') === $role['slug'];
                                                if ($is_selected) { $found = true; }
                                                $label = $role['name'] . ' (' . $role['slug'] . ')';
                                                if ($role['type'] === 'EnvironmentRole') { $label .= ' [global]'; }
                                            ?>
                                                <option value="<?php echo esc_attr($role['slug']); ?>" <?php selected($is_selected); ?>><?php echo esc_html($label); ?></option>
                                            <?php endforeach; ?>
                                            <?php if (!$found && !empty($row['workos_role'])): ?>
                                                <option value="<?php echo esc_attr($row['workos_role']); ?>" selected><?php echo esc_html($row['workos_role']); ?> [not found]</option>
                                            <?php endif; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="workos_role_map[<?php echo $i; ?>][wp_role]">
                                            <?php foreach ($wp_roles as $role_slug => $role_name): ?>
                                                <option value="<?php echo esc_attr($role_slug); ?>" <?php selected($row['wp_role'] ?? '', $role_slug); ?>><?php echo esc_html(translate_user_role($role_name)); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td class="workos-table-actions">
                                        <button type="button" class="workos-btn workos-btn-danger workos-btn-sm workos-remove-row" title="<?php esc_attr_e('Remove', 'workos-for-wordpress'); ?>">&times;</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div style="margin-top: 12px;">
                        <button type="button" class="workos-btn workos-btn-secondary workos-btn-sm" id="workos-add-role-row">+ <?php esc_html_e('Add Mapping', 'workos-for-wordpress'); ?></button>
                    </div>
                </div>

                <button type="submit" class="workos-btn workos-btn-primary"><?php esc_html_e('Save Changes', 'workos-for-wordpress'); ?></button>
            </form>
        </div>

        <script>
        (function() {
            var counter = <?php echo count($role_map); ?>;
            var workosOpts = <?php echo wp_json_encode($workos_options_html); ?>;
            var wpOpts = <?php echo wp_json_encode($wp_options_html); ?>;
            var tbody = document.getElementById('workos-role-map-rows');

            document.getElementById('workos-add-role-row').addEventListener('click', function() {
                var tr = document.createElement('tr');
                tr.innerHTML =
                    '<td><select name="workos_role_map[' + counter + '][workos_role]">' + workosOpts + '</select></td>' +
                    '<td><select name="workos_role_map[' + counter + '][wp_role]">' + wpOpts + '</select></td>' +
                    '<td class="workos-table-actions"><button type="button" class="workos-btn workos-btn-danger workos-btn-sm workos-remove-row" title="<?php echo esc_attr__('Remove', 'workos-for-wordpress'); ?>">&times;</button></td>';
                tbody.appendChild(tr);
                counter++;
            });

            tbody.addEventListener('click', function(e) {
                if (e.target.classList.contains('workos-remove-row')) {
                    e.target.closest('tr').remove();
                }
            });
        })();
        </script>
        <?php
    }

    public function render_usage_page(): void {
        $tracking_enabled = ActivityLog::is_enabled();
        $stats = $tracking_enabled ? ActivityLog::get_stats(30) : ['totals' => [], 'unique_users' => 0, 'daily' => []];
        $events_data = $tracking_enabled ? ActivityLog::get_events(20) : ['events' => [], 'total' => 0];
        $total_events = ActivityLog::count();

        // Flash message after clear.
        $cleared = isset($_GET['workos_cleared']) ? (int) $_GET['workos_cleared'] : null;
        ?>
        <div class="wrap workos-admin">
            <?php $this->render_global_header('workos-usage'); ?>

            <div class="workos-page-header">
                <h1><?php esc_html_e('Usage', 'workos-for-wordpress'); ?></h1>
                <p class="workos-page-description"><?php esc_html_e('Monitor authentication activity for this WordPress site.', 'workos-for-wordpress'); ?></p>
            </div>

            <?php if (null !== $cleared): ?>
                <div class="workos-alert workos-alert-success">
                    <?php echo esc_html(sprintf(
                        _n('%s event cleared.', '%s events cleared.', $cleared, 'workos-for-wordpress'),
                        number_format_i18n($cleared)
                    )); ?>
                </div>
            <?php endif; ?>

            <div class="workos-card">
                <div class="workos-card-header">
                    <div class="workos-card-header-row">
                        <h2><?php esc_html_e('Activity Tracking', 'workos-for-wordpress'); ?></h2>
                    </div>
                    <p><?php esc_html_e('When enabled, login, logout, and failed authentication events are stored locally in your WordPress database.', 'workos-for-wordpress'); ?></p>
                </div>

                <form action="options.php" method="post">
                    <?php settings_fields('workos_usage_settings'); ?>
                    <div class="workos-field">
                        <label class="workos-toggle-label">
                            <input type="hidden" name="workos_activity_tracking" value="0" />
                            <input type="checkbox" name="workos_activity_tracking" value="1" <?php checked($tracking_enabled); ?> class="workos-toggle-input" />
                            <span class="workos-toggle-switch"></span>
                            <span><?php esc_html_e('Enable activity tracking', 'workos-for-wordpress'); ?></span>
                        </label>
                        <p class="workos-field-description"><?php esc_html_e('Tracking is disabled by default. Events are stored in a local database table and are not sent to any external service.', 'workos-for-wordpress'); ?></p>
                    </div>
                    <button type="submit" class="workos-btn workos-btn-primary workos-btn-sm"><?php esc_html_e('Save', 'workos-for-wordpress'); ?></button>
                </form>
            </div>

            <?php if ($tracking_enabled): ?>

            <div class="workos-stats-grid">
                <div class="workos-stat-card">
                    <div class="workos-stat-value"><?php echo esc_html(number_format_i18n($stats['totals']['login'] ?? 0)); ?></div>
                    <div class="workos-stat-label"><?php esc_html_e('Logins', 'workos-for-wordpress'); ?></div>
                    <div class="workos-stat-period"><?php esc_html_e('Last 30 days', 'workos-for-wordpress'); ?></div>
                </div>
                <div class="workos-stat-card">
                    <div class="workos-stat-value"><?php echo esc_html(number_format_i18n($stats['totals']['logout'] ?? 0)); ?></div>
                    <div class="workos-stat-label"><?php esc_html_e('Logouts', 'workos-for-wordpress'); ?></div>
                    <div class="workos-stat-period"><?php esc_html_e('Last 30 days', 'workos-for-wordpress'); ?></div>
                </div>
                <div class="workos-stat-card">
                    <div class="workos-stat-value"><?php echo esc_html(number_format_i18n($stats['totals']['login_failed'] ?? 0)); ?></div>
                    <div class="workos-stat-label"><?php esc_html_e('Failed Logins', 'workos-for-wordpress'); ?></div>
                    <div class="workos-stat-period"><?php esc_html_e('Last 30 days', 'workos-for-wordpress'); ?></div>
                </div>
                <div class="workos-stat-card">
                    <div class="workos-stat-value"><?php echo esc_html(number_format_i18n($stats['unique_users'])); ?></div>
                    <div class="workos-stat-label"><?php esc_html_e('Unique Users', 'workos-for-wordpress'); ?></div>
                    <div class="workos-stat-period"><?php esc_html_e('Last 30 days', 'workos-for-wordpress'); ?></div>
                </div>
            </div>

            <div class="workos-card">
                <div class="workos-card-header">
                    <div class="workos-card-header-row">
                        <h2><?php esc_html_e('Recent Activity', 'workos-for-wordpress'); ?></h2>
                        <span class="workos-badge workos-badge-muted"><?php echo esc_html(number_format_i18n($events_data['total'])); ?> <?php esc_html_e('total', 'workos-for-wordpress'); ?></span>
                    </div>
                </div>

                <?php if (empty($events_data['events'])): ?>
                    <p style="color: var(--wos-text-tertiary); font-size: 13px;"><?php esc_html_e('No events recorded yet. Activity will appear here after users log in or out.', 'workos-for-wordpress'); ?></p>
                <?php else: ?>
                    <table class="workos-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Event', 'workos-for-wordpress'); ?></th>
                                <th><?php esc_html_e('User', 'workos-for-wordpress'); ?></th>
                                <th><?php esc_html_e('IP Address', 'workos-for-wordpress'); ?></th>
                                <th><?php esc_html_e('Time', 'workos-for-wordpress'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events_data['events'] as $event): ?>
                                <tr>
                                    <td>
                                        <?php
                                        $type = $event['event_type'];
                                        $badge_class = match($type) {
                                            'login'        => 'workos-badge-success',
                                            'logout'       => 'workos-badge-muted',
                                            'login_failed' => 'workos-badge-danger',
                                            default        => 'workos-badge-muted',
                                        };
                                        $type_label = match($type) {
                                            'login'        => __('Login', 'workos-for-wordpress'),
                                            'logout'       => __('Logout', 'workos-for-wordpress'),
                                            'login_failed' => __('Failed', 'workos-for-wordpress'),
                                            default        => $type,
                                        };
                                        ?>
                                        <span class="workos-badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html($type_label); ?></span>
                                    </td>
                                    <td>
                                        <?php if ($event['user_email']): ?>
                                            <span class="workos-diag-value"><?php echo esc_html($event['user_email']); ?></span>
                                        <?php else: ?>
                                            <span style="color: var(--wos-text-tertiary);">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($event['ip_address']): ?>
                                            <span class="workos-diag-value"><?php echo esc_html($event['ip_address']); ?></span>
                                        <?php else: ?>
                                            <span style="color: var(--wos-text-tertiary);">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span title="<?php echo esc_attr($event['created_at']); ?>">
                                            <?php echo esc_html(human_time_diff(strtotime($event['created_at'] . ' UTC'), time()) . ' ' . __('ago', 'workos-for-wordpress')); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <?php if ($total_events > 0): ?>
                <div class="workos-card workos-card-danger-zone">
                    <div class="workos-card-header">
                        <div class="workos-card-header-row">
                            <div>
                                <h2><?php esc_html_e('Clear Activity Log', 'workos-for-wordpress'); ?></h2>
                                <p style="margin: 4px 0 0;"><?php echo esc_html(sprintf(
                                    __('Permanently delete all %s tracked events. This action cannot be undone.', 'workos-for-wordpress'),
                                    number_format_i18n($total_events)
                                )); ?></p>
                            </div>
                            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" id="workos-clear-log-form">
                                <input type="hidden" name="action" value="workos_clear_activity_log" />
                                <?php wp_nonce_field('workos_clear_activity_log', '_workos_nonce'); ?>
                                <button type="button" class="workos-btn workos-btn-danger workos-btn-sm" id="workos-clear-log-btn"><?php esc_html_e('Clear All Events', 'workos-for-wordpress'); ?></button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php endif; /* tracking_enabled */ ?>
        </div>

        <script>
        (function() {
            var btn = document.getElementById('workos-clear-log-btn');
            if (!btn) return;
            btn.addEventListener('click', function() {
                var count = <?php echo wp_json_encode(number_format_i18n($total_events)); ?>;
                if (confirm(<?php echo wp_json_encode(sprintf(
                    __('Are you sure you want to permanently delete all %s events? This cannot be undone.', 'workos-for-wordpress'),
                    number_format_i18n($total_events)
                )); ?>)) {
                    document.getElementById('workos-clear-log-form').submit();
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * Handle the admin-post action to clear the activity log.
     */
    public function handle_clear_activity_log(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to do this.', 'workos-for-wordpress'), 403);
        }

        check_admin_referer('workos_clear_activity_log', '_workos_nonce');

        $cleared = ActivityLog::clear();

        wp_safe_redirect(add_query_arg([
            'page'            => 'workos-usage',
            'workos_cleared'  => $cleared,
        ], admin_url('admin.php')));
        exit;
    }

    public function render_learning_mode_page(): void {
        $learning_enabled = LearningMode::is_enabled();
        $gate_enabled = (bool) get_option('workos_org_entitlement_gate', false);
        $org_id = $this->get_organization_id();
        $configured = $this->is_configured() && !empty($org_id);

        $synced_count = 0;
        $syncable_count = 0;
        $syncable_users = [];

        if ($configured) {
            $lm = new LearningMode();
            $syncable_users = $lm->get_syncable_users();
            $synced_users = $lm->get_synced_users();
            $syncable_count = count($syncable_users);
            $synced_count = count($synced_users);
        }

        $total_users = count_users();
        $total = $total_users['total_users'] ?? 0;
        ?>
        <div class="wrap workos-admin">
            <?php $this->render_global_header('workos-learning-mode'); ?>

            <div class="workos-page-header">
                <h1><?php esc_html_e('Learning Mode', 'workos-for-wordpress'); ?></h1>
                <p class="workos-page-description"><?php esc_html_e('Import existing WordPress users into WorkOS and manage organization entitlement for inbound authentication.', 'workos-for-wordpress'); ?></p>
            </div>

            <?php if (!$configured): ?>
                <div class="workos-alert workos-alert-warning">
                    <?php esc_html_e('API credentials and an organization must be configured before Learning Mode can be used. Configure them on the Welcome and Roles pages.', 'workos-for-wordpress'); ?>
                </div>
            <?php endif; ?>

            <!-- Settings Card -->
            <div class="workos-card">
                <div class="workos-card-header">
                    <div class="workos-card-header-row">
                        <h2><?php esc_html_e('Learning Mode Settings', 'workos-for-wordpress'); ?></h2>
                        <button type="button" class="workos-tooltip-toggle" aria-expanded="false" aria-controls="workos-import-tips" title="<?php esc_attr_e('Import best practices', 'workos-for-wordpress'); ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                        </button>
                    </div>
                    <p><?php esc_html_e('Control user synchronization and organization-based access.', 'workos-for-wordpress'); ?></p>
                    <div class="workos-tooltip-content" id="workos-import-tips" hidden>
                        <p><strong><?php esc_html_e('Import Best Practices', 'workos-for-wordpress'); ?></strong></p>
                        <p><strong><?php esc_html_e('Before importing:', 'workos-for-wordpress'); ?></strong></p>
                        <p><?php esc_html_e('1. Audit your WordPress users — remove inactive or spam accounts first to avoid syncing unnecessary users.', 'workos-for-wordpress'); ?></p>
                        <p><?php esc_html_e('2. Set up role mappings on the Roles page so users get the correct WorkOS role during import.', 'workos-for-wordpress'); ?></p>
                        <p><?php esc_html_e('3. Test with a small batch first — use the individual sync buttons to verify a few users before running a full sync.', 'workos-for-wordpress'); ?></p>
                        <p><strong><?php esc_html_e('During import:', 'workos-for-wordpress'); ?></strong></p>
                        <p><?php esc_html_e('4. Keep this page open during sync — the process runs in the browser and may take time for large user bases.', 'workos-for-wordpress'); ?></p>
                        <p><?php esc_html_e('5. WorkOS API rate limits apply — the sync processes users sequentially to stay within limits.', 'workos-for-wordpress'); ?></p>
                        <p><strong><?php esc_html_e('After import:', 'workos-for-wordpress'); ?></strong></p>
                        <p><?php esc_html_e('6. Enable "Require organization entitlement" to ensure only organization members can log in via WorkOS.', 'workos-for-wordpress'); ?></p>
                        <p><?php esc_html_e('7. Disable Learning Mode once all users are imported — it is not needed for ongoing operation.', 'workos-for-wordpress'); ?></p>
                        <p><?php esc_html_e('8. Users will need to authenticate through WorkOS on their next login. Their WordPress passwords will no longer be used if AuthKit is the primary login method.', 'workos-for-wordpress'); ?></p>
                    </div>
                </div>

                <form action="options.php" method="post">
                    <?php settings_fields('workos_learning_mode_settings'); ?>

                    <div class="workos-field" style="margin-bottom: 24px;">
                        <label class="workos-toggle-label">
                            <input type="hidden" name="workos_learning_mode" value="0" />
                            <input type="checkbox" name="workos_learning_mode" value="1" <?php checked($learning_enabled); ?> class="workos-toggle-input" <?php disabled(!$configured); ?> />
                            <span class="workos-toggle-switch"></span>
                            <span><?php esc_html_e('Enable Learning Mode', 'workos-for-wordpress'); ?></span>
                        </label>
                        <p class="workos-field-description"><?php esc_html_e('When enabled, shows user sync controls and allows importing existing WordPress users into WorkOS.', 'workos-for-wordpress'); ?></p>
                    </div>

                    <div class="workos-field">
                        <label class="workos-toggle-label">
                            <input type="hidden" name="workos_org_entitlement_gate" value="0" />
                            <input type="checkbox" name="workos_org_entitlement_gate" value="1" <?php checked($gate_enabled); ?> class="workos-toggle-input" <?php disabled(!$configured); ?> />
                            <span class="workos-toggle-switch"></span>
                            <span><?php esc_html_e('Require organization entitlement', 'workos-for-wordpress'); ?></span>
                        </label>
                        <p class="workos-field-description"><?php esc_html_e('When enabled, only users with an active membership in the configured organization can log in through WorkOS. Users without entitlement will be denied access. This is recommended after completing your user import.', 'workos-for-wordpress'); ?></p>
                    </div>

                    <div style="margin-top: 20px;">
                        <button type="submit" class="workos-btn workos-btn-primary workos-btn-sm"><?php esc_html_e('Save Settings', 'workos-for-wordpress'); ?></button>
                    </div>
                </form>
            </div>

            <?php if ($learning_enabled && $configured): ?>

            <!-- Sync Overview -->
            <div class="workos-stats-grid" style="grid-template-columns: repeat(3, 1fr);">
                <div class="workos-stat-card">
                    <div class="workos-stat-value"><?php echo esc_html(number_format_i18n($total)); ?></div>
                    <div class="workos-stat-label"><?php esc_html_e('Total WP Users', 'workos-for-wordpress'); ?></div>
                </div>
                <div class="workos-stat-card">
                    <div class="workos-stat-value"><?php echo esc_html(number_format_i18n($synced_count)); ?></div>
                    <div class="workos-stat-label"><?php esc_html_e('Synced to WorkOS', 'workos-for-wordpress'); ?></div>
                </div>
                <div class="workos-stat-card">
                    <div class="workos-stat-value"><?php echo esc_html(number_format_i18n($syncable_count)); ?></div>
                    <div class="workos-stat-label"><?php esc_html_e('Pending Sync', 'workos-for-wordpress'); ?></div>
                </div>
            </div>

            <!-- User Sync Table -->
            <?php if ($syncable_count > 0): ?>
                <div class="workos-card">
                    <div class="workos-card-header">
                        <div class="workos-card-header-row">
                            <h2><?php esc_html_e('Pending Users', 'workos-for-wordpress'); ?></h2>
                            <button type="button" class="workos-btn workos-btn-primary workos-btn-sm" id="workos-sync-all-btn">
                                <?php echo esc_html(sprintf(
                                    __('Sync All %s Users', 'workos-for-wordpress'),
                                    number_format_i18n($syncable_count)
                                )); ?>
                            </button>
                        </div>
                        <p><?php esc_html_e('WordPress users not yet linked to a WorkOS account. Sync them individually or all at once.', 'workos-for-wordpress'); ?></p>
                    </div>

                    <!-- Progress bar (hidden until sync starts) -->
                    <div id="workos-sync-progress" style="display: none; margin-bottom: 20px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                            <span class="workos-sync-progress-label" style="font-size: 13px; font-weight: 500;"><?php esc_html_e('Syncing...', 'workos-for-wordpress'); ?></span>
                            <span class="workos-sync-progress-count" style="font-size: 13px; color: var(--wos-text-secondary);">0 / <?php echo esc_html($syncable_count); ?></span>
                        </div>
                        <div class="workos-progress-bar">
                            <div class="workos-progress-bar-fill" style="width: 0%;"></div>
                        </div>
                        <div id="workos-sync-summary" style="display: none; margin-top: 12px;"></div>
                    </div>

                    <table class="workos-table" id="workos-sync-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('User', 'workos-for-wordpress'); ?></th>
                                <th><?php esc_html_e('Email', 'workos-for-wordpress'); ?></th>
                                <th><?php esc_html_e('Role', 'workos-for-wordpress'); ?></th>
                                <th><?php esc_html_e('Status', 'workos-for-wordpress'); ?></th>
                                <th class="workos-table-actions"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($syncable_users as $user): ?>
                                <tr data-user-id="<?php echo esc_attr($user->ID); ?>">
                                    <td>
                                        <span style="font-weight: 500;"><?php echo esc_html($user->display_name); ?></span>
                                        <br><span style="font-size: 12px; color: var(--wos-text-tertiary);"><?php echo esc_html($user->user_login); ?></span>
                                    </td>
                                    <td><span class="workos-diag-value"><?php echo esc_html($user->user_email); ?></span></td>
                                    <td><span class="workos-badge workos-badge-muted"><?php echo esc_html(implode(', ', $user->roles)); ?></span></td>
                                    <td class="workos-sync-status">
                                        <?php
                                        $has_workos_id = get_user_meta($user->ID, '_workos_user_id', true);
                                        if (!empty($has_workos_id)):
                                        ?>
                                            <span class="workos-badge workos-badge-info"><?php esc_html_e('Linked, needs org sync', 'workos-for-wordpress'); ?></span>
                                        <?php else: ?>
                                            <span class="workos-badge workos-badge-warning"><?php esc_html_e('Pending', 'workos-for-wordpress'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="workos-table-actions">
                                        <button type="button" class="workos-btn workos-btn-secondary workos-btn-sm workos-sync-single-btn" data-user-id="<?php echo esc_attr($user->ID); ?>">
                                            <?php esc_html_e('Sync', 'workos-for-wordpress'); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="workos-card">
                    <div class="workos-card-header">
                        <h2><?php esc_html_e('User Sync', 'workos-for-wordpress'); ?></h2>
                    </div>
                    <div class="workos-alert workos-alert-success" style="margin-bottom: 0;">
                        <?php esc_html_e('All WordPress users are synced to WorkOS. You can disable Learning Mode.', 'workos-for-wordpress'); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php endif; /* learning_enabled && configured */ ?>
        </div>

        <?php if ($learning_enabled && $configured): ?>
        <script>
        (function() {
            var nonce = <?php echo wp_json_encode(wp_create_nonce('workos_learning_mode')); ?>;
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;

            // Toggle best practices
            var tipBtn = document.querySelector('.workos-tooltip-toggle');
            if (tipBtn) {
                tipBtn.addEventListener('click', function() {
                    var tip = document.getElementById(tipBtn.getAttribute('aria-controls'));
                    var expanded = tipBtn.getAttribute('aria-expanded') === 'true';
                    tipBtn.setAttribute('aria-expanded', String(!expanded));
                    tip.hidden = expanded;
                });
            }

            // Single user sync
            document.querySelectorAll('.workos-sync-single-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var userId = this.dataset.userId;
                    var row = this.closest('tr');
                    var statusCell = row.querySelector('.workos-sync-status');
                    var actionCell = row.querySelector('.workos-table-actions');

                    btn.disabled = true;
                    btn.textContent = <?php echo wp_json_encode(__('Syncing...', 'workos-for-wordpress')); ?>;
                    statusCell.innerHTML = '<span class="workos-badge workos-badge-info">' + <?php echo wp_json_encode(__('Syncing', 'workos-for-wordpress')); ?> + '</span>';

                    var fd = new FormData();
                    fd.append('action', 'workos_learning_mode_sync_single');
                    fd.append('_wpnonce', nonce);
                    fd.append('user_id', userId);

                    fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data.success) {
                                statusCell.innerHTML = '<span class="workos-badge workos-badge-success">' + <?php echo wp_json_encode(__('Synced', 'workos-for-wordpress')); ?> + '</span>';
                                actionCell.innerHTML = '<span style="color: var(--wos-text-tertiary); font-size: 12px;">' + (data.data.action === 'created' ? <?php echo wp_json_encode(__('Created', 'workos-for-wordpress')); ?> : <?php echo wp_json_encode(__('Linked', 'workos-for-wordpress')); ?>) + '</span>';
                            } else {
                                statusCell.innerHTML = '<span class="workos-badge workos-badge-danger">' + <?php echo wp_json_encode(__('Error', 'workos-for-wordpress')); ?> + '</span>';
                                btn.disabled = false;
                                btn.textContent = <?php echo wp_json_encode(__('Retry', 'workos-for-wordpress')); ?>;
                            }
                        })
                        .catch(function() {
                            statusCell.innerHTML = '<span class="workos-badge workos-badge-danger">' + <?php echo wp_json_encode(__('Error', 'workos-for-wordpress')); ?> + '</span>';
                            btn.disabled = false;
                            btn.textContent = <?php echo wp_json_encode(__('Retry', 'workos-for-wordpress')); ?>;
                        });
                });
            });

            // Batch sync all
            var syncAllBtn = document.getElementById('workos-sync-all-btn');
            if (syncAllBtn) {
                syncAllBtn.addEventListener('click', function() {
                    if (!confirm(<?php echo wp_json_encode(__('This will sync all pending users to WorkOS. Continue?', 'workos-for-wordpress')); ?>)) return;

                    var rows = document.querySelectorAll('#workos-sync-table tbody tr[data-user-id]');
                    var pendingRows = [];
                    rows.forEach(function(row) {
                        var badge = row.querySelector('.workos-sync-status .workos-badge');
                        if (badge && !badge.classList.contains('workos-badge-success')) {
                            pendingRows.push(row);
                        }
                    });

                    if (pendingRows.length === 0) return;

                    syncAllBtn.disabled = true;
                    syncAllBtn.textContent = <?php echo wp_json_encode(__('Syncing...', 'workos-for-wordpress')); ?>;

                    var progress = document.getElementById('workos-sync-progress');
                    var progressLabel = progress.querySelector('.workos-sync-progress-label');
                    var progressCount = progress.querySelector('.workos-sync-progress-count');
                    var progressFill = progress.querySelector('.workos-progress-bar-fill');
                    var summary = document.getElementById('workos-sync-summary');
                    progress.style.display = 'block';

                    var total = pendingRows.length;
                    var done = 0;
                    var created = 0;
                    var linked = 0;
                    var errors = 0;

                    function syncNext(index) {
                        if (index >= total) {
                            progressLabel.textContent = <?php echo wp_json_encode(__('Complete', 'workos-for-wordpress')); ?>;
                            summary.style.display = 'block';
                            summary.innerHTML = '<div class="workos-alert workos-alert-success">' +
                                <?php echo wp_json_encode(__('Sync complete.', 'workos-for-wordpress')); ?> + ' ' +
                                created + ' ' + <?php echo wp_json_encode(__('created,', 'workos-for-wordpress')); ?> + ' ' +
                                linked + ' ' + <?php echo wp_json_encode(__('linked,', 'workos-for-wordpress')); ?> + ' ' +
                                errors + ' ' + <?php echo wp_json_encode(__('errors.', 'workos-for-wordpress')); ?> +
                                '</div>';
                            syncAllBtn.textContent = <?php echo wp_json_encode(__('Done', 'workos-for-wordpress')); ?>;
                            return;
                        }

                        var row = pendingRows[index];
                        var userId = row.dataset.userId;
                        var statusCell = row.querySelector('.workos-sync-status');
                        var actionCell = row.querySelector('.workos-table-actions');

                        statusCell.innerHTML = '<span class="workos-badge workos-badge-info">' + <?php echo wp_json_encode(__('Syncing', 'workos-for-wordpress')); ?> + '</span>';

                        var fd = new FormData();
                        fd.append('action', 'workos_learning_mode_sync_single');
                        fd.append('_wpnonce', nonce);
                        fd.append('user_id', userId);

                        fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                done++;
                                if (data.success) {
                                    statusCell.innerHTML = '<span class="workos-badge workos-badge-success">' + <?php echo wp_json_encode(__('Synced', 'workos-for-wordpress')); ?> + '</span>';
                                    actionCell.innerHTML = '<span style="color: var(--wos-text-tertiary); font-size: 12px;">' + (data.data.action === 'created' ? <?php echo wp_json_encode(__('Created', 'workos-for-wordpress')); ?> : <?php echo wp_json_encode(__('Linked', 'workos-for-wordpress')); ?>) + '</span>';
                                    if (data.data.action === 'created') created++;
                                    else linked++;
                                } else {
                                    statusCell.innerHTML = '<span class="workos-badge workos-badge-danger">' + <?php echo wp_json_encode(__('Error', 'workos-for-wordpress')); ?> + '</span>';
                                    errors++;
                                }
                                progressCount.textContent = done + ' / ' + total;
                                progressFill.style.width = Math.round((done / total) * 100) + '%';
                                // Small delay to respect rate limits
                                setTimeout(function() { syncNext(index + 1); }, 300);
                            })
                            .catch(function() {
                                done++;
                                errors++;
                                statusCell.innerHTML = '<span class="workos-badge workos-badge-danger">' + <?php echo wp_json_encode(__('Error', 'workos-for-wordpress')); ?> + '</span>';
                                progressCount.textContent = done + ' / ' + total;
                                progressFill.style.width = Math.round((done / total) * 100) + '%';
                                setTimeout(function() { syncNext(index + 1); }, 300);
                            });
                    }

                    syncNext(0);
                });
            }
        })();
        </script>
        <?php endif;
    }

    /**
     * AJAX handler: sync a single user to WorkOS.
     */
    public function handle_learning_mode_sync_single(): void {
        check_ajax_referer('workos_learning_mode');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'workos-for-wordpress')], 403);
        }

        if (!LearningMode::is_enabled()) {
            wp_send_json_error(['message' => __('Learning Mode is not enabled.', 'workos-for-wordpress')]);
        }

        $user_id = (int) ($_POST['user_id'] ?? 0);
        $user = get_user_by('id', $user_id);

        if (!$user) {
            wp_send_json_error(['message' => __('User not found.', 'workos-for-wordpress')]);
        }

        $lm = new LearningMode();
        $result = $lm->sync_user($user);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX handler: batch sync all users (kept for future use, currently sync is client-side sequential).
     */
    public function handle_learning_mode_sync(): void {
        check_ajax_referer('workos_learning_mode');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'workos-for-wordpress')], 403);
        }

        if (!LearningMode::is_enabled()) {
            wp_send_json_error(['message' => __('Learning Mode is not enabled.', 'workos-for-wordpress')]);
        }

        $lm = new LearningMode();
        $results = $lm->run_batch_sync();

        wp_send_json_success($results);
    }

    /**
     * Add a "WorkOS" column to the Users list table.
     */
    public function add_users_workos_column(array $columns): array {
        $columns['workos_status'] = __('WorkOS', 'workos-for-wordpress');
        return $columns;
    }

    /**
     * Render the WorkOS status badge in the Users list table.
     */
    public function render_users_workos_column(string $output, string $column_name, int $user_id): string {
        if ($column_name !== 'workos_status') {
            return $output;
        }

        $suspended = get_user_meta($user_id, '_workos_suspended', true);
        $synced_at = get_user_meta($user_id, '_workos_synced_at', true);
        $workos_user_id = get_user_meta($user_id, '_workos_user_id', true);

        if (!empty($suspended)) {
            $reason = get_user_meta($user_id, '_workos_suspended_reason', true);
            $reason_label = $reason === 'not_found_in_workos'
                ? __('Not found in WorkOS', 'workos-for-wordpress')
                : __('No org membership', 'workos-for-wordpress');
            return sprintf(
                '<span class="workos-users-badge workos-users-badge-suspended" title="%s">%s</span>',
                esc_attr($reason_label),
                esc_html__('Suspended', 'workos-for-wordpress')
            );
        }

        if (!empty($synced_at)) {
            return sprintf(
                '<span class="workos-users-badge workos-users-badge-synced" title="%s">%s</span>',
                esc_attr(sprintf(__('Synced %s', 'workos-for-wordpress'), $synced_at)),
                esc_html__('Synced', 'workos-for-wordpress')
            );
        }

        if (!empty($workos_user_id)) {
            return sprintf(
                '<span class="workos-users-badge workos-users-badge-linked">%s</span>',
                esc_html__('Linked', 'workos-for-wordpress')
            );
        }

        return sprintf(
            '<span class="workos-users-badge workos-users-badge-pending">%s</span>',
            esc_html__('Not synced', 'workos-for-wordpress')
        );
    }

    /**
     * Add a "reSync" row action to users in the Users list table.
     */
    public function add_users_resync_action(array $actions, \WP_User $user): array {
        if (!current_user_can('manage_options') || !$this->is_configured()) {
            return $actions;
        }

        $label = LearningMode::is_enabled()
            ? __('reSync to WorkOS', 'workos-for-wordpress')
            : __('reSync from WorkOS', 'workos-for-wordpress');

        $actions['workos_resync'] = sprintf(
            '<a href="#" class="workos-resync-user" data-user-id="%d" data-nonce="%s">%s</a>',
            $user->ID,
            wp_create_nonce('workos_resync_user_' . $user->ID),
            esc_html($label)
        );

        return $actions;
    }

    /**
     * AJAX handler: reSync a single user from the Users table.
     *
     * Learning Mode ON  → push WP user to WorkOS (create/link + org membership).
     * Learning Mode OFF → verify user exists in WorkOS with org membership;
     *                      if not, suspend the local WordPress user (remove all roles).
     */
    public function handle_resync_user(): void {
        $user_id = (int) ($_POST['user_id'] ?? 0);

        check_ajax_referer('workos_resync_user_' . $user_id);

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'workos-for-wordpress')], 403);
        }

        $user = get_user_by('id', $user_id);
        if (!$user) {
            wp_send_json_error(['message' => __('User not found.', 'workos-for-wordpress')]);
        }

        // Don't allow deleting the current admin.
        if (!LearningMode::is_enabled() && (int) $user->ID === get_current_user_id()) {
            wp_send_json_error([
                'message' => __('Cannot reSync yourself — your own account is protected.', 'workos-for-wordpress'),
            ]);
        }

        if (LearningMode::is_enabled()) {
            $this->resync_push($user);
        } else {
            $this->resync_pull($user);
        }
    }

    /**
     * Push mode: sync the WordPress user into WorkOS.
     */
    private function resync_push(\WP_User $user): void {
        delete_user_meta($user->ID, '_workos_synced_at');
        delete_user_meta($user->ID, '_workos_suspended');
        delete_user_meta($user->ID, '_workos_suspended_reason');
        delete_user_meta($user->ID, '_workos_suspended_at');

        $lm = new LearningMode();
        $result = $lm->sync_user($user);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Pull mode: verify user exists in WorkOS with active org membership.
     * If not found or no membership, delete the local WordPress user.
     */
    private function resync_pull(\WP_User $user): void {
        $org_id = $this->get_organization_id();
        $um = new \WorkOS\UserManagement();

        try {
            // Look up by email in WorkOS.
            [$before, $after, $users] = $um->listUsers(email: $user->user_email, limit: 1);
            $workos_user = !empty($users) ? $users[0] : null;

            if (!$workos_user) {
                $this->suspend_local_user($user, 'not_found_in_workos');
                return;
            }

            // Check for active org membership.
            if (!empty($org_id)) {
                [$before, $after, $memberships] = $um->listOrganizationMemberships(
                    userId: $workos_user->id,
                    organizationId: $org_id,
                    limit: 1
                );

                $has_active = false;
                if (!empty($memberships)) {
                    $status = $memberships[0]->status ?? 'active';
                    $has_active = ($status === 'active');
                }

                if (!$has_active) {
                    $this->suspend_local_user($user, 'no_org_membership');
                    return;
                }
            }

            // User exists with membership — clear any suspension and update meta.
            delete_user_meta($user->ID, '_workos_suspended');
            delete_user_meta($user->ID, '_workos_suspended_reason');
            delete_user_meta($user->ID, '_workos_suspended_at');
            update_user_meta($user->ID, '_workos_user_id', $workos_user->id);
            if (!empty($org_id)) {
                update_user_meta($user->ID, '_workos_organization_id', $org_id);
            }
            update_user_meta($user->ID, '_workos_synced_at', current_time('mysql', true));

            // Sync role from WorkOS membership.
            if (!empty($memberships)) {
                $workos_role_slug = $memberships[0]->role->slug ?? null;
                if ($workos_role_slug) {
                    $wp_role = self::get_wp_role_for_workos_role($workos_role_slug);
                    if ($wp_role && !in_array($wp_role, $user->roles, true)) {
                        $user->set_role($wp_role);
                    }
                    update_user_meta($user->ID, '_workos_role_slug', $workos_role_slug);
                }
            }

            wp_send_json_success([
                'action'  => 'verified',
                'message' => sprintf(
                    __('User %s verified in WorkOS with active org membership.', 'workos-for-wordpress'),
                    $user->user_email
                ),
            ]);

        } catch (\Exception $e) {
            wp_send_json_error([
                'action'  => 'error',
                'message' => sprintf(
                    __('Failed to verify %s: %s', 'workos-for-wordpress'),
                    $user->user_email,
                    $e->getMessage()
                ),
            ]);
        }
    }

    /**
     * Suspend a local WordPress user who is not entitled via WorkOS.
     * Sets their role to empty (no capabilities) to prevent login.
     */
    private function suspend_local_user(\WP_User $user, string $reason): void {
        $email = $user->user_email;

        // Remove all roles — user will have zero capabilities.
        $user->set_role('');

        update_user_meta($user->ID, '_workos_suspended', '1');
        update_user_meta($user->ID, '_workos_suspended_reason', $reason);
        update_user_meta($user->ID, '_workos_suspended_at', current_time('mysql', true));

        // Clear sync meta since they're no longer entitled.
        delete_user_meta($user->ID, '_workos_synced_at');

        ActivityLog::record('user_suspended', [
            'user_id'    => $user->ID,
            'user_email' => $email,
            'metadata'   => ['reason' => $reason],
        ]);

        wp_send_json_success([
            'action'  => 'suspended',
            'message' => sprintf(
                __('User %s suspended — %s.', 'workos-for-wordpress'),
                $email,
                $reason === 'not_found_in_workos'
                    ? __('not found in WorkOS', 'workos-for-wordpress')
                    : __('no active organization membership', 'workos-for-wordpress')
            ),
        ]);
    }

    /**
     * Enqueue inline CSS and JS for WorkOS column on the Users table.
     */
    public function enqueue_users_table_assets(string $hook): void {
        if ($hook !== 'users.php') {
            return;
        }

        wp_register_style('workos-users-table', false);
        wp_enqueue_style('workos-users-table');
        wp_add_inline_style('workos-users-table', '
            .workos-users-badge {
                display: inline-flex;
                align-items: center;
                height: 22px;
                padding: 0 8px;
                font-size: 11px;
                font-weight: 500;
                border-radius: 999px;
                letter-spacing: 0.02em;
            }
            .workos-users-badge-synced {
                background: rgba(48, 164, 108, 0.08);
                color: #30A46C;
            }
            .workos-users-badge-linked {
                background: rgba(108, 71, 255, 0.08);
                color: #6C47FF;
            }
            .workos-users-badge-pending {
                background: #F9F9FB;
                color: #8B8D98;
                border: 1px solid #E0E1E6;
            }
            .workos-users-badge-suspended {
                background: rgba(229, 72, 77, 0.08);
                color: #E5484D;
            }
            .workos-resync-user.syncing {
                opacity: 0.5;
                pointer-events: none;
            }
        ');
        wp_enqueue_style('workos-users-table');

        $learning_mode = LearningMode::is_enabled();

        wp_add_inline_script('jquery', '
            jQuery(function($) {
                $(document).on("click", ".workos-resync-user", function(e) {
                    e.preventDefault();
                    var $link = $(this);
                    if ($link.hasClass("syncing")) return;

                    var userId = $link.data("user-id");
                    var nonce = $link.data("nonce");
                    var $row = $link.closest("tr");
                    var $badge = $row.find(".workos-users-badge");
                    var originalText = $link.text();
                    var learningMode = ' . ($learning_mode ? 'true' : 'false') . ';

                    if (!learningMode) {
                        if (!confirm("' . esc_js(__('This will verify the user against WorkOS. If they are not found or have no active organization membership, they will be suspended (locked out). Continue?', 'workos-for-wordpress')) . '")) {
                            return;
                        }
                    }

                    $link.addClass("syncing").text("' . esc_js(__('Syncing...', 'workos-for-wordpress')) . '");

                    $.post(ajaxurl, {
                        action: "workos_resync_user",
                        user_id: userId,
                        _ajax_nonce: nonce
                    }, function(response) {
                        if (response.success) {
                            if (response.data.action === "suspended") {
                                $badge.attr("class", "workos-users-badge workos-users-badge-suspended").text("' . esc_js(__('Suspended', 'workos-for-wordpress')) . '");
                                $row.css("background", "rgba(229, 72, 77, 0.06)");
                                $link.removeClass("syncing").text(originalText);
                            } else {
                                $badge.attr("class", "workos-users-badge workos-users-badge-synced").text("' . esc_js(__('Synced', 'workos-for-wordpress')) . '");
                                $link.text("' . esc_js(__('Done!', 'workos-for-wordpress')) . '");
                                setTimeout(function() {
                                    $link.removeClass("syncing").text(originalText);
                                }, 2000);
                            }
                        } else {
                            $link.removeClass("syncing").text(originalText);
                            alert(response.data && response.data.message ? response.data.message : "' . esc_js(__('Sync failed.', 'workos-for-wordpress')) . '");
                        }
                    }).fail(function() {
                        $link.removeClass("syncing").text(originalText);
                        alert("' . esc_js(__('Sync request failed.', 'workos-for-wordpress')) . '");
                    });
                });
            });
        ');
    }

    public function render_diagnostics_page(): void {
        ?>
        <div class="wrap workos-admin">
            <?php $this->render_global_header('workos-diagnostics'); ?>

            <div class="workos-page-header">
                <h1><?php esc_html_e('Diagnostics', 'workos-for-wordpress'); ?></h1>
                <p class="workos-page-description"><?php esc_html_e('Verify your WorkOS configuration and connectivity.', 'workos-for-wordpress'); ?></p>
            </div>
            <?php $this->render_diagnostics(); ?>
        </div>
        <?php
    }

    /* ================================================================
       Field Renderers (no longer used by Settings API, kept for compat)
       ================================================================ */

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
    }

    public function render_field_redirect_uri(): void {
        $url = AuthKit::get_callback_url();
        echo '<code>' . esc_html($url) . '</code>';
        echo '<p class="description">' . esc_html__('Add this URL as a Redirect URI in your WorkOS Dashboard.', 'workos-for-wordpress') . '</p>';
    }

    /* ================================================================
       WorkOS API Fetchers
       ================================================================ */

    /**
     * Fetch available WorkOS organizations.
     */
    private function fetch_workos_organizations(): array {
        if (!$this->is_configured()) {
            return [];
        }

        $transient_key = 'workos_organizations';
        $cached = get_transient($transient_key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $orgs_api = new \WorkOS\Organizations();
            [$before, $after, $organizations] = $orgs_api->listOrganizations(limit: 100);

            $org_data = [];
            foreach ($organizations as $org) {
                $org_data[] = [
                    'id'   => $org->id,
                    'name' => $org->name,
                ];
            }

            set_transient($transient_key, $org_data, 5 * MINUTE_IN_SECONDS);
            return $org_data;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Fetch WorkOS roles (organization + global) for the configured organization.
     */
    private function fetch_workos_roles(): array {
        $org_id = $this->get_organization_id();
        if (empty($org_id) || !$this->is_configured()) {
            return [];
        }

        $transient_key = 'workos_roles_' . md5($org_id);
        $cached = get_transient($transient_key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $orgs = new \WorkOS\Organizations();
            [$roles] = $orgs->listOrganizationRoles($org_id);

            $role_data = [];
            foreach ($roles as $role) {
                $role_data[] = [
                    'slug' => $role->slug,
                    'name' => $role->name,
                    'type' => $role->type ?? '',
                ];
            }

            set_transient($transient_key, $role_data, 5 * MINUTE_IN_SECONDS);
            return $role_data;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Attempt to fetch roles and return the error message if it fails.
     */
    private function fetch_workos_roles_error(string $org_id): ?string {
        try {
            $orgs = new \WorkOS\Organizations();
            $orgs->listOrganizationRoles($org_id);
            return null;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    public function sanitize_role_map($input): array {
        if (!is_array($input)) {
            return [];
        }

        $clean = [];
        foreach ($input as $row) {
            $workos_role = sanitize_text_field($row['workos_role'] ?? '');
            $wp_role = sanitize_text_field($row['wp_role'] ?? '');
            if (!empty($workos_role) && !empty($wp_role)) {
                $clean[] = [
                    'workos_role' => $workos_role,
                    'wp_role'     => $wp_role,
                ];
            }
        }
        return $clean;
    }

    /**
     * Get the WordPress role for a given WorkOS role slug, or null if no mapping exists.
     */
    public static function get_wp_role_for_workos_role(string $workos_role_slug): ?string {
        $role_map = get_option('workos_role_map', []);
        if (!is_array($role_map)) {
            return null;
        }

        foreach ($role_map as $mapping) {
            if (($mapping['workos_role'] ?? '') === $workos_role_slug) {
                return $mapping['wp_role'] ?? null;
            }
        }
        return null;
    }

    /* ================================================================
       Diagnostics
       ================================================================ */

    private function render_diagnostics(): void {
        if (!$this->is_configured()) {
            echo '<div class="workos-alert workos-alert-warning">'
                . esc_html__('API credentials must be configured before diagnostics are available.', 'workos-for-wordpress')
                . '</div>';
            return;
        }

        $client_id = $this->get_client_id();
        $api_key = $this->get_api_key();
        $org_id = $this->get_organization_id();

        // Connection status.
        $connection_ok = false;
        $auth_url = '';
        $auth_error = '';

        try {
            $um = new \WorkOS\UserManagement();
            $auth_url = $um->getAuthorizationUrl(
                AuthKit::get_callback_url(),
                null,
                \WorkOS\UserManagement::AUTHORIZATION_PROVIDER_AUTHKIT,
                null,
                $org_id ?: null
            );
            $connection_ok = true;
        } catch (\Exception $e) {
            $auth_error = $e->getMessage();
        }
        ?>
        <div class="workos-card">
            <div class="workos-card-header">
                <h2><?php esc_html_e('Connection Status', 'workos-for-wordpress'); ?></h2>
            </div>
            <?php if ($connection_ok): ?>
                <div class="workos-alert workos-alert-success">
                    <?php esc_html_e('Successfully connected to WorkOS. AuthKit authorization URL was generated.', 'workos-for-wordpress'); ?>
                </div>
            <?php else: ?>
                <div class="workos-alert workos-alert-error">
                    <?php echo esc_html(sprintf(__('Connection failed: %s', 'workos-for-wordpress'), $auth_error)); ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="workos-card">
            <div class="workos-card-header">
                <h2><?php esc_html_e('Configuration Values', 'workos-for-wordpress'); ?></h2>
            </div>
            <table class="workos-table">
                <tbody>
                    <tr>
                        <td class="workos-table-label"><?php esc_html_e('Client ID', 'workos-for-wordpress'); ?></td>
                        <td>
                            <span class="workos-diag-value"><?php echo esc_html($client_id); ?></span>
                            <span class="workos-badge workos-badge-muted"><?php echo strlen($client_id); ?> chars</span>
                            <?php if ($this->is_constant('WORKOS_CLIENT_ID')): ?>
                                <span class="workos-badge workos-badge-info"><?php esc_html_e('constant', 'workos-for-wordpress'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="workos-table-label"><?php esc_html_e('API Key', 'workos-for-wordpress'); ?></td>
                        <td>
                            <span class="workos-diag-value"><?php echo esc_html(substr($api_key, 0, 7) . '...' . substr($api_key, -4)); ?></span>
                            <span class="workos-badge workos-badge-muted"><?php echo strlen($api_key); ?> chars</span>
                            <?php if ($this->is_constant('WORKOS_API_KEY')): ?>
                                <span class="workos-badge workos-badge-info"><?php esc_html_e('constant', 'workos-for-wordpress'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="workos-table-label"><?php esc_html_e('Organization ID', 'workos-for-wordpress'); ?></td>
                        <td>
                            <?php if ($org_id): ?>
                                <span class="workos-diag-value"><?php echo esc_html($org_id); ?></span>
                                <?php if ($this->is_constant('WORKOS_ORGANIZATION_ID')): ?>
                                    <span class="workos-badge workos-badge-info"><?php esc_html_e('constant', 'workos-for-wordpress'); ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="workos-badge workos-badge-warning"><?php esc_html_e('Not set', 'workos-for-wordpress'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="workos-table-label"><?php esc_html_e('Redirect URI', 'workos-for-wordpress'); ?></td>
                        <td><span class="workos-diag-value"><?php echo esc_html(AuthKit::get_callback_url()); ?></span></td>
                    </tr>
                    <?php if ($auth_url): ?>
                    <tr>
                        <td class="workos-table-label"><?php esc_html_e('Auth URL', 'workos-for-wordpress'); ?></td>
                        <td><span class="workos-diag-value"><?php echo esc_html($auth_url); ?></span></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php $this->render_updater_diagnostics(); ?>
        <?php
    }

    /**
     * Render self-update diagnostics card on the Diagnostics page.
     */
    private function render_updater_diagnostics(): void {
        $github_repo = 'AlwaysCuriousCo/workos-for-wordpress';
        $api_url = "https://api.github.com/repos/{$github_repo}/releases/latest";
        $current_version = WORKOS_WP_VERSION;
        $plugin_slug = plugin_basename(WORKOS_WP_PLUGIN_FILE);

        // Fetch from GitHub directly (no cache).
        $response = wp_remote_get($api_url, [
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WorkOS-WordPress-Plugin/' . $current_version,
            ],
            'timeout' => 10,
        ]);

        $github_ok = false;
        $github_error = '';
        $remote_version = '';
        $zip_url = '';
        $http_code = 0;

        if (is_wp_error($response)) {
            $github_error = $response->get_error_message();
        } else {
            $http_code = wp_remote_retrieve_response_code($response);
            if ($http_code !== 200) {
                $github_error = sprintf('HTTP %d', $http_code);
            } else {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if (!empty($body['tag_name'])) {
                    $github_ok = true;
                    $remote_version = ltrim($body['tag_name'], 'v');
                    // Find zip asset.
                    foreach ($body['assets'] ?? [] as $asset) {
                        if (str_starts_with($asset['name'], 'workos-for-wordpress') && str_ends_with($asset['name'], '.zip')) {
                            $zip_url = $asset['browser_download_url'];
                            break;
                        }
                    }
                    if (empty($zip_url)) {
                        $zip_url = "https://github.com/{$github_repo}/releases/latest/download/workos-for-wordpress.zip";
                    }
                } else {
                    $github_error = 'No tag_name in response';
                }
            }
        }

        // Check transient cache state (version-scoped key matches Updater).
        $cache_key = 'workos_wp_update_' . md5($current_version);
        $cached = get_transient($cache_key);
        $cache_status = 'empty';
        if ($cached === 'none') {
            $cache_status = 'failure sentinel (none)';
        } elseif (is_array($cached) && !empty($cached['tag_name'])) {
            $cache_status = 'cached: ' . $cached['tag_name'];
        } elseif ($cached !== false) {
            $cache_status = 'unexpected: ' . gettype($cached) . ' = ' . var_export($cached, true);
        }

        // Check WordPress update transient.
        $update_transient = get_site_transient('update_plugins');
        $wp_sees_update = false;
        $wp_no_update = false;
        if (is_object($update_transient)) {
            if (isset($update_transient->response[$plugin_slug])) {
                $wp_sees_update = true;
            }
            if (isset($update_transient->no_update[$plugin_slug])) {
                $wp_no_update = true;
            }
        }
        $checked_version = $update_transient->checked[$plugin_slug] ?? 'not in checked array';

        $update_available = $github_ok && version_compare($current_version, $remote_version, '<');
        ?>
        <div class="workos-card">
            <div class="workos-card-header">
                <h2><?php esc_html_e('Self-Update Check', 'workos-for-wordpress'); ?></h2>
            </div>

            <?php if ($github_ok): ?>
                <div class="workos-alert <?php echo $update_available ? 'workos-alert-warning' : 'workos-alert-success'; ?>">
                    <?php if ($update_available): ?>
                        <?php echo esc_html(sprintf(__('Update available: %s → %s', 'workos-for-wordpress'), $current_version, $remote_version)); ?>
                    <?php else: ?>
                        <?php echo esc_html(sprintf(__('You are running the latest version (%s).', 'workos-for-wordpress'), $current_version)); ?>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="workos-alert workos-alert-error">
                    <?php echo esc_html(sprintf(__('GitHub API error: %s', 'workos-for-wordpress'), $github_error)); ?>
                </div>
            <?php endif; ?>

            <table class="workos-table">
                <tbody>
                    <tr>
                        <td class="workos-table-label"><?php esc_html_e('Installed Version', 'workos-for-wordpress'); ?></td>
                        <td><span class="workos-diag-value"><?php echo esc_html($current_version); ?></span></td>
                    </tr>
                    <tr>
                        <td class="workos-table-label"><?php esc_html_e('GitHub Latest', 'workos-for-wordpress'); ?></td>
                        <td><span class="workos-diag-value"><?php echo esc_html($remote_version ?: '—'); ?></span></td>
                    </tr>
                    <tr>
                        <td class="workos-table-label"><?php esc_html_e('Plugin Basename', 'workos-for-wordpress'); ?></td>
                        <td><span class="workos-diag-value"><?php echo esc_html($plugin_slug); ?></span></td>
                    </tr>
                    <tr>
                        <td class="workos-table-label"><?php esc_html_e('WP Checked Version', 'workos-for-wordpress'); ?></td>
                        <td><span class="workos-diag-value"><?php echo esc_html($checked_version); ?></span></td>
                    </tr>
                    <tr>
                        <td class="workos-table-label"><?php esc_html_e('Updater Cache', 'workos-for-wordpress'); ?></td>
                        <td><span class="workos-diag-value"><?php echo esc_html($cache_status); ?></span></td>
                    </tr>
                    <tr>
                        <td class="workos-table-label"><?php esc_html_e('WP Sees Update', 'workos-for-wordpress'); ?></td>
                        <td>
                            <?php if ($wp_sees_update): ?>
                                <span class="workos-badge workos-badge-success"><?php esc_html_e('Yes', 'workos-for-wordpress'); ?></span>
                            <?php elseif ($wp_no_update): ?>
                                <span class="workos-badge workos-badge-muted"><?php esc_html_e('No (checked, up to date)', 'workos-for-wordpress'); ?></span>
                            <?php else: ?>
                                <span class="workos-badge workos-badge-warning"><?php esc_html_e('Not in transient', 'workos-for-wordpress'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($zip_url): ?>
                    <tr>
                        <td class="workos-table-label"><?php esc_html_e('Download URL', 'workos-for-wordpress'); ?></td>
                        <td><span class="workos-diag-value" style="word-break:break-all;"><?php echo esc_html($zip_url); ?></span></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="workos-table-label"><?php esc_html_e('GitHub API', 'workos-for-wordpress'); ?></td>
                        <td>
                            <span class="workos-diag-value"><?php echo esc_html($api_url); ?></span>
                            <span class="workos-badge <?php echo $github_ok ? 'workos-badge-success' : 'workos-badge-error'; ?>">
                                <?php echo esc_html($github_ok ? 'HTTP 200' : "HTTP {$http_code}"); ?>
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }
}
