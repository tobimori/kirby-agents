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

class EntriesField extends Field
{
	public function describe(Compiler $schema): string
	{
		return 'entries<' . $schema->expression($this->field()) . '>' . $this->count();
	}

	public function input(mixed $value, mixed $current): mixed
	{
		$value = self::json($value);

		if (!is_array($value)) {
			return $value;
		}

		$field = Fields::for($this->field());

		return array_map(static fn(mixed $item): mixed => $field->input($item, null), $value);
	}

	public function itemDefinition(string $kind, string $type): array
	{
		return $kind === 'entry' ? $this->field() : parent::itemDefinition($kind, $type);
	}

	public function accept(string $kind, Node $node): void
	{
		parent::accept($kind, $node);

		$type = $this->entryType();

		if ($node->type !== $type) {
			throw new ToolError("an entry of type `{$node->type}` cannot move into entries of type `{$type}`");
		}
	}

	public function check(mixed $value, InputCheck $check, string $where): void
	{
		$field = Fields::for($this->field());

		foreach (array_values(A::wrap($value)) as $index => $item) {
			$field->check($item, $check, "{$where} > entry " . ($index + 1));
		}
	}

	public function nodes(array $value, Nodes $index, array $path, ?int $parent, string $field): void
	{
		$type = $this->entryType();

		foreach (array_keys($value) as $i) {
			$index->add('entry', $type, [...$path, $i], [], null, $parent, $field, $this->props);
		}
	}

	public function summary(mixed $value, Presenter $presenter): string
	{
		return $this->listSummary($value);
	}

	public function preview(mixed $value, Presenter $presenter): ?string
	{
		return null;
	}

	private function entryType(): string
	{
		$type = $this->field()['type'] ?? null;

		return is_string($type) ? $type : 'entry';
	}

	/**
	 * @return array<array-key, mixed>
	 */
	private function field(): array
	{
		return is_array($this->props['field'] ?? null) ? $this->props['field'] : [];
	}
}
