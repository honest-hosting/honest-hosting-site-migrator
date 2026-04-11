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
 * Accepts: "5MB", "10Mb", "20mb". Range: 5 MB – 20 MB.
 */
class ChunkSizeValidator {

	/**
	 * Minimum chunk size in bytes (5 MB).
	 *
	 * @var int
	 */
	public const MIN_BYTES = 5 * 1024 * 1024;

	/**
	 * Maximum chunk size in bytes (20 MB).
	 *
	 * @var int
	 */
	public const MAX_BYTES = 20 * 1024 * 1024;

	/**
	 * Default chunk size in bytes (10 MB).
	 *
	 * @var int
	 */
	public const DEFAULT_BYTES = 10 * 1024 * 1024;

	/**
	 * Fallback chunk size in bytes (5 MB) for constrained environments.
	 *
	 * @var int
	 */
	public const FALLBACK_BYTES = 5 * 1024 * 1024;

	/**
	 * Memory threshold below which we use the fallback chunk size (64 MB).
	 *
	 * @var int
	 */
	private const MEMORY_THRESHOLD = 64 * 1024 * 1024;

	/**
	 * Parse a human-readable chunk size string into bytes.
	 *
	 * @param string $input User input, e.g. "5MB", "10Mb", "20mb".
	 * @return int|WP_Error Bytes on success, WP_Error on invalid input.
	 */
	public static function parse( string $input ) {
		$input = trim( $input );

		if ( ! preg_match( '/^(\d+)\s*(MB|Mb|mb)$/', $input, $matches ) ) {
			return new WP_Error(
				'hh_migrator_invalid_chunk_size',
				__( 'Chunk size must be a number followed by MB (e.g., "5MB", "10Mb", "20mb").', 'honest-hosting-site-migrator' )
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
	 * Returns the user-configured value if valid, otherwise the default
	 * (10 MB, or 5 MB on memory-constrained hosts).
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
	 * Compute the default chunk size.
	 *
	 * Returns 10 MB unless PHP memory_limit is below 64 MB, in which
	 * case 5 MB is used as a safe fallback.
	 *
	 * @return int Default chunk size in bytes.
	 */
	public static function get_default_size(): int {
		$memory_limit = self::get_memory_limit_bytes();

		if ( $memory_limit > 0 && $memory_limit < self::MEMORY_THRESHOLD ) {
			return self::FALLBACK_BYTES;
		}

		return self::DEFAULT_BYTES;
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
