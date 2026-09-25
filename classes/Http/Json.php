<?php

declare(strict_types=1);

namespace tobimori\Agents\Http;

use Kirby\Http\Response;

/**
 * JSON responses for endpoints that use no cookies, so any origin may read them
 */
final class Json
{
	public static function response(array $data, int $code = 200, array $headers = []): Response
	{
		return Response::json($data, $code, headers: [
			'Access-Control-Allow-Origin' => '*',
			'Cache-Control' => 'no-store',
			...$headers,
		]);
	}

	/**
	 * OAuth error (RFC 6749 section 5.2)
	 */
	public static function error(string $error, string $description, int $code = 400, array $headers = []): Response
	{
		return self::response(['error' => $error, 'error_description' => $description], $code, $headers);
	}

	public static function preflight(): Response
	{
		return new Response('', null, 204, [
			'Access-Control-Allow-Origin' => '*',
			'Access-Control-Allow-Methods' => 'POST',
			'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
			'Access-Control-Max-Age' => '86400',
		]);
	}
}
