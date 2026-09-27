<?php

declare(strict_types=1);

namespace tobimori\Agents\Content;

use tobimori\Agents\Fields\Fields;

final class Nodes
{
	/**
	 * @var array<int, Node>
	 */
	private array $nodes = [];

	/**
	 * If the fields that are indexed now are in a read-only field, also through objects without a ref
	 */
	private bool $locked = false;

	/**
	 * @param array<array-key, mixed> $fields
	 *
	 * @return array<int, Node>
	 */
	public static function index(array $fields, array $values): array
	{
		$index = new self();
		$index->fields($fields, $values, [], null);

		return $index->nodes;
	}

	/**
	 * @param array<array-key, mixed> $fields
	 * @param list<string|int> $path
	 */
	public function fields(array $fields, array $values, array $path, ?int $parent): void
	{
		foreach ($fields as $name => $props) {
			$value = $values[$name] ?? null;

			if (!is_array($props) || !is_array($value)) {
				continue;
			}

			$outer = $this->locked;
			$this->locked = $outer || ($props['disabled'] ?? false) === true;
			Fields::for($props)->nodes($value, $this, [...$path, $name], $parent, (string) $name);
			$this->locked = $outer;
		}
	}

	/**
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

		$this->nodes[$ref] = new Node($ref, $kind, $type, $path, $fields, $id, $parent, $field, $props, $this->locked);

		return $ref;
	}
}
