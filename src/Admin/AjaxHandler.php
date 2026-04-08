<?php
/**
 * AJAX endpoint handler for admin UI actions.
 *
 * @package HonestHosting\SiteMigrator\Admin
 */

namespace HonestHosting\SiteMigrator\Admin;

defined( 'ABSPATH' ) || exit;

use HonestHosting\SiteMigrator\Api\ApiEndpoints;
use HonestHosting\SiteMigrator\Api\HonestHostingClient;
use HonestHosting\SiteMigrator\Log\MigrationLogger;
use HonestHosting\SiteMigrator\Migration\BackgroundRunner;
use HonestHosting\SiteMigrator\Migration\MigrationOrchestrator;
use HonestHosting\SiteMigrator\Migration\SessionManager;
use HonestHosting\SiteMigrator\Preflight\PreflightResult;
use HonestHosting\SiteMigrator\Preflight\PreflightRunner;
use HonestHosting\SiteMigrator\Schedule\CronScheduler;
use HonestHosting\SiteMigrator\Util\ChunkSizeValidator;
use WP_Error;

/**
 * Registers and handles all AJAX actions for the migrator admin UI.
 *
 * Every handler verifies nonce + manage_options capability.
 */
class AjaxHandler {

	/**
	 * Constructor — register AJAX hooks.
	 */
	public function __construct() {
		$actions = array(
			'hh_migrator_validate_key',
			'hh_migrator_save_config',
			'hh_migrator_run_preflight',
			'hh_migrator_start_migration',
			'hh_migrator_resume_migration',
			'hh_migrator_get_status',
			'hh_migrator_cancel_migration',
			'hh_migrator_update_schedule',
			'hh_migrator_refresh_log',
			'hh_migrator_clear_log',
			'hh_migrator_download_debug',
		);

		foreach ( $actions as $action ) {
			$method = 'handle_' . str_replace( 'hh_migrator_', '', $action );
			add_action(
				'wp_ajax_' . $action,
				function () use ( $method ) {
					$this->$method();
				}
			);
		}
	}

