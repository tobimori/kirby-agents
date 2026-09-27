<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use tobimori\Agents\Content\Models;
use tobimori\Agents\Content\Presenter;
use tobimori\Agents\Content\Reader;
use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\Scope;

final class ContentGet implements Tool
{
	public function name(): string
	{
		return 'content_get';
	}

	public function definition(): array
	{
		return [
			'title' => 'Read page content',
			'description' => implode("\n", [
				'Reads the content of a page, the site, or a file (its metadata, like `alt`).',
				'Without `fields` and `ref`, returns an outline: each field with a short preview in plain text, without HTML. Items in blocks, layouts, structures, and entries have a ref number, and nested items are indented under the name of their field.',
				'With `fields` or `ref`, returns full values as JSON. Nested items carry their `ref` number.',
				'Ref numbers and the `etag` belong to this version of the content. They change when the content changes, so read again after a change.',
				'If another user made the unsaved changes, the read names them (`editor`). Their edits are part of these changes: if you publish, you publish their edits too, so tell the user first.',
			]),
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'page' => [
						'type' => 'string',
						'description' => Models::CONTENT_ID,
					],
					'fields' => [
						'type' => 'array',
						'items' => ['type' => 'string'],
						'description' => 'Field names to return with full values',
					],
					'ref' => [
						'type' => 'integer',
						'minimum' => 1,
						'description' => 'Ref number from the outline, to return one item with full values',
					],
					'ids' => [
						'type' => 'boolean',
						'default' => false,
						'description' => 'Also return the UUIDs of blocks, layout rows, and columns',
					],
					'version' => [
						'type' => 'string',
						'enum' => ['latest', 'changes'],
						'description' => '`changes` are unsaved changes, `latest` the saved content. Without it, `changes` if they exist',
					],
					'language' => [
						'type' => 'string',
						'description' => 'Language code on multi-language sites. Without it, the default language',
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

	public function call(Arguments $arguments, Access $access): array|string
	{
		$content = Reader::read(
			Models::content((string) $arguments->string('page')),
			$arguments->string('version'),
			$arguments->string('language'),
		);

		$ids = $arguments->bool('ids', false);
		$ref = $arguments->int('ref', 0, 0, PHP_INT_MAX);

		if ($ref > 0) {
			return Presenter::node($content, $ref, $ids);
		}

		$fields = $arguments->strings('fields');

		if ($fields !== []) {
			return Presenter::fields($content, $fields, $ids);
		}

		return Presenter::outline($content);
	}
}
