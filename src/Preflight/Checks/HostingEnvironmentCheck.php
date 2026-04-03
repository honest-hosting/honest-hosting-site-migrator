<?php
/**
 * Hosting environment preflight check.
 *
 * @package HonestHosting\SiteMigrator\Preflight\Checks
 */

namespace HonestHosting\SiteMigrator\Preflight\Checks;

defined( 'ABSPATH' ) || exit;

use HonestHosting\SiteMigrator\Preflight\PreflightCheckInterface;
use HonestHosting\SiteMigrator\Preflight\PreflightResult;

/**
 * Detects hosting environment limitations that may affect migration.
 */
class HostingEnvironmentCheck implements PreflightCheckInterface {

	/**
	 * Minimum recommended memory limit in bytes (64 MB).
	 *
	 * @var int
	 */
	private const MIN_MEMORY = 64 * 1024 * 1024;

	/**
	 * Minimum recommended execution time in seconds.
	 *
	 * @var int
	 */
	private const MIN_EXECUTION_TIME = 30;

	/**
	 * Run the hosting environment check.
	 *
	 * @param PreflightResult $result Result to populate.
	 * @return void
	 */
	public function run( PreflightResult $result ): void {
		$this->check_curl( $result );
		$this->check_open_basedir( $result );
		$this->check_memory_limit( $result );
		$this->check_execution_time( $result );
		$this->check_upload_limits( $result );
		$this->check_compression( $result );
		$this->check_wp_cron( $result );
	}

	/**
	 * Check if curl extension is available.
	 *
	 * @param PreflightResult $result Result to populate.
	 * @return void
	 */
	private function check_curl( PreflightResult $result ): void {
		if ( ! extension_loaded( 'curl' ) ) {
			$result->add_warning( 'curl_missing', __( 'PHP curl extension is not loaded. HTTP requests may be unreliable.', 'honest-hosting-site-migrator' ) );
		}
	}

	/**
	 * Check open_basedir restrictions.
	 *
	 * @param PreflightResult $result Result to populate.
	 * @return void
	 */
	private function check_open_basedir( PreflightResult $result ): void {
		$open_basedir = ini_get( 'open_basedir' );
		if ( ! empty( $open_basedir ) ) {
			$result->add_warning(
				'open_basedir_set',
				sprintf(
					/* translators: %s: open_basedir value */
					__( 'open_basedir is set to: %s. This may restrict file access.', 'honest-hosting-site-migrator' ),
					$open_basedir
				)
			);
		}
	}

	/**
	 * Check PHP memory limit.
	 *
	 * @param PreflightResult $result Result to populate.
	 * @return void
	 */
	private function check_memory_limit( PreflightResult $result ): void {
		$limit = $this->parse_ini_size( (string) ini_get( 'memory_limit' ) );

		$result->add_info(
			'memory_limit',
			sprintf(
				/* translators: %s: memory limit value */
				__( 'PHP memory limit: %s', 'honest-hosting-site-migrator' ),
				ini_get( 'memory_limit' )
			)
		);

		// -1 means unlimited.
		if ( $limit > 0 && $limit < self::MIN_MEMORY ) {
			$result->add_warning(
				'low_memory',
				sprintf(
					/* translators: %s: memory limit value */
					__( 'PHP memory limit (%s) is below recommended 64M. Migration may fail on large files.', 'honest-hosting-site-migrator' ),
					ini_get( 'memory_limit' )
				)
			);
		}
	}

	/**
	 * Check PHP max execution time.
	 *
	 * @param PreflightResult $result Result to populate.
	 * @return void
	 */
	private function check_execution_time( PreflightResult $result ): void {
		$max_time = (int) ini_get( 'max_execution_time' );

		$result->add_info(
			'max_execution_time',
			sprintf(
				/* translators: %d: seconds */
				__( 'PHP max execution time: %ds', 'honest-hosting-site-migrator' ),
				$max_time
			)
		);

		// 0 means unlimited.
		if ( $max_time > 0 && $max_time < self::MIN_EXECUTION_TIME ) {
			$result->add_warning(
				'low_execution_time',
				sprintf(
					/* translators: %d: seconds */
					__( 'PHP max execution time (%ds) is below recommended 30s. Migration will rely heavily on resume support.', 'honest-hosting-site-migrator' ),
					$max_time
				)
			);
		}
	}

	/**
	 * Check upload and request size limits.
	 *
	 * @param PreflightResult $result Result to populate.
	 * @return void
	 */
	private function check_upload_limits( PreflightResult $result ): void {
		$result->add_info(
			'upload_max_filesize',
			sprintf(
				/* translators: %s: size value */
				__( 'upload_max_filesize: %s', 'honest-hosting-site-migrator' ),
				ini_get( 'upload_max_filesize' )
			)
		);

		$result->add_info(
			'post_max_size',
			sprintf(
				/* translators: %s: size value */
				__( 'post_max_size: %s', 'honest-hosting-site-migrator' ),
				ini_get( 'post_max_size' )
			)
		);
	}

	/**
	 * Check compression support.
	 *
	 * @param PreflightResult $result Result to populate.
	 * @return void
	 */
	private function check_compression( PreflightResult $result ): void {
		if ( function_exists( 'gzencode' ) ) {
			$result->add_info( 'compression_available', __( 'Gzip compression is available. Chunks will be compressed.', 'honest-hosting-site-migrator' ) );
		} else {
			$result->add_warning( 'compression_unavailable', __( 'Gzip compression is unavailable. Chunks will be uploaded uncompressed, which may be slower.', 'honest-hosting-site-migrator' ) );
		}
	}

	/**
	 * Check WP-Cron availability.
	 *
	 * @param PreflightResult $result Result to populate.
	 * @return void
	 */
	private function check_wp_cron( PreflightResult $result ): void {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$result->add_info( 'wp_cron_disabled', __( 'WP-Cron is disabled. Scheduled incremental sync will not be available.', 'honest-hosting-site-migrator' ) );
		} elseif ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) {
			$result->add_warning( 'wp_cron_alternate', __( 'ALTERNATE_WP_CRON is enabled. Scheduled sync may be unreliable.', 'honest-hosting-site-migrator' ) );
		}
	}

	/**
	 * Parse a PHP ini size string to bytes.
	 *
	 * @param string $value PHP ini value like "128M", "1G", etc.
	 * @return int Bytes. Returns -1 for unlimited.
	 */
	private function parse_ini_size( string $value ): int {
		$value = trim( $value );

		if ( '-1' === $value ) {
			return -1;
		}

		$last = strtolower( substr( $value, -1 ) );
		$num  = (int) $value;

		switch ( $last ) {
			case 'g':
				$num *= 1024 * 1024 * 1024;
				break;
			case 'm':
				$num *= 1024 * 1024;
				break;
			case 'k':
				$num *= 1024;
				break;
		}

		return $num;
	}
}
