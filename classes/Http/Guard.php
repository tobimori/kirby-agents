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
	 * Error response for plain HTTP, except for direct requests from this machine
	 */
	public static function https(Request $request): ?Response
	{
		if ($request->ssl() || self::isLoopback()) {
			return null;
		}

		return Json::error('invalid_request', 'HTTPS is required', 403);
	}

	/**
	 * Error response for browser requests from other sites (DNS rebinding)
	 */
	public static function origin(Request $request): ?Response
	{
		$origin = (string) $request->header('Origin');

		if ($origin === '' || self::isAllowedOrigin($origin)) {
			return null;
		}

		return Json::error('invalid_request', 'Origin is not allowed', 403);
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
