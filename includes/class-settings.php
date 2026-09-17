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
	 * Action name of the "Clear metadata cache" request, also used as nonce action.
	 */
	private const FLUSH_ACTION = 'feichtmedia_imagemanager_acf_flush_cache';

	/**
	 * Nonce field name. Custom, because the settings form already prints an `_wpnonce` field with the same ID.
	 */
	private const FLUSH_NONCE = 'feichtmedia_imagemanager_acf_flush_nonce';

	/**
	 * ID of the separate form submitted by the "Clear metadata cache" button.
	 */
	private const FLUSH_FORM_ID = 'feichtmedia-imagemanager-acf-flush-cache';

	/**
	 * Query argument carrying the number of removed entries back to the settings page.
	 */
	private const FLUSH_QUERY_ARG = 'fm-imagemanager-cache-cleared';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );

		// Site page posts to admin-post.php, the network page to network/edit.php (is_network_admin() context).
		add_action( 'admin_post_' . self::FLUSH_ACTION, [ $this, 'handle_flush_request' ] );
		add_action( 'network_admin_edit_' . self::FLUSH_ACTION, [ $this, 'handle_flush_request' ] );
		add_action( 'admin_notices', [ $this, 'render_flush_notice' ] );
		add_action( 'network_admin_notices', [ $this, 'render_flush_notice' ] );
		add_filter(
			'removable_query_args',
			static function ( array $args ): array {
				$args[] = self::FLUSH_QUERY_ARG;
				return $args;
			}
		);

		// Hand our options to Core: network storage, site write lock, uninstall cleanup.
		add_filter(
			'fm_imagemanager_managed_options',
			static function ( array $options ): array {
				$options['feichtmedia_imagemanager_acf_cache_enabled'] = 1;
				$options['feichtmedia_imagemanager_acf_cache_ttl']     = 3600;
				return $options;
			}
		);

		// Cached metadata embeds project ID and domain in its URLs — stale once either changes.
		// Cache toggle and TTL: entries written under the old settings would otherwise linger
		// (disabled cache) or keep their old lifetime.
		// add_/delete_ matter on multisite: a site switching from the inherited network value
		// to its own (or back) creates/removes the option, which never fires update_option_*.
		add_action( 'fm_imagemanager_settings_updated', [ $this, 'flush_network_metadata_cache' ] );
		$invalidating_options = [
			'feichtmedia_imagemanager_project_id',
			'feichtmedia_imagemanager_domain',
			'feichtmedia_imagemanager_acf_cache_enabled',
			'feichtmedia_imagemanager_acf_cache_ttl',
		];
		foreach ( $invalidating_options as $name ) {
			add_action( "add_option_{$name}", [ $this, 'flush_metadata_cache' ] );
			add_action( "update_option_{$name}", [ $this, 'flush_metadata_cache' ] );
			add_action( "delete_option_{$name}", [ $this, 'flush_metadata_cache' ] );
		}
	}

	/**
	 * Invalidate all cached image metadata of the current site.
	 *
	 * @return int Number of cached images removed from the options table.
	 */
	public function flush_metadata_cache(): int {
		return feichtmedia_imagemanager_flush_metadata_cache();
	}

	/**
	 * Invalidate cached image metadata on every site.
	 *
	 * Runs after the network settings changed and for the network page's "Clear metadata
	 * cache" button. Network values (and the enforce switch) affect all sites, but
	 * transients are stored per site — flushing only the current site would leave the
	 * others stale.
	 *
	 * @return int Number of cached images removed from the options tables of all sites.
	 */
	public function flush_network_metadata_cache(): int {
		if ( ! is_multisite() ) {
			return $this->flush_metadata_cache();
		}

		$count = 0;

		// 'number' => 0 returns all sites. Very large networks may need batching here.
		foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $site_id ) {
			switch_to_blog( $site_id );
			$count += $this->flush_metadata_cache();
			restore_current_blog();
		}

		return $count;
	}

	/**
	 * Handle the "Clear metadata cache" button of the site and network settings pages.
	 *
	 * Flushes the current site (admin-post.php) or all sites (network/edit.php), then
	 * redirects back to the settings page with the number of removed entries.
	 *
	 * @return void
	 */
	public function handle_flush_request(): void {
		$network = is_network_admin();

		if ( ! current_user_can( $network ? 'manage_network_options' : 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to clear the metadata cache.', 'feichtmedia-imagemanager-acf' ), 403 );
		}

		check_admin_referer( self::FLUSH_ACTION, self::FLUSH_NONCE );

		$count = $network ? $this->flush_network_metadata_cache() : $this->flush_metadata_cache();

		wp_safe_redirect(
			add_query_arg(
				[
					'page'                => 'feichtmedia-imagemanager',
					self::FLUSH_QUERY_ARG => $count,
				],
				$network ? network_admin_url( 'settings.php' ) : admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Render the success notice after the metadata cache was cleared.
	 *
	 * Uses its own notice instead of add_settings_error(): the site settings page prints
	 * settings errors twice (options-head.php and the page itself).
	 *
	 * @return void
	 */
	public function render_flush_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only values set by our own redirect.
		if ( ! isset( $_GET[ self::FLUSH_QUERY_ARG ], $_GET['page'] ) || 'feichtmedia-imagemanager' !== $_GET['page'] ) {
			return;
		}
		$count = absint( wp_unslash( $_GET[ self::FLUSH_QUERY_ARG ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// With a persistent object cache the entries are invalidated, not deleted — there is nothing to count.
		$message = wp_using_ext_object_cache()
			? __( 'Metadata cache cleared.', 'feichtmedia-imagemanager-acf' )
			: sprintf(
				/* translators: %s: number of removed cache entries */
				_n( 'Metadata cache cleared: %s cached image removed.', 'Metadata cache cleared: %s cached images removed.', $count, 'feichtmedia-imagemanager-acf' ),
				number_format_i18n( $count )
			);
		?>
		<div class="notice notice-success is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
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

		add_settings_field(
			'feichtmedia_imagemanager_acf_cache_flush',
			__( 'Clear Cache', 'feichtmedia-imagemanager-acf' ),
			[ $this, 'render_cache_flush_field' ],
			'feichtmedia-imagemanager',
			'feichtmedia_imagemanager_acf'
		);
	}

	/**
	 * Sanitize the cache TTL value.
	 *
	 * @param mixed $value Raw user input.
	 * @return int Integer between 0 and MONTH_IN_SECONDS (see feichtmedia_imagemanager_get_metadata_cache_ttl()).
	 */
	public function sanitize_ttl( $value ): int {
		return min( MONTH_IN_SECONDS, max( 0, (int) $value ) );
	}

	/**
	 * Render the metadata cache toggle field.
	 *
	 * @return void
	 */
	public function render_cache_enabled_field(): void {
		$enabled = (bool) FM_ImageManager_Core::field_value( 'feichtmedia_imagemanager_acf_cache_enabled', 1 );
		?>
		<input type="hidden" name="feichtmedia_imagemanager_acf_cache_enabled" value="0" />
		<label>
			<input
				type="checkbox"
				id="feichtmedia_imagemanager_acf_cache_enabled"
				name="feichtmedia_imagemanager_acf_cache_enabled"
				value="1"
				<?php checked( $enabled ); ?>
				<?php disabled( FM_ImageManager_Core::field_disabled() ); ?> />
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
		$ttl = (int) FM_ImageManager_Core::field_value( 'feichtmedia_imagemanager_acf_cache_ttl', 3600 );
		?>
		<input
			type="number"
			id="feichtmedia_imagemanager_acf_cache_ttl"
			name="feichtmedia_imagemanager_acf_cache_ttl"
			value="<?php echo esc_attr( $ttl ); ?>"
			min="0"
			max="<?php echo esc_attr( MONTH_IN_SECONDS ); ?>"
			step="1"
			class="small-text"
			style="min-width: 100px;"
			<?php disabled( FM_ImageManager_Core::field_disabled() ); ?> />
		<p class="description">
			<?php esc_html_e( 'How long API responses are cached, in seconds. Default: 3600 (1 hour). Maximum: 2592000 (30 days); 0 also uses the maximum.', 'feichtmedia-imagemanager-acf' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the "Clear metadata cache" button.
	 *
	 * The field sits inside the settings form and forms cannot be nested, so the button
	 * submits a separate form printed in the admin footer via its `form` attribute.
	 * It stays enabled while the network enforces its configuration: clearing the cache
	 * changes no setting.
	 *
	 * @return void
	 */
	public function render_cache_flush_field(): void {
		add_action( 'admin_footer', [ $this, 'render_cache_flush_form' ] );
		?>
		<button type="submit" form="<?php echo esc_attr( self::FLUSH_FORM_ID ); ?>" class="button">
			<?php esc_html_e( 'Clear metadata cache', 'feichtmedia-imagemanager-acf' ); ?>
		</button>
		<p class="description">
			<?php
			if ( is_network_admin() ) {
				esc_html_e( 'Deletes the cached image metadata of all sites in the network. It is fetched from the ImageManager API again on the next read.', 'feichtmedia-imagemanager-acf' );
			} else {
				esc_html_e( 'Deletes the cached image metadata of this site. It is fetched from the ImageManager API again on the next read.', 'feichtmedia-imagemanager-acf' );
			}
			?>
		</p>
		<?php
	}

	/**
	 * Render the form submitted by the "Clear metadata cache" button.
	 *
	 * @return void
	 */
	public function render_cache_flush_form(): void {
		$url = is_network_admin()
			? network_admin_url( 'edit.php?action=' . self::FLUSH_ACTION )
			: admin_url( 'admin-post.php?action=' . self::FLUSH_ACTION );
		?>
		<form id="<?php echo esc_attr( self::FLUSH_FORM_ID ); ?>" method="post" action="<?php echo esc_url( $url ); ?>">
			<?php wp_nonce_field( self::FLUSH_ACTION, self::FLUSH_NONCE, false ); ?>
		</form>
		<?php
	}
}
