<?php

declare(strict_types=1);

namespace tobimori\Agents\Schema;

/**
 * Compiled schema of a page or the site: field lines and named types.
 * Nested parts are named types, so the notation never nests.
 */
final class Schema
{
	/**
	 * @param array<string, string> $fields name => description
	 * @param array<string, array<string, string>> $types `kind name` => fields
	 */
	public function __construct(
		public readonly string $title,
		public readonly array $fields,
		public readonly array $types,
	) {}

	/**
	 * Compact notation, or only one named type with `focus`
	 */
	public function render(?string $focus = null): ?string
	{
		if ($focus !== null) {
			$name = $this->findType($focus);

			return $name !== null ? self::block($name, $this->types[$name]) : null;
		}

		$parts = [self::block($this->title, $this->fields)];

		foreach ($this->types as $name => $fields) {
			$parts[] = self::block($name, $fields);
		}

		return implode("\n\n", $parts);
	}

	/**
	 * Accepts the full name (`block columns`) or only the name (`columns`)
	 */
	public function findType(string $focus): ?string
	{
		foreach (array_keys($this->types) as $name) {
			if ($name === $focus || explode(' ', $name, 2)[1] === $focus) {
				return $name;
			}
		}

		return null;
	}

	/**
	 * @param array<string, string> $fields
	 */
	private static function block(string $title, array $fields): string
	{
		if ($fields === []) {
			return $title . "\n  (no fields)";
		}

		$width = max(array_map(strlen(...), array_keys($fields)));
		$lines = [$title];

		foreach ($fields as $name => $description) {
			$lines[] = '  ' . str_pad($name, $width) . '  ' . $description;
		}

		return implode("\n", $lines);
	}
}
