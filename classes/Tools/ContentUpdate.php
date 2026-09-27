<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use Kirby\Cms\Page;
use tobimori\Agents\Content\Models;
use tobimori\Agents\Content\Presenter;
use tobimori\Agents\Content\Reader;
use tobimori\Agents\Content\Writer;
use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\Scope;

final class ContentUpdate implements Tool
{
	private const MAX_OPS = 100;

	public function name(): string
	{
		return 'content_update';
	}

	public function definition(): array
	{
		return [
			'title' => 'Change page content',
			'description' => implode("\n", [
				'Changes the content of a page, the site, or a file (its metadata) with a list of operations. Read the page with content_get first, and send its `etag`.',
				'All operations are applied together: if one is invalid, nothing is saved.',
				'Ref numbers come from that read. All refs in one call point to that read, also after earlier operations in the same call. New items can get a name with `as`, which later operations in the same call can use instead of a number.',
				'Operations:',
				'- `{"op": "set", "field": "subtitle", "value": "New"}` sets a top-level field',
				'- `{"op": "set", "ref": 5, "field": "text", "value": "<p>New</p>"}` sets a field of a block, a structure row, or the settings of a layout row',
				'- `{"op": "replace", "ref": 5, "field": "text", "old": "<p>Old sentence.</p>", "new": "<p>New sentence.</p>"}` replaces text in a field, so that you do not need to send a long text again. Without `ref`, in a top-level field. `old` must be in the field exactly once, as content_get shows it, with its HTML tags. Operations run in order, so a later replace sees the earlier ones',
				'- `{"op": "insert", "into": "text", "type": "heading", "content": {"text": "Hi"}, "as": "a"}` adds a block at the end of a top-level field',
				'- `{"op": "insert", "after": 5, "type": "text", "content": {…}}` adds a block after item 5. `before` works the same way',
				'- `{"op": "insert", "into": 3, "slot": "left", "type": "text", "content": {…}}` adds a block to the nested field `left` of block 3',
				'- `{"op": "insert", "into": "links", "content": {"label": "Docs", "url": "https://…"}}` adds a structure row (no `type`)',
				'- `{"op": "insert", "into": "layout", "columns": ["1/2", "1/2"], "content": {"background": "dark"}, "as": "r"}` adds a layout row. `content` is its settings',
				'- `{"op": "insert", "into": "r", "column": 1, "type": "text", "content": {…}}` adds a block to column 1 (counted from 1) of a layout row. `into` a column ref works too',
				'- `{"op": "move", "ref": 1, "after": 5}` moves an item. `before` and `into` work as for insert',
				'- `{"op": "remove", "ref": 6}` removes an item with everything in it',
				'Values use the formats from schema_get. For files, pages, and users, send UUIDs like `page://…`. Missing fields in `content` stay empty.',
				'By default the changes are saved as unsaved changes (`changes` version), which an editor sees and publishes in the Panel. The result lists the refs of all new items and the new `etag` and outline.',
			]),
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'page' => [
						'type' => 'string',
						'description' => Models::CONTENT_ID,
					],
					'etag' => [
						'type' => 'string',
						'description' => '`etag` from the content_get read that the refs come from',
					],
					'ops' => [
						'type' => 'array',
						'minItems' => 1,
						'maxItems' => self::MAX_OPS,
						'items' => [
							'type' => 'object',
							'properties' => [
								'op' => ['type' => 'string', 'enum' => ['set', 'replace', 'insert', 'move', 'remove']],
								// string first: some clients use only the first type
								'ref' => ['type' => ['string', 'integer']],
								'field' => ['type' => 'string'],
								// no type: strict clients drop tools with an empty schema
								'value' => ['description' => 'New value in the format of the field in schema_get'],
								'old' => ['type' => 'string'],
								'new' => ['type' => 'string'],
								'type' => ['type' => 'string'],
								'content' => ['type' => 'object'],
								'as' => ['type' => 'string'],
								'after' => ['type' => ['string', 'integer']],
								'before' => ['type' => ['string', 'integer']],
								'into' => ['type' => ['string', 'integer']],
								'slot' => ['type' => 'string'],
								'column' => ['type' => 'integer', 'minimum' => 1],
								'columns' => ['type' => 'array', 'items' => ['type' => 'string']],
							],
							'required' => ['op'],
						],
						'description' => 'Operations, applied in order',
					],
					'version' => [
						'type' => 'string',
						'enum' => ['changes', 'latest'],
						'default' => 'changes',
						'description' => '`changes` saves for review in the Panel, `latest` also publishes. For pages that are not drafts, `latest` needs the `content:publish` scope. Without it, the server asks the client to authorize again with that scope (HTTP 403 `insufficient_scope`), so ask the user before you try',
					],
					'language' => [
						'type' => 'string',
						'description' => 'Language code on multi-language sites. Without it, the default language',
					],
					'dryRun' => [
						'type' => 'boolean',
						'default' => false,
						'description' => 'Check the operations and show the result without saving',
					],
				],
				'required' => ['page', 'etag', 'ops'],
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
		return Scope::ContentWrite;
	}

