<?php
/**
 * Integration test for session resume lifecycle.
 *
 * @package HonestHosting\SiteMigrator\Tests\Integration
 */

namespace HonestHosting\SiteMigrator\Tests\Integration;

use HonestHosting\SiteMigrator\Migration\ResumeHandler;
use HonestHosting\SiteMigrator\Migration\SessionManager;
use WP_UnitTestCase;

/**
 * Tests the full session lifecycle: create, partial progress, simulate interrupt, resume, complete.
 */
class SessionResumeIntegrationTest extends WP_UnitTestCase {

	private SessionManager $manager;
	private ResumeHandler $handler;

	public function set_up(): void {
		parent::set_up();
		$this->manager = new SessionManager();
		$this->handler = new ResumeHandler( $this->manager );
	}

	public function tear_down(): void {
		// Clean up both session JSON and per-session storage (files/DB progress tables).
		$this->manager->delete( 'resume-integ-001' );

		$dir = $this->manager->get_sessions_dir();
		if ( is_dir( $dir ) ) {
			$files = glob( $dir . '/resume-integ-*.json' );
			if ( $files ) {
				array_map( 'unlink', $files );
			}
		}
		parent::tear_down();
	}

	/**
	 * Full lifecycle: create → partial progress → release lock → resume → complete.
	 */
	public function test_full_resume_lifecycle(): void {
		// 1. Create session.
		$state = $this->manager->create( 'resume-integ-001', 'site-integ-1', 'full', 2097152 );
		$this->assertEquals( 'pending', $state['status'] );

		// 2. Acquire lock and simulate partial file progress.
		$this->assertTrue( $this->manager->acquire_lock( 'resume-integ-001' ) );
		$this->manager->update( 'resume-integ-001', array(
			'status'        => 'exporting_files',
			'file_progress' => array(
				'total_files'     => 10,
				'completed_files' => 3,
				'current_file'    => 'plugins/myplugin/main.php',
				'total_bytes'     => 1048576,
				'uploaded_bytes'  => 314572,
			),
		) );

		// Completed files are tracked in the per-session storage (not session JSON),
		// which is the source of truth for ResumeHandler::get_remaining_work().
		$this->manager->storage( 'resume-integ-001' )->mark_files_completed(
			array(
				'themes/t1/style.css'     => array( 'size' => 100, 'mtime' => 1700000000 ),
				'themes/t1/functions.php' => array( 'size' => 200, 'mtime' => 1700000000 ),
				'plugins/p1/p1.php'       => array( 'size' => 300, 'mtime' => 1700000000 ),
			)
		);

		// 3. Simulate interrupt — release lock (as if PHP timed out and lock expired).
		$this->manager->release_lock( 'resume-integ-001' );

		// 4. Verify session is resumable.
		$resumable = $this->handler->find_resumable( 'site-integ-1' );
		$this->assertNotNull( $resumable );
		$this->assertEquals( 'resume-integ-001', $resumable['import_id'] );
		$this->assertEquals( 'exporting_files', $resumable['status'] );

		// 5. Prepare resume — should acquire lock.
		$resumed = $this->handler->prepare_resume( 'resume-integ-001' );
		$this->assertIsArray( $resumed );
		$this->assertTrue( $this->manager->is_locked( 'resume-integ-001' ) );

		// 6. Verify remaining work knows what to skip.
		$remaining = $this->handler->get_remaining_work( $resumed );
		$this->assertFalse( $remaining['skip_files'] );
		$this->assertFalse( $remaining['skip_db'] );
		$this->assertCount( 3, $remaining['completed_files'] );

		// 7. Simulate completion.
		$this->manager->update( 'resume-integ-001', array( 'status' => 'completed' ) );
		$this->manager->release_lock( 'resume-integ-001' );

		// 8. Verify no longer resumable.
		$this->assertNull( $this->handler->find_resumable( 'site-integ-1' ) );

		// 9. Final state is correct.
		$final = $this->manager->load( 'resume-integ-001' );
		$this->assertEquals( 'completed', $final['status'] );
		$this->assertEquals( 3, $final['file_progress']['completed_files'] );
	}
}
