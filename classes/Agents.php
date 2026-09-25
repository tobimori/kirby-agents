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
	 * The OAuth issuer, which is the Panel URL
	 */
	public static function issuer(): string
	{
		return rtrim((string) App::instance()->url('panel'), '/');
	}

	/**
	 * The canonical URI of the MCP endpoint (RFC 8707 resource)
	 */
	public static function resource(): string
	{
		return static::issuer() . '/mcp';
	}
}
