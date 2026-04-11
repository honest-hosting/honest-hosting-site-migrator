<?php
/**
 * Structured migration event logger.
 *
 * @package HonestHosting\SiteMigrator\Log
 */

namespace HonestHosting\SiteMigrator\Log;

defined( 'ABSPATH' ) || exit;

use DateTime;
use DateTimeZone;

/**
 * Logs migration events to a custom database table.
 */
class MigrationLogger {

	/**
	 * Get the log table name.
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'hh_migrator_log';
	}

	/**
	 * Log an event.
	 *
	 * @param string               $import_id Import session ULID (empty for non-session events).
	 * @param string               $event     Event identifier (e.g. 'preflight.started').
	 * @param string               $message   Human-readable message.
	 * @param array<string, mixed> $context   Additional context data.
	 * @param string               $level     Log level: INFO, WARN, or ERROR.
	 * @return void
	 */
	public function log( string $import_id, string $event, string $message, array $context = array(), string $level = 'INFO' ): void {
		global $wpdb;

		$table = $this->get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'import_id'  => $import_id,
				'level'      => $level,
				'event'      => $event,
				'message'    => $message,
				'context'    => wp_json_encode( $context ),
				'created_at' => ( new DateTime( 'now', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s.v' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get recent log entries.
	 *
	 * @param int         $limit     Maximum entries to return.
	 * @param string|null $import_id Filter by import ID.
	 * @return list<object{id: string, import_id: string, level: string, event: string, message: string, context: string, created_at: string}>
	 */
	public function get_recent( int $limit = 100, ?string $import_id = null ): array {
		global $wpdb;

		$table = $this->get_table_name();

		if ( null !== $import_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE import_id = %s ORDER BY created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$import_id,
					$limit
				)
			);
			return is_array( $results ) ? $results : array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			)
		);
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get a page of log entries.
	 *
	 * @param int $limit  Number of entries per page.
	 * @param int $offset Offset from the start.
	 * @return list<object{id: string, import_id: string, level: string, event: string, message: string, context: string, created_at: string}>
	 */
	public function get_page( int $limit, int $offset ): array {
		global $wpdb;

		$table = $this->get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit,
				$offset
			)
		);
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get total count of log entries.
	 *
	 * @return int
	 */
	public function get_count(): int {
		global $wpdb;

		$table = $this->get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Clear all log entries.
	 *
	 * @return void
	 */
	public function clear(): void {
		global $wpdb;

		$table = $this->get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Get all log entries (for debug download).
	 *
	 * @return list<object{id: string, import_id: string, level: string, event: string, message: string, context: string, created_at: string}>
	 */
	public function get_all(): array {
		global $wpdb;

		$table = $this->get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			"SELECT * FROM {$table} ORDER BY created_at DESC" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return is_array( $results ) ? $results : array();
	}
}
