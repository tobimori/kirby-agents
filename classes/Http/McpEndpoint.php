<?php

declare(strict_types=1);

namespace tobimori\Agents\Http;

use Kirby\Cms\App;
use Kirby\Http\Request\Auth\BearerAuth;
use Kirby\Http\Response;
use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\Metadata;
use tobimori\Agents\OAuth\Scope;
use tobimori\Agents\Protocol\Server;

final class McpEndpoint
{
	/**
	 * Path of the endpoint in the Panel
	 */
	public const PATH = 'mcp';

	public static function handle(): Response
	{
		$request = App::instance()->request();

		$error = Guard::https($request) ?? Guard::origin($request);

		if ($error !== null) {
			return $error;
		}

		if ($request->method() !== 'POST') {
			return new Response('', null, 405, ['Allow' => 'POST']);
		}

		// only bearer tokens count, the Panel session cookie is ignored
		$auth = $request->auth();

		if (!$auth instanceof BearerAuth) {
			return static::challenge();
		}

		$access = Access::fromToken($auth->token());

		if ($access === null) {
			return static::challenge('invalid_token');
		}

		App::instance()->auth()->setUser($access->user);

		return Server::handle($request, $access);
	}

	/**
	 * 401 response that tells the client where to get a token
	 */
	public static function challenge(?string $error = null): Response
	{
		return self::authenticate(401, $error ?? 'unauthorized', Scope::minimal(), $error !== null);
	}

	/**
	 * 403 response that asks the client to authorize again with more scopes
	 */
	public static function insufficientScope(Scope $scope): Response
	{
		return self::authenticate(403, 'insufficient_scope', [$scope->value], true);
	}

	/**
	 * @param list<string> $scopes
	 */
	private static function authenticate(int $status, string $error, array $scopes, bool $withError): Response
	{
		$params = [
			'resource_metadata="' . Metadata::protectedResourceUrl() . '"',
			'scope="' . implode(' ', $scopes) . '"',
		];

		if ($withError) {
			$params[] = 'error="' . $error . '"';
		}

		return Response::json(['error' => $error], $status, headers: [
			'WWW-Authenticate' => 'Bearer ' . implode(', ', $params),
		]);
	}
}
