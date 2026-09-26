<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use tobimori\Agents\Content\InputCheck;
use tobimori\Agents\Content\Node;
use tobimori\Agents\Content\Nodes;
use tobimori\Agents\Content\Presenter;
use tobimori\Agents\Schema\Compiler;
use tobimori\Agents\Tools\ToolError;

/**
 * What the agent tools know about one field type.
 * Kirby still reads and stores the values: these classes only add what the Panel does not need,
 * like a text notation of the field, previews, and checks for input the Panel never sends.
 *
 * Register a class for a custom field type with the option `fields`:
 * `'tobimori.agents.fields' => ['rating' => RatingField::class]`
 */
abstract class Field
{
	/**
	 * @param array<array-key, mixed> $props field props from the Kirby form
	 */
	final public function __construct(
		public readonly array $props,
	) {}

	/**
	 * Type and constraints for schema_get, for example `text, max 120 characters`
	 */
	abstract public function describe(Compiler $schema): string;

	/**
	 * Nested field sets for field paths like `text > image > alt`:
	 * by block type, `settings` for layout rows, or `''` for the fields of a structure or object
	 *
	 * @return array<string, array<array-key, mixed>>
	 */
	public function fieldSets(): array
	{
		return [];
	}

	/**
	 * Node kind of the items that agents can insert into the field
	 */
	public function itemKind(): ?string
	{
		return null;
	}

	/**
	 * Creates an item of the node kind, and its node meta
	 *
	 * @param array<array-key, mixed> $op
	 * @param array<array-key, mixed> $content
	 *
	 * @return array{0: array<array-key, mixed>, 1: array{kind: string, type: string, fields: array<array-key, mixed>, props: array<array-key, mixed>}}
	 */
	public function newItem(string $kind, array $op, array $content): array
	{
		throw new ToolError("items cannot be inserted into a {$this->type()} field");
	}

	/**
	 * Checks that a node can move into the field
	 *
	 * @param array{kind: string, type: string, fields: array<array-key, mixed>, props: array<array-key, mixed>} $node
	 */
	public function accept(string $kind, array $node): void
	{
		if ($kind !== $node['kind']) {
			throw new ToolError("a {$node['kind']} cannot be moved into a {$this->type()} field");
		}
	}

	/**
	 * Where inserts `into` a node of the field go, if not into a nested field (`slot`): for example a layout column
	 *
	 * @param mixed $node value of the node
	 * @param array<array-key, mixed> $op
	 *
	 * @return array{path: list<string|int>, kind: string}|null path from the node, and the node kind of the items
	 */
	public function into(string $kind, mixed $node, array $op): ?array
	{
		return null;
	}

	/**
	 * Keys from a node of the field to the values of its fields, for example `content` for blocks
	 *
	 * @return list<string>
	 */
	public function contentPath(string $kind): array
	{
		return [];
	}

	/**
	 * Input from the agent before Kirby gets it
	 */
	public function input(mixed $value): mixed
	{
		return $value;
	}

	/**
	 * Adds errors for input that Kirby changes without an error
	 */
	public function check(mixed $value, InputCheck $check, string $where): void {}

	/**
	 * Numbers the nested nodes of the value
	 *
	 * @param array<array-key, mixed> $value
	 * @param list<string|int> $path
	 */
	public function nodes(array $value, Nodes $index, array $path, ?int $parent, string $field): void {}

	/**
	 * Full value for content_get
	 *
	 * @param list<string|int> $path
	 */
	public function present(mixed $value, array $path, Presenter $presenter): mixed
	{
		return $value;
	}

	/**
	 * Full value of one node of this field
	 */
	public function presentNode(Node $node, mixed $value, Presenter $presenter): array
	{
		return ['ref' => $node->ref, 'value' => $value];
	}

	/**
	 * Value in the outline, after the field name
	 */
	public function summary(mixed $value, Presenter $presenter): string
	{
		return Presenter::short($this->present($value, [], $presenter));
	}

	/**
	 * One node of this field in the outline, after the ref number
	 */
	public function nodeSummary(Node $node, mixed $value, Presenter $presenter): string
	{
		return Presenter::short($value);
	}

	/**
	 * Value in the short preview of a node or object, or null to leave it out
	 */
	public function preview(mixed $value, Presenter $presenter): ?string
	{
		return $this->summary($value, $presenter);
	}

	/**
	 * Text says more about an item than options or flags, so previews show it first
	 */
	public function prominent(): bool
	{
		return false;
	}

	/**
	 * Lists and objects sent as JSON text. Some clients do this, because the schema of `value` has no type.
	 */
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

	/**
	 * A value in a notation: strings in quotes, the rest as JSON
	 */
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
		return is_string($this->props['type'] ?? null) ? $this->props['type'] : 'unknown';
	}

	protected function name(): string
	{
		return is_string($this->props['name'] ?? null) ? $this->props['name'] : 'item';
	}

	/**
	 * Allowed values: `"a" | "b" (Label)`, or `strings` without options
	 */
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
	 * `min`, `max`, and `step` as they are set
	 *
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

	/**
	 * Number of items for list-like fields
	 */
	protected function count(): string
	{
		$min = is_int($this->props['min'] ?? null) ? $this->props['min'] : null;
		$max = is_int($this->props['max'] ?? null) ? $this->props['max'] : null;

		// relation fields with `multiple: false` take one item
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

	/**
	 * Outline value of a list field: `blocks, 3 items`
	 */
	protected function listSummary(mixed $value): string
	{
		$count = is_array($value) ? count($value) : 0;

		return $this->type() . ', ' . ($count === 1 ? '1 item' : $count . ' items');
	}

	/**
	 * Fails for content keys that are not fields
	 *
	 * @param array<array-key, mixed> $content
	 * @param array<array-key, mixed> $fields
	 */
	protected static function ensureKnown(array $content, array $fields, string $where): void
	{
		foreach (array_keys($content) as $field) {
			if (!is_array($fields[$field] ?? null)) {
				throw new ToolError(self::unknownField((string) $field, $fields, $where));
			}
		}
	}
}
