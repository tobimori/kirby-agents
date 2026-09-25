<?php

declare(strict_types=1);

namespace tobimori\Agents\Content;

use tobimori\Agents\Schema\FieldProps;

/**
 * Numbers all nodes of the form values depth-first, in document order.
 * The numbers come from the content alone, so they are the same on every request
 * until the content changes.
 */
final class Nodes
{
	/**
	 * @var array<int, Node>
	 */
	private array $nodes = [];

	/**
	 * @param array<array-key, mixed> $fields props by field name
	 *
	 * @return array<int, Node> by ref number
	 */
	public static function index(array $fields, array $values): array
	{
		$index = new self();
		$index->walkFields($fields, $values, [], null);

		return $index->nodes;
	}

	/**
	 * @param array<array-key, mixed> $fields
	 * @param list<string|int> $path
	 */
	private function walkFields(array $fields, array $values, array $path, ?int $parent): void
	{
		foreach ($fields as $name => $props) {
			$value = $values[$name] ?? null;

			if (is_array($props) && is_array($value)) {
				$this->walkField($props, $value, [...$path, $name], $parent, (string) $name);
			}
		}
	}

	/**
	 * @param list<string|int> $path
	 */
	private function walkField(array $props, array $value, array $path, ?int $parent, string $field): void
	{
		match ($props['type'] ?? null) {
			'blocks' => $this->walkBlocks($props, $value, $path, $parent, $field),
			'layout' => $this->walkLayout($props, $value, $path, $parent, $field),
			'structure' => $this->walkRows($props, $value, $path, $parent, $field),
			'object' => $this->walkFields(FieldProps::fields($props), $value, $path, $parent),
			'entries' => $this->walkEntries($props, $value, $path, $parent, $field),
			default => null,
		};
	}

	/**
	 * @param list<string|int> $path
	 */
	private function walkBlocks(array $props, array $blocks, array $path, ?int $parent, string $field): void
	{
		foreach ($blocks as $index => $block) {
			$type = is_array($block) && is_string($block['type'] ?? null) ? $block['type'] : 'unknown';
			$fields = FieldProps::fieldset($props, $type);
			$ref = $this->add('block', $type, [...$path, $index], $fields, $block, $parent, $field, $props);
			$content = is_array($block) && is_array($block['content'] ?? null) ? $block['content'] : [];

			$this->walkFields($fields, $content, [...$path, $index, 'content'], $ref);
		}
	}

	/**
	 * @param list<string|int> $path
	 */
	private function walkLayout(array $props, array $rows, array $path, ?int $parent, string $field): void
	{
		$settings = FieldProps::settings($props);

		foreach ($rows as $r => $row) {
			$rowRef = $this->add('layout', 'row', [...$path, $r], $settings, $row, $parent, $field, $props);

			foreach (is_array($row) && is_array($row['columns'] ?? null) ? $row['columns'] : [] as $c => $column) {
				$width = is_array($column) && is_string($column['width'] ?? null) ? $column['width'] : '1/1';
				$columnRef = $this->add(
					'column',
					$width,
					[...$path, $r, 'columns', $c],
					[],
					$column,
					$rowRef,
					'columns',
					$props,
				);
				$blocks = is_array($column) && is_array($column['blocks'] ?? null) ? $column['blocks'] : [];

				$this->walkBlocks($props, $blocks, [...$path, $r, 'columns', $c, 'blocks'], $columnRef, 'blocks');
			}
		}
	}

	/**
	 * @param list<string|int> $path
	 */
	private function walkRows(array $props, array $rows, array $path, ?int $parent, string $field): void
	{
		$fields = FieldProps::fields($props);

		foreach ($rows as $index => $row) {
			$ref = $this->add('row', $field, [...$path, $index], $fields, $row, $parent, $field, $props);
			$this->walkFields($fields, is_array($row) ? $row : [], [...$path, $index], $ref);
		}
	}

	/**
	 * @param list<string|int> $path
	 */
	private function walkEntries(array $props, array $entries, array $path, ?int $parent, string $field): void
	{
		$inner = is_array($props['field'] ?? null) ? $props['field'] : [];
		$type = is_string($inner['type'] ?? null) ? $inner['type'] : 'entry';

		foreach (array_keys($entries) as $index) {
			$this->add('entry', $type, [...$path, $index], [], null, $parent, $field, $props);
		}
	}

	/**
	 * @param list<string|int> $path
	 * @param array<array-key, mixed> $fields
	 */
	private function add(
		string $kind,
		string $type,
		array $path,
		array $fields,
		mixed $value,
		?int $parent,
		string $field,
		array $props,
	): int {
		$ref = count($this->nodes) + 1;
		$id = is_array($value) && is_string($value['id'] ?? null) ? $value['id'] : null;

		$this->nodes[$ref] = new Node($ref, $kind, $type, $path, $fields, $id, $parent, $field, $props);

		return $ref;
	}
}
