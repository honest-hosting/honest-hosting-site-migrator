<?php
/**
 * Preflight check interface.
 *
 * @package HonestHosting\SiteMigrator\Preflight
 */

namespace HonestHosting\SiteMigrator\Preflight;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for individual preflight checks.
 */
interface PreflightCheckInterface {

	/**
	 * Run the check and populate the result.
	 *
	 * @param PreflightResult $result Result to populate.
	 * @return void
	 */
	public function run( PreflightResult $result ): void;
}
