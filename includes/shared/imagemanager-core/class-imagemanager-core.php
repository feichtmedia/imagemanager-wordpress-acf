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

	/**
	 * Whether the network options page is currently being saved. Lets sanitize
	 * callbacks fall back to the network value instead of the current site's value.
	 */
	private bool $saving_network = false;

	private function __construct() {
	}

	/**
	 * Return the options managed on the shared settings page, mapped to their defaults.
	 *
	 * Consumer plugins append their own options via the `fm_imagemanager_managed_options`
	 * filter. The list drives network storage, the site write lock, and uninstall
	 * cleanup. The consumer registry is intentionally not part of it — it stays per site.
	 *
	 * @return array<string, mixed> Map of option name => default value.
	 */
	public static function managed_options(): array {
		return (array) apply_filters(
			'fm_imagemanager_managed_options',
			[
				'feichtmedia_imagemanager_api_key'    => '',
				'feichtmedia_imagemanager_project_id' => '',
				'feichtmedia_imagemanager_domain'     => '',
			]
		);
	}

	/**
	 * Whether the network admin enforces its configuration on all sites.
	 *
	 * @return bool True on multisite with the enforce switch enabled.
	 */
	public static function is_network_enforced(): bool {
		return is_multisite() && (bool) get_site_option('feichtmedia_imagemanager_network_enforce', 0);
	}

	/**
	 * Read a managed setting, resolving site vs. network scope.
	 *
	 * Single site: plain site option. Multisite with enforce on: network value.
	 * Multisite with enforce off: the site value wins if the option exists and is
	 * not an empty string; otherwise the network value is used as a fallback.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $default Default when neither scope has a value.
	 * @return mixed Resolved value.
	 */
	public static function get_setting(string $name, mixed $default = ''): mixed {
		if (! is_multisite()) {
			return get_option($name, $default);
		}

		if (self::is_network_enforced()) {
			return get_site_option($name, $default);
		}

		// Check for existence, not empty(): `0` is a valid site value (e.g. cache disabled)
		// and must not fall through to the network value.
		$value = get_option($name, null);
		if (null !== $value && '' !== $value) {
			return $value;
		}

		return get_site_option($name, $default);
	}

	/**
	 * Value to show in a settings form field for the current admin context.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $default Default value.
	 * @return mixed Network value in the network admin, resolved setting elsewhere.
	 */
	public static function field_value(string $name, mixed $default = ''): mixed {
		return is_network_admin() ? get_site_option($name, $default) : self::get_setting($name, $default);
	}

	/**
	 * Whether settings form fields must be rendered read-only.
	 *
	 * @return bool True on a site settings page while the network enforces its configuration.
	 */
	public static function field_disabled(): bool {
		return ! is_network_admin() && self::is_network_enforced();
	}

	/**
	 * Value to prefill into a text field that can inherit the network value.
	 *
	 * Unlike field_value(), a non-enforced site page shows only the site's own value:
	 * prefilling the inherited network value would copy it into the site option on the
	 * next save and silently detach the site from later network changes.
	 *
	 * @param string $name Option name.
	 * @return string Network value in the network admin or while enforced, otherwise the site's own value.
	 */
	private static function own_field_value(string $name): string {
		if (is_network_admin() || self::is_network_enforced()) {
			return (string) get_site_option($name, '');
		}
		return (string) get_option($name, '');
	}

	/**
	 * Network value an empty site field falls back to, for display as a placeholder.
	 *
	 * @param string $name Option name.
	 * @return string Inherited network value, or '' when nothing is inherited in this context.
	 */
	private static function inherited_field_value(string $name): string {
		if (! is_multisite() || is_network_admin() || self::is_network_enforced()) {
			return '';
		}
		return (string) get_site_option($name, '');
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
		add_action('network_admin_menu', [$this, 'register_network_options_page']);
		add_action('admin_init', [$this, 'register_network_settings']);
		add_action('network_admin_edit_feichtmedia_imagemanager', [$this, 'save_network_options']);

		// Consumer plugins add their options to managed_options() on plugins_loaded:10,
		// after this runs on :5 — so the write lock is registered once the list is complete.
		add_action('plugins_loaded', [$this, 'register_write_protection'], 20);
	}

	/**
	 * Hook the site write lock onto every managed option.
	 *
	 * @return void
	 */
	public function register_write_protection(): void {
		foreach (array_keys(self::managed_options()) as $name) {
			add_filter("pre_update_option_{$name}", [$this, 'block_site_write'], 10, 3);
		}
	}

	/**
	 * Filter site writes of managed options on multisite.
	 *
	 * While the network enforces its configuration, the stored site value is kept.
	 * The disabled form fields are cosmetic only; this stops direct POSTs to
	 * options.php (and any other update_option() call).
	 *
	 * Otherwise, a site that has no own value yet keeps inheriting when the submitted
	 * value equals the network value. Checkboxes and number fields are always posted,
	 * so without this, saving the site page once would pin the inherited values and
	 * detach the site from later network changes.
	 *
	 * update_site_option() uses a different filter, so network saves are unaffected.
	 *
	 * @param mixed  $value     New value.
	 * @param mixed  $old_value Currently stored value (or the registered default if the option does not exist).
	 * @param string $name      Option name.
	 * @return mixed Old value to skip the write, otherwise the new value.
	 */
	public function block_site_write(mixed $value, mixed $old_value, string $name): mixed {
		if (self::is_network_enforced()) {
			return $old_value;
		}

		// Existence check via an explicit null default: $old_value cannot be used, because
		// register_setting() defaults make get_option() return e.g. 3600 for a missing option.
		if (is_multisite() && null === get_option($name, null)) {
			$network = get_site_option($name, null);
			// Scalar string comparison: posted values are strings, stored ones may be ints.
			// No network value means nothing to inherit — `0` must still be stored.
			if (null !== $network && is_scalar($value) && (string) $value === (string) $network) {
				return $old_value;
			}
		}

		return $value;
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
	 * Strip the protocol prefix from the CDN domain value and validate that what
	 * remains is a real hostname.
	 *
	 * Invalid input (paths, query strings, credentials, ports, malformed labels) is
	 * rejected outright rather than silently stripped — saving a partially-fixed
	 * value would mask the underlying mistake. On rejection, the previously stored
	 * value is kept and a settings error is queued for display.
	 *
	 * @param string $value Raw user input.
	 * @return string Sanitized domain without protocol, or the previous value if invalid.
	 */
	public function sanitize_domain(string $value): string {
		$value = sanitize_text_field($value);
		$value = (string) preg_replace('#^https?://#i', '', $value);
		$value = rtrim($value, '/');

		// Empty is a valid (incomplete-configuration) state — nothing to validate.
		if ($value === '') {
			return '';
		}

		if (! $this->is_valid_hostname($value)) {
			add_settings_error(
				'feichtmedia_imagemanager_domain',
				'feichtmedia_imagemanager_invalid_domain',
				__('Please enter a valid CDN domain without a protocol, path, or port (e.g. cdn.example.com). Your change was not saved.', 'feichtmedia-imagemanager-acf')
			);
			// Keep the previous value of the scope being saved — never copy the main site's value into the network option.
			return $this->saving_network
				? (string) get_site_option('feichtmedia_imagemanager_domain', '')
				: (string) get_option('feichtmedia_imagemanager_domain', '');
		}

		return $value;
	}

	/**
	 * Check whether a string is a syntactically valid hostname.
	 *
	 * Rejects anything still carrying path, query, fragment, credential, or port
	 * segments, then validates the remainder against RFC 1123 hostname label rules
	 * (or a raw IP address, for local/dev CDN setups). Does not resolve the DNS
	 * name — it only guards against paths and malformed input reaching the option.
	 *
	 * @param string $host Candidate hostname (protocol/trailing slash already stripped).
	 * @return bool True if syntactically valid.
	 */
	private function is_valid_hostname(string $host): bool {
		if (strlen($host) > 253) {
			return false;
		}

		// A real hostname never contains any of these — catches paths, query strings,
		// fragments, userinfo, and explicit ports in one pass. Delimiter is `~` (not `#`)
		// because `#` itself must appear in the character class.
		if (preg_match('~[/?#@: ]~', $host)) {
			return false;
		}

		if (filter_var($host, FILTER_VALIDATE_IP)) {
			return true;
		}

		// RFC 1123: dot-separated labels, 1–63 chars each, alphanumeric/hyphen,
		// never starting or ending with a hyphen.
		return (bool) preg_match(
			'/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/',
			$host
		);
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

		$api_key    = self::get_setting('feichtmedia_imagemanager_api_key', '');
		$project_id = self::get_setting('feichtmedia_imagemanager_project_id', '');
		$domain     = self::get_setting('feichtmedia_imagemanager_domain', '');

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
		echo '<div class="notice notice-warning"><p>';

		if (self::is_network_enforced()) {
			// Site admins cannot change network-enforced values, so only super admins get a link.
			if (current_user_can('manage_network_options')) {
				printf(
					wp_kses(
						/* translators: %s: URL to the network settings page */
						__('FeichtMedia ImageManager: Please complete the network settings under <a href="%s">Network Admin &#8594; Settings &#8594; FeichtMedia ImageManager</a>.', 'feichtmedia-imagemanager-acf'),
						['a' => ['href' => []]]
					),
					esc_url(network_admin_url('settings.php?page=feichtmedia-imagemanager'))
				);
			} else {
				esc_html_e('FeichtMedia ImageManager: The configuration is incomplete. It is managed network-wide — please contact your network administrator.', 'feichtmedia-imagemanager-acf');
			}
			echo '</p></div>';
			return;
		}

		$settings_url = admin_url('options-general.php?page=feichtmedia-imagemanager');
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
			<?php settings_errors(); ?>
			<?php if (self::is_network_enforced()) : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e('These settings are managed network-wide by your network administrator and cannot be changed on this site.', 'feichtmedia-imagemanager-acf'); ?></p>
				</div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php
				settings_fields('feichtmedia_imagemanager');
				do_settings_sections('feichtmedia-imagemanager');
				if (! self::is_network_enforced()) {
					submit_button();
				}
				?>
			</form>
		</div>
	<?php
	}

	/**
	 * Register the network settings page under Network Admin → Settings.
	 *
	 * @return void
	 */
	public function register_network_options_page(): void {
		add_submenu_page(
			'settings.php',
			__('FeichtMedia ImageManager', 'feichtmedia-imagemanager-acf'),
			__('FeichtMedia ImageManager', 'feichtmedia-imagemanager-acf'),
			'manage_network_options',
			'feichtmedia-imagemanager',
			[$this, 'render_network_options_page']
		);
	}

	/**
	 * Register the network-only section with the enforce switch.
	 *
	 * Uses its own page slug so the switch never appears on site settings pages.
	 *
	 * @return void
	 */
	public function register_network_settings(): void {
		if (! is_network_admin()) {
			return;
		}

		add_settings_section(
			'feichtmedia_imagemanager_network',
			__('Network', 'feichtmedia-imagemanager-acf'),
			null,
			'feichtmedia-imagemanager-network'
		);

		add_settings_field(
			'feichtmedia_imagemanager_network_enforce',
			__('Enforce Configuration', 'feichtmedia-imagemanager-acf'),
			[$this, 'render_network_enforce_field'],
			'feichtmedia-imagemanager-network',
			'feichtmedia_imagemanager_network'
		);
	}

	/**
	 * Render the network settings page.
	 *
	 * @return void
	 */
	public function render_network_options_page(): void {
		if (! current_user_can('manage_network_options')) {
			return;
		}

		// add_settings_error() does not survive the redirect after saving, so the
		// save handler hands the errors over in a short-lived site transient.
		$errors = get_site_transient('fm_imagemanager_network_settings_errors');
		if (is_array($errors)) {
			delete_site_transient('fm_imagemanager_network_settings_errors');
			foreach ($errors as $error) {
				add_settings_error($error['setting'], $error['code'], $error['message'], $error['type']);
			}
		}
	?>
		<div class="wrap">
			<h1><?php echo esc_html__('FeichtMedia ImageManager', 'feichtmedia-imagemanager-acf'); ?></h1>
			<?php if (isset($_GET['updated'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect. ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e('Settings saved.', 'feichtmedia-imagemanager-acf'); ?></p>
				</div>
			<?php endif; ?>
			<?php settings_errors(); ?>
			<p><?php esc_html_e('These values apply to all sites in the network. Sites without their own value use them as a fallback, unless the configuration is enforced below.', 'feichtmedia-imagemanager-acf'); ?></p>
			<form method="post" action="<?php echo esc_url(network_admin_url('edit.php?action=feichtmedia_imagemanager')); ?>">
				<?php
				wp_nonce_field('feichtmedia_imagemanager_network');
				do_settings_sections('feichtmedia-imagemanager');
				do_settings_sections('feichtmedia-imagemanager-network');
				submit_button();
				?>
			</form>
		</div>
	<?php
	}

	/**
	 * Save the network settings page (network_admin_edit_{action} handler).
	 *
	 * Reuses the sanitize callbacks registered via register_setting() so network
	 * values follow exactly the same rules as site values.
	 *
	 * @return void
	 */
	public function save_network_options(): void {
		if (! current_user_can('manage_network_options')) {
			wp_die(esc_html__('Sorry, you are not allowed to manage network options.', 'feichtmedia-imagemanager-acf'), 403);
		}

		check_admin_referer('feichtmedia_imagemanager_network');

		$this->saving_network = true;

		foreach (array_keys(self::managed_options()) as $name) {
			// Only options rendered on the page are posted; leave everything else untouched.
			if (! isset($_POST[$name])) {
				continue;
			}

			// sanitize_option() runs the `sanitize_option_{$name}` filter that register_setting() attached.
			$value = sanitize_option($name, wp_unslash($_POST[$name])); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via the registered callback.
			update_site_option($name, $value);
		}

		$this->saving_network = false;

		update_site_option(
			'feichtmedia_imagemanager_network_enforce',
			empty($_POST['feichtmedia_imagemanager_network_enforce']) ? 0 : 1
		);

		/**
		 * Fires after the network settings have been saved.
		 */
		do_action('fm_imagemanager_settings_updated');

		set_site_transient('fm_imagemanager_network_settings_errors', get_settings_errors(), 30);

		wp_safe_redirect(
			add_query_arg(
				['page' => 'feichtmedia-imagemanager', 'updated' => 'true'],
				network_admin_url('settings.php')
			)
		);
		exit;
	}

	/**
	 * Render the network enforce checkbox.
	 *
	 * @return void
	 */
	public function render_network_enforce_field(): void {
		$enforced = (bool) get_site_option('feichtmedia_imagemanager_network_enforce', 0);
	?>
		<input type="hidden" name="feichtmedia_imagemanager_network_enforce" value="0" />
		<label>
			<input
				type="checkbox"
				id="feichtmedia_imagemanager_network_enforce"
				name="feichtmedia_imagemanager_network_enforce"
				value="1"
				<?php checked($enforced); ?> />
			<?php esc_html_e('Enforce configuration network-wide', 'feichtmedia-imagemanager-acf'); ?>
		</label>
		<p class="description">
			<?php esc_html_e('When enabled, all sites use the values above and site administrators can no longer change them. When disabled, the values above only apply to sites that have not set their own.', 'feichtmedia-imagemanager-acf'); ?>
		</p>
	<?php
	}

	/**
	 * Render the API key input field.
	 *
	 * @return void
	 */
	public function render_api_key_field(): void {
		$name     = 'feichtmedia_imagemanager_api_key';
		$disabled = self::field_disabled();

		// Never print the network key on a site page — site admins could read it from the page source.
		$value = $disabled ? '' : self::own_field_value($name);

		$uses_network_key = $disabled
			? '' !== (string) get_site_option($name, '')
			: '' === $value && '' !== self::inherited_field_value($name);
	?>
		<input
			type="password"
			id="feichtmedia_imagemanager_api_key"
			name="feichtmedia_imagemanager_api_key"
			value="<?php echo esc_attr($value); ?>"
			class="regular-text"
			autocomplete="new-password"
			<?php if ($uses_network_key) : ?>
			placeholder="<?php esc_attr_e('Using the network-wide API key', 'feichtmedia-imagemanager-acf'); ?>"
			<?php endif; ?>
			<?php disabled($disabled); ?> />
		<p class="description">
			<?php esc_html_e('Read-only API key for the ImageManager API (Bearer token).', 'feichtmedia-imagemanager-acf'); ?>
			<?php $this->render_inherit_hint($name); ?>
		</p>
	<?php
	}

	/**
	 * Print a hint that an empty site field falls back to the network value.
	 *
	 * @param string $name Option name.
	 * @return void
	 */
	private function render_inherit_hint(string $name): void {
		if ('' === self::inherited_field_value($name)) {
			return;
		}
		esc_html_e('Leave empty to use the network-wide value.', 'feichtmedia-imagemanager-acf');
	}

	/**
	 * Render the project ID input field.
	 *
	 * @return void
	 */
	public function render_project_id_field(): void {
		$name = 'feichtmedia_imagemanager_project_id';
	?>
		<input
			type="text"
			id="feichtmedia_imagemanager_project_id"
			name="feichtmedia_imagemanager_project_id"
			value="<?php echo esc_attr(self::own_field_value($name)); ?>"
			class="regular-text"
			placeholder="<?php echo esc_attr(self::inherited_field_value($name)); ?>"
			<?php disabled(self::field_disabled()); ?> />
		<p class="description">
			<?php esc_html_e('Your ImageManager usergroup / project ID (e.g. wordpress).', 'feichtmedia-imagemanager-acf'); ?>
			<?php $this->render_inherit_hint($name); ?>
		</p>
	<?php
	}

	/**
	 * Render the CDN domain input field.
	 *
	 * @return void
	 */
	public function render_domain_field(): void {
		$name = 'feichtmedia_imagemanager_domain';
	?>
		<input
			type="text"
			id="feichtmedia_imagemanager_domain"
			name="feichtmedia_imagemanager_domain"
			value="<?php echo esc_attr(self::own_field_value($name)); ?>"
			class="regular-text"
			placeholder="<?php echo esc_attr(self::inherited_field_value($name) ?: 'cdn.example.com'); ?>"
			<?php disabled(self::field_disabled()); ?> />
		<p class="description">
			<?php esc_html_e('CDN domain without protocol (e.g. cdn.example.com). The protocol will be stripped automatically.', 'feichtmedia-imagemanager-acf'); ?>
			<?php $this->render_inherit_hint($name); ?>
		</p>
<?php
	}
}
// fm_imagemanager_register_consumer() is declared in bootstrap.php so it is
// available even when this class file has not yet been loaded (e.g. during
// plugin activation before plugins_loaded fires).
//
// Multisite support (network settings page, network enforce switch, site write
// lock, get_setting() scope resolution) is included since Core 1.2.0.
