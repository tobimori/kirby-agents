<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use tobimori\Agents\Content\Nodes;
use tobimori\Agents\Content\Presenter;
use tobimori\Agents\Schema\Compiler;

/**
 * A list of values of one simple field. Each value is a node of kind `entry`.
 */
class EntriesField extends Field
{
	public function describe(Compiler $schema): string
	{
		return 'entries<' . $schema->expression($this->field()) . '>' . $this->count();
	}

	public function input(mixed $value): mixed
	{
		return self::json($value);
	}

	public function nodes(array $value, Nodes $index, array $path, ?int $parent, string $field): void
	{
		$type = $this->field()['type'] ?? null;
		$type = is_string($type) ? $type : 'entry';

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

	/**
	 * Props of the field of each entry
	 *
	 * @return array<array-key, mixed>
	 */
	private function field(): array
	{
		return is_array($this->props['field'] ?? null) ? $this->props['field'] : [];
	}
}
