<?php

declare(strict_types=1);

namespace tobimori\Agents\Content;

use tobimori\Agents\Fields\Field;
use tobimori\Agents\Fields\Fields;
use tobimori\Agents\Tools\ToolError;

/**
 * Applies operations to content. Nodes keep their identity in a map of paths, outside of the values,
 * and their fields and rules always come from where they are now
 */
final class Editor
{
	/**
	 * @var array<array-key, mixed>
	 */
	private array $values;

	/**
	 * Where each node is now: the refs of the read, and the keys of new nodes
	 *
	 * @var array<int|string, list<string|int>>
	 */
	private array $paths = [];

	/**
	 * Keys of new nodes, in order. Unnamed ones start with `_`
	 *
	 * @var list<string>
	 */
	private array $created = [];

	/**
	 * Nodes that are moving now, so that a node cannot move into itself
	 *
	 * @var array<int|string, true>
	 */
	private array $moving = [];

	/**
	 * The nodes of the current values, by path
	 *
	 * @var array<string, Node>|null
	 */
	private ?array $index = null;

	/**
	 * @var array<string, true>
	 */
	private array $changed = [];

	public function __construct(
		private readonly Reader $content,
	) {
		$this->values = $content->values;

		foreach ($content->nodes as $node) {
			$this->paths[$node->ref] = $node->path;
		}
	}

	/**
	 * @param list<mixed> $ops
	 */
	public function apply(array $ops): void
	{
		foreach ($ops as $index => $op) {
			$number = $index + 1;

			if (!is_array($op)) {
				throw new ToolError("op {$number}: must be an object");
			}

			try {
				match ($op['op'] ?? null) {
					'set' => $this->set($op),
					'replace' => $this->replace($op),
					'insert' => $this->insert($op),
					'move' => $this->move($op),
					'remove' => $this->remove($op),
					default => throw new ToolError('`op` must be set, replace, insert, move, or remove'),
				};
			} catch (ToolError $error) {
				throw new ToolError("op {$number}: " . $error->getMessage());
			}
		}
	}

	/**
	 * @return array{values: array<array-key, mixed>, created: list<array{name: string|null, path: list<string|int>}>, changed: list<string>}
	 */
	public function result(): array
	{
		$created = [];

		foreach ($this->created as $key) {
			$path = $this->paths[$key] ?? null;

			if ($path !== null) {
				$created[] = ['name' => str_starts_with($key, '_') ? null : $key, 'path' => $path];
			}
		}

		return [
			'values' => $this->values,
			'created' => $created,
			'changed' => array_keys($this->changed),
		];
	}

	private function set(array $op): void
	{
		if (!array_key_exists('value', $op)) {
			throw new ToolError('`value` is required');
		}

		[$props, $path] = $this->slot($op);
		$this->write($props, $path, $op['value']);
	}

	/**
	 * Replaces one exact piece of text in a field, so that long texts do not need to be sent again
	 */
	private function replace(array $op): void
	{
		$old = $op['old'] ?? null;
		$new = $op['new'] ?? null;

		if (!is_string($old) || $old === '' || !is_string($new)) {
			throw new ToolError('`old` and `new` are required, and `old` must not be empty');
		}

		[$props, $path] = $this->slot($op);
		$current = self::getAt($this->values, $path);
		$field = (string) end($path);

		if (!is_string($current)) {
			throw new ToolError("`{$field}` has no text to replace in. Change it with `set`");
		}

		$count = substr_count($current, $old);

		if ($count === 0) {
			throw new ToolError(
				"`old` is not in `{$field}`. Copy it exactly from content_get, with its HTML tags, or read the field again",
			);
		}

		if ($count > 1) {
			throw new ToolError(
				"`old` is {$count} times in `{$field}`. Add more of the text around it, so that it is there only once",
			);
		}

		$this->write($props, $path, substr_replace($current, $new, (int) strpos($current, $old), strlen($old)));
	}

	/**
	 * The field that `set` and `replace` change, from `field` and an optional `ref`
	 *
	 * @return array{0: array<array-key, mixed>, 1: list<string|int>}
	 */
	private function slot(array $op): array
	{
		$field = $op['field'] ?? null;

		if (!is_string($field)) {
			throw new ToolError('`field` is required');
		}

		$ref = $op['ref'] ?? null;

		if ($ref === null) {
			$props = $this->content->fields[$field] ?? null;

			if (!is_array($props)) {
				throw new ToolError(Field::unknownField($field, $this->content->fields, 'the page'));
			}

			self::ensureEditable($field, $props);

			return [$props, [$field]];
		}

		$key = $this->key($ref);
		$node = $this->open($key);
		$props = $node->fields[$field] ?? null;

		if (!is_array($props)) {
			throw new ToolError(Field::unknownField($field, $node->fields, "{$node->kind} {$ref}"));
		}

		self::ensureEditable($field, $props);

		return [$props, [...$node->path, ...Fields::for($node->props)->contentPath($node->kind), $field]];
	}

