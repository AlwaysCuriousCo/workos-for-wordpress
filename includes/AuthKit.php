<?php

namespace AlwaysCurious\WorkOSWP;

defined('ABSPATH') || exit;

class AuthKit {

    private \WorkOS\UserManagement $user_management;

    public function __construct() {
        $this->user_management = new \WorkOS\UserManagement();
    }

    public function register_hooks(): void {
        add_action('login_init', [$this, 'intercept_login']);
        add_action('template_redirect', [$this, 'handle_callback']);
        add_action('wp_logout', [$this, 'handle_logout'], 10, 1);
        add_filter('login_url', [$this, 'filter_login_url'], 10, 3);
    }

    /**
     * Get the callback URL that WorkOS will redirect to after authentication.
     */
    public static function get_callback_url(): string {
        return home_url('/workos/callback');
    }

    /**
     * Intercept the WordPress login page and redirect to WorkOS AuthKit.
     */
    public function intercept_login(): void {
        // Don't redirect for logout, post-logout, password resets, or explicit bypass.
        if (
            isset($_GET['action']) && in_array($_GET['action'], ['logout', 'lostpassword', 'rp', 'resetpass', 'postpass'], true) ||
            isset($_GET['loggedout']) ||
            isset($_GET['workos_bypass']) ||
            'POST' === $_SERVER['REQUEST_METHOD']
        ) {
            return;
        }

        $redirect_to = $_GET['redirect_to'] ?? admin_url();
        $org_id = get_option('workos_organization_id');

        $authorization_url = $this->user_management->getAuthorizationUrl(
            self::get_callback_url(),
            ['redirect_to' => $redirect_to],
            \WorkOS\UserManagement::AUTHORIZATION_PROVIDER_AUTHKIT,
            null,
            $org_id ?: null
        );

        wp_redirect($authorization_url);
        exit;
    }

    /**
     * Handle the OAuth callback from WorkOS AuthKit.
     */
    public function handle_callback(): void {
        if (!$this->is_callback_request()) {
            return;
        }

        if (isset($_GET['error'])) {
            wp_die(
                esc_html(sprintf(
                    __('Authentication failed: %s', 'workos-for-wordpress'),
                    $_GET['error_description'] ?? $_GET['error']
                )),
                __('Login Error', 'workos-for-wordpress'),
                ['response' => 403]
            );
        }

        $code = sanitize_text_field($_GET['code'] ?? '');
        if (empty($code)) {
            wp_die(
                esc_html__('Missing authorization code.', 'workos-for-wordpress'),
                __('Login Error', 'workos-for-wordpress'),
                ['response' => 400]
            );
        }

        try {
            $client_id = \WorkOS\WorkOS::getClientId();
            $response = $this->user_management->authenticateWithCode($client_id, $code);

            $workos_user = $response->user;

            // Organization entitlement gate: deny login if user is not a member.
            if ($this->is_entitlement_gate_active()) {
                $org_id = $response->organizationId ?? get_option('workos_organization_id');
                if (!$this->user_has_org_entitlement($workos_user->id, $org_id)) {
                    ActivityLog::record('login_denied', [
                        'user_email'     => $workos_user->email,
                        'workos_user_id' => $workos_user->id,
                        'metadata'       => [
                            'reason'          => 'no_org_entitlement',
                            'organization_id' => $org_id,
                        ],
                    ]);

                    wp_die(
                        esc_html__('Access denied. You are not entitled to this site. Contact your administrator to request access.', 'workos-for-wordpress'),
                        __('Access Denied', 'workos-for-wordpress'),
                        ['response' => 403]
                    );
                }
            }

            $wp_user = $this->find_or_create_user($workos_user);

            if (is_wp_error($wp_user)) {
                wp_die(
                    esc_html($wp_user->get_error_message()),
                    __('Login Error', 'workos-for-wordpress'),
                    ['response' => 403]
                );
            }

            // Store WorkOS metadata on the user.
            update_user_meta($wp_user->ID, '_workos_user_id', $workos_user->id);
            update_user_meta($wp_user->ID, '_workos_access_token', $response->accessToken);
            update_user_meta($wp_user->ID, '_workos_refresh_token', $response->refreshToken);
            if (!empty($response->organizationId)) {
                update_user_meta($wp_user->ID, '_workos_organization_id', $response->organizationId);
            }

            // Sync WordPress role from WorkOS organization membership.
            $this->sync_user_role($wp_user, $workos_user->id, $response->organizationId ?? null);

            // Extract and store the session ID from the access token for logout.
            $session_id = $this->extract_session_id($response->accessToken);
            if ($session_id) {
                update_user_meta($wp_user->ID, '_workos_session_id', $session_id);
            }

            // Log the user in.
            wp_set_current_user($wp_user->ID);
            wp_set_auth_cookie($wp_user->ID, true);
            do_action('wp_login', $wp_user->user_login, $wp_user);

            // Record login event for activity tracking.
            ActivityLog::record('login', [
                'user_id'        => $wp_user->ID,
                'user_email'     => $wp_user->user_email,
                'workos_user_id' => $workos_user->id,
                'metadata'       => [
                    'organization_id' => $response->organizationId ?? null,
                    'method'          => 'authkit',
                ],
            ]);

            // Redirect to the intended destination.
            $state = $this->decode_state($_GET['state'] ?? '');
            $redirect_to = $state['redirect_to'] ?? admin_url();

            wp_safe_redirect($redirect_to);
            exit;

        } catch (\Exception $e) {
            ActivityLog::record('login_failed', [
                'metadata' => ['error' => $e->getMessage()],
            ]);

            wp_die(
                esc_html(sprintf(
                    __('Authentication error: %s', 'workos-for-wordpress'),
                    $e->getMessage()
                )),
                __('Login Error', 'workos-for-wordpress'),
                ['response' => 500]
            );
        }
    }

