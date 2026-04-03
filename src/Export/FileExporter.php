<?php
/**
 * File exporter — wp-content recursive scan and chunked export.
 *
 * @package HonestHosting\SiteMigrator\Export
 */

namespace HonestHosting\SiteMigrator\Export;

defined( 'ABSPATH' ) || exit;

use HonestHosting\SiteMigrator\Api\S3Uploader;
use HonestHosting\SiteMigrator\Migration\SessionManager;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WP_Error;

/**
 * Scans wp-content, builds a file manifest, and uploads chunks to S3.
 */
class FileExporter {

	/**
	 * S3 uploader.
	 *
	 * @var S3Uploader
	 */
	private S3Uploader $uploader;

	/**
	 * Chunk encoder.
	 *
	 * @var ChunkEncoder
	 */
	private ChunkEncoder $encoder;

	/**
	 * Session manager.
	 *
	 * @var SessionManager
	 */
	private SessionManager $session_manager;

	/**
	 * Wp-content path override for testing.
	 *
	 * @var string|null
	 */
	private ?string $wp_content_path;

	/**
	 * Constructor.
	 *
	 * @param S3Uploader     $uploader         S3 uploader.
	 * @param ChunkEncoder   $encoder          Chunk encoder.
	 * @param SessionManager $session_manager  Session manager.
	 * @param string|null    $wp_content_path  Override path for testing.
	 */
	public function __construct(
		S3Uploader $uploader,
		ChunkEncoder $encoder,
		SessionManager $session_manager,
		?string $wp_content_path = null
	) {
		$this->uploader        = $uploader;
		$this->encoder         = $encoder;
		$this->session_manager = $session_manager;
		$this->wp_content_path = $wp_content_path;
	}

	/**
	 * Scan wp-content and build a file manifest.
	 *
	 * @return array<string, array{path: string, size: int, hash: string}> Keyed by relative path.
	 */
	public function scan(): array {
		$wp_content = $this->wp_content_path ?? WP_CONTENT_DIR;
		$manifest   = array();
		$state_dir  = $this->get_state_dir();

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $wp_content, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$real_path = $file->getRealPath();
			if ( false === $real_path ) {
				continue;
			}

			// Exclude plugin state directory.
			if ( null !== $state_dir && str_starts_with( $real_path, $state_dir ) ) {
				continue;
			}

			$relative_path = (string) str_replace( $wp_content . '/', '', $real_path );
			$hash          = md5_file( $real_path );
			if ( false === $hash ) {
				continue;
			}

			$manifest[ $relative_path ] = array(
				'path' => $relative_path,
				'size' => (int) $file->getSize(),
				'hash' => $hash,
			);
		}

