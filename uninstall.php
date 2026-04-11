<?php
/**
 * Uninstall handler for HonestHosting Site Migrator.
 *
 * Cleans up all plugin data when the plugin is deleted via the WordPress admin.
 *
 * @package HonestHosting\SiteMigrator
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Delete plugin options.
$honest_hosting_site_migrator_options = array(
	'hh_migrator_api_base_url',
	'hh_migrator_import_key',
	'hh_migrator_destination_site_id',
	'hh_migrator_destination_site_name',
	'hh_migrator_destination_site_url',
	'hh_migrator_chunk_size',
	'hh_migrator_compression',
	'hh_migrator_schedule_enabled',
	'hh_migrator_schedule_interval',
	'hh_migrator_last_preflight',
	'hh_migrator_last_preflight_passed',
	'hh_migrator_active_import_id',
	'hh_migrator_db_version',
);

foreach ( $honest_hosting_site_migrator_options as $honest_hosting_site_migrator_option ) {
	delete_option( $honest_hosting_site_migrator_option );
}

// Clear scheduled events.
wp_clear_scheduled_hook( 'hh_migrator_scheduled_sync' );

// Drop plugin tables.
global $wpdb;
$honest_hosting_site_migrator_tables = array(
	$wpdb->prefix . 'hh_migrator_log',
	$wpdb->prefix . 'hh_migrator_file_progress',
	$wpdb->prefix . 'hh_migrator_session',
	$wpdb->prefix . 'hh_migrator_chunk_ref',
	$wpdb->prefix . 'hh_migrator_table_progress',
);
foreach ( $honest_hosting_site_migrator_tables as $honest_hosting_site_migrator_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$honest_hosting_site_migrator_table}" );
}

// Remove state files directory.
$honest_hosting_site_migrator_upload_dir = wp_upload_dir();
$honest_hosting_site_migrator_state_dir  = $honest_hosting_site_migrator_upload_dir['basedir'] . '/hh-migrator';
if ( is_dir( $honest_hosting_site_migrator_state_dir ) ) {
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	WP_Filesystem();
	global $wp_filesystem;

	$honest_hosting_site_migrator_iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $honest_hosting_site_migrator_state_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $honest_hosting_site_migrator_iterator as $honest_hosting_site_migrator_file ) {
		if ( $honest_hosting_site_migrator_file->isDir() ) {
			$wp_filesystem->rmdir( $honest_hosting_site_migrator_file->getRealPath() );
		} else {
			wp_delete_file( $honest_hosting_site_migrator_file->getRealPath() );
		}
	}

	$wp_filesystem->rmdir( $honest_hosting_site_migrator_state_dir );
}
