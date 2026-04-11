<?php
/**
 * Tests for ChunkSizeValidator.
 *
 * @package HonestHosting\SiteMigrator\Tests\Unit\Util
 */

namespace HonestHosting\SiteMigrator\Tests\Unit\Util;

use HonestHosting\SiteMigrator\Util\ChunkSizeValidator;
use WP_UnitTestCase;

/**
 * Tests for chunk size parsing and validation.
 */
class ChunkSizeValidatorTest extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		delete_option( 'hh_migrator_chunk_size' );
		parent::tear_down();
	}

	/**
	 * Parse valid "5MB" input (min boundary).
	 */
	public function test_parse_5mb(): void {
		$result = ChunkSizeValidator::parse( '5MB' );
		$this->assertEquals( 5 * 1024 * 1024, $result );
	}

	/**
	 * Parse valid "10Mb" input.
	 */
	public function test_parse_10mb_mixed_case(): void {
		$result = ChunkSizeValidator::parse( '10Mb' );
		$this->assertEquals( 10 * 1024 * 1024, $result );
	}

	/**
	 * Parse valid "15mb" input.
	 */
	public function test_parse_15mb_lowercase(): void {
		$result = ChunkSizeValidator::parse( '15mb' );
		$this->assertEquals( 15 * 1024 * 1024, $result );
	}

	/**
	 * Parse valid "20MB" (max boundary).
	 */
	public function test_parse_20mb_max_boundary(): void {
		$result = ChunkSizeValidator::parse( '20MB' );
		$this->assertEquals( 20 * 1024 * 1024, $result );
	}

	/**
	 * Parse handles leading/trailing whitespace.
	 */
	public function test_parse_with_whitespace(): void {
		$result = ChunkSizeValidator::parse( '  10MB  ' );
		$this->assertEquals( 10 * 1024 * 1024, $result );
	}

	/**
	 * Parse handles space between number and unit.
	 */
	public function test_parse_with_space_between(): void {
		$result = ChunkSizeValidator::parse( '10 MB' );
		$this->assertEquals( 10 * 1024 * 1024, $result );
	}

	/**
	 * Reject input below minimum (4MB).
	 */
	public function test_parse_rejects_below_minimum(): void {
		$result = ChunkSizeValidator::parse( '4MB' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'hh_migrator_chunk_size_too_small', $result->get_error_code() );
	}

	/**
	 * Reject input above maximum (21MB).
	 */
	public function test_parse_rejects_above_maximum(): void {
		$result = ChunkSizeValidator::parse( '21MB' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'hh_migrator_chunk_size_too_large', $result->get_error_code() );
	}

	/**
	 * Reject invalid unit (GB).
	 */
	public function test_parse_rejects_invalid_unit(): void {
		$result = ChunkSizeValidator::parse( '2GB' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'hh_migrator_invalid_chunk_size', $result->get_error_code() );
	}

	/**
	 * Reject empty input.
	 */
	public function test_parse_rejects_empty(): void {
		$result = ChunkSizeValidator::parse( '' );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Reject numeric-only input.
	 */
	public function test_parse_rejects_numeric_only(): void {
		$result = ChunkSizeValidator::parse( '50' );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Reject KB unit.
	 */
	public function test_parse_rejects_kb(): void {
		$result = ChunkSizeValidator::parse( '2048KB' );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Format converts bytes to human-readable.
	 */
	public function test_format(): void {
		$this->assertEquals( '5 MB', ChunkSizeValidator::format( 5 * 1024 * 1024 ) );
		$this->assertEquals( '10 MB', ChunkSizeValidator::format( 10 * 1024 * 1024 ) );
		$this->assertEquals( '20 MB', ChunkSizeValidator::format( 20 * 1024 * 1024 ) );
	}

	/**
	 * get_configured_size returns default when no option set.
	 */
	public function test_get_configured_size_default(): void {
		$this->assertEquals( ChunkSizeValidator::DEFAULT_BYTES, ChunkSizeValidator::get_configured_size() );
	}

	/**
	 * get_configured_size returns stored option.
	 */
	public function test_get_configured_size_from_option(): void {
		update_option( 'hh_migrator_chunk_size', '10MB' );
		$this->assertEquals( 10 * 1024 * 1024, ChunkSizeValidator::get_configured_size() );
	}

	/**
	 * get_configured_size falls back to default on invalid stored value.
	 */
	public function test_get_configured_size_fallback_on_invalid(): void {
		update_option( 'hh_migrator_chunk_size', 'invalid' );
		$this->assertEquals( ChunkSizeValidator::DEFAULT_BYTES, ChunkSizeValidator::get_configured_size() );
	}

	/**
	 * Constants are correct.
	 */
	public function test_constants(): void {
		$this->assertEquals( 5 * 1024 * 1024, ChunkSizeValidator::MIN_BYTES );
		$this->assertEquals( 20 * 1024 * 1024, ChunkSizeValidator::MAX_BYTES );
		$this->assertEquals( 10 * 1024 * 1024, ChunkSizeValidator::DEFAULT_BYTES );
	}
}
