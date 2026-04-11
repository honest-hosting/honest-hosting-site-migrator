<?php
/**
 * Resume detection and session continuation.
 *
 * @package HonestHosting\SiteMigrator\Migration
 */

namespace HonestHosting\SiteMigrator\Migration;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Detects interrupted sessions and manages resume vs. restart decisions.
 */
class ResumeHandler {

	/**
	 * Session manager.
	 *
	 * @var SessionManager
	 */
	private SessionManager $session_manager;

	/**
	 * Constructor.
	 *
	 * @param SessionManager $session_manager Session manager instance.
	 */
	public function __construct( SessionManager $session_manager ) {
		$this->session_manager = $session_manager;
	}

	/**
	 * Check if an incomplete session exists for a destination site.
	 *
	 * @param string $destination_site_id Destination site ULID.
	 * @return array<string, mixed>|null Incomplete session state, or null.
	 */
	public function find_resumable( string $destination_site_id ): ?array {
		$session = $this->session_manager->find_incomplete( $destination_site_id );

		if ( null === $session ) {
			return null;
		}

		// Handle stale locks — if locked but expired, it's safe to resume.
		$import_id = $session['import_id'] ?? '';
		if ( $this->session_manager->is_locked( $import_id ) ) {
			// Lock is still active, can't resume right now.
			return null;
		}

		return $session;
	}

	/**
	 * Prepare to resume a session.
	 *
	 * @param string $import_id Import session ULID.
	 * @return array<string, mixed>|WP_Error Session state ready for resume, or error.
	 */
	public function prepare_resume( string $import_id ) {
		$state = $this->session_manager->load( $import_id );

		if ( null === $state ) {
			return new WP_Error(
				'hh_migrator_session_not_found',
				__( 'Session not found.', 'honest-hosting-site-migrator' )
			);
		}

		$status = $state['status'] ?? '';

		// Can't resume completed or cancelled sessions.
		if ( in_array( $status, array( 'completed', 'cancelled' ), true ) ) {
			return new WP_Error(
				'hh_migrator_session_not_resumable',
				sprintf(
					/* translators: %s: session status */
					__( 'Session is %s and cannot be resumed.', 'honest-hosting-site-migrator' ),
					$status
				)
			);
		}

		// Try to acquire lock.
		if ( ! $this->session_manager->acquire_lock( $import_id ) ) {
			return new WP_Error(
				'hh_migrator_session_locked',
				__( 'Session is locked by another process.', 'honest-hosting-site-migrator' )
			);
		}

		// Clear last error for fresh attempt.
		$this->session_manager->update(
			$import_id,
			array( 'last_error' => null )
		);

		return $this->session_manager->load( $import_id ) ?? $state;
	}

	/**
	 * Determine what work remains for a session.
	 *
	 * @param array<string, mixed> $state Session state.
	 * @return array{skip_files: bool, skip_db: bool, completed_files: array<string>, completed_tables: array<string>}
	 */
	public function get_remaining_work( array $state ): array {
		$mode   = $state['mode'] ?? 'full';
		$status = $state['status'] ?? 'pending';

		$skip_files = false;
		$skip_db    = false;

		// For incremental modes, respect the scope.
		if ( 'incremental_files' === $mode ) {
			$skip_db = true;
		} elseif ( 'incremental_db' === $mode ) {
			$skip_files = true;
		}

		// If we've already completed files, skip them.
		if ( 'exporting_db' === $status || 'uploading' === $status || 'completing' === $status ) {
			$skip_files = true;
		}

		$import_id = $state['import_id'] ?? '';
		$store     = $this->session_manager->storage( $import_id );

		return array(
			'skip_files'       => $skip_files,
			'skip_db'          => $skip_db,
			'completed_files'  => $store->get_completed_file_paths(),
			'completed_tables' => $state['db_progress']['completed_table_names'] ?? array(),
		);
	}
}
