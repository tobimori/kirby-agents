<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use Kirby\Cms\Language;
use Kirby\Cms\Page;
use Kirby\Cms\PageRules;
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
				'Send only what changes. All changes are checked first: if one is not allowed, nothing changes. These changes are live at once, there is no review step.',
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

		if ($title !== null) {
			PageRules::changeTitle($page, $title);
		}

		if ($slug !== null && $language->isDefault()) {
			PageRules::changeSlug($page, $slug);
		}

		if ($template !== null && $template !== $page->intendedTemplate()->name()) {
			PageRules::changeTemplate($page, $template);
		}

		if ($parent !== null) {
			PageRules::move($page, $parent);
		}

		if ($position !== null && $page->blueprint()->num() !== 'default') {
			throw new ToolError(
				'The position of this page comes from its blueprint (`num: '
				. $page->blueprint()->num()
				. '`), so it cannot be set.',
			);
		}

		if ($listed !== null) {
			PageRules::changeStatus($page, $listed, $position ?? 0);
		}

		$before = self::state($page, $language);

		// template first: the new blueprint can have other rules for the rest
		if ($template !== null) {
			$page = $page->changeTemplate($template);
		}

		if ($title !== null) {
			$page = $page->changeTitle($title, $language->code());
		}

		if ($slug !== null) {
			$page = $page->changeSlug($slug, $language->code());
		}

		if ($parent !== null) {
			$page = $page->move($parent);
		}

		if ($listed !== null) {
			$page = $page->changeStatus($listed, $position);
		}

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
