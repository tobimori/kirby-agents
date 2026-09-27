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
	 * @var array<string, true>
	 */
	private array $old = [];

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
		array_walk_recursive($before, static function (mixed $value) use ($check): void {
			$check->old[(string) json_encode($value)] = true;
		});
		$check->fields($fields, $values, '');

		return $check->errors;
	}

	/**
	 * @param array<array-key, mixed> $fields
	 */
	public function fields(array $fields, array $values, string $where): void
	{
		foreach ($fields as $name => $props) {
			if (is_array($props)) {
				Fields::for($props)->check($values[$name] ?? null, $this, $where . $name);
			}
		}
	}

	public function isNew(mixed $value): bool
	{
		return ($this->old[(string) json_encode($value)] ?? false) === false;
	}

	public function error(string $message): void
	{
		$this->errors[] = $message;
	}
}
