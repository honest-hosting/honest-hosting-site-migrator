<?php
/**
 * Database exporter — PHP-native SQL export with chunked streaming.
 *
 * @package HonestHosting\SiteMigrator\Export
 */

namespace HonestHosting\SiteMigrator\Export;

defined( 'ABSPATH' ) || exit;

use HonestHosting\SiteMigrator\Api\S3Uploader;
use HonestHosting\SiteMigrator\Migration\SessionManager;
use WP_Error;

/**
 * Exports database tables as SQL statements, streamed in chunks.
 *
 * Pure PHP — no shell access or mysqldump required.
 */
class DatabaseExporter {

	/**
	 * Default row batch size.
	 *
	 * @var int
	 */
	private const BATCH_SIZE = 1000;

	/**
	 * S3 uploader.
	 *
	 * @var S3Uploader
	 */
	private S3Uploader $uploader;

	/**
	 * Chunk encoder.
	 *
	 * @var ChunkEncoder
	 */
	private ChunkEncoder $encoder;

	/**
	 * Session manager.
	 *
	 * @var SessionManager
	 */
	private SessionManager $session_manager;

	/**
	 * Constructor.
	 *
	 * @param S3Uploader     $uploader        S3 uploader.
	 * @param ChunkEncoder   $encoder         Chunk encoder.
	 * @param SessionManager $session_manager Session manager.
	 */
	public function __construct( S3Uploader $uploader, ChunkEncoder $encoder, SessionManager $session_manager ) {
		$this->uploader        = $uploader;
		$this->encoder         = $encoder;
		$this->session_manager = $session_manager;
	}

	/**
	 * Get the list of tables to export.
	 *
	 * Scoped to the current site prefix (multisite-aware).
	 *
	 * @return array<int, array{name: string, engine: string, rows: int, size: int}>
	 */
	public function get_tables(): array {
		global $wpdb;

		$prefix = $wpdb->prefix;
		$tables = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );

		if ( empty( $results ) ) {
			return $tables;
		}

		foreach ( $results as $row ) {
			$name = $row['Name'] ?? '';
			if ( ! str_starts_with( $name, $prefix ) ) {
				continue;
			}

			$tables[] = array(
				'name'   => $name,
				'engine' => $row['Engine'] ?? 'Unknown',
				'rows'   => (int) ( $row['Rows'] ?? 0 ),
				'size'   => (int) ( $row['Data_length'] ?? 0 ) + (int) ( $row['Index_length'] ?? 0 ),
			);
		}