	/**
	 * Validate the import key by retrieving the destination site info.
	 *
	 * @return void
	 */
	public function handle_validate_key(): void {
		$this->verify_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_request().
		$import_key = sanitize_text_field( wp_unslash( $_POST['import_key'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_request().
		$api_base_url = sanitize_text_field( wp_unslash( $_POST['api_base_url'] ?? '' ) );

		if ( empty( $import_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Import key is required.', 'honest-hosting-site-migrator' ) ) );
		}

		// Temporarily save for the client to use.
		if ( ! empty( $api_base_url ) && ApiEndpoints::is_valid_base_url( $api_base_url ) ) {
			update_option( 'hh_migrator_api_base_url', $api_base_url );
		}
		update_option( 'hh_migrator_import_key', $import_key );

		$client   = new HonestHostingClient( $import_key );
		$response = $client->get_site();

		if ( is_wp_error( $response ) ) {
			$error_data = $response->get_error_data();
			$status     = is_array( $error_data ) ? ( $error_data['status'] ?? 0 ) : 0;
			$message    = ( 401 === $status || 403 === $status )
				? __( 'Could not validate Site Import Key, is it valid?', 'honest-hosting-site-migrator' )
				: $response->get_error_message();

			wp_send_json_error( array( 'message' => $message ) );
		}

		// Auto-store destination site UUID from the response.
		$site_uuid = $response['uuid'] ?? '';
		if ( ! empty( $site_uuid ) ) {
			update_option( 'hh_migrator_destination_site_id', $site_uuid );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Import key validated successfully.', 'honest-hosting-site-migrator' ),
				'site'    => $response,
			)
		);
	}

	/**
	 * Save plugin configuration (base URL, import key, chunk size).
	 *
	 * @return void
	 */
	public function handle_save_config(): void {
		$this->verify_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_request().
		$api_base_url = sanitize_text_field( wp_unslash( $_POST['api_base_url'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_request().
		$import_key = sanitize_text_field( wp_unslash( $_POST['import_key'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_request().
		$chunk_size = sanitize_text_field( wp_unslash( $_POST['chunk_size'] ?? '' ) );

		// Validate base URL.
		if ( ! empty( $api_base_url ) ) {
			if ( ! ApiEndpoints::is_valid_base_url( $api_base_url ) ) {
				wp_send_json_error(
					array( 'message' => __( 'API base URL must use HTTPS.', 'honest-hosting-site-migrator' ) )
				);
			}
			if ( ! defined( 'HH_MIGRATOR_API_BASE_URL' ) ) {
				update_option( 'hh_migrator_api_base_url', $api_base_url );
			}
		}

		// Validate chunk size.
		if ( ! empty( $chunk_size ) ) {
			$parsed = ChunkSizeValidator::parse( $chunk_size );
			if ( is_wp_error( $parsed ) ) {
				wp_send_json_error(
					array( 'message' => $parsed->get_error_message() )
				);
			}
			update_option( 'hh_migrator_chunk_size', $chunk_size );
		}

		if ( ! empty( $import_key ) ) {
			update_option( 'hh_migrator_import_key', $import_key );
		}

		// Run preflight checks and log results.
		$result = $this->run_and_log_preflight();

		$message = $result->has_blocking_errors()
			? __( 'Configuration saved. Preflight checks found errors — review the migration log.', 'honest-hosting-site-migrator' )
			: __( 'Configuration saved. Preflight checks passed.', 'honest-hosting-site-migrator' );

		wp_send_json_success(
			array(
				'message'    => $message,
				'has_errors' => $result->has_blocking_errors(),
			)
		);
	}

	/**
	 * Run preflight checks.
	 *
	 * @return void
	 */
	public function handle_run_preflight(): void {
		$this->verify_request();

		$result = $this->run_and_log_preflight();

		wp_send_json_success(
			array(
				'results'    => $result->to_array(),
				'has_errors' => $result->has_blocking_errors(),
			)
		);
	}

	/**
	 * Run preflight checks, log results to the migration log, and record the timestamp.
	 *
	 * @return PreflightResult
	 */
	private function run_and_log_preflight(): PreflightResult {
		$runner = new PreflightRunner();
		$result = $runner->run();

		$logger    = new MigrationLogger();
		$level_map = array(
			'info'    => 'INFO',
			'warning' => 'WARN',
			'error'   => 'ERROR',
		);
		foreach ( $result->to_array() as $item ) {
			$source = $item['source'];
			$event  = 'preflight.' . $item['type'] . '.' . $source;
			$level  = $level_map[ $item['type'] ] ?? 'INFO';
			$logger->log( '', $event, $item['message'], array(), $level );
		}

		update_option( 'hh_migrator_last_preflight', time() );

		return $result;
	}

	/**
	 * Ensure preflight has been run at least once. Returns WP_Error if it fails.
	 *
	 * @return true|WP_Error
	 */
	private function ensure_preflight() {
		$last_preflight = (int) get_option( 'hh_migrator_last_preflight', 0 );
		if ( $last_preflight > 0 ) {
			return true;
		}

		$result = $this->run_and_log_preflight();
		if ( $result->has_blocking_errors() ) {
			return new WP_Error(
				'hh_migrator_preflight_failed',
				__( 'Preflight checks found blocking errors. Review the migration log before starting.', 'honest-hosting-site-migrator' )
			);
		}

		return true;
	}

	/**
	 * Start a new migration.
	 *
	 * @return void
	 */
	public function handle_start_migration(): void {
		$this->verify_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_request().
		$mode    = sanitize_text_field( wp_unslash( $_POST['mode'] ?? 'full' ) );
		$site_id = (string) get_option( 'hh_migrator_destination_site_id', '' );

		if ( empty( $site_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No destination site selected. Please validate your import key first.', 'honest-hosting-site-migrator' ) ) );
		}

		// Run preflight if not previously completed.
		$preflight = $this->ensure_preflight();
		if ( is_wp_error( $preflight ) ) {
			wp_send_json_error( array( 'message' => $preflight->get_error_message() ) );
		}

		// Prepare synchronously so API errors (409, 401, etc.) are returned to the user.
		$orchestrator = new MigrationOrchestrator();
		$state        = $orchestrator->prepare( $site_id, $mode );

		if ( is_wp_error( $state ) ) {
			wp_send_json_error( array( 'message' => $state->get_error_message() ) );
		}

		$import_id = $state['import_id'] ?? '';

		// Persist the active import UUID so the UI can poll status.
		update_option( 'hh_migrator_active_import_id', $import_id );

		// Dispatch the actual export/upload work in the background.
		$runner = new BackgroundRunner();
		$runner->dispatch_run( $import_id, $mode );

		wp_send_json_success(
			array(
				'message'   => __( 'Migration started in the background. Refresh the log to monitor progress.', 'honest-hosting-site-migrator' ),
				'import_id' => $import_id,
			) 
		);
	}

	/**
	 * Resume an interrupted migration.
	 *
	 * @return void
	 */
	public function handle_resume_migration(): void {
		$this->verify_request();

		// Run preflight if not previously completed.
		$preflight = $this->ensure_preflight();
		if ( is_wp_error( $preflight ) ) {
			wp_send_json_error( array( 'message' => $preflight->get_error_message() ) );
		}

		$runner = new BackgroundRunner();
		$runner->dispatch_resume();

		wp_send_json_success( array( 'message' => __( 'Migration resumed in the background. Refresh the log to monitor progress.', 'honest-hosting-site-migrator' ) ) );
	}

	/**
	 * Get migration status from the backend API.
	 *
	 * @return void
	 */
	public function handle_get_status(): void {
		$this->verify_request();

		$import_id = (string) get_option( 'hh_migrator_active_import_id', '' );

		if ( empty( $import_id ) ) {
			wp_send_json_success( array( 'status' => 'none' ) );
		}

		$client   = new HonestHostingClient();
		$response = $client->get_import( $import_id );

		if ( is_wp_error( $response ) ) {
			// Import not found or key invalid — clear the stored ID.
			delete_option( 'hh_migrator_active_import_id' );
			wp_send_json_success( array( 'status' => 'none' ) );
		}

		$status = $response['status'] ?? 'unknown';

		// Clear stored ID if the import has reached a terminal state.
		if ( in_array( $status, array( 'completed', 'cancelled', 'error' ), true ) ) {
			delete_option( 'hh_migrator_active_import_id' );
		}

		wp_send_json_success(
			array(
				'import_id' => $import_id,
				'status'    => $status,
			) 
		);
	}

	/**
	 * Cancel an in-progress migration.
	 *
	 * @return void
	 */
	public function handle_cancel_migration(): void {
		$this->verify_request();

		$orchestrator = new MigrationOrchestrator();
		$result       = $orchestrator->cancel();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		delete_option( 'hh_migrator_active_import_id' );

		wp_send_json_success( array( 'message' => __( 'Migration cancelled.', 'honest-hosting-site-migrator' ) ) );
	}

	/**
	 * Update cron schedule settings.
	 *
	 * @return void
	 */
	public function handle_update_schedule(): void {
		$this->verify_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_request().
		$enabled = sanitize_text_field( wp_unslash( $_POST['enabled'] ?? '0' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_request().
		$interval = sanitize_text_field( wp_unslash( $_POST['interval'] ?? 'hh_migrator_24h' ) );

		$valid_intervals = array( 'hh_migrator_1h', 'hh_migrator_4h', 'hh_migrator_12h', 'hh_migrator_24h' );
		if ( ! in_array( $interval, $valid_intervals, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid schedule interval.', 'honest-hosting-site-migrator' ) ) );
		}

		update_option( 'hh_migrator_schedule_enabled', '1' === $enabled );
		update_option( 'hh_migrator_schedule_interval', $interval );

		$scheduler = new CronScheduler();
		$scheduler->update_schedule( '1' === $enabled, $interval );

		wp_send_json_success( array( 'message' => __( 'Schedule updated.', 'honest-hosting-site-migrator' ) ) );
	}

	/**
	 * Refresh migration log entries.
	 *
	 * @return void
	 */
	public function handle_refresh_log(): void {
		$this->verify_request();

		$logger  = new MigrationLogger();
		$entries = $logger->get_recent( 100 );

		$rows = array();
		foreach ( $entries as $entry ) {
			$rows[] = array(
				'created_at' => $entry->created_at,
				'level'      => $entry->level,
				'event'      => $entry->event,
				'message'    => $entry->message,
			);
		}

		wp_send_json_success( array( 'entries' => $rows ) );
	}

	/**
	 * Clear all migration log entries.
	 *
	 * @return void
	 */
	public function handle_clear_log(): void {
		$this->verify_request();

		$logger = new MigrationLogger();
		$logger->clear();

		wp_send_json_success( array( 'message' => __( 'Log cleared.', 'honest-hosting-site-migrator' ) ) );
	}

	/**
	 * Download debug data bundle.
	 *
	 * @return void
	 */
	public function handle_download_debug(): void {
		check_ajax_referer( 'hh_migrator_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'honest-hosting-site-migrator' ), 403 );
		}

		$session_manager = new SessionManager();
		$logger          = new MigrationLogger();

		// Redact import key.
		$raw_key      = (string) get_option( 'hh_migrator_import_key', '' );
		$redacted_key = '';
		if ( strlen( $raw_key ) > 4 ) {
			$redacted_key = substr( $raw_key, 0, 4 ) . str_repeat( '*', min( strlen( $raw_key ) - 4, 20 ) );
		}

		$debug = array(
			'generated_at'      => gmdate( 'c' ),
			'plugin_version'    => HH_MIGRATOR_VERSION,
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'multisite'         => is_multisite(),
			'site_url'          => get_site_url(),
			'api_base_url'      => ApiEndpoints::get_base_url(),
			'import_key'        => $redacted_key,
			'sessions'          => $session_manager->list_all(),
			'logs'              => $logger->get_all(),
			'environment'       => array(
				'memory_limit'          => ini_get( 'memory_limit' ),
				'max_execution_time'    => (int) ini_get( 'max_execution_time' ),
				'upload_max_filesize'   => ini_get( 'upload_max_filesize' ),
				'post_max_size'         => ini_get( 'post_max_size' ),
				'curl_available'        => extension_loaded( 'curl' ),
				'compression_available' => function_exists( 'gzencode' ),
				'wp_cron_available'     => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
			),
		);

		$filename = 'hh-migrator-debug-' . gmdate( 'Y-m-d' ) . '.json';

		header( 'Content-Type: application/json' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );

		echo wp_json_encode( $debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Verify nonce and capability for AJAX requests.
	 *
	 * @return void
	 */
	private function verify_request(): void {
		check_ajax_referer( 'hh_migrator_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to perform this action.', 'honest-hosting-site-migrator' ) ),
				403
			);
		}
	}
}
