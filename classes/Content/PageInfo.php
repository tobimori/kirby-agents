<?php

declare(strict_types=1);

namespace tobimori\Agents\Content;

use Kirby\Cms\Page;
use Kirby\Uuid\PageUuid;

final class PageInfo
{
	public const FIELDS = 'Each page has: `id` (its path, use it with other tools), `uuid` (null until the page has a stored UUID), `title`, `template`, `blueprint` (pages without an own blueprint use `default`), `status` (listed, unlisted, or draft), `num` (sort number), `children` (count, drafts included), `modified`, `changes` (true when the page has unsaved changes).';

	/**
	 * @return array{id: string, uuid: string|null, title: string, template: string, blueprint: string, status: string, num: int|null, children: int, modified: string, changes: bool}
	 */
	public static function summary(Page $page): array
	{
		// `uuid()` would write a missing UUID
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
			'changes' => $page->version('changes')->exists('*'),
		];
	}
}
