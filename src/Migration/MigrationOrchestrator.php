<?php
/**
 * Migration orchestrator — top-level flow controller.
 *
 * @package HonestHosting\SiteMigrator\Migration
 */

namespace HonestHosting\SiteMigrator\Migration;

defined( 'ABSPATH' ) || exit;

use HonestHosting\SiteMigrator\Api\HonestHostingClient;
use HonestHosting\SiteMigrator\Api\S3Uploader;
use HonestHosting\SiteMigrator\Export\ChunkEncoder;
use HonestHosting\SiteMigrator\Export\DatabaseExporter;
use HonestHosting\SiteMigrator\Export\FileExporter;
use HonestHosting\SiteMigrator\Log\MigrationLogger;
use HonestHosting\SiteMigrator\Preflight\PreflightRunner;
use Throwable;
use WP_Error;

/**
 * Coordinates the full migration lifecycle.
 */
class MigrationOrchestrator {

	/**
	 * API client.
	 *
	 * @var HonestHostingClient
	 */
	private HonestHostingClient $client;

	/**
	 * Session manager.
	 *
	 * @var SessionManager
	 */
	private SessionManager $session_manager;

	/**
	 * Resume handler.
	 *
	 * @var ResumeHandler
	 */
	private ResumeHandler $resume_handler;

	/**
	 * Logger.
	 *
	 * @var MigrationLogger
	 */
	private MigrationLogger $logger;

	/**
	 * Constructor.
	 *
	 * @param HonestHostingClient|null $client          API client override.
	 * @param SessionManager|null      $session_manager Session manager override.
	 * @param MigrationLogger|null     $logger          Logger override.
	 */
	public function __construct(
		?HonestHostingClient $client = null,
		?SessionManager $session_manager = null,
		?MigrationLogger $logger = null
	) {
		$this->client          = $client ?? new HonestHostingClient();
		$this->session_manager = $session_manager ?? new SessionManager();
		$this->resume_handler  = new ResumeHandler( $this->session_manager );
		$this->logger          = $logger ?? new MigrationLogger();
	}

	/**
	 * Start a new migration.
	 *
	 * @param string $site_id Destination site UUID (from stored option).
	 * @param string $mode    Migration mode: full, incremental_all, incremental_files, incremental_db.
	 * @return array<string, mixed>|WP_Error Session state on success.
	 */
	public function start( string $site_id, string $mode ) {
		$state = $this->prepare( $site_id, $mode );
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		return $this->run( $state['import_id'] ?? '', $mode );
	}

	/**
	 * Run the migration pipeline for a previously prepared session.
	 *
	 * @param string $import_id Import session UUID.
	 * @param string $mode      Migration mode.
	 * @return array<string, mixed>|WP_Error Session state on success.
	 */
	public function run( string $import_id, string $mode ) {
		$start_time = microtime( true );
		$result     = $this->execute( $import_id, $mode );

		if ( is_wp_error( $result ) ) {
			$this->handle_run_error( $import_id, $result );
			return $result;
		}

		$duration = (int) round( microtime( true ) - $start_time );
		$this->logger->log( $import_id, 'export.completed', sprintf( 'Export finalized successfully, total duration: %ds', $duration ) );

		$this->session_manager->update( $import_id, array( 'status' => 'completed' ) );
		$this->session_manager->release_lock( $import_id );

		return $this->session_manager->load( $import_id ) ?? array( 'import_id' => $import_id );
	}

	/**
	 * Handle an error from the execute pipeline.
	 *
	 * @param string   $import_id Import session UUID.
	 * @param WP_Error $error     The error that occurred.
	 * @return void
	 */
	private function handle_run_error( string $import_id, WP_Error $error ): void {
		if ( 'hh_migrator_cancelled' === $error->get_error_code() ) {
			$this->session_manager->update( $import_id, array( 'status' => 'cancelled' ) );
			$this->session_manager->release_lock( $import_id );
			$this->logger->log( $import_id, 'cancelled', $error->get_error_message(), array(), 'INFO' );
			return;
		}

		$this->session_manager->update(
			$import_id,
			array(
				'status'     => 'failed',
				'last_error' => $error->get_error_message(),
			)
		);
		$this->session_manager->release_lock( $import_id );
		$this->logger->log( $import_id, 'failure', $error->get_error_message(), array(), 'ERROR' );
	}

