<?php

declare(strict_types=1);

namespace tobimori\Agents\OAuth;

use SensitiveParameter;

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
		return self::sign(self::ACCESS, [
			'u' => $user,
			'g' => $grant,
			's' => $scopes,
			'a' => $audience,
			'e' => time() + self::ACCESS_TTL,
		]);
	}

	/**
	 * @return array{user: string, grant: string, scopes: list<string>, audience: string}|null
	 */
	public static function parseAccess(#[SensitiveParameter] string $token): ?array
	{
		$data = self::verify(self::ACCESS, $token);

		if (
			$data === null
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
	 * A token that the server can trust: `prefix.payload.signature`. Anyone can read the payload
	 *
	 * @param array<array-key, mixed> $data
	 */
	public static function sign(string $prefix, array $data): string
	{
		$payload = self::encode((string) json_encode($data));

		return $prefix . '.' . $payload . '.' . Secret::sign($prefix . '.' . $payload);
	}

	/**
	 * The payload of a token from sign(), if its prefix and signature are right
	 *
	 * @return array<array-key, mixed>|null
	 */
	public static function verify(string $prefix, #[SensitiveParameter] string $token): ?array
	{
		$parts = explode('.', $token);

		if (
			count($parts) !== 3
			|| $parts[0] !== $prefix
			|| hash_equals(Secret::sign($prefix . '.' . $parts[1]), $parts[2]) === false
		) {
			return null;
		}

		$data = json_decode(self::decode($parts[1]), true);

		return is_array($data) ? $data : null;
	}

	public static function encode(string $data): string
	{
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	public static function decode(string $data): string
	{
		return (string) base64_decode(strtr($data, '-_', '+/'), true);
	}
}
