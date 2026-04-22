<?php
/**
 * Destination capacity preflight check.
 *
 * @package HonestHosting\SiteMigrator\Preflight\Checks
 */

namespace HonestHosting\SiteMigrator\Preflight\Checks;

defined( 'ABSPATH' ) || exit;

use HonestHosting\SiteMigrator\Api\HonestHostingClient;
use HonestHosting\SiteMigrator\Preflight\PreflightCheckInterface;
use HonestHosting\SiteMigrator\Preflight\PreflightResult;
use HonestHosting\SiteMigrator\Util\FormatHelper;
use HonestHosting\SiteMigrator\Util\PathHelper;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Validates destination has sufficient capacity for source data.
 */
class DestinationCapacityCheck implements PreflightCheckInterface {

	/**
	 * API client.
	 *
	 * @var HonestHostingClient
	 */
	private HonestHostingClient $client;

	/**
	 * Source estimates override for testing.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $estimates;

	/**
	 * Constructor.
	 *
	 * @param HonestHostingClient       $client    API client.
	 * @param array<string, mixed>|null $estimates Source estimates override.
	 */
	public function __construct( HonestHostingClient $client, ?array $estimates = null ) {
		$this->client    = $client;
		$this->estimates = $estimates;
	}

	/**
	 * Run the destination capacity check.
	 *
	 * @param PreflightResult $result Result to populate.
	 * @return void
	 */
	public function run( PreflightResult $result ): void {
		$estimates = $this->estimates ?? $this->gather_estimates();

		// Build SiteImportRequest body for validation.
		$request_body = array(
			'file_bytes'        => $estimates['file_bytes'],
			'file_count'        => $estimates['file_count'],
			'db_bytes'          => $estimates['db_bytes'],
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'multisite'         => is_multisite(),
		);

		$response = $this->client->validate_import( $request_body );

		if ( is_wp_error( $response ) ) {
			// A 400 response indicates validation errors.
			$error_data = $response->get_error_data();
			$api_errors = array();

			if ( is_array( $error_data ) && ! empty( $error_data['response'] ) ) {
				$api_response = $error_data['response'];
				$api_errors   = $api_response['errors'] ?? array();
			}

			if ( ! empty( $api_errors ) ) {
				foreach ( $api_errors as $error ) {
					$message = is_string( $error ) ? $error : __( 'Capacity insufficient.', 'honest-hosting-site-migrator' );
					$result->add_error( 'capacity_exceeded', $message, 'destination' );
				}
				return;
			}

			$result->add_error(
				'capacity_check_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Could not validate capacity: %s', 'honest-hosting-site-migrator' ),
					$response->get_error_message()
				),
				'destination'
			);
			return;
		}

		// 200 response means validation passed.
		$result->add_info( 'capacity_ok', __( 'Sufficient capacity for this migration.', 'honest-hosting-site-migrator' ), 'destination' );
	}

	/**
	 * Gather source size estimates from the local environment.
	 *
	 * @return array<string, mixed>
	 */
	private function gather_estimates(): array {
		global $wpdb;

		// File size estimate.
		$file_bytes = 0;
		$file_count = 0;
		$wp_content = PathHelper::wp_content_dir();

		if ( is_dir( $wp_content ) ) {
			try {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $wp_content, RecursiveDirectoryIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::LEAVES_ONLY
				);

				foreach ( $iterator as $file ) {
					if ( $file->isFile() ) {
						$file_bytes += $file->getSize();
						++$file_count;
					}
				}
			} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Best effort — continue with partial estimates.
				unset( $e );
			}
		}

		// DB size estimate.
		$db_bytes = 0;
		$prefix   = $wpdb->prefix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$tables = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );

		if ( ! empty( $tables ) ) {
			foreach ( $tables as $table ) {
				if ( str_starts_with( $table['Name'] ?? '', $prefix ) ) {
					$db_bytes += (int) ( $table['Data_length'] ?? 0 ) + (int) ( $table['Index_length'] ?? 0 );
				}
			}
		}

		return array(
			'file_bytes' => $file_bytes,
			'file_count' => $file_count,
			'db_bytes'   => $db_bytes,
		);
	}
}
