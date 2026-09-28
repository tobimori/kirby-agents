<?php

declare(strict_types=1);

namespace tobimori\Agents;

use Closure;
use Kirby\Cms\App;
use Kirby\Content\Field;
use Kirby\Plugin\Plugin;

final class Agents
{
	/**
	 * An option of `tobimori.agents`. A closure is called and gives the value
	 */
	public static function option(string $key, mixed $default = null): mixed
	{
		$option = App::instance()->option("tobimori.agents.{$key}", $default);

		// not is_callable(): strings like `date` are callable too
		return $option instanceof Closure ? $option() : $option;
	}

	/**
	 * The values of a plugin key, like `tobimori.agents.tools`, by the name of the plugin
	 *
	 * @return array<string, mixed>
	 */
	public static function extensions(string $key): array
	{
		$values = [];

		foreach (App::instance()->plugins() as $plugin) {
			if (!$plugin instanceof Plugin || !array_key_exists($key, $plugin->extends())) {
				continue;
			}

			$values[$plugin->name()] = $plugin->extends()[$key];
		}

		return $values;
	}

	public static function path(): ?string
	{
		$path = static::option('path');

		return is_string($path) ? trim($path, '/') : null;
	}

	public static function issuer(): string
	{
		$kirby = App::instance();
		$path = static::path();

		return rtrim($path === null ? (string) $kirby->url('panel') : (string) $kirby->url() . '/' . $path, '/');
	}

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
