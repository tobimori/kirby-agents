<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use Kirby\Toolkit\A;
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

		$content = Fields::input($this->settings(), $content, [], 'layout row settings');

		return [
			'id' => Str::uuid(),
			'attrs' => $content,
			'columns' => array_map(static fn(mixed $width): array => [
				'id' => Str::uuid(),
				'width' => $width,
				'blocks' => [],
			], $columns),
		];
	}

	public function itemDefinition(string $kind, string $type): array
	{
		if ($kind === 'block') {
			return parent::itemDefinition($kind, $type);
		}

		// rows and columns contain blocks, and rows have columns from the layouts
		return [
			'settings' => self::tabFields(A::wrap($this->props['settings'] ?? null)),
			'fieldsets' => $this->props['fieldsets'] ?? null,
			'layouts' => $this->layouts(),
		];
	}

	public function accept(string $kind, Node $node): void
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

	public function input(mixed $value, mixed $current): mixed
	{
		if (is_array($current) && $this->locks($current)) {
			throw $this->lockedError();
		}

		$value = self::json($value);

		if (!is_array($value)) {
			return $value;
		}

		$rows = array_values($value);

		foreach ($rows as $r => $row) {
			$row = A::wrap($row);
			$where = $this->name() . ' > row ' . ($r + 1);
			$row['attrs'] = Fields::input($this->settings(), A::wrap($row['attrs'] ?? null), [], "{$where} settings");
			$columns = array_values(A::wrap($row['columns'] ?? null));

			foreach ($columns as $c => $column) {
				$column = A::wrap($column);
				$column['blocks'] = $this->blocksInput(
					A::wrap($column['blocks'] ?? null),
					"{$where} > column " . ($c + 1),
				);
				$columns[$c] = $column;
			}

			$row['columns'] = $columns;
			$rows[$r] = $row;
		}

		return $rows;
	}

	public function locks(array $value): bool
	{
		$settings = self::tabFields(A::wrap($this->props['settings'] ?? null));

		foreach ($value as $row) {
			$row = A::wrap($row);

			if (self::lockedIn($settings, $row['attrs'] ?? null)) {
				return true;
			}

			foreach (A::wrap($row['columns'] ?? null) as $column) {
				if (parent::locks(A::wrap($column['blocks'] ?? null))) {
					return true;
				}
			}
		}

		return false;
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
