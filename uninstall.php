<?php
/**
 * Runs only when the plugin is deleted from the Plugins screen.
 * Content is removed only if "Delete data on uninstall" is switched on in
 * Postful → Settings. Deactivation never removes anything.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$postful_settings = get_option( 'postful_settings', [] );
if ( empty( $postful_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

$postful_types = array_keys( (array) get_option( 'postful_types', [] ) );
if ( $postful_types ) {
	$placeholders = implode( ',', array_fill( 0, count( $postful_types ), '%s' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$postful_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($placeholders)", $postful_types ) );
	foreach ( $postful_ids as $postful_id ) {
		wp_delete_post( (int) $postful_id, true ); // Also removes meta and revisions.
	}
}

// Entries of types deleted earlier are kept by design; catch them by their day key.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$postful_orphans = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", '_pf_sort' ) );
foreach ( $postful_orphans as $postful_id ) {
	wp_delete_post( (int) $postful_id, true );
}

foreach ( [ 'postful_types', 'postful_settings', 'postful_version', 'postful_flush_rewrites' ] as $postful_option ) {
	delete_option( $postful_option );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_postful\_%' OR option_name LIKE '\_transient\_timeout\_postful\_%'" );
