<?php
/**
 * Plugin Name: HonestHosting Site Migrator
 * Plugin URI:  https://honesthosting.io
 * Description: Migrate WordPress sites to HonestHosting via streamed, chunked, resumable exports.
 * Version: 0.0.1
 * Author:      HonestHosting
 * Author URI:  https://honesthosting.io
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: honest-hosting-site-migrator
 * Domain Path: /languages
 * Requires at least: 6.7
 * Requires PHP: 8.0
 *
 * @package HonestHosting\SiteMigrator
 */

defined( 'ABSPATH' ) || exit;

define( 'HH_MIGRATOR_VERSION', '0.0.1' );
define( 'HH_MIGRATOR_FILE', __FILE__ );
define( 'HH_MIGRATOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'HH_MIGRATOR_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoloader.
 */
if ( file_exists( HH_MIGRATOR_PATH . 'vendor/autoload.php' ) ) {
	require_once HH_MIGRATOR_PATH . 'vendor/autoload.php';
}

/**
 * Boot the plugin.
 *
 * @return \HonestHosting\SiteMigrator\Plugin
 */
function hh_migrator() {
	return \HonestHosting\SiteMigrator\Plugin::get_instance();
}

hh_migrator();
