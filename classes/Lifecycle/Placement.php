<?php

declare(strict_types=1);

namespace tobimori\Agents\Lifecycle;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\PageRules;
use Kirby\Cms\Section;
use Kirby\Cms\Site;
use Kirby\Content\MemoryStorage;
use Kirby\Exception\Exception as KirbyException;
use Kirby\Toolkit\I18n;

/**
 * Where pages can be created and moved to, by the same rules as the Panel
 */
final class Placement
{
	/**
	 * Most pages to check for move targets, because each check loads the sections of a blueprint
	 */
	public const MAX_MOVE_CHECKS = 300;

	/**
	 * Templates for new children of the parent, like the add buttons of the pages sections
	 * that list its children: in its own blueprint and, for pages, in the site blueprint
	 *
	 * @return array<string, string> title by template name
	 */
	public static function templates(Site|Page $parent): array
	{
		$templates = [];

		foreach (self::sections($parent) as $section) {
			// false when creation is off, the section is full, or it would not show the new page
			if ($section->__call('add') !== true) {
				continue;
			}

			$blueprints = $section->__call('blueprints');

			foreach (is_array($blueprints) ? $blueprints : [] as $blueprint) {
				if (is_array($blueprint) && is_string($blueprint['name'] ?? null)) {
					$title = $blueprint['title'] ?? $blueprint['name'];
					$title = is_array($title) ? I18n::translate($title) : $title;
					$templates[$blueprint['name']] = is_string($title) ? $title : $blueprint['name'];
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
	 * Ids of the site and pages the page can move to, checked with `PageRules::move`
	 *
	 * @return array{targets: list<string>, complete: bool}
	 */
	public static function moveTargets(Page $page, int $limit): array
	{
		$site = App::instance()->site();
		$current = $page->parent()?->id() ?? 'site';
		$targets = [];
		$checked = 0;

		foreach ([$site, ...$site->index(drafts: true)] as $parent) {
			if (count($targets) >= $limit || $checked >= self::MAX_MOVE_CHECKS) {
				return ['targets' => $targets, 'complete' => false];
			}

			$id = $parent instanceof Page ? $parent->id() : 'site';

			if ($id === $current || !$parent instanceof Page && !$parent instanceof Site) {
				continue;
			}

			$checked++;

			if (self::canMove($page, $parent)) {
				$targets[] = $id;
			}
		}

		return ['targets' => $targets, 'complete' => true];
	}

	private static function canMove(Page $page, Site|Page $parent): bool
	{
		try {
			PageRules::move($page, $parent);

			return true;
		} catch (KirbyException) {
			return false;
		}
	}

	/**
	 * @return list<Section>
	 */
	private static function sections(Site|Page $parent): array
	{
		$blueprints = [$parent->blueprint()];

		if ($parent instanceof Page) {
			$blueprints[] = $parent->site()->blueprint();
		}

		$sections = [];

		foreach ($blueprints as $blueprint) {
			foreach ($blueprint->sections() as $section) {
				// sections have their values only through `__call`
				if (!$section instanceof Section || $section->__call('type') !== 'pages') {
					continue;
				}

				$listed = $section->__call('parent');

				if (
					$listed instanceof Site && $parent instanceof Site
					|| $listed instanceof Page && $parent instanceof Page && $listed->is($parent)
				) {
					$sections[] = $section;
				}
			}
		}

		return $sections;
	}
}
