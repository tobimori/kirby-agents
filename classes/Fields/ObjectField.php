<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use Kirby\Toolkit\A;
use tobimori\Agents\Content\InputCheck;
use tobimori\Agents\Content\Nodes;
use tobimori\Agents\Content\Presenter;
use tobimori\Agents\Schema\Compiler;

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

	public function input(mixed $value, mixed $current): mixed
	{
		$value = self::json($value);
		$current = A::wrap($current);

		if (!is_array($value)) {
			// an empty object would remove the values of hidden and read-only fields
			if ($this->locks($current)) {
				throw $this->lockedError();
			}

			return $value;
		}

		// hidden and read-only fields of the object keep their values, but not those in nested fields
		$open = A::filter(
			$this->fields(),
			static fn(mixed $props): bool => is_array($props) && !Fields::isLocked($props),
		);

		if (self::lockedIn($open, $current)) {
			throw $this->lockedError();
		}

		$value = Fields::input($this->fields(), $value, $current, $this->name());
		$locked = array_diff_key($this->allFields(), $open);

		return [...$value, ...array_intersect_key($current, $locked)];
	}

	public function locks(array $value): bool
	{
		return self::lockedIn($this->allFields(), $value);
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
		return Fields::visible($this->allFields());
	}

	/**
	 * @return array<array-key, mixed>
	 */
	protected function allFields(): array
	{
		return A::wrap($this->props['fields'] ?? null);
	}
}
