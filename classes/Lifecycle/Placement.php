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
use Throwable;

/**
 * Where pages can be created and moved to, by the rules of the `pages` sections.
 * It reads the section props, because a `Section` object computes its Panel items,
 * which is slow and writes missing UUIDs into content files.
 */
final class Placement
{
	/**
	 * Most pages to check for move targets
	 */
	public const MAX_MOVE_CHECKS = 300;

	private const SORT = [
		'default' => 'listed pages have a position, which you can set with page_update',
		'zero' => 'listed pages have no position and are sorted by title',
		'date' => 'listed pages are sorted by their date field, the position is set automatically',
		'datetime' => 'listed pages are sorted by their date field, the position is set automatically',
	];

	/**
	 * How the page is sorted among its listed siblings, from `num` in its blueprint
	 */
	public static function sorting(Page $page): string
	{
		$num = $page->blueprint()->num();

		return self::SORT[$num] ?? "listed pages are sorted by the query `{$num}`, the position is set automatically";
	}

	/**
	 * Templates for new children of the parent, like the add buttons of the pages sections
	 * that list its children: in its own blueprint and, for pages, in the site blueprint
	 *
	 * @return array<string, string> title by template name
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

		// the role may not create some templates (`options.create` in the blueprint)
		return array_filter(
			$templates,
			static fn(string $title, string $template): bool => self::draft($parent, $template)
				->permissions()
				->can('create'),
			ARRAY_FILTER_USE_BOTH,
		);
	}

	/**
	 * Unsaved draft for the template, to read its blueprint and permissions
	 */
	public static function draft(Site|Page $parent, string $template, string $slug = '__new__'): Page
	{
		$page = Page::factory([
			'slug' => $slug,
			'template' => $template,
			'model' => $template,
			'parent' => $parent instanceof Page ? $parent : null,
			'isDraft' => true,
		]);

		// copy, never move: with the slug of an existing draft, moving deletes its content from the disk
		$page->changeStorage(MemoryStorage::class, copy: true);

		return $page;
	}

	/**
	 * Ids of the site and pages the page can move to, with the checks of `PageRules::move`:
	 * not into itself, no page with the same slug, and a pages section in the blueprint
	 * of the target that lists its children and accepts the template.
	 * page_update runs the real `PageRules::move` before a move.
	 *
	 * @return array{targets: list<string>, complete: bool}
	 */
	public static function moveTargets(Page $page, int $limit): array
	{
		$site = App::instance()->site();
		$current = $page->parent()?->id() ?? 'site';
		$template = $page->intendedTemplate()->name();
		$targets = [];
		$checked = 0;

		foreach ([$site, ...$site->index(drafts: true)] as $target) {
			if (count($targets) >= $limit || $checked >= self::MAX_MOVE_CHECKS) {
				return ['targets' => $targets, 'complete' => false];
			}

			$id = $target instanceof Page ? $target->id() : 'site';

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
	 * Props of the sections of a type (`pages` or `files`) in the blueprint of the model
	 * that list the children or files of the parent
	 *
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

	/**
	 * The `parent` prop is a query from the model. Without it, the section lists the children of the model.
	 */
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
	 * Templates the add button offers: `create`, else `templates`, else all page blueprints
	 *
	 * @return list<string>
	 */
	private static function names(array $section): array
	{
		// `create: true` only allows creation, like no `create`
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
	 * A string or a list as a list of non-empty strings
	 *
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
	 * The `add` value of the section: false when creation is off, the section is full,
	 * or it would not show the new pages because of its status filter
	 *
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

	/**
	 * Number of pages the section shows, to compare with `max`
	 */
	private static function listed(Site|Page $model, Site|Page $parent, array $section, string $status): int
	{
		$query = $section['query'] ?? null;
		$pages = is_string($query) ? $model->query($query, Pages::class) : null;
		$pages = $pages instanceof Pages ? $pages : $parent->childrenAndDrafts();

		$pages = match ($status) {
			'draft' => $pages->filter(static fn(Page $page): bool => $page->isDraft()),
			'published' => $pages->filter(static fn(Page $page): bool => !$page->isDraft()),
			'listed' => $pages->filter(static fn(Page $page): bool => $page->isListed()),
			'unlisted' => $pages->filter(static fn(Page $page): bool => $page->isUnlisted()),
			default => $pages,
		};

		$templates = self::templatesProp($section);
		$ignore = self::strings($section['templatesIgnore'] ?? null);

		return $pages
			->filter(
				static fn(Page $page): bool => (
					($templates === [] || in_array($page->intendedTemplate()->name(), $templates, true))
					&& !in_array($page->intendedTemplate()->name(), $ignore, true)
				),
			)
			->count();
	}

	private static function title(string $name): string
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
