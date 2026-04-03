<?php
/**
 * Tests for S3Uploader.
 *
 * @package HonestHosting\SiteMigrator\Tests\Unit\Api
 */

namespace HonestHosting\SiteMigrator\Tests\Unit\Api;

use HonestHosting\SiteMigrator\Api\HonestHostingClient;
use HonestHosting\SiteMigrator\Api\S3Uploader;
use WP_UnitTestCase;

/**
 * Tests for the S3 presigned URL chunk uploader.
 */
class S3UploaderTest extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * Create a mock client that returns a presigned URL.
	 *
	 * @return HonestHostingClient
	 */
	private function mock_client_with_presigned_url(): HonestHostingClient {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				// Backend call for presigned URL.
				if ( str_contains( $url, 'uploadUrl' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'url'    => 'https://s3.amazonaws.com/bucket/key?signature=abc',
								's3_key' => 'imports/01XYZ/chunk-0',
							)
						),
					);
				}

				// S3 PUT call.
				if ( str_contains( $url, 's3.amazonaws.com' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'headers'  => array( 'etag' => '"abc123"' ),
						'body'     => '',
					);
				}

				return $preempt;
			},
			10,
			3
		);

		return new HonestHostingClient( 'test-key' );
	}

	/**
	 * Successful upload returns chunk reference.
	 */
	public function test_upload_chunk_success(): void {
		$client   = $this->mock_client_with_presigned_url();
		$uploader = new S3Uploader( $client );

		$result = $uploader->upload_chunk( '01XYZ', 0, 'test-data', 'application/octet-stream' );

		$this->assertIsArray( $result );
		$this->assertEquals( 'imports/01XYZ/chunk-0', $result['s3_key'] );
		$this->assertEquals( 'abc123', $result['etag'] );
	}

	/**
	 * Upload returns WP_Error when presigned URL request fails.
	 */
	public function test_upload_chunk_fails_on_presigned_url_error(): void {
		add_filter(
			'pre_http_request',
			function () {
				return new \WP_Error( 'http_error', 'Connection refused' );
			},
			10,
			3
		);

		$client   = new HonestHostingClient( 'test-key' );
		$uploader = new S3Uploader( $client );

		$result = $uploader->upload_chunk( '01XYZ', 0, 'test-data', 'application/octet-stream' );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Upload returns WP_Error on invalid presigned URL response.
	 */
	public function test_upload_chunk_fails_on_invalid_presigned_response(): void {
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'no_url' => true ) ),
				);
			},
			10,
			3
		);

		$client   = new HonestHostingClient( 'test-key' );
		$uploader = new S3Uploader( $client );

		$result = $uploader->upload_chunk( '01XYZ', 0, 'test-data', 'application/octet-stream' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'hh_migrator_upload_url_invalid', $result->get_error_code() );
	}

	/**
	 * Upload returns WP_Error on S3 client error (non-retryable).
	 */
	public function test_upload_chunk_fails_on_s3_client_error(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( str_contains( $url, 'uploadUrl' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'url'    => 'https://s3.amazonaws.com/bucket/key',
								's3_key' => 'chunk-0',
							)
						),
					);
				}

				return array(
					'response' => array( 'code' => 403 ),
					'body'     => 'Access Denied',
				);
			},
			10,
			3
		);

		$client   = new HonestHostingClient( 'test-key' );
		$uploader = new S3Uploader( $client );

		$result = $uploader->upload_chunk( '01XYZ', 0, 'test-data', 'application/octet-stream' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'hh_migrator_s3_upload_failed', $result->get_error_code() );
	}
}
