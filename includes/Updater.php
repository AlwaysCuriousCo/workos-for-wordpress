<?php

namespace AlwaysCurious\WorkOSWP;

defined('ABSPATH') || exit;

class Updater {

    private string $plugin_file;
    private string $plugin_slug;
    private string $version;
    private string $github_repo = 'AlwaysCuriousCo/workos-for-wordpress';
    private string $cache_key = 'workos_wp_update_check';
    private int $cache_ttl = 43200; // 12 hours

    public function __construct(string $plugin_file, string $version) {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename($plugin_file);
        $this->version = $version;
    }

    public function register_hooks(): void {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_filter('plugin_row_meta', [$this, 'plugin_row_meta'], 10, 2);

        // Clear cache when WordPress forces an update check.
        add_action('delete_site_transient_update_plugins', [$this, 'clear_cache']);
    }

    /**
     * Check GitHub for a newer release and inject it into the update transient.
     */
    public function check_for_update(object $transient): object {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_latest_release();
        if (!$release) {
            return $transient;
        }

        $remote_version = ltrim($release['tag_name'], 'v');

        if (version_compare($this->version, $remote_version, '<')) {
            $transient->response[$this->plugin_slug] = (object) [
                'slug'         => dirname($this->plugin_slug),
                'plugin'       => $this->plugin_slug,
                'new_version'  => $remote_version,
                'url'          => $release['html_url'],
                'package'      => $release['zip_url'],
                'icons'        => [],
                'banners'      => [],
                'tested'       => '',
                'requires_php' => '8.1',
                'requires'     => '6.4',
            ];
        } else {
            // Tell WordPress we checked and there's no update.
            $transient->no_update[$this->plugin_slug] = (object) [
                'slug'        => dirname($this->plugin_slug),
                'plugin'      => $this->plugin_slug,
                'new_version' => $this->version,
                'url'         => "https://github.com/{$this->github_repo}",
                'package'     => '',
            ];
        }

        return $transient;
    }

    /**
     * Provide plugin details for the "View details" modal in the update screen.
     */
    public function plugin_info($result, string $action, object $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== dirname($this->plugin_slug)) {
            return $result;
        }

        $release = $this->get_latest_release();
        if (!$release) {
            return $result;
        }

        $remote_version = ltrim($release['tag_name'], 'v');

        return (object) [
            'name'            => 'WorkOS for WordPress',
            'slug'            => dirname($this->plugin_slug),
            'version'         => $remote_version,
            'author'          => '<a href="https://alwayscurious.co">Always Curious</a>',
            'homepage'        => "https://github.com/{$this->github_repo}",
            'requires'        => '6.4',
            'requires_php'    => '8.1',
            'downloaded'      => 0,
            'last_updated'    => $release['published_at'],
            'sections'        => [
                'description'  => 'Enterprise-grade authentication and user management for WordPress, powered by WorkOS.',
                'changelog'    => $this->format_changelog($release['body']),
                'installation' => 'Upload the plugin zip via <strong>Plugins &gt; Add New &gt; Upload Plugin</strong>, then activate and configure under the <strong>WorkOS</strong> menu.',
            ],
            'download_link'   => $release['zip_url'],
            'banners'         => [],
        ];
    }

    /**
     * Add a "Check for updates" link in the plugin row on the Plugins page.
     */
    public function plugin_row_meta(array $meta, string $file): array {
        if ($file !== $this->plugin_slug) {
            return $meta;
        }

        $meta[] = sprintf(
            '<a href="%s">%s</a>',
            wp_nonce_url(admin_url('update-core.php?force-check=1'), 'upgrade-core'),
            __('Check for updates', 'workos-for-wordpress')
        );

        return $meta;
    }

    /**
     * Fetch the latest release from GitHub, with caching.
     */
    private function get_latest_release(): ?array {
        $cached = get_transient($this->cache_key);
        if ($cached !== false) {
            return $cached ?: null;
        }

        $response = wp_remote_get(
            "https://api.github.com/repos/{$this->github_repo}/releases/latest",
            [
                'headers' => [
                    'Accept'     => 'application/vnd.github.v3+json',
                    'User-Agent' => 'WorkOS-WordPress-Plugin/' . $this->version,
                ],
                'timeout' => 10,
            ]
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            // Cache the failure so we don't hammer the API.
            set_transient($this->cache_key, '', $this->cache_ttl);
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['tag_name'])) {
            set_transient($this->cache_key, '', $this->cache_ttl);
            return null;
        }

        // Find the plugin zip asset in the release.
        $zip_url = $this->find_zip_asset($body['assets'] ?? []);

        $data = [
            'tag_name'     => $body['tag_name'],
            'html_url'     => $body['html_url'],
            'body'         => $body['body'] ?? '',
            'published_at' => $body['published_at'] ?? '',
            'zip_url'      => $zip_url,
        ];

        set_transient($this->cache_key, $data, $this->cache_ttl);

        return $data;
    }

    /**
     * Find the plugin zip from release assets.
     * Falls back to the GitHub source zip if no matching asset is found.
     */
    private function find_zip_asset(array $assets): string {
        foreach ($assets as $asset) {
            if (
                str_starts_with($asset['name'], 'workos-for-wordpress') &&
                str_ends_with($asset['name'], '.zip')
            ) {
                return $asset['browser_download_url'];
            }
        }

        // Fallback: GitHub's auto-generated source zipball.
        return "https://github.com/{$this->github_repo}/releases/latest/download/workos-for-wordpress.zip";
    }

    /**
     * Convert GitHub markdown release notes to basic HTML for the changelog tab.
     */
    private function format_changelog(string $markdown): string {
        if (empty($markdown)) {
            return '<p>See the <a href="https://github.com/' . esc_attr($this->github_repo) . '/releases">release notes on GitHub</a>.</p>';
        }

        // Basic markdown to HTML: headings, bold, lists, links.
        $html = esc_html($markdown);
        $html = preg_replace('/^### (.+)$/m', '<h4>$1</h4>', $html);
        $html = preg_replace('/^## (.+)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
        $html = preg_replace('/^\* (.+)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/^- (.+)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $html);
        $html = nl2br($html);

        return $html;
    }

    /**
     * Clear the cached release data.
     */
    public function clear_cache(): void {
        delete_transient($this->cache_key);
    }
}
