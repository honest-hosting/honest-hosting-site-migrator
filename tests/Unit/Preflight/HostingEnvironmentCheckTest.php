<?php
/**
 * Tests for HostingEnvironmentCheck.
 *
 * @package HonestHosting\SiteMigrator\Tests\Unit\Preflight
 */

namespace HonestHosting\SiteMigrator\Tests\Unit\Preflight;

use HonestHosting\SiteMigrator\Preflight\Checks\HostingEnvironmentCheck;
use HonestHosting\SiteMigrator\Preflight\PreflightResult;
use WP_UnitTestCase;

/**
 * Tests for hosting environment preflight check.
 */
class HostingEnvironmentCheckTest extends WP_UnitTestCase {

	/**
	 * Check produces informational items about the environment.
	 */
	public function test_produces_environment_info(): void {
		$check  = new HostingEnvironmentCheck();
		$result = new PreflightResult();
		$check->run( $result );

		$items = $result->to_array();
		$this->assertNotEmpty( $items );

		// Should have memory_limit info.
		$codes = array_column( $items, 'code' );
		$this->assertContains( 'memory_limit', $codes );
		$this->assertContains( 'max_execution_time', $codes );
		$this->assertContains( 'upload_max_filesize', $codes );
		$this->assertContains( 'post_max_size', $codes );
	}

	/**
	 * Check reports compression status.
	 */
	public function test_reports_compression_status(): void {
		$check  = new HostingEnvironmentCheck();
		$result = new PreflightResult();
		$check->run( $result );

		$codes = array_column( $result->to_array(), 'code' );
		$this->assertTrue(
			in_array( 'compression_available', $codes, true )
			|| in_array( 'compression_unavailable', $codes, true )
		);
	}
}
