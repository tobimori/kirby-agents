<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use tobimori\Agents\Content\Models;
use tobimori\Agents\Content\Pending;
use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\Scope;

final class ChangesDiscard implements Tool
{
	public function name(): string
	{
		return 'changes_discard';
	}

	public function definition(): array
	{
		return [
			'title' => 'Discard unsaved changes',
			'description' => implode("\n", [
				'Deletes the unsaved changes of a page, the site, or a file, like the Discard button in the Panel. The published content stays. This cannot be undone.',
				'Read the page with content_get first and send its `etag`, so you do not delete changes you have not seen. The changes can also be from an editor, so only discard when the user asked for it.',
			]),
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'page' => [
						'type' => 'string',
						'description' => Models::CONTENT_ID,
					],
					'etag' => [
						'type' => 'string',
						'description' => '`etag` of the `changes` version, from content_get or from the result of content_update',
					],
					'language' => [
						'type' => 'string',
						'description' => 'Language code on multi-language sites. Without it, the default language',
					],
				],
				'required' => ['page', 'etag'],
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

	public function scope(): Scope
	{
		return Scope::ContentWrite;
	}

	public function call(Arguments $arguments, Access $access): string
	{
		$pending = Pending::discard(
			Models::content((string) $arguments->string('page')),
			(string) $arguments->string('etag'),
			$arguments->string('language'),
		);

		$text = $pending->changed === []
			? 'Discarded. The changes were the same as the published content.'
			: 'Discarded the changes to: '
			. implode(', ', $pending->changed)
			. '. The published content applies again.';

		if ($pending->editor !== null) {
			$text .= " These were the unsaved changes of {$pending->editor}, and they are discarded now.";
		}

		return $text;
	}
}
