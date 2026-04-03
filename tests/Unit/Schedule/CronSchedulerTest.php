<?php
/**
 * Tests for CronScheduler.
 *
 * @package HonestHosting\SiteMigrator\Tests\Unit\Schedule
 */

namespace HonestHosting\SiteMigrator\Tests\Unit\Schedule;

use HonestHosting\SiteMigrator\Schedule\CronScheduler;
use WP_UnitTestCase;

/**
 * Tests for WP-Cron scheduling.
 */
class CronSchedulerTest extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		wp_clear_scheduled_hook( CronScheduler::HOOK );
		parent::tear_down();
	}

	/**
	 * Valid intervals are defined.
	 */
	public function test_valid_intervals(): void {
		$this->assertContains( 'hh_migrator_1h', CronScheduler::VALID_INTERVALS );
		$this->assertContains( 'hh_migrator_4h', CronScheduler::VALID_INTERVALS );
		$this->assertContains( 'hh_migrator_12h', CronScheduler::VALID_INTERVALS );
		$this->assertContains( 'hh_migrator_24h', CronScheduler::VALID_INTERVALS );
	}

	/**
	 * update_schedule with enabled=true schedules an event when cron is available.
	 *
	 * Uses a subclass to override is_cron_available since the WP test environment
	 * sets DISABLE_WP_CRON.
	 */
	public function test_update_schedule_enables(): void {
		$scheduler = new class extends CronScheduler {
			public function is_cron_available(): bool {
				return true;
			}
		};

		$scheduler->update_schedule( true, 'hh_migrator_24h' );

		$next = wp_next_scheduled( CronScheduler::HOOK );
		$this->assertNotFalse( $next );
	}

	/**
	 * update_schedule with enabled=false clears the event.
	 */
	public function test_update_schedule_disables(): void {
		$scheduler = new class extends CronScheduler {
			public function is_cron_available(): bool {
				return true;
			}
		};

		$scheduler->update_schedule( true, 'hh_migrator_24h' );
		$scheduler->update_schedule( false, 'hh_migrator_24h' );

		$next = wp_next_scheduled( CronScheduler::HOOK );
		$this->assertFalse( $next );
	}

	/**
	 * update_schedule rejects invalid interval.
	 */
	public function test_update_schedule_rejects_invalid_interval(): void {
		$scheduler = new class extends CronScheduler {
			public function is_cron_available(): bool {
				return true;
			}
		};

		$scheduler->update_schedule( true, 'invalid_interval' );

		$next = wp_next_scheduled( CronScheduler::HOOK );
		$this->assertFalse( $next );
	}

	/**
	 * update_schedule does not schedule when cron is unavailable.
	 */
	public function test_update_schedule_skips_when_cron_disabled(): void {
		$scheduler = new class extends CronScheduler {
			public function is_cron_available(): bool {
				return false;
			}
		};

		$scheduler->update_schedule( true, 'hh_migrator_24h' );

		$next = wp_next_scheduled( CronScheduler::HOOK );
		$this->assertFalse( $next );
	}

	/**
	 * is_cron_available returns false when DISABLE_WP_CRON is set.
	 *
	 * The WP test environment defines DISABLE_WP_CRON=true.
	 */
	public function test_is_cron_available_reflects_constant(): void {
		$scheduler = new CronScheduler();

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$this->assertFalse( $scheduler->is_cron_available() );
		} else {
			$this->assertTrue( $scheduler->is_cron_available() );
		}
	}
}
