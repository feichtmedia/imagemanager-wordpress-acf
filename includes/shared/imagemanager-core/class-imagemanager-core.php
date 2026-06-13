<?php

/**
 * FM_ImageManager_Core
 *
 * Shared component bundled in every FeichtMedia ImageManager plugin. Registers
 * the shared options page and shared options (api_key, project_id, domain).
 * Boots exactly once via the version-negotiated bootstrap.
 *
 * This file is IDENTICAL in every FeichtMedia ImageManager plugin.
 *
 * @package FeichtMedia\ImageManager
 */

if (! defined('ABSPATH')) {
	exit;
}

class FM_ImageManager_Core {

	private static ?self $instance = null;

	private function __construct() {
	}

	/**
	 * Return the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register all hooks. Called once by the bootstrap after loading.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action('admin_menu', [$this, 'register_options_page']);
		add_action('admin_init', [$this, 'register_settings']);
		add_action('admin_init', [$this, 'maybe_show_incomplete_notice']);
	}

	/**
	 * Register the shared settings page under Settings.
	 *
	 * @return void
	 */
	public function register_options_page(): void {
		add_options_page(
			__('FeichtMedia ImageManager', 'feichtmedia-imagemanager-acf'),
			__('FeichtMedia ImageManager', 'feichtmedia-imagemanager-acf'),
			'manage_options',
			'feichtmedia-imagemanager',
			[$this, 'render_options_page']
		);
	}

	/**
	 * Register settings, sections, and fields for the shared options page.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'feichtmedia_imagemanager',
			'feichtmedia_imagemanager_api_key',
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			]
		);

		register_setting(
			'feichtmedia_imagemanager',
			'feichtmedia_imagemanager_project_id',
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			]
		);

		register_setting(
			'feichtmedia_imagemanager',
			'feichtmedia_imagemanager_domain',
			[
				'type'              => 'string',
				'sanitize_callback' => [$this, 'sanitize_domain'],
				'default'           => '',
			]
		);

		add_settings_section(
			'feichtmedia_imagemanager_api',
			__('API Connection', 'feichtmedia-imagemanager-acf'),
			null,
			'feichtmedia-imagemanager'
		);

		add_settings_field(
			'feichtmedia_imagemanager_api_key',
			__('API Key', 'feichtmedia-imagemanager-acf'),
			[$this, 'render_api_key_field'],
			'feichtmedia-imagemanager',
			'feichtmedia_imagemanager_api'
		);

		add_settings_field(
			'feichtmedia_imagemanager_project_id',
			__('Project ID', 'feichtmedia-imagemanager-acf'),
			[$this, 'render_project_id_field'],
			'feichtmedia-imagemanager',
			'feichtmedia_imagemanager_api'
		);

		add_settings_field(
			'feichtmedia_imagemanager_domain',
			__('CDN Domain', 'feichtmedia-imagemanager-acf'),
			[$this, 'render_domain_field'],
			'feichtmedia-imagemanager',
			'feichtmedia_imagemanager_api'
		);
	}

	/**
	 * Strip protocol prefix from the CDN domain value if present.
	 *
	 * @param string $value Raw user input.
	 * @return string Sanitized domain without protocol.
	 */
	public function sanitize_domain(string $value): string {
		$value = sanitize_text_field($value);
		$value = (string) preg_replace('#^https?://#', '', $value);
		return rtrim($value, '/');
	}

	/**
	 * Show a persistent admin notice when required settings are missing.
	 *
	 * @return void
	 */
	public function maybe_show_incomplete_notice(): void {
		if (! current_user_can('manage_options')) {
			return;
		}

		$api_key    = get_option('feichtmedia_imagemanager_api_key', '');
		$project_id = get_option('feichtmedia_imagemanager_project_id', '');
		$domain     = get_option('feichtmedia_imagemanager_domain', '');

		if (empty($api_key) || empty($project_id) || empty($domain)) {
			add_action('admin_notices', [$this, 'render_incomplete_notice']);
		}
	}

	/**
	 * Render the incomplete-settings admin notice.
	 *
	 * @return void
	 */
	public function render_incomplete_notice(): void {
		$settings_url = admin_url('options-general.php?page=feichtmedia-imagemanager');
		echo '<div class="notice notice-warning"><p>';
		printf(
			wp_kses(
				/* translators: %s: URL to the settings page */
				__('FeichtMedia ImageManager: Please complete the plugin settings under <a href="%s">Settings &#8594; FeichtMedia ImageManager</a>.', 'feichtmedia-imagemanager-acf'),
				['a' => ['href' => []]]
			),
			esc_url($settings_url)
		);
		echo '</p></div>';
	}

	/**
	 * Render the full settings page HTML.
	 *
	 * @return void
	 */
	public function render_options_page(): void {
		if (! current_user_can('manage_options')) {
			return;
		}
?>
		<div class="wrap">
			<h1><?php echo esc_html__('FeichtMedia ImageManager', 'feichtmedia-imagemanager-acf'); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields('feichtmedia_imagemanager');
				do_settings_sections('feichtmedia-imagemanager');
				submit_button();
				?>
			</form>
		</div>
	<?php
	}

	/**
	 * Render the API key input field.
	 *
	 * @return void
	 */
	public function render_api_key_field(): void {
		$value = get_option('feichtmedia_imagemanager_api_key', '');
	?>
		<input
			type="password"
			id="feichtmedia_imagemanager_api_key"
			name="feichtmedia_imagemanager_api_key"
			value="<?php echo esc_attr($value); ?>"
			class="regular-text"
			autocomplete="new-password" />
		<p class="description">
			<?php esc_html_e('Read-only API key for the ImageManager API (Bearer token).', 'feichtmedia-imagemanager-acf'); ?>
		</p>
	<?php
	}

	/**
	 * Render the project ID input field.
	 *
	 * @return void
	 */
	public function render_project_id_field(): void {
		$value = get_option('feichtmedia_imagemanager_project_id', '');
	?>
		<input
			type="text"
			id="feichtmedia_imagemanager_project_id"
			name="feichtmedia_imagemanager_project_id"
			value="<?php echo esc_attr($value); ?>"
			class="regular-text" />
		<p class="description">
			<?php esc_html_e('Your ImageManager usergroup / project ID (e.g. wordpress).', 'feichtmedia-imagemanager-acf'); ?>
		</p>
	<?php
	}

	/**
	 * Render the CDN domain input field.
	 *
	 * @return void
	 */
	public function render_domain_field(): void {
		$value = get_option('feichtmedia_imagemanager_domain', '');
	?>
		<input
			type="text"
			id="feichtmedia_imagemanager_domain"
			name="feichtmedia_imagemanager_domain"
			value="<?php echo esc_attr($value); ?>"
			class="regular-text"
			placeholder="cdn.example.com" />
		<p class="description">
			<?php esc_html_e('CDN domain without protocol (e.g. cdn.example.com). The protocol will be stripped automatically.', 'feichtmedia-imagemanager-acf'); ?>
		</p>
<?php
	}
}
// fm_imagemanager_register_consumer() is declared in bootstrap.php so it is
// available even when this class file has not yet been loaded (e.g. during
// plugin activation before plugins_loaded fires).
