<?php
/**
 * PHPStan bootstrap — define constants and stubs for static analysis.
 *
 * @package HonestHosting\SiteMigrator\Tests
 */

// WordPress constants.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! defined( 'HH_MIGRATOR_VERSION' ) ) {
	define( 'HH_MIGRATOR_VERSION', '1.0.0' );
}

if ( ! defined( 'HH_MIGRATOR_FILE' ) ) {
	define( 'HH_MIGRATOR_FILE', dirname( __DIR__ ) . '/honest-hosting-site-migrator.php' );
}

if ( ! defined( 'HH_MIGRATOR_PATH' ) ) {
	define( 'HH_MIGRATOR_PATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'HH_MIGRATOR_URL' ) ) {
	define( 'HH_MIGRATOR_URL', 'https://example.com/wp-content/plugins/honest-hosting-site-migrator/' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'HH_MIGRATOR_TEST_MODE' ) ) {
	define( 'HH_MIGRATOR_TEST_MODE', true );
}
