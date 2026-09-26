<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\FilePicker;
use Kirby\Cms\Page;
use Kirby\Cms\PagePicker;
use Kirby\Cms\User;
use Kirby\Cms\UserPicker;
use Kirby\Uuid\FileUuid;
use Kirby\Uuid\PageUuid;
use tobimori\Agents\Content\FieldPath;
use tobimori\Agents\Content\Models;
use tobimori\Agents\Content\Reader;
use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\Scope;

final class RelationsFind implements Tool
{
	public function name(): string
	{
		return 'relations_find';
	}

	public function definition(): array
	{
		return [
			'title' => 'Find values for a pages, files, or users field',
			'description' => implode("\n", [
				'Lists the pages, files, or users that a relation field accepts, with the query of the field applied, like the picker in the Panel. Use the `uuid` (or the `id` if the uuid is null) in the value list with content_update. Pages fields without a query accept all pages, drafts included.',
				'`field` is a path with the names from schema_get: `cover`, `links > page` (structure or object, then its field), `text > image > image` (blocks field, block type, field), `layout > settings > image` (layout row settings). It works for blocks that do not exist yet.',
			]),
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'page' => [
						'type' => 'string',
						'description' => Models::CONTENT_ID,
					],
					'field' => [
						'type' => 'string',
						'description' => 'Path of a pages, files, or users field, for example `text > image > image`',
					],
					'search' => [
						'type' => 'string',
						'description' => 'Words to search for. Pages fields without a query search the whole site',
					],
					'parent' => [
						'type' => 'string',
						'description' => 'Pages fields without a query: list the children and drafts of this page id. Without it, the top-level pages',
					],
					'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20],
					'pageNumber' => [
						'type' => 'integer',
						'minimum' => 1,
						'default' => 1,
						'description' => 'Page of the result list, for more than `limit` items',
					],
				],
				'required' => ['page', 'field'],
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
		$model = Models::content((string) $arguments->string('page'));
		$path = (string) $arguments->string('field');
		$props = FieldPath::resolve(Reader::read($model)->fields, $path);
		$type = is_string($props['type'] ?? null) ? $props['type'] : '';
		$query = is_string($props['query'] ?? null) && $props['query'] !== '' ? $props['query'] : null;
		$search = $arguments->string('search');
		$parent = $arguments->string('parent');
		$limit = $arguments->int('limit', 20, 1, 50);
		$number = $arguments->int('pageNumber', 1, 1, 10000);
		$options = ['model' => $model, 'query' => $query, 'search' => $search, 'limit' => $limit, 'page' => $number];

		$result = match (true) {
			// the Panel picker lists no drafts and searches one level, but all pages are valid values
			$type === 'pages' && $query === null => self::pages($parent, $search, $limit, $number),
			$type === 'pages' => (new PagePicker([...$options, 'map' => self::page(...)]))->toArray(),
			$type === 'files' => (new FilePicker([...$options, 'map' => self::file(...)]))->toArray(),
			$type === 'users' => (new UserPicker([...$options, 'map' => self::user(...)]))->toArray(),
			default => throw new ToolError("`{$path}` is a {$type} field, not a pages, files, or users field"),
		};

		$max = $props['max'] ?? null;

		return [
			'field' => $path,
			'type' => $type,
			'query' => $query,
			'max' => match (true) {
				is_int($max) => $max,
				($props['multiple'] ?? true) === false => 1,
				default => null,
			},
			'total' => $result['pagination']['total'] ?? 0,
			'items' => $result['data'],
		];
	}

	/**
	 * Children and drafts of the parent, or with a search, all pages of the site
	 *
	 * @return array{data: list<array<string, mixed>>, pagination: array{total: int}}
	 */
	private static function pages(?string $parent, ?string $search, int $limit, int $page): array
	{
		$pages = match (true) {
			$search !== null && $search !== '' => App::instance()->site()->index(drafts: true)->search($search),
			$parent !== null => Models::find($parent)->childrenAndDrafts(),
			default => App::instance()->site()->childrenAndDrafts(),
		};
		$pages = $pages->filter('isListable', true);
		$data = [];

		foreach ($pages->paginate(['limit' => $limit, 'page' => $page]) as $item) {
			$data[] = self::page($item);
		}

		return ['data' => $data, 'pagination' => ['total' => $pages->count()]];
	}

	/**
	 * `retrieveId()` reads the stored UUID, `uuid()` would write a missing one
	 *
	 * @return array<string, mixed>
	 */
	private static function page(Page $page): array
	{
		$uuid = PageUuid::retrieveId($page);

		return [
			'uuid' => $uuid !== null ? 'page://' . $uuid : null,
			'id' => $page->id(),
			'title' => (string) $page->title()->value(),
			'template' => $page->intendedTemplate()->name(),
			'status' => $page->status(),
			'children' => $page->hasChildren(),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function file(File $file): array
	{
		$uuid = FileUuid::retrieveId($file);

		return [
			'uuid' => $uuid !== null ? 'file://' . $uuid : null,
			'id' => $file->id(),
			'type' => $file->type(),
			'alt' => $file->content()->toArray()['alt'] ?? null,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function user(User $user): array
	{
		return [
			'uuid' => 'user://' . $user->id(),
			'email' => $user->email(),
			'name' => $user->name()->value(),
			'role' => $user->role()->name(),
		];
	}
}
