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
	 * Encode a single-entry chunk (database, manifest, etc.).
	 *
	 * @param string $data        Raw chunk data.
	 * @param string $import_id   Import session ULID.
	 * @param int    $chunk_index Zero-based chunk index.
	 * @param string $source_path Source file path (relative) or table identifier.
	 * @param string $type        Chunk type: 'file', 'database', or 'manifest'.
	 * @param int    $offset      Byte offset within source.
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
		$entries = array(
			array(
				'path'          => $source_path,
				'source_offset' => $offset,
				'size'          => strlen( $data ),
			),
		);

		return $this->encode_multi( $data, $import_id, $chunk_index, $type, $entries );
	}

	/**
	 * Encode a multi-entry chunk (bundled files).
	 *
	 * The data is the concatenation of all entries' content. Each entry
	 * describes its path, source offset (within the original file), and
	 * size (bytes in this chunk). The restore side uses entries to split
	 * the decompressed data back into individual files.
	 *
	 * @param string                                                         $data        Raw concatenated data.
	 * @param string                                                         $import_id   Import session ULID.
	 * @param int                                                            $chunk_index Zero-based chunk index.
	 * @param string                                                         $type        Chunk type.
	 * @param array<int, array{path: string, source_offset: int, size: int}> $entries     File entries in this chunk.
	 * @return array{data: string, compressed: bool, metadata: array<string, mixed>}
	 */
	public function encode_multi(
		string $data,
		string $import_id,
		int $chunk_index,
		string $type,
		array $entries
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
			'type'          => $type,
			'entries'       => $entries,
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
