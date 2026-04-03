<?php
/**
 * Chunk size parsing and validation.
 *
 * @package HonestHosting\SiteMigrator\Util
 */

namespace HonestHosting\SiteMigrator\Util;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Validates and normalizes human-readable chunk size strings.
 *
 * Accepts: "2MB", "50Mb", "100mb". Range: 2 MB – 200 MB.
 */
class ChunkSizeValidator {

	/**
	 * Minimum chunk size in bytes (2 MB).
	 *
	 * @var int
	 */
	public const MIN_BYTES = 2 * 1024 * 1024;

	/**
	 * Maximum chunk size in bytes (200 MB).
	 *
	 * @var int
	 */
	public const MAX_BYTES = 200 * 1024 * 1024;

	/**
	 * Default chunk size in bytes (2 MB).
	 *
	 * @var int
	 */
	public const DEFAULT_BYTES = 2 * 1024 * 1024;

	/**
	 * Parse a human-readable chunk size string into bytes.
	 *
	 * @param string $input User input, e.g. "2MB", "50Mb", "100mb".
	 * @return int|WP_Error Bytes on success, WP_Error on invalid input.
	 */
	public static function parse( string $input ) {
		$input = trim( $input );

		if ( ! preg_match( '/^(\d+)\s*(MB|Mb|mb)$/', $input, $matches ) ) {
			return new WP_Error(
				'hh_migrator_invalid_chunk_size',
				__( 'Chunk size must be a number followed by MB (e.g., "2MB", "50Mb", "100mb").', 'honest-hosting-site-migrator' )
			);
		}

		$megabytes = (int) $matches[1];
		$bytes     = $megabytes * 1024 * 1024;

		if ( $bytes < self::MIN_BYTES ) {
			return new WP_Error(
				'hh_migrator_chunk_size_too_small',
				sprintf(
					/* translators: %d: minimum chunk size in megabytes */
					__( 'Chunk size must be at least %d MB.', 'honest-hosting-site-migrator' ),
					self::MIN_BYTES / ( 1024 * 1024 )
				)
			);
		}

		if ( $bytes > self::MAX_BYTES ) {
			return new WP_Error(
				'hh_migrator_chunk_size_too_large',
				sprintf(
					/* translators: %d: maximum chunk size in megabytes */
					__( 'Chunk size must be at most %d MB.', 'honest-hosting-site-migrator' ),
					self::MAX_BYTES / ( 1024 * 1024 )
				)
			);
		}

		return $bytes;
	}

	/**
	 * Format a byte value as a human-readable string.
	 *
	 * @param int $bytes Size in bytes.
	 * @return string Formatted string, e.g. "2 MB".
	 */
	public static function format( int $bytes ): string {
		$megabytes = $bytes / ( 1024 * 1024 );

		return sprintf( '%d MB', (int) $megabytes );
	}

	/**
	 * Get the current configured chunk size in bytes.
	 *
	 * @return int Chunk size in bytes.
	 */
	public static function get_configured_size(): int {
		$stored = get_option( 'hh_migrator_chunk_size', '' );

		if ( ! empty( $stored ) ) {
			$parsed = self::parse( (string) $stored );
			if ( ! is_wp_error( $parsed ) ) {
				return $parsed;
			}
		}

		return self::DEFAULT_BYTES;
	}
}
