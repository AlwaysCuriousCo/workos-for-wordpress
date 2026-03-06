# WorkOS for WordPress

**Enterprise-grade authentication and user management for WordPress, powered by [WorkOS](https://workos.com).**

Replace the default WordPress login with WorkOS AuthKit and unlock SSO, SAML, OIDC, social login, MFA, and centralized user management — all without writing a single line of code.

---

## Why WorkOS for WordPress?

WordPress powers millions of business-critical sites, but its built-in authentication wasn't designed for enterprise requirements. WorkOS for WordPress bridges that gap:

- **Single Sign-On (SSO)** — Let users authenticate with their corporate identity provider via SAML or OIDC.
- **AuthKit hosted login** — A polished, branded login experience with social login, email + password, Magic Auth, and MFA built in.
- **Organization-based access control** — Restrict site access to members of a specific WorkOS organization.
- **Role synchronization** — Map WorkOS organization roles to WordPress roles automatically.
- **User lifecycle management** — Import existing WordPress users into WorkOS, or enforce entitlements and suspend users who lose access.
- **Audit logging** — Track every login, logout, and access decision locally.

---

## Requirements

- WordPress 6.4 or later
- PHP 8.1 or later
- A [WorkOS account](https://workos.com) (free to start)
- Composer (for installing dependencies)

---

## Installation

### Download a Release (recommended)

1. **Download the latest zip** from [GitHub Releases](https://github.com/AlwaysCuriousCo/workos-for-wordpress/releases). The zip includes all dependencies — no Composer required.
2. In your WordPress admin, go to **Plugins > Add New > Upload Plugin** and upload the zip.
3. **Activate** the plugin.
4. **Configure your credentials** under the new **WorkOS** menu in the admin sidebar.

### Install from Source

If you prefer to clone the repository directly:

1. Clone into your plugins directory:

   ```bash
   cd wp-content/plugins/
   git clone https://github.com/AlwaysCuriousCo/workos-for-wordpress.git
   ```

2. Install dependencies via Composer:

   ```bash
   cd workos-for-wordpress
   composer install
   ```

3. **Activate** the plugin and configure under **WorkOS** in the admin sidebar.

---

## Configuration

### Quick Start

1. Navigate to **WorkOS > Welcome** in your WordPress admin.
2. Enter your **API Key** and **Client ID** from the [WorkOS Dashboard](https://dashboard.workos.com).
3. Go to **WorkOS > Organization & Roles** and enter your **Organization ID**.
4. In your WorkOS Dashboard, add your callback URL as a **Redirect URI**:
   ```
   https://your-site.com/workos/callback
   ```
5. Visit **WorkOS > Diagnostics** to verify connectivity.

That's it — your WordPress login page now redirects through WorkOS AuthKit.

### Environment-Based Configuration

For managed deployments, staging environments, or version-controlled infrastructure, you can define credentials as constants in `wp-config.php` instead of storing them in the database:

```php
define('WORKOS_API_KEY', 'sk_live_...');
define('WORKOS_CLIENT_ID', 'client_...');
define('WORKOS_ORGANIZATION_ID', 'org_...');
```

When constants are defined, the corresponding fields in the admin UI become read-only and display where the value is sourced from.

---

## Features

### AuthKit Login

When the plugin is configured, the WordPress login page (`wp-login.php`) automatically redirects to WorkOS AuthKit. Users authenticate through your configured providers — SSO, social login, email/password, Magic Auth — and are redirected back to WordPress with a session established.

- **Automatic user provisioning** — New users are created in WordPress on first login with their WorkOS profile data.
- **Profile sync** — First name, last name, and email are updated from WorkOS on each login.
- **Session management** — WorkOS session IDs are tracked so logout properly revokes both the WordPress session and the AuthKit hosted session.
- **Bypass mode** — Append `?workos_bypass` to `wp-login.php` to access the native WordPress login form (useful for emergency access).

### Organization & Role Mapping

Bind your WordPress site to a WorkOS organization and map WorkOS roles to WordPress roles.

1. Go to **WorkOS > Organization & Roles**.
2. Enter your Organization ID (or set it via `WORKOS_ORGANIZATION_ID` in `wp-config.php`).
3. Add role mappings — for example, map the WorkOS `admin` role to the WordPress `administrator` role, and `member` to `editor`.

On each login, the plugin fetches the user's organization membership from WorkOS and sets their WordPress role accordingly. Users without a mapped role keep their current role.

### Organization Entitlement Gate

When enabled, the entitlement gate requires users to have an **active membership** in your configured WorkOS organization before they can log in. Users who authenticate successfully via WorkOS but are not organization members are denied access with a clear message.

Enable this under **WorkOS > Learning Mode** → "Organization Entitlement Gate."

### Learning Mode

Learning Mode is designed for adopting WorkOS on an **existing WordPress site** with established users. When enabled, it discovers your WordPress users and syncs them into WorkOS:

- **Users not in WorkOS** are created with their WordPress profile data (email, first name, last name) and marked as email-verified.
- **Users already in WorkOS** (matched by email) are linked to their WordPress account.
- **Organization membership** is ensured for every synced user, with WordPress roles reverse-mapped to WorkOS roles.

#### How to Use Learning Mode

1. Go to **WorkOS > Learning Mode**.
2. Enable Learning Mode.
3. Review the list of pending users.
4. Click **Sync All** to process all users, or sync individual users one at a time.
5. Once all users are synced, disable Learning Mode.

The sync runs sequentially with a built-in delay between API calls to respect rate limits. Progress is displayed in real time.

#### Best Practices

- **Back up your database** before running a batch sync.
- **Test with a small group first** — sync a few users individually before running a full batch.
- **Review role mappings** before syncing to ensure WordPress roles map correctly to WorkOS roles.
- **Enable the Entitlement Gate** after syncing to enforce organization-based access going forward.
- **Disable Learning Mode** once all users are imported — it is an onboarding tool, not a permanent setting.

### Users Table Integration

The plugin enhances the standard WordPress **Users** table (`wp-admin/users.php`) with:

- **WorkOS status column** — Shows a badge for each user:
  - **Synced** (green) — User is fully synced with WorkOS and has an active organization membership. Hover to see the sync timestamp.
  - **Linked** (purple) — User has logged in via WorkOS but hasn't been processed by Learning Mode.
  - **Not synced** (gray) — No WorkOS association.
  - **Suspended** (red) — User was suspended because they are not entitled in WorkOS. Hover to see the reason.

- **reSync action** — Appears in each user's row actions (visible to administrators):
  - **Learning Mode ON** → "reSync to WorkOS" — Pushes the user's data to WorkOS and ensures organization membership.
  - **Learning Mode OFF** → "reSync from WorkOS" — Verifies the user exists in WorkOS with an active organization membership. If they don't, the user is **suspended** (all WordPress roles are removed, preventing login). Their account and content are preserved.

### Activity Tracking

An optional local audit log that records authentication events. No data is sent externally.

Enable it under **WorkOS > Usage** → "Enable activity tracking."

**Tracked events:**

| Event | Description |
|---|---|
| `login` | Successful authentication via WorkOS AuthKit |
| `logout` | User logged out (WordPress + WorkOS session revoked) |
| `login_failed` | Authentication error during the OAuth callback |
| `login_denied` | User blocked by the organization entitlement gate |
| `learning_mode_sync` | User synced to WorkOS via Learning Mode |
| `learning_mode_error` | Error during Learning Mode sync |
| `user_suspended` | User suspended after failing pull-mode reSync |

The **Usage** page displays:
- Login, logout, and failed login counts (last 30 days)
- Unique user count (last 30 days)
- A table of the 20 most recent events with user email, IP address, and timestamp
- A button to clear all logged events

### Diagnostics

The **Diagnostics** page verifies your WorkOS configuration:

- Tests connectivity to the WorkOS API by generating an authorization URL.
- Displays your Client ID, API Key (last 4 characters only), Organization ID, Redirect URI, and generated Auth URL.
- Indicates whether each value is sourced from the database or a `wp-config.php` constant.

---

## How It Works

### Login Flow

```
User visits wp-login.php
    → Redirected to WorkOS AuthKit
    → User authenticates (SSO / email / social / MFA)
    → WorkOS redirects to /workos/callback with authorization code
    → Plugin exchanges code for access token + user profile
    → [Optional] Entitlement gate checks org membership
    → WordPress user created or matched (by WorkOS ID, then email)
    → Role synced from WorkOS organization membership
    → WordPress session established
    → User redirected to their intended destination
```

### Logout Flow

```
User clicks Log Out in WordPress
    → WordPress session cleared
    → Plugin revokes WorkOS session server-side
    → User redirected to WorkOS logout endpoint
    → AuthKit hosted session cookie cleared
    → User returned to site homepage
```

### ReSync (Pull Mode) Flow

```
Admin clicks "reSync from WorkOS" on a user
    → Plugin looks up user by email in WorkOS
    → If not found → user suspended (roles removed)
    → If found, checks for active organization membership
    → If no active membership → user suspended
    → If active membership → meta updated, role synced, badge set to "Synced"
```

---

## User Metadata Reference

The plugin stores the following metadata on WordPress user records:

| Meta Key | Set By | Description |
|---|---|---|
| `_workos_user_id` | AuthKit / Learning Mode | The user's WorkOS user ID |
| `_workos_access_token` | AuthKit | OAuth access token (JWT) |
| `_workos_refresh_token` | AuthKit | OAuth refresh token |
| `_workos_session_id` | AuthKit | Session ID for logout |
| `_workos_organization_id` | AuthKit / Learning Mode | Organization the user belongs to |
| `_workos_role_slug` | AuthKit / reSync | Last synced WorkOS role slug |
| `_workos_synced_at` | Learning Mode / reSync | Timestamp of last Learning Mode sync |
| `_workos_suspended` | reSync (pull mode) | Flag indicating user is suspended |
| `_workos_suspended_reason` | reSync (pull mode) | `not_found_in_workos` or `no_org_membership` |
| `_workos_suspended_at` | reSync (pull mode) | Timestamp of suspension |

---

## Bypass & Recovery

If you are locked out due to a misconfiguration:

1. **Bypass AuthKit** — Access `wp-login.php?workos_bypass` to use the native WordPress login form.
2. **Disable via wp-config.php** — If the plugin prevents all access, deactivate it by renaming the plugin directory or adding to `wp-config.php`:
   ```php
   // Temporarily disable WorkOS plugin
   define('WORKOS_API_KEY', '');
   ```
3. **WP-CLI** — Deactivate the plugin from the command line:
   ```bash
   wp plugin deactivate workos-for-wordpress
   ```

---

## Security

- API keys and tokens are stored in the WordPress database using standard options and user meta. For enhanced security, define credentials in `wp-config.php` and restrict file permissions.
- Access tokens are stored per-user in user meta and cleared on logout.
- All AJAX handlers verify nonces and check `manage_options` capability.
- The activity log stores IP addresses and user agents for audit purposes. Enable only if your privacy policy permits it.

---

## FAQ

**Does this replace the WordPress login entirely?**
By default, yes — `wp-login.php` redirects to AuthKit. You can always access the native form via `?workos_bypass`.

**Can I use this on a multisite installation?**
The plugin is designed for single-site WordPress installations. Multisite support is not currently included.

**What happens if WorkOS is unreachable?**
The callback will fail with an error message. Users can use the bypass parameter to access the native login form as a fallback.

**Will existing users lose access?**
No. Existing users are matched by email on first AuthKit login. Use Learning Mode to proactively sync users before enforcing the entitlement gate.

**What happens to suspended users' content?**
Suspended users retain their account, posts, and content. Only their WordPress roles are removed, which prevents login. Re-enable their access by re-syncing with Learning Mode enabled or by manually assigning a role.

---

## License

GPL-3.0-or-later. See [LICENSE](LICENSE) for details.

---

## Credits

Built by [Always Curious](https://alwayscurious.co). Powered by [WorkOS](https://workos.com).
