<?php

declare(strict_types=1);

namespace tobimori\Agents\Http;

use Kirby\Http\Response;

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
