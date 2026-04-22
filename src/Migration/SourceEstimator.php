<?php
/**
 * Gathers source site size estimates for migration requests.
 *
 * @package HonestHosting\SiteMigrator\Migration
 */

namespace HonestHosting\SiteMigrator\Migration;

defined( 'ABSPATH' ) || exit;

use HonestHosting\SiteMigrator\Util\PathHelper;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Estimates file and database sizes for the source WordPress site.
 */
class SourceEstimator {

	/**
	 * Gather source size estimates for the SiteImportRequest.
	 *
	 * @return array{file_bytes: int, file_count: int, db_bytes: int}
	 */
	public function gather(): array {
		$file_stats = $this->scan_wp_content();

		return array(
			'file_bytes' => $file_stats['bytes'],
			'file_count' => $file_stats['count'],
			'db_bytes'   => $this->estimate_db_bytes(),
		);
	}

	/**
	 * Estimate total file size and count under wp-content.
	 *
	 * @return array{bytes: int, count: int}
	 */
	private function scan_wp_content(): array {
		$bytes      = 0;
		$count      = 0;
		$wp_content = PathHelper::wp_content_dir();

		if ( ! is_dir( $wp_content ) ) {
			return array(
				'bytes' => 0,
				'count' => 0,
			);
		}

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $wp_content, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$bytes += $file->getSize();
					++$count;
				}
			}
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Best effort — continue with partial estimates.
			unset( $e );
		}

		return array(
			'bytes' => $bytes,
			'count' => $count,
		);
	}

	/**
	 * Estimate total database size for tables matching the WP prefix.
	 *
	 * @return int
	 */
	private function estimate_db_bytes(): int {
		global $wpdb;

		$prefix = $wpdb->prefix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$tables = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );

		if ( empty( $tables ) ) {
			return 0;
		}

		$bytes = 0;
		foreach ( $tables as $table ) {
			if ( str_starts_with( $table['Name'] ?? '', $prefix ) ) {
				$bytes += (int) ( $table['Data_length'] ?? 0 ) + (int) ( $table['Index_length'] ?? 0 );
			}
		}

		return $bytes;
	}
}
