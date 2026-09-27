<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use Kirby\Toolkit\A;
use tobimori\Agents\Content\InputCheck;
use tobimori\Agents\Content\Node;
use tobimori\Agents\Content\Nodes;
use tobimori\Agents\Content\Presenter;
use tobimori\Agents\Schema\Compiler;
use tobimori\Agents\Tools\ToolError;

class StructureField extends ObjectField
{
	public function describe(Compiler $schema): string
	{
		return 'structure<' . $schema->type('row', $this->name(), $this->fields()) . '>' . $this->count();
	}

	public function itemKind(): ?string
	{
		return 'row';
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

		foreach ($rows as $index => $row) {
			$rows[$index] = Fields::input($this->fields(), A::wrap($row), [], "{$this->name()} > row " . ($index + 1));
		}

		return $rows;
	}

	public function locks(array $value): bool
	{
		return A::some($value, fn(mixed $row): bool => is_array($row) && parent::locks($row));
	}

	public function newItem(string $kind, array $op, array $content): array
	{
		if ($kind !== 'row') {
			return parent::newItem($kind, $op, $content);
		}

		$content = Fields::input($this->fields(), $content, [], "row {$this->name()}");

		return [
			$content,
			['kind' => 'row', 'type' => $this->name(), 'fields' => $this->fields(), 'props' => $this->props],
		];
	}

	public function accept(string $kind, array $node): void
	{
		parent::accept($kind, $node);

		if (($this->props['name'] ?? null) !== ($node['props']['name'] ?? null)) {
			throw new ToolError('structure rows can only move within the same structure field');
		}
	}

	public function check(mixed $value, InputCheck $check, string $where): void
	{
		foreach (array_values(is_array($value) ? $value : []) as $index => $row) {
			$check->fields($this->fields(), is_array($row) ? $row : [], "{$where} > row " . ($index + 1) . ' > ');
		}
	}

	public function nodes(array $value, Nodes $index, array $path, ?int $parent, string $field): void
	{
		foreach ($value as $i => $row) {
			$ref = $index->add('row', $field, [...$path, $i], $this->fields(), $row, $parent, $field, $this->props);
			$index->fields($this->fields(), is_array($row) ? $row : [], [...$path, $i], $ref);
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
		return $this->row($node->path, $value, $presenter);
	}

	public function summary(mixed $value, Presenter $presenter): string
	{
		return $this->listSummary($value);
	}

	public function nodeSummary(Node $node, mixed $value, Presenter $presenter): string
	{
		return $presenter->preview($node->fields, is_array($value) ? $value : []);
	}

	/**
	 * @param list<string|int> $path
	 */
	private function row(array $path, mixed $row, Presenter $presenter): array
	{
		return [
			...$presenter->ref($path, null),
			...$presenter->values($this->fields(), is_array($row) ? $row : [], $path),
		];
	}
}
