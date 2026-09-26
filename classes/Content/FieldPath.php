<?php

declare(strict_types=1);

namespace tobimori\Agents\Content;

use tobimori\Agents\Schema\FieldProps;
use tobimori\Agents\Tools\ToolError;

/**
 * Finds the props of a nested field by a path like `text > image > image`:
 * a field name, then a block type for blocks and layouts (or `settings` for layout rows),
 * then a field name in it, and so on. Structures and objects are followed by a field name.
 */
final class FieldPath
{
	/**
	 * @param array<array-key, mixed> $fields top-level field props by name
	 *
	 * @return array<array-key, mixed> props of the field at the end of the path
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
			$type = is_string($props['type'] ?? null) ? $props['type'] : '';
			$next = array_shift($parts);

			$fields = match ($type) {
				'blocks', 'layout' => self::container($props, $next, $where),
				'structure', 'object' => FieldProps::fields($props),
				default => throw new ToolError("`{$where}` is a {$type} field and has no nested fields"),
			};

			if (in_array($type, ['blocks', 'layout'], true)) {
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
	 * Fields of a block type, or of the settings of layout rows
	 *
	 * @return array<array-key, mixed>
	 */
	private static function container(array $props, string $name, string $where): array
	{
		if ($props['type'] === 'layout' && $name === 'settings') {
			return FieldProps::settings($props);
		}

		$types = FieldProps::blockTypes($props);

		if (!in_array($name, $types, true)) {
			$settings = $props['type'] === 'layout' ? ', or `settings` for the row settings' : '';

			throw new ToolError(
				"`{$name}` is not a block type of `{$where}`. Block types: " . implode(', ', $types) . $settings,
			);
		}

		return FieldProps::fieldset($props, $name);
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
