<?php

declare(strict_types=1);

namespace tobimori\Agents\Content;

use Kirby\Cms\ModelWithContent;
use tobimori\Agents\Fields\Fields;

/**
 * Checks input that Kirby changes without an error, because the Panel never sends it.
 * For example, option fields drop values that are not an option. The field classes do the checks.
 */
final class InputCheck
{
	/**
	 * @var list<string>
	 */
	private array $errors = [];

	/**
	 * @var array<string, true> JSON of each value in the content before the change
	 */
	private array $old = [];

	private function __construct(
		public readonly ModelWithContent $model,
	) {}

	/**
	 * @param array<array-key, mixed> $fields props by field name
	 * @param array<array-key, mixed> $before content before the change: its values are not checked
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
	 * Checks the values of some fields. `$where` comes before each field name in the errors
	 *
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

	/**
	 * False for a single value (text, option, reference) that the content had before,
	 * anywhere. Old content should not block a change, also when it moved.
	 */
	public function isNew(mixed $value): bool
	{
		return ($this->old[(string) json_encode($value)] ?? false) === false;
	}

	public function error(string $message): void
	{
		$this->errors[] = $message;
	}
}
