<?php
/**
 * Tests for the main Plugin singleton.
 *
 * @package HonestHosting\SiteMigrator\Tests\Unit
 */

namespace HonestHosting\SiteMigrator\Tests\Unit;

use HonestHosting\SiteMigrator\Plugin;
use WP_UnitTestCase;

/**
 * Plugin lifecycle and singleton tests.
 */
class PluginTest extends WP_UnitTestCase {

	/**
	 * Verify singleton returns the same instance.
	 */
	public function test_get_instance_returns_singleton(): void {
		$a = Plugin::get_instance();
		$b = Plugin::get_instance();
		$this->assertSame( $a, $b );
	}

	/**
	 * Verify plugin instance is the correct type.
	 */
	public function test_instance_is_plugin(): void {
		$this->assertInstanceOf( Plugin::class, Plugin::get_instance() );
	}

	/**
	 * Verify cron schedules are registered.
	 */
	public function test_cron_schedules_registered(): void {
		$plugin    = Plugin::get_instance();
		$schedules = $plugin->register_cron_schedules( array() );

		$this->assertArrayHasKey( 'hh_migrator_1h', $schedules );
		$this->assertArrayHasKey( 'hh_migrator_4h', $schedules );
		$this->assertArrayHasKey( 'hh_migrator_12h', $schedules );
		$this->assertArrayHasKey( 'hh_migrator_24h', $schedules );
	}

	/**
	 * Verify cron schedule intervals are correct.
	 */
	public function test_cron_schedule_intervals(): void {
		$plugin    = Plugin::get_instance();
		$schedules = $plugin->register_cron_schedules( array() );

		$this->assertEquals( HOUR_IN_SECONDS, $schedules['hh_migrator_1h']['interval'] );
		$this->assertEquals( 4 * HOUR_IN_SECONDS, $schedules['hh_migrator_4h']['interval'] );
		$this->assertEquals( 12 * HOUR_IN_SECONDS, $schedules['hh_migrator_12h']['interval'] );
		$this->assertEquals( DAY_IN_SECONDS, $schedules['hh_migrator_24h']['interval'] );
	}

	/**
	 * Verify cron schedules preserve existing schedules.
	 */
	public function test_cron_schedules_preserve_existing(): void {
		$plugin   = Plugin::get_instance();
		$existing = array(
			'daily' => array(
				'interval' => DAY_IN_SECONDS,
				'display'  => 'Once Daily',
			),
		);

		$schedules = $plugin->register_cron_schedules( $existing );

		$this->assertArrayHasKey( 'daily', $schedules );
		$this->assertArrayHasKey( 'hh_migrator_1h', $schedules );
	}

	/**
	 * Verify the global helper function returns the plugin instance.
	 */
	public function test_global_helper_function(): void {
		$this->assertTrue( function_exists( 'honest_hosting_site_migrator' ) );
		$this->assertInstanceOf( Plugin::class, honest_hosting_site_migrator() );
		$this->assertSame( Plugin::get_instance(), honest_hosting_site_migrator() );
	}
}