		return $manifest;
	}

	/**
	 * Determine which files have changed since the previous session.
	 *
	 * @param array<string, array{path: string, size: int, hash: string}> $current_manifest  Current scan.
	 * @param array<string, string>                                       $previous_hashes   Previous manifest hashes (path => hash).
	 * @return array<string, array{path: string, size: int, hash: string}> Changed files only.
	 */
	public function diff( array $current_manifest, array $previous_hashes ): array {
		$changed = array();

		foreach ( $current_manifest as $path => $entry ) {
			if ( ! isset( $previous_hashes[ $path ] ) || $previous_hashes[ $path ] !== $entry['hash'] ) {
				$changed[ $path ] = $entry;
			}
		}

		return $changed;
	}

	/**
	 * Export files to S3.
	 *
	 * @param string                                                      $import_id       Import session ULID.
	 * @param array<string, array{path: string, size: int, hash: string}> $manifest        Files to export.
	 * @param array<string>                                               $skip_paths      Paths already completed.
	 * @param int                                                         $chunk_size      Chunk size in bytes.
	 * @return true|WP_Error
	 */
	public function export( string $import_id, array $manifest, array $skip_paths, int $chunk_size ) {
		$wp_content  = $this->wp_content_path ?? WP_CONTENT_DIR;
		$chunk_index = $this->get_next_chunk_index( $import_id );
		$completed   = 0;
		$total       = count( $manifest );

		// Update session with file totals.
		$total_bytes = array_sum( array_column( $manifest, 'size' ) );
		$this->session_manager->update(
			$import_id,
			array(
				'status'        => 'exporting_files',
				'file_progress' => array(
					'total_files'          => $total,
					'completed_files'      => count( $skip_paths ),
					'current_file'         => null,
					'completed_file_paths' => $skip_paths,
					'total_bytes'          => $total_bytes,
					'uploaded_bytes'       => 0,
				),
			)
		);

		foreach ( $manifest as $relative_path => $entry ) {
			if ( in_array( $relative_path, $skip_paths, true ) ) {
				++$completed;
				continue;
			}

			$full_path = $wp_content . '/' . $relative_path;
			if ( ! file_exists( $full_path ) || ! is_readable( $full_path ) ) {
				continue;
			}

			// Update current file in session.
			$this->session_manager->update(
				$import_id,
				array(
					'file_progress' => array_merge(
						$this->session_manager->load( $import_id )['file_progress'] ?? array(),
						array( 'current_file' => $relative_path )
					),
				) 
			);

			// Read and upload in chunks.
			$handle = fopen( $full_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( false === $handle ) {
				continue;
			}

			$offset = 0;
			while ( ! feof( $handle ) ) {
				$raw = fread( $handle, max( 1, $chunk_size ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( false === $raw || '' === $raw ) {
					break;
				}

				$encoded = $this->encoder->encode( $raw, $import_id, $chunk_index, $relative_path, 'file', $offset );

				$result = $this->uploader->upload_chunk(
					$import_id,
					$chunk_index,
					$encoded['data'],
					'application/octet-stream',
					$encoded['compressed']
				);

				if ( is_wp_error( $result ) ) {
					fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					$this->session_manager->update(
						$import_id,
						array(
							'last_error' => $result->get_error_message(),
						) 
					);
					return $result;
				}

				// Record chunk reference.
				$state  = $this->session_manager->load( $import_id );
				$refs   = $state['chunk_references'] ?? array();
				$refs[] = array_merge( $encoded['metadata'], $result );
				$this->session_manager->update(
					$import_id,
					array(
						'chunk_references' => $refs,
					) 
				);

				$offset += strlen( $raw );
				++$chunk_index;

				// Refresh lock.
				$this->session_manager->refresh_lock( $import_id );
			}

			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

			++$completed;

			// Update file progress.
			$state                      = $this->session_manager->load( $import_id );
			$fp                         = $state['file_progress'] ?? array();
			$fp['completed_files']      = $completed;
			$fp['completed_file_paths'] = array_merge( $fp['completed_file_paths'] ?? array(), array( $relative_path ) );
			$fp['uploaded_bytes']       = ( $fp['uploaded_bytes'] ?? 0 ) + $entry['size'];
			$fp['current_file']         = null;

			// Store manifest hash for future incremental diffs.
			$hashes                   = $state['file_manifest_hashes'] ?? array();
			$hashes[ $relative_path ] = $entry['hash'];

			$this->session_manager->update(
				$import_id,
				array(
					'file_progress'        => $fp,
					'file_manifest_hashes' => $hashes,
				) 
			);
		}

		return true;
	}

	/**
	 * Get the next chunk index based on existing references.
	 *
	 * @param string $import_id Import session ULID.
	 * @return int
	 */
	private function get_next_chunk_index( string $import_id ): int {
		$state = $this->session_manager->load( $import_id );
		$refs  = $state['chunk_references'] ?? array();
		return count( $refs );
	}

	/**
	 * Get the plugin state directory path.
	 *
	 * @return string|null
	 */
	private function get_state_dir(): ?string {
		$upload_dir = wp_upload_dir();
		$state_dir  = $upload_dir['basedir'] . '/hh-migrator';
		return is_dir( $state_dir ) ? $state_dir : null;
	}
}
