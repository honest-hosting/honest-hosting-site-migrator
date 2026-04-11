<?php
/**
 * Tests for ResumeHandler.
 *
 * @package HonestHosting\SiteMigrator\Tests\Unit\Migration
 */

namespace HonestHosting\SiteMigrator\Tests\Unit\Migration;

use HonestHosting\SiteMigrator\Migration\ResumeHandler;
use HonestHosting\SiteMigrator\Migration\SessionManager;
use WP_UnitTestCase;

/**
 * Tests for resume detection and continuation.
 */
class ResumeHandlerTest extends WP_UnitTestCase {

	private SessionManager $manager;
	private ResumeHandler $handler;

	public function set_up(): void {
		parent::set_up();
		$this->manager = new SessionManager();
		$this->handler = new ResumeHandler( $this->manager );
	}

	public function tear_down(): void {
		$dir = $this->manager->get_sessions_dir();
		if ( is_dir( $dir ) ) {
			$files = glob( $dir . '/test-resume-*' );
			if ( $files ) {
				array_map( 'unlink', $files );
			}
		}
		parent::tear_down();
	}

	/**
	 * Finds a resumable incomplete session.
	 */
	public function test_find_resumable(): void {
		$this->manager->create( 'test-resume-001', 'site-resume-1', 'full', 2097152 );

		$found = $this->handler->find_resumable( 'site-resume-1' );
		$this->assertNotNull( $found );
		$this->assertEquals( 'test-resume-001', $found['import_id'] );
	}

	/**
	 * Returns null when no incomplete session exists.
	 */
	public function test_find_resumable_returns_null_when_none(): void {
		$this->assertNull( $this->handler->find_resumable( 'nonexistent-site' ) );
	}

	/**
	 * Returns null when session is locked.
	 */
	public function test_find_resumable_returns_null_when_locked(): void {
		$this->manager->create( 'test-resume-002', 'site-resume-2', 'full', 2097152 );
		$this->manager->acquire_lock( 'test-resume-002' );

		$found = $this->handler->find_resumable( 'site-resume-2' );
		$this->assertNull( $found );

		$this->manager->release_lock( 'test-resume-002' );
	}

	/**
	 * prepare_resume acquires lock and returns state.
	 */
	public function test_prepare_resume_success(): void {
		$this->manager->create( 'test-resume-003', 'site-resume-3', 'full', 2097152 );

		$state = $this->handler->prepare_resume( 'test-resume-003' );
		$this->assertIsArray( $state );
		$this->assertTrue( $this->manager->is_locked( 'test-resume-003' ) );

		$this->manager->release_lock( 'test-resume-003' );
	}

	/**
	 * prepare_resume returns error for completed session.
	 */
	public function test_prepare_resume_fails_for_completed(): void {
		$this->manager->create( 'test-resume-004', 'site-resume-4', 'full', 2097152 );
		$this->manager->update( 'test-resume-004', array( 'status' => 'completed' ) );

		$result = $this->handler->prepare_resume( 'test-resume-004' );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * get_remaining_work respects incremental modes.
	 */
	public function test_get_remaining_work_incremental_files(): void {
		$import_id = 'test-resume-incr-files';
		$this->manager->create( $import_id, 'site-123', 'incremental_files' );

		// Mark a file as completed in storage.
		$store = $this->manager->storage( $import_id );
		$store->mark_files_completed( array( 'file1.txt' => array( 'size' => 100, 'mtime' => 1000 ) ) );

		$state = array(
			'import_id'   => $import_id,
			'mode'        => 'incremental_files',
			'status'      => 'exporting_files',
			'db_progress' => array( 'completed_table_names' => array() ),
		);

		$remaining = $this->handler->get_remaining_work( $state );

		$this->assertFalse( $remaining['skip_files'] );
		$this->assertTrue( $remaining['skip_db'] );
		$this->assertContains( 'file1.txt', $remaining['completed_files'] );
	}

	/**
	 * get_remaining_work respects incremental_db mode.
	 */
	public function test_get_remaining_work_incremental_db(): void {
		$import_id = 'test-resume-incr-db';
		$this->manager->create( $import_id, 'site-123', 'incremental_db' );

		$state = array(
			'import_id'   => $import_id,
			'mode'        => 'incremental_db',
			'status'      => 'pending',
			'db_progress' => array( 'completed_table_names' => array( 'wp_posts' ) ),
		);

		$remaining = $this->handler->get_remaining_work( $state );

		$this->assertTrue( $remaining['skip_files'] );
		$this->assertFalse( $remaining['skip_db'] );
		$this->assertContains( 'wp_posts', $remaining['completed_tables'] );
	}
}
