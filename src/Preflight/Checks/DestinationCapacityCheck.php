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
		$site_id = (string) get_option( 'hh_migrator_destination_site_id', '' );

		if ( empty( $site_id ) ) {
			$result->add_info( 'capacity_skipped', __( 'No destination site selected. Capacity check skipped.', 'honest-hosting-site-migrator' ) );
			return;
		}

		$estimates = $this->estimates ?? $this->gather_estimates();

		$response = $this->client->validate_import( $site_id, $estimates );

		if ( is_wp_error( $response ) ) {
			$result->add_warning(
				'capacity_check_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Could not validate destination capacity: %s', 'honest-hosting-site-migrator' ),
					$response->get_error_message()
				)
			);
			return;
		}

		// Backend returns validation result.
		$ok     = $response['ok'] ?? $response['valid'] ?? true;
		$errors = $response['errors'] ?? array();

		if ( $ok && empty( $errors ) ) {
			$result->add_info( 'capacity_ok', __( 'Destination has sufficient capacity for this migration.', 'honest-hosting-site-migrator' ) );
			return;
		}

		foreach ( $errors as $error ) {
			$message = is_string( $error ) ? $error : ( $error['message'] ?? __( 'Destination capacity insufficient.', 'honest-hosting-site-migrator' ) );
			$result->add_error( 'capacity_exceeded', $message );
		}
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
		$wp_content = WP_CONTENT_DIR;

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
