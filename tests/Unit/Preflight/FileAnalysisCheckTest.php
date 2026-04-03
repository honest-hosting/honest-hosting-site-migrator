<?php
/**
 * Tests for FileAnalysisCheck.
 *
 * @package HonestHosting\SiteMigrator\Tests\Unit\Preflight
 */

namespace HonestHosting\SiteMigrator\Tests\Unit\Preflight;

use HonestHosting\SiteMigrator\Preflight\Checks\FileAnalysisCheck;
use HonestHosting\SiteMigrator\Preflight\PreflightResult;
use WP_UnitTestCase;

/**
 * Tests for file analysis preflight check.
 */
class FileAnalysisCheckTest extends WP_UnitTestCase {

	/**
	 * Temp directory for test files.
	 *
	 * @var string
	 */
	private string $temp_dir;

	/**
	 * Set up temp directory.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->temp_dir = sys_get_temp_dir() . '/hh-migrator-test-' . uniqid();
		mkdir( $this->temp_dir, 0755, true );
	}

	/**
	 * Clean up temp directory.
	 */
	public function tear_down(): void {
		$this->rmdir_recursive( $this->temp_dir );
		parent::tear_down();
	}

	/**
	 * Scan reports total size and file count.
	 */
	public function test_reports_size_and_count(): void {
		file_put_contents( $this->temp_dir . '/file1.txt', str_repeat( 'a', 1024 ) );
		file_put_contents( $this->temp_dir . '/file2.txt', str_repeat( 'b', 2048 ) );

		$check  = new FileAnalysisCheck( $this->temp_dir );
		$result = new PreflightResult();
		$check->run( $result );

		$items = $result->to_array();
		$this->assertNotEmpty( $items );

		$size_item = $this->find_item( $items, 'file_total_size' );
		$this->assertNotNull( $size_item );
		$this->assertEquals( 'info', $size_item['type'] );
		$this->assertStringContainsString( '2', $size_item['message'] ); // 2 files.
	}

	/**
	 * Scan handles missing directory.
	 */
	public function test_handles_missing_directory(): void {
		$check  = new FileAnalysisCheck( '/nonexistent/path' );
		$result = new PreflightResult();
		$check->run( $result );

		$this->assertTrue( $result->has_blocking_errors() );
		$errors = $result->get_errors();
		$this->assertEquals( 'wp_content_missing', $errors[0]['code'] );
	}

	/**
	 * Scan handles empty directory.
	 */
	public function test_handles_empty_directory(): void {
		$check  = new FileAnalysisCheck( $this->temp_dir );
		$result = new PreflightResult();
		$check->run( $result );

		$this->assertFalse( $result->has_blocking_errors() );
		$size_item = $this->find_item( $result->to_array(), 'file_total_size' );
		$this->assertNotNull( $size_item );
	}

	/**
	 * Find an item by code.
	 *
	 * @param array<int, array<string, string>> $items Items.
	 * @param string                             $code  Code to find.
	 * @return array<string, string>|null
	 */
	private function find_item( array $items, string $code ): ?array {
		foreach ( $items as $item ) {
			if ( $item['code'] === $code ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function rmdir_recursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $files as $file ) {
			$file->isDir() ? rmdir( $file->getRealPath() ) : unlink( $file->getRealPath() );
		}
		rmdir( $dir );
	}
}
