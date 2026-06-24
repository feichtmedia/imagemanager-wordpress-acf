<?php

/**
 * FM_ImageManager_GraphQL
 *
 * Registers the imagemanager_image field type with WPGraphQL for ACF v2.x.
 * Loaded only when WPGraphQL for ACF is active (function_exists check in the
 * main plugin file). The resolver calls get_field() which runs format_value(),
 * so no extra API calls are made at the GraphQL layer.
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
	 * get_field() so format_value() runs and no additional API calls are needed.
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
					$field_name    = $acf_field['name'];
					$source_id     = is_array($root) ? ($root['databaseId'] ?? null) : ($root->databaseId ?? null);

					// When source_id is null and the field name exists as a key in $root,
					// we are inside a repeater row. WPGraphQL for ACF v2.x fetches repeater
					// rows via get_field() on the parent, so format_value() has already run
					// on every sub-field; calling get_field() again with a null ID would
					// query the global post context and miss the repeater sub-field entirely.
					if (is_null($source_id) && is_array($root) && array_key_exists($field_name, $root)) {
						$value = $root[$field_name];
					} else {
						$value = get_field($field_name, $source_id);
					}

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
}
