<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use tobimori\Agents\Schema\Compiler;

/**
 * Field types without a class: the notation shows what the props tell
 */
class CustomField extends Field
{
	public function describe(Compiler $schema): string
	{
		$parts = ["custom field \"{$this->type()}\""];

		if (is_array($this->props['options'] ?? null) && $this->props['options'] !== []) {
			$parts[] = 'options ' . $this->options();
		}

		$parts = [...$parts, ...$this->limits()];

		foreach (['minlength', 'maxlength'] as $key) {
			if (is_int($this->props[$key] ?? null)) {
				$parts[] = $key . ' ' . $this->props[$key];
			}
		}

		$default = $this->props['default'] ?? null;

		if ($default !== null && $default !== '') {
			$parts[] = 'default ' . self::quote($default);
		}

		// top-level fields carry their current value, which shows the value type
		if (($this->props['value'] ?? null) !== null) {
			$parts[] = 'value ' . get_debug_type($this->props['value']);
		}

		return implode(', ', $parts);
	}
}
