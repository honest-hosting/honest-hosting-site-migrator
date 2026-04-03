<?php
/**
 * Tests for ChunkEncoder.
 *
 * @package HonestHosting\SiteMigrator\Tests\Unit\Export
 */

namespace HonestHosting\SiteMigrator\Tests\Unit\Export;

use HonestHosting\SiteMigrator\Export\ChunkEncoder;
use WP_UnitTestCase;

/**
 * Tests for chunk compression and framing.
 */
class ChunkEncoderTest extends WP_UnitTestCase {

	/**
	 * Encode with compression available.
	 */
	public function test_encode_with_compression(): void {
		$encoder = new ChunkEncoder( true );
		$data    = str_repeat( 'Hello World! ', 100 );

		$result = $encoder->encode( $data, '01XYZ', 0, 'test/file.txt', 'file', 0 );

		$this->assertTrue( $result['compressed'] );
		$this->assertLessThan( strlen( $data ), strlen( $result['data'] ) );
		$this->assertEquals( '01XYZ', $result['metadata']['import_id'] );
		$this->assertEquals( 0, $result['metadata']['chunk_index'] );
		$this->assertEquals( 'test/file.txt', $result['metadata']['source_path'] );
		$this->assertEquals( 'file', $result['metadata']['type'] );
		$this->assertEquals( strlen( $data ), $result['metadata']['original_size'] );
		$this->assertEquals( md5( $data ), $result['metadata']['hash'] );
	}

	/**
	 * Encode without compression.
	 */
	public function test_encode_without_compression(): void {
		$encoder = new ChunkEncoder( false );
		$data    = 'test data';

		$result = $encoder->encode( $data, '01XYZ', 5, 'db/wp_posts', 'database', 1024 );

		$this->assertFalse( $result['compressed'] );
		$this->assertEquals( $data, $result['data'] );
		$this->assertEquals( 5, $result['metadata']['chunk_index'] );
		$this->assertEquals( 'database', $result['metadata']['type'] );
		$this->assertEquals( 1024, $result['metadata']['offset'] );
	}

	/**
	 * is_compression_available reflects constructor param.
	 */
	public function test_is_compression_available(): void {
		$with    = new ChunkEncoder( true );
		$without = new ChunkEncoder( false );

		$this->assertTrue( $with->is_compression_available() );
		$this->assertFalse( $without->is_compression_available() );
	}

	/**
	 * Metadata includes both original and encoded sizes.
	 */
	public function test_metadata_sizes(): void {
		$encoder = new ChunkEncoder( true );
		$data    = str_repeat( 'x', 10000 );

		$result = $encoder->encode( $data, '01XYZ', 0, 'file.txt' );

		$this->assertEquals( 10000, $result['metadata']['original_size'] );
		$this->assertEquals( strlen( $result['data'] ), $result['metadata']['encoded_size'] );
	}
}
