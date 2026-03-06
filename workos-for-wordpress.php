<?php
/**
 * Plugin Name: WorkOS for WordPress
 * Plugin URI:  https://github.com/AlwaysCuriousCo/workos-for-wordpress
 * Description: Integrate WorkOS authentication and user management into WordPress.
 * Version:     1.1.3
 * Author:      Always Curious
 * Author URI:  https://alwayscurious.co
 * License:     GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: workos-for-wordpress
 * Requires PHP: 8.1
 * Requires at least: 6.4
 */

defined('ABSPATH') || exit;

define('WORKOS_WP_VERSION', '1.1.3');
define('WORKOS_WP_PLUGIN_FILE', __FILE__);
define('WORKOS_WP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WORKOS_WP_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load Composer autoloader.
$autoloader = WORKOS_WP_PLUGIN_DIR . 'vendor/autoload.php';
if (! file_exists($autoloader)) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('WorkOS for WordPress requires Composer dependencies. Please run <code>composer install</code> in the plugin directory.', 'workos-for-wordpress');
        echo '</p></div>';
    });
    return;
}
require_once $autoloader;

use AlwaysCurious\WorkOSWP\Plugin;
use AlwaysCurious\WorkOSWP\Updater;

Plugin::instance();

// Self-hosted update checker via GitHub Releases.
$updater = new Updater(WORKOS_WP_PLUGIN_FILE, WORKOS_WP_VERSION);
$updater->register_hooks();
