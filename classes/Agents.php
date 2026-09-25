<?php

declare(strict_types=1);

namespace tobimori\Agents;

use Kirby\Cms\App;

final class Agents
{
	/**
	 * Returns a plugin option
	 */
	public static function option(string $key, mixed $default = null): mixed
	{
		return App::instance()->option("tobimori.agents.{$key}", $default);
	}

	/**
	 * OAuth issuer: the Panel URL
	 */
	public static function issuer(): string
	{
		return rtrim((string) App::instance()->url('panel'), '/');
	}

	/**
	 * Canonical URL of the MCP endpoint
	 */
	public static function resource(): string
	{
		return static::issuer() . '/mcp';
	}
}
