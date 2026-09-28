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

		$auth = $request->auth();

		if (!$auth instanceof BearerAuth) {
			return static::challenge();
		}

		$access = Access::fromToken($auth->token());

		if ($access === null) {
			return static::challenge('invalid_token');
		}

		App::instance()->auth()->setUser($access->user);

		return RateLimit::hit('mcp', 'grant ' . $access->grant) ?? Server::handle($request, $access);
	}

	public static function challenge(?string $error = null): Response
	{
		return self::authenticate(401, $error ?? 'unauthorized', Scope::minimal(), $error !== null);
	}

	public static function insufficientScope(Scope $scope, Access $access): Response
	{
		return ChallengeResponse::json(['error' => 'insufficient_scope'], 403, headers: [
			'WWW-Authenticate' => self::scopeChallenge($scope, $access),
		]);
	}

	/**
	 * The scopes of the token and the missing one, because clients often request exactly these
	 */
	public static function scopeChallenge(Scope $scope, Access $access): string
	{
		$scopes = array_values(array_unique([...$access->scopes, $scope->value]));

		return self::header($scopes, 'insufficient_scope', "This call needs the permission `{$scope->value}`");
	}

	/**
	 * @param list<string> $scopes
	 */
	private static function authenticate(int $status, string $error, array $scopes, bool $withError): Response
	{
		return ChallengeResponse::json(['error' => $error], $status, headers: [
			'WWW-Authenticate' => self::header($scopes, $withError ? $error : null),
		]);
	}

	/**
	 * @param list<string> $scopes
	 */
	private static function header(array $scopes, ?string $error = null, ?string $description = null): string
	{
		$params = [
			'resource_metadata="' . Metadata::protectedResourceUrl() . '"',
			'scope="' . implode(' ', $scopes) . '"',
		];

		if ($error !== null) {
			$params[] = 'error="' . $error . '"';
		}

		if ($description !== null) {
			$params[] = 'error_description="' . $description . '"';
		}

		return 'Bearer ' . implode(', ', $params);
	}
}
