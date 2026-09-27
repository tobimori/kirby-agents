<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Content\Changes;
use tobimori\Agents\Agents;
use tobimori\Agents\Content\PageInfo;
use tobimori\Agents\Lifecycle\Placement;
use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\Scope;

final class SiteOverview implements Tool
{
	public function name(): string
	{
		return 'site_overview';
	}

	public function definition(): array
	{
		return [
			'title' => 'Site overview',
			'description' =>
				'Start here. Returns the site title, languages, the acting user and token scopes, the page `blueprints`, the pages with `unsavedChanges`, and the page tree, drafts included. '
					. PageInfo::FIELDS,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'depth' => [
						'type' => 'integer',
						'minimum' => 0,
						'maximum' => 3,
						'default' => 2,
						'description' => 'Levels of the page tree. 0 returns no pages',
					],
					'limit' => [
						'type' => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 25,
						'description' => 'Pages per parent. A `{"more": n}` item counts the pages left out, use pages_find for them',
					],
				],
				'additionalProperties' => false,
			],
			'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
		];
	}

	public function scope(): Scope
	{
		return Scope::ContentRead;
	}

	public function call(Arguments $arguments, Access $access): array
	{
		$kirby = App::instance();
		$depth = $arguments->int('depth', 2, 0, 3);
		$limit = $arguments->int('limit', 25, 1, 100);

		return [
			'site' => [
				'title' => Agents::siteTitle(),
				'url' => $kirby->site()->url(),
			],
			'languages' => self::languages(),
			'user' => [
				'email' => $access->user->email(),
				'role' => $access->user->role()->name(),
			],
			'scopes' => $access->scopes,
			'blueprints' => self::blueprints(),
			'unsavedChanges' => self::unsaved(),
			'pages' => $depth > 0 ? self::tree($kirby->site()->childrenAndDrafts(), $depth, $limit) : [],
		];
	}

	/**
	 * @return list<array{code: string, name: string, default: bool}>
	 */
	private static function languages(): array
	{
		$languages = [];

		foreach (App::instance()->languages() as $language) {
			$languages[] = [
				'code' => $language->code(),
				'name' => $language->name(),
				'default' => $language->isDefault(),
			];
		}

		return $languages;
	}

	/**
	 * @return list<array{name: string, title: string}>
	 */
	private static function blueprints(): array
	{
		$blueprints = [];

		foreach (App::instance()->blueprints('pages') as $name) {
			if (is_string($name)) {
				$blueprints[] = ['name' => $name, 'title' => Placement::title($name)];
			}
		}

		return $blueprints;
	}

	/**
	 * @return list<string>
	 */
	private static function unsaved(): array
	{
		$site = App::instance()->site();
		$ids = $site->version('changes')->exists('*') ? ['site'] : [];

		foreach ((new Changes())->pages() as $page) {
			if ($page instanceof Page && $page->isListable()) {
				$ids[] = $page->id();
			}
		}

		return $ids;
	}

	private static function tree(Pages $pages, int $depth, int $limit): array
	{
		$listable = $pages->filter(static fn(Page $page): bool => $page->isListable());
		$tree = [];

		foreach ($listable->limit($limit) as $page) {
			$item = PageInfo::summary($page);

			if ($depth > 1 && $item['children'] > 0) {
				$item['pages'] = self::tree($page->childrenAndDrafts(), $depth - 1, $limit);
			}

			$tree[] = $item;
		}

		if ($listable->count() > $limit) {
			$tree[] = ['more' => $listable->count() - $limit];
		}

		return $tree;
	}
}
