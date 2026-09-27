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

class LayoutField extends BlocksField
{
	private const COLUMN_ERROR = 'columns belong to their layout row. Insert blocks `into` the column instead';

	public function describe(Compiler $schema): string
	{
		$layouts = [];

		foreach ($this->layouts() as $columns) {
			$layouts[] = implode(' ', $columns);
		}

		$expression =
			'layout, rows with columns '
			. implode(' | ', $layouts)
			. ', blocks<'
			. $this->describeFieldsets($schema)
			. '>';

		if (is_array($this->props['settings'] ?? null)) {
			$expression .= ', row settings<' . $schema->type('settings', $this->name(), $this->settings()) . '>';
		}

		return $expression;
	}

	public function fieldSets(): array
	{
		return ['settings' => $this->settings(), ...parent::fieldSets()];
	}

	public function itemKind(): ?string
	{
		return 'layout';
	}

	public function newItem(string $kind, array $op, array $content): array
	{
		if ($kind === 'column') {
			throw new ToolError(self::COLUMN_ERROR);
		}

		if ($kind !== 'layout') {
			return parent::newItem($kind, $op, $content);
		}

		$columns = $op['columns'] ?? null;

		if (!in_array($columns, $this->layouts(), true)) {
			$options = array_map(static fn(array $layout): string => (string) json_encode($layout), $this->layouts());

			throw new ToolError('`columns` must be one of: ' . implode(', ', $options));
		}

		self::ensureKnown($content, $this->settings(), 'layout row settings');

		return [
			[
				'id' => Str::uuid(),
				'attrs' => $content,
				'columns' => array_map(static fn(mixed $width): array => [
					'id' => Str::uuid(),
					'width' => $width,
					'blocks' => [],
				], $columns),
			],
			['kind' => 'layout', 'type' => 'row', 'fields' => $this->settings(), 'props' => $this->props],
		];
	}

	public function accept(string $kind, array $node): void
	{
		if ($kind === 'column') {
			throw new ToolError(self::COLUMN_ERROR);
		}

		parent::accept($kind, $node);
	}

	public function into(string $kind, mixed $node, array $op): ?array
	{
		if ($kind === 'column') {
			return ['path' => ['blocks'], 'kind' => 'block'];
		}

		if ($kind !== 'layout') {
			return parent::into($kind, $node, $op);
		}

		$column = $op['column'] ?? null;
		$column = is_string($column) && ctype_digit($column) ? (int) $column : $column;
		$count = is_array($node) && is_array($node['columns'] ?? null) ? count($node['columns']) : 0;

		if (!is_int($column) || $column < 1 || $column > $count) {
			throw new ToolError("into a layout row, send `column` as a number from 1 to {$count}");
		}

		return ['path' => ['columns', $column - 1, 'blocks'], 'kind' => 'block'];
	}

	public function contentPath(string $kind): array
	{
		return $kind === 'layout' ? ['attrs'] : parent::contentPath($kind);
	}

	public function check(mixed $value, InputCheck $check, string $where): void
	{
		foreach (array_values(is_array($value) ? $value : []) as $r => $row) {
			$row = is_array($row) ? $row : [];
			$number = $r + 1;
			$attrs = is_array($row['attrs'] ?? null) ? $row['attrs'] : [];

			$check->fields($this->settings(), $attrs, "{$where} > row {$number} settings > ");

			foreach (array_values(is_array($row['columns'] ?? null) ? $row['columns'] : []) as $c => $column) {
				$blocks = is_array($column) && is_array($column['blocks'] ?? null) ? $column['blocks'] : [];
				parent::check($blocks, $check, "{$where} > row {$number} > column " . ($c + 1));
			}
		}
	}

	public function nodes(array $value, Nodes $index, array $path, ?int $parent, string $field): void
	{
		foreach ($value as $r => $row) {
			$rowRef = $index->add(
				'layout',
				'row',
				[...$path, $r],
				$this->settings(),
				$row,
				$parent,
				$field,
				$this->props,
			);

			foreach (is_array($row) && is_array($row['columns'] ?? null) ? $row['columns'] : [] as $c => $column) {
				$width = is_array($column) && is_string($column['width'] ?? null) ? $column['width'] : '1/1';
				$columnPath = [...$path, $r, 'columns', $c];
				$columnRef = $index->add('column', $width, $columnPath, [], $column, $rowRef, 'columns', $this->props);
				$blocks = is_array($column) && is_array($column['blocks'] ?? null) ? $column['blocks'] : [];

				parent::nodes($blocks, $index, [...$columnPath, 'blocks'], $columnRef, 'blocks');
			}
		}
	}

	public function present(mixed $value, array $path, Presenter $presenter): mixed
	{
		if (!is_array($value)) {
			return $value;
		}

		return array_map(fn(int|string $i): array => $this->row(
			[...$path, $i],
			$value[$i],
			$presenter,
		), array_keys($value));
	}

	public function presentNode(Node $node, mixed $value, Presenter $presenter): array
	{
		return match ($node->kind) {
			'layout' => $this->row($node->path, $value, $presenter),
			'column' => $this->column($node->path, $value, $presenter),
			default => parent::presentNode($node, $value, $presenter),
		};
	}

	public function nodeSummary(Node $node, mixed $value, Presenter $presenter): string
	{
		$attrs = is_array($value) && is_array($value['attrs'] ?? null) ? $value['attrs'] : [];

		return match ($node->kind) {
			'layout' => trim('row ' . $presenter->preview($node->fields, $attrs)),
			'column' => 'column ' . $node->type,
			default => parent::nodeSummary($node, $value, $presenter),
		};
	}

	/**
	 * @return array<array-key, mixed>
	 */
	private function settings(): array
	{
		return self::tabs(is_array($this->props['settings'] ?? null) ? $this->props['settings'] : []);
	}

	/**
	 * @return list<list<string>>
	 */
	private function layouts(): array
	{
		$layouts = [];

		foreach (is_array($this->props['layouts'] ?? null) ? $this->props['layouts'] : [] as $layout) {
			$layouts[] = array_values(array_filter(is_array($layout) ? $layout : [], is_string(...)));
		}

		return $layouts;
	}

	/**
	 * @param list<string|int> $path
	 */
	private function row(array $path, mixed $row, Presenter $presenter): array
	{
		$row = is_array($row) ? $row : [];
		$columns = is_array($row['columns'] ?? null) ? $row['columns'] : [];

		return [
			...$presenter->ref($path, $row),
			'settings' => $presenter->values(
				$this->settings(),
				is_array($row['attrs'] ?? null) ? $row['attrs'] : [],
				[...$path, 'attrs'],
			),
			'columns' => array_map(fn(int|string $c): array => $this->column(
				[...$path, 'columns', $c],
				$columns[$c],
				$presenter,
			), array_keys($columns)),
		];
	}

	/**
	 * @param list<string|int> $path
	 */
	private function column(array $path, mixed $column, Presenter $presenter): array
	{
		$column = is_array($column) ? $column : [];
		$blocks = is_array($column['blocks'] ?? null) ? $column['blocks'] : [];

		return [
			...$presenter->ref($path, $column),
			'width' => $column['width'] ?? '1/1',
			'blocks' => parent::present($blocks, [...$path, 'blocks'], $presenter),
		];
	}
}
