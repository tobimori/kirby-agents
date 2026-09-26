<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use tobimori\Agents\Content\Models;
use tobimori\Agents\Content\Pending;
use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\Scope;

final class ChangesPublish implements Tool
{
	public function name(): string
	{
		return 'changes_publish';
	}

	public function definition(): array
	{
		return [
			'title' => 'Publish unsaved changes',
			'description' => implode("\n", [
				'Publishes the unsaved changes of a page or the site, like the Save button in the Panel. The changes can come from content_update or from an editor in the Panel.',
				'Read the page with content_get first (it shows the `changes` version) and send its `etag`, so you publish exactly what you read. The page status does not change: a draft stays a draft. To make a page public, use page_update with `status`.',
				'Content with invalid fields is not published. Only publish when the user asked for it.',
			]),
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'page' => [
						'type' => 'string',
						'description' => 'Page id, for example `blog/my-post`, or `site`',
					],
					'etag' => [
						'type' => 'string',
						'description' => '`etag` from content_get of the `changes` version',
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
		return Scope::ContentPublish;
	}

	public function call(Arguments $arguments, Access $access): string
	{
		$pending = Pending::for(
			Models::find((string) $arguments->string('page')),
			(string) $arguments->string('etag'),
			$arguments->string('language'),
		);
		$pending->publish();

		return $pending->changed === []
			? 'Published. The changes were the same as the published content.'
			: 'Published. Changed fields: ' . implode(', ', $pending->changed) . '.';
	}
}