    /**
     * Handle WordPress logout: revoke the WorkOS session and redirect to
     * WorkOS logout endpoint to clear the hosted AuthKit session cookie.
     *
     * WordPress has already called wp_clear_auth_cookie() before this hook fires,
     * so the WP session is destroyed regardless of what happens here.
     */
    public function handle_logout(int $user_id): void {
        if (!$user_id) {
            return;
        }

        $user = get_user_by('id', $user_id);
        ActivityLog::record('logout', [
            'user_id'        => $user_id,
            'user_email'     => $user ? $user->user_email : null,
            'workos_user_id' => get_user_meta($user_id, '_workos_user_id', true) ?: null,
        ]);

        $session_id = get_user_meta($user_id, '_workos_session_id', true);

        // Clean up all stored WorkOS metadata.
        delete_user_meta($user_id, '_workos_access_token');
        delete_user_meta($user_id, '_workos_refresh_token');
        delete_user_meta($user_id, '_workos_session_id');

        if (!$session_id) {
            return;
        }

        // Revoke the session server-side so the token can't be reused.
        try {
            $this->user_management->revokeSession($session_id);
        } catch (\Exception $e) {
            // Session may already be expired — continue to clear hosted cookie.
        }

        // Redirect to WorkOS logout to clear the AuthKit hosted session cookie,
        // then return to the site homepage.
        $logout_url = $this->user_management->getLogoutUrl($session_id, home_url('/'));
        wp_redirect($logout_url);
        exit;
    }

    /**
     * Filter login URL to point to wp-login.php which will be intercepted.
     */
    public function filter_login_url(string $login_url, string $redirect, bool $force_reauth): string {
        if (!empty($redirect)) {
            $login_url = add_query_arg('redirect_to', urlencode($redirect), $login_url);
        }
        return $login_url;
    }

