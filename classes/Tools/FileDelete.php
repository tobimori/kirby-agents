<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use Kirby\Cms\File;
use tobimori\Agents\Content\Models;
use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\Scope;

final class FileDelete implements Tool
{
	public function name(): string
	{
		return 'file_delete';
	}

	public function definition(): array
	{
		return [
			'title' => 'Delete a file',
			'description' => implode("\n", [
				'Deletes a file with its content. This cannot be undone. Only delete files when the user asked for it, or files you uploaded yourself in this task by mistake.',
				'Fields that use the file keep their reference, which then shows nothing. Remove such references with content_update.',
			]),
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'file' => [
						'type' => 'string',
						'description' => 'File id, for example `blog/my-post/photo.jpg`, or its UUID',
					],
				],
				'required' => ['file'],
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
		return Scope::FILES_DELETE;
	}

	public function call(Arguments $arguments, Access $access): string
	{
		$id = (string) $arguments->string('file');
		$file = Models::content($id);

		if (!$file instanceof File) {
			throw new ToolError("`{$id}` is not a file. To delete pages, use page_delete.");
		}

		$fileId = $file->id();

		$file->delete();

		return "Deleted `{$fileId}`.";
	}
}
