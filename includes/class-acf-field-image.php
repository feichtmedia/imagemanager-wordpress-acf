<?php

/**
 * FM_ImageManager_ACF_Field_Image
 *
 * Registers the custom ACF field type 'imagemanager_image'. Editors select
 * images through the native file-browser modal; the stored value is always
 * the plain image ID (newFilename). Backward-compatible with legacy relative
 * URL values stored by earlier plain-text fields.
 *
 * @package FeichtMedia\ImageManagerACF
 */

if (! defined('ABSPATH')) {
	exit;
}

class FM_ImageManager_ACF_Field_Image extends acf_field {

	/**
	 * Set field type properties.
	 *
	 * @return void
	 */
	public function initialize(): void {
		$this->name     = 'imagemanager_image';
		$this->label    = __('Image', 'feichtmedia-imagemanager-acf');
		$this->category = 'FeichtMedia ImageManager';
		$this->defaults = [
			'return_format' => 'relative_url',
			'allow_null'    => 1,
		];
	}

	/**
	 * Render the field-group editor settings for this field.
	 *
	 * @param array $field Field configuration array.
	 * @return void
	 */
	public function render_field_settings($field): void {
		acf_render_field_setting(
			$field,
			[
				'label'   => __('Return Format', 'feichtmedia-imagemanager-acf'),
				'name'    => 'return_format',
				'type'    => 'select',
				'choices' => [
					'relative_url' => __('Relative URL', 'feichtmedia-imagemanager-acf'),
					'absolute_url' => __('Absolute URL', 'feichtmedia-imagemanager-acf'),
					'metadata'     => __('Metadata Object', 'feichtmedia-imagemanager-acf'),
				],
			]
		);

		acf_render_field_setting(
			$field,
			[
				'label' => __('Allow Null', 'feichtmedia-imagemanager-acf'),
				'name'  => 'allow_null',
				'type'  => 'true_false',
				'ui'    => 1,
			]
		);
	}

	/**
	 * Render the field input HTML in the post/page editor.
	 *
	 * Shows a configuration notice when required settings are missing.
	 * Otherwise shows either the empty-state "Add image" button or the
	 * preview thumbnail with "Change image" / "Remove" buttons.
	 *
	 * @param array $field Field configuration array (includes current value).
	 * @return void
	 */
	public function render_field($field): void {
		$api_key    = get_option('feichtmedia_imagemanager_api_key', '');
		$project_id = get_option('feichtmedia_imagemanager_project_id', '');
		$domain     = get_option('feichtmedia_imagemanager_domain', '');
		$thumb_filters = 'fit-in/300x300/filters:quality(80)/filters:strip_exif()/filters:strip_icc()/filters:no_upscale()';

		if (empty($api_key) || empty($project_id) || empty($domain)) {
			$settings_url = admin_url('options-general.php?page=feichtmedia-imagemanager');
			echo '<div class="fm-imagemanager-config-notice">';
			printf(
				wp_kses(
					/* translators: %s: URL to the plugin settings page */
					__('<span aria-hidden="true">&#9888;</span> Configuration incomplete. Please complete all settings under <a href="%s">Settings &#8594; FeichtMedia ImageManager</a>.', 'feichtmedia-imagemanager-acf'),
					['span' => ['aria-hidden' => []], 'a' => ['href' => []]]
				),
				esc_url($settings_url)
			);
			echo '</div>';
			return;
		}

		$value     = $field['value'] ?? '';
		$field_key = $field['key'] ?? '';
		$has_image = ! empty($value);
		$img_src   = '';
		$img_alt   = '';

		if ($has_image) {
			$parsed  = feichtmedia_imagemanager_parse_value((string) $value);
			$img_src = esc_url(feichtmedia_imagemanager_build_url($parsed['groupId'], $parsed['imageId'], $domain . '/' . $thumb_filters));
			// Use the image ID as a fallback alt text; JS updates it to the real title after
			// selection. Empty string would mark the preview as purely decorative, which is
			// misleading — the preview conveys which specific image is selected.
			$img_alt = esc_attr($parsed['imageId']);
		}
?>
		<div class="fm-imagemanager-field" data-field-key="<?php echo esc_attr($field_key); ?>">

			<input
				type="hidden"
				id="<?php echo esc_attr($field['id']); ?>"
				name="<?php echo esc_attr($field['name']); ?>"
				value="<?php echo esc_attr((string) $value); ?>"
				data-fm-imagemanager-input />

			<div class="fm-imagemanager-empty-state" <?php echo $has_image ? ' style="display:none;"' : ''; ?>>
				<button type="button" class="button fm-imagemanager-btn-add">
					<?php esc_html_e('Add image', 'feichtmedia-imagemanager-acf'); ?>
				</button>
			</div>

			<div class="fm-imagemanager-preview-state" <?php echo ! $has_image ? ' style="display:none;"' : ''; ?>>
				<div class="fm-imagemanager-preview">
					<img
						src="<?php echo $img_src; ?>"
						alt="<?php echo $img_alt; ?>"
						data-fm-imagemanager-preview />
					<div class="fm-imagemanager-img-error" style="display:none;">
						<?php esc_html_e('Image not found.', 'feichtmedia-imagemanager-acf'); ?>
					</div>
				</div>
				<div class="fm-imagemanager-actions">
					<button type="button" class="button fm-imagemanager-btn-change">
						<?php esc_html_e('Change image', 'feichtmedia-imagemanager-acf'); ?>
					</button>
					<?php if (! empty($field['allow_null'])) : ?>
						<button type="button" class="button fm-imagemanager-btn-remove">
							<?php esc_html_e('Remove', 'feichtmedia-imagemanager-acf'); ?>
						</button>
					<?php endif; ?>
				</div>
			</div>

		</div>
<?php
	}

