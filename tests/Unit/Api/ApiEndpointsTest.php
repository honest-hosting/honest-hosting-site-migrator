<?php
/**
 * Tests for ApiEndpoints.
 *
 * @package HonestHosting\SiteMigrator\Tests\Unit\Api
 */

namespace HonestHosting\SiteMigrator\Tests\Unit\Api;

use HonestHosting\SiteMigrator\Api\ApiEndpoints;
use WP_UnitTestCase;

/**
 * Tests for central endpoint URL registry.
 */
class ApiEndpointsTest extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		delete_option( 'hh_migrator_api_base_url' );
		parent::tear_down();
	}

	/**
	 * Default base URL is the production URL.
	 */
	public function test_default_base_url(): void {
		$this->assertEquals( 'https://api.honesthosting.io', ApiEndpoints::DEFAULT_BASE_URL );
	}

	/**
	 * get_base_url returns default when no option or constant set.
	 */
	public function test_get_base_url_returns_default(): void {
		$this->assertEquals( 'https://api.honesthosting.io', ApiEndpoints::get_base_url() );
	}

	/**
	 * get_base_url returns stored option when set.
	 */
	public function test_get_base_url_returns_option(): void {
		update_option( 'hh_migrator_api_base_url', 'https://staging.honesthosting.io' );
		$this->assertEquals( 'https://staging.honesthosting.io', ApiEndpoints::get_base_url() );
	}

	/**
	 * url() builds correct full URL for GET_SITE.
	 */
	public function test_url_get_site(): void {
		$url = ApiEndpoints::url( ApiEndpoints::GET_SITE );
		$this->assertEquals( 'https://api.honesthosting.io/v1/siteImport', $url );
	}

	/**
	 * url() builds correct full URL for CREATE_IMPORT.
	 */
	public function test_url_create_import(): void {
		$url = ApiEndpoints::url( ApiEndpoints::CREATE_IMPORT );
		$this->assertEquals( 'https://api.honesthosting.io/v1/siteImport', $url );
	}

	/**
	 * url() builds correct full URL for VALIDATE_IMPORT.
	 */
	public function test_url_validate_import(): void {
		$url = ApiEndpoints::url( ApiEndpoints::VALIDATE_IMPORT );
		$this->assertEquals( 'https://api.honesthosting.io/v1/siteImport/validate', $url );
	}

	/**
	 * url() substitutes uuid for GET_IMPORT.
	 */
	public function test_url_get_import(): void {
		$url = ApiEndpoints::url( ApiEndpoints::GET_IMPORT, '01XYZ789' );
		$this->assertEquals( 'https://api.honesthosting.io/v1/siteImport/01XYZ789', $url );
	}

	/**
	 * url() substitutes uuid for GET_UPLOAD_URL.
	 */
	public function test_url_get_upload_url(): void {
		$url = ApiEndpoints::url( ApiEndpoints::GET_UPLOAD_URL, '01XYZ789' );
		$this->assertEquals( 'https://api.honesthosting.io/v1/siteImport/01XYZ789/uploadUrl', $url );
	}

	/**
	 * url() builds correct full URL for FINALIZE_IMPORT.
	 */
	public function test_url_finalize_import(): void {
		$url = ApiEndpoints::url( ApiEndpoints::FINALIZE_IMPORT );
		$this->assertEquals( 'https://api.honesthosting.io/v1/siteImport/finalize', $url );
	}

	/**
	 * url() uses custom base URL from option.
	 */
	public function test_url_with_custom_base(): void {
		update_option( 'hh_migrator_api_base_url', 'https://custom.example.com' );
		$url = ApiEndpoints::url( ApiEndpoints::GET_SITE );
		$this->assertEquals( 'https://custom.example.com/v1/siteImport', $url );
	}

	/**
	 * url() strips trailing slash from base URL.
	 */
	public function test_url_strips_trailing_slash(): void {
		update_option( 'hh_migrator_api_base_url', 'https://api.honesthosting.io/' );
		$url = ApiEndpoints::url( ApiEndpoints::GET_SITE );
		$this->assertEquals( 'https://api.honesthosting.io/v1/siteImport', $url );
	}

	/**
	 * is_valid_base_url accepts HTTPS URLs.
	 */
	public function test_is_valid_base_url_accepts_https(): void {
		$this->assertTrue( ApiEndpoints::is_valid_base_url( 'https://api.honesthosting.io' ) );
		$this->assertTrue( ApiEndpoints::is_valid_base_url( 'https://staging.example.com:8443' ) );
	}

	/**
	 * is_valid_base_url rejects HTTP URLs.
	 */
	public function test_is_valid_base_url_rejects_http(): void {
		$this->assertFalse( ApiEndpoints::is_valid_base_url( 'http://api.honesthosting.io' ) );
	}

	/**
	 * is_valid_base_url rejects invalid URLs.
	 */
	public function test_is_valid_base_url_rejects_invalid(): void {
		$this->assertFalse( ApiEndpoints::is_valid_base_url( '' ) );
		$this->assertFalse( ApiEndpoints::is_valid_base_url( 'not-a-url' ) );
		$this->assertFalse( ApiEndpoints::is_valid_base_url( 'ftp://example.com' ) );
	}

	/**
	 * All endpoint constants are defined.
	 */
	public function test_all_endpoint_constants_defined(): void {
		$this->assertNotEmpty( ApiEndpoints::GET_SITE );
		$this->assertNotEmpty( ApiEndpoints::CREATE_IMPORT );
		$this->assertNotEmpty( ApiEndpoints::VALIDATE_IMPORT );
		$this->assertNotEmpty( ApiEndpoints::GET_IMPORT );
		$this->assertNotEmpty( ApiEndpoints::GET_UPLOAD_URL );
		$this->assertNotEmpty( ApiEndpoints::FINALIZE_IMPORT );
	}
}
