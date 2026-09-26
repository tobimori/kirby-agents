<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use Kirby\Cms\App;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use tobimori\Agents\Content\Models;
use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\Scope;
use tobimori\Agents\Schema\Compiler;

final class SchemaGet implements Tool
{
	public function name(): string
	{
		return 'schema_get';
	}

	public function definition(): array
	{
		return [
			'title' => 'Get the content schema',
			'description' => implode("\n", [
				'Returns the fields of a page, a page blueprint, the site, or a file, in a compact notation. Read it before you change content.',
				'The first block lists the fields: name, then type and rules. Nested content refers to named types, which follow as their own blocks. Kinds of named types: `block` (a block type), `row` (a structure row), `object` (an object field), `settings` (the settings of the rows in a layout field).',
				'Values:',
				'- text, url, email, slug, tel, textarea, markdown: string',
				'- html: HTML string with only the listed tags. `inline html` has no <p>',
				'- boolean, number: JSON boolean and number',
				'- `one of`: one of the listed values. `list of`: an array of them',
				'- date and time: string in the given format',
				'- files, pages, users: UUIDs like `file://…`, `page://…`, `user://…`. content_get returns them as `{uuid, id, title}`',
				'- blocks, structure, layout: read them item by item with content_get and its ref numbers',
				'- `only if a = "b"`: the field is used only when the condition is true. `read-only`: do not change',
				'- `custom field`: a field type from a plugin, the notation lists what is known about it',
			]),
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'page' => [
						'type' => 'string',
						'description' => Models::CONTENT_ID,
					],
					'blueprint' => [
						'type' => 'string',
						'description' => 'Page blueprint name, for example `post`, to see the fields of a new page',
					],
					'focus' => [
						'type' => 'string',
						'description' => 'Return only one named type, for example `block columns`. The short name `columns` works if only one kind has that name',
					],
				],
				'additionalProperties' => false,
			],
			'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
		];
	}

	public function scope(): Scope
	{
		return Scope::ContentRead;
	}

	public function call(Arguments $arguments, Access $access): string
	{
		$schema = Compiler::compile(self::model($arguments->string('page'), $arguments->string('blueprint')));
		$focus = $arguments->string('focus');
		$text = $schema->render($focus);

		if ($text === null) {
			$types = array_keys($schema->types);

			throw new ToolError("No type `{$focus}`. Types: " . ($types === [] ? 'none' : implode(', ', $types)));
		}

		return $text;
	}

	private static function model(?string $id, ?string $blueprint): ModelWithContent
	{
		$kirby = App::instance();

		if ($id !== null) {
			return Models::content($id);
		}

		if ($blueprint !== null) {
			$names = array_filter($kirby->blueprints('pages'), is_string(...));

			if (!in_array($blueprint, $names, true)) {
				throw new ToolError("No page blueprint `{$blueprint}`. Blueprints: " . implode(', ', $names));
			}

			// a page that exists only in memory, to build the form of a new page
			return new Page(['slug' => 'new-page', 'template' => $blueprint]);
		}

		throw new ToolError('Send `page` or `blueprint`');
	}
}
