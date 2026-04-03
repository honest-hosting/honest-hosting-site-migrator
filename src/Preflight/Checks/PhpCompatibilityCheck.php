<?php
/**
 * PHP compatibility preflight check.
 *
 * @package HonestHosting\SiteMigrator\Preflight\Checks
 */

namespace HonestHosting\SiteMigrator\Preflight\Checks;

defined( 'ABSPATH' ) || exit;

use HonestHosting\SiteMigrator\Api\HonestHostingClient;
use HonestHosting\SiteMigrator\Preflight\PreflightCheckInterface;
use HonestHosting\SiteMigrator\Preflight\PreflightResult;

/**
 * Reports PHP version and checks compatibility with destination.
 */
class PhpCompatibilityCheck implements PreflightCheckInterface {

	/**
	 * API client.
	 *
	 * @var HonestHostingClient
	 */
	private HonestHostingClient $client;

	/**
	 * Constructor.
	 *
	 * @param HonestHostingClient $client API client.
	 */
	public function __construct( HonestHostingClient $client ) {
		$this->client = $client;
	}

	/**
	 * Run the PHP compatibility check.
	 *
	 * @param PreflightResult $result Result to populate.
	 * @return void
	 */
	public function run( PreflightResult $result ): void {
		$source_version = PHP_VERSION;
		$source_major   = PHP_MAJOR_VERSION;

		$result->add_info(
			'php_source_version',
			sprintf(
				/* translators: %s: PHP version */
				__( 'Source PHP version: %s', 'honest-hosting-site-migrator' ),
				$source_version
			)
		);

		// Try to get destination PHP version from the import session info.
		$import_id = $this->get_active_import_id();
		if ( empty( $import_id ) ) {
			return;
		}

		$import_info = $this->client->get_import( $import_id );
		if ( is_wp_error( $import_info ) ) {
			return;
		}

		$dest_version = $import_info['destination_php_version'] ?? '';
		if ( empty( $dest_version ) ) {
			return;
		}

		$result->add_info(
			'php_dest_version',
			sprintf(
				/* translators: %s: PHP version */
				__( 'Destination PHP version: %s', 'honest-hosting-site-migrator' ),
				$dest_version
			)
		);

		// Warn on major version mismatch.
		$dest_major = (int) explode( '.', $dest_version )[0];
		if ( $source_major !== $dest_major ) {
			$result->add_warning(
				'php_version_mismatch',
				sprintf(
					/* translators: 1: source PHP version 2: destination PHP version */
					__( 'PHP major version mismatch: source is %1$s, destination is %2$s. Some plugins or themes may not be compatible.', 'honest-hosting-site-migrator' ),
					$source_version,
					$dest_version
				)
			);
		}
	}

	/**
	 * Get the active import ID if one exists.
	 *
	 * @return string
	 */
	private function get_active_import_id(): string {
		$session_dir = $this->get_sessions_dir();
		if ( ! is_dir( $session_dir ) ) {
			return '';
		}

		$files = glob( $session_dir . '/*.json' );
		if ( empty( $files ) ) {
			return '';
		}

		// Use the most recently modified session.
		usort( $files, fn( $a, $b ) => filemtime( $b ) - filemtime( $a ) );

		return basename( $files[0], '.json' );
	}

	/**
	 * Get the sessions directory path.
	 *
	 * @return string
	 */
	private function get_sessions_dir(): string {
		$upload_dir = wp_upload_dir();
		return $upload_dir['basedir'] . '/hh-migrator/sessions';
	}
}
