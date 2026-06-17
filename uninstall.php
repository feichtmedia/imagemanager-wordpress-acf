<?php
/**
 * Uninstall handler for FeichtMedia ImageManager for Advanced Custom Fields.
 *
 * Runs only on plugin deletion (not on deactivation). Uses reference-counting
 * to decide whether shared options can be safely removed:
 *   - If other FeichtMedia ImageManager plugins still consume the shared options,
 *     keep them and only remove this plugin from the registry.
 *   - If this is the last consumer, delete all shared options as well.
 *
 * Post meta is never deleted — stored image IDs remain valid if the site admin
 * switches to a plain text field or reinstalls the plugin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$self = 'feichtmedia-imagemanager-acf/feichtmedia-imagemanager-acf.php';

// 1) Remove this plugin from the shared consumer registry.
$fm_imagemanager_consumers = (array) get_option( 'feichtmedia_imagemanager_consumers', [] );
$fm_imagemanager_consumers = array_values( array_diff( $fm_imagemanager_consumers, [ $self ] ) );

if ( empty( $fm_imagemanager_consumers ) ) {
	// 2) Last consumer — remove all shared options.
	delete_option( 'feichtmedia_imagemanager_api_key' );
	delete_option( 'feichtmedia_imagemanager_project_id' );
	delete_option( 'feichtmedia_imagemanager_domain' );
	delete_option( 'feichtmedia_imagemanager_consumers' );
} else {
	// Other plugins still rely on the shared options — only update the registry.
	update_option( 'feichtmedia_imagemanager_consumers', $fm_imagemanager_consumers );
}

// 3) Always remove this plugin's own options.
delete_option( 'feichtmedia_imagemanager_acf_cache_enabled' );
delete_option( 'feichtmedia_imagemanager_acf_cache_ttl' );

// 4) Always remove this plugin's own regenerable metadata transients.
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_feichtmedia\_imagemanager\_acf\_meta\_%'
	    OR option_name LIKE '\_transient\_timeout\_feichtmedia\_imagemanager\_acf\_meta\_%'"
);
