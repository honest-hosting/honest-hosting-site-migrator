<?php
/**
 * Formatting utilities.
 *
 * @package HonestHosting\SiteMigrator\Util
 */

namespace HonestHosting\SiteMigrator\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Shared formatting helpers for human-readable display.
 */
class FormatHelper {

	/**
	 * Format a byte value as a human-readable string with 2 decimal places.
	 *
	 * @param int $bytes Size in bytes.
	 * @return string Formatted string, e.g. "1.23 GB", "659.21 MB", "4.50 KB".
	 */
	public static function format_bytes( int $bytes ): string {
		if ( $bytes < 1024 ) {
			return $bytes . ' B';
		}

		if ( $bytes < 1048576 ) {
			return number_format( $bytes / 1024, 2 ) . ' KB';
		}

		if ( $bytes < 1073741824 ) {
			return number_format( $bytes / 1048576, 2 ) . ' MB';
		}

		return number_format( $bytes / 1073741824, 2 ) . ' GB';
	}
}