		return $tables;
	}

	/**
	 * Get table checksums for incremental detection.
	 *
	 * @param array<int, array{name: string}> $tables Table list.
	 * @return array<string, string> Table name => checksum.
	 */
	public function get_checksums( array $tables ): array {
		global $wpdb;

		$checksums   = array();
		$table_names = array_map( fn( $t ) => $t['name'], $tables );

		if ( empty( $table_names ) ) {
			return $checksums;
		}

		$table_list = implode( ', ', array_map( fn( $n ) => "`{$n}`", $table_names ) );

		// CHECKSUM TABLE requires literal table names — cannot use $wpdb->prepare() for identifiers.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results( "CHECKSUM TABLE {$table_list}", ARRAY_A );

		if ( ! empty( $results ) ) {
			foreach ( $results as $row ) {
				$name = $row['Table'] ?? '';
				// Strip database prefix from table name if present.
				if ( str_contains( $name, '.' ) ) {
					$name = explode( '.', $name )[1];
				}
				$checksums[ $name ] = (string) ( $row['Checksum'] ?? '' );
			}
		}

		return $checksums;
	}

	/**
	 * Export tables to S3.
	 *
	 * @param string        $import_id     Import session ULID.
	 * @param array<string> $skip_tables   Table names already completed.
	 * @param int           $chunk_size    Chunk size in bytes.
	 * @return true|WP_Error
	 */
	public function export( string $import_id, array $skip_tables, int $chunk_size ) {
		global $wpdb;

		$tables = $this->get_tables();

		$this->session_manager->update(
			$import_id,
			array(
				'status'      => 'exporting_db',
				'db_progress' => array(
					'total_tables'          => count( $tables ),
					'completed_tables'      => count( $skip_tables ),
					'current_table'         => null,
					'completed_table_names' => $skip_tables,
				),
			)
		);

		$chunk_index = $this->get_next_chunk_index( $import_id );

		foreach ( $tables as $table ) {
			$name = $table['name'];

			if ( in_array( $name, $skip_tables, true ) ) {
				continue;
			}

			$this->session_manager->update(
				$import_id,
				array(
					'db_progress' => array_merge(
						$this->session_manager->load( $import_id )['db_progress'] ?? array(),
						array( 'current_table' => $name )
					),
				) 
			);

			// Try transactional consistency for InnoDB.
			$is_innodb = 'InnoDB' === $table['engine'];
			if ( $is_innodb ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( 'START TRANSACTION' );
			}

			$result = $this->export_table( $import_id, $name, $chunk_size, $chunk_index );

			if ( $is_innodb ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( 'COMMIT' );
			}

			if ( is_wp_error( $result ) ) {
				$this->session_manager->update(
					$import_id,
					array(
						'last_error' => $result->get_error_message(),
					) 
				);
				return $result;
			}

			$chunk_index = $result;

			// Mark table complete.
			$state                       = $this->session_manager->load( $import_id );
			$dp                          = $state['db_progress'] ?? array();
			$dp['completed_tables']      = ( $dp['completed_tables'] ?? 0 ) + 1;
			$dp['completed_table_names'] = array_merge( $dp['completed_table_names'] ?? array(), array( $name ) );
			$dp['current_table']         = null;

			// Store checksum for future incremental diffs.
			$checksums          = $state['db_table_checksums'] ?? array();
			$current_checksums  = $this->get_checksums( array( array( 'name' => $name ) ) );
			$checksums[ $name ] = $current_checksums[ $name ] ?? '';

			$this->session_manager->update(
				$import_id,
				array(
					'db_progress'        => $dp,
					'db_table_checksums' => $checksums,
				) 
			);

			$this->session_manager->refresh_lock( $import_id );
		}

		return true;
	}

	/**
	 * Export a single table.
	 *
	 * @param string $import_id   Import session ULID.
	 * @param string $table_name  Table name.
	 * @param int    $chunk_size  Chunk size in bytes.
	 * @param int    $chunk_index Starting chunk index.
	 * @return int|WP_Error Next chunk index on success.
	 */
	private function export_table( string $import_id, string $table_name, int $chunk_size, int $chunk_index ) {
		global $wpdb;

		$buffer = '';

		// CREATE TABLE statement.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table_name}`", ARRAY_A );
		if ( $create ) {
			$buffer .= "DROP TABLE IF EXISTS `{$table_name}`;\n";
			$buffer .= ( $create['Create Table'] ?? '' ) . ";\n\n";
		}

		// Stream rows in batches.
		$offset = 0;

		while ( true ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table_name}` LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					self::BATCH_SIZE,
					$offset
				),
				ARRAY_A
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$values = array_map(
					function ( $value ) use ( $wpdb ) {
						if ( null === $value ) {
							return 'NULL';
						}
						return "'" . $wpdb->_real_escape( $value ) . "'";
					},
					array_values( $row )
				);

				$columns = array_map( fn( $col ) => "`{$col}`", array_keys( $row ) );

				$buffer .= sprintf(
					"INSERT INTO `%s` (%s) VALUES (%s);\n",
					$table_name,
					implode( ', ', $columns ),
					implode( ', ', $values )
				);

				// Flush buffer when it exceeds chunk size.
				if ( strlen( $buffer ) >= $chunk_size ) {
					$result = $this->flush_chunk( $import_id, $chunk_index, $buffer, $table_name );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					$buffer = '';
					++$chunk_index;
				}
			}

			$offset += self::BATCH_SIZE;
		}

		// Flush remaining buffer.
		if ( '' !== $buffer ) {
			$result = $this->flush_chunk( $import_id, $chunk_index, $buffer, $table_name );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			++$chunk_index;
		}

		return $chunk_index;
	}

	/**
	 * Encode and upload a buffer as a chunk.
	 *
	 * @param string $import_id   Import session ULID.
	 * @param int    $chunk_index Chunk index.
	 * @param string $data        SQL data.
	 * @param string $table_name  Source table name.
	 * @return true|WP_Error
	 */
	private function flush_chunk( string $import_id, int $chunk_index, string $data, string $table_name ) {
		$encoded = $this->encoder->encode( $data, $import_id, $chunk_index, $table_name, 'database' );

		$result = $this->uploader->upload_chunk(
			$import_id,
			$chunk_index,
			$encoded['data'],
			'application/sql',
			$encoded['compressed']
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Record chunk reference.
		$state  = $this->session_manager->load( $import_id );
		$refs   = $state['chunk_references'] ?? array();
		$refs[] = array_merge( $encoded['metadata'], $result );
		$this->session_manager->update( $import_id, array( 'chunk_references' => $refs ) );

		return true;
	}

	/**
	 * Get the next chunk index based on existing references.
	 *
	 * @param string $import_id Import session ULID.
	 * @return int
	 */
	private function get_next_chunk_index( string $import_id ): int {
		$state = $this->session_manager->load( $import_id );
		return count( $state['chunk_references'] ?? array() );
	}
}
