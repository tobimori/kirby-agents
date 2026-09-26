<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use tobimori\Agents\OAuth\Access;

final class Tools
{
	/**
	 * Sorted by name, so the list is stable for client caches
	 *
	 * @return list<Tool>
	 */
	public static function all(): array
	{
		$tools = [
			new ChangesDiscard(),
			new ChangesPublish(),
			new ContentGet(),
			new ContentUpdate(),
			new PageCreate(),
			new PageRulesGet(),
			new PagesFind(),
			new SchemaGet(),
			new SiteOverview(),
		];
		usort($tools, static fn(Tool $a, Tool $b): int => strcmp($a->name(), $b->name()));

		return $tools;
	}

	/**
	 * Tools the access token has the scope for
	 *
	 * @return list<Tool>
	 */
	public static function for(Access $access): array
	{
		return array_values(array_filter(self::all(), static fn(Tool $tool): bool => $access->allows($tool->scope())));
	}

	public static function find(string $name): ?Tool
	{
		foreach (self::all() as $tool) {
			if ($tool->name() === $name) {
				return $tool;
			}
		}

		return null;
	}
}
