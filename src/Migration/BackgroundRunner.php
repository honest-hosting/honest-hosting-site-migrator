<?php
/**
 * Background migration runner using WP-Cron.
 *
 * @package HonestHosting\SiteMigrator\Migration
 */

namespace HonestHosting\SiteMigrator\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Dispatches migration execution as background WP-Cron events
 * so the admin UI returns immediately.
 */
class BackgroundRunner {

	/**
	 * Cron hook for background migration execution.
	 *
	 * @var string
	 */
	public const HOOK_RUN = 'hh_migrator_background_run';

	/**
	 * Cron hook for background migration resume.
	 *
	 * @var string
	 */
	public const HOOK_RESUME = 'hh_migrator_background_resume';

	/**
	 * Register cron callbacks.
	 */
	public function __construct() {
		add_action( self::HOOK_RUN, array( $this, 'handle_run' ), 10, 2 );
		add_action( self::HOOK_RESUME, array( $this, 'handle_resume' ) );
	}

	/**
	 * Schedule background execution for a prepared import session.
	 *
	 * @param string $import_id Import session UUID.
	 * @param string $mode      Migration mode.
	 * @return void
	 */
	public function dispatch_run( string $import_id, string $mode ): void {
		wp_schedule_single_event( time(), self::HOOK_RUN, array( $import_id, $mode ) );
		spawn_cron();
	}

	/**
	 * Schedule a background migration resume.
	 *
	 * @return void
	 */
	public function dispatch_resume(): void {
		wp_schedule_single_event( time(), self::HOOK_RESUME );
		spawn_cron();
	}

	/**
	 * WP-Cron callback: run a prepared migration.
	 *
	 * @param string $import_id Import session UUID.
	 * @param string $mode      Migration mode.
	 * @return void
	 */
	public function handle_run( string $import_id, string $mode ): void {
		$orchestrator = new MigrationOrchestrator();
		$orchestrator->run( $import_id, $mode );
	}

	/**
	 * WP-Cron callback: resume a migration.
	 *
	 * @return void
	 */
	public function handle_resume(): void {
		$orchestrator = new MigrationOrchestrator();
		$orchestrator->resume();
	}
}
