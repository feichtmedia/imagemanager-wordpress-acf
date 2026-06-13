<?php
/**
 * FM_ImageManager_Settings
 *
 * Adds plugin-specific settings to the shared FeichtMedia ImageManager
 * options page (owned by FM_ImageManager_Core).
 *
 * @package FeichtMedia\ImageManagerACF
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FM_ImageManager_Settings {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Register settings, section, and fields on the shared options page.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'feichtmedia_imagemanager',
			'feichtmedia_imagemanager_acf_cache_enabled',
			[
				'type'              => 'integer',
				'sanitize_callback' => static fn( $v ) => (int) (bool) $v,
				'default'           => 1,
			]
		);

		register_setting(
			'feichtmedia_imagemanager',
			'feichtmedia_imagemanager_acf_cache_ttl',
			[
				'type'              => 'integer',
				'sanitize_callback' => [ $this, 'sanitize_ttl' ],
				'default'           => 3600,
			]
		);

		add_settings_section(
			'feichtmedia_imagemanager_acf',
			__( 'ACF Field', 'feichtmedia-imagemanager-acf' ),
			null,
			'feichtmedia-imagemanager'
		);

		add_settings_field(
			'feichtmedia_imagemanager_acf_cache_enabled',
			__( 'Metadata Cache', 'feichtmedia-imagemanager-acf' ),
			[ $this, 'render_cache_enabled_field' ],
			'feichtmedia-imagemanager',
			'feichtmedia_imagemanager_acf'
		);

		add_settings_field(
			'feichtmedia_imagemanager_acf_cache_ttl',
			__( 'Cache TTL', 'feichtmedia-imagemanager-acf' ),
			[ $this, 'render_cache_ttl_field' ],
			'feichtmedia-imagemanager',
			'feichtmedia_imagemanager_acf'
		);
	}

	/**
	 * Sanitize the cache TTL value.
	 *
	 * @param mixed $value Raw user input.
	 * @return int Non-negative integer.
	 */
	public function sanitize_ttl( $value ): int {
		return max( 0, (int) $value );
	}

	/**
	 * Render the metadata cache toggle field.
	 *
	 * @return void
	 */
	public function render_cache_enabled_field(): void {
		$enabled = (bool) get_option( 'feichtmedia_imagemanager_acf_cache_enabled', 1 );
		?>
		<input type="hidden" name="feichtmedia_imagemanager_acf_cache_enabled" value="0" />
		<label>
			<input
				type="checkbox"
				id="feichtmedia_imagemanager_acf_cache_enabled"
				name="feichtmedia_imagemanager_acf_cache_enabled"
				value="1"
				<?php checked( $enabled ); ?> />
			<?php esc_html_e( 'Enable metadata cache', 'feichtmedia-imagemanager-acf' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Caches ImageManager API metadata responses in WordPress transients. Applies only to ACF field value reads (e.g. get_field(), GraphQL) — the image browser in the editor is not affected. Disable for debugging or when image metadata changes frequently.', 'feichtmedia-imagemanager-acf' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the cache TTL input field.
	 *
	 * @return void
	 */
	public function render_cache_ttl_field(): void {
		$ttl = (int) get_option( 'feichtmedia_imagemanager_acf_cache_ttl', 3600 );
		?>
		<input
			type="number"
			id="feichtmedia_imagemanager_acf_cache_ttl"
			name="feichtmedia_imagemanager_acf_cache_ttl"
			value="<?php echo esc_attr( $ttl ); ?>"
			min="0"
			step="1"
			class="small-text" />
		<p class="description">
			<?php esc_html_e( 'How long API responses are cached, in seconds. Default: 3600 (1 hour). Set to 0 for no expiry.', 'feichtmedia-imagemanager-acf' ); ?>
		</p>
		<?php
	}
}
