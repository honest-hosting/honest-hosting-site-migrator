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
		$result = $this->execute( $import_id, $mode );

		if ( is_wp_error( $result ) ) {
			$this->session_manager->update(
				$import_id,
				array(
					'status'     => 'failed',
					'last_error' => $result->get_error_message(),
				)
			);
			$this->session_manager->release_lock( $import_id );
			$this->logger->log( $import_id, 'failure', $result->get_error_message(), array(), 'ERROR' );
			return $result;
		}

		$this->session_manager->update( $import_id, array( 'status' => 'completed' ) );
		$this->session_manager->release_lock( $import_id );

		return $this->session_manager->load( $import_id ) ?? array( 'import_id' => $import_id );
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

		// Check for existing incomplete session.
		$existing = $this->resume_handler->find_resumable( $site_id );
		if ( null !== $existing ) {
			return new WP_Error(
				'hh_migrator_session_exists',
				__( 'An incomplete session exists for this destination. Resume or cancel it first.', 'honest-hosting-site-migrator' ),
				array( 'import_id' => $existing['import_id'] ?? '' )
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

		// Create local session.
		$state  = $this->session_manager->create( $import_id, $site_id, $mode );
		$engine = $this->session_manager->storage( $import_id )->get_engine_name();
		$this->logger->log( $import_id, 'session.created', sprintf( 'Session created (storage engine: %s).', $engine ) );

		// Acquire lock.
		if ( ! $this->session_manager->acquire_lock( $import_id ) ) {
			return new WP_Error( 'hh_migrator_lock_failed', __( 'Could not acquire session lock.', 'honest-hosting-site-migrator' ) );
		}

		$this->logger->log( $import_id, 'full' === $mode ? 'export.full.started' : 'export.incremental.started', 'Migration started.', array( 'mode' => $mode ) );

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
			$this->session_manager->update(
				$import_id,
				array(
					'status'     => 'failed',
					'last_error' => $result->get_error_message(),
				)
			);
			$this->session_manager->release_lock( $import_id );
			$this->logger->log( $import_id, 'failure', $result->get_error_message(), array(), 'ERROR' );
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
		$chunk_size = $state['chunk_size_bytes'] ?? ( 2 * 1024 * 1024 );
		$encoder    = new ChunkEncoder();
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
			$result = $this->execute_db_export( $import_id, $mode, $state, $uploader, $encoder, $remaining, $chunk_size );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Build and upload manifest, then finalize.
		return $this->execute_finalize( $import_id, $encoder, $uploader );
	}

	/**
	 * Run preflight checks if not already done.
	 *
	 * @param string               $import_id Import session UUID.
	 * @param array<string, mixed> $state     Session state.
	 * @return true|WP_Error
	 */
	private function execute_preflight( string $import_id, array $state ) {
		if ( null !== ( $state['preflight_result'] ?? null ) ) {
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

		$file_exporter = new FileExporter( $uploader, $encoder, $this->session_manager, $this->logger );
		$manifest      = $file_exporter->scan();

		$this->logger->log( $import_id, 'file_scan.completed', sprintf( 'File scan completed: %d files found.', count( $manifest ) ) );

		if ( str_starts_with( $mode, 'incremental' ) ) {
			$previous_hashes = $state['file_manifest_hashes'] ?? array();
			$manifest        = $file_exporter->diff( $manifest, $previous_hashes );
		}

		$result = $file_exporter->export( $import_id, $manifest, $remaining['completed_files'], $chunk_size );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->logger->log( $import_id, 'upload.completed', 'File upload completed.' );
		return true;
	}

	/**
	 * Execute the database export phase.
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
	private function execute_db_export( string $import_id, string $mode, array $state, S3Uploader $uploader, ChunkEncoder $encoder, array $remaining, int $chunk_size ) {
		$this->logger->log( $import_id, 'db_export.started', 'Database export starting.' );

		$db_exporter = new DatabaseExporter( $uploader, $encoder, $this->session_manager, $this->logger );
		$skip_tables = $remaining['completed_tables'];

		if ( str_starts_with( $mode, 'incremental' ) ) {
			$previous_checksums = $state['db_table_checksums'] ?? array();
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
	 * @param string       $import_id Import session UUID.
	 * @param ChunkEncoder $encoder   Chunk encoder.
	 * @param S3Uploader   $uploader  S3 uploader.
	 * @return true|WP_Error
	 */
	private function execute_finalize( string $import_id, ChunkEncoder $encoder, S3Uploader $uploader ) {
		$this->session_manager->update( $import_id, array( 'status' => 'completing' ) );

		$manifest_builder = new ManifestBuilder();
		$final_state      = $this->session_manager->load( $import_id );
		if ( null === $final_state ) {
			return new WP_Error( 'hh_migrator_session_not_found', __( 'Session not found.', 'honest-hosting-site-migrator' ) );
		}

		$manifest_data = $manifest_builder->build( $final_state );
		$manifest_json = wp_json_encode( $manifest_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $manifest_json ) ) {
			return new WP_Error( 'hh_migrator_manifest_encode_failed', __( 'Failed to encode manifest.', 'honest-hosting-site-migrator' ) );
		}

		$encoded = $encoder->encode( $manifest_json, $import_id, 999999, '_manifest.json', 'manifest' );

		$upload_result = $uploader->upload_chunk(
			$import_id,
			999999,
			$encoded['data'],
			'application/json',
			$encoded['compressed']
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
