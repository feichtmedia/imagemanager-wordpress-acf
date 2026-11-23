<?php

/**
 * FM_ImageManager_API_Client
 *
 * Single entry point for all server-side requests to the ImageManager API
 * (metadata fetch and REST proxy). Keeps a slow or failing API from blocking
 * WordPress:
 *
 * - Hard timeouts per context: render (metadata on page views) 2 s connect /
 *   3 s total, proxy (file browser in the editor) 3 s / 10 s. Overridable via
 *   constants in wp-config.php and the feichtmedia_imagemanager_acf_api_timeouts
 *   filter (see get_timeouts()).
 * - The shared circuit breaker (FM_ImageManager_Circuit_Breaker).
 * - Per PHP request: all requests share the total timeout as a time budget, and
 *   after a transport error (timeout, connection failure) no further request is
 *   sent, so a page with many images waits about one timeout, also when the API
 *   answers slowly. Not on WP-CLI, whose processes may run for hours.
 * - No redirects: a redirect would resend the API key and restart the timeout.
 * - Error logging throttled to one entry per minute, plus an action per failure.
 *
 * Never throws: every failure is returned as a WP_Error.
 *
 * @package FeichtMedia\ImageManagerACF
 */

if (! defined('ABSPATH')) {
	exit;
}

class FM_ImageManager_API_Client {

	/**
	 * Context for requests made while rendering field values (format_value(), GraphQL).
	 */
	public const CONTEXT_RENDER = 'render';

	/**
	 * Context for requests made by the REST proxy for the file browser in the editor.
	 */
	public const CONTEXT_PROXY = 'proxy';

	/**
	 * Site transient that throttles error logging.
	 */
	public const LOG_LOCK_KEY = 'feichtmedia_imagemanager_acf_api_log_lock';

	/**
	 * Minimum interval between two failure log entries, in seconds.
	 */
	private const LOG_INTERVAL = MINUTE_IN_SECONDS;

	/**
	 * Whether a transport error occurred earlier in this PHP request.
	 *
	 * @var bool
	 */
	private static bool $transport_failed = false;

	/**
	 * Seconds spent waiting for the API so far in this PHP request.
	 *
	 * @var float
	 */
	private static float $seconds_waited = 0.0;

