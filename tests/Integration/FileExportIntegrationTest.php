<?php
/**
 * Integration test for file export.
 *
 * @package HonestHosting\SiteMigrator\Tests\Integration
 */

namespace HonestHosting\SiteMigrator\Tests\Integration;

use HonestHosting\SiteMigrator\Export\ChunkEncoder;
use HonestHosting\SiteMigrator\Export\FileExporter;
use HonestHosting\SiteMigrator\Api\S3Uploader;
use HonestHosting\SiteMigrator\Api\HonestHostingClient;
use HonestHosting\SiteMigrator\Migration\SessionManager;
use WP_UnitTestCase;

/**
 * Tests file scanning and chunking against a real temp directory.
 */
class FileExportIntegrationTest extends WP_UnitTestCase {

	private string $temp_dir;
	private SessionManager $session_manager;

	public function set_up(): void {
		parent::set_up();
		$this->temp_dir        = sys_get_temp_dir() . '/hh-migrator-integ-' . uniqid();
		$this->session_manager = new SessionManager();
		mkdir( $this->temp_dir, 0755, true );
		mkdir( $this->temp_dir . '/themes/mytheme', 0755, true );
		mkdir( $this->temp_dir . '/plugins/myplugin', 0755, true );
	}

	public function tear_down(): void {
		$this->rmdir_recursive( $this->temp_dir );

		$dir = $this->session_manager->get_sessions_dir();
		if ( is_dir( $dir ) ) {
			$files = glob( $dir . '/integ-*.json' );
			if ( $files ) {
				array_map( 'unlink', $files );
			}
		}

		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * Scan detects files recursively.
	 */
	public function test_scan_detects_files(): void {
		file_put_contents( $this->temp_dir . '/themes/mytheme/style.css', 'body { color: red; }' );
		file_put_contents( $this->temp_dir . '/plugins/myplugin/main.php', '<?php // plugin' );

		$encoder  = new ChunkEncoder( false );

		add_filter( 'pre_http_request', fn() => array( 'response' => array( 'code' => 200 ), 'body' => '{}' ), 10, 3 );

		$client   = new HonestHostingClient( 'key' );
		$uploader = new S3Uploader( $client );
		$exporter = new FileExporter( $uploader, $encoder, $this->session_manager, $this->temp_dir );

		$manifest = $exporter->scan();

		$this->assertArrayHasKey( 'themes/mytheme/style.css', $manifest );
		$this->assertArrayHasKey( 'plugins/myplugin/main.php', $manifest );
		$this->assertCount( 2, $manifest );

		// Each entry has path, size, hash.
		$entry = $manifest['themes/mytheme/style.css'];
		$this->assertEquals( 'themes/mytheme/style.css', $entry['path'] );
		$this->assertGreaterThan( 0, $entry['size'] );
		$this->assertNotEmpty( $entry['hash'] );
	}

	/**
	 * Diff identifies changed and new files.
	 */
	public function test_diff_detects_changes(): void {
		file_put_contents( $this->temp_dir . '/themes/mytheme/style.css', 'v2' );
		file_put_contents( $this->temp_dir . '/plugins/myplugin/main.php', 'v1' );
		file_put_contents( $this->temp_dir . '/plugins/newplugin.php', 'new' );

		add_filter( 'pre_http_request', fn() => array( 'response' => array( 'code' => 200 ), 'body' => '{}' ), 10, 3 );

		$client   = new HonestHostingClient( 'key' );
		$uploader = new S3Uploader( $client );
		$encoder  = new ChunkEncoder( false );
		$exporter = new FileExporter( $uploader, $encoder, $this->session_manager, $this->temp_dir );

		$current = $exporter->scan();

		$previous_hashes = array(
			'themes/mytheme/style.css'  => md5( 'v1' ), // Changed (v1 -> v2).
			'plugins/myplugin/main.php' => md5( 'v1' ), // Unchanged.
		);

		$changed = $exporter->diff( $current, $previous_hashes );

		$this->assertArrayHasKey( 'themes/mytheme/style.css', $changed );     // Changed.
		$this->assertArrayNotHasKey( 'plugins/myplugin/main.php', $changed );  // Unchanged.
		$this->assertArrayHasKey( 'plugins/newplugin.php', $changed );         // New.
	}

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
