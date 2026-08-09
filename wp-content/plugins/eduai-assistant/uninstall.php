<?php
/**
 * Removes the plugin's data when it is deleted from wp-admin.
 * Deactivation alone leaves everything intact.
 *
 * @package EduAI
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
foreach ( array( 'eduai_chunks', 'eduai_messages', 'eduai_exam_attempts', 'eduai_exams' ) as $suffix ) {
	$table = $wpdb->prefix . $suffix;
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}
// phpcs:enable

delete_option( 'eduai_settings' );
delete_option( 'eduai_db_version' );
delete_option( 'eduai_last_index' );
delete_transient( 'eduai_admin_notice' );

$timestamp = wp_next_scheduled( 'eduai_reindex_event' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'eduai_reindex_event' );
}
