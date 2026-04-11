<?php
/**
 * SQLite-backed session storage.
 *
 * @package HonestHosting\SiteMigrator\Storage
 */

namespace HonestHosting\SiteMigrator\Storage;

defined( 'ABSPATH' ) || exit;

use RuntimeException;
use SQLite3;

/**
 * Stores migration session state in a per-import SQLite database file.
 *
 * File location: wp-content/uploads/hh-migrator/sessions/{import_id}.db
 */
class SqliteStorage implements SessionStorageInterface {

	/**
	 * Lock TTL in seconds.
	 *
	 * @var int
	 */
	private const LOCK_TTL = 60;

	/**
	 * SQLite database instance.
	 *
	 * @var SQLite3
	 */
	private SQLite3 $db;

	/**
	 * Database file path.
	 *
	 * @var string
	 */
	private string $db_path;

	/**
	 * Constructor.
	 *
	 * @param string $sessions_dir Sessions directory path.
	 * @param string $import_id    Import session ULID.
	 */
	public function __construct( string $sessions_dir, string $import_id ) {
		if ( ! is_dir( $sessions_dir ) ) {
			wp_mkdir_p( $sessions_dir );
		}

		$this->db_path = $sessions_dir . '/' . $import_id . '.db';
		$this->db      = new SQLite3( $this->db_path );

		$this->db->busyTimeout( 5000 );
		$this->db->exec( 'PRAGMA journal_mode = WAL' );
		$this->db->exec( 'PRAGMA synchronous = NORMAL' );

		// Always ensure tables exist (idempotent).
		$this->init( $import_id );
	}

	/**
	 * Initialize storage for a new import session.
	 *
	 * @param string $import_id Import session ULID.
	 * @return void
	 */
	public function init( string $import_id ): void {
		$this->db->exec(
			'
			CREATE TABLE IF NOT EXISTS session (
				key TEXT PRIMARY KEY,
				value TEXT
			)
		' 
		);

		$this->db->exec(
			'
			CREATE TABLE IF NOT EXISTS file_progress (
				path TEXT PRIMARY KEY,
				size INTEGER NOT NULL DEFAULT 0,
				mtime INTEGER NOT NULL DEFAULT 0
			)
		'
		);

		$this->db->exec(
			'
			CREATE TABLE IF NOT EXISTS chunk_ref (
				chunk_index INTEGER PRIMARY KEY,
				data TEXT NOT NULL
			)
		' 
		);

		$this->db->exec(
			'
			CREATE TABLE IF NOT EXISTS db_progress (
				table_name TEXT PRIMARY KEY,
				checksum TEXT NOT NULL
			)
		' 
		);
	}

	/**
	 * Prepare a statement, asserting it does not fail.
	 *
	 * @param string $sql SQL statement.
	 * @return \SQLite3Stmt
	 * @throws RuntimeException If the statement cannot be prepared.
	 */
	private function stmt( string $sql ): \SQLite3Stmt {
		$stmt = $this->db->prepare( $sql );
		if ( ! $stmt instanceof \SQLite3Stmt ) {
			throw new RuntimeException( 'SQLite3::prepare() failed: ' . esc_html( $this->db->lastErrorMsg() ) );
		}
		return $stmt;
	}

	/**
	 * Execute a query, asserting it does not fail.
	 *
	 * @param string $sql SQL statement.
	 * @return \SQLite3Result
	 * @throws RuntimeException If the query fails.
	 */
	private function query( string $sql ): \SQLite3Result {
		$result = $this->db->query( $sql );
		if ( false === $result ) {
			throw new RuntimeException( 'SQLite3::query() failed: ' . esc_html( $this->db->lastErrorMsg() ) );
		}
		return $result;
	}

	/**
	 * Get the storage engine name (for logging).
	 *
	 * @return string e.g. 'sqlite3' or 'mysql'.
	 */
	public function get_engine_name(): string {
		return 'sqlite3';
	}

	/**
	 * Get a session value by key.
	 *
	 * @param string $key      Key name.
	 * @param mixed  $fallback Fallback value if key not found.
	 * @return mixed
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		$stmt = $this->stmt( 'SELECT value FROM session WHERE key = :key' );
		$stmt->bindValue( ':key', $key, SQLITE3_TEXT );
		$result = $stmt->execute();

		if ( false === $result ) {
			return $fallback;
		}

		$row = $result->fetchArray( SQLITE3_ASSOC );
		if ( false === $row ) {
			return $fallback;
		}

		$decoded = json_decode( $row['value'], true );
		return ( null === $decoded && 'null' !== $row['value'] ) ? $fallback : $decoded;
	}

	/**
	 * Set a session value by key.
	 *
	 * @param string $key   Key name.
	 * @param mixed  $value Value (will be JSON-encoded if not scalar).
	 * @return void
	 */
	public function set( string $key, mixed $value ): void {
		$this->set_many( array( $key => $value ) );
	}

	/**
	 * Set multiple session values at once.
	 *
	 * @param array<string, mixed> $values Key-value pairs.
	 * @return void
	 */
	public function set_many( array $values ): void {
		$this->db->exec( 'BEGIN TRANSACTION' );
		$stmt = $this->stmt( 'INSERT OR REPLACE INTO session (key, value) VALUES (:key, :value)' );

		foreach ( $values as $key => $value ) {
			$stmt->bindValue( ':key', $key, SQLITE3_TEXT );
			$stmt->bindValue( ':value', wp_json_encode( $value ), SQLITE3_TEXT );
			$stmt->execute();
			$stmt->reset();
		}

		$this->db->exec( 'COMMIT' );
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
		$this->db->exec( 'BEGIN TRANSACTION' );
		$stmt = $this->stmt( 'INSERT OR REPLACE INTO file_progress (path, size, mtime) VALUES (:path, :size, :mtime)' );

		foreach ( $files as $path => $meta ) {
			$stmt->bindValue( ':path', $path, SQLITE3_TEXT );
			$stmt->bindValue( ':size', $meta['size'], SQLITE3_INTEGER );
			$stmt->bindValue( ':mtime', $meta['mtime'], SQLITE3_INTEGER );
			$stmt->execute();
			$stmt->reset();
		}

		$this->db->exec( 'COMMIT' );
	}

