<?php

/**
 * Plugin Name: FeichtMedia ImageManager for Advanced Custom Fields
 * Description: ACF custom field type for the FeichtMedia ImageManager DAM. Editors pick images through a native WP-admin file browser; all API requests are proxied server-side so the API key never reaches the browser.
 * Version:     1.1.0
 * Author:      FeichtMedia
 * Author URI:  https://www.feicht-media.de/
 * Text Domain: feichtmedia-imagemanager-acf
 * Domain Path: /languages
 * Requires at least: 7.0
 * Requires PHP: 8.2
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (! defined('ABSPATH')) {
    exit;
}

define('FM_IMAGEMANAGER_ACF_VERSION', '1.1.0');
define('FM_IMAGEMANAGER_ACF_PATH', plugin_dir_path(__FILE__));
define('FM_IMAGEMANAGER_ACF_URL', plugin_dir_url(__FILE__));
define('FM_IMAGEMANAGER_API_URL', 'https://imagemanager.feicht-media.de/api/v2');
define('FM_IMAGEMANAGER_DASHBOARD_URL', 'https://imagemanager.feicht-media.de');

// 1) Shared Core component: registers its version as a candidate and boots the highest version once.
require_once FM_IMAGEMANAGER_ACF_PATH . 'includes/shared/imagemanager-core/bootstrap.php';

// 2) Consumer registry: add this plugin on activation so shared options survive partial uninstalls.
register_activation_hook(__FILE__, function () {
    fm_imagemanager_register_consumer(plugin_basename(__FILE__));
});

// 3) Plugin initialisation — runs after all plugins are loaded (priority 10, after Core at 5).
add_action('plugins_loaded', function () {

    // 3a) Hard dependency: ACF. Without it nothing can be registered.
    if (! class_exists('ACF')) {
        add_action('admin_notices', 'feichtmedia_imagemanager_acf_missing_notice');
        return;
    }

    // 3b) Translations. Deferred to the `init` hook — calling load_plugin_textdomain()
    // any earlier (e.g. directly here on plugins_loaded) triggers WordPress's
    // "translation loading triggered too early" _doing_it_wrong() notice. Priority 1
    // ensures the textdomain is ready before ACF registers field types on init:5
    // (acf/include_field_types), so translated field labels are not missed.
    add_action('init', function () {
        load_plugin_textdomain(
            'feichtmedia-imagemanager-acf',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }, 1);

    // 3c) Helpers + ACF field type.
    require_once FM_IMAGEMANAGER_ACF_PATH . 'includes/helpers.php';
    require_once FM_IMAGEMANAGER_ACF_PATH . 'includes/class-acf-field-image.php';
    add_action('acf/include_field_types', function () {
        acf_register_field_type('FM_ImageManager_ACF_Field_Image');
    });

    // 3d) Plugin-specific settings section on the shared options page.
    require_once FM_IMAGEMANAGER_ACF_PATH . 'includes/class-settings.php';
    (new FM_ImageManager_Settings())->register();

    // 3e) REST proxy — only when an API key is configured.
    if (get_option('feichtmedia_imagemanager_api_key')) {
        require_once FM_IMAGEMANAGER_ACF_PATH . 'includes/class-rest-proxy.php';
        (new FM_ImageManager_REST_Proxy())->register();
    }

    // 3f) WPGraphQL for ACF integration — only when WPGraphQL for ACF v2+ is active.
    if (function_exists('register_graphql_acf_field_type')) {
        require_once FM_IMAGEMANAGER_ACF_PATH . 'includes/class-graphql.php';
        (new FM_ImageManager_GraphQL())->register();
    }
});

/**
 * Admin notice shown when ACF is not installed or not active.
 *
 * @return void
 */
function feichtmedia_imagemanager_acf_missing_notice(): void {
    echo '<div class="notice notice-error"><p>';
    echo esc_html__('FeichtMedia ImageManager for Advanced Custom Fields requires Advanced Custom Fields (ACF) to be installed and active.', 'feichtmedia-imagemanager-acf');
    echo '</p></div>';
}
