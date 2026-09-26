<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use tobimori\Agents\Content\InputCheck;
use tobimori\Agents\Schema\Compiler;

/**
 * A list of values: checkboxes, multiselect, tags
 */
class OptionsField extends OptionField
{
	public function describe(Compiler $schema): string
	{
		return 'list of ' . $this->options() . $this->count();
	}

	public function input(mixed $value): mixed
	{
		return self::json($value);
	}

	/**
	 * Like Kirby: tags accept any value by default (`accept: all`), the others only options
	 */
	public function check(mixed $value, InputCheck $check, string $where): void
	{
		if (($this->props['accept'] ?? 'options') === 'options') {
			$this->checkOptions(is_array($value) ? $value : [$value], $check, $where);
		}
	}
}
