<?php

declare(strict_types=1);

namespace tobimori\Agents\Content;

final class Node
{
	/**
	 * @param list<string|int> $path
	 * @param array<array-key, mixed> $fields
	 * @param array<array-key, mixed> $props
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
		public readonly bool $locked,
	) {}

	public function key(): string
	{
		return implode('/', $this->path);
	}
}
