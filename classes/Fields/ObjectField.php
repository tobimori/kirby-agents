<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use tobimori\Agents\Content\InputCheck;
use tobimori\Agents\Content\Nodes;
use tobimori\Agents\Content\Presenter;
use tobimori\Agents\Schema\Compiler;

/**
 * An object with its own fields
 */
class ObjectField extends Field
{
	public function describe(Compiler $schema): string
	{
		return 'object<' . $schema->type('object', $this->name(), $this->fields()) . '>';
	}

	public function fieldSets(): array
	{
		return ['' => $this->fields()];
	}

	public function input(mixed $value): mixed
	{
		return self::json($value);
	}

	public function check(mixed $value, InputCheck $check, string $where): void
	{
		$check->fields($this->fields(), is_array($value) ? $value : [], $where . ' > ');
	}

	public function nodes(array $value, Nodes $index, array $path, ?int $parent, string $field): void
	{
		$index->fields($this->fields(), $value, $path, $parent);
	}

	public function present(mixed $value, array $path, Presenter $presenter): mixed
	{
		return is_array($value) ? $presenter->values($this->fields(), $value, $path) : $value;
	}

	public function summary(mixed $value, Presenter $presenter): string
	{
		return 'object ' . $presenter->preview($this->fields(), is_array($value) ? $value : []);
	}

	public function preview(mixed $value, Presenter $presenter): ?string
	{
		return null;
	}

	/**
	 * @return array<array-key, mixed>
	 */
	protected function fields(): array
	{
		return is_array($this->props['fields'] ?? null) ? $this->props['fields'] : [];
	}
}
