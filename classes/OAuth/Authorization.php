<?php

declare(strict_types=1);

namespace tobimori\Agents\OAuth;

use Kirby\Cms\App;
use Kirby\Http\Response;
use Kirby\Panel\Panel;
use Kirby\Toolkit\Escape;
use tobimori\Agents\Agents;
use tobimori\Agents\Http\Guard;
use tobimori\Agents\Http\RateLimit;

final class Authorization
{
	private const SESSION = 'tobimori.agents.authorize.';

	private const TTL = 10 * 60;

	public static function start(): Response
	{
		$kirby = App::instance();
		$request = $kirby->request();

		if ($request->method() !== 'GET') {
			return new Response('', null, 405, ['Allow' => 'GET']);
		}

		$refused = Guard::https($request) ?? RateLimit::hit('authorize');

		if ($refused !== null) {
			return $refused;
		}

		$query = $request->query();
		$client = Client::find((string) $query->get('client_id'));
		$redirect = (string) $query->get('redirect_uri');

		if ($client === null) {
			return self::page('The client is unknown or its metadata document is not available.');
		}

		if ($client->allowsRedirect($redirect) === false) {
			return self::page('The redirect URI is not registered for this client.');
		}

		$state = $query->get('state');
		$state = is_string($state) ? $state : null;

		if ($query->get('response_type') !== 'code') {
			return self::back($redirect, $state, 'unsupported_response_type', 'Only the code flow is supported');
		}

		$challenge = (string) $query->get('code_challenge');

		if ($challenge === '' || $query->get('code_challenge_method') !== 'S256') {
			return self::back($redirect, $state, 'invalid_request', 'PKCE with S256 is required');
		}

		$resource = $query->get('resource');

		if ($resource !== null && self::isResource((string) $resource) === false) {
			return self::back($redirect, $state, 'invalid_target', 'Unknown resource');
		}

		$scopes = array_values(array_filter(explode(' ', (string) $query->get('scope'))));
		$scopes = $scopes !== [] ? $scopes : Scope::minimal();

		if (array_diff($scopes, Scope::all()) !== []) {
			return self::back($redirect, $state, 'invalid_scope', 'Unknown scope');
		}

		$extra = Agents::option('scopes', []);
		$extra = is_array($extra) ? array_intersect($extra, Scope::all()) : [];
		$scopes = array_values(array_unique([...$scopes, ...$extra]));

		$id = bin2hex(random_bytes(16));

		$kirby->session()->data()->set(self::SESSION . $id, [
			'client' => $client->id,
			'redirect' => $redirect,
			'state' => $state,
			'challenge' => $challenge,
			'scopes' => $scopes,
			'expires' => time() + self::TTL,
		]);

		// in the path, because the Panel drops the query after the login
		return Response::redirect(Panel::url('agents/authorize/' . $id));
	}

	public static function view(string $id): array
	{
		$kirby = App::instance();
		$pending = self::pending($id);
		$client = $pending !== null ? Client::find($pending['client']) : null;
		$user = $kirby->user();

		if ($pending === null || $client === null || $user === null) {
			return ['component' => 'k-agents-authorize-view', 'props' => ['error' => 'expired']];
		}

		$allowed = Scope::allowedFor($user, $pending['scopes']);
		$redirect = $pending['redirect'];

		return [
			'component' => 'k-agents-authorize-view',
			'title' => $client->name,
			'props' => [
				'error' => $allowed === [] ? 'forbidden' : null,
				'client' => [
					'name' => $client->name,
					'host' => $client->host(),
				],
				'redirect' => [
					'host' => RedirectUri::display($redirect),
					'type' => match (true) {
						RedirectUri::isLoopback($redirect) => 'loopback',
						str_starts_with($redirect, 'https://') => 'web',
						default => 'app',
					},
				],
				'scopes' => array_map(static fn(string $scope): array => [
					'id' => $scope,
					'text' => Scope::find($scope)?->label() ?? $scope,
					'allowed' => in_array($scope, $allowed, true),
				], $pending['scopes']),
				'site' => Agents::siteTitle(),
				'user' => $user->email(),
				'csrf' => $kirby->csrf(),
				'action' => Panel::url('agents/authorize/' . $id),
			],
		];
	}

