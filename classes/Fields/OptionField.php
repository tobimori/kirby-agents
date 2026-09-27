<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use tobimori\Agents\Content\InputCheck;
use tobimori\Agents\Schema\Compiler;

class OptionField extends Field
{
	public function describe(Compiler $schema): string
	{
		return 'one of ' . $this->options();
	}

	public function check(mixed $value, InputCheck $check, string $where): void
	{
		$this->checkOptions([$value], $check, $where);
	}

	/**
	 * @param array<array-key, mixed> $given
	 */
	protected function checkOptions(array $given, InputCheck $check, string $where): void
	{
		$options = is_array($this->props['options'] ?? null) ? $this->props['options'] : [];
		$allowed = array_column(array_filter($options, is_array(...)), 'value');

		// no options means options from an API or a query the form could not resolve
		if ($allowed === []) {
			return;
		}

		foreach ($given as $item) {
			if ($item !== null && $item !== '' && !in_array($item, $allowed, true) && $check->isNew($item)) {
				$check->error(
					"{$where}: " . self::encode($item) . ' is not an option. Options: '
						. implode(', ', array_map(self::encode(...), $allowed)),
				);
			}
		}
	}

	private static function encode(mixed $value): string
	{
		return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}
}
