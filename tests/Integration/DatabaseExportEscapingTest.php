<?php
/**
 * Integration test for DatabaseExporter value escaping.
 *
 * Verifies that literal `%` characters (including `%s`, `%d`, `%1$s`, and
 * double-percent sequences) survive the SQL export → re-import round-trip
 * byte-identically. The DatabaseExporter previously avoided `$wpdb->_real_escape()`
 * out of a concern that it applied placeholder escaping; this test asserts the
 * actual behavior and guards against regressions if the escaping call is changed.
 *
 * @package HonestHosting\SiteMigrator\Tests\Integration
 */

namespace HonestHosting\SiteMigrator\Tests\Integration;

use HonestHosting\SiteMigrator\Api\HonestHostingClient;
use HonestHosting\SiteMigrator\Api\S3Uploader;
use HonestHosting\SiteMigrator\Export\ChunkEncoder;
use HonestHosting\SiteMigrator\Export\DatabaseExporter;
use HonestHosting\SiteMigrator\Log\MigrationLogger;
use HonestHosting\SiteMigrator\Migration\SessionManager;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * Round-trip payloads containing tricky characters through DatabaseExporter.
 */
class DatabaseExportEscapingTest extends WP_UnitTestCase {

	private string $source_table;
	private string $restore_table;
	private DatabaseExporter $exporter;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$this->source_table  = $wpdb->prefix . 'hh_escape_src';
		$this->restore_table = $wpdb->prefix . 'hh_escape_dst';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$this->source_table}`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$this->restore_table}`" );

		$wpdb->query(
			"CREATE TABLE `{$this->source_table}` (
				id INT AUTO_INCREMENT PRIMARY KEY,
				label VARCHAR(64) NOT NULL,
				body LONGTEXT NOT NULL
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);
		// phpcs:enable

		$exporter = $this->build_exporter();
		$this->exporter = $exporter;
	}

	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$this->source_table}`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$this->restore_table}`" );
		// phpcs:enable

		parent::tear_down();
	}

	/**
	 * Payloads that historically broke under various escape implementations.
	 *
	 * @return array<string, string>
	 */
	private function tricky_payloads(): array {
		return array(
			'bare_percent'         => 'price 50% off today',
			'wp_prepare_s'         => 'contains %s placeholder',
			'wp_prepare_d'         => 'contains %d placeholder',
			'wp_prepare_f'         => 'contains %f placeholder',
			'wp_prepare_indexed'   => 'indexed %1$s and %2$d style',
			'double_percent'       => '100%% uptime guaranteed',
			'consecutive_percents' => '%%%%%%%%',
			'mixed_percent_quote'  => "it's %s and it's 50% done",
			'single_quote'         => "O'Brien said \"hello\"",
			'double_quote'         => 'she said "hi" to %s',
			'backslash'            => 'C:\\Users\\%USERNAME%\\file',
			'null_byte'            => "before\0after%s",
			'newline'              => "line1\nline2\t%d",
			'backslash_percent'    => '\\%s \\%% end',
			'utf8'                 => 'café — 50% ☕ %s',
			'sql_keywords'         => "'; DROP TABLE wp_users; -- %s",
		);
	}

	/**
	 * Insert all tricky payloads into the source table.
	 *
	 * @return array<string, string> Map of label → body, matching what was inserted.
	 */
	private function seed_source_table(): array {
		global $wpdb;

		$payloads = $this->tricky_payloads();

		foreach ( $payloads as $label => $body ) {
			// Use $wpdb->insert() — this is how real WordPress data gets stored,
			// so we're testing with the same escaping path that would produce
			// production data.
			$result = $wpdb->insert(
				$this->source_table,
				array(
					'label' => $label,
					'body'  => $body,
				),
				array( '%s', '%s' )
			);
			$this->assertNotFalse( $result, "Failed to insert payload: {$label}" );
		}

		return $payloads;
	}

	/**
	 * Invoke the private export_table() method, returning the accumulated SQL buffer.
	 *
	 * Uses a very large chunk_size so no flush_chunk() calls happen — all SQL stays
	 * in the passed-by-reference buffer and is returned to the test.
	 */
	private function export_table_to_buffer( string $table_name ): string {
		$method = new ReflectionMethod( DatabaseExporter::class, 'export_table' );
		$method->setAccessible( true );

		$buffer      = '';
		$chunk_size  = 100 * 1024 * 1024; // 100 MB — ensures no mid-export flush.
		$import_id   = 'TEST-ESCAPE-' . uniqid();
		$chunk_index = 0;

		$result = $method->invokeArgs(
			$this->exporter,
			array( $import_id, $table_name, $chunk_size, $chunk_index, &$buffer )
		);

		$this->assertIsInt( $result, 'export_table should return an int chunk index' );

		return $buffer;
	}

	/**
	 * Execute the exported SQL against the database to recreate the data in a new table.
	 *
	 * The exported SQL references the source table name; we rewrite it to target the
	 * restore table so source and restored rows can be compared side-by-side.
	 */
	private function restore_sql_to_table( string $sql, string $source_table, string $restore_table ): void {
		global $wpdb;

		// Rewrite the DROP/CREATE/INSERT statements to hit the restore table.
		$rewritten = str_replace( "`{$source_table}`", "`{$restore_table}`", $sql );

		// Split on ";\n" — the exporter terminates each statement with `;\n`.
		$statements = array_filter(
			array_map( 'trim', explode( ";\n", $rewritten ) ),
			fn( $s ) => '' !== $s
		);

		foreach ( $statements as $stmt ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$ok = $wpdb->query( $stmt );
			$this->assertNotFalse(
				$ok,
				'Failed to replay exported statement: ' . substr( $stmt, 0, 200 ) . ' — error: ' . $wpdb->last_error
			);
		}
	}

	/**
	 * Build a DatabaseExporter suitable for reflection-driven tests.
	 *
	 * HTTP is filtered to a no-op 200 response so any accidental upload call
	 * during the test doesn't hit the network.
	 */
	private function build_exporter(): DatabaseExporter {
		add_filter(
			'pre_http_request',
			fn() => array(
				'response' => array( 'code' => 200 ),
				'body'     => '{}',
			),
			10,
			3
		);

		$encoder         = new ChunkEncoder( false );
		$client          = new HonestHostingClient( 'test-key' );
		$uploader        = new S3Uploader( $client );
		$session_manager = new SessionManager();
		$logger          = new MigrationLogger();

		return new DatabaseExporter( $uploader, $encoder, $session_manager, $logger );
	}

	/**
	 * INSERT values in the exported SQL must contain the literal `%` characters
	 * from the source data — unmodified. A broken escaper would either strip them,
	 * double them, or corrupt surrounding bytes.
	 */
	public function test_exported_sql_preserves_percent_characters_verbatim(): void {
		$this->seed_source_table();

		$sql = $this->export_table_to_buffer( $this->source_table );

		$this->assertNotSame( '', $sql, 'export_table produced empty SQL' );
		$this->assertStringContainsString( "INSERT INTO `{$this->source_table}`", $sql );

		// Spot-check the literal body strings survived as substrings of the dump.
		// We check both the "raw" form (what should be visible inside the quoted
		// SQL literal) and verify no stray backslash-percent or double-percent
		// corruption has occurred for bodies that don't contain backslashes.
		$this->assertStringContainsString( "'price 50% off today'", $sql );
		$this->assertStringContainsString( "'contains %s placeholder'", $sql );
		$this->assertStringContainsString( "'contains %d placeholder'", $sql );
		$this->assertStringContainsString( "'indexed %1\$s and %2\$d style'", $sql );
		$this->assertStringContainsString( "'100%% uptime guaranteed'", $sql );
		$this->assertStringContainsString( "'%%%%%%%%'", $sql );

		// `'` inside literals must be escaped to `\'` (the mysqli_* / _real_escape
		// output form). The raw character must not appear un-escaped inside a
		// value literal.
		$this->assertStringContainsString( "O\\'Brien", $sql );
	}

	/**
	 * End-to-end round-trip: export the source table's SQL, replay it against a
	 * restore table, and confirm every row's bytes match the original exactly.
	 *
	 * This is the real correctness test — if any escaping regression corrupts
	 * `%` handling, quote handling, or binary bytes, this will fail.
	 */
	public function test_round_trip_restores_original_bytes(): void {
		global $wpdb;

		$expected = $this->seed_source_table();

		$sql = $this->export_table_to_buffer( $this->source_table );
		$this->restore_sql_to_table( $sql, $this->source_table, $this->restore_table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT label, body FROM `{$this->restore_table}` ORDER BY id ASC",
			ARRAY_A
		);

		$this->assertCount( count( $expected ), $rows, 'Row count mismatch after restore' );

		$restored = array();
		foreach ( $rows as $row ) {
			$restored[ $row['label'] ] = $row['body'];
		}

		foreach ( $expected as $label => $body ) {
			$this->assertArrayHasKey( $label, $restored, "Missing label after restore: {$label}" );
			$this->assertSame(
				$body,
				$restored[ $label ],
				sprintf(
					"Payload '%s' was corrupted by export→restore. expected=%s actual=%s",
					$label,
					bin2hex( $body ),
					bin2hex( $restored[ $label ] )
				)
			);
		}
	}
}
