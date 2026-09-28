<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Cms\PageRules;
use Kirby\Content\VersionCache;
use Kirby\Filesystem\Dir;
use tobimori\Agents\Content\Models;
use tobimori\Agents\Content\PageInfo;
use tobimori\Agents\Content\Writer;
use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\Scope;
use tobimori\Agents\OAuth\Secret;

final class PageDelete implements Tool
{
	private const CONFIRM_TTL = 600;

	public function name(): string
	{
		return 'page_delete';
	}

	public function definition(): array
	{
		return [
			'title' => 'Delete a page',
			'description' => implode("\n", [
				'Deletes a page with all its subpages, drafts, and files. This cannot be undone.',
				'It takes two calls. The first call deletes nothing: it shows what would be deleted and returns a `confirm` code. Show this to the user and ask. Only if the user agrees, call again with the `confirm` code within 10 minutes. If the user clearly asked to delete this page before, and the first call shows nothing unexpected (for example subpages they did not mention), that counts as agreement.',
				'The code is only valid for this page in its current state: if the page changes, ask again.',
			]),
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'page' => [
						'type' => 'string',
						'description' => 'Page id, for example `blog/my-post`',
					],
					'confirm' => [
						'type' => 'string',
						'description' => 'Code from the first call, after the user agreed',
					],
				],
				'required' => ['page'],
				'additionalProperties' => false,
			],
			'annotations' => [
				'readOnlyHint' => false,
				'destructiveHint' => true,
				'idempotentHint' => false,
				'openWorldHint' => false,
			],
		];
	}

	public function scope(): string
	{
		return Scope::PAGES_DELETE;
	}

	public function call(Arguments $arguments, Access $access): array|string
	{
		$page = Models::find((string) $arguments->string('page'));

		if (!$page instanceof Page) {
			throw new ToolError('The site cannot be deleted');
		}

		PageRules::delete($page, force: true);

		$confirm = $arguments->string('confirm');

		if ($confirm === null) {
			return self::preview($page, $access);
		}

		if (self::valid($confirm, $page, $access) === false) {
			throw new ToolError(
				'The `confirm` code is not valid: it expired, is for another page, or the page, a subpage, or a file changed since. Call page_delete without `confirm` and ask the user again.',
			);
		}

		self::ensureNotEdited($page);

		$id = $page->id();
		$page->delete(force: true);

		return "Deleted `{$id}`.";
	}

	private static function preview(Page $page, Access $access): array
	{
		$expires = time() + self::CONFIRM_TTL;
		$deletes = self::deletes($page);

		return [
			'page' => PageInfo::summary($page),
			'deletes' => $deletes,
			'confirm' => $expires . '.' . Secret::sign(self::state($page, $access, $expires)),
			'next' =>
				'Nothing was deleted. Ask the user: delete `'
					. $page->id()
					. '`'
					. ($deletes['subpages'] > 0 ? " and its {$deletes['subpages']} subpages" : '')
					. '? Only if they agree, call page_delete again with `confirm`.',
		];
	}

	private static function valid(string $confirm, Page $page, Access $access): bool
	{
		$parts = explode('.', $confirm, 2);
		$expires = (int) $parts[0];

		return (
			count($parts) === 2
			&& $expires >= time()
			&& hash_equals(Secret::sign(self::state($page, $access, $expires)), $parts[1])
		);
	}

	private static function state(Page $page, Access $access, int $expires): string
	{
		return implode('|', [
			'page_delete',
			$access->user->id(),
			$access->grant,
			$page->id(),
			self::fingerprint($page),
			$expires,
		]);
	}

	/**
	 * What the deletion removes: the folder of the page, as it is on disk now, and not as loaded models
	 * show it. The contents of all files, because times have only seconds and a replaced file can keep
	 * its size and inode. xxh128 is fast, but large media makes the check slower
	 */
	private static function fingerprint(Page $page): string
	{
		$root = (string) $page->root();
		$parts = [];

		foreach (is_dir($root) ? Dir::index($root, recursive: true) : [] as $path) {
			$file = $root . '/' . (string) $path;

			if (is_file($file)) {
				$parts[$path] = hash_file('xxh128', $file);
			}
		}

		return hash('sha256', (string) json_encode($parts));
	}

	private static function ensureNotEdited(Page $page): void
	{
		// content that Kirby read before can be old
		VersionCache::reset();

		foreach (self::models($page) as $model) {
			$lock = $model->version('changes')->lock('*');

			if ($lock->isLocked()) {
				throw new ToolError(
					'Nothing was deleted. `' . $model->id() . '`: ' . Writer::locked($lock->toArray())->getMessage(),
				);
			}
		}
	}

	/**
	 * @return list<Page|File>
	 */
	private static function models(Page $page): array
	{
		$models = [];

		foreach ([$page, ...$page->index(drafts: true)] as $item) {
			$models[] = $item;

			foreach ($item->files() as $file) {
				$models[] = $file;
			}
		}

		return $models;
	}

	/**
	 * @return array{subpages: int, files: int}
	 */
	private static function deletes(Page $page): array
	{
		$subpages = $page->index(drafts: true);
		$files = $page->files()->count();

		foreach ($subpages as $subpage) {
			$files += $subpage instanceof Page ? $subpage->files()->count() : 0;
		}

		return ['subpages' => $subpages->count(), 'files' => $files];
	}
}
