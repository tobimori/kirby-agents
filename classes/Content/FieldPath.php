<?php

declare(strict_types=1);

namespace tobimori\Agents\Content;

use tobimori\Agents\Fields\Fields;
use tobimori\Agents\Tools\ToolError;

final class FieldPath
{
	/**
	 * @param array<array-key, mixed> $fields
	 *
	 * @return array<array-key, mixed>
	 */
	public static function resolve(array $fields, string $path): array
	{
		$parts = array_values(array_filter(
			array_map(trim(...), explode('>', $path)),
			static fn(string $part): bool => $part !== '',
		));

		if ($parts === []) {
			throw new ToolError('`field` is required');
		}

		$props = self::field($fields, array_shift($parts), '');
		$where = (string) ($props['name'] ?? '');

		while ($parts !== []) {
			$sets = Fields::for($props)->fieldSets();
			$next = array_shift($parts);

			if ($sets === []) {
				$type = is_string($props['type'] ?? null) ? $props['type'] : 'unknown';

				throw new ToolError("`{$where}` is a {$type} field and has no nested fields");
			}

			$fields = $sets[''] ?? null;

			if ($fields === null) {
				if (!array_key_exists($next, $sets)) {
					throw new ToolError(
						"`{$next}` is not a block type of `{$where}`. Choose one of: "
							. implode(', ', array_keys($sets)),
					);
				}

				$fields = $sets[$next];
				$where .= " > {$next}";
				$next = array_shift($parts);

				if ($next === null) {
					throw new ToolError("Add a field name after `{$where}`. Fields: " . self::names($fields));
				}
			}

			$props = self::field($fields, $next, $where);
			$where .= " > {$next}";
		}

		return $props;
	}

	/**
	 * @param array<array-key, mixed> $fields
	 *
	 * @return array<array-key, mixed>
	 */
	private static function field(array $fields, string $name, string $where): array
	{
		$props = $fields[$name] ?? null;

		if (!is_array($props)) {
			$in = $where === '' ? '' : " in `{$where}`";

			throw new ToolError("There is no field `{$name}`{$in}. Fields: " . self::names($fields));
		}

		return $props;
	}

	/**
	 * @param array<array-key, mixed> $fields
	 */
	private static function names(array $fields): string
	{
		return implode(', ', array_map(strval(...), array_keys($fields)));
	}
}
