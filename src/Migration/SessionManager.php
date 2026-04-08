<?php
/**
 * Session state persistence and lock management.
 *
 * @package HonestHosting\SiteMigrator\Migration
 */

namespace HonestHosting\SiteMigrator\Migration;

defined( 'ABSPATH' ) || exit;

use HonestHosting\SiteMigrator\Storage\SessionStorageInterface;
use HonestHosting\SiteMigrator\Storage\StorageFactory;
use HonestHosting\SiteMigrator\Util\ChunkSizeValidator;

/**
 * Manages migration session state via a pluggable storage backend.
 *
 * Uses SQLite3 when available, falls back to MySQL.
 */
class SessionManager {

	/**
	 * Cached storage instances keyed by import_id.
	 *
	 * @var array<string, SessionStorageInterface>
	 */
	private array $storage_cache = array();

	/**
	 * Get the sessions directory path.
	 *
	 * @return string
	 */
	public function get_sessions_dir(): string {
		$upload_dir = wp_upload_dir();
		return $upload_dir['basedir'] . '/hh-migrator/sessions';
	}

	/**
	 * Get or create a storage instance for an import session.
	 *
	 * @param string $import_id Import session ULID.
	 * @return SessionStorageInterface
	 */
	public function storage( string $import_id ): SessionStorageInterface {
		if ( ! isset( $this->storage_cache[ $import_id ] ) ) {
			$this->storage_cache[ $import_id ] = StorageFactory::create( $import_id );
		}
		return $this->storage_cache[ $import_id ];
	}

	/**
	 * Create a new session.
	 *
	 * @param string   $import_id          Backend-issued ULID.
	 * @param string   $destination_site_id Destination site ULID.
	 * @param string   $mode               Migration mode.
	 * @param int|null $chunk_size_bytes    Chunk size in bytes (defaults to configured size).
	 * @return array<string, mixed> Session state.
	 */
	public function create( string $import_id, string $destination_site_id, string $mode, ?int $chunk_size_bytes = null ): array {
		$chunk_size_bytes = $chunk_size_bytes ?? ChunkSizeValidator::get_configured_size();
		$store            = $this->storage( $import_id );

		$store->init( $import_id );

		$state = array(
			'import_id'           => $import_id,
			'destination_site_id' => $destination_site_id,
			'mode'                => $mode,
			'status'              => 'pending',
			'chunk_size_bytes'    => $chunk_size_bytes,
			'created_at'          => gmdate( 'c' ),
			'updated_at'          => gmdate( 'c' ),
			'total_files'         => 0,
			'total_bytes'         => 0,
			'uploaded_bytes'      => 0,
			'total_tables'        => 0,
			'current_file'        => null,
			'current_table'       => null,
			'preflight_result'    => null,
			'retry_count'         => 0,
			'last_error'          => null,
		);

		$store->set_many( $state );

		return $state;
	}

	/**
	 * Load a session by import ID.
	 *
	 * Returns session state as an associative array for backward compatibility.
	 *
	 * @param string $import_id Import session ULID.
	 * @return array<string, mixed>|null Session state, or null if not found.
	 */
	public function load( string $import_id ): ?array {
		$store  = $this->storage( $import_id );
		$status = $store->get( 'status' );

		if ( null === $status ) {
			return null;
		}

		return array(
			'import_id'            => $store->get( 'import_id', $import_id ),
			'destination_site_id'  => $store->get( 'destination_site_id', '' ),
			'mode'                 => $store->get( 'mode', 'full' ),
			'status'               => $status,
			'chunk_size_bytes'     => $store->get( 'chunk_size_bytes', 2 * 1024 * 1024 ),
			'created_at'           => $store->get( 'created_at' ),
			'updated_at'           => $store->get( 'updated_at' ),
			'total_files'          => $store->get( 'total_files', 0 ),
			'total_bytes'          => $store->get( 'total_bytes', 0 ),
			'uploaded_bytes'       => $store->get( 'uploaded_bytes', 0 ),
			'total_tables'         => $store->get( 'total_tables', 0 ),
			'current_file'         => $store->get( 'current_file' ),
			'current_table'        => $store->get( 'current_table' ),
			'preflight_result'     => $store->get( 'preflight_result' ),
			'retry_count'          => $store->get( 'retry_count', 0 ),
			'last_error'           => $store->get( 'last_error' ),
			'file_progress'        => array(
				'total_files'          => $store->get( 'total_files', 0 ),
				'completed_files'      => $store->get_completed_file_count(),
				'current_file'         => $store->get( 'current_file' ),
				'completed_file_paths' => $store->get_completed_file_paths(),
				'total_bytes'          => $store->get( 'total_bytes', 0 ),
				'uploaded_bytes'       => $store->get( 'uploaded_bytes', 0 ),
			),
			'db_progress'          => array(
				'total_tables'          => $store->get( 'total_tables', 0 ),
				'completed_tables'      => count( $store->get_completed_table_names() ),
				'current_table'         => $store->get( 'current_table' ),
				'completed_table_names' => $store->get_completed_table_names(),
			),
			'chunk_references'     => $store->get_chunk_refs(),
			'file_manifest_hashes' => $store->get_file_hashes(),
			'db_table_checksums'   => $store->get_table_checksums(),
		);
	}

