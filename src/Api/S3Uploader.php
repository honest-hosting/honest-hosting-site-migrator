<?php
/**
 * S3 chunk uploader using presigned URLs.
 *
 * @package HonestHosting\SiteMigrator\Api
 */

namespace HonestHosting\SiteMigrator\Api;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Uploads chunks directly to S3 via presigned URLs obtained from the backend.
 */
class S3Uploader {

	/**
	 * Maximum retry attempts for transient failures.
	 *
	 * @var int
	 */
	private const MAX_RETRIES = 3;

	/**
	 * Base backoff delay in seconds.
	 *
	 * @var int
	 */
	private const BACKOFF_BASE = 2;

	/**
	 * Upload timeout in seconds.
	 *
	 * @var int
	 */
	private const UPLOAD_TIMEOUT = 120;

	/**
	 * API client instance.
	 *
	 * @var HonestHostingClient
	 */
	private HonestHostingClient $client;

	/**
	 * Constructor.
	 *
	 * @param HonestHostingClient $client API client.
	 */
	public function __construct( HonestHostingClient $client ) {
		$this->client = $client;
	}

	/**
	 * Upload a chunk to S3.
	 *
	 * 1. Request a presigned URL from the backend.
	 * 2. PUT the chunk data directly to S3.
	 * 3. Retry transient failures with exponential backoff.
	 *
	 * @param string $import_id    Import session ULID.
	 * @param int    $chunk_index  Zero-based chunk index.
	 * @param string $data         Raw chunk data (possibly compressed).
	 * @param string $content_type MIME type of the chunk.
	 * @param bool   $compressed   Whether the chunk is gzip-compressed.
	 * @return array{s3_key: string, etag: string}|WP_Error Chunk reference on success.
	 */
	public function upload_chunk( string $import_id, int $chunk_index, string $data, string $content_type, bool $compressed = false ) {
		// Get presigned URL from backend.
		$url_response = $this->client->get_upload_url(
			$import_id,
			array(
				'chunk_index'    => $chunk_index,
				'content_type'   => $content_type,
				'content_length' => strlen( $data ),
				'compressed'     => $compressed,
			)
		);

		if ( is_wp_error( $url_response ) ) {
			return $url_response;
		}

		if ( empty( $url_response['url'] ) || empty( $url_response['s3_key'] ) ) {
			return new WP_Error(
				'hh_migrator_upload_url_invalid',
				__( 'Backend returned an invalid presigned URL response.', 'honest-hosting-site-migrator' )
			);
		}

		// Upload to S3 with retry.
		return $this->put_with_retry(
			$url_response['url'],
			$url_response['s3_key'],
			$data,
			$content_type,
			$compressed
		);
	}

	/**
	 * PUT data to a presigned S3 URL with exponential backoff retry.
	 *
	 * @param string $presigned_url S3 presigned URL.
	 * @param string $s3_key        S3 object key.
	 * @param string $data          Raw data to upload.
	 * @param string $content_type  MIME type.
	 * @param bool   $compressed    Whether content is gzip-compressed.
	 * @return array{s3_key: string, etag: string}|WP_Error
	 */
	private function put_with_retry( string $presigned_url, string $s3_key, string $data, string $content_type, bool $compressed ) {
		$last_error = null;

		for ( $attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++ ) {
			if ( $attempt > 0 ) {
				$delay = (int) pow( self::BACKOFF_BASE, $attempt );
				sleep( $delay );
			}

			$headers = array(
				'Content-Type'   => $content_type,
				'Content-Length' => (string) strlen( $data ),
			);

			if ( $compressed ) {
				$headers['Content-Encoding'] = 'gzip';
			}

			$response = wp_remote_request(
				$presigned_url,
				array(
					'method'  => 'PUT',
					'headers' => $headers,
					'body'    => $data,
					'timeout' => self::UPLOAD_TIMEOUT,
				)
			);

			if ( is_wp_error( $response ) ) {
				$last_error = $response;
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( $code >= 200 && $code < 300 ) {
				$etag = wp_remote_retrieve_header( $response, 'etag' );

				return array(
					's3_key' => $s3_key,
					'etag'   => is_string( $etag ) ? trim( $etag, '"' ) : '',
				);
			}

			// Retry on 5xx server errors.
			if ( $code >= 500 ) {
				$last_error = new WP_Error(
					'hh_migrator_s3_server_error',
					sprintf(
						/* translators: 1: HTTP status code 2: retry attempt number */
						__( 'S3 upload returned HTTP %1$d (attempt %2$d).', 'honest-hosting-site-migrator' ),
						$code,
						$attempt + 1
					),
					array( 'status' => $code )
				);
				continue;
			}

			// Non-retryable client error.
			return new WP_Error(
				'hh_migrator_s3_upload_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'S3 upload failed with HTTP %d.', 'honest-hosting-site-migrator' ),
					$code
				),
				array(
					'status' => $code,
					'body'   => wp_remote_retrieve_body( $response ),
				)
			);
		}

		return $last_error ?? new WP_Error(
			'hh_migrator_s3_upload_exhausted',
			__( 'S3 upload failed after all retry attempts.', 'honest-hosting-site-migrator' )
		);
	}
}
