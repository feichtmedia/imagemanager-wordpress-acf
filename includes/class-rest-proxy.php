<?php

/**
 * FM_ImageManager_REST_Proxy
 *
 * Registers four read-only WP REST API routes that forward requests to the
 * ImageManager API. The API key is injected server-side from wp_options and
 * never exposed to the browser.
 *
 * Namespace: feichtmedia/imagemanager/v2
 *
 * @package FeichtMedia\ImageManagerACF
 */

if (! defined('ABSPATH')) {
	exit;
}

class FM_ImageManager_REST_Proxy {

	private const NAMESPACE = 'feichtmedia/imagemanager/v2';

	/**
	 * Whitelisted query parameters per route. Only these are forwarded upstream.
	 */
	private const PARAM_WHITELIST = [
		'images'     => ['offset', 'limit', 'orderBy', 'order', 'search', 'category', 'filetype', 'startDate', 'endDate'],
		'image'      => ['includeCategory', 'includeProject'],
		'categories' => ['parentCategory', 'offset', 'limit', 'orderBy', 'order', 'search', 'includeParent'],
		'category'   => ['includeParent', 'includeChildren', 'includeProject', 'includePath'],
	];

	/**
	 * Register REST API hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action('rest_api_init', [$this, 'register_routes']);
	}

	/**
	 * Register all proxy routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/images',
			[
				'methods'             => 'GET',
				'callback'            => [$this, 'proxy_images'],
				'permission_callback' => [$this, 'check_permission'],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/images/(?P<imageId>[\w.\-]+)',
			[
				'methods'             => 'GET',
				'callback'            => [$this, 'proxy_single_image'],
				'permission_callback' => [$this, 'check_permission'],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/categories',
			[
				'methods'             => 'GET',
				'callback'            => [$this, 'proxy_categories'],
				'permission_callback' => [$this, 'check_permission'],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/categories/(?P<categoryId>[\d]+)',
			[
				'methods'             => 'GET',
				'callback'            => [$this, 'proxy_single_category'],
				'permission_callback' => [$this, 'check_permission'],
			]
		);
	}

	/**
	 * Verify the current user has edit_posts capability.
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can('edit_posts');
	}

	// --- Route callbacks ---------------------------------------------------

	/**
	 * Proxy GET /images to the upstream API.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function proxy_images(WP_REST_Request $request): WP_REST_Response {
		return $this->forward('/images', $request, self::PARAM_WHITELIST['images']);
	}

	/**
	 * Proxy GET /images/{imageId} to the upstream API.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function proxy_single_image(WP_REST_Request $request): WP_REST_Response {
		$image_id = $request->get_param('imageId');
		return $this->forward('/images/' . rawurlencode($image_id), $request, self::PARAM_WHITELIST['image']);
	}

	/**
	 * Proxy GET /categories to the upstream API.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function proxy_categories(WP_REST_Request $request): WP_REST_Response {
		return $this->forward('/categories', $request, self::PARAM_WHITELIST['categories']);
	}

	/**
	 * Proxy GET /categories/{categoryId} to the upstream API.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function proxy_single_category(WP_REST_Request $request): WP_REST_Response {
		$category_id = $request->get_param('categoryId');
		return $this->forward('/categories/' . rawurlencode($category_id), $request, self::PARAM_WHITELIST['category']);
	}

	// --- Core proxy logic --------------------------------------------------

	/**
	 * Forward a request to the ImageManager API with the stored API key.
	 *
	 * Only whitelisted query parameters are forwarded; all others are silently
	 * dropped to prevent parameter injection.
	 *
	 * The User-Agent header appends the plugin identifier to the default
	 * WordPress user agent so proxy requests are identifiable in upstream logs.
	 *
	 * @param string          $path           Upstream path (e.g. '/images').
	 * @param WP_REST_Request $request        Incoming WP REST request.
	 * @param string[]        $allowed_params Whitelisted query parameter names.
	 * @return WP_REST_Response
	 */
	private function forward(string $path, WP_REST_Request $request, array $allowed_params): WP_REST_Response {
		$api_key = get_option('feichtmedia_imagemanager_api_key', '');

		if (empty($api_key)) {
			return new WP_REST_Response(
				[
					'error'   => 'missing_api_key',
					'message' => 'ImageManager API key is not configured.',
				],
				500
			);
		}

		// Build query string from whitelisted params only.
		$query = [];
		foreach ($allowed_params as $param) {
			$value = $request->get_param($param);
			if ($value !== null && $value !== '') {
				$query[$param] = $value;
			}
		}

		$url = FM_IMAGEMANAGER_API_URL . $path;
		if (! empty($query)) {
			$url .= '?' . http_build_query($query);
		}

		$base_ua    = apply_filters('http_headers_useragent', 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'), $url);
		$user_agent = $base_ua . ' FeichtMedia-ImageManager-ACF/' . FM_IMAGEMANAGER_ACF_VERSION;

		$response = wp_remote_get(
			$url,
			[
				'headers'    => [
					'Authorization' => 'Bearer ' . $api_key,
					'Accept'        => 'application/json',
				],
				'user-agent' => $user_agent,
				'timeout'    => 15,
			]
		);

		if (is_wp_error($response)) {
			return new WP_REST_Response(
				[
					'error'   => 'upstream_error',
					'message' => $response->get_error_message(),
				],
				502
			);
		}

		$status = wp_remote_retrieve_response_code($response);
		$body   = json_decode(wp_remote_retrieve_body($response), true);

		return new WP_REST_Response($body ?? [], (int) $status);
	}
}
