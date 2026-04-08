<?php
/**
 * Database exporter — PHP-native SQL export with chunked streaming.
 *
 * @package HonestHosting\SiteMigrator\Export
 */

namespace HonestHosting\SiteMigrator\Export;

defined( 'ABSPATH' ) || exit;

use HonestHosting\SiteMigrator\Api\S3Uploader;
use HonestHosting\SiteMigrator\Log\MigrationLogger;
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
	 * Logger.
	 *
	 * @var MigrationLogger
	 */
	private MigrationLogger $logger;

	/**
	 * Constructor.
	 *
	 * @param S3Uploader      $uploader        S3 uploader.
	 * @param ChunkEncoder    $encoder         Chunk encoder.
	 * @param SessionManager  $session_manager Session manager.
	 * @param MigrationLogger $logger          Logger.
	 */
	public function __construct( S3Uploader $uploader, ChunkEncoder $encoder, SessionManager $session_manager, MigrationLogger $logger ) {
		$this->uploader        = $uploader;
		$this->encoder         = $encoder;
		$this->session_manager = $session_manager;
		$this->logger          = $logger;
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
		$buffer      = '';

		$this->logger->log(
			$import_id,
			'db_export.started',
			sprintf( 'Starting database export: %d tables.', count( $tables ) )
		);

		$store = $this->session_manager->storage( $import_id );

		foreach ( $tables as $table ) {
			$name = $table['name'];

			if ( in_array( $name, $skip_tables, true ) ) {
				continue;
			}

			$store->set( 'current_table', $name );

			// Try transactional consistency for InnoDB.
			$is_innodb = 'InnoDB' === $table['engine'];
			if ( $is_innodb ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( 'START TRANSACTION' );
			}

			$this->logger->log( $import_id, 'db_export.table', sprintf( 'Exporting table: %s (%d rows)', $name, $table['rows'] ) );

			$result = $this->export_table( $import_id, $name, $chunk_size, $chunk_index, $buffer );

			if ( $is_innodb ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( 'COMMIT' );
			}

			if ( is_wp_error( $result ) ) {
				$this->session_manager->update(
					$import_id,
					array( 'last_error' => $result->get_error_message() )
				);
				return $result;
			}

			$chunk_index = $result;

			// Mark table complete via storage.
			$current_checksums = $this->get_checksums( array( array( 'name' => $name ) ) );
			$store->mark_table_completed( $name, $current_checksums[ $name ] ?? '' );
			$store->set( 'current_table', null );

			$store->refresh_lock();
		}

		// Flush any remaining buffer across all tables.
		if ( '' !== $buffer ) {
			$result = $this->flush_chunk( $import_id, $chunk_index, $buffer, '_combined' );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$this->logger->log( $import_id, 'db_export.completed', sprintf( 'Database export completed: %d tables.', count( $tables ) ) );

		return true;
	}

	/**
	 * Export a single table.
	 *
	 * @param string $import_id   Import session ULID.
	 * @param string $table_name  Table name.
	 * @param int    $chunk_size  Chunk size in bytes.
	 * @param int    $chunk_index Starting chunk index.
	 * @param string &$buffer     Shared buffer carried across tables.
	 * @return int|WP_Error Next chunk index on success.
	 */
	private function export_table( string $import_id, string $table_name, int $chunk_size, int $chunk_index, string &$buffer ) {
		global $wpdb;

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
					set_time_limit( max( 60, (int) ini_get( 'max_execution_time' ) ) );
				}
			}

			$offset += self::BATCH_SIZE;
		}

		// Buffer carries over to the next table — no per-table flush.
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
		$raw_size = strlen( $data );
		$encoded  = $this->encoder->encode( $data, $import_id, $chunk_index, $table_name, 'database' );

		$this->logger->log(
			$import_id,
			'db_export.chunk_upload',
			sprintf( 'Uploading db chunk-%d for %s (%s raw, %s encoded).', $chunk_index, $table_name, $this->format_bytes( $raw_size ), $this->format_bytes( strlen( $encoded['data'] ) ) )
		);

		$result = $this->uploader->upload_chunk(
			$import_id,
			$chunk_index,
			$encoded['data'],
			'application/sql',
			$encoded['compressed']
		);

		if ( is_wp_error( $result ) ) {
			$this->logger->log( $import_id, 'db_export.chunk_error', sprintf( 'Failed to upload db chunk-%d: %s', $chunk_index, $result->get_error_message() ), array(), 'ERROR' );
			return $result;
		}

		set_time_limit( max( 60, (int) ini_get( 'max_execution_time' ) ) );

		// Record chunk reference.
		$this->session_manager->storage( $import_id )->add_chunk_ref(
			array_merge( $encoded['metadata'], $result )
		);

		return true;
	}

	/**
	 * Get the next chunk index from storage.
	 *
	 * @param string $import_id Import session ULID.
	 * @return int
	 */
	private function get_next_chunk_index( string $import_id ): int {
		return $this->session_manager->storage( $import_id )->get_chunk_count();
	}

	/**
	 * Format bytes into a human-readable string.
	 *
	 * @param int $bytes Size in bytes.
	 * @return string
	 */
	private function format_bytes( int $bytes ): string {
		if ( $bytes < 1024 ) {
			return $bytes . 'B';
		}
		if ( $bytes < 1048576 ) {
			return round( $bytes / 1024, 1 ) . 'KB';
		}
		return round( $bytes / 1048576, 1 ) . 'MB';
	}
}
