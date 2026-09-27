<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use Kirby\Cms\Page;
use tobimori\Agents\Content\Models;
use tobimori\Agents\Content\PageInfo;
use tobimori\Agents\Content\Presenter;
use tobimori\Agents\Content\Reader;
use tobimori\Agents\Lifecycle\Creator;
use tobimori\Agents\Lifecycle\Placement;
use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\Scope;

final class PageCreate implements Tool
{
	public function name(): string
	{
		return 'page_create';
	}

	public function definition(): array
	{
		return [
			'title' => 'Create a page',
			'description' => implode("\n", [
				'Creates a page like the create dialog in the Panel. Read page_rules of the parent first: its `create` list has the allowed templates and their create options.',
				'New pages are drafts, unless the blueprint sets another status. Then add the content with content_update: the result has the `etag` and outline of the new page.',
				'Drafts are not public, so content_update can save a draft with `version: latest` without the publish scope.',
			]),
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'parent' => [
						'type' => 'string',
						'description' => 'Page id of the parent, for example `blog`, or `site` for a top-level page',
					],
					'template' => [
						'type' => 'string',
						'description' => 'A template from the `create` list of page_rules',
					],
					'title' => [
						'type' => 'string',
						'description' => 'Title, unless the create options set it from a template',
					],
					'slug' => [
						'type' => 'string',
						'description' => 'URL part, for example `my-post`. Without it, it comes from the title',
					],
					'content' => [
						'type' => 'object',
						'description' => 'Values for the `fields` of the create options, in the formats of schema_get',
					],
					'dryRun' => [
						'type' => 'boolean',
						'default' => false,
						'description' => 'Check the input without creating the page',
					],
				],
				'required' => ['parent', 'template'],
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

	public function scope(): Scope
	{
		return Scope::PagesManage;
	}

	public function call(Arguments $arguments, Access $access): string
	{
		$parent = Models::find((string) $arguments->string('parent'));

		$template = (string) $arguments->string('template');
		$templates = Placement::templates($parent);

		if (!array_key_exists($template, $templates)) {
			throw new ToolError(
				$templates === []
					? 'No pages can be created here. The pages sections of the parent do not allow it, are full, or your role may not create pages.'
					: "The template `{$template}` is not allowed here. Allowed: "
					. implode(', ', array_keys($templates))
					. '.',
			);
		}

		$creator = new Creator($parent, $template, $arguments->string('title'), $arguments->string('slug'));

		if ($creator->status() !== 'draft' && $access->allows(Scope::ContentPublish) === false) {
			throw new ScopeRequired(Scope::ContentPublish);
		}

		$content = $arguments->object('content');
		$dryRun = $arguments->bool('dryRun', false);
		$page = $creator->create($content, save: $dryRun === false);

		if (!$page instanceof Page) {
			return 'Dry run: the page can be created. Nothing was saved.';
		}

		$summary = PageInfo::summary($page);

		return implode("\n", [
			"Created {$summary['status']} `{$summary['id']}` with the template `{$summary['template']}`.",
			'Add content with content_update and this etag.',
			'',
			Presenter::outline(Reader::read($page)),
		]);
	}
}