	/**
	 * Enqueue field assets on ACF admin screens.
	 *
	 * Assets are registered with a single handle regardless of how many field
	 * instances appear on the page — wp_enqueue_* deduplicates by handle.
	 * UI strings are translated in PHP and passed once via wp_localize_script.
	 *
	 * @return void
	 */
	public function input_admin_enqueue_scripts(): void {
		wp_enqueue_script(
			'fm-imagemanager-field',
			FM_IMAGEMANAGER_ACF_URL . 'assets/js/acf-imagemanager-field.js',
			['acf-input', 'wp-api-fetch'],
			FM_IMAGEMANAGER_ACF_VERSION,
			true
		);

		wp_localize_script(
			'fm-imagemanager-field',
			'fmImageManager',
			[
				'restNamespace' => 'feichtmedia/imagemanager/v2',
				'projectId'     => get_option('feichtmedia_imagemanager_project_id', ''),
				'domain'        => get_option('feichtmedia_imagemanager_domain', ''),
				'dashboardUrl'  => FM_IMAGEMANAGER_DASHBOARD_URL,
				// All UI strings translated in PHP; JS reads from this object only.
				'strings'       => [
					'addImage'     => __('Add image', 'feichtmedia-imagemanager-acf'),
					'changeImage'  => __('Change image', 'feichtmedia-imagemanager-acf'),
					'remove'       => __('Remove', 'feichtmedia-imagemanager-acf'),
					'search'       => __('Search…', 'feichtmedia-imagemanager-acf'),
					'noResults'    => __('No images found.', 'feichtmedia-imagemanager-acf'),
					'noCatResults' => __('No categories found.', 'feichtmedia-imagemanager-acf'),
					'loadError'    => __('Could not load images. Please try again.', 'feichtmedia-imagemanager-acf'),
					'catLoadError' => __('Could not load categories. Please try again.', 'feichtmedia-imagemanager-acf'),
					/* translators: %1$d: number of images currently shown, %2$d: total image count */
					'showingCount' => __('Showing %1$d of %2$d', 'feichtmedia-imagemanager-acf'),
					'selectImage'  => __('Select image', 'feichtmedia-imagemanager-acf'),
					'select'       => __('Select', 'feichtmedia-imagemanager-acf'),
					'close'        => __('Close', 'feichtmedia-imagemanager-acf'),
					'loading'      => __('Loading…', 'feichtmedia-imagemanager-acf'),
					'loadMore'     => __('Load more', 'feichtmedia-imagemanager-acf'),
					'root'         => __('All images', 'feichtmedia-imagemanager-acf'),
					'imageNotFound' => __('Image not found.', 'feichtmedia-imagemanager-acf'),
					'missingApiKey' => __('The ImageManager API key is not configured. Please check your settings.', 'feichtmedia-imagemanager-acf'),
					'infoTooltip'  => __('View in ImageManager', 'feichtmedia-imagemanager-acf'),
					'retry'        => __('Try again', 'feichtmedia-imagemanager-acf'),
					'upload'       => __('Upload', 'feichtmedia-imagemanager-acf'),
					/* translators: label appended to links that open in a new browser tab, e.g. "Upload (opens in new window)" */
					'newWindow'    => __('opens in new window', 'feichtmedia-imagemanager-acf'),
					/* translators: aria-label for the breadcrumb navigation landmark */
					'breadcrumbs'  => __('Breadcrumb navigation', 'feichtmedia-imagemanager-acf'),
					/* translators: ACF Conditional Logic operator label shown in the field group editor */
					'hasValue'     => __('Has a value', 'feichtmedia-imagemanager-acf'),
					/* translators: ACF Conditional Logic operator label shown in the field group editor */
					'hasNoValue'   => __('Has no value', 'feichtmedia-imagemanager-acf'),
				],
			]
		);

		wp_enqueue_style(
			'fm-imagemanager-field',
			FM_IMAGEMANAGER_ACF_URL . 'assets/css/acf-imagemanager-field.css',
			['acf-input', 'dashicons'],
			FM_IMAGEMANAGER_ACF_VERSION
		);
	}

