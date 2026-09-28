<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use Kirby\Exception\Exception as KirbyException;
use Kirby\Exception\InvalidArgumentException;
use tobimori\Agents\Agents;
use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\Scope;

final class Tools
{
	public const EXTENSION = 'tobimori.agents.tools';

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

		foreach (Agents::extensions(self::EXTENSION) as $plugin => $declared) {
			foreach (is_array($declared) ? $declared : [] as $class) {
				$tools[] = self::custom($class, $tools, $plugin);
			}
		}

		usort($tools, static fn(Tool $a, Tool $b): int => strcmp($a->name(), $b->name()));

		return self::enabled($tools);
	}

	/**
	 * Without the tools that the `tools` option turns off
	 *
	 * @param list<Tool> $tools
	 *
	 * @return list<Tool>
	 */
	private static function enabled(array $tools): array
	{
		$option = Agents::option('tools', []);
		$names = array_map(static fn(Tool $tool): string => $tool->name(), $tools);

		foreach (is_array($option) ? $option : [] as $name => $enabled) {
			// a typo would leave the tool on without a sign
			if (!in_array($name, $names, true) || !is_bool($enabled)) {
				throw new InvalidArgumentException(
					message: "The option tobimori.agents.tools: `{$name}` must be the name of a tool, with `false` to turn it off",
				);
			}
		}

		return array_values(array_filter(
			$tools,
			static fn(Tool $tool): bool => !is_array($option) || ($option[$tool->name()] ?? true) !== false,
		));
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

	/**
	 * @param list<Tool> $tools
	 */
	private static function custom(mixed $class, array $tools, string $plugin): Tool
	{
		if (!is_string($class) || !class_exists($class) || !is_subclass_of($class, Tool::class)) {
			throw new InvalidArgumentException(
				message: "The tools of the plugin {$plugin} must be names of classes that implement " . Tool::class,
			);
		}

		// @mago-expect analysis:unsafe-instantiation (class_exists() is false for the interface)
		$tool = new $class();
		$name = $tool->name();
		$error = static fn(string $problem): InvalidArgumentException => new InvalidArgumentException(
			message: "The tool `{$name}` of the plugin {$plugin}: {$problem}",
		);

		// the allowed characters of the MCP spec
		if (preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $name) !== 1) {
			throw $error('the name may only contain letters, digits, `_`, `-` and `.`');
		}

		if (array_filter($tools, static fn(Tool $other): bool => $other->name() === $name) !== []) {
			throw $error('another tool has this name already');
		}

		if (Scope::find($tool->scope()) === null) {
			throw $error("the scope `{$tool->scope()}` does not exist");
		}

		return $tool;
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
						"Needs the `{$tool->scope()}` scope, which this connection does not have yet. A call asks the user to allow it in the browser, so ask the user first.\n\n"
						. $definition['description'];
				}

				// ChatGPT starts the authorization for a missing scope only for tools that name it here
				$definition['securitySchemes'] = [['type' => 'oauth2', 'scopes' => [$tool->scope()]]];

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
