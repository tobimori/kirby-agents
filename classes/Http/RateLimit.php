<?php

declare(strict_types=1);

namespace tobimori\Agents\Http;

use Kirby\Cms\App;
use Kirby\Http\Response;
use tobimori\Agents\Agents;

final class RateLimit
{
	private const DEFAULTS = [
		'register' => [20, 3600],
		'authorize' => [30, 60],
		'token' => [60, 60],
		'mcp' => [120, 60],
		'upload' => [30, 60],
	];

	public static function hit(string $bucket, ?string $key = null): ?Response
	{
		$limit = self::limit($bucket);

		if ($limit === null) {
			return null;
		}

		[$max, $window] = $limit;
		$kirby = App::instance();
		$ip = $kirby->environment()->get('REMOTE_ADDR');
		$key ??= 'ip ' . (is_string($ip) ? $ip : '');
		$slot = intdiv(time(), $window);
		$id = $bucket . '.' . hash('sha256', $key) . '.' . $slot;
		$cache = $kirby->cache('tobimori.agents.limits');
		$count = $cache->get($id, 0);
		$count = (is_int($count) ? $count : 0) + 1;

		// Kirby's cache expires in minutes
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
