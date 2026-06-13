<?php
/**
 * ImageManager Core bootstrap.
 *
 * Registers this bundled copy as a version candidate. After all plugins have
 * loaded, the highest registered version boots exactly once. This file is
 * IDENTICAL in every FeichtMedia ImageManager plugin.
 *
 * @package FeichtMedia\ImageManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Register this copy as a candidate (version + path to the class file).
$GLOBALS['fm_imagemanager_core_candidates']   = $GLOBALS['fm_imagemanager_core_candidates'] ?? [];
$GLOBALS['fm_imagemanager_core_candidates'][] = [
	'version' => '1.0.0',
	'file'    => __DIR__ . '/class-imagemanager-core.php',
];

// Declare helpers only once — fatal-error guard for multiple bundled copies.
if ( ! function_exists( 'fm_imagemanager_register_consumer' ) ) {

	/**
	 * Register a plugin as a consumer of the shared ImageManager options.
	 *
	 * Must be callable during plugin activation (before plugins_loaded fires for
	 * the being-activated plugin), so it lives here rather than in the class file.
	 *
	 * @param string $plugin_basename Plugin basename, e.g. plugin_basename(__FILE__).
	 * @return void
	 */
	function fm_imagemanager_register_consumer( string $plugin_basename ): void {
		$consumers = (array) get_option( 'feichtmedia_imagemanager_consumers', [] );
		if ( ! in_array( $plugin_basename, $consumers, true ) ) {
			$consumers[] = $plugin_basename;
			update_option( 'feichtmedia_imagemanager_consumers', $consumers );
		}
	}
}

if ( ! function_exists( 'fm_imagemanager_core_boot' ) ) {

	/**
	 * Boot the highest registered ImageManager Core version exactly once.
	 *
	 * @return void
	 */
	function fm_imagemanager_core_boot(): void {
		if ( defined( 'FM_IMAGEMANAGER_CORE_BOOTED' ) ) {
			return;
		}

		$candidates = $GLOBALS['fm_imagemanager_core_candidates'] ?? [];
		usort( $candidates, fn( $a, $b ) => version_compare( $b['version'], $a['version'] ) );
		$winner = $candidates[0];

		require_once $winner['file'];
		define( 'FM_IMAGEMANAGER_CORE_BOOTED', $winner['version'] );

		FM_ImageManager_Core::instance()->init();
	}

	add_action( 'plugins_loaded', 'fm_imagemanager_core_boot', 5 );
}
