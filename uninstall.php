<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package WPMUDEV\PluginTest
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Options saved by the plugin.
$options = array(
	'wpmudev_plugin_tests_auth',
	'wpmudev_drive_access_token',
	'wpmudev_drive_refresh_token',
	'wpmudev_drive_token_expires',
	'wpmudev_posts_maintenance_job',
	'wpmudev_posts_maintenance_settings',
	'wpmudev_posts_maintenance_last_run',
	'wpmudev_plugin_test_force_google_client',
);

foreach ( $options as $option ) {
	delete_option( $option );
	delete_site_option( $option );
}

// Clear posts meta used to store the last scan timestamp.
delete_post_meta_by_key( 'wpmudev_test_last_scan' );

// Remove transient state keys created during OAuth flow.
global $wpdb;
$like = $wpdb->esc_like( 'wpmudev_drive_state_' );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_' . $like ) . '%',
		$wpdb->esc_like( '_transient_timeout_' . $like ) . '%'
	)
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

// Unschedule any pending cron events registered by the plugin.
wp_clear_scheduled_hook( 'wpmudev_posts_maintenance_process' );
wp_clear_scheduled_hook( 'wpmudev_posts_maintenance_daily' );

// Ensure the singleton job option is removed in case it was recreated during shutdown.
delete_option( 'wpmudev_posts_maintenance_job' );
