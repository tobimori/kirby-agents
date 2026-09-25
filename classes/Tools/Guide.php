<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

/**
 * Writes the parameters of an input schema as text for the tool description,
 * because some clients show only the description, not the property schemas
 */
final class Guide
{
	public static function parameters(array $schema): string
	{
		$properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

		if ($properties === []) {
			return 'No parameters.';
		}

		$required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
		$lines = [$required === [] ? 'Parameters, all optional:' : 'Parameters:'];

		foreach ($properties as $name => $property) {
			$lines[] =
				'- '
				. $name
				. ': '
				. self::property(is_array($property) ? $property : [], in_array($name, $required, true));
		}

		return implode("\n", $lines);
	}

	private static function property(array $property, bool $required): string
	{
		$parts = [];

		if ($required) {
			$parts[] = 'Required';
		}

		if (is_string($property['description'] ?? null)) {
			$parts[] = $property['description'];
		}

		if (is_array($property['enum'] ?? null)) {
			$parts[] = 'One of ' . implode(', ', array_map(self::code(...), $property['enum']));
		}

		if (is_int($property['minimum'] ?? null) && is_int($property['maximum'] ?? null)) {
			$parts[] = "Range {$property['minimum']} to {$property['maximum']}";
		}

		if (array_key_exists('default', $property)) {
			$parts[] = 'Default ' . self::code($property['default']);
		}

		return implode('. ', $parts);
	}

	private static function code(mixed $value): string
	{
		$text = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		return '`' . (string) $text . '`';
	}
}
