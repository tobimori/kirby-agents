<?php

declare(strict_types=1);

namespace tobimori\Agents\Http;

use Kirby\Cms\App;
use Kirby\Http\Response;
use tobimori\Agents\Agents;

/**
 * Counts requests in fixed time windows, in the `tobimori.agents.limits` cache.
 * The counters are not atomic with the file cache, so a limit can let a few more through.
 */
final class RateLimit
{
	/**
	 * Requests per window in seconds
	 */
	private const DEFAULTS = [
		'register' => [20, 3600],
		'authorize' => [30, 60],
		'token' => [60, 60],
		'mcp' => [120, 60],
		'upload' => [30, 60],
	];

	/**
	 * Counts a request. Returns a 429 response if the limit is reached, else null.
	 * Without a key, the limit is per IP address.
	 */
	public static function hit(string $bucket, ?string $key = null): ?Response
	{
		$limit = self::limit($bucket);

		if ($limit === null) {
			return null;
		}

		[$max, $window] = $limit;
		$kirby = App::instance();
		// behind a proxy, all requests share the address of the proxy
		$ip = $kirby->environment()->get('REMOTE_ADDR');
		$key ??= 'ip ' . (is_string($ip) ? $ip : '');
		$slot = intdiv(time(), $window);
		$id = $bucket . '.' . hash('sha256', $key) . '.' . $slot;
		$cache = $kirby->cache('tobimori.agents.limits');
		$count = $cache->get($id, 0);
		$count = (is_int($count) ? $count : 0) + 1;

		// minutes, a little longer than the window
		$cache->set($id, $count, intdiv($window, 60) + 1);

		if ($count <= $max) {
			return null;
		}

		$retry = (($slot + 1) * $window) - time();

		return Json::response(
			[
				'error' => 'too_many_requests',
				'error_description' => "Too many requests. Try again in {$retry} seconds.",
			],
			429,
			['Retry-After' => (string) $retry],
		);
	}

	/**
	 * `[requests, seconds]` from the `limits` option, or null if the limit is off
	 *
	 * @return array{0: int, 1: int}|null
	 */
	private static function limit(string $bucket): ?array
	{
		$option = Agents::option('limits', []);

		if ($option === false) {
			return null;
		}

		$limit = is_array($option) && array_key_exists($bucket, $option)
			? $option[$bucket]
			: self::DEFAULTS[$bucket] ?? null;

		if (!is_array($limit) || !is_int($limit[0] ?? null) || !is_int($limit[1] ?? null) || $limit[1] < 1) {
			return null;
		}

		return [$limit[0], $limit[1]];
	}
}
