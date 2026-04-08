<?php
/**
 * Session storage interface.
 *
 * @package HonestHosting\SiteMigrator\Storage
 */

namespace HonestHosting\SiteMigrator\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the contract for session state and progress persistence.
 */
interface SessionStorageInterface {

	/**
	 * Initialize storage for a new import session.
	 *
	 * @param string $import_id Import session ULID.
	 * @return void
	 */
	public function init( string $import_id ): void;

	/**
	 * Get the storage engine name (for logging).
	 *
	 * @return string e.g. 'sqlite3' or 'mysql'.
	 */
	public function get_engine_name(): string;

	// --- Session state (key-value) ---

	/**
	 * Get a session value by key.
	 *
	 * @param string $key      Key name.
	 * @param mixed  $fallback Fallback value if key not found.
	 * @return mixed
	 */
	public function get( string $key, mixed $fallback = null ): mixed;

	/**
	 * Set a session value by key.
	 *
	 * @param string $key   Key name.
	 * @param mixed  $value Value (will be JSON-encoded if not scalar).
	 * @return void
	 */
	public function set( string $key, mixed $value ): void;

	/**
	 * Set multiple session values at once.
	 *
	 * @param array<string, mixed> $values Key-value pairs.
	 * @return void
	 */
	public function set_many( array $values ): void;

	// --- File progress ---

	/**
	 * Mark a file as completed.
	 *
	 * @param string $path Relative file path.
	 * @param string $hash File content hash.
	 * @return void
	 */
	public function mark_file_completed( string $path, string $hash ): void;

	/**
	 * Mark multiple files as completed in a batch.
	 *
	 * @param array<string, string> $files Map of path => hash.
	 * @return void
	 */
	public function mark_files_completed( array $files ): void;

	/**
	 * Check if a file has been completed.
	 *
	 * @param string $path Relative file path.
	 * @return bool
	 */
	public function is_file_completed( string $path ): bool;

	/**
	 * Get count of completed files.
	 *
	 * @return int
	 */
	public function get_completed_file_count(): int;

	/**
	 * Get all completed file paths.
	 *
	 * @return array<string>
	 */
	public function get_completed_file_paths(): array;

	/**
	 * Get all file hashes (path => hash).
	 *
	 * @return array<string, string>
	 */
	public function get_file_hashes(): array;

	// --- Chunk references ---

	/**
	 * Add a chunk reference.
	 *
	 * @param array<string, mixed> $ref Chunk reference data.
	 * @return void
	 */
	public function add_chunk_ref( array $ref ): void;

	/**
	 * Add multiple chunk references in a batch.
	 *
	 * @param array<int, array<string, mixed>> $refs Chunk references.
	 * @return void
	 */
	public function add_chunk_refs( array $refs ): void;

	/**
	 * Get all chunk references.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_chunk_refs(): array;

	/**
	 * Get count of chunk references.
	 *
	 * @return int
	 */
	public function get_chunk_count(): int;

	// --- DB progress ---

	/**
	 * Mark a table as completed.
	 *
	 * @param string $table_name Table name.
	 * @param string $checksum   Table checksum.
	 * @return void
	 */
	public function mark_table_completed( string $table_name, string $checksum ): void;

	/**
	 * Check if a table has been completed.
	 *
	 * @param string $table_name Table name.
	 * @return bool
	 */
	public function is_table_completed( string $table_name ): bool;

	/**
	 * Get all completed table names.
	 *
	 * @return array<string>
	 */
	public function get_completed_table_names(): array;

	/**
	 * Get all table checksums (name => checksum).
	 *
	 * @return array<string, string>
	 */
	public function get_table_checksums(): array;

	// --- Lock management ---

	/**
	 * Acquire a lock.
	 *
	 * @return bool True if lock acquired.
	 */
	public function acquire_lock(): bool;

	/**
	 * Release the lock.
	 *
	 * @return void
	 */
	public function release_lock(): void;

	/**
	 * Refresh the lock expiry.
	 *
	 * @return void
	 */
	public function refresh_lock(): void;

	/**
	 * Check if a lock is currently held.
	 *
	 * @return bool
	 */
	public function is_locked(): bool;

	// --- Lifecycle ---

	/**
	 * Destroy the storage (cleanup).
	 *
	 * @return void
	 */
	public function destroy(): void;
}
