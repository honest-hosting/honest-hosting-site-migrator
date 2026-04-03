<?php
/**
 * Basic test to verify the WordPress test environment works.
 *
 * @package HonestHosting\SiteMigrator\Tests\Unit
 */

namespace HonestHosting\SiteMigrator\Tests\Unit;

use WP_UnitTestCase;

/**
 * Basic environment tests.
 */
class BasicTest extends WP_UnitTestCase {

	/**
	 * Verify core WordPress functions are available.
	 */
	public function test_wordpress_functions_exist(): void {
		$this->assertTrue( function_exists( 'sanitize_text_field' ) );
		$this->assertTrue( function_exists( 'get_option' ) );
		$this->assertTrue( function_exists( 'update_option' ) );
		$this->assertTrue( function_exists( 'add_action' ) );
		$this->assertTrue( function_exists( 'add_filter' ) );
	}

	/**
	 * Verify WordPress database operations work.
	 */
	public function test_wordpress_database_operations(): void {
		update_option( 'hh_migrator_test_key', 'test_value' );
		$this->assertEquals( 'test_value', get_option( 'hh_migrator_test_key' ) );
		delete_option( 'hh_migrator_test_key' );
		$this->assertFalse( get_option( 'hh_migrator_test_key', false ) );
	}

	/**
	 * Verify WordPress hooks system works.
	 */
	public function test_wordpress_hooks_system(): void {
		add_filter(
			'hh_migrator_test_filter',
			function ( $value ) {
				return $value . '_filtered';
			}
		);

		$result = apply_filters( 'hh_migrator_test_filter', 'original' );
		$this->assertEquals( 'original_filtered', $result );
	}

	/**
	 * Verify plugin constants are defined.
	 */
	public function test_plugin_constants_defined(): void {
		$this->assertTrue( defined( 'HH_MIGRATOR_VERSION' ) );
		$this->assertTrue( defined( 'HH_MIGRATOR_FILE' ) );
		$this->assertTrue( defined( 'HH_MIGRATOR_PATH' ) );
		$this->assertTrue( defined( 'HH_MIGRATOR_URL' ) );
	}

	/**
	 * Verify test mode constant is set.
	 */
	public function test_test_mode_enabled(): void {
		$this->assertTrue( defined( 'HH_MIGRATOR_TEST_MODE' ) );
		$this->assertTrue( HH_MIGRATOR_TEST_MODE );
	}
}
