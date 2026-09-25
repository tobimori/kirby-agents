<?php

declare(strict_types=1);

namespace tobimori\Agents\OAuth;

use Kirby\Cms\App;
use Kirby\Cms\User;
use Kirby\Http\Request;
use Kirby\Http\Request\Auth\BasicAuth;
use Kirby\Http\Response;
use tobimori\Agents\Http\Guard;
use tobimori\Agents\Http\Json;

final class TokenEndpoint
{
	/**
	 * `POST /panel/oauth/token`
	 */
	public static function token(): Response
	{
		$request = App::instance()->request();
		$early = self::check($request);

		if ($early !== null) {
			return $early;
		}

		$body = $request->body()->toArray();
		$client = self::client($request, $body);

		if ($client === null) {
			return Json::error('invalid_client', 'Client authentication failed', 401);
		}

		return match ($body['grant_type'] ?? null) {
			'authorization_code' => self::exchangeCode($client, $body),
			'refresh_token' => self::refresh($client, $body),
			default => Json::error('unsupported_grant_type', 'Supported: authorization_code, refresh_token'),
		};
	}

	/**
	 * `POST /panel/oauth/revoke` (RFC 7009), answers 200 even for unknown tokens
	 */
	public static function revoke(): Response
	{
		$request = App::instance()->request();
		$early = self::check($request);

		if ($early !== null) {
			return $early;
		}

		$body = $request->body()->toArray();
		$client = self::client($request, $body);

		if ($client === null) {
			return Json::error('invalid_client', 'Client authentication failed', 401);
		}

		$token = (string) ($body['token'] ?? '');
		$ids = Token::parseOpaque(Token::REFRESH, $token) ?? Token::parseAccess($token);
		$user = $ids !== null ? App::instance()->users()->find($ids['user']) : null;

		if ($ids !== null && $user instanceof User) {
			(new GrantStore($user))->change(static function (array &$grants) use ($ids, $client): void {
				if (($grants[$ids['grant']] ?? null)?->client === $client->id) {
					unset($grants[$ids['grant']]);
				}
			});
		}

		return Json::response([]);
	}

	private static function check(Request $request): ?Response
	{
		if ($request->method() === 'OPTIONS') {
			return Json::preflight();
		}

		if ($request->method() !== 'POST') {
			return Json::error('invalid_request', 'Use POST', 405);
		}

		return Guard::https($request);
	}

	/**
	 * Public clients send only their id, confidential clients also their secret
	 * with HTTP Basic auth or in the body (RFC 6749 section 2.3.1)
	 */
	private static function client(Request $request, array $body): ?Client
	{
		$auth = $request->auth();

		if ($auth instanceof BasicAuth) {
			$client = Client::find(urldecode((string) $auth->username()));
			$secret = urldecode((string) $auth->password());
		} else {
			$client = Client::find((string) ($body['client_id'] ?? ''));
			$secret = (string) ($body['client_secret'] ?? '');
		}

		if ($client === null || $client->isPublic() || $client->hasSecret($secret)) {
			return $client;
		}

		return null;
	}

	private static function exchangeCode(Client $client, array $body): Response
	{
		$ids = Token::parseOpaque(Token::CODE, (string) ($body['code'] ?? ''));
		$user = $ids !== null ? App::instance()->users()->find($ids['user']) : null;

		if ($ids === null || !$user instanceof User) {
			return Json::error('invalid_grant', 'Unknown code');
		}

		return (new GrantStore($user))->change(static function (array &$grants) use (
			$ids,
			$user,
			$client,
			$body,
		): Response {
			$grant = $grants[$ids['grant']] ?? null;

			if (
				!$grant instanceof Grant
				|| $grant->code === null
				|| !hash_equals($grant->code, Token::hash($ids['secret']))
			) {
				return Json::error('invalid_grant', 'Unknown code');
			}

			// a code that is used twice may have leaked, so the grant is revoked
			if ($grant->challenge === null) {
				unset($grants[$grant->id]);

				return Json::error('invalid_grant', 'The code was already used');
			}

			$verifier = (string) ($body['code_verifier'] ?? '');

			if (
				($grant->created + Token::CODE_TTL) < time()
				|| $grant->client !== $client->id
				|| ($body['redirect_uri'] ?? null) !== $grant->redirect
				|| !hash_equals($grant->challenge, Token::encode(hash('sha256', $verifier, true)))
			) {
				return Json::error('invalid_grant', 'The code is expired or does not match the request');
			}

			$grant->challenge = null;

			return self::issue($user, $grant);
		});
	}

	private static function refresh(Client $client, array $body): Response
	{
		$ids = Token::parseOpaque(Token::REFRESH, (string) ($body['refresh_token'] ?? ''));
		$user = $ids !== null ? App::instance()->users()->find($ids['user']) : null;

		if ($ids === null || !$user instanceof User) {
			return Json::error('invalid_grant', 'Unknown refresh token');
		}

		return (new GrantStore($user))->change(static function (array &$grants) use ($ids, $user, $client): Response {
			$grant = $grants[$ids['grant']] ?? null;
			$hash = Token::hash($ids['secret']);

			if (!$grant instanceof Grant || $grant->client !== $client->id) {
				return Json::error('invalid_grant', 'Unknown refresh token');
			}

			// an old refresh token may have leaked, so the grant is revoked
			if ($grant->previous !== null && hash_equals($grant->previous, $hash)) {
				unset($grants[$grant->id]);

				return Json::error('invalid_grant', 'The refresh token was already used');
			}

			if ($grant->refresh === null || !hash_equals($grant->refresh, $hash)) {
				return Json::error('invalid_grant', 'Unknown refresh token');
			}

			return self::issue($user, $grant);
		});
	}

	/**
	 * New access and refresh token. The access token gets only the scopes
	 * that the role still allows.
	 */
	private static function issue(User $user, Grant $grant): Response
	{
		$scopes = Scope::allowedFor($user, $grant->scopes);

		if ($scopes === []) {
			return Json::error('invalid_grant', 'The user can no longer connect agents');
		}

		$refresh = Token::random();
		$grant->rotate($refresh);

		return Json::response([
			'access_token' => Token::access($user->id(), $grant->id, $scopes, $grant->resource),
			'token_type' => 'Bearer',
			'expires_in' => Token::ACCESS_TTL,
			'refresh_token' => Token::opaque(Token::REFRESH, $user->id(), $grant->id, $refresh),
			'scope' => implode(' ', $scopes),
		]);
	}
}
