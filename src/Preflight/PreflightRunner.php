<?php
/**
 * Preflight check orchestrator.
 *
 * @package HonestHosting\SiteMigrator\Preflight
 */

namespace HonestHosting\SiteMigrator\Preflight;

defined( 'ABSPATH' ) || exit;

use HonestHosting\SiteMigrator\Api\HonestHostingClient;
use HonestHosting\SiteMigrator\Preflight\Checks\FileAnalysisCheck;
use HonestHosting\SiteMigrator\Preflight\Checks\DatabaseAnalysisCheck;
use HonestHosting\SiteMigrator\Preflight\Checks\HostingEnvironmentCheck;
use HonestHosting\SiteMigrator\Preflight\Checks\MigrationReadinessCheck;
use HonestHosting\SiteMigrator\Preflight\Checks\PhpCompatibilityCheck;
use HonestHosting\SiteMigrator\Preflight\Checks\DestinationCapacityCheck;
use HonestHosting\SiteMigrator\Preflight\Checks\DestinationFingerprintCheck;

/**
 * Runs all preflight checks and aggregates results.
 */
class PreflightRunner {

	/**
	 * Check instances.
	 *
	 * @var PreflightCheckInterface[]
	 */
	private array $checks;

	/**
	 * Constructor.
	 *
	 * @param PreflightCheckInterface[]|null $checks Optional check overrides for testing.
	 */
	public function __construct( ?array $checks = null ) {
		if ( null !== $checks ) {
			$this->checks = $checks;
		} else {
			$client       = new HonestHostingClient();
			$this->checks = array(
				new MigrationReadinessCheck(),
				new DestinationFingerprintCheck( $client ),
				new FileAnalysisCheck(),
				new DatabaseAnalysisCheck(),
				new HostingEnvironmentCheck(),
				new PhpCompatibilityCheck(),
				new DestinationCapacityCheck( $client ),
			);
		}
	}

	/**
	 * Run all checks and return aggregated result.
	 *
	 * @return PreflightResult
	 */
	public function run(): PreflightResult {
		$result = new PreflightResult();

		do_action( 'honest_hosting_site_migrator_preflight_started' );

		foreach ( $this->checks as $check ) {
			$check->run( $result );
		}

		do_action( 'honest_hosting_site_migrator_preflight_completed', $result );

		return $result;
	}
}
