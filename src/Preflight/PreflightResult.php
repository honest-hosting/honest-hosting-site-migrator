<?php
/**
 * Preflight result DTO.
 *
 * @package HonestHosting\SiteMigrator\Preflight
 */

namespace HonestHosting\SiteMigrator\Preflight;

defined( 'ABSPATH' ) || exit;

/**
 * Aggregates preflight check results: errors, warnings, and informational notices.
 */
class PreflightResult {

	/**
	 * Result items.
	 *
	 * @var array<int, array{type: string, code: string, message: string, source: string}>
	 */
	private array $items = array();

	/**
	 * Add a blocking error.
	 *
	 * @param string $code    Machine-readable code.
	 * @param string $message Human-readable message.
	 * @param string $source  Origin: 'source' or 'destination'.
	 * @return void
	 */
	public function add_error( string $code, string $message, string $source = 'source' ): void {
		$this->items[] = array(
			'type'    => 'error',
			'code'    => $code,
			'message' => $message,
			'source'  => $source,
		);
	}

	/**
	 * Add a non-blocking warning.
	 *
	 * @param string $code    Machine-readable code.
	 * @param string $message Human-readable message.
	 * @param string $source  Origin: 'source' or 'destination'.
	 * @return void
	 */
	public function add_warning( string $code, string $message, string $source = 'source' ): void {
		$this->items[] = array(
			'type'    => 'warning',
			'code'    => $code,
			'message' => $message,
			'source'  => $source,
		);
	}

	/**
	 * Add an informational notice.
	 *
	 * @param string $code    Machine-readable code.
	 * @param string $message Human-readable message.
	 * @param string $source  Origin: 'source' or 'destination'.
	 * @return void
	 */
	public function add_info( string $code, string $message, string $source = 'source' ): void {
		$this->items[] = array(
			'type'    => 'info',
			'code'    => $code,
			'message' => $message,
			'source'  => $source,
		);
	}

	/**
	 * Whether any blocking errors exist.
	 *
	 * @return bool
	 */
	public function has_blocking_errors(): bool {
		foreach ( $this->items as $item ) {
			if ( 'error' === $item['type'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get only error items.
	 *
	 * @return array<int, array{type: string, code: string, message: string, source: string}>
	 */
	public function get_errors(): array {
		return array_values(
			array_filter(
				$this->items,
				fn( array $item ) => 'error' === $item['type']
			)
		);
	}

	/**
	 * Get only warning items.
	 *
	 * @return array<int, array{type: string, code: string, message: string, source: string}>
	 */
	public function get_warnings(): array {
		return array_values(
			array_filter(
				$this->items,
				fn( array $item ) => 'warning' === $item['type']
			)
		);
	}

	/**
	 * Get only info items.
	 *
	 * @return array<int, array{type: string, code: string, message: string, source: string}>
	 */
	public function get_info_items(): array {
		return array_values(
			array_filter(
				$this->items,
				fn( array $item ) => 'info' === $item['type']
			)
		);
	}

	/**
	 * Get all items as an array.
	 *
	 * @return array<int, array{type: string, code: string, message: string, source: string}>
	 */
	public function to_array(): array {
		return $this->items;
	}
}
