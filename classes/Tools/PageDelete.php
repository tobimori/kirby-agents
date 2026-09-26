<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use Kirby\Cms\Page;
use Kirby\Cms\PageRules;
use tobimori\Agents\Content\Models;
use tobimori\Agents\Content\PageInfo;
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

	public function scope(): Scope
	{
		return Scope::PagesDelete;
	}

	public function call(Arguments $arguments, Access $access): array|string
	{
		$page = Models::find((string) $arguments->string('page'));

		if (!$page instanceof Page) {
			throw new ToolError('The site cannot be deleted');
		}

		// check the permission first, so the user is not asked for something that is not possible
		PageRules::delete($page, force: true);

		$confirm = $arguments->string('confirm');

		if ($confirm === null) {
			return self::preview($page, $access);
		}

		if (self::valid($confirm, $page, $access) === false) {
			throw new ToolError(
				'The `confirm` code is not valid: it expired, is for another page, or the page changed. Call page_delete without `confirm` and ask the user again.',
			);
		}

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

	/**
	 * What the code is for: the connection, the page, and what would be deleted
	 */
	private static function state(Page $page, Access $access, int $expires): string
	{
		return implode('|', [
			'page_delete',
			$access->grant,
			$page->id(),
			(string) $page->modified(),
			json_encode(self::deletes($page)),
			$expires,
		]);
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
