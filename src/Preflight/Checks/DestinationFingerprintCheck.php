<?php
/**
 * Destination fingerprint preflight check.
 *
 * @package HonestHosting\SiteMigrator\Preflight\Checks
 */

namespace HonestHosting\SiteMigrator\Preflight\Checks;

defined( 'ABSPATH' ) || exit;

use HonestHosting\SiteMigrator\Api\HonestHostingClient;
use HonestHosting\SiteMigrator\Preflight\PreflightCheckInterface;
use HonestHosting\SiteMigrator\Preflight\PreflightResult;

/**
 * Reports destination site metadata as INFO entries during preflight.
 */
class DestinationFingerprintCheck implements PreflightCheckInterface {

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
	 * Run the destination fingerprint check.
	 *
	 * @param PreflightResult $result Result to populate.
	 * @return void
	 */
	public function run( PreflightResult $result ): void {
		$site = $this->client->get_site();

		if ( is_wp_error( $site ) ) {
			$result->add_warning(
				'dest_fingerprint_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Could not retrieve site info: %s', 'honest-hosting-site-migrator' ),
					$site->get_error_message()
				),
				'destination'
			);
			return;
		}

		$name = $site['name'] ?? '';
		if ( ! empty( $name ) ) {
			$result->add_info(
				'dest_site_name',
				sprintf(
					/* translators: %s: site name */
					__( 'Site name: %s', 'honest-hosting-site-migrator' ),
					$name
				),
				'destination'
			);
		}

		$url = $site['url'] ?? '';
		if ( ! empty( $url ) ) {
			$result->add_info(
				'dest_site_url',
				sprintf(
					/* translators: %s: site URL */
					__( 'Site URL: %s', 'honest-hosting-site-migrator' ),
					$url
				),
				'destination'
			);
		}

		$site_type = $site['site_type'] ?? '';
		if ( ! empty( $site_type ) ) {
			$result->add_info(
				'dest_site_type',
				sprintf(
					/* translators: %s: site type description */
					__( 'Site type: %s', 'honest-hosting-site-migrator' ),
					$site_type
				),
				'destination'
			);
		}

		$site_tier = $site['site_tier'] ?? '';
		if ( ! empty( $site_tier ) ) {
			$result->add_info(
				'dest_site_tier',
				sprintf(
					/* translators: %s: site tier name */
					__( 'Site tier: %s', 'honest-hosting-site-migrator' ),
					$site_tier
				),
				'destination'
			);
		}

		$storage_gb    = $site['storage_gb'] ?? 0;
		$storage_db_gb = $site['storage_database_gb'] ?? 0;
		if ( $storage_gb > 0 || $storage_db_gb > 0 ) {
			$result->add_info(
				'dest_storage',
				sprintf(
					/* translators: 1: file storage GB 2: database storage GB */
					__( 'Storage: %1$dGB (files), %2$dGB (database)', 'honest-hosting-site-migrator' ),
					$storage_gb,
					$storage_db_gb
				),
				'destination'
			);
		}

		$datacenter = $site['datacenter'] ?? array();
		$dc_name    = $datacenter['name'] ?? '';
		$dc_region  = $datacenter['region'] ?? '';
		if ( ! empty( $dc_name ) ) {
			$dc_label = ! empty( $dc_region ) ? "$dc_name — $dc_region" : $dc_name;
			$result->add_info(
				'dest_datacenter',
				sprintf(
					/* translators: %s: datacenter name and region */
					__( 'Datacenter: %s', 'honest-hosting-site-migrator' ),
					$dc_label
				),
				'destination'
			);
		}

		// Version compatibility checks.
		$this->check_version_compatibility( $result, $site_type );
	}

	/**
	 * Compare source and destination WordPress/PHP versions from the site type description.
	 *
	 * @param PreflightResult $result    Result to populate.
	 * @param string          $site_type Site type description (e.g. "WordPress v6.9.4, PHP v8.5").
	 * @return void
	 */
	private function check_version_compatibility( PreflightResult $result, string $site_type ): void {
		if ( empty( $site_type ) ) {
			return;
		}

		// Parse destination WordPress version from "WordPress v6.9.4, PHP v8.5".
		if ( preg_match( '/WordPress\s+v?([\d]+\.[\d]+)/i', $site_type, $wp_matches ) ) {
			$dest_wp    = $wp_matches[1];
			$source_wp  = get_bloginfo( 'version' );
			$source_maj = implode( '.', array_slice( explode( '.', $source_wp ), 0, 2 ) );

			if ( $dest_wp !== $source_maj ) {
				$result->add_warning(
					'wp_version_mismatch',
					sprintf(
						/* translators: 1: source WP version 2: destination WP major.minor */
						__( 'WordPress version mismatch: source is %1$s, destination is %2$s. Some plugins or themes may not be compatible.', 'honest-hosting-site-migrator' ),
						$source_wp,
						$dest_wp
					),
					'destination'
				);
			}
		}

		// Parse destination PHP version from "WordPress v6.9.4, PHP v8.5".
		if ( preg_match( '/PHP\s+v?([\d]+\.[\d]+)/i', $site_type, $php_matches ) ) {
			$dest_php   = $php_matches[1];
			$source_php = PHP_VERSION;
			$source_maj = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

			if ( $dest_php !== $source_maj ) {
				$result->add_warning(
					'php_version_mismatch',
					sprintf(
						/* translators: 1: source PHP version 2: destination PHP major.minor */
						__( 'PHP version mismatch: source is %1$s, destination is %2$s. Some plugins or themes may not be compatible.', 'honest-hosting-site-migrator' ),
						$source_php,
						$dest_php
					),
					'destination'
				);
			}
		}
	}
}
