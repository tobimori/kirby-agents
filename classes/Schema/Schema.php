<?php

declare(strict_types=1);

namespace tobimori\Agents\Schema;

final class Schema
{
	/**
	 * @param array<string, string> $fields
	 * @param array<string, array<string, string>> $types
	 */
	public function __construct(
		public readonly string $title,
		public readonly array $fields,
		public readonly array $types,
	) {}

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

	public function findType(string $focus): ?string
	{
		if (array_key_exists($focus, $this->types)) {
			return $focus;
		}

		$matches = array_filter(
			array_keys($this->types),
			static fn(string $name): bool => explode(' ', $name, 2)[1] === $focus,
		);

		return count($matches) === 1 ? reset($matches) : null;
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