	/**
	 * Transform the raw stored value into the configured return format.
	 *
	 * Called by ACF whenever get_field() / the_field() is invoked, and by
	 * the GraphQL resolver (which calls get_field() internally).
	 *
	 * @param mixed          $value   Raw value from post_meta.
	 * @param int|string     $post_id Post / object ID.
	 * @param array          $field   Field configuration array.
	 * @return mixed String (URL formats) or array (metadata format).
	 */
	public function format_value($value, $post_id, $field) {
		if (empty($value)) {
			return $value;
		}

		$parsed   = feichtmedia_imagemanager_parse_value((string) $value);
		$image_id = $parsed['imageId'];
		$group_id = $parsed['groupId'];
		$domain   = get_option('feichtmedia_imagemanager_domain', '');

		switch ($field['return_format'] ?? 'relative_url') {
			case 'absolute_url':
				return feichtmedia_imagemanager_build_url($group_id, $image_id, $domain);

			case 'metadata':
				// Server-side API call with Transient cache (TTL 1 h). Never calls
				// the API directly — always goes through the caching helper.
				return feichtmedia_imagemanager_get_metadata($group_id, $image_id, $domain);

			case 'relative_url':
			default:
				return '/' . $group_id . '/' . $image_id;
		}
	}

	/**
	 * Normalise the value before it is written to post_meta.
	 *
	 * Strips any leading path segments so only the bare image ID (newFilename)
	 * is persisted. New saves from the JS file browser always write the image ID
	 * directly; this guard handles any legacy URLs that might slip through.
	 *
	 * @param mixed      $value   Value submitted by the form.
	 * @param int|string $post_id Post / object ID.
	 * @param array      $field   Field configuration array.
	 * @return string Image ID only, or empty string.
	 */
	public function update_value($value, $post_id, $field) {
		if (empty($value)) {
			return '';
		}

		$str = sanitize_text_field((string) $value);

		if (str_contains($str, '/')) {
			$parsed = feichtmedia_imagemanager_parse_value($str);
			return $parsed['imageId'];
		}

		return $str;
	}
}
