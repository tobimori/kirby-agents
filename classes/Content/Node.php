<?php

declare(strict_types=1);

namespace tobimori\Agents\Content;

/**
 * A numbered item inside a structured field: a block, layout row, layout column, structure row, or entry
 */
final class Node
{
	/**
	 * @param list<string|int> $path keys from the form values to the node
	 * @param array<array-key, mixed> $fields props of the fields in the node content
	 * @param int|null $parent ref of the node that contains this one
	 * @param string $field name of the structured field that holds the node
	 * @param array<array-key, mixed> $props props of that field
	 */
	public function __construct(
		public readonly int $ref,
		public readonly string $kind,
		public readonly string $type,
		public readonly array $path,
		public readonly array $fields,
		public readonly ?string $id,
		public readonly ?int $parent,
		public readonly string $field,
		public readonly array $props,
	) {}

	public function key(): string
	{
		return implode('/', $this->path);
	}
}
