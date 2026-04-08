<?php
/**
 * PHP compatibility preflight check.
 *
 * @package HonestHosting\SiteMigrator\Preflight\Checks
 */

namespace HonestHosting\SiteMigrator\Preflight\Checks;

defined( 'ABSPATH' ) || exit;

use HonestHosting\SiteMigrator\Preflight\PreflightCheckInterface;
use HonestHosting\SiteMigrator\Preflight\PreflightResult;

/**
 * Reports source PHP version.
 */
class PhpCompatibilityCheck implements PreflightCheckInterface {

	/**
	 * Run the PHP compatibility check.
	 *
	 * @param PreflightResult $result Result to populate.
	 * @return void
	 */
	public function run( PreflightResult $result ): void {
		$result->add_info(
			'php_source_version',
			sprintf(
				/* translators: %s: PHP version */
				__( 'Source PHP version: %s', 'honest-hosting-site-migrator' ),
				PHP_VERSION
			)
		);
	}
}
