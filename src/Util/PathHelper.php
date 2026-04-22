<?php
/**
 * Filesystem path helpers.
 *
 * @package HonestHosting\SiteMigrator\Util
 */

namespace HonestHosting\SiteMigrator\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Centralized access to WordPress filesystem paths used by the migrator.
 */
class PathHelper {

	/**
	 * Absolute path to the site's wp-content directory, normalized for
	 * cross-platform use (forward slashes regardless of OS).
	 *
	 * Honors any WP_CONTENT_DIR override set in wp-config.php — this is the
	 * canonical WordPress mechanism for relocating wp-content. WordPress
	 * provides no public function that returns this path (plugin_dir_path()
	 * returns the plugin's own dir, wp_upload_dir() returns only the uploads
	 * subdirectory), so the constant is used here and wrapped in
	 * wp_normalize_path() for portability.
	 *
	 * @return string
	 */
	public static function wp_content_dir(): string {
		return wp_normalize_path( WP_CONTENT_DIR );
	}
}
