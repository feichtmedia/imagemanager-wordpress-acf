<?php

/**
 * FM_ImageManager_Circuit_Breaker
 *
 * Shared failure state for all requests to the ImageManager API. After a number
 * of consecutive failures (default 3) the breaker opens and API calls are skipped
 * for a cooldown (default 60 s). Without it, every page view would wait for its own
 * timeout while the API hangs, and the PHP worker pool would still run full.
 *
 * When the cooldown has passed, the next request probes the API (half-open): a
 * success closes the breaker, a failure opens it for another cooldown.
 *
 * The state is a site transient: network-wide on multisite (all sites call the
 * same API and share the PHP worker pool), otherwise an options row or an entry
 * in the persistent object cache. Updates are not atomic — concurrent workers may
 * lose an increment, which only delays opening by a request or two.
 *
 * @package FeichtMedia\ImageManagerACF
 */

if (! defined('ABSPATH')) {
	exit;
}

class FM_ImageManager_Circuit_Breaker {

	/**
	 * Site transient holding the state: array{failures: int, open_until: int}.
	 */
	public const STATE_KEY = 'feichtmedia_imagemanager_acf_api_breaker';

	/**
	 * How long a failure count is kept without further failures, in seconds.
	 */
	private const FAILURE_MEMORY = 10 * MINUTE_IN_SECONDS;

	/**
	 * Number of consecutive failures that opens the breaker.
	 *
	 * @return int At least 1.
	 */
	public static function threshold(): int {
		/**
		 * Filters the number of consecutive API failures that opens the circuit breaker.
		 *
		 * @param int $threshold Default 3.
		 */
		return max(1, (int) apply_filters('feichtmedia_imagemanager_acf_circuit_breaker_threshold', 3));
	}

	/**
	 * How long API calls are skipped once the breaker is open.
	 *
	 * @return int Seconds, at least 1.
	 */
	public static function cooldown(): int {
		/**
		 * Filters how long API calls are skipped once the circuit breaker is open.
		 *
		 * @param int $cooldown Seconds, default 60.
		 */
		return max(1, (int) apply_filters('feichtmedia_imagemanager_acf_circuit_breaker_cooldown', MINUTE_IN_SECONDS));
	}

	/**
	 * Decide whether an API request may be sent now.
	 *
	 * Closed: always. Open: not until the cooldown has passed. Half-open (cooldown
	 * over): the caller claims the probe by moving open_until forward by
	 * $probe_window, so concurrent requests keep skipping while the probe runs.
	 *
	 * @param int $probe_window Seconds the probe may take (request timeout plus margin).
	 * @return bool True if the request may be sent.
	 */
	public static function allow_request(int $probe_window): bool {
		$state = self::get_state();

		if ($state['failures'] < self::threshold()) {
			return true;
		}

		$now = time();
		if ($now < $state['open_until']) {
			return false;
		}

		$state['open_until'] = $now + max(1, $probe_window);
		self::save_state($state);

		return true;
	}

	/**
	 * Record a successful API response and close the breaker.
	 *
	 * @return int Number of consecutive failures before this success (0: nothing was reset).
	 */
	public static function record_success(): int {
		$state = self::get_state();

		// Hot path while the API is healthy: no write.
		if (0 === $state['failures']) {
			return 0;
		}

		delete_site_transient(self::STATE_KEY);

		return $state['failures'];
	}

	/**
	 * Record a failed API request; opens the breaker once the threshold is reached.
	 *
	 * @return array{failures: int, open_until: int} State after this failure.
	 */
	public static function record_failure(): array {
		$state = self::get_state();
		++$state['failures'];

		if ($state['failures'] >= self::threshold()) {
			$state['open_until'] = time() + self::cooldown();
		}

		self::save_state($state);

		return $state;
	}

	/**
	 * Read the current state.
	 *
	 * @return array{failures: int, open_until: int} Consecutive failures and the Unix time until which calls are skipped.
	 */
	public static function get_state(): array {
		$state = get_site_transient(self::STATE_KEY);

		if (! is_array($state)) {
			return ['failures' => 0, 'open_until' => 0];
		}

		return [
			'failures'   => max(0, (int) ($state['failures'] ?? 0)),
			'open_until' => (int) ($state['open_until'] ?? 0),
		];
	}

	/**
	 * Persist the state. It must outlive open_until, otherwise the breaker would
	 * close early; after that, failures are forgotten once FAILURE_MEMORY passes.
	 *
	 * @param array{failures: int, open_until: int} $state State to store.
	 * @return void
	 */
	private static function save_state(array $state): void {
		$lifetime = max(0, $state['open_until'] - time()) + self::FAILURE_MEMORY;

		set_site_transient(self::STATE_KEY, $state, $lifetime);
	}
}
