<?php

/**
 * FM_ImageManager_GraphQL
 *
 * Registers the imagemanager_image field type with WPGraphQL for ACF v2.x.
 * Loaded only when WPGraphQL for ACF is active (function_exists check in the
 * main plugin file). The resolver reads pre-resolved values from $root when a
 * parent field (repeater, group, flexible content) has already fetched them,
 * and otherwise calls get_field() which runs format_value() — no extra API
 * calls are made at the GraphQL layer either way.
 *
 * Return types:
 *   - return_format relative_url | absolute_url → String
 *   - return_format metadata                    → ImageManagerImage (custom object type)
 *
 * @package FeichtMedia\ImageManagerACF
 */

if (! defined('ABSPATH')) {
	exit;
}

class FM_ImageManager_GraphQL {

	/**
	 * Register GraphQL hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action('graphql_register_types', [$this, 'register_graphql_types']);
		$this->register_acf_field_type();
	}

	/**
	 * Register the custom ImageManagerImage object type with WPGraphQL.
	 *
	 * @return void
	 */
	public function register_graphql_types(): void {
		register_graphql_object_type(
			'ImageManagerImage',
			[
				'description' => __('An image from the FeichtMedia ImageManager', 'feichtmedia-imagemanager-acf'),
				'fields'      => [
					'imageId'     => [
						'type'        => ['non_null' => 'String'],
						'description' => 'Unique image identifier (newFilename).',
					],
					'relativeUrl' => [
						'type'        => ['non_null' => 'String'],
						'description' => 'Relative CDN path, e.g. /wordpress/image.jpg.',
					],
					'absoluteUrl' => [
						'type'        => ['non_null' => 'String'],
						'description' => 'Absolute CDN URL, e.g. https://cdn.example.com/wordpress/image.jpg.',
					],
					'orgFilename' => [
						'type'        => 'String',
						'description' => 'Original filename at upload.',
					],
					'title'       => [
						'type'        => 'String',
						'description' => 'User-defined image title.',
					],
					'alt'         => [
						'type'        => 'String',
						'description' => 'Alt text for accessibility.',
					],
					'copyright'   => [
						'type'        => 'String',
						'description' => 'Copyright information.',
					],
					'width'       => [
						'type'        => 'Int',
						'description' => 'Image width in pixels.',
					],
					'height'      => [
						'type'        => 'Int',
						'description' => 'Image height in pixels.',
					],
					'filetype'    => [
						'type'        => 'String',
						'description' => 'File extension, e.g. jpg, png, webp.',
					],
					'filesize'    => [
						'type'        => 'Int',
						'description' => 'File size in bytes.',
					],
				],
			]
		);
	}

	/**
	 * Register the imagemanager_image ACF field type with WPGraphQL for ACF v2.x.
	 *
	 * Uses the register_graphql_acf_field_type() API introduced in v2.0. The
	 * graphql_type callable inspects return_format at schema-build time to choose
	 * between String and ImageManagerImage. The resolve callable delegates to
	 * resolve_field_value(), which always ends up running format_value() exactly
	 * once, so no additional API calls are needed.
	 *
	 * @return void
	 */
	protected function register_acf_field_type(): void {
		register_graphql_acf_field_type(
			'imagemanager_image',
			[
				'graphql_type' => function (\WPGraphQL\Acf\FieldConfig $field_config): string {
					$acf_field     = $field_config->get_acf_field();
					$return_format = $acf_field['return_format'] ?? 'relative_url';

					return $return_format === 'metadata' ? 'ImageManagerImage' : 'String';
				},

				'resolve' => function ($root, array $_args, $_context, $_info, $_field_type, \WPGraphQL\Acf\FieldConfig $field_config) {
					$acf_field     = $field_config->get_acf_field();
					$return_format = $acf_field['return_format'] ?? 'relative_url';

					$value = $this->resolve_field_value($root, $acf_field);

					if ($return_format === 'metadata') {
						if (empty($value) || ! is_array($value)) {
							return null;
						}
						// Map snake_case ACF keys to camelCase GraphQL fields.
						return [
							'imageId'     => $value['image_id']     ?? '',
							'relativeUrl' => $value['relative_url'] ?? '',
							'absoluteUrl' => $value['absolute_url'] ?? '',
							'orgFilename' => $value['org_filename'] ?? null,
							'title'       => $value['title']        ?? null,
							'alt'         => $value['alt']          ?? null,
							'copyright'   => $value['copyright']    ?? null,
							'width'       => isset($value['width'])    ? (int) $value['width']    : null,
							'height'      => isset($value['height'])   ? (int) $value['height']   : null,
							'filetype'    => $value['filetype']     ?? null,
							'filesize'    => isset($value['filesize']) ? (int) $value['filesize'] : null,
						];
					}

					return $value ?: null;
				},
			]
		);
	}

