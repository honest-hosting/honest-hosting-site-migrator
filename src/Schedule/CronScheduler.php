<?php
/**
 * WP-Cron scheduler for incremental sync.
 *
 * @package HonestHosting\SiteMigrator\Schedule
 */

namespace HonestHosting\SiteMigrator\Schedule;

defined( 'ABSPATH' ) || exit;

use HonestHosting\SiteMigrator\Migration\MigrationOrchestrator;

/**
 * Manages scheduled incremental sync via WP-Cron.
 *
 * Never schedules full imports — only incremental.
 */
class CronScheduler {

	/**
	 * Cron hook name.
	 *
	 * @var string
	 */
	public const HOOK = 'hh_migrator_scheduled_sync';

	/**
	 * Maximum jitter in seconds (5 minutes).
	 *
	 * @var int
	 */
	private const MAX_JITTER = 300;

	/**
	 * Valid interval keys.
	 *
	 * @var array<string>
	 */
	public const VALID_INTERVALS = array(
		'hh_migrator_1h',
		'hh_migrator_4h',
		'hh_migrator_12h',
		'hh_migrator_24h',
	);

	/**
	 * Constructor — register hooks.
	 */
	public function __construct() {
		add_action( self::HOOK, array( $this, 'run_scheduled_sync' ) );
	}

	/**
	 * Update the cron schedule.
	 *
	 * @param bool   $enabled  Whether to enable the schedule.
	 * @param string $interval Cron interval key.
	 * @return void
	 */
	public function update_schedule( bool $enabled, string $interval ): void {
		// Always clear existing schedule first.
		wp_clear_scheduled_hook( self::HOOK );

		if ( ! $enabled ) {
			return;
		}

		if ( ! in_array( $interval, self::VALID_INTERVALS, true ) ) {
			return;
		}

		if ( ! $this->is_cron_available() ) {
			return;
		}

		// Add randomized jitter to avoid thundering herd.
		$jitter   = wp_rand( 0, self::MAX_JITTER );
		$next_run = time() + $jitter;

		wp_schedule_event( $next_run, $interval, self::HOOK );
	}

	/**
	 * Execute scheduled incremental sync.
	 *
	 * This is the WP-Cron callback. Always runs incremental_all mode.
	 *
	 * @return void
	 */
	public function run_scheduled_sync(): void {
		$site_id = (string) get_option( 'hh_migrator_destination_site_id', '' );

		if ( empty( $site_id ) ) {
			return;
		}

		$orchestrator = new MigrationOrchestrator();

		// Try resume first, then start a new incremental.
		$result = $orchestrator->resume();

		if ( is_wp_error( $result ) && 'hh_migrator_no_resumable' === $result->get_error_code() ) {
			$orchestrator->start( $site_id, 'incremental_all' );
		}
	}

	/**
	 * Check if WP-Cron is available.
	 *
	 * @return bool
	 */
	public function is_cron_available(): bool {
		return ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
	}
}