	/**
	 * Check if a file has been completed.
	 *
	 * @param string $path Relative file path.
	 * @return bool
	 */
	public function is_file_completed( string $path ): bool {
		$stmt = $this->stmt( 'SELECT 1 FROM file_progress WHERE path = :path' );
		$stmt->bindValue( ':path', $path, SQLITE3_TEXT );
		$result = $stmt->execute();
		return false !== $result && false !== $result->fetchArray();
	}

	/**
	 * Get count of completed files.
	 *
	 * @return int
	 */
	public function get_completed_file_count(): int {
		$result = $this->db->querySingle( 'SELECT COUNT(*) FROM file_progress' );
		return (int) $result;
	}

	/**
	 * Get all completed file paths.
	 *
	 * @return array<string>
	 */
	public function get_completed_file_paths(): array {
		$paths  = array();
		$result = $this->query( 'SELECT path FROM file_progress' );
		while ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) {
			$paths[] = $row['path'];
		}
		return $paths;
	}

	/**
	 * Get all file metadata (path => {size, mtime}).
	 *
	 * @return array<string, array{size: int, mtime: int}>
	 */
	public function get_file_metadata(): array {
		$meta   = array();
		$result = $this->query( 'SELECT path, size, mtime FROM file_progress' );
		while ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) {
			$meta[ $row['path'] ] = array(
				'size'  => (int) $row['size'],
				'mtime' => (int) $row['mtime'],
			);
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
		$this->add_chunk_refs( array( $ref ) );
	}

	/**
	 * Add multiple chunk references in a batch.
	 *
	 * @param array<int, array<string, mixed>> $refs Chunk references.
	 * @return void
	 */
	public function add_chunk_refs( array $refs ): void {
		$this->db->exec( 'BEGIN TRANSACTION' );
		$stmt = $this->stmt( 'INSERT OR REPLACE INTO chunk_ref (chunk_index, data) VALUES (:idx, :data)' );

		foreach ( $refs as $ref ) {
			$index = $ref['chunk_index'] ?? $this->get_chunk_count();
			$stmt->bindValue( ':idx', $index, SQLITE3_INTEGER );
			$stmt->bindValue( ':data', wp_json_encode( $ref ), SQLITE3_TEXT );
			$stmt->execute();
			$stmt->reset();
		}

		$this->db->exec( 'COMMIT' );
	}

	/**
	 * Get all chunk references.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_chunk_refs(): array {
		$refs   = array();
		$result = $this->query( 'SELECT data FROM chunk_ref ORDER BY chunk_index' );
		while ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) {
			$decoded = json_decode( $row['data'], true );
			if ( is_array( $decoded ) ) {
				$refs[] = $decoded;
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
		$result = $this->db->querySingle( 'SELECT COUNT(*) FROM chunk_ref' );
		return (int) $result;
	}

	/**
	 * Mark a table as completed.
	 *
	 * @param string $table_name Table name.
	 * @param string $checksum   Table checksum.
	 * @return void
	 */
	public function mark_table_completed( string $table_name, string $checksum ): void {
		$stmt = $this->stmt( 'INSERT OR REPLACE INTO db_progress (table_name, checksum) VALUES (:name, :checksum)' );
		$stmt->bindValue( ':name', $table_name, SQLITE3_TEXT );
		$stmt->bindValue( ':checksum', $checksum, SQLITE3_TEXT );
		$stmt->execute();
	}

	/**
	 * Check if a table has been completed.
	 *
	 * @param string $table_name Table name.
	 * @return bool
	 */
	public function is_table_completed( string $table_name ): bool {
		$stmt = $this->stmt( 'SELECT 1 FROM db_progress WHERE table_name = :name' );
		$stmt->bindValue( ':name', $table_name, SQLITE3_TEXT );
		$result = $stmt->execute();
		return false !== $result && false !== $result->fetchArray();
	}

	/**
	 * Get all completed table names.
	 *
	 * @return array<string>
	 */
	public function get_completed_table_names(): array {
		$names  = array();
		$result = $this->query( 'SELECT table_name FROM db_progress' );
		while ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) {
			$names[] = $row['table_name'];
		}
		return $names;
	}

	/**
	 * Get all table checksums (name => checksum).
	 *
	 * @return array<string, string>
	 */
	public function get_table_checksums(): array {
		$checksums = array();
		$result    = $this->query( 'SELECT table_name, checksum FROM db_progress' );
		while ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) {
			$checksums[ $row['table_name'] ] = $row['checksum'];
		}
		return $checksums;
	}

	/**
	 * Acquire a lock.
	 *
	 * @return bool True if lock acquired.
	 */
	public function acquire_lock(): bool {
		if ( $this->is_locked() ) {
			return false;
		}

		$this->set_many(
			array(
				'lock_holder'  => gethostname() . ':' . getmypid() . ':' . time(),
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
		$expires = $this->get( 'lock_expires' );
		return null !== $expires && (int) $expires > time();
	}

	/**
	 * Destroy the storage (cleanup).
	 *
	 * @return void
	 */
	public function destroy(): void {
		$this->db->close();

		foreach ( array( '', '-wal', '-shm' ) as $suffix ) {
			$file = $this->db_path . $suffix;
			if ( file_exists( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
	}
}
