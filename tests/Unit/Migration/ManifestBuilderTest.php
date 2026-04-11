<?php
/**
 * Tests for ManifestBuilder.
 *
 * @package HonestHosting\SiteMigrator\Tests\Unit\Migration
 */

namespace HonestHosting\SiteMigrator\Tests\Unit\Migration;

use HonestHosting\SiteMigrator\Migration\ManifestBuilder;
use WP_UnitTestCase;

/**
 * Tests for manifest JSON generation.
 */
class ManifestBuilderTest extends WP_UnitTestCase {

	/**
	 * Build produces correct structure.
	 */
	public function test_build_structure(): void {
		$builder = new ManifestBuilder();

		$state = array(
			'import_id'            => '01ABCXYZ',
			'destination_site_id'  => '01SITEABC',
			'chunk_size_bytes'     => 2097152,
			'file_progress'        => array(
				'completed_files'      => 2,
				'uploaded_bytes'       => 4096,
			),
			'db_progress'          => array(
				'completed_table_names' => array( 'wp_posts', 'wp_options' ),
			),
			'file_manifest_meta' => array(
				'themes/theme/style.css'  => array( 'size' => 1024, 'mtime' => 1700000000 ),
				'plugins/plugin/main.php' => array( 'size' => 2048, 'mtime' => 1700000000 ),
			),
			'chunk_references'     => array(
				array(
					'source_path' => 'themes/theme/style.css',
					's3_key'      => 'chunks/01',
					'type'        => 'file',
				),
				array(
					'source_path' => 'wp_posts',
					's3_key'      => 'chunks/02',
					'type'        => 'database',
				),
			),
		);

		$manifest = $builder->build( $state );

		$this->assertEquals( '01ABCXYZ', $manifest['import_id'] );
		$this->assertEquals( '01SITEABC', $manifest['destination_site_id'] );
		$this->assertArrayHasKey( 'source_site', $manifest );
		$this->assertArrayHasKey( 'files', $manifest );
		$this->assertArrayHasKey( 'database', $manifest );
		$this->assertArrayHasKey( 'compression', $manifest );
		$this->assertArrayHasKey( 'chunk_size_bytes', $manifest );
		$this->assertArrayHasKey( 'totals', $manifest );
	}

	/**
	 * Source site info includes WordPress details.
	 */
	public function test_source_site_info(): void {
		$builder  = new ManifestBuilder();
		$manifest = $builder->build( array(
			'import_id'            => 'test',
			'chunk_references'     => array(),
			'file_manifest_meta' => array(),
			'file_progress'        => array(),
			'db_progress'          => array( 'completed_table_names' => array() ),
		) );

		$source = $manifest['source_site'];
		$this->assertArrayHasKey( 'url', $source );
		$this->assertArrayHasKey( 'wp_version', $source );
		$this->assertArrayHasKey( 'php_version', $source );
		$this->assertArrayHasKey( 'multisite', $source );
		$this->assertEquals( PHP_VERSION, $source['php_version'] );
	}

	/**
	 * Totals reflect session progress.
	 */
	public function test_totals(): void {
		$builder  = new ManifestBuilder();
		$manifest = $builder->build( array(
			'import_id'            => 'test',
			'file_progress'        => array(
				'completed_files' => 5,
				'uploaded_bytes'  => 10240,
			),
			'db_progress'          => array( 'completed_table_names' => array() ),
			'chunk_references'     => array(
				array( 'type' => 'file', 's3_key' => 'a', 'source_path' => 'x' ),
				array( 'type' => 'file', 's3_key' => 'b', 'source_path' => 'y' ),
				array( 'type' => 'database', 's3_key' => 'c', 'source_path' => 'z' ),
			),
			'file_manifest_meta' => array(),
		) );

		$this->assertEquals( 5, $manifest['totals']['file_count'] );
		$this->assertEquals( 10240, $manifest['totals']['file_bytes'] );
		$this->assertEquals( 3, $manifest['totals']['chunk_count'] );
	}
}
