<?php
/**
 * Session state persistence and lock management.
 *
 * @package HonestHosting\SiteMigrator\Migration
 */

namespace HonestHosting\SiteMigrator\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Manages JSON state files for migration sessions.
 *
 * State files are stored in wp-content/uploads/hh-migrator/sessions/<importId>.json.
 * Writes are atomic (write-to-temp + rename) for crash safety.
 */
class SessionManager {

	/**
	 * Lock expiry in seconds (10 minutes).
	 *
	 * @var int
	 */
	private const LOCK_TTL = 600;

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
	 * Get the file path for a session.
	 *
	 * @param string $import_id Import session ULID.
	 * @return string
	 */
	public function get_session_path( string $import_id ): string {
		return $this->get_sessions_dir() . '/' . $import_id . '.json';
	}

	/**
	 * Create a new session.
	 *
	 * @param string $import_id         Backend-issued ULID.
	 * @param string $destination_site_id Destination site ULID.
	 * @param string $mode              Migration mode.
	 * @param int    $chunk_size_bytes   Chunk size in bytes.
	 * @return array<string, mixed> Session state.
	 */
	public function create( string $import_id, string $destination_site_id, string $mode, int $chunk_size_bytes ): array {
		$state = array(
			'import_id'            => $import_id,
			'destination_site_id'  => $destination_site_id,
			'mode'                 => $mode,
			'status'               => 'pending',
			'chunk_size_bytes'     => $chunk_size_bytes,
			'created_at'           => gmdate( 'c' ),
			'updated_at'           => gmdate( 'c' ),
			'file_progress'        => array(
				'total_files'          => 0,
				'completed_files'      => 0,
				'current_file'         => null,
				'completed_file_paths' => array(),
				'total_bytes'          => 0,
				'uploaded_bytes'       => 0,
			),
			'db_progress'          => array(
				'total_tables'          => 0,
				'completed_tables'      => 0,
				'current_table'         => null,
				'completed_table_names' => array(),
			),
			'chunk_references'     => array(),
			'file_manifest_hashes' => array(),
			'db_table_checksums'   => array(),
			'preflight_result'     => null,
			'retry_count'          => 0,
			'last_error'           => null,
			'lock'                 => array(
				'holder'      => null,
				'acquired_at' => null,
				'expires_at'  => null,
			),
		);

		$this->save( $import_id, $state );

		return $state;
	}

	/**
	 * Load a session by import ID.
	 *
	 * @param string $import_id Import session ULID.
	 * @return array<string, mixed>|null Session state, or null if not found.
	 */
	public function load( string $import_id ): ?array {
		$path = $this->get_session_path( $import_id );

		if ( ! file_exists( $path ) ) {
			return null;
		}

		$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $json ) {
			return null;
		}

		$state = json_decode( $json, true );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * Save session state atomically.
	 *
	 * @param string               $import_id Import session ULID.
	 * @param array<string, mixed> $state     Session state.
	 * @return bool
	 */
	public function save( string $import_id, array $state ): bool {
		$state['updated_at'] = gmdate( 'c' );

		$path = $this->get_session_path( $import_id );
		$tmp  = $path . '.tmp.' . getmypid();
		$json = wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( false === $json ) {
			return false;
		}

		$dir = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $tmp, $json ) ) {
			return false;
		}

		return rename( $tmp, $path );
	}

	/**
	 * Update specific fields in a session.
	 *
	 * @param string               $import_id Import session ULID.
	 * @param array<string, mixed> $updates   Fields to update.
	 * @return bool
	 */
	public function update( string $import_id, array $updates ): bool {
		$state = $this->load( $import_id );
		if ( null === $state ) {
			return false;
		}

		$state = array_merge( $state, $updates );
		return $this->save( $import_id, $state );
	}

	/**
	 * Acquire a lock on a session.
	 *
	 * @param string $import_id Import session ULID.
	 * @return bool True if lock acquired.
	 */
	public function acquire_lock( string $import_id ): bool {
		$state = $this->load( $import_id );
		if ( null === $state ) {
			return false;
		}

		$lock = $state['lock'] ?? array();

		// Check if there's an active, non-expired lock.
		if ( ! empty( $lock['holder'] ) && ! empty( $lock['expires_at'] ) ) {
			$expires = strtotime( $lock['expires_at'] );
			if ( $expires > time() ) {
				return false; // Lock is held.
			}
		}

		$holder = $this->get_lock_holder_id();

		$state['lock'] = array(
			'holder'      => $holder,
			'acquired_at' => gmdate( 'c' ),
			'expires_at'  => gmdate( 'c', time() + self::LOCK_TTL ),
		);

		return $this->save( $import_id, $state );
	}

	/**
	 * Release a lock on a session.
	 *
	 * @param string $import_id Import session ULID.
	 * @return bool
	 */
	public function release_lock( string $import_id ): bool {
		$state = $this->load( $import_id );
		if ( null === $state ) {
			return false;
		}

		$state['lock'] = array(
			'holder'      => null,
			'acquired_at' => null,
			'expires_at'  => null,
		);

		return $this->save( $import_id, $state );
	}

	/**
	 * Refresh a lock (extend its expiry).
	 *
	 * @param string $import_id Import session ULID.
	 * @return bool
	 */
	public function refresh_lock( string $import_id ): bool {
		$state = $this->load( $import_id );
		if ( null === $state ) {
			return false;
		}

		$state['lock']['expires_at'] = gmdate( 'c', time() + self::LOCK_TTL );
		return $this->save( $import_id, $state );
	}

	/**
	 * Check if a session is locked.
	 *
	 * @param string $import_id Import session ULID.
	 * @return bool
	 */
	public function is_locked( string $import_id ): bool {
		$state = $this->load( $import_id );
		if ( null === $state ) {
			return false;
		}

		$lock = $state['lock'] ?? array();
		if ( empty( $lock['holder'] ) || empty( $lock['expires_at'] ) ) {
			return false;
		}

		return strtotime( $lock['expires_at'] ) > time();
	}

	/**
	 * List all session state files.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_all(): array {
		$sessions = array();
		$dir      = $this->get_sessions_dir();

		if ( ! is_dir( $dir ) ) {
			return $sessions;
		}

		$files = glob( $dir . '/*.json' );
		if ( false === $files ) {
			return $sessions;
		}

		foreach ( $files as $file ) {
			$import_id = basename( $file, '.json' );
			$state     = $this->load( $import_id );
			if ( null !== $state ) {
				$sessions[] = $state;
			}
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
		$path = $this->get_session_path( $import_id );
		if ( file_exists( $path ) ) {
			return unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		return true;
	}

	/**
	 * Generate a lock holder ID for this process.
	 *
	 * @return string
	 */
	private function get_lock_holder_id(): string {
		return gethostname() . ':' . getmypid() . ':' . time();
	}
}
