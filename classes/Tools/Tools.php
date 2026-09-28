<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use Kirby\Exception\Exception as KirbyException;
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

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function definitions(Access $access): array
	{
		return array_map(
			static function (Tool $tool) use ($access): array {
				$definition = $tool->definition();
				$schema = is_array($definition['inputSchema'] ?? null) ? $definition['inputSchema'] : [];
				$summary = is_string($definition['description'] ?? null) ? $definition['description'] : '';
				$definition['description'] = $summary . "\n\n" . Guide::parameters($schema);

				if ($access->allows($tool->scope()) === false) {
					$definition['description'] =
						"Needs the `{$tool->scope()->value}` scope, which this connection does not have yet. A call asks the user to allow it in the browser, so ask the user first.\n\n"
						. $definition['description'];
				}

				// ChatGPT starts the authorization for a missing scope only for tools that name it here
				$definition['securitySchemes'] = [['type' => 'oauth2', 'scopes' => [$tool->scope()->value]]];

				return ['name' => $tool->name(), ...$definition];
			},
			self::for($access),
		);
	}

	/**
	 * @throws ScopeRequired
	 *
	 * @return array<string, mixed>
	 */
	public static function run(Tool $tool, array $arguments, Access $access): array
	{
		if ($access->allows($tool->scope()) === false) {
			throw new ScopeRequired($tool->scope());
		}

		try {
			$data = $tool->call(new Arguments($arguments), $access);
		} catch (ToolError|KirbyException $error) {
			return ['content' => [['type' => 'text', 'text' => $error->getMessage()]], 'isError' => true];
		}

		if (is_string($data)) {
			return ['content' => [['type' => 'text', 'text' => $data]]];
		}

		return [
			'content' => [[
				'type' => 'text',
				'text' => (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
			]],
			'structuredContent' => $data,
		];
	}
}
