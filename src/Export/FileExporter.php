<?php
/**
 * File exporter — wp-content recursive scan and chunked export.
 *
 * @package HonestHosting\SiteMigrator\Export
 */

namespace HonestHosting\SiteMigrator\Export;

defined( 'ABSPATH' ) || exit;

use HonestHosting\SiteMigrator\Api\S3Uploader;
use HonestHosting\SiteMigrator\Log\MigrationLogger;
use HonestHosting\SiteMigrator\Migration\SessionManager;
use HonestHosting\SiteMigrator\Util\FormatHelper;
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
	 * Logger.
	 *
	 * @var MigrationLogger
	 */
	private MigrationLogger $logger;

	/**
	 * Wp-content path override for testing.
	 *
	 * @var string|null
	 */
	private ?string $wp_content_path;

	/**
	 * Constructor.
	 *
	 * @param S3Uploader      $uploader         S3 uploader.
	 * @param ChunkEncoder    $encoder          Chunk encoder.
	 * @param SessionManager  $session_manager  Session manager.
	 * @param MigrationLogger $logger           Logger.
	 * @param string|null     $wp_content_path  Override path for testing.
	 */
	public function __construct(
		S3Uploader $uploader,
		ChunkEncoder $encoder,
		SessionManager $session_manager,
		MigrationLogger $logger,
		?string $wp_content_path = null
	) {
		$this->uploader        = $uploader;
		$this->encoder         = $encoder;
		$this->session_manager = $session_manager;
		$this->logger          = $logger;
		$this->wp_content_path = $wp_content_path;
	}

	/**
	 * Scan wp-content and build a file manifest.
	 *
	 * Uses stat() only (size + mtime) — no file content is read during the scan.
	 *
	 * @param string $import_id Import session ULID (for progress logging).
	 * @return array<string, array{path: string, size: int, mtime: int}> Keyed by relative path.
	 */
	public function scan( string $import_id = '' ): array {
		$wp_content = $this->wp_content_path ?? WP_CONTENT_DIR;
		$manifest   = array();
		$state_dir  = $this->get_state_dir();
		$count      = 0;

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

			$manifest[ $relative_path ] = array(
				'path'  => $relative_path,
				'size'  => (int) $file->getSize(),
				'mtime' => (int) $file->getMTime(),
			);

			// Reset execution timer and log progress periodically.
			++$count;
			if ( 0 === $count % 2500 ) {
				if ( function_exists( 'set_time_limit' ) ) {
					// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Reset PHP execution timer to prevent timeout during long-running file scans on shared hosting with low max_execution_time limits.
					set_time_limit( max( 60, (int) ini_get( 'max_execution_time' ) ) );
				}
				$this->logger->log( $import_id, 'file_scan.progress', sprintf( 'Scanned %d files...', $count ) );
			}
		}

		return $manifest;
	}

	/**
	 * Determine which files have changed since the previous session.
	 *
	 * Uses rsync-style size+mtime comparison — no file content is read.
	 *
	 * @param array<string, array{path: string, size: int, mtime: int}> $current_manifest Current scan.
	 * @param array<string, array{size: int, mtime: int}>               $previous_meta    Previous session file metadata.
	 * @return array<string, array{path: string, size: int, mtime: int}> Changed or new files only.
	 */
	public function diff( array $current_manifest, array $previous_meta ): array {
		$changed = array();

		foreach ( $current_manifest as $path => $entry ) {
			$prev = $previous_meta[ $path ] ?? null;
			if ( null === $prev || $prev['size'] !== $entry['size'] || $prev['mtime'] !== $entry['mtime'] ) {
				$changed[ $path ] = $entry;
			}
		}

		return $changed;
	}

	/**
	 * Export files to S3 using buffered chunking.
	 *
	 * Small files are bundled together into chunks up to chunk_size.
	 * Large files that exceed chunk_size are split across multiple chunks.
	 *
	 * @param string                                                    $import_id       Import session ULID.
	 * @param array<string, array{path: string, size: int, mtime: int}> $manifest        Files to export.
	 * @param array<string>                                             $skip_paths      Paths already completed.
	 * @param int                                                       $chunk_size      Chunk size in bytes.
	 * @return true|WP_Error
	 */
	public function export( string $import_id, array $manifest, array $skip_paths, int $chunk_size ) {
		$wp_content  = $this->wp_content_path ?? WP_CONTENT_DIR;
		$chunk_index = $this->get_next_chunk_index( $import_id );
		$total       = count( $manifest );
		$skip_set    = array_flip( $skip_paths );

		// Estimate total chunks.
		$total_bytes      = array_sum( array_column( $manifest, 'size' ) );
		$estimated_chunks = max( 1, (int) ceil( $total_bytes / $chunk_size ) );

		$this->logger->log(
			$import_id,
			'file_export.started',
			sprintf( 'Starting file export: %d files, %s total, ~%d chunks (%s each).', $total, FormatHelper::format_bytes( $total_bytes ), $estimated_chunks, FormatHelper::format_bytes( $chunk_size ) )
		);

		// Update session with file totals.
		$this->session_manager->update(
			$import_id,
			array(
				'status'        => 'exporting_files',
				'file_progress' => array(
					'total_files'     => $total,
					'completed_files' => count( $skip_paths ),
					'current_file'    => null,
					'total_bytes'     => $total_bytes,
					'uploaded_bytes'  => 0,
				),
			)
		);

		$ctx = array(
			'wp_content'       => $wp_content,
			'chunk_size'       => $chunk_size,
			'chunk_index'      => $chunk_index,
			'buffer'           => '',
			'buffer_entries'   => array(),
			'completed'        => 0,
			'completed_paths'  => $skip_paths,
			'uploaded_bytes'   => 0,
			'hashes'           => array(),
			'chunk_refs'       => array(),
			'files_since_save' => 0,
		);

		foreach ( $manifest as $relative_path => $entry ) {
			if ( isset( $skip_set[ $relative_path ] ) ) {
				++$ctx['completed'];
				continue;
			}

			$result = $this->export_file( $import_id, $relative_path, $entry, $ctx );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Batch session updates every 50 files to reduce I/O.
			if ( $ctx['files_since_save'] >= 50 ) {
				// Check for cancellation before saving.
				if ( $this->session_manager->is_cancelled( $import_id ) ) {
					return new WP_Error( 'hh_migrator_cancelled', __( 'Migration was cancelled.', 'honest-hosting-site-migrator' ) );
				}

				$this->save_progress( $import_id, $ctx['uploaded_bytes'], $ctx['hashes'], $ctx['chunk_refs'] );
				$ctx['files_since_save'] = 0;
				$ctx['chunk_refs']       = array();
				$ctx['hashes']           = array();
			}
		}

		// Flush any remaining data in the buffer.
		if ( '' !== $ctx['buffer'] ) {
			$flush_result = $this->flush_chunk( $import_id, $ctx['chunk_index'], $ctx['buffer'], $ctx['buffer_entries'], $ctx['chunk_refs'] );
			if ( is_wp_error( $flush_result ) ) {
				return $flush_result;
			}
		}

		// Final progress save.
		if ( $ctx['files_since_save'] > 0 || ! empty( $ctx['chunk_refs'] ) ) {
			$this->save_progress( $import_id, $ctx['uploaded_bytes'], $ctx['hashes'], $ctx['chunk_refs'] );
		}

		$this->logger->log( $import_id, 'file_export.completed', sprintf( 'File export completed: %d files, %s uploaded.', $ctx['completed'], FormatHelper::format_bytes( $ctx['uploaded_bytes'] ) ) );

		return true;
	}

	/**
	 * Export a single file into the chunk buffer.
	 *
	 * @param string                                     $import_id     Import session ULID.
	 * @param string                                     $relative_path Relative file path.
	 * @param array{path: string, size: int, mtime: int} $entry     Manifest entry.
	 * @param array<string, mixed>                       &$ctx          Export context (buffer, indices, counters).
	 * @return true|WP_Error
	 */
	private function export_file( string $import_id, string $relative_path, array $entry, array &$ctx ) {
		$full_path = $ctx['wp_content'] . '/' . $relative_path;
		if ( ! file_exists( $full_path ) || ! is_readable( $full_path ) ) {
			return true;
		}

		$file_size  = $entry['size'];
		$chunk_size = $ctx['chunk_size'];

		// Large files: flush buffer then stream.
		if ( $file_size > $chunk_size ) {
			if ( '' !== $ctx['buffer'] ) {
				$flush_result = $this->flush_chunk( $import_id, $ctx['chunk_index'], $ctx['buffer'], $ctx['buffer_entries'], $ctx['chunk_refs'] );
				if ( is_wp_error( $flush_result ) ) {
					return $flush_result;
				}
				++$ctx['chunk_index'];
				$ctx['buffer']         = '';
				$ctx['buffer_entries'] = array();
			}

			$result = $this->export_large_file( $import_id, $full_path, $relative_path, $file_size, $chunk_size, $ctx['chunk_index'], $ctx['chunk_refs'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$ctx['chunk_index'] = $result;
		} else {
			$file_data = file_get_contents( $full_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $file_data ) {
				return true;
			}

			$ctx['buffer']          .= $file_data;
			$ctx['buffer_entries'][] = array(
				'path'          => $relative_path,
				'source_offset' => 0,
				'size'          => strlen( $file_data ),
			);

			if ( strlen( $ctx['buffer'] ) >= $chunk_size ) {
				$flush_result = $this->flush_chunk( $import_id, $ctx['chunk_index'], $ctx['buffer'], $ctx['buffer_entries'], $ctx['chunk_refs'] );
				if ( is_wp_error( $flush_result ) ) {
					return $flush_result;
				}
				++$ctx['chunk_index'];
				$ctx['buffer']         = '';
				$ctx['buffer_entries'] = array();
			}
		}

		++$ctx['completed'];
		$ctx['uploaded_bytes']          += $file_size;
		$ctx['completed_paths'][]        = $relative_path;
		$ctx['hashes'][ $relative_path ] = array(
			'size'  => $entry['size'],
			'mtime' => $entry['mtime'],
		);
		++$ctx['files_since_save'];

		return true;
	}

	/**
	 * Export a large file by streaming it in chunk_size pieces.
	 *
	 * @param string                           $import_id   Import session ULID.
	 * @param string                           $full_path   Absolute file path.
	 * @param string                           $relative    Relative path.
	 * @param int                              $file_size   File size in bytes.
	 * @param int                              $chunk_size  Chunk size in bytes.
	 * @param int                              $chunk_index Starting chunk index.
	 * @param array<int, array<string, mixed>> &$chunk_refs  Chunk references accumulator.
	 * @return int|WP_Error Next chunk index on success.
	 */
	private function export_large_file( string $import_id, string $full_path, string $relative, int $file_size, int $chunk_size, int $chunk_index, array &$chunk_refs ) {
		$handle = fopen( $full_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return $chunk_index;
		}

		$this->logger->log( $import_id, 'file_export.large_file', sprintf( 'Streaming large file: %s (%s)', $relative, FormatHelper::format_bytes( $file_size ) ) );

		$source_offset = 0;
		while ( ! feof( $handle ) ) {
			$raw = fread( $handle, max( 1, $chunk_size ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $raw || '' === $raw ) {
				break;
			}

			$entries = array(
				array(
					'path'          => $relative,
					'source_offset' => $source_offset,
					'size'          => strlen( $raw ),
				),
			);

			$flush_result = $this->flush_chunk( $import_id, $chunk_index, $raw, $entries, $chunk_refs );
			if ( is_wp_error( $flush_result ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return $flush_result;
			}

			$source_offset += strlen( $raw );
			++$chunk_index;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $chunk_index;
	}

	/**
	 * Encode and upload a buffered chunk.
	 *
	 * @param string                                                         $import_id   Import session ULID.
	 * @param int                                                            $chunk_index Zero-based chunk index.
	 * @param string                                                         $data        Concatenated raw data.
	 * @param array<int, array{path: string, source_offset: int, size: int}> $entries     File entries in this chunk.
	 * @param array<int, array<string, mixed>>                               &$chunk_refs  Chunk references accumulator.
	 * @return true|WP_Error
	 */
	private function flush_chunk( string $import_id, int $chunk_index, string $data, array $entries, array &$chunk_refs ) {
		$data_size = strlen( $data );

		$encoded = $this->encoder->encode_multi( $data, $import_id, $chunk_index, 'file', $entries );

		$this->logger->log(
			$import_id,
			'file_export.chunk_upload',
			sprintf(
				'Uploading chunk-%d to S3 (%s raw, %s encoded, %d files).',
				$chunk_index,
				FormatHelper::format_bytes( $data_size ),
				FormatHelper::format_bytes( strlen( $encoded['data'] ) ),
				count( $entries )
			)
		);

		// Refresh the session lock immediately before the S3 upload so the UI's
		// "stalled" detector doesn't trip when a single chunk takes longer than
		// LOCK_TTL on a slow uplink (e.g. 10MB chunk over a constrained connection).
		$this->session_manager->refresh_lock( $import_id );

		$result = $this->uploader->upload_chunk(
			$import_id,
			$chunk_index,
			$encoded['data'],
			'application/octet-stream',
			$encoded['compressed']
		);

		if ( is_wp_error( $result ) ) {
			$this->logger->log( $import_id, 'file_export.chunk_error', sprintf( 'Failed to upload chunk-%d: %s', $chunk_index, $result->get_error_message() ), array(), 'ERROR' );
			$this->session_manager->update(
				$import_id,
				array( 'last_error' => $result->get_error_message() )
			);
			return $result;
		}

		$chunk_refs[] = array_merge( $encoded['metadata'], $result );

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Reset PHP execution timer after each chunk upload to prevent timeout when exporting many files on hosts with low max_execution_time.
			set_time_limit( max( 60, (int) ini_get( 'max_execution_time' ) ) );
		}

		return true;
	}

	/**
	 * Batch-save progress to storage.
	 *
	 * @param string                                      $import_id      Import session ULID.
	 * @param int                                         $uploaded_bytes Total uploaded bytes.
	 * @param array<string, array{size: int, mtime: int}> $file_meta New file metadata to persist.
	 * @param array<int, array<string, mixed>>            $new_refs       New chunk references.
	 * @return void
	 */
	private function save_progress( string $import_id, int $uploaded_bytes, array $file_meta, array $new_refs ): void {
		$store = $this->session_manager->storage( $import_id );

		$this->logger->log( $import_id, 'file_export.save_progress', sprintf( 'Saving progress: %d files, %d chunk refs, %s read', count( $file_meta ), count( $new_refs ), FormatHelper::format_bytes( $uploaded_bytes ) ) );

		// Persist file metadata (marks files completed in storage).
		if ( ! empty( $file_meta ) ) {
			$store->mark_files_completed( $file_meta );
		}

		// Persist chunk references.
		if ( ! empty( $new_refs ) ) {
			$store->add_chunk_refs( $new_refs );
		}

		// Update scalar progress values.
		$store->set_many(
			array(
				'uploaded_bytes' => $uploaded_bytes,
				'current_file'   => null,
				'updated_at'     => gmdate( 'c' ),
			) 
		);

		$store->refresh_lock();
	}

	/**
	 * Get the next chunk index from storage.
	 *
	 * @param string $import_id Import session ULID.
	 * @return int
	 */
	private function get_next_chunk_index( string $import_id ): int {
		return $this->session_manager->storage( $import_id )->get_chunk_count();
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
