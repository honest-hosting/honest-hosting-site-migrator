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
	 * Fallback chunk size in bytes (2 MB) for constrained environments.
	 *
	 * @var int
	 */
	public const FALLBACK_BYTES = 2 * 1024 * 1024;

	/**
	 * Memory threshold below which we use the fallback chunk size (100 MB).
	 *
	 * @var int
	 */
	private const MEMORY_THRESHOLD = 100 * 1024 * 1024;

	/**
	 * Percentage of memory limit to use as default chunk size.
	 *
	 * @var float
	 */
	private const MEMORY_RATIO = 0.20;

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
	 * If the user has not configured a chunk size, the default is computed
	 * from the PHP memory limit: 20% of memory_limit rounded to the nearest
	 * MB when memory_limit >= 100 MB, otherwise 2 MB.
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

		return self::get_default_size();
	}

	/**
	 * Compute the default chunk size based on the PHP memory limit.
	 *
	 * If memory_limit >= 100 MB: 20% of memory_limit, rounded to nearest MB.
	 * If memory_limit < 100 MB or unlimited (-1): 2 MB fallback.
	 *
	 * @return int Default chunk size in bytes.
	 */
	public static function get_default_size(): int {
		$memory_limit = self::get_memory_limit_bytes();

		if ( $memory_limit < self::MEMORY_THRESHOLD ) {
			return self::FALLBACK_BYTES;
		}

		$chunk_bytes = (int) ( $memory_limit * self::MEMORY_RATIO );

		// Round to nearest MB.
		$mb          = 1024 * 1024;
		$chunk_bytes = (int) ( round( $chunk_bytes / $mb ) * $mb );

		return max( self::MIN_BYTES, min( self::MAX_BYTES, $chunk_bytes ) );
	}

	/**
	 * Get the PHP memory_limit in bytes.
	 *
	 * @return int Memory limit in bytes. Returns 0 for unlimited (-1).
	 */
	private static function get_memory_limit_bytes(): int {
		$limit = (string) ini_get( 'memory_limit' );
		if ( '' === $limit || '-1' === $limit ) {
			return 0;
		}

		return wp_convert_hr_to_bytes( $limit );
	}
}
