<?php

declare(strict_types=1);

namespace tobimori\Agents\OAuth;

use SensitiveParameter;

/**
 * Token formats:
 * - code:    `kac.<userId>.<grantId>.<secret>`
 * - refresh: `kar.<userId>.<grantId>.<secret>`
 * - access:  `kat.<payload>.<signature>`, payload is JSON with user, grant, scopes, audience, expiry
 *
 * Only hashes of code and refresh secrets are stored.
 */
final class Token
{
	public const CODE = 'kac';
	public const REFRESH = 'kar';
	public const ACCESS = 'kat';

	public const CODE_TTL = 60;
	public const REFRESH_TTL = 30 * 24 * 60 * 60;
	public const ACCESS_TTL = 60 * 60;

	public static function random(): string
	{
		return self::encode(random_bytes(32));
	}

	public static function hash(#[SensitiveParameter] string $secret): string
	{
		return hash('sha256', $secret);
	}

	/**
	 * Code or refresh token
	 */
	public static function opaque(
		string $type,
		string $user,
		string $grant,
		#[SensitiveParameter]
		string $secret,
	): string {
		return implode('.', [$type, $user, $grant, $secret]);
	}

	/**
	 * @return array{user: string, grant: string, secret: string}|null
	 */
	public static function parseOpaque(string $type, #[SensitiveParameter] string $token): ?array
	{
		$parts = explode('.', $token);

		if (count($parts) !== 4 || $parts[0] !== $type) {
			return null;
		}

		return ['user' => $parts[1], 'grant' => $parts[2], 'secret' => $parts[3]];
	}

	/**
	 * @param list<string> $scopes
	 */
	public static function access(string $user, string $grant, array $scopes, string $audience): string
	{
		$payload = self::encode((string) json_encode([
			'u' => $user,
			'g' => $grant,
			's' => $scopes,
			'a' => $audience,
			'e' => time() + self::ACCESS_TTL,
		]));

		return self::ACCESS . '.' . $payload . '.' . Secret::sign($payload);
	}

	/**
	 * Returns the payload if the signature is valid and the token has not expired
	 *
	 * @return array{user: string, grant: string, scopes: list<string>, audience: string}|null
	 */
	public static function parseAccess(#[SensitiveParameter] string $token): ?array
	{
		$parts = explode('.', $token);

		if (count($parts) !== 3 || $parts[0] !== self::ACCESS) {
			return null;
		}

		if (hash_equals(Secret::sign($parts[1]), $parts[2]) === false) {
			return null;
		}

		$data = json_decode(self::decode($parts[1]), true);

		if (
			!is_array($data)
			|| !is_string($data['u'] ?? null)
			|| !is_string($data['g'] ?? null)
			|| !is_array($data['s'] ?? null)
			|| !is_string($data['a'] ?? null)
			|| !is_int($data['e'] ?? null)
			|| $data['e'] < time()
		) {
			return null;
		}

		return [
			'user' => $data['u'],
			'grant' => $data['g'],
			'scopes' => array_values(array_filter($data['s'], is_string(...))),
			'audience' => $data['a'],
		];
	}

	/**
	 * Base64url without padding
	 */
	public static function encode(string $data): string
	{
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	public static function decode(string $data): string
	{
		return (string) base64_decode(strtr($data, '-_', '+/'), true);
	}
}
