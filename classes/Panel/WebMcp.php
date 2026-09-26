<?php

declare(strict_types=1);

namespace tobimori\Agents\Panel;

use Kirby\Cms\App;
use Kirby\Exception\PermissionException;
use Kirby\Http\Response;
use tobimori\Agents\Agents;
use tobimori\Agents\Http\RateLimit;
use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\Scope;
use tobimori\Agents\Protocol\Server;
use tobimori\Agents\Tools\ScopeRequired;
use tobimori\Agents\Tools\Tools;

/**
 * Bridge for WebMCP: the Panel registers the MCP tools with `document.modelContext`,
 * and calls them through the Kirby API with the Panel session and its CSRF token.
 * The user is at the browser, so the agent gets all scopes the role allows.
 */
final class WebMcp
{
	/**
	 * Id of the access, used where MCP uses the grant id, for example in confirm codes
	 */
	private const GRANT = 'panel';

	/**
	 * `GET /api/agents/tools`: definitions in the WebMCP format
	 *
	 * @return array{enabled: bool, tools: list<array<string, mixed>>}
	 */
	public static function tools(): array
	{
		$access = self::access();

		if ($access === null) {
			return ['enabled' => false, 'tools' => []];
		}

		$tools = [];

		foreach (Server::definitions($access) as $definition) {
			$annotations = is_array($definition['annotations'] ?? null) ? $definition['annotations'] : [];
			$readOnly = ($annotations['readOnlyHint'] ?? false) === true;

			$tools[] = [
				'name' => $definition['name'],
				'title' => $definition['title'] ?? null,
				'description' => $definition['description'] ?? '',
				'inputSchema' => $definition['inputSchema'] ?? ['type' => 'object'],
				'annotations' => [
					'readOnlyHint' => $readOnly,
					// changes that cannot be undone, like deleting, need a confirmation in the browser
					'consequentialHint' => ($annotations['destructiveHint'] ?? false) === true,
					// read tools return content that editors wrote, which can contain instructions
					'untrustedContentHint' => $readOnly,
				],
			];
		}

		return ['enabled' => true, 'tools' => $tools];
	}

	/**
	 * `POST /api/agents/tools/<name>`: runs a tool, returns an MCP tool result
	 *
	 * @return array<string, mixed>|Response
	 */
	public static function call(string $name, array $arguments): array|Response
	{
		$access = self::access() ?? throw new PermissionException(
			message: 'Agents in the browser are not available for your role',
		);
		$limited = RateLimit::hit('mcp', 'user ' . $access->user->id());

		if ($limited !== null) {
			return $limited;
		}

		$tool = Tools::find($name);

		if ($tool === null) {
			return self::error("Unknown tool: {$name}");
		}

		try {
			return Server::run($tool, $arguments, $access);
		} catch (ScopeRequired $error) {
			return self::error("Your role may not do this (it needs `{$error->scope->value}`).");
		}
	}

	/**
	 * The logged-in user with all scopes of the role, or null if WebMCP is off or the role may not connect agents
	 */
	private static function access(): ?Access
	{
		$user = App::instance()->user();

		if ($user === null || Agents::option('webmcp', true) === false) {
			return null;
		}

		$scopes = Scope::allowedFor($user, Scope::all());

		return $scopes === [] ? null : new Access($user, self::GRANT, $scopes);
	}

	/**
	 * @return array{content: list<array{type: string, text: string}>, isError: true}
	 */
	private static function error(string $message): array
	{
		return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
	}
}
