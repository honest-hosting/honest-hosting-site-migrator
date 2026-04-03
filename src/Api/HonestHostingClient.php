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
 * Every request includes the X-HH-Site-Import-Key header.
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
	 * Filter for eligible destination sites.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function filter_sites() {
		return $this->get( ApiEndpoints::url( ApiEndpoints::FILTER_SITES ) );
	}

	/**
	 * Create a new import session for a destination site.
	 *
	 * @param string               $site_id  Destination site ULID.
	 * @param array<string, mixed> $metadata Source site metadata.
	 * @return array<string, mixed>|WP_Error Response including import_id.
	 */
	public function create_import( string $site_id, array $metadata = array() ) {
		return $this->post(
			ApiEndpoints::url( ApiEndpoints::CREATE_IMPORT, $site_id ),
			$metadata
		);
	}

	/**
	 * Validate a pending import (capacity, destination readiness).
	 *
	 * @param string               $site_id   Destination site ULID.
	 * @param array<string, mixed> $estimates Source size estimates.
	 * @return array<string, mixed>|WP_Error
	 */
	public function validate_import( string $site_id, array $estimates ) {
		return $this->post(
			ApiEndpoints::url( ApiEndpoints::VALIDATE_IMPORT, $site_id ),
			$estimates
		);
	}

	/**
	 * List available import sessions.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function filter_imports() {
		return $this->get( ApiEndpoints::url( ApiEndpoints::FILTER_IMPORTS ) );
	}

	/**
	 * Get import session info.
	 *
	 * @param string $import_id Import session ULID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_import( string $import_id ) {
		return $this->get( ApiEndpoints::url( ApiEndpoints::GET_IMPORT, $import_id ) );
	}

	/**
	 * Obtain a presigned S3 URL for chunk upload.
	 *
	 * @param string               $import_id  Import session ULID.
	 * @param array<string, mixed> $chunk_meta Chunk metadata (index, content_type, content_length, etc.).
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_upload_url( string $import_id, array $chunk_meta ) {
		return $this->post(
			ApiEndpoints::url( ApiEndpoints::GET_UPLOAD_URL, $import_id ),
			$chunk_meta
		);
	}

	/**
	 * Check if destination is ready for import.
	 *
	 * @param string $import_id Import session ULID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function check_ready( string $import_id ) {
		return $this->get( ApiEndpoints::url( ApiEndpoints::CHECK_READY, $import_id ) );
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
	 * Build request headers.
	 *
	 * @return array<string, string>
	 */
	private function build_headers(): array {
		return array(
			'Content-Type'         => 'application/json',
			'Accept'               => 'application/json',
			'X-HH-Site-Import-Key' => $this->import_key,
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
