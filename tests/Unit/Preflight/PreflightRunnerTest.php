<?php
/**
 * Tests for PreflightRunner.
 *
 * @package HonestHosting\SiteMigrator\Tests\Unit\Preflight
 */

namespace HonestHosting\SiteMigrator\Tests\Unit\Preflight;

use HonestHosting\SiteMigrator\Preflight\PreflightCheckInterface;
use HonestHosting\SiteMigrator\Preflight\PreflightResult;
use HonestHosting\SiteMigrator\Preflight\PreflightRunner;
use WP_UnitTestCase;

/**
 * Tests for the preflight check orchestrator.
 */
class PreflightRunnerTest extends WP_UnitTestCase {

	/**
	 * Runner with no checks returns empty result.
	 */
	public function test_run_with_no_checks(): void {
		$runner = new PreflightRunner( array() );
		$result = $runner->run();

		$this->assertInstanceOf( PreflightResult::class, $result );
		$this->assertFalse( $result->has_blocking_errors() );
		$this->assertEmpty( $result->to_array() );
	}

	/**
	 * Runner aggregates results from multiple checks.
	 */
	public function test_run_aggregates_results(): void {
		$check1 = new class implements PreflightCheckInterface {
			public function run( PreflightResult $result ): void {
				$result->add_info( 'test_info', 'Info message' );
			}
		};

		$check2 = new class implements PreflightCheckInterface {
			public function run( PreflightResult $result ): void {
				$result->add_warning( 'test_warning', 'Warning message' );
			}
		};

		$runner = new PreflightRunner( array( $check1, $check2 ) );
		$result = $runner->run();

		$this->assertCount( 2, $result->to_array() );
		$this->assertFalse( $result->has_blocking_errors() );
	}

	/**
	 * Runner detects blocking errors.
	 */
	public function test_run_detects_blocking_errors(): void {
		$check = new class implements PreflightCheckInterface {
			public function run( PreflightResult $result ): void {
				$result->add_error( 'blocking', 'Blocking error' );
			}
		};

		$runner = new PreflightRunner( array( $check ) );
		$result = $runner->run();

		$this->assertTrue( $result->has_blocking_errors() );
	}

	/**
	 * PreflightResult classifies items correctly.
	 */
	public function test_result_classification(): void {
		$result = new PreflightResult();
		$result->add_error( 'e1', 'Error 1' );
		$result->add_warning( 'w1', 'Warning 1' );
		$result->add_warning( 'w2', 'Warning 2' );
		$result->add_info( 'i1', 'Info 1' );

		$this->assertCount( 1, $result->get_errors() );
		$this->assertCount( 2, $result->get_warnings() );
		$this->assertCount( 1, $result->get_info_items() );
		$this->assertCount( 4, $result->to_array() );
	}
}
