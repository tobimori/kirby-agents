<?php

declare(strict_types=1);

namespace tobimori\Agents\Http;

use Kirby\Cms\App;
use Kirby\Http\Request;
use Kirby\Http\Response;
use Kirby\Http\Uri;
use tobimori\Agents\Agents;

final class Guard
{
	/**
	 * Returns an error response if the request must be rejected
	 */
	public static function check(Request $request): ?Response
	{
		if ($request->ssl() === false && static::isLoopback() === false) {
			return Response::json(['error' => 'HTTPS is required'], 403);
		}

		$origin = (string) $request->header('Origin');

		if ($origin !== '' && static::isAllowedOrigin($origin) === false) {
			return Response::json(['error' => 'Origin is not allowed'], 403);
		}

		return null;
	}

	/**
	 * Direct request from this machine, not through a proxy
	 */
	private static function isLoopback(): bool
	{
		$environment = App::instance()->environment();

		return (
			in_array($environment->get('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)
			&& $environment->get('HTTP_X_FORWARDED_FOR') === null
		);
	}

	private static function isAllowedOrigin(string $origin): bool
	{
		$site = (new Uri((string) App::instance()->url()))->base();
		$extra = Agents::option('origins', []);

		return $origin === $site || is_array($extra) && in_array($origin, $extra, true);
	}
}
