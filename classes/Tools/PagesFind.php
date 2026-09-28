<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use tobimori\Agents\Content\PageInfo;
use tobimori\Agents\Lifecycle\Placement;
use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\Scope;

final class PagesFind implements Tool
{
	private const STATUSES = ['all', 'published', 'listed', 'unlisted', 'draft'];

	public function name(): string
	{
		return 'pages_find';
	}

	public function definition(): array
	{
		return [
			'title' => 'Find pages',
			'description' =>
				'Lists pages with filters. Returns `total`, `pages`, and `nextCursor` (null on the last page of results). '
					. PageInfo::FIELDS,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'parent' => [
						'type' => 'string',
						'description' => 'Page id, for example `blog`. Without it, top-level pages',
					],
					'recursive' => [
						'type' => 'boolean',
						'default' => false,
						'description' => 'True includes all descendants, false only direct children',
					],
					'template' => [
						'type' => ['string', 'array'],
						'items' => ['type' => 'string'],
						'description' => 'One template name or a list. Filters by template, not by blueprint',
					],
					'status' => [
						'type' => 'string',
						'enum' => self::STATUSES,
						'default' => 'all',
						'description' => '`all` includes drafts, `published` means listed or unlisted',
					],
					'query' => [
						'type' => 'string',
						'description' => 'Search text. Kirby searches all content fields and sorts by relevance',
					],
					'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
					'cursor' => ['type' => 'string', 'description' => '`nextCursor` from the previous result'],
				],
				'additionalProperties' => false,
			],
			'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
		];
	}

	public function scope(): string
	{
		return Scope::CONTENT_READ;
	}

	public function call(Arguments $arguments, Access $access): array
	{
		$kirby = App::instance();
		$parentId = $arguments->string('parent');
		$parent = $parentId !== null ? $kirby->page($parentId, drafts: true) : $kirby->site();

		if ($parent === null || $parent instanceof Page && !$parent->isListable()) {
			throw new ToolError("No page with the id `{$parentId}`");
		}

		$pages = $arguments->bool('recursive', false) ? $parent->index(drafts: true) : $parent->childrenAndDrafts();

		$templates = $arguments->strings('template');

		$pages = Placement::withStatus($pages->template($templates), $arguments->enum('status', self::STATUSES, 'all'));

		$query = $arguments->string('query');

		if ($query !== null && trim($query) !== '') {
			$pages = $pages->search($query);
		}

		$pages = $pages->filter(static fn(Page $page): bool => $page->isListable());
		$limit = $arguments->int('limit', 20, 1, 100);
		$offset = $arguments->offset();
		$total = $pages->count();

		return [
			'total' => $total,
			'pages' => array_values(array_map(PageInfo::summary(...), $pages->slice($offset, $limit)->values())),
			'nextCursor' => Arguments::nextCursor($offset, $limit, $total),
		];
	}
}
