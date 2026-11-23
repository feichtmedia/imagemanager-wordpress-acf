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
 * e.g. /filters:blur(5)/wordpress/20260101-080000-image.jpg). The leading
 * slash is optional ({groupId}/{imageId}).
 *
 * The regex always extracts the last two path segments as groupId / imageId,
 * which correctly handles any number of leading filter segments.
 *
 * @param string $value Raw value from post_meta.
 * @return array{format: string, groupId: string, imageId: string}
 */
function feichtmedia_imagemanager_parse_value(string $value): array {
	if (str_contains($value, '/')) {
		// Legacy relative URL — extract the last two path segments. The groupId
		// segment may start the string: values without a leading slash
		// ("wordpress/image.jpg") must not fall through to the bare-ID branch, which
		// would prepend the project ID to the whole path.
		if (preg_match('#(?:^|/)([^/]+)/([^/]+)$#', $value, $matches)) {
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
		'groupId' => (string) FM_ImageManager_Core::get_setting('feichtmedia_imagemanager_project_id', ''),
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
 * Fetch image metadata from the ImageManager API, with Transient caching and stale-if-error.
 *
 * Cache entries are stored as `['meta' => array, 'expires' => int]`: they are fresh
 * until `expires` (the TTL setting, see feichtmedia_imagemanager_get_metadata_cache_ttl())
 * and stay in the cache until feichtmedia_imagemanager_get_metadata_stale_ttl() as
 * the fallback for a failed refresh. Caching (and with it the fallback) can be
 * disabled via feichtmedia_imagemanager_acf_cache_enabled. Key: see
 * feichtmedia_imagemanager_get_metadata_cache_key(); without an entry under it, an
 * entry written by 1.3.x (legacy key) is returned until it expires.
 *
 * Never fails: when the API cannot deliver (timeout, error status, invalid response,
 * circuit breaker open — see FM_ImageManager_API_Client), the last known metadata is
 * returned, or without one an array built from locally known values only (URLs; title,
 * alt, copyright etc. null), so format_value() can still return a usable result.
 *
 * @param string $group_id Usergroup / project ID.
 * @param string $image_id Image filename (newFilename).
 * @param string $domain   CDN domain without protocol.
 * @return array Canonical metadata array (see feichtmedia_imagemanager_map_image()).
 */
function feichtmedia_imagemanager_get_metadata(string $group_id, string $image_id, string $domain): array {
	$cache_enabled = (bool) FM_ImageManager_Core::get_setting('feichtmedia_imagemanager_acf_cache_enabled', 1);
	$cache_key     = $cache_enabled ? feichtmedia_imagemanager_get_metadata_cache_key($image_id) : '';
	$stale         = null;

	if ($cache_enabled) {
		$cached = get_transient($cache_key);

		if (is_array($cached) && isset($cached['meta'], $cached['expires']) && is_array($cached['meta'])) {
			if ((int) $cached['expires'] > time()) {
				return $cached['meta'];
			}
			// Expired: refresh below, but keep it as the fallback in case the API fails.
			$stale = $cached['meta'];
		} else {
			// Entry written by 1.3.x (bare metadata array under the unversioned key, stored with
			// the TTL as transient expiry): fresh until the transient itself expires.
			$legacy = get_transient(feichtmedia_imagemanager_get_metadata_cache_key($image_id, true));
			if (is_array($legacy) && [] !== $legacy) {
				return $legacy;
			}
		}
	}

	$response = FM_ImageManager_API_Client::get('/images/' . rawurlencode($image_id));
	$data     = (! is_wp_error($response) && 200 === $response['status']) ? ($response['body']['data'] ?? null) : null;

	// Metadata only if `data` has a field the mapping reads: anything else (e.g. an error object
	// after an API change) would replace the fallback with empty values for the whole TTL.
	$known_fields = ['orgFilename', 'customTitle', 'altText', 'copyrightInfos', 'width', 'height', 'filetype', 'filesize'];

	if (! is_array($data) || [] === array_intersect_key($data, array_flip($known_fields))) {
		// Never cached: the next request after the outage fetches again.
		return $stale ?? feichtmedia_imagemanager_map_image([], $group_id, $image_id, $domain);
	}

	$meta = feichtmedia_imagemanager_map_image($data, $group_id, $image_id, $domain);

	if ($cache_enabled) {
		set_transient(
			$cache_key,
			[
				'meta'    => $meta,
				'expires' => time() + feichtmedia_imagemanager_get_metadata_cache_ttl(),
			],
			feichtmedia_imagemanager_get_metadata_stale_ttl()
		);
	}

	return $meta;
}

/**
 * Build the transient name for an image's cached metadata.
 *
 * Format: feichtmedia_imagemanager_acf_meta_{md5('v2|' . salt . imageId)}. The per-site
 * salt (option feichtmedia_imagemanager_acf_cache_salt) is rotated on every flush, which
 * invalidates all entries at once — including those in a persistent object cache,
 * where the query in feichtmedia_imagemanager_delete_metadata_transients() cannot
 * reach them. A site without a salt is flushed first, so entries written before the
 * salt existed (keyed by image ID only) do not linger in the options table.
 *
 * 'v2|' marks the stale-if-error entry format (1.4.0). 1.3.x stored bare metadata arrays
 * under md5(salt . imageId) and returns whatever it finds there as the field value, so
 * after a downgrade it would hand ['meta' => …, 'expires' => …] to templates if both
 * formats shared a key.
 *
 * @param string $image_id Image filename (newFilename).
 * @param bool   $legacy   Return the key of the 1.3.x format (read only, during the transition).
 * @return string Transient name.
 */
function feichtmedia_imagemanager_get_metadata_cache_key(string $image_id, bool $legacy = false): string {
	// Internal per-site state, not a setting — read directly, never via get_setting().
	$salt = (string) get_option('feichtmedia_imagemanager_acf_cache_salt', '');

	if ('' === $salt) {
		feichtmedia_imagemanager_flush_metadata_cache();
		$salt = (string) get_option('feichtmedia_imagemanager_acf_cache_salt', '');
	}

	return 'feichtmedia_imagemanager_acf_meta_' . md5(($legacy ? '' : 'v2|') . $salt . $image_id);
}

/**
 * Resolve the effective metadata cache lifetime.
 *
 * Reads feichtmedia_imagemanager_acf_cache_ttl (default 3600 s) and caps it at 30 days.
 * The cap also replaces 0 ("no expiry"): WordPress autoloads transients without
 * expiration on every request and never purges them. 30 days is the longest lifetime
 * Memcached accepts as relative — larger values are read as a Unix timestamp in the
 * past, so the entry would expire immediately.
 *
 * @return int Lifetime in seconds, between 1 and MONTH_IN_SECONDS.
 */
function feichtmedia_imagemanager_get_metadata_cache_ttl(): int {
	$ttl = (int) FM_ImageManager_Core::get_setting('feichtmedia_imagemanager_acf_cache_ttl', 3600);

	return ($ttl <= 0 || $ttl > MONTH_IN_SECONDS) ? MONTH_IN_SECONDS : $ttl;
}

/**
 * Resolve how long cached metadata stays available as a fallback (stale-if-error).
 *
 * This is the transient expiry of a metadata entry, counted from its last successful
 * fetch; the entry is fresh only for feichtmedia_imagemanager_get_metadata_cache_ttl().
 * Default and maximum: 30 days (Memcached limit, see the TTL function). It is never
 * shorter than the TTL, so a TTL of 30 days leaves no fallback window.
 *
 * @return int Lifetime in seconds, between the cache TTL and MONTH_IN_SECONDS.
 */
function feichtmedia_imagemanager_get_metadata_stale_ttl(): int {
	/**
	 * Filters how long cached image metadata stays available as a fallback when the
	 * ImageManager API fails. Return 0 to keep entries only for the cache TTL.
	 *
	 * @param int $lifetime Seconds after the last successful fetch, default MONTH_IN_SECONDS.
	 */
	$lifetime = (int) apply_filters('feichtmedia_imagemanager_acf_stale_ttl', MONTH_IN_SECONDS);

	return min(MONTH_IN_SECONDS, max(feichtmedia_imagemanager_get_metadata_cache_ttl(), $lifetime));
}

/**
 * Invalidate all cached image metadata of the current site.
 *
 * Rotates the cache key salt (see feichtmedia_imagemanager_get_metadata_cache_key()),
 * then deletes the stored transients from the options table.
 *
 * @return int Number of cached images removed from the options table. Always 0 with a
 *             persistent object cache, whose entries are invalidated but not deleted.
 */
function feichtmedia_imagemanager_flush_metadata_cache(): int {
	update_option('feichtmedia_imagemanager_acf_cache_salt', wp_generate_password(12, false), true);

	return feichtmedia_imagemanager_delete_metadata_transients();
}

/**
 * Delete all cached image metadata transients of the current site.
 *
 * Uses a direct query because transient keys are hashed and cannot be enumerated
 * via the Transients API. Reads $wpdb->options at call time so it targets the
 * right table after switch_to_blog().
 *
 * @return int Number of deleted transients (timeout rows not counted).
 */
function feichtmedia_imagemanager_delete_metadata_transients(): int {
	global $wpdb;

	$count = (int) $wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '\_transient\_feichtmedia\_imagemanager\_acf\_meta\_%'"
	);
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '\_transient\_timeout\_feichtmedia\_imagemanager\_acf\_meta\_%'"
	);

	// Transients stored without expiry were autoloaded — drop the cached copy of all
	// autoloaded options so a persistent object cache does not keep serving them.
	if ($count > 0) {
		wp_cache_delete('alloptions', 'options');
	}

	return $count;
}
