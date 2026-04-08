<?php
/**
 * HTTP client for the HonestHosting backend API.
 *
 * @package HonestHosting\SiteMigrator\Api
 */

namespace HonestHosting\SiteMigrator\Api;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Wraps all HTTP communication with the HonestHosting backend.
 *
 * Every request includes the X-API-Site-Import-Token header.
 */
class HonestHostingClient {

	/**
	 * Default request timeout in seconds.
	 *
	 * @var int
	 */
	private const TIMEOUT = 30;

	/**
	 * Site import key.
	 *
	 * @var string
	 */
	private string $import_key;

	/**
	 * Constructor.
	 *
	 * @param string|null $import_key Import key override. Defaults to stored option.
	 */
	public function __construct( ?string $import_key = null ) {
		$this->import_key = $import_key ?? (string) get_option( 'hh_migrator_import_key', '' );
	}

	/**
	 * Get the destination site metadata (import key scoped, returns single site).
	 *
	 * @return array<string, mixed>|WP_Error SiteImportSiteResponse.
	 */
	public function get_site() {
		return $this->get( ApiEndpoints::url( ApiEndpoints::GET_SITE ) );
	}

	/**
	 * Create a new import session.
	 *
	 * @param array<string, mixed> $request_body SiteImportRequest body.
	 * @return array<string, mixed>|WP_Error SiteImportResponse including uuid.
	 */
	public function create_import( array $request_body ) {
		return $this->post(
			ApiEndpoints::url( ApiEndpoints::CREATE_IMPORT ),
			$request_body
		);
	}

	/**
	 * Validate a pending import (capacity, destination readiness).
	 *
	 * @param array<string, mixed> $request_body SiteImportRequest body.
	 * @return array<string, mixed>|WP_Error
	 */
	public function validate_import( array $request_body ) {
		return $this->post(
			ApiEndpoints::url( ApiEndpoints::VALIDATE_IMPORT ),
			$request_body
		);
	}

	/**
	 * Get import session info.
	 *
	 * @param string $import_id Import session UUID.
	 * @return array<string, mixed>|WP_Error SiteImportResponse.
	 */
	public function get_import( string $import_id ) {
		return $this->get( ApiEndpoints::url( ApiEndpoints::GET_IMPORT, $import_id ) );
	}

	/**
	 * Obtain a presigned S3 URL for chunk upload.
	 *
	 * @param string               $import_id  Import session UUID.
	 * @param array<string, mixed> $chunk_meta SiteImportUploadUrlRequest body.
	 * @return array<string, mixed>|WP_Error SiteImportUploadUrlResponse.
	 */
	public function get_upload_url( string $import_id, array $chunk_meta ) {
		return $this->post(
			ApiEndpoints::url( ApiEndpoints::GET_UPLOAD_URL, $import_id ),
			$chunk_meta
		);
	}

	/**
	 * Finalize a site import (signal backend that upload is complete).
	 *
	 * @return array<string, mixed>|WP_Error SiteImportResponse.
	 */
	public function finalize_import() {
		return $this->post( ApiEndpoints::url( ApiEndpoints::FINALIZE_IMPORT ) );
	}

	/**
	 * Cancel the active import for the authenticated site.
	 *
	 * @return array<string, mixed>|WP_Error SiteImportResponse.
	 */
	public function cancel_import() {
		return $this->delete( ApiEndpoints::url( ApiEndpoints::GET_SITE ) );
	}

	/**
	 * Perform a GET request.
	 *
	 * @param string $url Full URL.
	 * @return array<string, mixed>|WP_Error
	 */
	private function get( string $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'headers' => $this->build_headers(),
				'timeout' => self::TIMEOUT,
			)
		);

		return $this->parse_response( $response );
	}

	/**
	 * Perform a POST request.
	 *
	 * @param string               $url  Full URL.
	 * @param array<string, mixed> $body Request body.
	 * @return array<string, mixed>|WP_Error
	 */
	private function post( string $url, array $body = array() ) {
		$json_body = wp_json_encode( $body );

		$response = wp_remote_post(
			$url,
			array(
				'headers' => $this->build_headers(),
				'body'    => is_string( $json_body ) ? $json_body : '{}',
				'timeout' => self::TIMEOUT,
			)
		);

		return $this->parse_response( $response );
	}

	/**
	 * Perform a DELETE request.
	 *
	 * @param string $url Full URL.
	 * @return array<string, mixed>|WP_Error
	 */
	private function delete( string $url ) {
		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'DELETE',
				'headers' => $this->build_headers(),
				'timeout' => self::TIMEOUT,
			)
		);

		return $this->parse_response( $response );
	}

	/**
	 * Build request headers.
	 *
	 * @return array<string, string>
	 */
	private function build_headers(): array {
		return array(
			'Content-Type'            => 'application/json',
			'Accept'                  => 'application/json',
			'X-API-Site-Import-Token' => $this->import_key,
		);
	}

	/**
	 * Parse an HTTP response into an array or WP_Error.
	 *
	 * @param array<string, mixed>|WP_Error $response Raw WordPress HTTP response.
	 * @return array<string, mixed>|WP_Error
	 */
	private function parse_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = 'API request failed';
			if ( is_array( $data ) && ! empty( $data['message'] ) ) {
				$message = (string) $data['message'];
			} elseif ( is_array( $data ) && ! empty( $data['error'] ) ) {
				$message = (string) $data['error'];
			}

			return new WP_Error(
				'hh_migrator_api_error',
				$message,
				array(
					'status'   => $code,
					'response' => $data,
				)
			);
		}

		return is_array( $data ) ? $data : array();
	}
}
