<?php
/**
 * Admin settings page registration and rendering.
 *
 * @package HonestHosting\SiteMigrator\Admin
 */

namespace HonestHosting\SiteMigrator\Admin;

defined( 'ABSPATH' ) || exit;

use HonestHosting\SiteMigrator\Api\ApiEndpoints;
use HonestHosting\SiteMigrator\Util\ChunkSizeValidator;

/**
 * Registers and renders the Tools → HH Site Migrator admin page.
 *
 * Uses WordPress native admin CSS exclusively.
 */
class AdminPage {

	/**
	 * Menu slug.
	 *
	 * @var string
	 */
	public const MENU_SLUG = 'hh-site-migrator';

	/**
	 * Page hook suffix (set after add_management_page).
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor — register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Register the admin menu page under Tools.
	 *
	 * @return void
	 */
	public function add_admin_menu(): void {
		$this->hook_suffix = (string) add_management_page(
			__( 'HH Site Migrator', 'honest-hosting-site-migrator' ),
			__( 'HH Site Migrator', 'honest-hosting-site-migrator' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin CSS and JS only on this plugin's page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'hh-migrator-admin',
			HH_MIGRATOR_URL . 'assets/admin.css',
			array(),
			HH_MIGRATOR_VERSION
		);

		wp_enqueue_script(
			'hh-migrator-admin',
			HH_MIGRATOR_URL . 'assets/admin.js',
			array( 'jquery' ),
			HH_MIGRATOR_VERSION,
			true
		);

		wp_localize_script(
			'hh-migrator-admin',
			'hh_migrator_ajax',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'hh_migrator_nonce' ),
			)
		);
	}

	/**
	 * Render the main admin page.
	 *
	 * @SuppressWarnings(PHPMD.UnusedLocalVariable) -- $hh_view is consumed by included view templates.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'honest-hosting-site-migrator' ) );
		}

		// View data array — consumed by included view templates via $hh_view.
		$hh_view = array(
			'api_base_url'      => ApiEndpoints::get_base_url(),
			'base_url_locked'   => defined( 'HH_MIGRATOR_API_BASE_URL' ),
			'import_key'        => (string) get_option( 'hh_migrator_import_key', '' ),
			'chunk_size'        => ChunkSizeValidator::format( ChunkSizeValidator::get_configured_size() ),
			'destination_id'    => (string) get_option( 'hh_migrator_destination_site_id', '' ),
			'schedule_enabled'  => (bool) get_option( 'hh_migrator_schedule_enabled', false ),
			'schedule_interval' => (string) get_option( 'hh_migrator_schedule_interval', 'hh_migrator_24h' ),
			'wp_cron_available' => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
		);

		include HH_MIGRATOR_PATH . 'src/Admin/Views/main.php';
	}

	/**
	 * Get the page hook suffix.
	 *
	 * @return string
	 */
	public function get_hook_suffix(): string {
		return $this->hook_suffix;
	}
}
