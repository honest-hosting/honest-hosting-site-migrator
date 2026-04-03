<?php
/**
 * Chunk compression and framing.
 *
 * @package HonestHosting\SiteMigrator\Export
 */

namespace HonestHosting\SiteMigrator\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Encodes chunks with optional gzip compression and metadata.
 */
class ChunkEncoder {

	/**
	 * Whether compression is available.
	 *
	 * @var bool
	 */
	private bool $compression_available;

	/**
	 * Constructor.
	 *
	 * @param bool|null $compression_override Override for testing.
	 */
	public function __construct( ?bool $compression_override = null ) {
		$this->compression_available = $compression_override ?? function_exists( 'gzencode' );
	}

	/**
	 * Encode a chunk.
	 *
	 * @param string $data        Raw chunk data.
	 * @param string $import_id   Import session ULID.
	 * @param int    $chunk_index Zero-based chunk index.
	 * @param string $source_path Source file path (relative) or table identifier.
	 * @param string $type        Chunk type: 'file' or 'database'.
	 * @param int    $offset      Byte offset within source file.
	 * @return array{data: string, compressed: bool, metadata: array<string, mixed>}
	 */
	public function encode(
		string $data,
		string $import_id,
		int $chunk_index,
		string $source_path,
		string $type = 'file',
		int $offset = 0
	): array {
		$compressed = false;
		$encoded    = $data;

		if ( $this->compression_available ) {
			$result = gzencode( $data, 6 );
			if ( false !== $result ) {
				$encoded    = $result;
				$compressed = true;
			}
		}

		$metadata = array(
			'import_id'     => $import_id,
			'chunk_index'   => $chunk_index,
			'source_path'   => $source_path,
			'type'          => $type,
			'offset'        => $offset,
			'original_size' => strlen( $data ),
			'encoded_size'  => strlen( $encoded ),
			'compressed'    => $compressed,
			'hash'          => md5( $data ),
		);

		return array(
			'data'       => $encoded,
			'compressed' => $compressed,
			'metadata'   => $metadata,
		);
	}

	/**
	 * Whether compression is available.
	 *
	 * @return bool
	 */
	public function is_compression_available(): bool {
		return $this->compression_available;
	}
}
