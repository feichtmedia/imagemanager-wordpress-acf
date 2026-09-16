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
 * On multisite, WordPress runs this file once in the context of the current site,
 * so the cleanup iterates over every site itself. Network options are removed only
 * once no site in the network has a consumer left.
 *
 * Post meta is never deleted — stored image IDs remain valid if the site admin
 * switches to a plain text field or reinstalls the plugin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/helpers.php';

/**
 * Clean up this plugin's data on the current site.
 *
 * @return bool Whether other consumers remain registered on this site.
 */
function fm_imagemanager_acf_uninstall_site(): bool {
	$self = 'feichtmedia-imagemanager-acf/feichtmedia-imagemanager-acf.php';

	// 1) Remove this plugin from the shared consumer registry.
	$consumers = (array) get_option( 'feichtmedia_imagemanager_consumers', [] );
	$consumers = array_values( array_diff( $consumers, [ $self ] ) );

	if ( empty( $consumers ) ) {
		// 2) Last consumer — remove all shared options.
		delete_option( 'feichtmedia_imagemanager_api_key' );
		delete_option( 'feichtmedia_imagemanager_project_id' );
		delete_option( 'feichtmedia_imagemanager_domain' );
		delete_option( 'feichtmedia_imagemanager_consumers' );
	} else {
		// Other plugins still rely on the shared options — only update the registry.
		update_option( 'feichtmedia_imagemanager_consumers', $consumers );
	}

	// 3) Always remove this plugin's own options.
	delete_option( 'feichtmedia_imagemanager_acf_cache_enabled' );
	delete_option( 'feichtmedia_imagemanager_acf_cache_ttl' );

	// 4) Always remove this plugin's own regenerable metadata transients.
	feichtmedia_imagemanager_delete_metadata_transients();

	return ! empty( $consumers );
}

if ( is_multisite() ) {
	$fm_imagemanager_consumers_remain = false;

	// 'number' => 0 returns all sites. Very large networks may need batching here.
	foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $fm_imagemanager_site_id ) {
		switch_to_blog( $fm_imagemanager_site_id );
		$fm_imagemanager_consumers_remain = fm_imagemanager_acf_uninstall_site() || $fm_imagemanager_consumers_remain;
		restore_current_blog();
	}

	// This plugin's own network options are always removed.
	delete_site_option( 'feichtmedia_imagemanager_acf_cache_enabled' );
	delete_site_option( 'feichtmedia_imagemanager_acf_cache_ttl' );

	if ( ! $fm_imagemanager_consumers_remain ) {
		// No FM ImageManager plugin left anywhere in the network — remove the shared network options.
		delete_site_option( 'feichtmedia_imagemanager_api_key' );
		delete_site_option( 'feichtmedia_imagemanager_project_id' );
		delete_site_option( 'feichtmedia_imagemanager_domain' );
		delete_site_option( 'feichtmedia_imagemanager_network_enforce' );
	}
} else {
	fm_imagemanager_acf_uninstall_site();
}
