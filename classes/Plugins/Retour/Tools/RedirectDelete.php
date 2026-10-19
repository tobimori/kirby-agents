<?php

declare(strict_types=1);

namespace tobimori\Agents\Plugins\Retour\Tools;

use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\Scope;
use tobimori\Agents\Plugins\Retour\RedirectInfo;
use tobimori\Agents\Tools\Arguments;
use tobimori\Agents\Tools\Tool;

final class RedirectDelete implements Tool
{
	public function name(): string
	{
		return 'retour_redirect_delete';
	}

	public function definition(): array
	{
		return [
			'title' => 'Delete a redirect',
			'description' => implode("\n", [
				'Deletes a redirect of the Retour plugin. Visitors of the old path then get a 404 error again, at once. Only delete redirects when the user asked for it.',
				'To stop a redirect but keep it, change its status to `disabled` with retour_redirect_update instead.',
			]),
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'redirect' => ['type' => 'string', 'description' => 'The `from` of the redirect'],
				],
				'required' => ['redirect'],
				'additionalProperties' => false,
			],
			'annotations' => [
				'readOnlyHint' => false,
				'destructiveHint' => true,
				'idempotentHint' => false,
				'openWorldHint' => false,
			],
		];
	}

	public function scope(): string
	{
		return Scope::RETOUR_REDIRECTS;
	}

	public function call(Arguments $arguments, Access $access): string
	{
		$redirect = RedirectInfo::find((string) $arguments->string('redirect'));
		$redirects = RedirectInfo::retour()->redirects();

		$redirects->remove($redirect->id());
		$redirects->save();

		return 'Deleted the redirect from `' . RedirectInfo::path($redirect) . '`.';
	}
}
