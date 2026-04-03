<?php
/**
 * WordPress test configuration.
 *
 * @package HonestHosting\SiteMigrator\Tests
 */

// Path to the WordPress installation used for tests.
// From tests/ -> honest-hosting-site-migrator -> honest-hosting -> github.com -> klm.
define( 'ABSPATH', dirname( __DIR__, 4 ) . '/wordpress/wordpress-6.8.3/' );

// Database settings for test environment (docker-compose MariaDB).
define( 'DB_NAME', getenv( 'WP_DB_NAME' ) ?: 'wordpress' );
define( 'DB_USER', getenv( 'WP_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_DB_PASS' ) ?: 'dead-beef' );
define( 'DB_HOST', getenv( 'WP_DB_HOST' ) ?: '127.0.0.1:13307' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

// Dummy auth keys for testing.
define( 'AUTH_KEY', 'test-auth-key' );
define( 'SECURE_AUTH_KEY', 'test-secure-auth-key' );
define( 'LOGGED_IN_KEY', 'test-logged-in-key' );
define( 'NONCE_KEY', 'test-nonce-key' );
define( 'AUTH_SALT', 'test-auth-salt' );
define( 'SECURE_AUTH_SALT', 'test-secure-auth-salt' );
define( 'LOGGED_IN_SALT', 'test-logged-in-salt' );
define( 'NONCE_SALT', 'test-nonce-salt' );