	/**
	 * @param array<array-key, mixed> $props
	 * @param list<string|int> $path
	 */
	private function write(array $props, array $path, mixed $value): void
	{
		$current = self::getAt($this->values, $path);
		$this->values = self::setAt($this->values, $path, Fields::for($props)->input($value, $current));

		// the nodes in the old value are gone
		$this->forget($path, false);
		$this->touch($path);
	}

	private function insert(array $op): void
	{
		$name = $op['as'] ?? null;

		if ($name !== null && (!is_string($name) || preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1)) {
			throw new ToolError('`as` must be a lowercase name like `a` or `intro`');
		}

		if (
			is_string($name)
			&& (in_array($name, $this->created, true) || array_key_exists($name, $this->content->fields))
		) {
			throw new ToolError("the name `{$name}` is already used");
		}

		$target = $this->target($op);
		$content = Field::json($op['content'] ?? null);
		$content = is_array($content) ? $content : [];
		$item = Fields::for($target['props'])->newItem($target['kind'], $op, $content);

		$key = $name ?? '_' . (count($this->created) + 1);
		$this->created[] = $key;
		$this->paths[$key] = $this->place($target, $item);
	}

	private function move(array $op): void
	{
		$key = $this->key($op['ref'] ?? null);
		$node = $this->open($key);
		$from = $node->path;
		$schema = $this->schema($key);
		$value = self::getAt($this->values, $from);

		// the node and its nodes leave their place, and keep their paths inside of it
		$inside = [];

		foreach ($this->paths as $other => $path) {
			if (self::within($path, $from)) {
				$inside[$other] = array_slice($path, count($from));
				$this->moving[$other] = true;
				unset($this->paths[$other]);
			}
		}

		$this->detach($from);

		try {
			$target = $this->target($op);
		} finally {
			$this->moving = [];
		}

		Fields::for($target['props'])->accept($target['kind'], $node);
		$to = $this->place($target, $value);

		foreach ($inside as $other => $rest) {
			$this->paths[$other] = [...$to, ...$rest];
		}

		$this->index = null;

		// fields and rules come from where a node is: they must stay the same
		if ($this->schema($key) !== $schema) {
			throw new ToolError(
				"{$node->kind} {$key} cannot move there, because its fields are different there. Remove it and insert a new one instead",
			);
		}
	}

	private function remove(array $op): void
	{
		$key = $this->key($op['ref'] ?? null);
		$path = $this->open($key)->path;

		$this->forget($path, true);
		$this->detach($path);
	}

	/**
	 * @return array{path: list<string|int>, index: int|null, props: array<array-key, mixed>, kind: string}
	 */
	private function target(array $op): array
	{
		$given = array_filter(['after', 'before', 'into'], static fn(string $key): bool => array_key_exists($key, $op));

		if (count($given) !== 1) {
			throw new ToolError('send exactly one of `after`, `before`, or `into`');
		}

		$mode = reset($given);

		if ($mode === 'into') {
			return $this->into($op['into'], $op);
		}

		$node = $this->open($this->key($op[$mode]));
		$path = $node->path;
		$index = (int) array_pop($path);

		return [
			'path' => $path,
			'index' => $mode === 'after' ? $index + 1 : $index,
			'props' => $node->props,
			'kind' => $node->kind,
		];
	}

	/**
	 * @return array{path: list<string|int>, index: int|null, props: array<array-key, mixed>, kind: string}
	 */
	private function into(mixed $into, array $op): array
	{
		if (is_string($into) && is_array($this->content->fields[$into] ?? null)) {
			$props = $this->content->fields[$into];
			self::ensureEditable($into, $props);

			return [
				'path' => [$into],
				'index' => null,
				'props' => $props,
				'kind' => Fields::for($props)->itemKind() ?? '',
			];
		}

		$node = $this->open($this->key($into));
		$owner = Fields::for($node->props);
		$inner = $owner->into($node->kind, self::getAt($this->values, $node->path), $op);

		if ($inner !== null) {
			return [
				'path' => [...$node->path, ...$inner['path']],
				'index' => null,
				'props' => $node->props,
				'kind' => $inner['kind'],
			];
		}

		$slot = $op['slot'] ?? null;
		$props = is_string($slot) ? $node->fields[$slot] ?? null : null;
		$kind = is_array($props) ? Fields::for($props)->itemKind() : null;

		if (!is_string($slot) || !is_array($props) || $kind === null) {
			$slots = array_keys(array_filter(
				$node->fields,
				static fn(mixed $props): bool => is_array($props) && Fields::for($props)->itemKind() !== null,
			));

			throw new ToolError(
				'into a '
				. $node->kind
				. ', send `slot` with one of: '
				. ($slots === [] ? 'none' : implode(', ', $slots)),
			);
		}

		self::ensureEditable($slot, $props);

		return [
			'path' => [...$node->path, ...$owner->contentPath($node->kind), $slot],
			'index' => null,
			'props' => $props,
			'kind' => $kind,
		];
	}

