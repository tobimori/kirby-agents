<?php

declare(strict_types=1);

namespace tobimori\Agents\Content;

use Kirby\Cms\App;
use Kirby\Cms\ModelWithContent;
use tobimori\Agents\Tools\ToolError;

final class Models
{
	/**
	 * A page by id, drafts included, or `site`. Pages the user may not access do not exist here.
	 */
	public static function find(string $id): ModelWithContent
	{
		$kirby = App::instance();

		if ($id === 'site') {
			return $kirby->site();
		}

		$page = $kirby->page($id, drafts: true);

		if ($page === null || $page->isAccessible() === false) {
			throw new ToolError("No page with the id `{$id}`");
		}

		return $page;
	}
}
