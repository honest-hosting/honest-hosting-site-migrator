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
	 * get_site sends correct headers.
	 */
	public function test_get_site_sends_correct_headers(): void {
		$captured_args = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'uuid' => '01ABC', 'name' => 'Test Site' ) ),
				);
			},
			10,
			3
		);

		$client = new HonestHostingClient( $this->test_key );
		$client->get_site();

		$this->assertNotNull( $captured_args );
		$this->assertEquals( 'application/json', $captured_args['headers']['Content-Type'] );
		$this->assertEquals( $this->test_key, $captured_args['headers']['X-HH-Site-Import-Key'] );
	}

	/**
	 * get_site returns parsed response on success.
	 */
	public function test_get_site_returns_site_info(): void {
		$site = array(
			'uuid'               => '01ABC',
			'name'               => 'My Site',
			'datacenter_id'      => 1,
			'import_key'         => 'dead-beef',
			'storage_gb'         => 10,
			'storage_database_gb' => 1,
			'tenant_uuid'        => 'ea7-beef',
		);

		add_filter(
			'pre_http_request',
			function () use ( $site ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( $site ),
				);
			},
			10,
			3
		);

		$client = new HonestHostingClient( $this->test_key );
		$result = $client->get_site();

		$this->assertIsArray( $result );
		$this->assertEquals( '01ABC', $result['uuid'] );
		$this->assertEquals( 'My Site', $result['name'] );
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
		$result = $client->get_site();

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
					'response' => array( 'code' => 401 ),
					'body'     => wp_json_encode( array( 'message' => 'Invalid import key' ) ),
				);
			},
			10,
			3
		);

		$client = new HonestHostingClient( $this->test_key );
		$result = $client->get_site();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'Invalid import key', $result->get_error_message() );
	}

	/**
	 * create_import sends POST with SiteImportRequest body.
	 */
	public function test_create_import_sends_post(): void {
		$captured_url  = null;
		$captured_body = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_url, &$captured_body ) {
				$captured_url  = $url;
				$captured_body = json_decode( $args['body'], true );
				return array(
					'response' => array( 'code' => 201 ),
					'body'     => wp_json_encode( array( 'uuid' => '01XYZ', 'site_uuid' => '01ABC', 'status' => 'pending' ) ),
				);
			},
			10,
			3
		);

		$client = new HonestHostingClient( $this->test_key );
		$result = $client->create_import(
			array(
				'file_bytes'        => 524288000,
				'file_count'        => 4500,
				'db_bytes'          => 104857600,
				'wordpress_version' => '6.7.1',
				'php_version'       => '8.2.15',
				'mode'              => 'full',
				'multisite'         => false,
			)
		);

		$this->assertStringEndsWith( '/v1/siteImport', $captured_url );
		$this->assertEquals( 524288000, $captured_body['file_bytes'] );
		$this->assertEquals( 4500, $captured_body['file_count'] );
		$this->assertEquals( '6.7.1', $captured_body['wordpress_version'] );
		$this->assertEquals( '01XYZ', $result['uuid'] );
	}

	/**
	 * validate_import sends POST with SiteImportRequest body.
	 */
	public function test_validate_import_sends_post(): void {
		$captured_url  = null;
		$captured_body = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_url, &$captured_body ) {
				$captured_url  = $url;
				$captured_body = json_decode( $args['body'], true );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '',
				);
			},
			10,
			3
		);

		$client = new HonestHostingClient( $this->test_key );
		$client->validate_import(
			array(
				'file_bytes'        => 100000,
				'file_count'        => 50,
				'db_bytes'          => 50000,
				'wordpress_version' => '6.7',
				'php_version'       => '8.2',
			)
		);

		$this->assertStringEndsWith( '/v1/siteImport/validate', $captured_url );
		$this->assertEquals( 100000, $captured_body['file_bytes'] );
	}

	/**
	 * finalize_import sends POST to finalize endpoint.
	 */
	public function test_finalize_import_sends_post(): void {
		$captured_url = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_url ) {
				$captured_url = $url;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'uuid' => '01XYZ', 'status' => 'ready' ) ),
				);
			},
			10,
			3
		);

		$client = new HonestHostingClient( $this->test_key );
		$result = $client->finalize_import();

		$this->assertStringEndsWith( '/v1/siteImport/finalize', $captured_url );
		$this->assertEquals( 'ready', $result['status'] );
	}

	/**
	 * finalize_import returns WP_Error on 409 conflict.
	 */
	public function test_finalize_import_returns_error_on_conflict(): void {
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 409 ),
					'body'     => wp_json_encode( array( 'message' => 'Site is locked for another import' ) ),
				);
			},
			10,
			3
		);

		$client = new HonestHostingClient( $this->test_key );
		$result = $client->finalize_import();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'locked', $result->get_error_message() );
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
		$result = $client->get_upload_url(
			'01XYZ',
			array(
				'chunk_index'    => 0,
				'content_type'   => 'application/octet-stream',
				'content_length' => 2097152,
				'compressed'     => true,
			)
		);

		$this->assertEquals( 0, $captured_body['chunk_index'] );
		$this->assertEquals( 'application/octet-stream', $captured_body['content_type'] );
		$this->assertTrue( $captured_body['compressed'] );
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
		$client->get_site();

		$this->assertEquals( 'stored-key-99', $captured_headers['X-HH-Site-Import-Key'] );
	}
}
