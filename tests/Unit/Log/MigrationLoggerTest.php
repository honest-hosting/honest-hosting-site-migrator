<?php
/**
 * Tests for MigrationLogger.
 *
 * @package HonestHosting\SiteMigrator\Tests\Unit\Log
 */

namespace HonestHosting\SiteMigrator\Tests\Unit\Log;

use HonestHosting\SiteMigrator\Log\MigrationLogger;
use WP_UnitTestCase;

/**
 * Tests for migration event logging.
 */
class MigrationLoggerTest extends WP_UnitTestCase {

	/**
	 * Logger instance.
	 *
	 * @var MigrationLogger
	 */
	private MigrationLogger $logger;

	/**
	 * Set up — ensure log table exists.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->logger = new MigrationLogger();
		$this->create_log_table();
	}

	/**
	 * Clean up log entries.
	 */
	public function tear_down(): void {
		global $wpdb;
		$table = $this->logger->get_table_name();
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore
		parent::tear_down();
	}

	/**
	 * Log an event and retrieve it.
	 */
	public function test_log_and_retrieve(): void {
		$this->logger->log( '01TEST', 'preflight.started', 'Preflight checks starting.' );

		$entries = $this->logger->get_recent( 10 );
		$this->assertCount( 1, $entries );
		$this->assertEquals( '01TEST', $entries[0]->import_id );
		$this->assertEquals( 'preflight.started', $entries[0]->event );
		$this->assertEquals( 'Preflight checks starting.', $entries[0]->message );
	}

	/**
	 * Log with context.
	 */
	public function test_log_with_context(): void {
		$this->logger->log( '01TEST', 'failure', 'Upload failed.', array( 'chunk' => 5, 'error' => 'timeout' ) );

		$entries = $this->logger->get_recent( 10 );
		$this->assertCount( 1, $entries );

		$context = json_decode( $entries[0]->context, true );
		$this->assertEquals( 5, $context['chunk'] );
		$this->assertEquals( 'timeout', $context['error'] );
	}

	/**
	 * Filter by import ID.
	 */
	public function test_filter_by_import_id(): void {
		$this->logger->log( '01AAA', 'event.a', 'Message A' );
		$this->logger->log( '01BBB', 'event.b', 'Message B' );
		$this->logger->log( '01AAA', 'event.c', 'Message C' );

		$entries = $this->logger->get_recent( 10, '01AAA' );
		$this->assertCount( 2, $entries );
	}

	/**
	 * get_recent respects limit.
	 */
	public function test_get_recent_limit(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->logger->log( '01TEST', "event.{$i}", "Message {$i}" );
		}

		$entries = $this->logger->get_recent( 5 );
		$this->assertCount( 5, $entries );
	}

	/**
	 * get_all returns all entries.
	 */
	public function test_get_all(): void {
		$this->logger->log( '01A', 'e1', 'M1' );
		$this->logger->log( '01B', 'e2', 'M2' );

		$entries = $this->logger->get_all();
		$this->assertCount( 2, $entries );
	}

	/**
	 * Create the log table for testing.
	 */
	private function create_log_table(): void {
		global $wpdb;

		$table           = $this->logger->get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			import_id VARCHAR(26) DEFAULT '' NOT NULL,
			event VARCHAR(100) DEFAULT '' NOT NULL,
			message TEXT NOT NULL,
			context LONGTEXT DEFAULT '' NOT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY import_id (import_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
