<?php

declare(strict_types=1);

namespace tobimori\Agents\Content;

use Kirby\Cms\Page;
use Kirby\Uuid\PageUuid;

/**
 * How pages are shown to agents in lists and trees
 */
final class PageInfo
{
	/**
	 * Explains the summary fields in tool descriptions
	 */
	public const FIELDS = 'Each page has: `id` (its path, use it with other tools), `uuid` (null until the page has a stored UUID), `title`, `template`, `blueprint` (pages without an own blueprint use `default`), `status` (listed, unlisted, or draft), `num` (sort number), `children` (count, drafts included), `modified`.';

	/**
	 * @return array{id: string, uuid: string|null, title: string, template: string, blueprint: string, status: string, num: int|null, children: int, modified: string}
	 */
	public static function summary(Page $page): array
	{
		// `uuid()` would generate and write a missing UUID, which a read must not do
		$uuid = PageUuid::retrieveId($page);

		return [
			'id' => $page->id(),
			'uuid' => $uuid !== null ? 'page://' . $uuid : null,
			'title' => (string) $page->title()->value(),
			'template' => $page->intendedTemplate()->name(),
			'blueprint' => basename($page->blueprint()->name()),
			'status' => $page->status(),
			'num' => $page->num(),
			'children' => $page->childrenAndDrafts()->count(),
			'modified' => date('c', (int) $page->modified()),
		];
	}
}