	/**
	 * Send a GET request to the ImageManager API.
	 *
	 * Responses with status 2xx and 4xx (except 429) count as success for the circuit
	 * breaker — the API answered — and are returned with their status, so callers
	 * decide what a 404 means.
	 *
	 * @param string $path    Path below FM_IMAGEMANAGER_API_URL including the query string, e.g. '/images/x.jpg'.
	 * @param string $context self::CONTEXT_RENDER or self::CONTEXT_PROXY; selects the timeouts.
	 * @return array{status: int, body: array}|WP_Error Decoded response, or a WP_Error with one of the codes
	 *     fm_imagemanager_unavailable (skipped: circuit breaker open, time budget of this PHP request
	 *     used up or earlier transport error),
	 *     fm_imagemanager_unreachable (timeout, connection or DNS failure),
	 *     fm_imagemanager_server_error (status 3xx, 5xx, 429 or below 200),
	 *     fm_imagemanager_invalid_response (2xx without a JSON object or array).
	 *     The error data holds the HTTP status the REST proxy should answer with.
	 */
	public static function get(string $path, string $context = self::CONTEXT_RENDER): array|WP_Error {
		$timeouts = self::get_timeouts($context);

		// A WP-CLI process may render thousands of posts over hours; there the per-request limits would
		// stop API calls for the rest of the run, and no PHP-FPM worker is blocked anyway.
		$limit_request = ! (defined('WP_CLI') && WP_CLI);
		// All requests of this PHP request share the total timeout as a budget, so a slow but
		// answering API cannot add up one long wait per image.
		$remaining = $timeouts['timeout'] - self::$seconds_waited;
		$skip      = $limit_request && (self::$transport_failed || $remaining <= 0);

		if ($limit_request && ! $skip) {
			// cURL enforces at least 1 s, so a page waits at most the budget plus 1 s.
			$timeouts['timeout']         = max(1.0, min($timeouts['timeout'], $remaining));
			$timeouts['connect_timeout'] = min($timeouts['connect_timeout'], $timeouts['timeout']);
		}

		// The breaker is asked last: in the half-open state, allow_request() claims the probe.
		if ($skip || ! FM_ImageManager_Circuit_Breaker::allow_request((int) ceil($timeouts['timeout']) + 1)) {
			return new WP_Error(
				'fm_imagemanager_unavailable',
				'ImageManager API calls are paused after failed or slow requests.',
				['status' => 503]
			);
		}

		$url      = FM_IMAGEMANAGER_API_URL . $path;
		$api_key  = (string) FM_ImageManager_Core::get_setting('feichtmedia_imagemanager_api_key', '');
		// Core filter, applied like WP_Http does, which skips it once a user-agent is passed.
		$base_ua  = apply_filters('http_headers_useragent', 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'), $url); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$started  = microtime(true);
		$response = self::remote_get(
			$url,
			[
				'headers'     => [
					'Authorization' => 'Bearer ' . $api_key,
					'Accept'        => 'application/json',
				],
				'user-agent'  => $base_ua . ' FeichtMedia-ImageManager-ACF/' . FM_IMAGEMANAGER_ACF_VERSION,
				'timeout'     => $timeouts['timeout'],
				// A redirect would send the API key to its target and start a new timeout per hop.
				'redirection' => 0,
			],
			$timeouts['connect_timeout']
		);

		self::$seconds_waited += microtime(true) - $started;

		if (is_wp_error($response)) {
			// Every further request in this PHP request would most likely wait just as long.
			self::$transport_failed = true;

			return self::fail(
				new WP_Error('fm_imagemanager_unreachable', $response->get_error_message(), ['status' => 504]),
				$path,
				$context
			);
		}

		$status = (int) wp_remote_retrieve_response_code($response);

		// 3xx: redirects are not followed (see above), and the API never redirects its JSON endpoints.
		if ($status < 200 || ($status >= 300 && $status < 400) || $status >= 500 || 429 === $status) {
			return self::fail(
				new WP_Error('fm_imagemanager_server_error', sprintf('HTTP status %d', $status), ['status' => 502]),
				$path,
				$context
			);
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);

		if (! is_array($body)) {
			// A 2xx without JSON is a broken API (e.g. an HTML maintenance page), a 4xx
			// without JSON is still a definite answer.
			if ($status < 300) {
				return self::fail(
					new WP_Error('fm_imagemanager_invalid_response', sprintf('HTTP status %d without a JSON body', $status), ['status' => 502]),
					$path,
					$context
				);
			}
			$body = [];
		}

		$previous_failures = FM_ImageManager_Circuit_Breaker::record_success();
		if ($previous_failures >= FM_ImageManager_Circuit_Breaker::threshold()) {
			// Logged unthrottled: it happens at most once per cooldown, and it closes the incident in the log.
			self::log(sprintf('ImageManager API reachable again after %d failed requests, API calls resumed.', $previous_failures), true);
		}

		return [
			'status' => $status,
			'body'   => $body,
		];
	}

	/**
	 * Resolve the timeouts for a request context.
	 *
	 * Defaults: render 2 s connect / 3 s total, proxy 3 s / 10 s. Constants defined
	 * in wp-config.php replace the defaults (FM_IMAGEMANAGER_ACF_API_CONNECT_TIMEOUT,
	 * FM_IMAGEMANAGER_ACF_API_TIMEOUT for render; FM_IMAGEMANAGER_ACF_PROXY_CONNECT_TIMEOUT,
	 * FM_IMAGEMANAGER_ACF_PROXY_TIMEOUT for proxy), the filter runs last.
	 *
	 * @param string $context self::CONTEXT_RENDER or self::CONTEXT_PROXY.
	 * @return array{connect_timeout: float, timeout: float} Seconds; timeout at least 1, connect_timeout at most timeout.
	 */
	public static function get_timeouts(string $context): array {
		if (self::CONTEXT_PROXY === $context) {
			$timeouts = [
				'connect_timeout' => defined('FM_IMAGEMANAGER_ACF_PROXY_CONNECT_TIMEOUT') ? FM_IMAGEMANAGER_ACF_PROXY_CONNECT_TIMEOUT : 3,
				'timeout'         => defined('FM_IMAGEMANAGER_ACF_PROXY_TIMEOUT') ? FM_IMAGEMANAGER_ACF_PROXY_TIMEOUT : 10,
			];
		} else {
			$timeouts = [
				'connect_timeout' => defined('FM_IMAGEMANAGER_ACF_API_CONNECT_TIMEOUT') ? FM_IMAGEMANAGER_ACF_API_CONNECT_TIMEOUT : 2,
				'timeout'         => defined('FM_IMAGEMANAGER_ACF_API_TIMEOUT') ? FM_IMAGEMANAGER_ACF_API_TIMEOUT : 3,
			];
		}

		/**
		 * Filters the timeouts for requests to the ImageManager API.
		 *
		 * @param array{connect_timeout: float|int, timeout: float|int} $timeouts Seconds. timeout is the total
		 *                                                                       time including the connection.
		 * @param string                                                $context  'render' (field values on page
		 *                                                                       views) or 'proxy' (file browser).
		 */
		$timeouts = (array) apply_filters('feichtmedia_imagemanager_acf_api_timeouts', $timeouts, $context);

		// The Requests library enforces at least 1 s total anyway (system DNS resolver).
		$timeout = max(1.0, (float) ($timeouts['timeout'] ?? 3));
		$connect = (float) ($timeouts['connect_timeout'] ?? $timeout);

		return [
			'connect_timeout' => ($connect > 0) ? min($connect, $timeout) : $timeout,
			'timeout'         => $timeout,
		];
	}

	/**
	 * Run wp_remote_get() with a separate connect timeout.
	 *
	 * WP_Http only passes `timeout` to the Requests library, which then connects
	 * with its default connect_timeout of 10 s (capped by the total timeout in cURL,
	 * not in the fsockopen transport). The Requests hook `requests.before_request`
	 * (WordPress action `requests-requests.before_request`) receives the options by
	 * reference and is registered for this one call only.
	 *
	 * @param string $url             Request URL.
	 * @param array  $args            wp_remote_get() arguments.
	 * @param float  $connect_timeout Connect timeout in seconds.
	 * @return array|WP_Error wp_remote_get() result; any other value a pre_http_request or
	 *                        http_response filter returns becomes a WP_Error.
	 */
	private static function remote_get(string $url, array $args, float $connect_timeout): array|WP_Error {
		$set_connect_timeout = static function (&$request_url, &$headers, &$data, &$type, &$options) use ($connect_timeout): void {
			// Prefix match: Requests may normalise the URL (IRI) before this hook.
			if (is_string($request_url) && str_starts_with($request_url, FM_IMAGEMANAGER_API_URL)) {
				$options['connect_timeout'] = $connect_timeout;
			}
		};

		add_action('requests-requests.before_request', $set_connect_timeout, 10, 5);

		try {
			$response = wp_remote_get($url, $args);
		} finally {
			remove_action('requests-requests.before_request', $set_connect_timeout, 10);
		}

		if (is_array($response) || is_wp_error($response)) {
			return $response;
		}

		return new WP_Error('http_request_failed', 'Unexpected result from the WordPress HTTP API.');
	}

	/**
	 * Record a failure: circuit breaker, action, throttled log entry.
	 *
	 * @param WP_Error $error   The failure.
	 * @param string   $path    Requested path including the query string.
	 * @param string   $context Request context.
	 * @return WP_Error The same error, for returning.
	 */
	private static function fail(WP_Error $error, string $path, string $context): WP_Error {
		$state = FM_ImageManager_Circuit_Breaker::record_failure();

		/**
		 * Fires after a failed request to the ImageManager API (unthrottled), e.g. for monitoring.
		 *
		 * @param WP_Error $error   Error with code fm_imagemanager_unreachable, fm_imagemanager_server_error
		 *                          or fm_imagemanager_invalid_response.
		 * @param string   $path    Requested path below FM_IMAGEMANAGER_API_URL, including the query string.
		 * @param string   $context 'render' or 'proxy'.
		 */
		do_action('feichtmedia_imagemanager_acf_api_error', $error, $path, $context);

		// Query strings stay out of the log: they carry editors' search terms.
		$message = sprintf(
			'ImageManager API request GET %s (%s) failed: %s.',
			strtok($path, '?'),
			$context,
			rtrim($error->get_error_message(), '.')
		);

		$threshold = FM_ImageManager_Circuit_Breaker::threshold();

		if ($state['failures'] >= $threshold) {
			$message .= sprintf(
				' %d failures in a row, API calls paused for %d s.',
				$state['failures'],
				FM_ImageManager_Circuit_Breaker::cooldown()
			);
		}

		// The failure that opens the breaker bypasses the throttle: it is the key line of an
		// incident and happens once per outage (later re-openings fall on the throttle).
		self::log($message, $state['failures'] === $threshold);

		return $error;
	}

	/**
	 * Write a log entry, throttled to one per LOG_INTERVAL (network-wide on multisite).
	 *
	 * @param string $message Log message (English, not translated).
	 * @param bool   $force   Skip the throttle.
	 * @return void
	 */
	private static function log(string $message, bool $force = false): void {
		/**
		 * Filters whether failed ImageManager API requests are written to the PHP error log.
		 *
		 * @param bool $enabled Default true.
		 */
		if (! apply_filters('feichtmedia_imagemanager_acf_log_api_errors', true)) {
			return;
		}

		if (! $force) {
			if (false !== get_site_transient(self::LOG_LOCK_KEY)) {
				return;
			}
			set_site_transient(self::LOG_LOCK_KEY, 1, self::LOG_INTERVAL);
		}

		// Deliberate production logging (see AGENTS.md "Code standards"): an unreachable API must show up in the server log.
		error_log('FeichtMedia ImageManager for ACF: ' . $message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
