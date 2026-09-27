<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\Scope;

final class Tools
{
	/**
	 * @return list<Tool>
	 */
	public static function all(): array
	{
		$tools = [
			new ChangesDiscard(),
			new ChangesPublish(),
			new ContentGet(),
			new ContentUpdate(),
			new FileDelete(),
			new FileUpload(),
			new FilesFind(),
			new PageCreate(),
			new PageDelete(),
			new PageRulesGet(),
			new PageUpdate(),
			new PagesFind(),
			new RelationsFind(),
			new SchemaGet(),
			new SiteOverview(),
		];
		usort($tools, static fn(Tool $a, Tool $b): int => strcmp($a->name(), $b->name()));

		return $tools;
	}

	/**
	 * @return list<Tool>
	 */
	public static function for(Access $access): array
	{
		$grantable = new Access($access->user, $access->grant, Scope::allowedFor($access->user, Scope::all()));

		return array_values(array_filter(self::all(), static fn(Tool $tool): bool => $grantable->allows(
			$tool->scope(),
		)));
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