	/**
	 * Update specific fields in a session.
	 *
	 * Handles the legacy nested update format (file_progress, db_progress, chunk_references, etc.)
	 * by mapping to the appropriate storage calls.
	 *
	 * @param string               $import_id Import session ULID.
	 * @param array<string, mixed> $updates   Fields to update.
	 * @return bool
	 */
	public function update( string $import_id, array $updates ): bool {
		$store  = $this->storage( $import_id );
		$simple = array();

		foreach ( $updates as $key => $value ) {
			switch ( $key ) {
				case 'file_progress':
					$this->update_file_progress( $store, $value );
					break;

				case 'db_progress':
					$this->update_db_progress( $store, $value );
					break;

				case 'chunk_references':
					// Handled via add_chunk_refs in exporters now — skip full replace.
					break;

				case 'file_manifest_hashes':
					if ( is_array( $value ) ) {
						$store->mark_files_completed( $value );
					}
					break;

				case 'db_table_checksums':
					if ( is_array( $value ) ) {
						foreach ( $value as $table => $checksum ) {
							$store->mark_table_completed( $table, $checksum );
						}
					}
					break;

				default:
					$simple[ $key ] = $value;
					break;
			}
		}

		if ( ! empty( $simple ) ) {
			$simple['updated_at'] = gmdate( 'c' );
			$store->set_many( $simple );
		}

		return true;
	}

	/**
	 * Acquire a lock on a session.
	 *
	 * @param string $import_id Import session ULID.
	 * @return bool True if lock acquired.
	 */
	public function acquire_lock( string $import_id ): bool {
		return $this->storage( $import_id )->acquire_lock();
	}

	/**
	 * Release a lock on a session.
	 *
	 * @param string $import_id Import session ULID.
	 * @return bool
	 */
	public function release_lock( string $import_id ): bool {
		$this->storage( $import_id )->release_lock();
		return true;
	}

	/**
	 * Refresh a lock (extend its expiry).
	 *
	 * @param string $import_id Import session ULID.
	 * @return bool
	 */
	public function refresh_lock( string $import_id ): bool {
		$this->storage( $import_id )->refresh_lock();
		return true;
	}

	/**
	 * Check if a session is locked.
	 *
	 * @param string $import_id Import session ULID.
	 * @return bool
	 */
	public function is_locked( string $import_id ): bool {
		return $this->storage( $import_id )->is_locked();
	}

	/**
	 * List all sessions.
	 *
	 * Scans for SQLite DB files or MySQL session records.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_all(): array {
		$sessions = array();

		// Try SQLite files first.
		$dir = $this->get_sessions_dir();
		if ( is_dir( $dir ) ) {
			$files = glob( $dir . '/*.db' );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					$id    = basename( $file, '.db' );
					$state = $this->load( $id );
					if ( null !== $state ) {
						$sessions[] = $state;
					}
				}
			}
		}

		// Also check MySQL if using fallback.
		if ( ! StorageFactory::is_sqlite_available() ) {
			$sessions = $this->list_mysql_sessions();
		}

		return $sessions;
	}

	/**
	 * Find the most recent incomplete session for a destination site.
	 *
	 * @param string $destination_site_id Destination site ULID.
	 * @return array<string, mixed>|null
	 */
	public function find_incomplete( string $destination_site_id ): ?array {
		$sessions            = $this->list_all();
		$incomplete_statuses = array( 'pending', 'preflight', 'exporting_files', 'exporting_db', 'uploading', 'completing' );

		foreach ( $sessions as $session ) {
			if (
				( $session['destination_site_id'] ?? '' ) === $destination_site_id
				&& in_array( $session['status'] ?? '', $incomplete_statuses, true )
			) {
				return $session;
			}
		}

		return null;
	}

	/**
	 * Delete a session.
	 *
	 * @param string $import_id Import session ULID.
	 * @return bool
	 */
	public function delete( string $import_id ): bool {
		$this->storage( $import_id )->destroy();
		unset( $this->storage_cache[ $import_id ] );
		return true;
	}

	/**
	 * Map file_progress updates to storage calls.
	 *
	 * @param SessionStorageInterface $store Storage instance.
	 * @param mixed                   $fp    File progress data.
	 * @return void
	 */
	private function update_file_progress( SessionStorageInterface $store, mixed $fp ): void {
		if ( ! is_array( $fp ) ) {
			return;
		}

		$simple = array();
		if ( isset( $fp['total_files'] ) ) {
			$simple['total_files'] = $fp['total_files'];
		}
		if ( isset( $fp['total_bytes'] ) ) {
			$simple['total_bytes'] = $fp['total_bytes'];
		}
		if ( isset( $fp['uploaded_bytes'] ) ) {
			$simple['uploaded_bytes'] = $fp['uploaded_bytes'];
		}
		if ( array_key_exists( 'current_file', $fp ) ) {
			$simple['current_file'] = $fp['current_file'];
		}
		if ( ! empty( $simple ) ) {
			$store->set_many( $simple );
		}
	}

	/**
	 * Map db_progress updates to storage calls.
	 *
	 * @param SessionStorageInterface $store Storage instance.
	 * @param mixed                   $dp    DB progress data.
	 * @return void
	 */
	private function update_db_progress( SessionStorageInterface $store, mixed $dp ): void {
		if ( ! is_array( $dp ) ) {
			return;
		}

		$simple = array();
		if ( isset( $dp['total_tables'] ) ) {
			$simple['total_tables'] = $dp['total_tables'];
		}
		if ( array_key_exists( 'current_table', $dp ) ) {
			$simple['current_table'] = $dp['current_table'];
		}
		if ( ! empty( $simple ) ) {
			$store->set_many( $simple );
		}
	}

	/**
	 * List sessions from MySQL (fallback).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function list_mysql_sessions(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( "SELECT DISTINCT import_id FROM {$wpdb->prefix}hh_migrator_session" );

		$sessions = array();
		if ( is_array( $ids ) ) {
			foreach ( $ids as $id ) {
				$state = $this->load( $id );
				if ( null !== $state ) {
					$sessions[] = $state;
				}
			}
		}

		return $sessions;
	}
}
