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

	private function __construct(
		public readonly ModelWithContent $model,
	) {}

	/**
	 * @param array<array-key, mixed> $fields props by field name
	 *
	 * @return list<string>
	 */
	public static function errors(ModelWithContent $model, array $fields, array $values): array
	{
		$check = new self($model);
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

	public function error(string $message): void
	{
		$this->errors[] = $message;
	}
}