	/**
	 * Puts a value into a list and moves the paths of the nodes after it
	 *
	 * @param array{path: list<string|int>, index: int|null, props: array<array-key, mixed>, kind: string} $target
	 *
	 * @return list<string|int> the path of the value
	 */
	private function place(array $target, mixed $value): array
	{
		$list = self::getAt($this->values, $target['path']);
		$list = is_array($list) ? array_values($list) : [];
		$index = min($target['index'] ?? count($list), count($list));

		array_splice($list, $index, 0, [$value]);
		$this->values = self::setAt($this->values, $target['path'], $list);
		$this->shift($target['path'], $index, 1);
		$this->touch($target['path']);

		return [...$target['path'], $index];
	}

	/**
	 * Takes a value out of its list and moves the paths of the nodes after it
	 *
	 * @param list<string|int> $path
	 */
	private function detach(array $path): void
	{
		$index = (int) array_pop($path);
		$list = self::getAt($this->values, $path);
		$list = is_array($list) ? $list : [];

		unset($list[$index]);
		$this->values = self::setAt($this->values, $path, array_values($list));
		$this->shift($path, $index + 1, -1);
		$this->touch($path);
	}

	/**
	 * @param list<string|int> $list
	 */
	private function shift(array $list, int $from, int $by): void
	{
		$depth = count($list);

		foreach ($this->paths as $key => $path) {
			$index = $path[$depth] ?? null;

			if (is_int($index) && $index >= $from && self::within($path, $list)) {
				$path[$depth] = $index + $by;
				$this->paths[$key] = $path;
			}
		}

		$this->index = null;
	}

	/**
	 * Removes the nodes in a path, and the node at the path itself
	 *
	 * @param list<string|int> $path
	 */
	private function forget(array $path, bool $self): void
	{
		foreach ($this->paths as $key => $other) {
			$inside = self::within($other, $path) && count($other) > count($path);

			if ($inside || $self && self::within($other, $path)) {
				unset($this->paths[$key]);
			}
		}

		$this->index = null;
	}

	/**
	 * The node of a key, as it is now, in a field that the agent may change
	 */
	private function open(int|string $key): Node
	{
		$node = $this->node($key);

		if ($node->locked) {
			throw new ToolError("node {$key} is in a read-only field");
		}

		return $node;
	}

	private function node(int|string $key): Node
	{
		if ($this->moving[$key] ?? false) {
			throw new ToolError("node {$key} cannot move into itself");
		}

		$path = $this->paths[$key] ?? throw new ToolError("node {$key} was removed earlier in this call");

		if ($this->index === null) {
			$this->index = [];

			foreach (Nodes::index($this->content->fields, $this->values) as $node) {
				$this->index[$node->key()] = $node;
			}
		}

		return $this->index[implode('/', $path)] ?? throw new ToolError("node {$key} is not there anymore");
	}

	/**
	 * Kinds, types, and complete definitions of a node and the nodes in it, by their path inside of it.
	 * Complete: the fields that agents see are not enough, because hidden fields keep content too
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<array-key, mixed>}>
	 */
	private function schema(int|string $key): array
	{
		$base = $this->node($key)->path;
		$schema = [];

		foreach ($this->index ?? [] as $node) {
			if (self::within($node->path, $base)) {
				$schema[implode('/', array_slice($node->path, count($base)))] = [
					$node->kind,
					$node->type,
					Fields::for($node->props)->itemDefinition($node->kind, $node->type),
				];
			}
		}

		return $schema;
	}

	private function key(mixed $ref): int|string
	{
		if (is_string($ref) && ctype_digit($ref)) {
			$ref = (int) $ref;
		}

		if (is_int($ref) && ($this->content->nodes[$ref] ?? null) !== null) {
			return $ref;
		}

		if (is_string($ref) && in_array($ref, $this->created, true)) {
			return $ref;
		}

		if (is_int($ref)) {
			throw new ToolError("no node {$ref}. Read the outline again to get the current numbers");
		}

		throw new ToolError('`ref` must be a number from the outline, or the `as` name of a node inserted before');
	}

	/**
	 * @param list<string|int> $path
	 */
	private function touch(array $path): void
	{
		$this->changed[(string) $path[0]] = true;
	}

	/**
	 * @param list<string|int> $path
	 * @param list<string|int> $base
	 */
	private static function within(array $path, array $base): bool
	{
		return array_slice($path, 0, count($base)) === $base;
	}

	/**
	 * @param list<string|int> $path
	 */
	private static function getAt(array $values, array $path): mixed
	{
		$value = $values;

		foreach ($path as $key) {
			$value = is_array($value) ? $value[$key] ?? null : null;
		}

		return $value;
	}

	/**
	 * @param list<string|int> $path
	 */
	private static function setAt(array $values, array $path, mixed $value): array
	{
		$key = array_shift($path);

		if ($key === null) {
			return is_array($value) ? $value : [];
		}

		$child = $values[$key] ?? [];
		$values[$key] = $path === [] ? $value : self::setAt(is_array($child) ? $child : [], $path, $value);

		return $values;
	}

	private static function ensureEditable(string $field, array $props): void
	{
		if (($props['disabled'] ?? false) === true) {
			throw new ToolError("field `{$field}` is read-only");
		}
	}
}
