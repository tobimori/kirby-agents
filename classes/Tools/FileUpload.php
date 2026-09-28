<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use Kirby\Filesystem\F;
use tobimori\Agents\Content\Models;
use tobimori\Agents\Http\UploadEndpoint;
use tobimori\Agents\Lifecycle\Uploads;
use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\Scope;

final class FileUpload implements Tool
{
	public function name(): string
	{
		return 'file_upload';
	}

	public function definition(): array
	{
		return [
			'title' => 'Upload a file',
			'description' => implode("\n", [
				'Returns a link to upload one file to a page or the site. The file does not go through this tool: send it with the shell command from the result. The link is valid for 10 minutes, for this filename and template.',
				'Read `upload` in page_rules first: it lists the file templates you can upload and what they accept. Read the fields of the template with schema_get (`blueprint: "files/<template>"`) and send them in `content`, required fields included: an uploaded file is public at once, there is no review step. Kirby checks the file type, size, and contents when it receives the file.',
				'The upload answers with JSON: the file with its `id` and `uuid`, or an `error`. Use the `uuid` in files fields. A file has its own content and unsaved changes, separate from its page. To remove a file again, use file_delete.',
			]),
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'page' => [
						'type' => 'string',
						'description' => 'Page id, for example `blog/my-post`, or `site`',
					],
					'filename' => [
						'type' => 'string',
						'description' => 'Name of the new file, for example `team-photo.jpg`. Kirby makes it safe for URLs',
					],
					'template' => [
						'type' => 'string',
						'description' => 'A template from `upload` in page_rules. Not needed if there is only one',
					],
					'content' => [
						'type' => 'object',
						'description' => 'Values for the fields of the file template, like `alt`, in the formats of schema_get',
					],
				],
				'required' => ['page', 'filename'],
				'additionalProperties' => false,
			],
			'annotations' => [
				'readOnlyHint' => false,
				'destructiveHint' => false,
				'idempotentHint' => true,
				'openWorldHint' => false,
			],
		];
	}

	public function scope(): Scope
	{
		return Scope::FilesManage;
	}

	public function call(Arguments $arguments, Access $access): array
	{
		$parent = Models::find((string) $arguments->string('page'));
		$templates = Uploads::templates($parent);

		if ($templates === []) {
			throw new ToolError(
				'No files can be uploaded here. The blueprint has no files section or field that uploads to it, or your role may not upload.',
			);
		}

		$template = $arguments->string('template') ?? (count($templates) === 1 ? array_key_first($templates) : null);

		if ($template === null || !array_key_exists($template, $templates)) {
			throw new ToolError('Send a `template`. Allowed: ' . implode(', ', array_keys($templates)) . '.');
		}

		$filename = F::safeName((string) $arguments->string('filename'));

		if ($filename === '' || F::extension($filename) === '') {
			throw new ToolError('The filename needs a name and an extension, for example `photo.jpg`');
		}

		if ($parent->file($filename) !== null) {
			throw new ToolError("A file `{$filename}` exists already. Choose another filename.");
		}

		$content = Uploads::content(Uploads::draft($parent, $template, $filename), $arguments->object('content'));

		if (strlen((string) json_encode($content)) > UploadEndpoint::MAX_CONTENT) {
			throw new ToolError(
				'`content` is too long for the link. Send the long fields later with content_update on the file.',
			);
		}

		$link = UploadEndpoint::link($access, $parent, $template, $filename, $content);

		return [
			'url' => $link['url'],
			'method' => 'POST, multipart form data, field `file`',
			'command' => 'curl -sS -F "file=@<path to the file>" "' . $link['url'] . '"',
			'expires' => date('c', $link['expires']),
			'filename' => $filename,
			'template' => $template,
			'accept' => $templates[$template]['accept'],
		];
	}
}