	/**
	 * Prepare a migration: validate, create API session, create local session.
	 *
	 * This runs synchronously and can be called separately from execute()
	 * so that errors (e.g., 409 conflict) are returned to the caller immediately.
	 *
	 * @param string $site_id Destination site UUID.
	 * @param string $mode    Migration mode.
	 * @return array<string, mixed>|WP_Error Session state on success.
	 */
	public function prepare( string $site_id, string $mode ) {
		$valid_modes = array( 'full', 'incremental_all', 'incremental_files', 'incremental_db' );
		if ( ! in_array( $mode, $valid_modes, true ) ) {
			return new WP_Error( 'hh_migrator_invalid_mode', __( 'Invalid migration mode.', 'honest-hosting-site-migrator' ) );
		}

		// Block if an incomplete session exists — user must Resume or Cancel first.
		$existing = $this->resume_handler->find_resumable( $site_id );
		if ( null !== $existing ) {
			return new WP_Error(
				'hh_migrator_session_exists',
				__( 'A previous migration session exists. Resume it or cancel before starting a new one.', 'honest-hosting-site-migrator' )
			);
		}

		// Gather source estimates for SiteImportRequest.
		$estimator = new SourceEstimator();
		$estimates = $estimator->gather();

		// Build SiteImportRequest body.
		$request_body = array(
			'file_bytes'        => $estimates['file_bytes'],
			'file_count'        => $estimates['file_count'],
			'db_bytes'          => $estimates['db_bytes'],
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'mode'              => $this->map_mode_to_api( $mode ),
			'multisite'         => is_multisite(),
		);

		// Create import session on backend.
		$response = $this->client->create_import( $request_body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$import_id = $response['uuid'] ?? '';
		if ( empty( $import_id ) ) {
			return new WP_Error( 'hh_migrator_no_import_id', __( 'Backend did not return an import UUID.', 'honest-hosting-site-migrator' ) );
		}

		// Wrap local-session setup in a Throwable catch so DB/storage exceptions
		// surface as a logged error + clean WP_Error to the UI, instead of an
		// opaque 500 from an uncaught exception.
		try {
			$state  = $this->session_manager->create( $import_id, $site_id, $mode );
			$engine = $this->session_manager->storage( $import_id )->get_engine_name();
			$this->logger->log( $import_id, 'session.created', sprintf( 'Session created (storage engine: %s).', $engine ) );

			$this->logger->log( $import_id, 'lock.acquiring', 'Attempting to acquire session lock.' );
			$locked = $this->session_manager->acquire_lock( $import_id );
			if ( ! $locked ) {
				$this->logger->log( $import_id, 'lock.contended', 'Lock is already held by another process.', array(), 'WARN' );
				return new WP_Error( 'hh_migrator_lock_failed', __( 'Could not acquire session lock.', 'honest-hosting-site-migrator' ) );
			}
			$this->logger->log( $import_id, 'lock.acquired', 'Session lock acquired.' );

			$this->logger->log( $import_id, 'export.' . $mode . '.started', 'Migration started.', array( 'mode' => $mode ) );
		} catch ( Throwable $e ) {
			$detail = sprintf( '%s: %s at %s:%d', get_class( $e ), $e->getMessage(), $e->getFile(), $e->getLine() );
			$this->logger->log(
				$import_id,
				'prepare.exception',
				$detail,
				array( 'trace' => $e->getTraceAsString() ),
				'ERROR'
			);
			return new WP_Error(
				'hh_migrator_prepare_failed',
				__( 'Migration failed to start. See the migration log for details.', 'honest-hosting-site-migrator' )
			);
		}

		return $state;
	}

	/**
	 * Resume an interrupted migration.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function resume() {
		$site_id = (string) get_option( 'hh_migrator_destination_site_id', '' );
		if ( empty( $site_id ) ) {
			return new WP_Error( 'hh_migrator_no_destination', __( 'No destination site selected.', 'honest-hosting-site-migrator' ) );
		}

		$existing = $this->resume_handler->find_resumable( $site_id );
		if ( null === $existing ) {
			return new WP_Error( 'hh_migrator_no_resumable', __( 'No resumable session found.', 'honest-hosting-site-migrator' ) );
		}

		$import_id = $existing['import_id'] ?? '';
		$state     = $this->resume_handler->prepare_resume( $import_id );

		if ( is_wp_error( $state ) ) {
			return $state;
		}

		$mode = $state['mode'] ?? 'full';

		$this->logger->log( $import_id, 'retry.started', 'Migration resumed.' );

		$result = $this->execute( $import_id, $mode );

		if ( is_wp_error( $result ) ) {
			$this->handle_run_error( $import_id, $result );
			return $result;
		}

		$this->session_manager->update( $import_id, array( 'status' => 'completed' ) );
		$this->session_manager->release_lock( $import_id );

		return $this->session_manager->load( $import_id ) ?? $state;
	}

	/**
	 * Cancel the current migration.
	 *
	 * @return true|WP_Error
	 */
	public function cancel() {
		$site_id = (string) get_option( 'hh_migrator_destination_site_id', '' );
		if ( empty( $site_id ) ) {
			return new WP_Error( 'hh_migrator_no_destination', __( 'No destination site selected.', 'honest-hosting-site-migrator' ) );
		}

		// Cancel on the backend API (best-effort — still clean up locally on failure).
		$this->client->cancel_import();

		// Clean up local sessions.
		$sessions = $this->session_manager->list_all();
		foreach ( $sessions as $session ) {
			if ( ( $session['destination_site_id'] ?? '' ) === $site_id ) {
				$import_id = $session['import_id'] ?? '';
				$this->session_manager->update( $import_id, array( 'status' => 'cancelled' ) );
				$this->session_manager->release_lock( $import_id );
			}
		}

		return true;
	}

	/**
	 * Execute the migration pipeline.
	 *
	 * @param string $import_id Import session UUID.
	 * @param string $mode      Migration mode.
	 * @return true|WP_Error
	 */
	private function execute( string $import_id, string $mode ) {
		$state = $this->session_manager->load( $import_id );
		if ( null === $state ) {
			return new WP_Error( 'hh_migrator_session_not_found', __( 'Session not found.', 'honest-hosting-site-migrator' ) );
		}

		$remaining  = $this->resume_handler->get_remaining_work( $state );
		$chunk_size = $state['chunk_size_bytes'] ?? ( 10 * 1024 * 1024 );
		$encoder    = new ChunkEncoder( 'none' === get_option( 'hh_migrator_compression', 'auto' ) ? false : null );
		$uploader   = new S3Uploader( $this->client );

		// Run preflight if not already done.
		$preflight_result = $this->execute_preflight( $import_id, $state );
		if ( is_wp_error( $preflight_result ) ) {
			return $preflight_result;
		}

		// File export.
		if ( ! $remaining['skip_files'] ) {
			$result = $this->execute_file_export( $import_id, $mode, $state, $uploader, $encoder, $remaining, $chunk_size );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Database export.
		if ( ! $remaining['skip_db'] ) {
			$result = $this->execute_db_export( $import_id, $mode, $uploader, $encoder, $remaining, $chunk_size );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Build and upload manifest, then finalize.
		return $this->execute_finalize( $import_id, $uploader );
	}

	/**
	 * Run preflight checks if not already done.
	 *
	 * @param string               $import_id Import session UUID.
	 * @param array<string, mixed> $state     Session state.
	 * @return true|WP_Error
	 */
	private function execute_preflight( string $import_id, array $state ) {
		// Skip if preflight was already run (either in this session or via the UI).
		$already_run = null !== ( $state['preflight_result'] ?? null )
			|| (int) get_option( 'hh_migrator_last_preflight', 0 ) > 0;

		if ( $already_run ) {
			$this->session_manager->update( $import_id, array( 'preflight_result' => 'passed_via_ui' ) );
			return true;
		}

		$this->logger->log( $import_id, 'preflight.started', 'Preflight checks starting.' );

		$preflight = new PreflightRunner();
		$result    = $preflight->run();

		$this->session_manager->update( $import_id, array( 'preflight_result' => $result->to_array() ) );
		$this->logger->log( $import_id, 'preflight.completed', 'Preflight checks completed.' );

		if ( $result->has_blocking_errors() ) {
			$this->logger->log( $import_id, 'preflight.warnings', 'Preflight found blocking errors.', array( 'errors' => $result->get_errors() ), 'ERROR' );
			return new WP_Error( 'hh_migrator_preflight_failed', __( 'Preflight checks found blocking errors.', 'honest-hosting-site-migrator' ) );
		}

		return true;
	}

	/**
	 * Execute the file export phase.
	 *
	 * @param string               $import_id Import session UUID.
	 * @param string               $mode      Migration mode.
	 * @param array<string, mixed> $state     Session state.
	 * @param S3Uploader           $uploader  S3 uploader.
	 * @param ChunkEncoder         $encoder   Chunk encoder.
	 * @param array<string, mixed> $remaining Remaining work descriptor.
	 * @param int                  $chunk_size Chunk size in bytes.
	 * @return true|WP_Error
	 */
	private function execute_file_export( string $import_id, string $mode, array $state, S3Uploader $uploader, ChunkEncoder $encoder, array $remaining, int $chunk_size ) {
		$this->logger->log( $import_id, 'file_scan.started', 'File scan starting.' );

		$file_exporter  = new FileExporter( $uploader, $encoder, $this->session_manager, $this->logger );
		$is_incremental = str_starts_with( $mode, 'incremental' );
		$manifest       = $file_exporter->scan( $import_id );

		$this->logger->log( $import_id, 'file_scan.completed', sprintf( 'File scan completed: %d files found.', count( $manifest ) ) );

		if ( $is_incremental ) {
			$previous_meta = $this->load_previous_file_metadata( $state['destination_site_id'] ?? '', $import_id );
			$manifest      = $file_exporter->diff( $manifest, $previous_meta );
			$this->logger->log( $import_id, 'file_scan.diff', sprintf( '%d files changed since last session.', count( $manifest ) ) );
		}

		$result = $file_exporter->export( $import_id, $manifest, $remaining['completed_files'], $chunk_size );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->logger->log( $import_id, 'upload.completed', 'File upload completed.' );
		return true;
	}

	/**
	 * Load file metadata from the most recent completed session for this destination.
	 *
	 * @param string $destination_site_id Destination site ULID.
	 * @param string $current_import_id   Current import session to exclude.
	 * @return array<string, array{size: int, mtime: int}>
	 */
	private function load_previous_file_metadata( string $destination_site_id, string $current_import_id ): array {
		$sessions = $this->session_manager->list_all();

		foreach ( $sessions as $session ) {
			$sid = $session['import_id'] ?? '';
			if (
				$sid !== $current_import_id
				&& ( $session['destination_site_id'] ?? '' ) === $destination_site_id
				&& 'completed' === ( $session['status'] ?? '' )
			) {
				$store = $this->session_manager->storage( $sid );
				if ( method_exists( $store, 'get_file_metadata' ) ) {
					return $store->get_file_metadata();
				}
				break;
			}
		}

		return array();
	}

	/**
	 * Execute the database export phase.
	 *
	 * @param string               $import_id Import session UUID.
	 * @param string               $mode      Migration mode.
	 * @param S3Uploader           $uploader  S3 uploader.
	 * @param ChunkEncoder         $encoder   Chunk encoder.
	 * @param array<string, mixed> $remaining Remaining work descriptor.
	 * @param int                  $chunk_size Chunk size in bytes.
	 * @return true|WP_Error
	 */
	private function execute_db_export( string $import_id, string $mode, S3Uploader $uploader, ChunkEncoder $encoder, array $remaining, int $chunk_size ) {
		$this->logger->log( $import_id, 'db_export.started', 'Database export starting.' );

		$db_exporter = new DatabaseExporter( $uploader, $encoder, $this->session_manager, $this->logger );
		$skip_tables = $remaining['completed_tables'];

		if ( str_starts_with( $mode, 'incremental' ) ) {
			$previous_checksums = $this->session_manager->storage( $import_id )->get_table_checksums();
			$tables             = $db_exporter->get_tables();
			$current_checksums  = $db_exporter->get_checksums( $tables );

			foreach ( $current_checksums as $name => $checksum ) {
				if ( isset( $previous_checksums[ $name ] ) && $previous_checksums[ $name ] === $checksum ) {
					$skip_tables[] = $name;
				}
			}
			$skip_tables = array_unique( $skip_tables );
		}

		$result = $db_exporter->export( $import_id, $skip_tables, $chunk_size );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->logger->log( $import_id, 'db_export.completed', 'Database export completed.' );
		return true;
	}

	/**
	 * Build manifest, upload as final chunk, and finalize the import.
	 *
	 * @param string     $import_id Import session UUID.
	 * @param S3Uploader $uploader  S3 uploader.
	 * @return true|WP_Error
	 */
	private function execute_finalize( string $import_id, S3Uploader $uploader ) {
		$this->session_manager->update( $import_id, array( 'status' => 'completing' ) );

		$manifest_builder = new ManifestBuilder();
		$final_state      = $this->session_manager->load( $import_id );
		if ( null === $final_state ) {
			return new WP_Error( 'hh_migrator_session_not_found', __( 'Session not found.', 'honest-hosting-site-migrator' ) );
		}

		// Load bulk data directly from storage for manifest building (not kept in $state to save memory).
		$store                             = $this->session_manager->storage( $import_id );
		$final_state['chunk_references']   = $store->get_chunk_refs();
		$final_state['file_manifest_meta'] = $store->get_file_metadata();
		$final_state['db_table_checksums'] = $store->get_table_checksums();

		$chunk_refs = $final_state['chunk_references'];
		$file_meta  = $final_state['file_manifest_meta'];
		$this->logger->log( $import_id, 'finalize.state', sprintf( 'Building manifest: %d chunk_references, %d files', count( $chunk_refs ), count( $file_meta ) ) );

		$manifest_data = $manifest_builder->build( $final_state );
		$manifest_json = wp_json_encode( $manifest_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $manifest_json ) ) {
			return new WP_Error( 'hh_migrator_manifest_encode_failed', __( 'Failed to encode manifest.', 'honest-hosting-site-migrator' ) );
		}

		// Upload manifest as plain JSON — not compressed — so the restore binary can read it directly.
		$upload_result = $uploader->upload_chunk(
			$import_id,
			999999,
			$manifest_json,
			'application/json',
			false
		);

		if ( is_wp_error( $upload_result ) ) {
			return $upload_result;
		}

		// Signal backend that the import is ready to be processed.
		$finalize_result = $this->client->finalize_import();
		if ( is_wp_error( $finalize_result ) ) {
			$this->logger->log( $import_id, 'failure', 'Finalize failed: ' . $finalize_result->get_error_message(), array(), 'ERROR' );
			return $finalize_result;
		}

		$this->logger->log( $import_id, 'import_ready.sent', 'Import finalized successfully.' );
		return true;
	}

	/**
	 * Map plugin migration mode to backend API mode.
	 *
	 * @param string $plugin_mode Plugin-local mode string.
	 * @return string API mode: auto, full, or incremental.
	 */
	private function map_mode_to_api( string $plugin_mode ): string {
		return match ( $plugin_mode ) {
			'full'               => 'full',
			'incremental_all',
			'incremental_files',
			'incremental_db'     => 'incremental',
			default              => 'auto',
		};
	}
}
