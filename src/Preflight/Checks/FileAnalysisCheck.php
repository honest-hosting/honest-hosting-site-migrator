<?php
/**
 * File analysis preflight check.
 *
 * @package HonestHosting\SiteMigrator\Preflight\Checks
 */

namespace HonestHosting\SiteMigrator\Preflight\Checks;

defined( 'ABSPATH' ) || exit;

use HonestHosting\SiteMigrator\Preflight\PreflightCheckInterface;
use HonestHosting\SiteMigrator\Preflight\PreflightResult;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Estimates wp-content size and file count.
 */
class FileAnalysisCheck implements PreflightCheckInterface {

	/**
	 * Large file threshold in bytes (50 MB).
	 *
	 * @var int
	 */
	private const LARGE_FILE_THRESHOLD = 50 * 1024 * 1024;

	/**
	 * Wp-content path override for testing.
	 *
	 * @var string|null
	 */
	private ?string $wp_content_path;

	/**
	 * Constructor.
	 *
	 * @param string|null $wp_content_path Override path for testing.
	 */
	public function __construct( ?string $wp_content_path = null ) {
		$this->wp_content_path = $wp_content_path;
	}

	/**
	 * Run the file analysis check.
	 *
	 * @param PreflightResult $result Result to populate.
	 * @return void
	 */
	public function run( PreflightResult $result ): void {
		$wp_content = $this->wp_content_path ?? WP_CONTENT_DIR;

		if ( ! is_dir( $wp_content ) ) {
			$result->add_error( 'wp_content_missing', __( 'wp-content directory not found.', 'honest-hosting-site-migrator' ) );
			return;
		}

		$total_size  = 0;
		$file_count  = 0;
		$large_files = array();
		$state_dir   = $this->get_state_dir();

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $wp_content, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}

				$path = $file->getRealPath();

				// Exclude plugin state directory.
				if ( null !== $state_dir && str_starts_with( $path, $state_dir ) ) {
					continue;
				}

				$size        = $file->getSize();
				$total_size += $size;
				++$file_count;

				if ( $size > self::LARGE_FILE_THRESHOLD ) {
					$large_files[] = array(
						'path' => str_replace( $wp_content . '/', '', $path ),
						'size' => $size,
					);
				}
			}
		} catch ( \Exception $e ) {
			$result->add_warning(
				'file_scan_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Error scanning wp-content: %s', 'honest-hosting-site-migrator' ),
					$e->getMessage()
				)
			);
			return;
		}

		$result->add_info(
			'file_total_size',
			sprintf(
				/* translators: 1: formatted size 2: file count */
				__( 'wp-content total size: %1$s (%2$s files)', 'honest-hosting-site-migrator' ),
				size_format( $total_size ),
				number_format_i18n( $file_count )
			)
		);

		foreach ( $large_files as $large ) {
			$result->add_info(
				'large_file',
				sprintf(
					/* translators: 1: file path 2: formatted size */
					__( 'Large file: %1$s (%2$s)', 'honest-hosting-site-migrator' ),
					$large['path'],
					size_format( $large['size'] )
				)
			);
		}
	}

	/**
	 * Get the plugin state directory path.
	 *
	 * @return string|null
	 */
	private function get_state_dir(): ?string {
		$upload_dir = wp_upload_dir();
		$state_dir  = $upload_dir['basedir'] . '/hh-migrator';
		return is_dir( $state_dir ) ? $state_dir : null;
	}
}
