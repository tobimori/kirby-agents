<?php

declare(strict_types=1);

namespace tobimori\Agents\OAuth;

use Kirby\Cms\App;
use Kirby\Content\Field;
use Kirby\Http\Response;
use Kirby\Http\Url;
use tobimori\Agents\Agents;
use tobimori\Agents\Http\Json;

final class Metadata
{
	/**
	 * OAuth 2.0 Protected Resource Metadata (RFC 9728)
	 */
	public static function protectedResource(): array
	{
		$title = App::instance()->site()->content()->get('title');

		return [
			'resource' => Agents::resource(),
			'authorization_servers' => [Agents::issuer()],
			'scopes_supported' => Scope::minimal(),
			'bearer_methods_supported' => ['header'],
			'resource_name' => $title instanceof Field ? $title->value() : null,
		];
	}

	/**
	 * OAuth 2.0 Authorization Server Metadata (RFC 8414)
	 */
	public static function authorizationServer(): array
	{
		$issuer = Agents::issuer();

		return [
			'issuer' => $issuer,
			'authorization_endpoint' => "{$issuer}/oauth/authorize",
			'token_endpoint' => "{$issuer}/oauth/token",
			'registration_endpoint' => "{$issuer}/oauth/register",
			'revocation_endpoint' => "{$issuer}/oauth/revoke",
			'response_types_supported' => ['code'],
			'grant_types_supported' => ['authorization_code', 'refresh_token'],
			'code_challenge_methods_supported' => ['S256'],
			'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
			'revocation_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
			'client_id_metadata_document_supported' => true,
			'authorization_response_iss_parameter_supported' => true,
			'scopes_supported' => Scope::all(),
		];
	}

	/**
	 * URL of the protected resource metadata, for `WWW-Authenticate`
	 */
	public static function protectedResourceUrl(): string
	{
		return (
			rtrim((string) App::instance()->url(), '/')
			. '/.well-known/oauth-protected-resource/'
			. static::path(Agents::resource())
		);
	}

	/**
	 * URL path without slashes at the ends
	 */
	public static function path(string $url): string
	{
		return trim(Url::path($url), '/');
	}

	public static function response(array $data): Response
	{
		return Json::response($data, headers: ['Cache-Control' => 'public, max-age=300']);
	}
}
