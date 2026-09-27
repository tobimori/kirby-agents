<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use Kirby\Toolkit\Str;
use tobimori\Agents\Content\InputCheck;
use tobimori\Agents\Content\Node;
use tobimori\Agents\Content\Nodes;
use tobimori\Agents\Content\Presenter;
use tobimori\Agents\Schema\Compiler;
use tobimori\Agents\Tools\ToolError;

class BlocksField extends Field
{
	public function describe(Compiler $schema): string
	{
		return 'blocks<' . $this->describeFieldsets($schema) . '>' . $this->count();
	}

	public function fieldSets(): array
	{
		$sets = [];

		foreach ($this->blockTypes() as $type) {
			$sets[$type] = $this->fieldset($type);
		}

		return $sets;
	}

	public function itemKind(): ?string
	{
		return 'block';
	}

	public function newItem(string $kind, array $op, array $content): array
	{
		if ($kind !== 'block') {
			return parent::newItem($kind, $op, $content);
		}

		$type = $op['type'] ?? null;

		if (!is_string($type) || !in_array($type, $this->blockTypes(), true)) {
			throw new ToolError('`type` must be one of: ' . implode(', ', $this->blockTypes()));
		}

		$fields = $this->fieldset($type);
		self::ensureKnown($content, $fields, "block {$type}");

		return [
			['id' => Str::uuid(), 'type' => $type, 'isHidden' => false, 'content' => $content],
			['kind' => 'block', 'type' => $type, 'fields' => $fields, 'props' => $this->props],
		];
	}

	public function accept(string $kind, array $node): void
	{
		parent::accept($kind, $node);

		if ($kind === 'block' && !in_array($node['type'], $this->blockTypes(), true)) {
			throw new ToolError(
				"block type `{$node['type']}` is not allowed there. Allowed: " . implode(', ', $this->blockTypes()),
			);
		}
	}

	public function contentPath(string $kind): array
	{
		return $kind === 'block' ? ['content'] : parent::contentPath($kind);
	}

	public function input(mixed $value, mixed $current): mixed
	{
		return self::json($value);
	}

	public function check(mixed $value, InputCheck $check, string $where): void
	{
		foreach (array_values(is_array($value) ? $value : []) as $index => $block) {
			$type = self::blockType($block);
			$content = is_array($block) && is_array($block['content'] ?? null) ? $block['content'] : [];
			$number = $index + 1;

			$check->fields($this->fieldset($type), $content, "{$where} > block {$number} ({$type}) > ");
		}
	}

	public function nodes(array $value, Nodes $index, array $path, ?int $parent, string $field): void
	{
		foreach ($value as $i => $block) {
			$type = self::blockType($block);
			$fields = $this->fieldset($type);
			$ref = $index->add('block', $type, [...$path, $i], $fields, $block, $parent, $field, $this->props);
			$content = is_array($block) && is_array($block['content'] ?? null) ? $block['content'] : [];

			$index->fields($fields, $content, [...$path, $i, 'content'], $ref);
		}
	}

	public function present(mixed $value, array $path, Presenter $presenter): mixed
	{
		if (!is_array($value)) {
			return $value;
		}

		return array_map(fn(int|string $i): array => $this->block(
			[...$path, $i],
			$value[$i],
			$presenter,
		), array_keys($value));
	}

	public function presentNode(Node $node, mixed $value, Presenter $presenter): array
	{
		return $node->kind === 'block'
			? $this->block($node->path, $value, $presenter)
			: parent::presentNode($node, $value, $presenter);
	}

	public function summary(mixed $value, Presenter $presenter): string
	{
		return $this->listSummary($value);
	}

	public function nodeSummary(Node $node, mixed $value, Presenter $presenter): string
	{
		if ($node->kind !== 'block') {
			return parent::nodeSummary($node, $value, $presenter);
		}

		$value = is_array($value) ? $value : [];
		$content = is_array($value['content'] ?? null) ? $value['content'] : [];

		return trim(
			$node->type
			. ' '
			. $presenter->preview($node->fields, $content, 2)
			. (($value['isHidden'] ?? false) === true ? ' (hidden)' : ''),
		);
	}

	public function preview(mixed $value, Presenter $presenter): ?string
	{
		return null;
	}

	/**
	 * @return list<string>
	 */
	public function blockTypes(): array
	{
		$fieldsets = is_array($this->props['fieldsets'] ?? null) ? $this->props['fieldsets'] : [];

		return array_map(strval(...), array_keys($fieldsets));
	}

	/**
	 * @return array<array-key, mixed>
	 */
	public function fieldset(string $type): array
	{
		$fieldset = $this->props['fieldsets'][$type] ?? null;

		return self::tabs(is_array($fieldset) ? $fieldset : []);
	}

	protected function describeFieldsets(Compiler $schema): string
	{
		$names = [];

		foreach ($this->blockTypes() as $type) {
			$names[] = $schema->type('block', $type, $this->fieldset($type));
		}

		return implode(' | ', $names);
	}

	/**
	 * @param list<string|int> $path
	 */
	protected function block(array $path, mixed $block, Presenter $presenter): array
	{
		$block = is_array($block) ? $block : [];
		$content = is_array($block['content'] ?? null) ? $block['content'] : [];
		$type = self::blockType($block);

		return array_filter(
			[
				...$presenter->ref($path, $block),
				'type' => $type,
				'hidden' => ($block['isHidden'] ?? false) === true ? true : null,
				'content' => $presenter->values($this->fieldset($type), $content, [...$path, 'content']),
			],
			static fn(mixed $value): bool => $value !== null,
		);
	}

	/**
	 * @return array<array-key, mixed>
	 */
	protected static function tabs(array $fieldset): array
	{
		$fields = [];

		foreach (is_array($fieldset['tabs'] ?? null) ? $fieldset['tabs'] : [] as $tab) {
			$fields += is_array($tab['fields'] ?? null) ? $tab['fields'] : [];
		}

		return Fields::visible($fields);
	}

	private static function blockType(mixed $block): string
	{
		return is_array($block) && is_string($block['type'] ?? null) ? $block['type'] : 'unknown';
	}
}
