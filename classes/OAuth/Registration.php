<?php

declare(strict_types=1);

namespace tobimori\Agents\OAuth;

use Kirby\Cms\App;
use Kirby\Http\Response;
use Kirby\Toolkit\Str;
use tobimori\Agents\Http\Guard;
use tobimori\Agents\Http\Json;
use tobimori\Agents\Http\RateLimit;

/**
 * Dynamic client registration (RFC 7591) without storage.
 * The client id carries the signed metadata: `kad.<metadata>.<signature>`.
 */
final class Registration
{
	private const PREFIX = 'kad';

	private const AUTH_METHODS = ['none', 'client_secret_basic', 'client_secret_post'];

	public static function handle(): Response
	{
		$request = App::instance()->request();

		if ($request->method() === 'OPTIONS') {
			return Json::preflight();
		}

		if ($request->method() !== 'POST') {
			return Json::error('invalid_request', 'Use POST', 405);
		}

		$refused = Guard::https($request) ?? RateLimit::hit('register');

		if ($refused !== null) {
			return $refused;
		}

		$data = $request->body()->toArray();

		$uris = $data['redirect_uris'] ?? null;

		if (!is_array($uris) || !array_is_list($uris) || $uris === [] || count($uris) > 10) {
			return Json::error('invalid_redirect_uri', 'Send between 1 and 10 redirect URIs');
		}

		foreach ($uris as $uri) {
			if (!is_string($uri) || RedirectUri::isValid($uri) === false) {
				return Json::error(
					'invalid_redirect_uri',
					'Redirect URIs must use HTTPS, loopback HTTP, or an app scheme',
				);
			}
		}

		// RFC 7591 default
		$method = $data['token_endpoint_auth_method'] ?? 'client_secret_basic';

		if (!in_array($method, self::AUTH_METHODS, true)) {
			return Json::error(
				'invalid_client_metadata',
				'Supported auth methods: ' . implode(', ', self::AUTH_METHODS),
			);
		}

		$grantTypes = $data['grant_types'] ?? ['authorization_code', 'refresh_token'];

		foreach (is_array($grantTypes) ? $grantTypes : [null] as $type) {
			if (!in_array($type, ['authorization_code', 'refresh_token'], true)) {
				return Json::error(
					'invalid_client_metadata',
					'Supported grant types: authorization_code, refresh_token',
				);
			}
		}

		$name = is_string($data['client_name'] ?? null) ? Str::short(trim($data['client_name']), 100) : '';
		$name = $name !== '' ? $name : 'Unnamed client';

		$payload = Token::encode((string) json_encode(['n' => $name, 'r' => $uris, 'm' => $method]));
		$id = self::PREFIX . '.' . $payload . '.' . Secret::sign(self::PREFIX . '.' . $payload);

		$client = [
			'client_id' => $id,
			'client_id_issued_at' => time(),
			'client_name' => $name,
			'redirect_uris' => $uris,
			'grant_types' => $grantTypes,
			'response_types' => ['code'],
			'token_endpoint_auth_method' => $method,
		];

		if ($method !== 'none') {
			$client['client_secret'] = self::secret($id);
			$client['client_secret_expires_at'] = 0;
		}

		return Json::response($client, 201);
	}

	/**
	 * Returns the client if the signature is valid
	 */
	public static function client(string $id): ?Client
	{
		$parts = explode('.', $id);

		if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
			return null;
		}

		if (hash_equals(Secret::sign(self::PREFIX . '.' . $parts[1]), $parts[2]) === false) {
			return null;
		}

		$data = json_decode(Token::decode($parts[1]), true);

		if (
			!is_array($data)
			|| !is_string($data['n'] ?? null)
			|| !is_array($data['r'] ?? null)
			|| !is_string($data['m'] ?? null)
		) {
			return null;
		}

		return new Client(
			id: $id,
			name: $data['n'],
			redirectUris: array_values(array_filter($data['r'], is_string(...))),
			authMethod: $data['m'],
			metadataDocument: false,
		);
	}

	/**
	 * Client secret for confidential clients, derived from the client id
	 */
	public static function secret(string $id): string
	{
		return Secret::sign('client-secret.' . $id);
	}
}
