<?php

declare(strict_types=1);

namespace tobimori\Agents\Content;

use Kirby\Cms\ModelWithContent;
use tobimori\Agents\Fields\Fields;

final class InputCheck
{
	/**
	 * @var list<string>
	 */
	private array $errors = [];

	/**
	 * Values that are stored already, by field path. They can be invalid and must not block other changes
	 *
	 * @var array<string, true>
	 */
	private array $old = [];

	/**
	 * Names and definitions of the fields that are checked now
	 *
	 * @var list<string>
	 */
	private array $path = [];

	private bool $collecting = false;

	private function __construct(
		public readonly ModelWithContent $model,
	) {}

	/**
	 * @param array<array-key, mixed> $fields
	 * @param array<array-key, mixed> $before
	 *
	 * @return list<string>
	 */
	public static function errors(ModelWithContent $model, array $fields, array $values, array $before = []): array
	{
		$check = new self($model);

		// the same checks on the stored values collect them with isNew()
		$check->collecting = true;
		$check->fields($fields, $before, '');
		$check->collecting = false;
		$check->errors = [];

		$check->fields($fields, $values, '');

		return $check->errors;
	}

	/**
	 * @param array<array-key, mixed> $fields
	 */
	public function fields(array $fields, array $values, string $where): void
	{
		foreach ($fields as $name => $props) {
			if (!is_array($props)) {
				continue;
			}

			// the definition too: fields with the same name can have other rules, like in two block types
			$this->path[] = $name . '#' . hash('xxh3', (string) json_encode($props));
			Fields::for($props)->check($values[$name] ?? null, $this, $where . $name);
			array_pop($this->path);
		}
	}

	/**
	 * If the value is not stored already in the same field with the same rules. Field paths skip the item numbers,
	 * because items can move
	 */
	public function isNew(mixed $value): bool
	{
		$key = implode('/', $this->path) . ' ' . (string) json_encode($value);

		if ($this->collecting) {
			$this->old[$key] = true;

			return false;
		}

		return ($this->old[$key] ?? false) === false;
	}

	public function error(string $message): void
	{
		$this->errors[] = $message;
	}
}
