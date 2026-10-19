<?php

declare(strict_types=1);

namespace tobimori\Agents\Plugins\Retour\Tools;

use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\Scope;
use tobimori\Agents\Plugins\Retour\RedirectInfo;
use tobimori\Agents\Tools\Arguments;
use tobimori\Agents\Tools\Tool;
use tobimori\Agents\Tools\ToolError;

final class RedirectCreate implements Tool
{
	public function name(): string
	{
		return 'retour_redirect_create';
	}

	public function definition(): array
	{
		return [
			'title' => 'Create a redirect',
			'description' => implode("\n", [
				'Creates a redirect with the Retour plugin, for example after a page moved or for a 404 error from retour_failures_find.',
				'The redirect works at once for all visitors: there are no unsaved changes to review. Tell the user which redirects you create. A 301 redirect stays in the cache of browsers, so use it only when the old path will not come back.',
				RedirectInfo::PATTERNS,
				'Marks the logged 404 errors as resolved that the redirect now handles. Returns the redirect, the `resolved` paths, and `notes` if the redirect will not work as expected.',
			]),
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'from' => ['type' => 'string', 'description' => RedirectInfo::PATTERN],
					'to' => [
						'type' => 'string',
						'description' => RedirectInfo::TARGET . '. Not needed for 4xx codes',
					],
					'status' => ['type' => ['integer', 'string'], 'description' => RedirectInfo::STATUS],
					'priority' => [
						'type' => 'boolean',
						'default' => false,
						'description' => 'True to redirect even when a page exists at `from`',
					],
					'comment' => ['type' => 'string', 'description' => 'Why the redirect exists, for editors'],
				],
				'required' => ['from'],
				'additionalProperties' => false,
			],
			'annotations' => [
				'readOnlyHint' => false,
				'destructiveHint' => false,
				'idempotentHint' => false,
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
		$retour = RedirectInfo::retour();
		$status = $retour->option('status');
		$values = RedirectInfo::values($arguments, [
			'status' => is_numeric($status) ? (int) $status : 301,
			'priority' => false,
		]);
		$redirects = $retour->redirects();

		if (RedirectInfo::get((string) $values['from']) !== null) {
			throw new ToolError(
				"A redirect from `{$values['from']}` exists already. Change it with retour_redirect_update.",
			);
		}

		$redirects->create([...$values, 'creator' => $access->user->email()]);
		$redirects->save();
		$redirect = RedirectInfo::find((string) $values['from']);

		return [
			'redirect' => RedirectInfo::summary($redirect),
			'resolved' => RedirectInfo::resolve($redirect),
			'notes' => RedirectInfo::notes($redirect),
		];
	}
}
