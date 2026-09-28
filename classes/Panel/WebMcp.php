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
use tobimori\Agents\Tools\ScopeRequired;
use tobimori\Agents\Tools\Tools;

final class WebMcp
{
	private const GRANT = 'panel';

	// upload links need an OAuth grant, and the Panel has its own upload
	private const UPLOAD = 'file_upload';

	/**
	 * @return array{enabled: bool, tools: list<array<string, mixed>>}
	 */
	public static function tools(): array
	{
		$access = self::access();

		if ($access === null) {
			return ['enabled' => false, 'tools' => []];
		}

		$tools = [];

		foreach (Tools::definitions($access) as $definition) {
			if ($definition['name'] === self::UPLOAD) {
				continue;
			}

			$annotations = is_array($definition['annotations'] ?? null) ? $definition['annotations'] : [];
			$readOnly = ($annotations['readOnlyHint'] ?? false) === true;

			$tools[] = [
				'name' => $definition['name'],
				'title' => $definition['title'] ?? null,
				'description' => $definition['description'] ?? '',
				'inputSchema' => $definition['inputSchema'] ?? ['type' => 'object'],
				'annotations' => [
					'readOnlyHint' => $readOnly,
					'consequentialHint' => ($annotations['destructiveHint'] ?? false) === true,
					'untrustedContentHint' => $readOnly,
				],
			];
		}

		return ['enabled' => true, 'tools' => $tools];
	}

	/**
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

		if ($name === self::UPLOAD) {
			return self::error('Uploads are not available here. Ask the user to upload the file in the Panel.');
		}

		$tool = Tools::find($name);

		if ($tool === null) {
			return self::error("Unknown tool: {$name}");
		}

		try {
			return Tools::run($tool, $arguments, $access);
		} catch (ScopeRequired $error) {
			return self::error("Your role may not do this (it needs `{$error->scope}`).");
		}
	}

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