	public function call(Arguments $arguments, Access $access): string
	{
		$version = $arguments->enum('version', ['changes', 'latest'], 'changes');
		$model = Models::content((string) $arguments->string('page'));

		// drafts are not public
		$publishes = $version === 'latest' && !($model instanceof Page && $model->isDraft());

		if ($publishes && $access->allows(Scope::ContentPublish) === false) {
			throw new ScopeRequired(Scope::ContentPublish);
		}
		$language = $arguments->string('language');
		$dryRun = $arguments->bool('dryRun', false);
		$outcome = Writer::write(
			$model,
			$language,
			(string) $arguments->string('etag'),
			$arguments->list('ops', self::MAX_OPS),
			$version === 'latest',
			$dryRun,
		);
		$base = $outcome['base'];
		$result = $outcome['edit'];

		if ($outcome['errors'] !== []) {
			throw new ToolError("Nothing was saved. Invalid values:\n" . self::messages($outcome['errors']));
		}

		$lines = [];

		if ($dryRun) {
			$lines[] = 'Dry run: the operations are valid. Nothing was saved. The content would be:';
			$after = $base->withValues($outcome['values']);
		} else {
			// the old model object keeps the old state
			$after = Reader::read(Models::content((string) $arguments->string('page')), null, $language);
			$lines[] = match (true) {
				$publishes => 'Saved and published.',
				$version === 'latest' => 'Saved. The page is still a draft.',
				default => self::savedText($after),
			};
		}

		$lines[] = 'Changed fields: ' . implode(', ', $result['changed']) . '.';

		$created = self::createdRefs($after, $result['created']);

		if ($created !== []) {
			$lines[] = 'New items: ' . implode(', ', $created) . '.';
		}

		if ($outcome['others'] !== null) {
			$lines[] =
				"The unsaved changes also had edits by {$outcome['others']['editor']} in: " . implode(
					', ',
					$outcome['others']['fields'],
				) . '. ' . match (true) {
					$version === 'latest' && $dryRun => 'They would be published too.',
					$version === 'latest' => 'They are published now too.',
					default => 'They stay in the unsaved changes.',
				};
		}

		if ($outcome['warnings'] !== []) {
			$lines[] = "Other fields with invalid values, not changed by you:\n" . self::messages($outcome['warnings']);
		}

		return implode("\n", $lines) . "\n\n" . Presenter::outline($after);
	}

	private static function savedText(Reader $after): string
	{
		return $after->version === 'changes'
			? 'Saved as unsaved changes. An editor can review and publish them in the Panel.'
			: 'Saved. The content is now the same as the published version, so there are no unsaved changes.';
	}

	/**
	 * @param list<array{name: string|null, path: list<string|int>}> $created
	 *
	 * @return list<string>
	 */
	private static function createdRefs(Reader $after, array $created): array
	{
		$refs = [];

		foreach ($created as $item) {
			foreach ($after->nodes as $node) {
				if ($node->path === $item['path']) {
					$name = $item['name'] !== null ? $item['name'] . ' = ' : '';
					$refs[] = "{$name}{$node->ref} ({$node->kind} {$node->type})";
				}
			}
		}

		return $refs;
	}

	/**
	 * @param list<string> $messages
	 */
	private static function messages(array $messages): string
	{
		return implode("\n", array_map(static fn(string $message): string => '- ' . $message, $messages));
	}
}
