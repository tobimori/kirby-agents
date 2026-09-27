<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use Kirby\Toolkit\A;
use Kirby\Toolkit\V;
use tobimori\Agents\Content\InputCheck;
use tobimori\Agents\Content\Node;
use tobimori\Agents\Content\Nodes;
use tobimori\Agents\Content\Presenter;
use tobimori\Agents\Schema\Compiler;
use tobimori\Agents\Tools\ToolError;

abstract class Field
{
	/**
	 * @param array<array-key, mixed> $props
	 */
	final public function __construct(
		public readonly array $props,
	) {}

	abstract public function describe(Compiler $schema): string;

	/**
	 * @return array<string, array<array-key, mixed>>
	 */
	public function fieldSets(): array
	{
		return [];
	}

	public function itemKind(): ?string
	{
		return null;
	}

	/**
	 * @param array<array-key, mixed> $op
	 * @param array<array-key, mixed> $content
	 *
	 * @return array<array-key, mixed>
	 */
	public function newItem(string $kind, array $op, array $content): array
	{
		throw new ToolError("items cannot be inserted into a {$this->type()} field");
	}

	/**
	 * The complete definition that the stored content of an item depends on, also hidden fields.
	 * Items can only move between places with the same definition
	 *
	 * @return array<array-key, mixed>
	 */
	public function itemDefinition(string $kind, string $type): array
	{
		return [];
	}

	public function accept(string $kind, Node $node): void
	{
		if ($kind !== $node->kind) {
			throw new ToolError("a {$node->kind} cannot be moved into a {$this->type()} field");
		}
	}

	/**
	 * @param mixed $node
	 * @param array<array-key, mixed> $op
	 *
	 * @return array{path: list<string|int>, kind: string}|null
	 */
	public function into(string $kind, mixed $node, array $op): ?array
	{
		return null;
	}

	/**
	 * @return list<string>
	 */
	public function contentPath(string $kind): array
	{
		return [];
	}

	public function input(mixed $value, mixed $current): mixed
	{
		return $value;
	}

	public function check(mixed $value, InputCheck $check, string $where): void {}

	/**
	 * If the value has content in fields that agents cannot change: hidden with `agents.ignore`, or read-only
	 */
	public function locks(array $value): bool
	{
		return false;
	}

	/**
	 * @param array<array-key, mixed> $value
	 * @param list<string|int> $path
	 */
	public function nodes(array $value, Nodes $index, array $path, ?int $parent, string $field): void {}

	/**
	 * @param list<string|int> $path
	 */
	public function present(mixed $value, array $path, Presenter $presenter): mixed
	{
		return $value;
	}

	public function presentNode(Node $node, mixed $value, Presenter $presenter): array
	{
		return ['ref' => $node->ref, 'value' => $value];
	}

	public function summary(mixed $value, Presenter $presenter): string
	{
		return Presenter::short($this->present($value, [], $presenter));
	}

	public function nodeSummary(Node $node, mixed $value, Presenter $presenter): string
	{
		return Presenter::short($value);
	}

	public function preview(mixed $value, Presenter $presenter): ?string
	{
		return $this->summary($value, $presenter);
	}

	public function prominent(): bool
	{
		return false;
	}

	public static function json(mixed $value): mixed
	{
		if (!is_string($value)) {
			return $value;
		}

		$text = ltrim($value);

		if (!str_starts_with($text, '[') && !str_starts_with($text, '{')) {
			return $value;
		}

		$decoded = json_decode($text, true);

		return is_array($decoded) ? $decoded : $value;
	}

	public static function quote(mixed $value): string
	{
		return is_string($value) ? '"' . $value . '"' : (string) json_encode($value);
	}

	/**
	 * @param array<array-key, mixed> $fields
	 */
	public static function unknownField(string $field, array $fields, string $where): string
	{
		$names = array_keys($fields);

		return "{$where} has no field `{$field}`. Fields: " . ($names === [] ? 'none' : implode(', ', $names));
	}

	protected function type(): string
	{
		$type = Fields::hint($this->props, 'as') ?? $this->props['type'] ?? null;

		return is_string($type) ? $type : 'unknown';
	}

	protected function name(): string
	{
		return is_string($this->props['name'] ?? null) ? $this->props['name'] : 'item';
	}

	protected function options(): string
	{
		$options = is_array($this->props['options'] ?? null) ? $this->props['options'] : [];

		if ($options === []) {
			return 'strings';
		}

		$values = [];

		foreach (array_slice($options, 0, 30) as $option) {
			$value = is_array($option) ? $option['value'] ?? null : $option;
			$text = is_array($option) && is_string($option['text'] ?? null) ? $option['text'] : null;
			$differs = $text !== null && is_string($value) && strtolower($text) !== strtolower($value);
			$values[] = self::quote($value) . ($differs ? ' (' . $text . ')' : '');
		}

		$more = count($options) - 30;

		return implode(' | ', $values) . ($more > 0 ? " | … {$more} more" : '');
	}

	protected function length(): string
	{
		$min = is_int($this->props['minlength'] ?? null) ? $this->props['minlength'] : null;
		$max = is_int($this->props['maxlength'] ?? null) ? $this->props['maxlength'] : null;

		return match (true) {
			$min !== null && $max !== null => ", {$min} to {$max} characters",
			$max !== null => ", max {$max} characters",
			$min !== null => ", min {$min} characters",
			default => '',
		};
	}

	/**
	 * @return list<string>
	 */
	protected function limits(): array
	{
		$parts = [];

		foreach (['min', 'max', 'step'] as $key) {
			if (is_int($this->props[$key] ?? null) || is_float($this->props[$key] ?? null)) {
				$parts[] = $key . ' ' . $this->props[$key];
			}
		}

		return $parts;
	}

	protected function count(): string
	{
		$min = is_int($this->props['min'] ?? null) ? $this->props['min'] : null;
		$max = is_int($this->props['max'] ?? null) ? $this->props['max'] : null;

		if (($this->props['multiple'] ?? true) === false) {
			$max = 1;
		}

		return match (true) {
			$min !== null && $max !== null => ", {$min} to {$max} items",
			$max !== null => ", max {$max}",
			$min !== null => ", min {$min}",
			default => '',
		};
	}

	protected function listSummary(mixed $value): string
	{
		$count = is_array($value) ? count($value) : 0;

		return $this->type() . ', ' . ($count === 1 ? '1 item' : $count . ' items');
	}

	/**
	 * A new whole value would remove content that the agent cannot see or change
	 */
	protected function lockedError(): ToolError
	{
		return new ToolError(
			"`{$this->name()}` has content in fields that are hidden from agents or read-only. A new whole value would change it, so change its items with `ref` operations instead",
		);
	}

	/**
	 * @param array<array-key, mixed> $fields all fields, also the hidden ones
	 */
	protected static function lockedIn(array $fields, mixed $content): bool
	{
		$content = A::wrap($content);

		return A::some($fields, static function (mixed $props, int|string $name) use ($content): bool {
			$value = $content[$name] ?? null;

			// @mago-expect analysis:non-documented-method (Kirby validators are called with __callStatic)
			if (!is_array($props) || V::empty($value)) {
				return false;
			}

			return Fields::isLocked($props) || is_array($value) && Fields::for($props)->locks($value);
		});
	}
}
