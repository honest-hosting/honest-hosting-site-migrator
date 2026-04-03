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
$hh_migrator_options = array(
	'hh_migrator_api_base_url',
	'hh_migrator_import_key',
	'hh_migrator_destination_site_id',
	'hh_migrator_chunk_size',
	'hh_migrator_schedule_enabled',
	'hh_migrator_schedule_interval',
	'hh_migrator_db_version',
);

foreach ( $hh_migrator_options as $option ) {
	delete_option( $option );
}

// Clear scheduled events.
wp_clear_scheduled_hook( 'hh_migrator_scheduled_sync' );

// Drop the log table.
global $wpdb;
$table_name = $wpdb->prefix . 'hh_migrator_log';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

// Remove state files directory.
$upload_dir = wp_upload_dir();
$state_dir  = $upload_dir['basedir'] . '/hh-migrator';
if ( is_dir( $state_dir ) ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $state_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $iterator as $file ) {
		if ( $file->isDir() ) {
			rmdir( $file->getRealPath() );
		} else {
			unlink( $file->getRealPath() );
		}
	}

	rmdir( $state_dir );
}
