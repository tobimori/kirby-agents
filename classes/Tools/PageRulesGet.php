<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Toolkit\I18n;
use tobimori\Agents\Agents;
use tobimori\Agents\Content\Models;
use tobimori\Agents\Content\PageInfo;
use tobimori\Agents\Lifecycle\Creator;
use tobimori\Agents\Lifecycle\Placement;
use tobimori\Agents\Lifecycle\Uploads;
use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\Scope;

final class PageRulesGet implements Tool
{
	private const MAX_TARGETS = 30;

	private const ACTIONS = [
		'changeSlug',
		'changeStatus',
		'changeTemplate',
		'changeTitle',
		'create',
		'delete',
		'duplicate',
		'move',
		'sort',
		'update',
	];

	public function name(): string
	{
		return 'page_rules';
	}

	public function definition(): array
	{
		return [
			'title' => 'Page rules',
			'description' => implode("\n", [
				'Returns what the blueprints and the role of the user allow for a page, or for new pages in it. Read it before page_create or page_update.',
				'- `create`: templates for new pages in this page (or `site`), with their create options: the `status` new pages get, how `title` and `slug` are set, the `fields` you can set when you create the page, and `sort` (how the new pages are sorted). No `create` means no new pages here',
				'- `upload`: file templates you can upload with file_upload, with their `accept` rules (mime types, extensions, `maxsize` in bytes) and where the Panel offers them (`from`). No `upload` means no uploads here',
				'- For pages also: `allowed` actions of the role, `statuses`, `sort` (how this page is sorted among its siblings), `templates` it can change to, and `moveTo` (where it can move)',
			]),
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'page' => [
						'type' => 'string',
						'description' => 'Page id, for example `blog/my-post`, or `site`',
					],
				],
				'required' => ['page'],
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
		$model = Models::find((string) $arguments->string('page'));

		if (!$model instanceof Page) {
			return [
				'page' => ['id' => 'site', 'title' => Agents::siteTitle()],
				'create' => self::create($model),
				'upload' => Uploads::templates($model),
			];
		}

		$permissions = $model->permissions()->toArray();
		$allowed = array_keys(array_filter(
			array_intersect_key($permissions, array_flip(self::ACTIONS)),
			static fn(mixed $value): bool => $value === true,
		));
		$rules = [
			'page' => PageInfo::summary($model),
			'create' => self::create($model),
			'upload' => Uploads::templates($model),
			'allowed' => array_values(array_map(strval(...), $allowed)),
			'statuses' => self::statuses($model),
			'sort' => Placement::sorting($model),
			'templates' => self::templates($model),
		];

		if (in_array('move', $allowed, true)) {
			$targets = Placement::moveTargets($model, self::MAX_TARGETS);
			$rules['moveTo'] = $targets['targets'];

			if ($targets['complete'] === false) {
				$rules['moveToNote'] = 'Not all targets are listed. Try page_update with `parent`, it checks the target.';
			}
		}

		return $rules;
	}

	/**
	 * @return array<string, array<array-key, mixed>>
	 */
	private static function create(Site|Page $parent): array
	{
		$create = [];

		foreach (Placement::templates($parent) as $template => $title) {
			$create[$template] = ['label' => $title, ...(new Creator($parent, $template))->describe()];
		}

		return $create;
	}

	/**
	 * Templates the page can change to
	 *
	 * @return list<string>
	 */
	private static function templates(Page $page): array
	{
		$templates = [];

		foreach ($page->blueprints() as $blueprint) {
			$name = is_array($blueprint) ? $blueprint['name'] ?? null : null;

			if (is_string($name) && $name !== $page->intendedTemplate()->name()) {
				$templates[] = $name;
			}
		}

		return $templates;
	}

	/**
	 * @return array<string, string> label by status
	 */
	private static function statuses(Page $page): array
	{
		$statuses = [];

		foreach ($page->blueprint()->status() as $status => $props) {
			$label = is_array($props) ? $props['label'] ?? $status : $status;
			$label = is_array($label) ? I18n::translate($label) : $label;
			$statuses[(string) $status] = is_string($label) ? $label : (string) $status;
		}

		return $statuses;
	}
}
