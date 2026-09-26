<?php

declare(strict_types=1);

namespace tobimori\Agents\Content;

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use tobimori\Agents\Tools\ToolError;

final class Models
{
	/**
	 * Description of the `page` argument of tools that work on content
	 */
	public const CONTENT_ID = 'Page id, for example `blog/my-post`, `site`, or a file id, for example `blog/my-post/photo.jpg`';

	/**
	 * A page, the site, or a file: all models with content fields.
	 * Files are found by id (`blog/my-post/photo.jpg`, or only the filename for site files) or UUID.
	 */
	public static function content(string $id): Site|Page|File
	{
		$kirby = App::instance();

		if ($id === 'site') {
			return $kirby->site();
		}

		$model = $kirby->page($id, drafts: true) ?? $kirby->file($id, drafts: true);

		if ($model === null || $model->isAccessible() === false) {
			throw new ToolError("No page or file with the id `{$id}`");
		}

		return $model;
	}

	/**
	 * A page by id, drafts included, or `site`. Pages the user may not access do not exist here.
	 */
	public static function find(string $id): Site|Page
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
