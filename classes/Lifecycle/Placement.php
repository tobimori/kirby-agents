<?php

declare(strict_types=1);

namespace tobimori\Agents\Lifecycle;

use Kirby\Blueprint\PageBlueprint;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Cms\Site;
use Kirby\Content\MemoryStorage;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Form\Field\PageListField;
use Kirby\Form\Fields;
use Kirby\Toolkit\Str;
use Throwable;
use tobimori\Agents\Content\Models;

final class Placement
{
	public const MAX_MOVE_CHECKS = 300;

	private const SORT = [
		'default' => 'listed pages have a position, which you can set with page_update',
		'zero' => 'listed pages have no position and are sorted by title',
		'date' => 'listed pages are sorted by their date field, the position is set automatically',
		'datetime' => 'listed pages are sorted by their date field, the position is set automatically',
	];

	public static function sorting(Page $page): string
	{
		$num = $page->blueprint()->num();

		return self::SORT[$num] ?? "listed pages are sorted by the query `{$num}`, the position is set automatically";
	}

	/**
	 * The templates for new pages from the page lists of the parent and the site that show its children, like the
	 * create dialog of the Panel
	 *
	 * @return array<string, string>
	 */
	public static function templates(Site|Page $parent): array
	{
		$templates = [];
		$models = $parent instanceof Page ? [$parent, $parent->site()] : [$parent];

		foreach ($models as $model) {
			foreach (self::lists($model, $parent) as $list) {
				foreach ($list->add() ? $list->acceptedPageBlueprints() : [] as $name) {
					$templates[$name] = self::title($name);
				}
			}
		}

		return array_filter(
			$templates,
			static fn(string $title, string $template): bool => self::draft($parent, $template)
				->guards()
				->isAvailable('create'),
			ARRAY_FILTER_USE_BOTH,
		);
	}

	/**
	 * A placeholder page that has no content, also when a real page has the same slug
	 */
	public static function draft(Site|Page $parent, string $template, string $slug = '__new__'): Page
	{
		$page = Page::factory([
			'slug' => $slug,
			'template' => $template,
			'model' => $template,
			'parent' => $parent instanceof Page ? $parent : null,
			'isDraft' => true,
			// a folder that does not exist: the storage reads the content from the root
			'root' => sys_get_temp_dir() . '/kirby-agents-' . (string) Str::random(16, 'alphaNum'),
		]);

		// in memory, so that nothing is written
		$page->changeStorage(MemoryStorage::class, copy: true);

		return $page;
	}

	/**
	 * @return array{targets: list<string>, complete: bool}
	 */
	public static function moveTargets(Page $page, int $limit): array
	{
		$site = App::instance()->site();
		$current = Models::id($page->parentModel());
		$template = $page->intendedTemplate()->name();
		$targets = [];
		$checked = 0;

		foreach ([$site, ...$site->index(drafts: true)] as $target) {
			if (count($targets) >= $limit || $checked >= self::MAX_MOVE_CHECKS) {
				return ['targets' => $targets, 'complete' => false];
			}

			$id = Models::id($target);

			if ($id === $current || $target instanceof Page && ($target->is($page) || $page->isAncestorOf($target))) {
				continue;
			}

			$checked++;

			// like Kirby's check of a move
			if (
				Fields::for($target)->hasPageBlueprint($template)
				&& !$target->childrenAndDrafts()->find($page->slug()) instanceof Page
			) {
				$targets[] = $id;
			}
		}

		return ['targets' => $targets, 'complete' => true];
	}

	/**
	 * `drafts()` of a collection returns the drafts of its pages, not the drafts in it
	 */
	public static function withStatus(Pages $pages, string $status): Pages
	{
		return match ($status) {
			'published' => $pages->published(),
			'listed' => $pages->listed(),
			'unlisted' => $pages->unlisted(),
			'draft' => $pages->filter('isDraft', '==', true),
			default => $pages,
		};
	}

	/**
	 * @return list<PageListField>
	 */
	private static function lists(Site|Page $model, Site|Page $parent): array
	{
		$lists = [];

		foreach (Fields::for($model) as $field) {
			if (!$field instanceof PageListField) {
				continue;
			}

			try {
				$listed = $field->parentModel();
			} catch (InvalidArgumentException) {
				continue;
			}

			if (Models::is($listed, $parent)) {
				$lists[] = $field;
			}
		}

		return $lists;
	}

	/**
	 * The title of a page blueprint, translated
	 */
	public static function title(string $name): string
	{
		try {
			return PageBlueprint::factory(App::instance()->site(), 'pages/' . $name)?->title() ?? ucfirst($name);
		} catch (Throwable) {
			return ucfirst($name);
		}
	}
}