	/**
	 * Resolve the formatted field value from the GraphQL $root.
	 *
	 * WPGraphQL for ACF v2.x passes a different $root shape depending on where
	 * the field lives:
	 *
	 *   - Top level of a field group: ['node' => <Model>, 'acf_field_group' => …].
	 *     The value is not in $root — fetch it via get_field() with the node's
	 *     ACF ID (posts, terms, users, options pages).
	 *   - Repeater row / flexible content layout: the parent resolver fetched
	 *     the rows via get_field() WITH formatting, so $root holds the already
	 *     formatted value keyed by the sub-field NAME.
	 *   - ACF group: the group resolver fetches the group value WITHOUT
	 *     formatting; ACF's group load_value() keys sub-values by field KEY, so
	 *     $root holds the raw stored value (bare image ID) — format_value()
	 *     must still be applied via format_raw_value().
	 *   - ACF block: ['node' => <parsed block array>, …]. The values live in
	 *     the block comment's attrs in post_content, not in post meta — the
	 *     block data must be registered as meta before get_field() can see it.
	 *
	 * @param mixed        $root      The GraphQL root passed to the resolver.
	 * @param array<mixed> $acf_field The ACF field configuration.
	 * @return mixed Formatted value (string or metadata array), or null.
	 */
	protected function resolve_field_value($root, array $acf_field) {
		if (is_array($root)) {
			// Formatted value passed down by a repeater row or flexible content
			// layout, keyed by field name. The is_object guard protects against
			// a field unluckily named 'node' colliding with the top-level root.
			$field_name = $acf_field['name'];
			if (array_key_exists($field_name, $root) && ! is_object($root[$field_name])) {
				return $root[$field_name];
			}

			// Raw value passed down by a group resolver, keyed by field key
			// ('__key' for cloned fields). A string here is the raw stored
			// value; an array is a metadata value that is already formatted.
			foreach ([$acf_field['key'] ?? null, $acf_field['__key'] ?? null] as $key) {
				if (null !== $key && isset($root[$key]) && '' !== $root[$key]) {
					return is_array($root[$key])
						? $root[$key]
						: $this->format_raw_value($root[$key], $acf_field);
				}
			}
		}

		$node = is_array($root) ? ($root['node'] ?? null) : null;

		// ACF block: the values live in the block comment's attrs inside
		// post_content, not in post meta — get_field() against the post would
		// find nothing. Mirror WPGraphQL for ACF's own block handling: register
		// the block data as meta under the block ID, then resolve via
		// get_field() so format_value() runs (function_exists guard: ACF
		// blocks are PRO-only).
		if (
			is_array($node)
			&& isset($node['blockName'], $node['attrs'])
			&& function_exists('acf_prepare_block')
		) {
			$block = $node['attrs'];
			if (! isset($block['id'])) {
				$block['id'] = uniqid('block_', true);
			}

			$block    = acf_prepare_block($block);
			$block_id = acf_ensure_block_id_prefix(acf_get_block_id($node['attrs']));

			acf_setup_meta($block['data'] ?? [], $block_id, true);
			$value = get_field($acf_field['name'], $block_id);
			acf_reset_meta($block_id);

			// Fallback for attrs that acf_setup_meta() could not expose
			// (e.g. an unregistered block type): read the raw value straight
			// from the block data and format it ourselves.
			if (empty($value) && isset($node['attrs']['data'][$acf_field['name']]) && '' !== $node['attrs']['data'][$acf_field['name']]) {
				$value = $this->format_raw_value($node['attrs']['data'][$acf_field['name']], $acf_field);
			}

			return $value ?: null;
		}

		// Top level: resolve against the node the field group is attached to.
		// get_node_acf_id() returns the ACF-style ID ('term_x', 'user_x', …),
		// so non-post locations resolve correctly too.
		$source_id = null;

		if (null !== $node) {
			$source_id = \WPGraphQL\Acf\Utils::get_node_acf_id($node);
		} elseif (is_array($root) && isset($root['databaseId'])) {
			$source_id = $root['databaseId'];
		} elseif (is_object($root) && isset($root->databaseId)) {
			$source_id = $root->databaseId;
		}

		return get_field($acf_field['name'], $source_id);
	}

	/**
	 * Run ACF's format_value filters on a raw stored value.
	 *
	 * Deliberately does NOT use acf_format_value(): that wrapper caches the
	 * result in the 'values' store under "$post_id:$field_name:formatted".
	 * We format with post_id 0 (the real ID is not available inside nested
	 * resolvers, and our format_value() ignores it), so two sub-fields with
	 * the same name in different groups (e.g. img_src) would collide on the
	 * cache key "0:img_src:formatted" and the second field would silently
	 * return the first field's value. Applying the filters directly is
	 * exactly what acf_format_value() does internally, minus the cache.
	 *
	 * @param mixed        $value     Raw stored value (bare image ID or legacy URL).
	 * @param array<mixed> $acf_field The ACF field configuration.
	 * @return mixed Formatted value (string or metadata array).
	 */
	protected function format_raw_value($value, array $acf_field) {
		$check = apply_filters('acf/pre_format_value', null, $value, 0, $acf_field, false);
		if (null !== $check) {
			return $check;
		}

		return apply_filters('acf/format_value', $value, 0, $acf_field, false);
	}
}
