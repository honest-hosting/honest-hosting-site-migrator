<?php
/**
 * Integration test for database export.
 *
 * @package HonestHosting\SiteMigrator\Tests\Integration
 */

namespace HonestHosting\SiteMigrator\Tests\Integration;

use HonestHosting\SiteMigrator\Export\DatabaseExporter;
use HonestHosting\SiteMigrator\Export\ChunkEncoder;
use HonestHosting\SiteMigrator\Api\S3Uploader;
use HonestHosting\SiteMigrator\Api\HonestHostingClient;
use HonestHosting\SiteMigrator\Migration\SessionManager;
use WP_UnitTestCase;

/**
 * Tests database export against a real MariaDB instance.
 */
class DatabaseExportIntegrationTest extends WP_UnitTestCase {

	private SessionManager $session_manager;

	public function set_up(): void {
		parent::set_up();
		$this->session_manager = new SessionManager();
	}

	public function tear_down(): void {
		$dir = $this->session_manager->get_sessions_dir();
		if ( is_dir( $dir ) ) {
			$files = glob( $dir . '/db-integ-*.json' );
			if ( $files ) {
				array_map( 'unlink', $files );
			}
		}
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * get_tables returns WordPress test tables.
	 */
	public function test_get_tables(): void {
		$encoder  = new ChunkEncoder( false );

		add_filter( 'pre_http_request', fn() => array( 'response' => array( 'code' => 200 ), 'body' => '{}' ), 10, 3 );

		$client   = new HonestHostingClient( 'key' );
		$uploader = new S3Uploader( $client );
		$exporter = new DatabaseExporter( $uploader, $encoder, $this->session_manager );

		$tables = $exporter->get_tables();

		$this->assertNotEmpty( $tables );

		$table_names = array_column( $tables, 'name' );

		// WordPress test tables should include at minimum wp_posts and wp_options.
		global $wpdb;
		$this->assertContains( $wpdb->prefix . 'posts', $table_names );
		$this->assertContains( $wpdb->prefix . 'options', $table_names );

		// Each table has expected structure.
		$first = $tables[0];
		$this->assertArrayHasKey( 'name', $first );
		$this->assertArrayHasKey( 'engine', $first );
		$this->assertArrayHasKey( 'rows', $first );
		$this->assertArrayHasKey( 'size', $first );
	}

	/**
	 * get_checksums returns values for tables.
	 */
	public function test_get_checksums(): void {
		$encoder  = new ChunkEncoder( false );

		add_filter( 'pre_http_request', fn() => array( 'response' => array( 'code' => 200 ), 'body' => '{}' ), 10, 3 );

		$client   = new HonestHostingClient( 'key' );
		$uploader = new S3Uploader( $client );
		$exporter = new DatabaseExporter( $uploader, $encoder, $this->session_manager );

		$tables    = $exporter->get_tables();
		$checksums = $exporter->get_checksums( $tables );

		$this->assertNotEmpty( $checksums );

		global $wpdb;
		$this->assertArrayHasKey( $wpdb->prefix . 'options', $checksums );
	}
}
