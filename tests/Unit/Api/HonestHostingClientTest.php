<?php
/**
 * Tests for HonestHostingClient.
 *
 * @package HonestHosting\SiteMigrator\Tests\Unit\Api
 */

namespace HonestHosting\SiteMigrator\Tests\Unit\Api;

use HonestHosting\SiteMigrator\Api\HonestHostingClient;
use WP_UnitTestCase;

/**
 * Tests for the HH backend HTTP client.
 */
class HonestHostingClientTest extends WP_UnitTestCase {

	/**
	 * Test import key.
	 *
	 * @var string
	 */
	private string $test_key = 'test-import-key-12345';

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		delete_option( 'hh_migrator_import_key' );
		delete_option( 'hh_migrator_api_base_url' );
		parent::tear_down();
	}

	/**
	 * filter_sites sends correct headers.
	 */
	public function test_filter_sites_sends_correct_headers(): void {
		$captured_args = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'sites' => array() ) ),
				);
			},
			10,
			3
		);

		$client = new HonestHostingClient( $this->test_key );
		$client->filter_sites();

		$this->assertNotNull( $captured_args );
		$this->assertEquals( 'application/json', $captured_args['headers']['Content-Type'] );
		$this->assertEquals( $this->test_key, $captured_args['headers']['X-HH-Site-Import-Key'] );
	}

	/**
	 * filter_sites returns parsed response on success.
	 */
	public function test_filter_sites_returns_sites(): void {
		$sites = array(
			array( 'id' => '01ABC', 'name' => 'My Site', 'domain' => 'example.com' ),
		);

		add_filter(
			'pre_http_request',
			function () use ( $sites ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'sites' => $sites ) ),
				);
			},
			10,
			3
		);

		$client = new HonestHostingClient( $this->test_key );
		$result = $client->filter_sites();

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['sites'] );
		$this->assertEquals( '01ABC', $result['sites'][0]['id'] );
	}

	/**
	 * Client returns WP_Error on HTTP failure.
	 */
	public function test_returns_wp_error_on_http_failure(): void {
		add_filter(
			'pre_http_request',
			function () {
				return new \WP_Error( 'http_error', 'Connection refused' );
			},
			10,
			3
		);

		$client = new HonestHostingClient( $this->test_key );
		$result = $client->filter_sites();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'http_error', $result->get_error_code() );
	}

	/**
	 * Client returns WP_Error on non-2xx response.
	 */
	public function test_returns_wp_error_on_api_error(): void {
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 403 ),
					'body'     => wp_json_encode( array( 'message' => 'Invalid import key' ) ),
				);
			},
			10,
			3
		);

		$client = new HonestHostingClient( $this->test_key );
		$result = $client->filter_sites();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'Invalid import key', $result->get_error_message() );
	}

	/**
	 * create_import sends POST with metadata.
	 */
	public function test_create_import_sends_post(): void {
		$captured_url  = null;
		$captured_body = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_url, &$captured_body ) {
				$captured_url  = $url;
				$captured_body = $args['body'];
				return array(
					'response' => array( 'code' => 201 ),
					'body'     => wp_json_encode( array( 'import_id' => '01XYZ' ) ),
				);
			},
			10,
			3
		);

		$client = new HonestHostingClient( $this->test_key );
		$result = $client->create_import( '01ABC', array( 'wp_version' => '6.7' ) );

		$this->assertStringContainsString( '/v1/siteImport/site/01ABC', $captured_url );
		$this->assertStringContainsString( '6.7', $captured_body );
		$this->assertEquals( '01XYZ', $result['import_id'] );
	}

	/**
	 * get_upload_url sends chunk metadata.
	 */
	public function test_get_upload_url_sends_chunk_meta(): void {
		$captured_body = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured_body ) {
				$captured_body = json_decode( $args['body'], true );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'url' => 'https://s3.example.com/presigned', 's3_key' => 'chunks/01' ) ),
				);
			},
			10,
			3
		);

		$client = new HonestHostingClient( $this->test_key );
		$result = $client->get_upload_url( '01XYZ', array( 'chunk_index' => 0, 'content_length' => 2097152 ) );

		$this->assertEquals( 0, $captured_body['chunk_index'] );
		$this->assertStringContainsString( 'https://s3.example.com', $result['url'] );
	}

	/**
	 * Client uses stored option key when none provided to constructor.
	 */
	public function test_uses_stored_option_key(): void {
		update_option( 'hh_migrator_import_key', 'stored-key-99' );
		$captured_headers = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured_headers ) {
				$captured_headers = $args['headers'];
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array() ),
				);
			},
			10,
			3
		);

		$client = new HonestHostingClient();
		$client->filter_sites();

		$this->assertEquals( 'stored-key-99', $captured_headers['X-HH-Site-Import-Key'] );
	}
}
