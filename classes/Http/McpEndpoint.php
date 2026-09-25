<?php

declare(strict_types=1);

namespace tobimori\Agents\Http;

use Kirby\Cms\App;
use Kirby\Http\Request\Auth\BearerAuth;
use Kirby\Http\Response;
use tobimori\Agents\OAuth\Metadata;
use tobimori\Agents\OAuth\Scope;

final class McpEndpoint
{
	/**
	 * Path of the endpoint in the Panel
	 */
	public const PATH = 'mcp';

	public static function handle(): Response
	{
		$request = App::instance()->request();

		$error = Guard::check($request);

		if ($error !== null) {
			return $error;
		}

		if ($request->method() !== 'POST') {
			return new Response('', null, 405, ['Allow' => 'POST']);
		}

		// only bearer tokens count, the Panel session cookie is ignored
		$token = $request->auth();

		// no token can be valid until the token endpoint exists
		return static::challenge($token instanceof BearerAuth ? 'invalid_token' : null);
	}

	/**
	 * 401 response that tells the client where to get a token
	 */
	public static function challenge(?string $error = null): Response
	{
		$params = [
			'resource_metadata="' . Metadata::protectedResourceUrl() . '"',
			'scope="' . implode(' ', Scope::minimal()) . '"',
		];

		if ($error !== null) {
			$params[] = 'error="' . $error . '"';
		}

		return Response::json(['error' => $error ?? 'unauthorized'], 401, headers: [
			'WWW-Authenticate' => 'Bearer ' . implode(', ', $params),
		]);
	}
}
