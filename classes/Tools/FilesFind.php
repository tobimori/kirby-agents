<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use Kirby\Cms\File;
use tobimori\Agents\Content\FileInfo;
use tobimori\Agents\Content\Models;
use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\Scope;

final class FilesFind implements Tool
{
	public function name(): string
	{
		return 'files_find';
	}

	public function definition(): array
	{
		return [
			'title' => 'Find files',
			'description' => 'Lists the files of a page or the site, in their sort order. ' . FileInfo::FIELDS,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'page' => [
						'type' => 'string',
						'description' => 'Page id, for example `blog/my-post`, or `site`',
					],
					'template' => [
						'type' => ['string', 'array'],
						'items' => ['type' => 'string'],
						'description' => 'Only files with this file template, or one of these',
					],
					'type' => [
						'type' => 'string',
						'description' => 'Only files of this type, for example `image` or `document`',
					],
					'query' => [
						'type' => 'string',
						'description' => 'Words to search for in the filename and the file content, like `alt`',
					],
					'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
					'cursor' => ['type' => 'string', 'description' => '`nextCursor` from the previous result'],
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
		$files = Models::find((string) $arguments->string('page'))
			->files()
			->sorted();
		$templates = $arguments->strings('template');
		$type = $arguments->string('type');
		$query = $arguments->string('query');

		if ($templates !== []) {
			$files = $files->filter(static fn(File $file): bool => in_array($file->template(), $templates, true));
		}

		if ($type !== null) {
			$files = $files->filter(static fn(File $file): bool => $file->type() === $type);
		}

		if ($query !== null && trim($query) !== '') {
			$files = $files->search($query);
		}

		$files = $files->filter(static fn(File $file): bool => $file->isListable());
		$limit = $arguments->int('limit', 20, 1, 100);
		$offset = $arguments->offset();
		$total = $files->count();

		return [
			'total' => $total,
			'files' => array_values(array_map(FileInfo::summary(...), $files->slice($offset, $limit)->values())),
			'nextCursor' => Arguments::nextCursor($offset, $limit, $total),
		];
	}
}
