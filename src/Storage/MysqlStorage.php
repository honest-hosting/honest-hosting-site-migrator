<?php
/**
 * MySQL-backed session storage (fallback when SQLite3 is unavailable).
 *
 * @package HonestHosting\SiteMigrator\Storage
 */

namespace HonestHosting\SiteMigrator\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Stores migration session state in WordPress MySQL tables.
 *
 * Tables: wp_hh_migrator_session, wp_hh_migrator_file_progress,
 * wp_hh_migrator_chunk_ref, wp_hh_migrator_db_progress.
 */
class MysqlStorage implements SessionStorageInterface {

	/**
	 * Lock TTL in seconds.
	 *
	 * @var int
	 */
	private const LOCK_TTL = 60;

	/**
	 * Import session ULID.
	 *
	 * @var string
	 */
	private string $import_id;

	/**
	 * Constructor.
	 *
	 * @param string $import_id Import session ULID.
	 */
	public function __construct( string $import_id ) {
		$this->import_id = $import_id;
	}

	/**
	 * Initialize storage for a new import session.
	 *
	 * @param string $import_id Import session ULID.
	 * @return void
	 */
	public function init( string $import_id ): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}hh_migrator_session (
			import_id VARCHAR(36) NOT NULL,
			`key` VARCHAR(191) NOT NULL,
			value LONGTEXT,
			PRIMARY KEY (import_id, `key`)
		) {$charset};" 
		);

		dbDelta(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}hh_migrator_file_progress (
			import_id VARCHAR(36) NOT NULL,
			path VARCHAR(500) NOT NULL,
			size BIGINT NOT NULL DEFAULT 0,
			mtime BIGINT NOT NULL DEFAULT 0,
			PRIMARY KEY (import_id, path(191))
		) {$charset};"
		);

		dbDelta(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}hh_migrator_chunk_ref (
			import_id VARCHAR(36) NOT NULL,
			chunk_index INT NOT NULL,
			data LONGTEXT NOT NULL,
			PRIMARY KEY (import_id, chunk_index)
		) {$charset};" 
		);

		dbDelta(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}hh_migrator_db_progress (
			import_id VARCHAR(36) NOT NULL,
			table_name VARCHAR(191) NOT NULL,
			checksum VARCHAR(64) NOT NULL,
			PRIMARY KEY (import_id, table_name)
		) {$charset};" 
		);
	}

	/**
	 * Get the storage engine name (for logging).
	 *
	 * @return string e.g. 'sqlite3' or 'mysql'.
	 */
	public function get_engine_name(): string {
		return 'mysql';
	}

	/**
	 * Get a session value by key.
	 *
	 * @param string $key      Key name.
	 * @param mixed  $fallback Fallback value if key not found.
	 * @return mixed
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT value FROM {$wpdb->prefix}hh_migrator_session WHERE import_id = %s AND `key` = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->import_id,
				$key
			)
		);

		if ( null === $value ) {
			return $fallback;
		}

		$decoded = json_decode( $value, true );
		return ( null === $decoded && 'null' !== $value ) ? $fallback : $decoded;
	}

	/**
	 * Set a session value by key.
	 *
	 * @param string $key   Key name.
	 * @param mixed  $value Value (will be JSON-encoded if not scalar).
	 * @return void
	 */
	public function set( string $key, mixed $value ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->replace(
			$wpdb->prefix . 'hh_migrator_session',
			array(
				'import_id' => $this->import_id,
				'key'       => $key,
				'value'     => wp_json_encode( $value ),
			),
			array( '%s', '%s', '%s' )
		);
	}

	/**
	 * Set multiple session values at once.
	 *
	 * @param array<string, mixed> $values Key-value pairs.
	 * @return void
	 */
	public function set_many( array $values ): void {
		foreach ( $values as $key => $value ) {
			$this->set( $key, $value );
		}
	}

	/**
	 * Mark a file as completed.
	 *
	 * @param string $path Relative file path.
	 * @return void
	 */
	public function mark_file_completed( string $path ): void {
		$this->mark_files_completed(
			array(
				$path => array(
					'size'  => 0,
					'mtime' => 0,
				),
			) 
		);
	}

	/**
	 * Mark multiple files as completed in a batch.
	 *
	 * @param array<string, array{size: int, mtime: int}> $files Map of path => metadata.
	 * @return void
	 */
	public function mark_files_completed( array $files ): void {
		global $wpdb;

		foreach ( $files as $path => $meta ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->replace(
				$wpdb->prefix . 'hh_migrator_file_progress',
				array(
					'import_id' => $this->import_id,
					'path'      => $path,
					'size'      => $meta['size'],
					'mtime'     => $meta['mtime'],
				),
				array( '%s', '%s', '%d', '%d' )
			);
		}
	}

	/**
	 * Check if a file has been completed.
	 *
	 * @param string $path Relative file path.
	 * @return bool
	 */
	public function is_file_completed( string $path ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}hh_migrator_file_progress WHERE import_id = %s AND path = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->import_id,
				$path
			)
		);

		return $count > 0;
	}

	/**
	 * Get count of completed files.
	 *
	 * @return int
	 */
	public function get_completed_file_count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}hh_migrator_file_progress WHERE import_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->import_id
			)
		);
	}

	/**
	 * Get all completed file paths.
	 *
	 * @return array<string>
	 */
	public function get_completed_file_paths(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT path FROM {$wpdb->prefix}hh_migrator_file_progress WHERE import_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->import_id
			)
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get all file metadata (path => {size, mtime}).
	 *
	 * @return array<string, array{size: int, mtime: int}>
	 */
	public function get_file_metadata(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT path, size, mtime FROM {$wpdb->prefix}hh_migrator_file_progress WHERE import_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->import_id
			),
			ARRAY_A
		);

		$meta = array();
		if ( is_array( $results ) ) {
			foreach ( $results as $row ) {
				$meta[ $row['path'] ] = array(
					'size'  => (int) $row['size'],
					'mtime' => (int) $row['mtime'],
				);
			}
		}
		return $meta;
	}

	/**
	 * Add a chunk reference.
	 *
	 * @param array<string, mixed> $ref Chunk reference data.
	 * @return void
	 */
	public function add_chunk_ref( array $ref ): void {
		global $wpdb;

		$index = $ref['chunk_index'] ?? $this->get_chunk_count();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->replace(
			$wpdb->prefix . 'hh_migrator_chunk_ref',
			array(
				'import_id'   => $this->import_id,
				'chunk_index' => $index,
				'data'        => wp_json_encode( $ref ),
			),
			array( '%s', '%d', '%s' )
		);
	}

	/**
	 * Add multiple chunk references in a batch.
	 *
	 * @param array<int, array<string, mixed>> $refs Chunk references.
	 * @return void
	 */
	public function add_chunk_refs( array $refs ): void {
		foreach ( $refs as $ref ) {
			$this->add_chunk_ref( $ref );
		}
	}

	/**
	 * Get all chunk references.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_chunk_refs(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT data FROM {$wpdb->prefix}hh_migrator_chunk_ref WHERE import_id = %s ORDER BY chunk_index", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->import_id
			)
		);

		$refs = array();
		if ( is_array( $results ) ) {
			foreach ( $results as $json ) {
				$decoded = json_decode( $json, true );
				if ( is_array( $decoded ) ) {
					$refs[] = $decoded;
				}
			}
		}
		return $refs;
	}

	/**
	 * Get count of chunk references.
	 *
	 * @return int
	 */
	public function get_chunk_count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}hh_migrator_chunk_ref WHERE import_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->import_id
			)
		);
	}

	/**
	 * Mark a table as completed.
	 *
	 * @param string $table_name Table name.
	 * @param string $checksum   Table checksum.
	 * @return void
	 */
	public function mark_table_completed( string $table_name, string $checksum ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->replace(
			$wpdb->prefix . 'hh_migrator_db_progress',
			array(
				'import_id'  => $this->import_id,
				'table_name' => $table_name,
				'checksum'   => $checksum,
			),
			array( '%s', '%s', '%s' )
		);
	}

	/**
	 * Check if a table has been completed.
	 *
	 * @param string $table_name Table name.
	 * @return bool
	 */
	public function is_table_completed( string $table_name ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}hh_migrator_db_progress WHERE import_id = %s AND table_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->import_id,
				$table_name
			)
		);

		return $count > 0;
	}

	/**
	 * Get all completed table names.
	 *
	 * @return array<string>
	 */
	public function get_completed_table_names(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT table_name FROM {$wpdb->prefix}hh_migrator_db_progress WHERE import_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->import_id
			)
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get all table checksums (name => checksum).
	 *
	 * @return array<string, string>
	 */
	public function get_table_checksums(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT table_name, checksum FROM {$wpdb->prefix}hh_migrator_db_progress WHERE import_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->import_id
			),
			ARRAY_A
		);

		$checksums = array();
		if ( is_array( $results ) ) {
			foreach ( $results as $row ) {
				$checksums[ $row['table_name'] ] = $row['checksum'];
			}
		}
		return $checksums;
	}

	/**
	 * Acquire a lock.
	 *
	 * @return bool True if lock acquired.
	 */
	public function acquire_lock(): bool {
		$holder  = $this->get( 'lock_holder' );
		$expires = $this->get( 'lock_expires' );

		if ( null !== $holder && null !== $expires && (int) $expires > time() ) {
			return false;
		}

		$new_holder = gethostname() . ':' . getmypid() . ':' . time();
		$this->set_many(
			array(
				'lock_holder'  => $new_holder,
				'lock_expires' => time() + self::LOCK_TTL,
			) 
		);

		return true;
	}

	/**
	 * Release the lock.
	 *
	 * @return void
	 */
	public function release_lock(): void {
		$this->set_many(
			array(
				'lock_holder'  => null,
				'lock_expires' => null,
			) 
		);
	}

	/**
	 * Refresh the lock expiry.
	 *
	 * @return void
	 */
	public function refresh_lock(): void {
		$this->set( 'lock_expires', time() + self::LOCK_TTL );
	}

	/**
	 * Check if a lock is currently held.
	 *
	 * @return bool
	 */
	public function is_locked(): bool {
		$holder  = $this->get( 'lock_holder' );
		$expires = $this->get( 'lock_expires' );

		return null !== $holder && null !== $expires && (int) $expires > time();
	}

	/**
	 * Destroy the storage (cleanup).
	 *
	 * @return void
	 */
	public function destroy(): void {
		global $wpdb;

		$tables = array( 'hh_migrator_session', 'hh_migrator_file_progress', 'hh_migrator_chunk_ref', 'hh_migrator_db_progress' );
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->delete( $wpdb->prefix . $table, array( 'import_id' => $this->import_id ), array( '%s' ) );
		}
	}
}
