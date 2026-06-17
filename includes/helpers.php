<?php

/**
 * Stateless helper functions for FeichtMedia ImageManager for Advanced Custom Fields.
 *
 * Covers: value parsing (current + legacy formats), URL building,
 * API-to-canonical field mapping, and cached metadata fetching.
 *
 * @package FeichtMedia\ImageManagerACF
 */

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Parse a stored field value into its components.
 *
 * Handles both the current format (image ID only) and the legacy format
 * (relative URL: /{groupId}/{imageId} or with filter segments prepended,
 * e.g. /filters:blur(5)/wordpress/20260101-080000-image.jpg).
 *
 * The regex always extracts the last two path segments as groupId / imageId,
 * which correctly handles any number of leading filter segments.
 *
 * @param string $value Raw value from post_meta.
 * @return array{format: string, groupId: string, imageId: string}
 */
function feichtmedia_imagemanager_parse_value(string $value): array {
	if (str_contains($value, '/')) {
		// Legacy relative URL — extract the last two path segments.
		if (preg_match('#/([^/]+)/([^/]+)$#', $value, $matches)) {
			return [
				'format'  => 'legacy',
				'groupId' => $matches[1],
				'imageId' => $matches[2],
			];
		}
		// Unexpected format containing "/" but not matching — treat as image ID
		// to avoid data loss (better to build a potentially wrong URL than crash).
	}

	return [
		'format'  => 'current',
		'groupId' => (string) get_option('feichtmedia_imagemanager_project_id', ''),
		'imageId' => $value,
	];
}

/**
 * Build the CDN URL for an image.
 *
 * @param string $group_id Usergroup / project ID.
 * @param string $image_id Image filename (newFilename).
 * @param string $domain   CDN domain without protocol.
 * @return string Absolute CDN URL.
 */
function feichtmedia_imagemanager_build_url(string $group_id, string $image_id, string $domain): string {
	return 'https://' . $domain . '/' . $group_id . '/' . $image_id;
}

/**
 * Map raw ImageManager API image data to the canonical metadata array.
 *
 * Translates API field names (orgFilename, customTitle, altText, …) to the
 * snake_case keys exposed by format_value() and GraphQL resolvers.
 *
 * @param array  $data     Raw associative array from the ImageManager API.
 * @param string $group_id Usergroup / project ID.
 * @param string $image_id Image filename (newFilename).
 * @param string $domain   CDN domain without protocol.
 * @return array{
 *     org_filename: string|null,
 *     image_id: string,
 *     relative_url: string,
 *     absolute_url: string,
 *     title: string|null,
 *     alt: string|null,
 *     copyright: string|null,
 *     width: int,
 *     height: int,
 *     filetype: string|null,
 *     filesize: int
 * }
 */
function feichtmedia_imagemanager_map_image(array $data, string $group_id, string $image_id, string $domain): array {
	return [
		'org_filename' => ($data['orgFilename']    ?? '') ?: null,
		'image_id'     => $image_id,
		'relative_url' => '/' . $group_id . '/' . $image_id,
		'absolute_url' => feichtmedia_imagemanager_build_url($group_id, $image_id, $domain),
		'title'        => ($data['customTitle']    ?? '') ?: null,
		'alt'          => ($data['altText']        ?? '') ?: null,
		'copyright'    => ($data['copyrightInfos'] ?? '') ?: null,
		'width'        => (int)    ($data['width']          ?? 0),
		'height'       => (int)    ($data['height']         ?? 0),
		'filetype'     => ($data['filetype']       ?? '') ?: null,
		'filesize'     => (int)    ($data['filesize']       ?? 0),
	];
}

/**
 * Fetch image metadata from the ImageManager API, with Transient caching.
 *
 * Cache key: feichtmedia_imagemanager_acf_meta_{md5(imageId)}, TTL controlled by the
 * plugin setting feichtmedia_imagemanager_acf_cache_ttl (default 3600 s). Caching can
 * be disabled entirely via feichtmedia_imagemanager_acf_cache_enabled.
 * On API error, returns minimal data built from locally known values so that
 * format_value() can still return a usable (if incomplete) result.
 *
 * @param string $group_id Usergroup / project ID.
 * @param string $image_id Image filename (newFilename).
 * @param string $domain   CDN domain without protocol.
 * @return array Canonical metadata array (see feichtmedia_imagemanager_map_image()).
 */
function feichtmedia_imagemanager_get_metadata(string $group_id, string $image_id, string $domain): array {
	$cache_key     = 'feichtmedia_imagemanager_acf_meta_' . md5($image_id);
	$cache_enabled = (bool) get_option('feichtmedia_imagemanager_acf_cache_enabled', 1);

	if ($cache_enabled) {
		$cached = get_transient($cache_key);
		if ($cached !== false) {
			return (array) $cached;
		}
	}

	$api_key  = get_option('feichtmedia_imagemanager_api_key', '');
	$response = wp_remote_get(
		FM_IMAGEMANAGER_API_URL . '/images/' . rawurlencode($image_id),
		[
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Accept'        => 'application/json',
				'User-Agent'    => apply_filters('http_headers_useragent', 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'), FM_IMAGEMANAGER_API_URL . '/images/' . $image_id) . ' FeichtMedia-ImageManager-ACF/' . FM_IMAGEMANAGER_ACF_VERSION,
			],
			'timeout' => 15,
		]
	);

	// On any error, return minimal data from locally known values.
	if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
		return [
			'org_filename' => null,
			'image_id'     => $image_id,
			'relative_url' => '/' . $group_id . '/' . $image_id,
			'absolute_url' => feichtmedia_imagemanager_build_url($group_id, $image_id, $domain),
			'title'        => null,
			'alt'          => null,
			'copyright'    => null,
			'width'        => 0,
			'height'       => 0,
			'filetype'     => null,
			'filesize'     => 0,
		];
	}

	$body = json_decode(wp_remote_retrieve_body($response), true) ?? [];
	$data = $body['data'] ?? [];
	$meta = feichtmedia_imagemanager_map_image($data, $group_id, $image_id, $domain);

	if ($cache_enabled) {
		$ttl = (int) get_option('feichtmedia_imagemanager_acf_cache_ttl', 3600);
		set_transient($cache_key, $meta, $ttl);
	}

	return $meta;
}
