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
		return array(
			'import_id'           => $state['import_id'] ?? '',
			'destination_site_id' => $state['destination_site_id'] ?? '',
			'source_site'         => $this->build_source_site_info(),
			'files'               => $this->build_file_entries( $state ),
			'database'            => $this->build_database_entries( $state ),
			'compression'         => function_exists( 'gzencode' ),
			'chunk_size_bytes'    => $state['chunk_size_bytes'] ?? 0,
			'totals'              => $this->build_totals( $state ),
		);
	}

	/**
	 * Build source site info.
	 *
	 * @return array<string, mixed>
	 */
	private function build_source_site_info(): array {
		$info = array(
			'url'         => get_site_url(),
			'wp_version'  => get_bloginfo( 'version' ),
			'php_version' => PHP_VERSION,
			'multisite'   => is_multisite(),
		);

		if ( is_multisite() ) {
			$info['site_id'] = get_current_blog_id();
		}

		return $info;
	}

	/**
	 * Build file entries from session state.
	 *
	 * @param array<string, mixed> $state Session state.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_file_entries( array $state ): array {
		$entries = array();
		$hashes  = $state['file_manifest_hashes'] ?? array();
		$chunks  = $state['chunk_references'] ?? array();

		foreach ( $hashes as $path => $hash ) {
			$file_chunks = array_filter(
				$chunks,
				fn( $chunk ) => ( $chunk['source_path'] ?? '' ) === $path
			);

			$entries[] = array(
				'path'   => $path,
				'hash'   => $hash,
				'size'   => 0, // Size is tracked at chunk level.
				'chunks' => array_values(
					array_map(
						fn( $chunk ) => $chunk['s3_key'] ?? '',
						$file_chunks
					) 
				),
			);
		}

		return $entries;
	}

	/**
	 * Build database entries from session state.
	 *
	 * @param array<string, mixed> $state Session state.
	 * @return array<string, mixed>
	 */
	private function build_database_entries( array $state ): array {
		$tables    = $state['db_progress']['completed_table_names'] ?? array();
		$chunks    = $state['chunk_references'] ?? array();
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
