<?php

declare(strict_types=1);

namespace tobimori\Agents\OAuth;

final class RedirectUri
{
	private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

	private const BLOCKED_SCHEMES = ['http', 'javascript', 'data', 'file', 'vbscript', 'blob', 'about', 'ws', 'wss'];

	public static function isValid(string $uri): bool
	{
		$parts = parse_url($uri);

		if ($parts === false || ($parts['fragment'] ?? null) !== null) {
			return false;
		}

		$scheme = strtolower($parts['scheme'] ?? '');

		return match ($scheme) {
			'' => false,
			'https' => ($parts['host'] ?? '') !== '',
			'http' => self::isLoopback($uri),
			default => !in_array($scheme, self::BLOCKED_SCHEMES, true),
		};
	}

	public static function isLoopback(string $uri): bool
	{
		$parts = parse_url($uri);

		return (
			is_array($parts)
			&& strtolower($parts['scheme'] ?? '') === 'http'
			&& in_array(strtolower($parts['host'] ?? ''), self::LOOPBACK_HOSTS, true)
		);
	}

	public static function matches(string $registered, string $given): bool
	{
		if ($registered === $given) {
			return true;
		}

		if (self::isLoopback($registered) === false || self::isLoopback($given) === false) {
			return false;
		}

		return self::withoutPort($registered) === self::withoutPort($given);
	}

	public static function display(string $uri): string
	{
		$parts = parse_url($uri);

		if (!is_array($parts)) {
			return $uri;
		}

		return $parts['host'] ?? ($parts['scheme'] ?? '') . '://';
	}

	private static function withoutPort(string $uri): string
	{
		return (string) preg_replace('~^(http://[^/:]+|http://\[::1\]):\d+~i', '$1', $uri);
	}
}
