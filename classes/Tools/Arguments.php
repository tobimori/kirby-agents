<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

/**
 * Typed access to tool arguments. Wrong types become a ToolError for the agent.
 */
final class Arguments
{
	public function __construct(
		private readonly array $values,
	) {}

	public function string(string $key, ?string $default = null): ?string
	{
		$value = $this->values[$key] ?? null;

		if ($value === null) {
			return $default;
		}

		if (!is_string($value)) {
			throw new ToolError("`{$key}` must be a string");
		}

		return $value;
	}

	public function int(string $key, int $default, int $min, int $max): int
	{
		$value = $this->values[$key] ?? null;

		if ($value === null) {
			return $default;
		}

		if (!is_int($value) || $value < $min || $value > $max) {
			throw new ToolError("`{$key}` must be an integer between {$min} and {$max}");
		}

		return $value;
	}

	public function bool(string $key, bool $default): bool
	{
		$value = $this->values[$key] ?? null;

		if ($value === null) {
			return $default;
		}

		if (!is_bool($value)) {
			throw new ToolError("`{$key}` must be true or false");
		}

		return $value;
	}

	/**
	 * @param list<string> $options
	 */
	public function enum(string $key, array $options, string $default): string
	{
		$value = $this->string($key, $default);

		if (!in_array($value, $options, true)) {
			throw new ToolError("`{$key}` must be one of: " . implode(', ', $options));
		}

		return $value;
	}

	/**
	 * A string or a list of strings
	 *
	 * @return list<string>
	 */
	public function strings(string $key): array
	{
		$value = $this->values[$key] ?? null;

		if ($value === null) {
			return [];
		}

		$list = is_array($value) ? $value : [$value];
		$strings = array_values(array_filter($list, is_string(...)));

		if (count($strings) !== count($list)) {
			throw new ToolError("`{$key}` must be a string or a list of strings");
		}

		return $strings;
	}
}
