<?php
/**
 * Database analysis preflight check.
 *
 * @package HonestHosting\SiteMigrator\Preflight\Checks
 */

namespace HonestHosting\SiteMigrator\Preflight\Checks;

defined( 'ABSPATH' ) || exit;

use HonestHosting\SiteMigrator\Preflight\PreflightCheckInterface;
use HonestHosting\SiteMigrator\Preflight\PreflightResult;

/**
 * Estimates database size and reports engine types.
 */
class DatabaseAnalysisCheck implements PreflightCheckInterface {

	/**
	 * Run the database analysis check.
	 *
	 * @param PreflightResult $result Result to populate.
	 * @return void
	 */
	public function run( PreflightResult $result ): void {
		global $wpdb;

		$prefix     = $wpdb->prefix;
		$total_size = 0;
		$engines    = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$tables = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );

		if ( empty( $tables ) ) {
			$result->add_warning( 'db_no_tables', __( 'No tables found in database.', 'honest-hosting-site-migrator' ) );
			return;
		}

		$site_tables = array();
		foreach ( $tables as $table ) {
			$name = $table['Name'] ?? '';

			// For multisite, only include tables matching the current site prefix.
			if ( ! str_starts_with( $name, $prefix ) ) {
				continue;
			}

			$data_length  = (int) ( $table['Data_length'] ?? 0 );
			$index_length = (int) ( $table['Index_length'] ?? 0 );
			$table_size   = $data_length + $index_length;
			$total_size  += $table_size;

			$engine = $table['Engine'] ?? 'Unknown';
			if ( ! isset( $engines[ $engine ] ) ) {
				$engines[ $engine ] = 0;
			}
			++$engines[ $engine ];

			$site_tables[] = array(
				'name'   => $name,
				'size'   => $table_size,
				'engine' => $engine,
			);
		}

		$result->add_info(
			'db_total_size',
			sprintf(
				/* translators: 1: formatted size 2: table count */
				__( 'Database size: %1$s (%2$d tables)', 'honest-hosting-site-migrator' ),
				size_format( $total_size ),
				count( $site_tables )
			)
		);

		// Report engine breakdown.
		foreach ( $engines as $engine => $count ) {
			$result->add_info(
				'db_engine_' . strtolower( $engine ),
				sprintf(
					/* translators: 1: engine name 2: table count */
					__( 'DB engine %1$s: %2$d tables', 'honest-hosting-site-migrator' ),
					$engine,
					$count
				)
			);
		}

		// Warn on non-InnoDB engines.
		$unsupported_engines = array_diff_key( $engines, array( 'InnoDB' => true ) );
		foreach ( $unsupported_engines as $engine => $count ) {
			$result->add_warning(
				'db_unsupported_engine',
				sprintf(
					/* translators: 1: engine name 2: table count */
					__( '%2$d tables use %1$s engine, which is unsupported. Import may still be attempted but results may vary.', 'honest-hosting-site-migrator' ),
					$engine,
					$count
				)
			);
		}
	}
}
