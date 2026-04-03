<?php
/**
 * PHPUnit bootstrap for HonestHosting Site Migrator tests.
 *
 * @package HonestHosting\SiteMigrator\Tests
 */

// Load Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Resolve the wp-phpunit test framework directory.
$wp_phpunit_dir = getenv( 'WP_PHPUNIT__DIR' );
if ( ! $wp_phpunit_dir ) {
	$wp_phpunit_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

require_once $wp_phpunit_dir . '/includes/functions.php';

// Define test mode constant.
if ( ! defined( 'HH_MIGRATOR_TEST_MODE' ) ) {
	define( 'HH_MIGRATOR_TEST_MODE', true );
}

// Block all external HTTP requests during tests.
if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) {
	define( 'WP_HTTP_BLOCK_EXTERNAL', true );
}
if ( ! defined( 'WP_ACCESSIBLE_HOSTS' ) ) {
	define( 'WP_ACCESSIBLE_HOSTS', 'localhost' );
}

/**
 * Load the plugin before tests run.
 */
tests_add_filter(
	'muplugins_loaded',
	function () {
		require dirname( __DIR__ ) . '/honest-hosting-site-migrator.php';
	}
);

// Block external HTTP requests via filter.
tests_add_filter(
	'pre_http_request',
	function ( $preempt, $parsed_args, $url ) {
		// Allow localhost requests for integration tests.
		if ( str_contains( $url, 'localhost' ) || str_contains( $url, '127.0.0.1' ) ) {
			return $preempt;
		}

		return new WP_Error(
			'http_request_blocked',
			sprintf( 'External HTTP request blocked in test: %s', $url )
		);
	},
	10,
	3
);

// Force minimal timeout for any HTTP requests.
tests_add_filter(
	'http_request_args',
	function ( $args ) {
		$args['timeout'] = 1;
		return $args;
	}
);

// Boot WordPress test environment.
require $wp_phpunit_dir . '/includes/bootstrap.php';
