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
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

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

    /**
     * Enqueue admin CSS only on our plugin pages.
     */
    public function enqueue_admin_assets(string $hook): void {
        $plugin_pages = [
            'toplevel_page_workos-settings',
            'workos_page_workos-role-mapping',
            'workos_page_workos-diagnostics',
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

        add_submenu_page(
            'workos-settings',
            __('Organization & Roles', 'workos-for-wordpress'),
            __('Organization & Roles', 'workos-for-wordpress'),
            'manage_options',
            'workos-role-mapping',
            [$this, 'render_role_mapping_page']
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
            'workos-settings'     => __('Welcome', 'workos-for-wordpress'),
            'workos-role-mapping' => __('Organization & Roles', 'workos-for-wordpress'),
            'workos-diagnostics'  => __('Diagnostics', 'workos-for-wordpress'),
        ];
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
        $api_key = get_option('workos_api_key', '');
        $client_id = get_option('workos_client_id', '');
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

            <form action="options.php" method="post">
                <?php settings_fields('workos_api_settings'); ?>

                <div class="workos-card">
                    <div class="workos-card-header">
                        <h2><?php esc_html_e('Credentials', 'workos-for-wordpress'); ?></h2>
                        <p><?php esc_html_e('Your API Key and Client ID can be found in the WorkOS Dashboard under API Keys.', 'workos-for-wordpress'); ?></p>
                    </div>

                    <div class="workos-field">
                        <label for="workos_api_key"><?php esc_html_e('API Key', 'workos-for-wordpress'); ?></label>
                        <input type="password" id="workos_api_key" name="workos_api_key" value="<?php echo esc_attr($api_key); ?>" placeholder="sk_test_..." />
                    </div>

                    <div class="workos-field">
                        <label for="workos_client_id"><?php esc_html_e('Client ID', 'workos-for-wordpress'); ?></label>
                        <input type="text" id="workos_client_id" name="workos_client_id" value="<?php echo esc_attr($client_id); ?>" placeholder="client_..." />
                    </div>
                </div>

                <div class="workos-card">
                    <div class="workos-card-header">
                        <h2><?php esc_html_e('Redirect URI', 'workos-for-wordpress'); ?></h2>
                        <p><?php esc_html_e('Add this URL as a Redirect URI in your WorkOS Dashboard under Redirects.', 'workos-for-wordpress'); ?></p>
                    </div>
                    <div class="workos-code"><?php echo esc_html($redirect_uri); ?></div>
                </div>

                <button type="submit" class="workos-btn workos-btn-primary"><?php esc_html_e('Save Changes', 'workos-for-wordpress'); ?></button>
            </form>
        </div>
        <?php
    }

    public function render_role_mapping_page(): void {
        $org_id = get_option('workos_organization_id', '');
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
                <h1><?php esc_html_e('Organization & Roles', 'workos-for-wordpress'); ?></h1>
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
                </div>

                <?php if ($this->is_configured() && !empty($org_id) && empty($workos_roles)): ?>
                    <div class="workos-alert workos-alert-error">
                        <?php esc_html_e('Could not fetch roles for this organization. Verify the organization is correct and your API key has access.', 'workos-for-wordpress'); ?>
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
        $org_id = get_option('workos_organization_id');
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

        $client_id = get_option('workos_client_id');
        $api_key = get_option('workos_api_key');
        $org_id = get_option('workos_organization_id');

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
                        </td>
                    </tr>
                    <tr>
                        <td class="workos-table-label"><?php esc_html_e('API Key', 'workos-for-wordpress'); ?></td>
                        <td>
                            <span class="workos-diag-value"><?php echo esc_html(substr($api_key, 0, 7) . '...' . substr($api_key, -4)); ?></span>
                            <span class="workos-badge workos-badge-muted"><?php echo strlen($api_key); ?> chars</span>
                        </td>
                    </tr>
                    <tr>
                        <td class="workos-table-label"><?php esc_html_e('Organization ID', 'workos-for-wordpress'); ?></td>
                        <td>
                            <?php if ($org_id): ?>
                                <span class="workos-diag-value"><?php echo esc_html($org_id); ?></span>
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
        <?php
    }
}
