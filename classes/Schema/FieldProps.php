<?php

declare(strict_types=1);

namespace tobimori\Agents\Schema;

/**
 * Reads nested field definitions from the props of a Kirby field
 */
final class FieldProps
{
	/**
	 * Sub-fields of a structure or object
	 *
	 * @return array<array-key, mixed>
	 */
	public static function fields(array $props): array
	{
		return is_array($props['fields'] ?? null) ? $props['fields'] : [];
	}

	/**
	 * Fields of one block type, from all its tabs
	 *
	 * @return array<array-key, mixed>
	 */
	public static function fieldset(array $props, string $type): array
	{
		$fieldset = $props['fieldsets'][$type] ?? null;

		return self::tabs(is_array($fieldset) ? $fieldset : []);
	}

	/**
	 * Fields of the settings of a layout row
	 *
	 * @return array<array-key, mixed>
	 */
	public static function settings(array $props): array
	{
		return self::tabs(is_array($props['settings'] ?? null) ? $props['settings'] : []);
	}

	/**
	 * @return list<string>
	 */
	public static function blockTypes(array $props): array
	{
		$fieldsets = is_array($props['fieldsets'] ?? null) ? $props['fieldsets'] : [];

		return array_map(strval(...), array_keys($fieldsets));
	}

	/**
	 * @return array<array-key, mixed>
	 */
	private static function tabs(array $fieldset): array
	{
		$fields = [];

		foreach (is_array($fieldset['tabs'] ?? null) ? $fieldset['tabs'] : [] as $tab) {
			$fields += is_array($tab['fields'] ?? null) ? $tab['fields'] : [];
		}

		return $fields;
	}
}
