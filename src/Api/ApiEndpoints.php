<?php
/**
 * Central endpoint URL registry.
 *
 * All backend API paths are defined here — no scattered string literals.
 *
 * @package HonestHosting\SiteMigrator\Api
 */

namespace HonestHosting\SiteMigrator\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Central registry of HonestHosting backend API endpoints.
 */
class ApiEndpoints {

	/**
	 * Default production base URL.
	 *
	 * @var string
	 */
	public const DEFAULT_BASE_URL = 'https://api.honesthosting.io';

	/**
	 * Filter for eligible destination sites (import key scoped).
	 *
	 * @var string
	 */
	public const FILTER_SITES = '/v1/siteImport/site/filter';

	/**
	 * Create a new import session for a destination site.
	 * Requires sprintf with siteId.
	 *
	 * @var string
	 */
	public const CREATE_IMPORT = '/v1/siteImport/site/%s';

	/**
	 * Validate a pending import (preflight capacity, destination readiness).
	 * Requires sprintf with siteId.
	 *
	 * @var string
	 */
	public const VALIDATE_IMPORT = '/v1/siteImport/site/%s/validate';

	/**
	 * List available import sessions.
	 *
	 * @var string
	 */
	public const FILTER_IMPORTS = '/v1/siteImport/filter';

	/**
	 * Get import session info (status, progress, errors).
	 * Requires sprintf with importId.
	 *
	 * @var string
	 */
	public const GET_IMPORT = '/v1/siteImport/%s';

	/**
	 * Obtain presigned S3 URL for chunked upload.
	 * Requires sprintf with importId.
	 *
	 * @var string
	 */
	public const GET_UPLOAD_URL = '/v1/siteImport/%s/uploadUrl';

	/**
	 * Check if destination is ready for import.
	 * Requires sprintf with importId.
	 *
	 * @var string
	 */
	public const CHECK_READY = '/v1/siteImport/%s/ready';

	/**
	 * Get the effective API base URL.
	 *
	 * Priority: constant > WP option > default.
	 *
	 * @return string
	 */
	public static function get_base_url(): string {
		if ( defined( 'HH_MIGRATOR_API_BASE_URL' ) ) {
			return (string) HH_MIGRATOR_API_BASE_URL;
		}

		return (string) get_option( 'hh_migrator_api_base_url', self::DEFAULT_BASE_URL );
	}

	/**
	 * Build a full URL for an endpoint.
	 *
	 * @param string $endpoint Endpoint constant (may contain %s placeholders).
	 * @param string ...$params Values to substitute into placeholders.
	 * @return string Full URL.
	 */
	public static function url( string $endpoint, string ...$params ): string {
		$path = empty( $params ) ? $endpoint : sprintf( $endpoint, ...$params );

		return rtrim( self::get_base_url(), '/' ) . $path;
	}

	/**
	 * Validate that a base URL is acceptable (HTTPS required).
	 *
	 * @param string $url The URL to validate.
	 * @return bool True if valid HTTPS URL.
	 */
	public static function is_valid_base_url( string $url ): bool {
		$parsed = wp_parse_url( $url );
		if ( ! $parsed || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return false;
		}

		return 'https' === strtolower( $parsed['scheme'] );
	}
}
