<?php
/**
 * JSON manifest builder.
 *
 * @package HonestHosting\SiteMigrator\Migration
 */

namespace HonestHosting\SiteMigrator\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the migration manifest from session state.
 */
class ManifestBuilder {

	/**
	 * Build the manifest from a completed session.
	 *
	 * @param array<string, mixed> $state Session state.
	 * @return array<string, mixed> Manifest data.
	 */
	public function build( array $state ): array {
		$chunks = $state['chunk_references'] ?? array();

		return array(
			'import_id'           => $state['import_id'] ?? '',
			'destination_site_id' => $state['destination_site_id'] ?? '',
			'source_site'         => $this->build_source_site_info(),
			'files'               => $this->build_file_entries( $state, $chunks ),
			'database'            => $this->build_database_entries( $state, $chunks ),
			'compression'         => function_exists( 'gzencode' ),
			'chunk_size_bytes'    => $state['chunk_size_bytes'] ?? 0,
			'totals'              => $this->build_totals( $state ),
			'chunk_references'    => $chunks,
		);
	}

	/**
	 * Build source site info.
	 *
	 * @return array<string, mixed>
	 */
	private function build_source_site_info(): array {
		global $wpdb;

		$info = array(
			'url'          => get_site_url(),
			'wp_version'   => get_bloginfo( 'version' ),
			'php_version'  => PHP_VERSION,
			'multisite'    => is_multisite(),
			// Source DB prefix. Destination restore rewrites table identifiers and
			// prefix-keyed rows in options/usermeta when this differs from the
			// destination's prefix.
			'table_prefix' => $wpdb->prefix,
		);

		if ( is_multisite() ) {
			$info['site_id'] = get_current_blog_id();
		}

		return $info;
	}

	/**
	 * Build file entries from session state.
	 *
	 * Maps each file to its S3 chunk keys by scanning chunk_references entries.
	 *
	 * @param array<string, mixed>             $state  Session state.
	 * @param array<int, array<string, mixed>> $chunks All chunk references.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_file_entries( array $state, array $chunks ): array {
		$entries   = array();
		$file_meta = $state['file_manifest_meta'] ?? array();
		$file_size = array();

		// Build a map of file path → list of S3 keys + total size from chunk entries.
		$file_chunks = array();
		foreach ( $chunks as $chunk ) {
			if ( ( $chunk['type'] ?? '' ) !== 'file' ) {
				continue;
			}

			$s3_key    = $chunk['s3_key'] ?? '';
			$c_entries = $chunk['entries'] ?? array();

			foreach ( $c_entries as $entry ) {
				$path = $entry['path'] ?? '';
				if ( '' === $path ) {
					continue;
				}

				if ( ! isset( $file_chunks[ $path ] ) ) {
					$file_chunks[ $path ] = array();
					$file_size[ $path ]   = 0;
				}

				// Only add the S3 key if not already listed (a file may span multiple entries in the same chunk).
				if ( ! in_array( $s3_key, $file_chunks[ $path ], true ) ) {
					$file_chunks[ $path ][] = $s3_key;
				}

				$file_size[ $path ] += (int) ( $entry['size'] ?? 0 );
			}
		}

		foreach ( $file_meta as $path => $meta ) {
			$entries[] = array(
				'path'   => $path,
				'size'   => $file_size[ $path ] ?? ( $meta['size'] ?? 0 ),
				'chunks' => $file_chunks[ $path ] ?? array(),
			);
		}

		return $entries;
	}

	/**
	 * Build database entries from session state.
	 *
	 * @param array<string, mixed>             $state  Session state.
	 * @param array<int, array<string, mixed>> $chunks All chunk references.
	 * @return array<string, mixed>
	 */
	private function build_database_entries( array $state, array $chunks ): array {
		$tables    = $state['db_progress']['completed_table_names'] ?? array();
		$db_chunks = array_filter(
			$chunks,
			fn( $chunk ) => ( $chunk['type'] ?? '' ) === 'database'
		);

		return array(
			'tables'     => $tables,
			'total_size' => 0,
			'chunks'     => array_values(
				array_map(
					fn( $chunk ) => $chunk['s3_key'] ?? '',
					$db_chunks
				)
			),
		);
	}

	/**
	 * Build summary totals.
	 *
	 * @param array<string, mixed> $state Session state.
	 * @return array<string, int>
	 */
	private function build_totals( array $state ): array {
		$file_progress = $state['file_progress'] ?? array();

		return array(
			'file_count'  => (int) ( $file_progress['completed_files'] ?? 0 ),
			'file_bytes'  => (int) ( $file_progress['uploaded_bytes'] ?? 0 ),
			'db_bytes'    => 0,
			'chunk_count' => count( $state['chunk_references'] ?? array() ),
		);
	}
}