	public static function decide(string $id): Response
	{
		$kirby = App::instance();
		$body = $kirby->request()->body();
		$user = $kirby->user();

		if ($user === null || $kirby->csrf((string) $body->get('csrf')) !== true) {
			return self::page('The request is not valid. Start the connection again from your app.', 403);
		}

		$pending = self::pending($id);
		$kirby
			->session()
			->data()
			->remove(self::SESSION . $id);
		$client = $pending !== null ? Client::find($pending['client']) : null;

		if ($pending === null || $client === null) {
			return self::page('This request has expired. Start the connection again from your app.');
		}

		$scopes = Scope::allowedFor($user, $pending['scopes']);

		if ($body->get('decision') !== 'approve' || $scopes === []) {
			return self::back($pending['redirect'], $pending['state'], 'access_denied', 'The user denied access');
		}

		$code = Token::random();
		$grant = Grant::create(
			client: $client->id,
			name: $client->name,
			scopes: $scopes,
			redirect: $pending['redirect'],
			resource: Agents::resource(),
			code: $code,
			challenge: $pending['challenge'],
		);

		(new GrantStore($user))->change(static function (array &$grants) use ($grant): void {
			$grants[$grant->id] = $grant;
		});

		return self::redirect($pending['redirect'], [
			'code' => Token::opaque(Token::CODE, $user->id(), $grant->id, $code),
			'state' => $pending['state'],
		]);
	}

	/**
	 * @return array{client: string, redirect: string, state: string|null, challenge: string, scopes: list<string>}|null
	 */
	private static function pending(string $id): ?array
	{
		$data = App::instance()
			->session()
			->data()
			->get(self::SESSION . $id);

		if (
			!is_array($data)
			|| !is_string($data['client'] ?? null)
			|| !is_string($data['redirect'] ?? null)
			|| !is_string($data['challenge'] ?? null)
			|| !is_array($data['scopes'] ?? null)
			|| !is_int($data['expires'] ?? null)
			|| $data['expires'] < time()
		) {
			return null;
		}

		return [
			'client' => $data['client'],
			'redirect' => $data['redirect'],
			'state' => is_string($data['state'] ?? null) ? $data['state'] : null,
			'challenge' => $data['challenge'],
			'scopes' => array_values(array_filter($data['scopes'], is_string(...))),
		];
	}

	private static function isResource(string $resource): bool
	{
		$normalize = static fn(string $url): string => rtrim(
			(string) preg_replace_callback(
				'~^[a-z]+://[^/]+~i',
				static fn(array $m): string => strtolower($m[0]),
				$url,
			),
			'/',
		);

		return $normalize($resource) === $normalize(Agents::resource());
	}

	private static function back(string $redirect, ?string $state, string $error, string $description): Response
	{
		return self::redirect($redirect, [
			'error' => $error,
			'error_description' => $description,
			'state' => $state,
		]);
	}

	private static function redirect(string $uri, array $params): Response
	{
		$params['iss'] = Agents::issuer();
		$query = http_build_query(array_filter($params, static fn(mixed $value): bool => $value !== null));

		// not Response::redirect(): Kirby's Uri drops custom schemes like `com.raycast:/oauth`
		return new Response('', null, 302, [
			'Location' => $uri . (str_contains($uri, '?') ? '&' : '?') . $query,
		]);
	}

	private static function page(string $message, int $code = 400): Response
	{
		$html =
			'<!doctype html><meta charset="utf-8"><title>Authorization failed</title>'
			. '<body style="font-family: system-ui, sans-serif; max-width: 32rem; margin: 4rem auto; padding: 0 1rem">'
			. '<h1>Authorization failed</h1><p>'
			. Escape::html($message)
			. '</p>';

		return new Response($html, 'text/html', $code);
	}
}
