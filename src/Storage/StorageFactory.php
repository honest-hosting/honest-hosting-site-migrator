<?php
/**
 * Storage factory — selects SQLite3 or MySQL based on availability.
 *
 * @package HonestHosting\SiteMigrator\Storage
 */

namespace HonestHosting\SiteMigrator\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the appropriate storage backend.
 */
class StorageFactory {

	/**
	 * Create a storage instance for the given import session.
	 *
	 * Prefers SQLite3 when available, falls back to MySQL.
	 *
	 * @param string $import_id Import session ULID.
	 * @return SessionStorageInterface
	 */
	public static function create( string $import_id ): SessionStorageInterface {
		if ( class_exists( 'SQLite3' ) ) {
			$upload_dir   = wp_upload_dir();
			$sessions_dir = $upload_dir['basedir'] . '/hh-migrator/sessions';
			return new SqliteStorage( $sessions_dir, $import_id );
		}

		return new MysqlStorage( $import_id );
	}

	/**
	 * Check if SQLite3 is available.
	 *
	 * @return bool
	 */
	public static function is_sqlite_available(): bool {
		return class_exists( 'SQLite3' );
	}
}
