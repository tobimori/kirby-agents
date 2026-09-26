<?php

declare(strict_types=1);

namespace tobimori\Agents\Content;

use tobimori\Agents\Fields\Fields;

/**
 * Numbers all nodes of the form values depth-first, in document order.
 * The numbers come from the content alone, so they are the same on every request
 * until the content changes. The field classes add the nodes of their type.
 */
final class Nodes
{
	/**
	 * @var array<int, Node>
	 */
	private array $nodes = [];

	/**
	 * @param array<array-key, mixed> $fields props by field name
	 *
	 * @return array<int, Node> by ref number
	 */
	public static function index(array $fields, array $values): array
	{
		$index = new self();
		$index->fields($fields, $values, [], null);

		return $index->nodes;
	}

	/**
	 * Adds the nodes in the values of some fields
	 *
	 * @param array<array-key, mixed> $fields
	 * @param list<string|int> $path
	 */
	public function fields(array $fields, array $values, array $path, ?int $parent): void
	{
		foreach ($fields as $name => $props) {
			$value = $values[$name] ?? null;

			if (is_array($props) && is_array($value)) {
				Fields::for($props)->nodes($value, $this, [...$path, $name], $parent, (string) $name);
			}
		}
	}

	/**
	 * Adds one node and returns its ref number
	 *
	 * @param list<string|int> $path
	 * @param array<array-key, mixed> $fields
	 */
	public function add(
		string $kind,
		string $type,
		array $path,
		array $fields,
		mixed $value,
		?int $parent,
		string $field,
		array $props,
	): int {
		$ref = count($this->nodes) + 1;
		$id = is_array($value) && is_string($value['id'] ?? null) ? $value['id'] : null;

		$this->nodes[$ref] = new Node($ref, $kind, $type, $path, $fields, $id, $parent, $field, $props);

		return $ref;
	}
}
