<?php

declare(strict_types=1);

namespace tobimori\Agents;

use Kirby\Cms\App;
use Kirby\Content\Field;

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
	 * Path of the endpoints outside the Panel (option `path`), or null for the Panel
	 */
	public static function path(): ?string
	{
		$path = static::option('path');

		return is_string($path) ? trim($path, '/') : null;
	}

	/**
	 * OAuth issuer and base of all endpoints: the Panel URL, or the URL of the `path` option
	 */
	public static function issuer(): string
	{
		$kirby = App::instance();
		$path = static::path();

		return rtrim($path === null ? (string) $kirby->url('panel') : (string) $kirby->url() . '/' . $path, '/');
	}

	/**
	 * Canonical URL of the MCP endpoint
	 */
	public static function resource(): string
	{
		return static::issuer() . '/mcp';
	}

	public static function siteTitle(): string
	{
		$title = App::instance()->site()->content()->get('title');

		return $title instanceof Field ? (string) $title->value() : '';
	}
}
