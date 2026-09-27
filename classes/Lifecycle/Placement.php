<?php

declare(strict_types=1);

namespace tobimori\Agents\Lifecycle;

use Kirby\Cms\App;
use Kirby\Cms\Blueprint;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Cms\Site;
use Kirby\Content\MemoryStorage;
use Kirby\Toolkit\A;
use Kirby\Toolkit\I18n;
use Kirby\Toolkit\Str;
use Throwable;
use tobimori\Agents\Content\Models;

/**
 * Reads the section props: a `Section` object is slow and writes missing UUIDs into content files
 */
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
	 * @return array<string, string>
	 */
	public static function templates(Site|Page $parent): array
	{
		$templates = [];
		$models = $parent instanceof Page ? [$parent, $parent->site()] : [$parent];

		foreach ($models as $model) {
			foreach (self::sections($model, $parent) as $props) {
				$names = self::names($props);

				if (self::canAdd($model, $parent, $props, $names)) {
					foreach ($names as $name) {
						$templates[$name] = self::title($name);
					}
				}
			}
		}

		return array_filter(
			$templates,
			static fn(string $title, string $template): bool => self::draft($parent, $template)
				->permissions()
				->can('create'),
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
			$sections = self::sections($target, $target);
			$allowed = array_merge(...array_map(self::templatesProp(...), $sections));

			if (
				$sections !== []
				&& ($allowed === [] || in_array($template, $allowed, true))
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
	 * @return list<array<array-key, mixed>>
	 */
	public static function sections(Site|Page $model, Site|Page $parent, string $type = 'pages'): array
	{
		$tabs = $model->blueprint()->toArray()['tabs'] ?? [];
		$sections = [];

		foreach (is_array($tabs) ? $tabs : [] as $tab) {
			$columns = is_array($tab) && is_array($tab['columns'] ?? null) ? $tab['columns'] : [];

			foreach ($columns as $column) {
				$props = is_array($column) && is_array($column['sections'] ?? null) ? $column['sections'] : [];

				foreach ($props as $section) {
					if (
						is_array($section)
						&& ($section['type'] ?? null) === $type
						&& self::lists($model, $section, $parent)
					) {
						$sections[] = $section;
					}
				}
			}
		}

		return $sections;
	}

	public static function lists(Site|Page $model, array $section, Site|Page $parent): bool
	{
		try {
			$listed = is_string($section['parent'] ?? null) ? $model->query($section['parent']) : $model;
		} catch (Throwable) {
			return false;
		}

		return (
			$listed instanceof Site
			&& $parent instanceof Site
			|| $listed instanceof Page
			&& $parent instanceof Page
			&& $listed->is($parent)
		);
	}

	/**
	 * @return list<string>
	 */
	private static function names(array $section): array
	{
		$names = self::strings($section['create'] ?? null);
		$names = $names !== [] ? $names : self::templatesProp($section);
		$names = $names !== [] ? $names : self::strings(App::instance()->blueprints());

		return array_values(array_diff($names, self::strings($section['templatesIgnore'] ?? null)));
	}

	/**
	 * @return list<string>
	 */
	private static function templatesProp(array $section): array
	{
		return self::strings($section['templates'] ?? $section['template'] ?? null);
	}

	/**
	 * @return list<string>
	 */
	private static function strings(mixed $value): array
	{
		$strings = [];

		foreach (A::wrap($value) as $item) {
			if (is_string($item) && $item !== '') {
				$strings[] = $item;
			}
		}

		return $strings;
	}

	/**
	 * @param list<string> $names
	 */
	private static function canAdd(Site|Page $model, Site|Page $parent, array $section, array $names): bool
	{
		if (($section['create'] ?? null) === false || $names === []) {
			return false;
		}

		$status = self::status($section);
		$max = $section['max'] ?? null;

		if (is_int($max) && self::listed($model, $parent, $section, $status) >= $max) {
			return false;
		}

		if ($status === 'all') {
			return true;
		}

		$statuses = array_unique(array_map(static function (string $name): string {
			try {
				$status = Blueprint::load('pages/' . $name)['create']['status'] ?? 'draft';
			} catch (Throwable) {
				$status = 'draft';
			}

			return is_string($status) ? $status : 'draft';
		}, $names));

		return count($statuses) === 1 && $statuses[0] === $status;
	}

	private static function status(array $section): string
	{
		$status = $section['status'] ?? '';
		$status = $status === 'drafts' ? 'draft' : $status;

		return in_array($status, ['draft', 'published', 'listed', 'unlisted'], true) ? $status : 'all';
	}

	private static function listed(Site|Page $model, Site|Page $parent, array $section, string $status): int
	{
		$query = $section['query'] ?? null;
		$pages = is_string($query) ? $model->query($query, Pages::class) : null;
		$pages = $pages instanceof Pages ? $pages : $parent->childrenAndDrafts();

		$ignore = self::strings($section['templatesIgnore'] ?? null);

		return self::withStatus($pages, $status)
			->template(self::templatesProp($section))
			->filter(static fn(Page $page): bool => !in_array($page->intendedTemplate()->name(), $ignore, true))
			->count();
	}

	/**
	 * The title of a page blueprint, translated
	 */
	public static function title(string $name): string
	{
		try {
			$title = Blueprint::load('pages/' . $name)['title'] ?? $name;
		} catch (Throwable) {
			return ucfirst($name);
		}

		$title = is_array($title) ? I18n::translate($title) : $title;

		return is_string($title) ? $title : $name;
	}
}
