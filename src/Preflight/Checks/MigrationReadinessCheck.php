<?php
/**
 * Migration readiness preflight check.
 *
 * @package HonestHosting\SiteMigrator\Preflight\Checks
 */

namespace HonestHosting\SiteMigrator\Preflight\Checks;

defined( 'ABSPATH' ) || exit;

use HonestHosting\SiteMigrator\Api\ApiEndpoints;
use HonestHosting\SiteMigrator\Migration\SessionManager;
use HonestHosting\SiteMigrator\Storage\StorageFactory;
use HonestHosting\SiteMigrator\Storage\MysqlStorage;
use Throwable;

/**
 * Verifies the preconditions a migration run depends on so that
 * misconfiguration surfaces as a clear preflight error instead of a
 * mid-run PHP fatal.
 */
class MigrationReadinessCheck implements \HonestHosting\SiteMigrator\Preflight\PreflightCheckInterface {

	/**
	 * Run the readiness check.
	 *
	 * @param \HonestHosting\SiteMigrator\Preflight\PreflightResult $result Result to populate.
	 * @return void
	 */
	public function run( \HonestHosting\SiteMigrator\Preflight\PreflightResult $result ): void {
		$this->check_required_php_extensions( $result );
		$this->check_disabled_functions( $result );
		$this->check_configuration( $result );
		$this->check_state_directory( $result );
		$this->check_storage_backend( $result );
		$this->check_log_table( $result );
		$this->check_wp_content_readable( $result );
		$this->check_active_session_conflict( $result );
	}

	/**
	 * Surface PHP functions the migration code path calls but can tolerate as
	 * disabled (because we guard each call site). Reported as warnings so the
	 * operator knows long-running phases may be limited by max_execution_time
	 * and lock holders use synthetic identifiers.
	 *
	 * @param \HonestHosting\SiteMigrator\Preflight\PreflightResult $result Result to populate.
	 * @return void
	 */
	private function check_disabled_functions( \HonestHosting\SiteMigrator\Preflight\PreflightResult $result ): void {
		$expected = array(
			'gethostname'    => __( 'Lock holders will be tagged with the SERVER_NAME or "unknown-host" instead of the system hostname.', 'honest-hosting-site-migrator' ),
			'getmypid'       => __( 'Lock holders will use a per-request random integer instead of the real process ID.', 'honest-hosting-site-migrator' ),
			'set_time_limit' => __( 'Long-running scan and upload phases cannot reset the PHP execution timer; ensure max_execution_time is generous or rely on resume support.', 'honest-hosting-site-migrator' ),
		);

		foreach ( $expected as $func => $impact ) {
			if ( ! function_exists( $func ) ) {
				$result->add_warning(
					'php_function_disabled_' . $func,
					sprintf(
						/* translators: 1: PHP function name, 2: impact description */
						__( 'PHP function "%1$s" is disabled or unavailable. %2$s', 'honest-hosting-site-migrator' ),
						$func,
						$impact
					)
				);
			}
		}
	}

	/**
	 * Check PHP extensions that the migration code path will dereference.
	 *
	 * `json` is required by every storage backend (state is JSON-encoded).
	 * `mbstring` is used implicitly by WordPress core and several helpers.
	 *
	 * @param \HonestHosting\SiteMigrator\Preflight\PreflightResult $result Result to populate.
	 * @return void
	 */
	private function check_required_php_extensions( \HonestHosting\SiteMigrator\Preflight\PreflightResult $result ): void {
		foreach ( array( 'json', 'mbstring' ) as $ext ) {
			if ( ! extension_loaded( $ext ) ) {
				$result->add_error(
					'php_extension_missing_' . $ext,
					sprintf(
						/* translators: %s: PHP extension name */
						__( 'Required PHP extension "%s" is not loaded. Migration cannot run.', 'honest-hosting-site-migrator' ),
						$ext
					)
				);
			}
		}
	}