    /**
     * Find an existing WordPress user by WorkOS ID or email, or create a new one.
     */
    private function find_or_create_user(object $workos_user): \WP_User|\WP_Error {
        // First, look up by WorkOS user ID.
        $existing_users = get_users([
            'meta_key'   => '_workos_user_id',
            'meta_value' => $workos_user->id,
            'number'     => 1,
        ]);

        if (!empty($existing_users)) {
            $wp_user = $existing_users[0];
            $this->update_user_profile($wp_user->ID, $workos_user);
            return $wp_user;
        }

        // Fall back to email lookup.
        $wp_user = get_user_by('email', $workos_user->email);
        if ($wp_user) {
            $this->update_user_profile($wp_user->ID, $workos_user);
            return $wp_user;
        }

        // Create a new user.
        $user_id = wp_insert_user([
            'user_login'   => $this->generate_username($workos_user),
            'user_email'   => $workos_user->email,
            'first_name'   => $workos_user->firstName ?? '',
            'last_name'    => $workos_user->lastName ?? '',
            'display_name' => trim(($workos_user->firstName ?? '') . ' ' . ($workos_user->lastName ?? '')) ?: $workos_user->email,
            'user_pass'    => wp_generate_password(32, true, true),
            'role'         => get_option('default_role', 'subscriber'),
        ]);

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        return get_user_by('id', $user_id);
    }

    /**
     * Update an existing WordPress user's profile from WorkOS data.
     */
    private function update_user_profile(int $user_id, object $workos_user): void {
        $update_data = ['ID' => $user_id];

        if (!empty($workos_user->firstName)) {
            $update_data['first_name'] = $workos_user->firstName;
        }
        if (!empty($workos_user->lastName)) {
            $update_data['last_name'] = $workos_user->lastName;
        }

        if (count($update_data) > 1) {
            wp_update_user($update_data);
        }
    }

    /**
     * Generate a unique username from WorkOS user data.
     */
    private function generate_username(object $workos_user): string {
        $base = strstr($workos_user->email, '@', true);
        $username = sanitize_user($base, true);

        if (username_exists($username)) {
            $i = 1;
            while (username_exists($username . $i)) {
                $i++;
            }
            $username .= $i;
        }

        return $username;
    }

    /**
     * Check if the current request is a WorkOS callback.
     */
    private function is_callback_request(): bool {
        $path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
        return $path === 'workos/callback';
    }

    /**
     * Fetch the user's WorkOS organization membership and sync their WordPress role.
     */
    private function sync_user_role(\WP_User $wp_user, string $workos_user_id, ?string $org_id): void {
        if (empty($org_id)) {
            $org_id = get_option('workos_organization_id');
        }
        if (empty($org_id)) {
            return;
        }

        try {
            [$before, $after, $memberships] = $this->user_management->listOrganizationMemberships(
                userId: $workos_user_id,
                organizationId: $org_id,
                statuses: ['active'],
                limit: 1
            );

            if (empty($memberships)) {
                return;
            }

            $membership = $memberships[0];
            $workos_role_slug = $membership->role->slug ?? null;

            if (!$workos_role_slug) {
                return;
            }

            $wp_role = Plugin::get_wp_role_for_workos_role($workos_role_slug);
            if ($wp_role && !in_array($wp_role, $wp_user->roles, true)) {
                $wp_user->set_role($wp_role);
            }

            update_user_meta($wp_user->ID, '_workos_role_slug', $workos_role_slug);
        } catch (\Exception $e) {
            // Role sync is best-effort — don't block login on failure.
        }
    }

    /**
     * Extract the session ID (sid claim) from a WorkOS access token JWT.
     */
    private function extract_session_id(string $access_token): ?string {
        $parts = explode('.', $access_token);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        return $payload['sid'] ?? null;
    }

    /**
     * Check if the organization entitlement gate is active.
     */
    private function is_entitlement_gate_active(): bool {
        return (bool) get_option('workos_org_entitlement_gate', false)
            && !empty(get_option('workos_organization_id'));
    }

    /**
     * Check if a WorkOS user has an active membership in the configured organization.
     */
    private function user_has_org_entitlement(string $workos_user_id, ?string $org_id): bool {
        if (empty($org_id)) {
            return false;
        }

        try {
            [$before, $after, $memberships] = $this->user_management->listOrganizationMemberships(
                userId: $workos_user_id,
                organizationId: $org_id,
                statuses: ['active'],
                limit: 1
            );

            return !empty($memberships);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Decode the state parameter from the callback.
     */
    private function decode_state(string $state): array {
        if (empty($state)) {
            return [];
        }

        $decoded = json_decode($state, true);
        return is_array($decoded) ? $decoded : [];
    }
}
