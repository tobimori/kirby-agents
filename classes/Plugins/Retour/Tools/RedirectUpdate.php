<?php

declare(strict_types=1);

namespace tobimori\Agents\Plugins\Retour\Tools;

use Kirby\Retour\Redirect;
use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\Scope;
use tobimori\Agents\Plugins\Retour\RedirectInfo;
use tobimori\Agents\Tools\Arguments;
use tobimori\Agents\Tools\Tool;
use tobimori\Agents\Tools\ToolError;

final class RedirectUpdate implements Tool
{
	public function name(): string
	{
		return 'retour_redirect_update';
	}

	public function definition(): array
	{
		return [
			'title' => 'Change a redirect',
			'description' => implode("\n", [
				'Changes a redirect of the Retour plugin. Send only what changes. The change works at once for all visitors, so tell the user what you changed.',
				RedirectInfo::PATTERNS,
				'Returns the redirect, the logged 404 errors that it now handles as `resolved`, and `notes` if the redirect will not work as expected.',
			]),
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'redirect' => ['type' => 'string', 'description' => 'The current `from` of the redirect'],
					'from' => ['type' => 'string', 'description' => 'New path. ' . RedirectInfo::PATTERN],
					'to' => [
						'type' => 'string',
						'description' => RedirectInfo::TARGET . '. Empty to remove',
					],
					'status' => ['type' => ['integer', 'string'], 'description' => RedirectInfo::STATUS],
					'priority' => [
						'type' => 'boolean',
						'description' => 'True to redirect even when a page exists at `from`',
					],
					'comment' => ['type' => 'string', 'description' => 'Empty to remove'],
				],
				'required' => ['redirect'],
				'additionalProperties' => false,
			],
			'annotations' => [
				'readOnlyHint' => false,
				'destructiveHint' => false,
				'idempotentHint' => true,
				'openWorldHint' => false,
			],
		];
	}

	public function scope(): string
	{
		return Scope::RETOUR_REDIRECTS;
	}

	public function call(Arguments $arguments, Access $access): array
	{
		$current = RedirectInfo::find((string) $arguments->string('redirect'));
		$values = RedirectInfo::values($arguments, $current->toArray());
		$redirects = RedirectInfo::retour()->redirects();
		$id = $current->id();
		$redirect = new Redirect([...$values, 'modifier' => $access->user->email()]);

		$other = RedirectInfo::get((string) $values['from']);

		if ($other !== null && $other->id() !== $id) {
			throw new ToolError("A redirect from `{$values['from']}` exists already.");
		}

		// keeps the position in the list
		$redirects->set($id, $redirect);
		$redirects->save();

		return [
			'redirect' => RedirectInfo::summary($redirect),
			'resolved' => RedirectInfo::resolve($redirect),
			'notes' => RedirectInfo::notes($redirect),
		];
	}
}