	/**
	 * Verify the configuration values the migration handler dereferences.
	 *
	 * @param \HonestHosting\SiteMigrator\Preflight\PreflightResult $result Result to populate.
	 * @return void
	 */
	private function check_configuration( \HonestHosting\SiteMigrator\Preflight\PreflightResult $result ): void {
		if ( '' === (string) get_option( 'hh_migrator_import_key', '' ) ) {
			$result->add_error( 'config_missing_import_key', __( 'Import key is not set. Validate your import key in the configuration section.', 'honest-hosting-site-migrator' ) );
		}

		if ( '' === (string) get_option( 'hh_migrator_destination_site_id', '' ) ) {
			$result->add_error( 'config_missing_destination', __( 'No destination site is selected. Validate your import key to populate the destination.', 'honest-hosting-site-migrator' ) );
		}

		$base_url = ApiEndpoints::get_base_url();
		if ( '' === $base_url || ! ApiEndpoints::is_valid_base_url( $base_url ) ) {
			$result->add_error(
				'config_invalid_api_base_url',
				sprintf(
					/* translators: %s: configured base URL */
					__( 'API base URL is missing or not HTTPS: "%s".', 'honest-hosting-site-migrator' ),
					$base_url
				)
			);
		}
	}

	/**
	 * Verify the state directory under uploads exists and is writable.
	 *
	 * SqliteStorage opens a database file under this path; if the directory
	 * cannot be created or written to, `new SQLite3()` throws and the
	 * migration handler crashes before any progress is recorded.
	 *
	 * @param \HonestHosting\SiteMigrator\Preflight\PreflightResult $result Result to populate.
	 * @return void
	 */
	private function check_state_directory( \HonestHosting\SiteMigrator\Preflight\PreflightResult $result ): void {
		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) ) {
			$result->add_error(
				'uploads_dir_unavailable',
				sprintf(
					/* translators: %s: WordPress error from wp_upload_dir */
					__( 'wp_upload_dir() reported an error: %s', 'honest-hosting-site-migrator' ),
					(string) $upload_dir['error']
				)
			);
			return;
		}

		$sessions_dir = $upload_dir['basedir'] . '/hh-migrator/sessions';

		if ( ! is_dir( $sessions_dir ) && ! wp_mkdir_p( $sessions_dir ) ) {
			$result->add_error(
				'sessions_dir_create_failed',
				sprintf(
					/* translators: %s: directory path */
					__( 'Could not create session state directory: %s', 'honest-hosting-site-migrator' ),
					$sessions_dir
				)
			);
			return;
		}

		// $sessions_dir is constructed from wp_upload_dir()['basedir'], so this is safe.
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_is_writable
		if ( ! is_writable( $sessions_dir ) ) {
			$result->add_error(
				'sessions_dir_not_writable',
				sprintf(
					/* translators: %s: directory path */
					__( 'Session state directory is not writable: %s', 'honest-hosting-site-migrator' ),
					$sessions_dir
				)
			);
		}
	}

	/**
	 * Verify the storage backend is usable.
	 *
	 * Prefers SQLite3; if absent, the MySQL fallback tables must exist or be
	 * creatable via dbDelta. Either gap causes a fatal at first session write.
	 *
	 * @param \HonestHosting\SiteMigrator\Preflight\PreflightResult $result Result to populate.
	 * @return void
	 */
	private function check_storage_backend( \HonestHosting\SiteMigrator\Preflight\PreflightResult $result ): void {
		if ( StorageFactory::is_sqlite_available() ) {
			$result->add_info( 'storage_engine', __( 'Session storage engine: SQLite3.', 'honest-hosting-site-migrator' ) );
			return;
		}

		$result->add_info( 'storage_engine', __( 'Session storage engine: MySQL (SQLite3 extension not loaded).', 'honest-hosting-site-migrator' ) );

		global $wpdb;
		$expected = array(
			$wpdb->prefix . 'hh_migrator_session',
			$wpdb->prefix . 'hh_migrator_file_progress',
			$wpdb->prefix . 'hh_migrator_chunk_ref',
			$wpdb->prefix . 'hh_migrator_db_progress',
		);

		$missing = $this->find_missing_tables( $expected );
		if ( empty( $missing ) ) {
			return;
		}

		// Tables are created on demand by MysqlStorage::init() — try once here so a
		// fresh install or upgraded plugin doesn't fail preflight unnecessarily.
		try {
			$dummy = new MysqlStorage( '__preflight__' );
			$dummy->init( '__preflight__' );
		} catch ( Throwable $e ) {
			$result->add_error(
				'mysql_storage_init_failed',
				sprintf(
					/* translators: %s: exception message */
					__( 'MySQL session storage initialization failed: %s', 'honest-hosting-site-migrator' ),
					$e->getMessage()
				)
			);
			return;
		}

		$missing = $this->find_missing_tables( $expected );
		if ( ! empty( $missing ) ) {
			$result->add_error(
				'mysql_storage_tables_missing',
				sprintf(
					/* translators: %s: comma-separated table names */
					__( 'Required MySQL session tables are missing and could not be created: %s. Deactivating and reactivating the plugin may resolve this.', 'honest-hosting-site-migrator' ),
					implode( ', ', $missing )
				)
			);
		}
	}

	/**
	 * Verify the migration log table exists.
	 *
	 * MigrationLogger::log() calls `$wpdb->insert()` against this table; a
	 * missing table fails silently for INSERT but breaks log readback later.
	 *
	 * @param \HonestHosting\SiteMigrator\Preflight\PreflightResult $result Result to populate.
	 * @return void
	 */
	private function check_log_table( \HonestHosting\SiteMigrator\Preflight\PreflightResult $result ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'hh_migrator_log';

		if ( ! empty( $this->find_missing_tables( array( $table ) ) ) ) {
			$result->add_error(
				'log_table_missing',
				sprintf(
					/* translators: %s: table name */
					__( 'Migration log table "%s" is missing. Deactivate and reactivate the plugin to recreate it.', 'honest-hosting-site-migrator' ),
					$table
				)
			);
		}
	}

	/**
	 * Verify wp-content is readable so the file scanner doesn't fatal partway through.
	 *
	 * @param \HonestHosting\SiteMigrator\Preflight\PreflightResult $result Result to populate.
	 * @return void
	 */
	private function check_wp_content_readable( \HonestHosting\SiteMigrator\Preflight\PreflightResult $result ): void {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			$result->add_error( 'wp_content_dir_undefined', __( 'WP_CONTENT_DIR is not defined.', 'honest-hosting-site-migrator' ) );
			return;
		}

		if ( ! is_dir( WP_CONTENT_DIR ) || ! is_readable( WP_CONTENT_DIR ) ) {
			$result->add_error(
				'wp_content_dir_unreadable',
				sprintf(
					/* translators: %s: path */
					__( 'WP_CONTENT_DIR is not readable: %s', 'honest-hosting-site-migrator' ),
					WP_CONTENT_DIR
				)
			);
		}
	}

	/**
	 * Surface any active/incomplete session that would cause prepare() to fail.
	 *
	 * Reported as a warning, not an error, since the user can resume or cancel
	 * from the same admin screen rather than re-running preflight.
	 *
	 * @param \HonestHosting\SiteMigrator\Preflight\PreflightResult $result Result to populate.
	 * @return void
	 */
	private function check_active_session_conflict( \HonestHosting\SiteMigrator\Preflight\PreflightResult $result ): void {
		$site_id = (string) get_option( 'hh_migrator_destination_site_id', '' );
		if ( '' === $site_id ) {
			return;
		}

		try {
			$existing = ( new SessionManager() )->find_incomplete( $site_id );
		} catch ( Throwable $e ) {
			$result->add_warning(
				'session_lookup_failed',
				sprintf(
					/* translators: %s: exception message */
					__( 'Could not check for incomplete sessions: %s', 'honest-hosting-site-migrator' ),
					$e->getMessage()
				)
			);
			return;
		}

		if ( null !== $existing ) {
			$result->add_warning(
				'incomplete_session_present',
				sprintf(
					/* translators: 1: import id, 2: status */
					__( 'A previous session is still in progress (import_id=%1$s, status=%2$s). Resume or cancel it before starting a new migration.', 'honest-hosting-site-migrator' ),
					(string) ( $existing['import_id'] ?? '?' ),
					(string) ( $existing['status'] ?? '?' )
				)
			);
		}
	}

	/**
	 * Return the subset of the provided table names that do not exist.
	 *
	 * @param array<int, string> $tables Fully-qualified table names.
	 * @return array<int, string>
	 */
	private function find_missing_tables( array $tables ): array {
		global $wpdb;
		$missing = array();

		foreach ( $tables as $table ) {
			// SHOW TABLES LIKE treats `_` as a wildcard; escape so we match the table name literally.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $found !== $table ) {
				$missing[] = $table;
			}
		}

		return $missing;
	}
}
