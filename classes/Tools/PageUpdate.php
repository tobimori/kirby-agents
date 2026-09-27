<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use Closure;
use Kirby\Cms\Language;
use Kirby\Cms\Page;
use Kirby\Cms\PageRules;
use Kirby\Exception\Exception as KirbyException;
use Kirby\Toolkit\Str;
use tobimori\Agents\Content\Models;
use tobimori\Agents\Content\PageInfo;
use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\Scope;

final class PageUpdate implements Tool
{
	public function name(): string
	{
		return 'page_update';
	}

	public function definition(): array
	{
		return [
			'title' => 'Change page title, URL, status, position, template, or parent',
			'description' => implode("\n", [
				'Changes the page itself, like the settings of a page in the Panel. For the content of fields, use content_update. Read page_rules first: it lists the allowed actions, statuses, templates, and move targets.',
				'Send only what changes. The changes are checked first, and then made in this order: template, title, slug, parent, status. Some checks depend on earlier changes, for example the rules of a new template: these are checked when the change is made. If a change fails, the earlier ones stay, and the error lists them. These changes are live at once, there is no review step.',
				'- `title`: the page title',
				'- `slug`: the URL part. Other pages and links that use the old URL do not change',
				'- `template`: one of `templates` from page_rules. Content of fields that the new template does not have is removed',
				'- `parent`: a page id from `moveTo` of page_rules, or `site`',
				'- `status`: `draft`, `unlisted`, or `listed`. This is how a page becomes public: `listed` pages show in menus and lists, `unlisted` pages only by URL. Needs the `content:publish` scope. Only the published content goes live: unsaved changes need changes_publish',
				'- `position`: position among the listed siblings, counted from 1. For top-level pages, this is usually the order in the main menu. Only when `sort` in page_rules says that you can set it',
			]),
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'page' => [
						'type' => 'string',
						'description' => 'Page id, for example `blog/my-post`',
					],
					'title' => ['type' => 'string'],
					'slug' => ['type' => 'string'],
					'template' => ['type' => 'string'],
					'parent' => ['type' => 'string'],
					'status' => ['type' => 'string', 'enum' => ['draft', 'unlisted', 'listed']],
					'position' => ['type' => 'integer', 'minimum' => 1],
					'language' => [
						'type' => 'string',
						'description' => 'Language of `title` and `slug` on multi-language sites. Without it, the default language',
					],
				],
				'required' => ['page'],
				'additionalProperties' => false,
			],
			'annotations' => [
				'readOnlyHint' => false,
				'destructiveHint' => true,
				'idempotentHint' => true,
				'openWorldHint' => false,
			],
		];
	}

	public function scope(): Scope
	{
		return Scope::PagesManage;
	}

	public function call(Arguments $arguments, Access $access): array
	{
		$page = Models::find((string) $arguments->string('page'));

		if (!$page instanceof Page) {
			throw new ToolError('Use this tool for pages. The site title is a field: change it with content_update.');
		}

		$title = $arguments->string('title');
		$slug = $arguments->string('slug');
		$template = $arguments->string('template');
		$parentId = $arguments->string('parent');
		$status = $arguments->has('status')
			? $arguments->enum('status', ['draft', 'unlisted', 'listed'], 'draft')
			: null;
		$position = $arguments->has('position') ? $arguments->int('position', 1, 1, PHP_INT_MAX) : null;
		$language = Language::ensure($arguments->string('language') ?? 'default');

		if (
			($status !== null || $position !== null && !$page->isListed())
			&& $access->allows(Scope::ContentPublish) === false
		) {
			throw new ScopeRequired(Scope::ContentPublish);
		}

		if ($slug !== null) {
			$rules = Str::$language;
			Str::$language = $language->rules();
			$slug = Str::slug($slug);
			Str::$language = $rules;
		}

		$parent = $parentId !== null ? Models::find($parentId) : null;
		$listed = $status ?? ($position !== null ? 'listed' : null);

		$newTemplate = $template !== null && $template !== $page->intendedTemplate()->name();
		$renames = $slug !== null && $language->isDefault();

		if ($newTemplate) {
			PageRules::changeTemplate($page, $template);
		}

		// checks with the page as it is now. After a template change, other rules can apply:
		// then Kirby checks each change when it makes it
		$check = !$newTemplate;

		if ($check && $title !== null) {
			PageRules::changeTitle($page, $title);
		}

		if ($check && $renames) {
			PageRules::changeSlug($page, $slug);
		}

		// the move checks the slug: after a rename, Kirby checks it with the new one
		if ($check && $parent !== null && !$renames) {
			PageRules::move($page, $parent);
		}

		if ($check && $position !== null) {
			self::ensurePosition($page);
		}

		if ($check && $listed !== null) {
			PageRules::changeStatus($page, $listed, $position ?? 0);
		}

		$before = self::state($page, $language);

		// template first: the new blueprint can have other rules for the rest
		$actions = [];

		if ($template !== null) {
			$actions['template'] = static fn(Page $page): Page => $page->changeTemplate($template);
		}

		if ($title !== null) {
			$actions['title'] = static fn(Page $page): Page => $page->changeTitle($title, $language->code());
		}

		if ($slug !== null) {
			$actions['slug'] = static fn(Page $page): Page => $page->changeSlug($slug, $language->code());
		}

		if ($parent !== null) {
			$actions['parent'] = static fn(Page $page): Page => $page->move($parent);
		}

		if ($listed !== null) {
			$actions['status'] = static function (Page $page) use ($listed, $position): Page {
				if ($position !== null) {
					self::ensurePosition($page);
				}

				return $page->changeStatus($listed, $position);
			};
		}

		$page = self::apply($page, $actions);

		$after = self::state($page, $language);
		$changed = array_keys(array_diff_assoc($after, $before));
		$summary = PageInfo::summary($page);
		$result = ['changed' => $changed, 'page' => $summary];

		if (!$language->isDefault()) {
			$result[$language->code()] = ['title' => $after['title'], 'slug' => $after['slug']];
		}

		if ($listed !== null && $listed !== 'draft' && $summary['changes']) {
			$result['note'] = 'The page has unsaved changes. They are not public yet: publish them with changes_publish.';
		}

		return $result;
	}

	/**
	 * The checks above use the page before the changes, so a later change can still fail
	 *
	 * @param array<string, Closure(Page): Page> $actions
	 */
	private static function apply(Page $page, array $actions): Page
	{
		$done = [];

		foreach ($actions as $name => $action) {
			try {
				$page = $action($page);
			} catch (KirbyException|ToolError $error) {
				if ($done === []) {
					throw $error;
				}

				$open = array_slice(array_keys($actions), count($done));

				throw new ToolError(
					"Changing `{$name}` failed: {$error->getMessage()}\n"
					. 'Already changed: '
					. implode(', ', $done)
					. '. Not changed: '
					. implode(', ', $open)
					. ".\n"
					. "The page is now `{$page->id()}`. Read page_rules again before you try the rest.",
				);
			}

			$done[] = $name;
		}

		return $page;
	}

	private static function ensurePosition(Page $page): void
	{
		if ($page->blueprint()->num() !== 'default') {
			throw new ToolError(
				'The position of this page comes from its blueprint (`num: '
				. $page->blueprint()->num()
				. '`), so it cannot be set.',
			);
		}
	}

	/**
	 * @return array<string, string|int|null>
	 */
	private static function state(Page $page, Language $language): array
	{
		$title = $page->content($language->code())->toArray()['title'] ?? '';

		return [
			'id' => $page->id(),
			'title' => is_string($title) ? $title : '',
			'slug' => $page->slug($language->code()),
			'template' => $page->intendedTemplate()->name(),
			'status' => $page->status(),
			'num' => $page->num(),
		];
	}
}
