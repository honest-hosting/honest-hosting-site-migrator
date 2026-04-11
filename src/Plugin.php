<?php
/**
 * Main plugin class.
 *
 * @package HonestHosting\SiteMigrator
 */

namespace HonestHosting\SiteMigrator;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin singleton — lifecycle hooks, admin registration, cron schedules.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Admin page instance.
	 *
	 * @var Admin\AdminPage|null
	 */
	private ?Admin\AdminPage $admin_page = null;

	/**
	 * AJAX handler instance.
	 *
	 * @var Admin\AjaxHandler|null
	 */
	private ?Admin\AjaxHandler $ajax_handler = null;

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — register WordPress hooks.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
		add_filter( 'cron_schedules', array( $this, 'register_cron_schedules' ) );

		register_activation_hook( HH_MIGRATOR_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( HH_MIGRATOR_FILE, array( $this, 'deactivate' ) );
	}

	/**
	 * Initialize plugin components.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->ensure_state_directory();

		// Register cron/background hooks (must run outside is_admin for WP-Cron).
		new Schedule\CronScheduler();
		new Migration\BackgroundRunner();

		if ( is_admin() ) {
			$this->admin_page   = new Admin\AdminPage();
			$this->ajax_handler = new Admin\AjaxHandler();
		}

		do_action( 'honest_hosting_site_migrator_init', $this );
	}

	/**
	 * Register custom cron schedules for incremental sync.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Existing schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public function register_cron_schedules( array $schedules ): array {
		$schedules['hh_migrator_1h']  = array(
			'interval' => HOUR_IN_SECONDS,
			'display'  => __( 'Every 1 Hour', 'honest-hosting-site-migrator' ),
		);
		$schedules['hh_migrator_4h']  = array(
			'interval' => 4 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 4 Hours', 'honest-hosting-site-migrator' ),
		);
		$schedules['hh_migrator_12h'] = array(
			'interval' => 12 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 12 Hours', 'honest-hosting-site-migrator' ),
		);
		$schedules['hh_migrator_24h'] = array(
			'interval' => DAY_IN_SECONDS,
			'display'  => __( 'Every 24 Hours', 'honest-hosting-site-migrator' ),
		);

		return $schedules;
	}

	/**
	 * Plugin activation handler.
	 *
	 * @return void
	 */
	public function activate(): void {
		$this->ensure_state_directory();
		$this->create_log_table();

		// Create MySQL fallback tables if SQLite3 is not available.
		if ( ! \HonestHosting\SiteMigrator\Storage\StorageFactory::is_sqlite_available() ) {
			$this->create_mysql_storage_tables();
		}

		flush_rewrite_rules();
		wp_cache_flush();
	}

	/**
	 * Plugin deactivation handler.
	 *
	 * @return void
	 */
	public function deactivate(): void {
		wp_clear_scheduled_hook( 'hh_migrator_scheduled_sync' );
		flush_rewrite_rules();
	}

	/**
	 * Ensure the state directory exists with proper protections.
	 *
	 * @return void
	 */
	private function ensure_state_directory(): void {
		$upload_dir = wp_upload_dir();
		$state_dir  = $upload_dir['basedir'] . '/hh-migrator';
		$sessions   = $state_dir . '/sessions';

		if ( ! is_dir( $sessions ) ) {
			wp_mkdir_p( $sessions );
		}

		// Protect state directory from direct web access.
		$htaccess = $state_dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$index = $state_dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Create the migration log table.
	 *
	 * @return void
	 */
	private function create_log_table(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'hh_migrator_log';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			import_id VARCHAR(26) DEFAULT '' NOT NULL,
			level VARCHAR(10) DEFAULT 'INFO' NOT NULL,
			event VARCHAR(100) DEFAULT '' NOT NULL,
			message TEXT NOT NULL,
			context LONGTEXT DEFAULT '' NOT NULL,
			created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
			PRIMARY KEY  (id),
			KEY import_id (import_id),
			KEY event (event),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'hh_migrator_db_version', HH_MIGRATOR_VERSION );
	}

	/**
	 * Create MySQL fallback tables for session storage.
	 *
	 * Only called when SQLite3 is not available.
	 *
	 * @return void
	 */
	private function create_mysql_storage_tables(): void {
		// Tables are created on-demand by MysqlStorage::init(),
		// but we trigger a dummy init here to ensure they exist.
		$dummy = new Storage\MysqlStorage( '__setup__' );
		$dummy->init( '__setup__' );
		$dummy->destroy();
	}

	/**
	 * Get admin page instance.
	 *
	 * @return Admin\AdminPage|null
	 */
	public function get_admin_page(): ?Admin\AdminPage {
		return $this->admin_page;
	}

	/**
	 * Get AJAX handler instance.
	 *
	 * @return Admin\AjaxHandler|null
	 */
	public function get_ajax_handler(): ?Admin\AjaxHandler {
		return $this->ajax_handler;
	}
}
