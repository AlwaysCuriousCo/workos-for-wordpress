<?php

namespace AlwaysCurious\WorkOSWP;

defined('ABSPATH') || exit;

class LearningMode {

    private \WorkOS\UserManagement $user_management;

    public function __construct() {
        $this->user_management = new \WorkOS\UserManagement();
    }

    /**
     * Check if learning mode is enabled.
     */
    public static function is_enabled(): bool {
        return (bool) get_option('workos_learning_mode', false);
    }

    /**
     * Get the organization ID for learning mode operations.
     */
    private function get_organization_id(): string {
        return Plugin::instance()->get_organization_id();
    }

    /**
     * Get all WordPress users not yet processed by learning mode.
     *
     * Uses _workos_synced_at (set only by learning mode) rather than _workos_user_id
     * (set by AuthKit login), so users who logged in via WorkOS but were never
     * verified for org membership still appear as pending.
     */
    public function get_syncable_users(): array {
        return get_users([
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key'     => '_workos_synced_at',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'   => '_workos_synced_at',
                    'value' => '',
                ],
            ],
            'number' => -1,
        ]);
    }

    /**
     * Get users already processed by learning mode.
     */
    public function get_synced_users(): array {
        return get_users([
            'meta_query' => [
                [
                    'key'     => '_workos_synced_at',
                    'compare' => 'EXISTS',
                ],
                [
                    'key'     => '_workos_synced_at',
                    'value'   => '',
                    'compare' => '!=',
                ],
            ],
            'number' => -1,
        ]);
    }

    /**
     * Sync a single WordPress user to WorkOS.
     *
     * 1. Check if user exists in WorkOS by email
     * 2. If not, create them
     * 3. Add organization membership if not already a member
     *
     * Returns an array with sync result details.
     */
    public function sync_user(\WP_User $wp_user): array {
        $org_id = $this->get_organization_id();
        if (empty($org_id)) {
            return [
                'success' => false,
                'action'  => 'skipped',
                'message' => __('No organization ID configured.', 'workos-for-wordpress'),
            ];
        }

        $email = $wp_user->user_email;
        if (empty($email)) {
            return [
                'success' => false,
                'action'  => 'skipped',
                'message' => __('User has no email address.', 'workos-for-wordpress'),
            ];
        }

        try {
            // Step 1: Look up user in WorkOS by email (covers both previously
            // linked users from AuthKit login and independently created users).
            $workos_user = $this->find_workos_user_by_email($email);
            $action = 'linked';

            if (!$workos_user) {
                // Step 2: Create the user in WorkOS.
                $workos_user = $this->create_workos_user($wp_user);
                $action = 'created';
            }

            // Step 3: Ensure organization membership.
            $membership_action = $this->ensure_organization_membership(
                $workos_user->id,
                $org_id,
                $wp_user
            );

            // Store WorkOS user ID on the WordPress user.
            update_user_meta($wp_user->ID, '_workos_user_id', $workos_user->id);
            update_user_meta($wp_user->ID, '_workos_organization_id', $org_id);
            update_user_meta($wp_user->ID, '_workos_synced_at', current_time('mysql', true));

            ActivityLog::record('learning_mode_sync', [
                'user_id'        => $wp_user->ID,
                'user_email'     => $email,
                'workos_user_id' => $workos_user->id,
                'metadata'       => [
                    'action'            => $action,
                    'membership_action' => $membership_action,
                    'organization_id'   => $org_id,
                ],
            ]);

            return [
                'success'           => true,
                'action'            => $action,
                'membership_action' => $membership_action,
                'workos_user_id'    => $workos_user->id,
                'message'           => sprintf(
                    __('User %s: %s in WorkOS, %s organization membership.', 'workos-for-wordpress'),
                    $email,
                    $action,
                    $membership_action
                ),
            ];

        } catch (\Exception $e) {
            ActivityLog::record('learning_mode_error', [
                'user_id'    => $wp_user->ID,
                'user_email' => $email,
                'metadata'   => ['error' => $e->getMessage()],
            ]);

            return [
                'success' => false,
                'action'  => 'error',
                'message' => sprintf(
                    __('Failed to sync %s: %s', 'workos-for-wordpress'),
                    $email,
                    $e->getMessage()
                ),
            ];
        }
    }

    /**
     * Find a WorkOS user by email address.
     */
    private function find_workos_user_by_email(string $email): ?object {
        [$before, $after, $users] = $this->user_management->listUsers(
            email: $email,
            limit: 1
        );

        return !empty($users) ? $users[0] : null;
    }

    /**
     * Create a new user in WorkOS from WordPress user data.
     */
    private function create_workos_user(\WP_User $wp_user): object {
        return $this->user_management->createUser(
            email: $wp_user->user_email,
            firstName: $wp_user->first_name ?: null,
            lastName: $wp_user->last_name ?: null,
            emailVerified: true
        );
    }

    /**
     * Ensure the WorkOS user has an active membership in the organization.
     * Returns the action taken: 'existing', 'created', or 'reactivated'.
     */
    private function ensure_organization_membership(string $workos_user_id, string $org_id, \WP_User $wp_user): string {
        // Check for existing membership.
        [$before, $after, $memberships] = $this->user_management->listOrganizationMemberships(
            userId: $workos_user_id,
            organizationId: $org_id,
            limit: 1
        );

        if (!empty($memberships)) {
            $membership = $memberships[0];
            $status = $membership->status ?? 'active';
            if ($status === 'active') {
                return 'existing';
            }
        }

        // Create new membership. Map WP role to WorkOS role if possible.
        $wp_role = $wp_user->roles[0] ?? get_option('default_role', 'subscriber');
        $workos_role_slug = $this->get_workos_role_for_wp_role($wp_role);

        $params = [
            'userId'         => $workos_user_id,
            'organizationId' => $org_id,
        ];

        if ($workos_role_slug) {
            $params['roleSlug'] = $workos_role_slug;
        }

        $this->user_management->createOrganizationMembership(
            $workos_user_id,
            $org_id,
            $workos_role_slug
        );

        return 'created';
    }

    /**
     * Reverse lookup: given a WordPress role, find the corresponding WorkOS role slug.
     */
    private function get_workos_role_for_wp_role(string $wp_role): ?string {
        $role_map = get_option('workos_role_map', []);
        if (!is_array($role_map)) {
            return null;
        }

        foreach ($role_map as $mapping) {
            if (($mapping['wp_role'] ?? '') === $wp_role) {
                return $mapping['workos_role'] ?? null;
            }
        }
        return null;
    }

    /**
     * Run a batch sync of all unsynchronized WordPress users.
     * Returns summary statistics.
     */
    public function run_batch_sync(): array {
        $users = $this->get_syncable_users();
        $results = [
            'total'   => count($users),
            'created' => 0,
            'linked'  => 0,
            'errors'  => 0,
            'skipped' => 0,
            'details' => [],
        ];

        foreach ($users as $user) {
            $result = $this->sync_user($user);
            $results['details'][] = $result;

            if ($result['success']) {
                if ($result['action'] === 'created') {
                    $results['created']++;
                } else {
                    $results['linked']++;
                }
            } elseif ($result['action'] === 'skipped') {
                $results['skipped']++;
            } else {
                $results['errors']++;
            }
        }

        return $results;
    }
}
