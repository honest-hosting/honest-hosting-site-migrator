<?php
/**
 * Lock-holder identity helper.
 *
 * @package HonestHosting\SiteMigrator\Util
 */

namespace HonestHosting\SiteMigrator\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the "host:pid:timestamp" identifier written into session locks.
 *
 * `gethostname()` and `getmypid()` are commonly placed in `disable_functions`
 * on hardened shared hosts. On PHP 8.x calling a disabled function is a hard
 * fatal, so we probe with `function_exists()` and substitute deterministic
 * fallbacks ("unknown-host" and a per-process random integer) when they are
 * not available.
 */
class LockHolder {

	/**
	 * Cached random pid fallback so it stays stable for the lifetime of the request.
	 *
	 * @var int|null
	 */
	private static ?int $pid_fallback = null;

	/**
	 * Build a host:pid:timestamp identifier safe to call on hosts that
	 * disable `gethostname()` or `getmypid()`.
	 *
	 * @return string
	 */
	public static function build(): string {
		return self::host() . ':' . self::pid() . ':' . time();
	}

	/**
	 * Get the hostname or a fallback.
	 *
	 * @return string
	 */
	private static function host(): string {
		if ( function_exists( 'gethostname' ) ) {
			$host = gethostname();
			if ( false !== $host && '' !== $host ) {
				return $host;
			}
		}

		$server_name = isset( $_SERVER['SERVER_NAME'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_NAME'] ) ) : '';
		return '' !== $server_name ? $server_name : 'unknown-host';
	}

	/**
	 * Get the process ID or a stable per-request fallback.
	 *
	 * @return int
	 */
	private static function pid(): int {
		if ( function_exists( 'getmypid' ) ) {
			$pid = getmypid();
			if ( false !== $pid ) {
				return $pid;
			}
		}

		if ( null === self::$pid_fallback ) {
			self::$pid_fallback = wp_rand( 100000, 999999 );
		}
		return self::$pid_fallback;
	}
}
