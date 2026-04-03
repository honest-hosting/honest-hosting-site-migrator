<?php
/**
 * Tests for SessionManager.
 *
 * @package HonestHosting\SiteMigrator\Tests\Unit\Migration
 */

namespace HonestHosting\SiteMigrator\Tests\Unit\Migration;

use HonestHosting\SiteMigrator\Migration\SessionManager;
use WP_UnitTestCase;

/**
 * Tests for session state persistence and lock management.
 */
class SessionManagerTest extends WP_UnitTestCase {

	/**
	 * Session manager instance.
	 *
	 * @var SessionManager
	 */
	private SessionManager $manager;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->manager = new SessionManager();
	}

	/**
	 * Clean up test session files.
	 */
	public function tear_down(): void {
		// Clean up any test sessions.
		$dir = $this->manager->get_sessions_dir();
		if ( is_dir( $dir ) ) {
			$files = glob( $dir . '/test-*.json' );
			if ( $files ) {
				array_map( 'unlink', $files );
			}
		}
		parent::tear_down();
	}

	/**
	 * Create and load a session.
	 */
	public function test_create_and_load(): void {
		$state = $this->manager->create( 'test-001', 'site-001', 'full', 2097152 );

		$this->assertEquals( 'test-001', $state['import_id'] );
		$this->assertEquals( 'pending', $state['status'] );

		$loaded = $this->manager->load( 'test-001' );
		$this->assertNotNull( $loaded );
		$this->assertEquals( 'test-001', $loaded['import_id'] );
		$this->assertEquals( 'site-001', $loaded['destination_site_id'] );
		$this->assertEquals( 'full', $loaded['mode'] );
		$this->assertEquals( 2097152, $loaded['chunk_size_bytes'] );
	}

	/**
	 * Load returns null for non-existent session.
	 */
	public function test_load_returns_null_for_missing(): void {
		$this->assertNull( $this->manager->load( 'nonexistent-id' ) );
	}

	/**
	 * Update merges fields.
	 */
	public function test_update_merges_fields(): void {
		$this->manager->create( 'test-002', 'site-001', 'full', 2097152 );

		$this->manager->update( 'test-002', array( 'status' => 'exporting_files' ) );

		$loaded = $this->manager->load( 'test-002' );
		$this->assertEquals( 'exporting_files', $loaded['status'] );
		$this->assertEquals( 'test-002', $loaded['import_id'] ); // Preserved.
	}

	/**
	 * Acquire and release lock.
	 */
	public function test_lock_acquire_and_release(): void {
		$this->manager->create( 'test-003', 'site-001', 'full', 2097152 );

		$this->assertTrue( $this->manager->acquire_lock( 'test-003' ) );
		$this->assertTrue( $this->manager->is_locked( 'test-003' ) );

		// Second acquire should fail.
		$this->assertFalse( $this->manager->acquire_lock( 'test-003' ) );

		$this->assertTrue( $this->manager->release_lock( 'test-003' ) );
		$this->assertFalse( $this->manager->is_locked( 'test-003' ) );

		// Now acquire should succeed again.
		$this->assertTrue( $this->manager->acquire_lock( 'test-003' ) );
	}

	/**
	 * List all sessions.
	 */
	public function test_list_all(): void {
		$this->manager->create( 'test-004', 'site-001', 'full', 2097152 );
		$this->manager->create( 'test-005', 'site-002', 'incremental_all', 2097152 );

		$sessions = $this->manager->list_all();
		$ids      = array_column( $sessions, 'import_id' );

		$this->assertContains( 'test-004', $ids );
		$this->assertContains( 'test-005', $ids );
	}

	/**
	 * Find incomplete session.
	 */
	public function test_find_incomplete(): void {
		$this->manager->create( 'test-006', 'site-001', 'full', 2097152 );

		$found = $this->manager->find_incomplete( 'site-001' );
		$this->assertNotNull( $found );
		$this->assertEquals( 'test-006', $found['import_id'] );

		// Completed session should not be found.
		$this->manager->update( 'test-006', array( 'status' => 'completed' ) );
		$found = $this->manager->find_incomplete( 'site-001' );
		$this->assertNull( $found );
	}

	/**
	 * Delete a session.
	 */
	public function test_delete(): void {
		$this->manager->create( 'test-007', 'site-001', 'full', 2097152 );

		$this->assertNotNull( $this->manager->load( 'test-007' ) );

		$this->assertTrue( $this->manager->delete( 'test-007' ) );
		$this->assertNull( $this->manager->load( 'test-007' ) );
	}
}
