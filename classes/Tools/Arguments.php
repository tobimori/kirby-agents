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

	public function has(string $key): bool
	{
		return ($this->values[$key] ?? null) !== null;
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
	 * A list of values of any type, with a maximum length
	 *
	 * @return list<mixed>
	 */
	public function list(string $key, int $max): array
	{
		$value = $this->values[$key] ?? null;

		if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > $max) {
			throw new ToolError("`{$key}` must be a list with 1 to {$max} items");
		}

		return $value;
	}

	/**
	 * Offset from the `cursor` argument, which is the `nextCursor` of a previous list
	 */
	public function offset(): int
	{
		$cursor = $this->string('cursor');

		if ($cursor === null) {
			return 0;
		}

		$offset = base64_decode($cursor, true);

		if ($offset === false || !ctype_digit($offset)) {
			throw new ToolError('`cursor` is not valid. Use `nextCursor` from the previous result.');
		}

		return (int) $offset;
	}

	/**
	 * `nextCursor` for a list result, null on the last page
	 */
	public static function nextCursor(int $offset, int $limit, int $total): ?string
	{
		return ($offset + $limit) < $total ? base64_encode((string) ($offset + $limit)) : null;
	}

	/**
	 * An object with names as keys, or an empty array when it is missing
	 *
	 * @return array<string, mixed>
	 */
	public function object(string $key): array
	{
		$value = $this->values[$key] ?? [];

		if (!is_array($value) || $value !== [] && array_is_list($value)) {
			throw new ToolError("`{$key}` must be an object");
		}

		$object = [];

		foreach ($value as $name => $item) {
			$object[(string) $name] = $item;
		}

		return $object;
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
