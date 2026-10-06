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
	 * Check whether the current user may use the proxy.
	 *
	 * The file browser does not belong to a single post: the field also sits in ACF
	 * blocks, on term screens and in objects that are not saved yet, so there is no
	 * object to check a capability against. Allowed is who may edit content
	 * somewhere in the admin, see current_user_can_edit_content().
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return bool
	 */
	public function check_permission(WP_REST_Request $request): bool {
		/**
		 * Filters whether the current user may use the REST proxy of the file browser.
		 *
		 * Widen the rule for fields on screens that require other capabilities
		 * (options pages, user profiles), or narrow it. The proxy serves the whole
		 * image library of the ImageManager project.
		 *
		 * @param bool            $allowed Whether the user may edit posts of a post type or terms of a taxonomy with an admin UI.
		 * @param WP_REST_Request $request Incoming request.
		 */
		$allowed = apply_filters('feichtmedia_imagemanager_acf_proxy_permission', $this->current_user_can_edit_content(), $request);

		// Only true grants access. The REST server denies on false, null and WP_Error
		// only, so a value such as 0 returned by a callback would let the request pass.
		return true === $allowed;
	}

	/**
	 * Check whether the current user may edit content the field can be placed on.
	 *
	 * Follows WP_REST_Block_Types_Controller::check_read_permission(): `edit_posts`,
	 * or the `edit_posts` capability of any post type. Two differences: post types
	 * are selected by `show_ui` instead of `show_in_rest`, because ACF fields also
	 * sit on post types without REST support, and taxonomies with an admin UI count
	 * as well (term screens).
	 *
	 * This runs on every proxy request, and each current_user_can() call runs the
	 * `map_meta_cap` and `user_has_cap` filters that role and permission plugins hook
	 * into. The order of the checks keeps the number of calls low:
	 *
	 *  - Logged-out requests are answered without any call.
	 *  - Roles with `edit_posts` need one call, as before.
	 *  - Other allowed users usually need two: the capabilities stored for the user
	 *    are checked first, independent of how many post types are registered.
	 *  - Only users without access run through all capabilities, each one once.
	 *
	 * @return bool
	 */
	private function current_user_can_edit_content(): bool {
		if (! is_user_logged_in()) {
			return false;
		}

		if (current_user_can('edit_posts')) {
			return true;
		}

		$caps = $this->get_edit_capabilities();

		// The stored capabilities only set the order. current_user_can() stays the
		// authority, because filters grant and revoke capabilities at runtime.
		$caps = array_intersect_key($caps, array_filter(wp_get_current_user()->allcaps)) + $caps;

		foreach (array_keys($caps) as $cap) {
			if (current_user_can($cap)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Collect the capabilities that allow editing posts or terms in the admin.
	 *
	 * @return array<string, true> Capability names as keys, so that a capability shared by several post types or taxonomies is listed once.
	 */
	private function get_edit_capabilities(): array {
		$caps = [];

		foreach (get_post_types(['show_ui' => true], 'objects') as $post_type) {
			$caps[$post_type->cap->edit_posts] = true;
		}

		foreach (get_taxonomies(['show_ui' => true], 'objects') as $taxonomy) {
			$caps[$taxonomy->cap->edit_terms]   = true;
			$caps[$taxonomy->cap->manage_terms] = true;
		}

		// Already checked by the caller.
		unset($caps['edit_posts']);

		return $caps;
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
		$api_key = FM_ImageManager_Core::get_setting('feichtmedia_imagemanager_api_key', '');

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
