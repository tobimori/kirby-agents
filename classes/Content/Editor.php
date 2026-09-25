<?php

declare(strict_types=1);

namespace tobimori\Agents\Content;

use Kirby\Toolkit\Str;
use tobimori\Agents\Schema\FieldProps;
use tobimori\Agents\Tools\ToolError;

/**
 * Applies operations to the form values of one read.
 *
 * All refs point to the read version. Each node carries a marker (`__ref` for
 * existing nodes, `__new` for inserted ones), so an operation finds its node
 * even after earlier operations moved or inserted other nodes.
 */
final class Editor
{
	private const REF = '__ref';

	private const NEW = '__new';

	/**
	 * @var array<array-key, mixed>
	 */
	private array $values;

	/**
	 * What we know about each node: kind, type, the fields of its content,
	 * and the props of the field that holds it
	 *
	 * @var array<int|string, array{kind: string, type: string, fields: array<array-key, mixed>, props: array<array-key, mixed>}>
	 */
	private array $nodes = [];

	/**
	 * @var array<string, true> top-level fields that the operations changed
	 */
	private array $changed = [];

	public function __construct(
		private readonly Reader $content,
	) {
		$this->values = $content->values;

		foreach ($content->nodes as $node) {
			$this->values = self::setAt($this->values, [...$node->path, self::REF], $node->ref);
			$this->nodes[$node->ref] = [
				'kind' => $node->kind,
				'type' => $node->type,
				'fields' => $node->fields,
				'props' => $node->props,
			];
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
					'insert' => $this->insert($op),
					'move' => $this->move($op),
					'remove' => $this->remove($op),
					default => throw new ToolError('`op` must be set, insert, move, or remove'),
				};
			} catch (ToolError $error) {
				throw new ToolError("op {$number}: " . $error->getMessage());
			}
		}
	}

	/**
	 * Form values without markers, and where each new node is now
	 *
	 * @return array{values: array<array-key, mixed>, created: list<array{name: string|null, path: list<string|int>}>, changed: list<string>}
	 */
	public function result(): array
	{
		$created = [];

		foreach (array_keys($this->nodes) as $key) {
			$path = is_string($key) ? self::search($this->values, self::NEW, $key, []) : null;

			// unnamed inserts have internal keys like `_4`, removed inserts have no path
			if ($path !== null) {
				$created[] = ['name' => str_starts_with((string) $key, '_') ? null : (string) $key, 'path' => $path];
			}
		}

		$values = self::strip($this->values);

		return [
			'values' => is_array($values) ? $values : [],
			'created' => $created,
			'changed' => array_keys($this->changed),
		];
	}

	private function set(array $op): void
	{
		$field = $op['field'] ?? null;

		if (!is_string($field)) {
			throw new ToolError('`field` is required');
		}

		if (!array_key_exists('value', $op)) {
			throw new ToolError('`value` is required');
		}

		$ref = $op['ref'] ?? null;

		if ($ref === null) {
			$props = $this->content->fields[$field] ?? null;

			if (!is_array($props)) {
				throw new ToolError(self::unknownField($field, $this->content->fields, 'the page'));
			}

			self::ensureEditable($field, $props);
			$this->values[$field] = $op['value'];
			$this->changed[$field] = true;

			return;
		}

		$key = $this->key($ref);
		$node = $this->nodes[$key];
		$props = $node['fields'][$field] ?? null;

		if (!is_array($props)) {
			throw new ToolError(self::unknownField($field, $node['fields'], "{$node['kind']} {$ref}"));
		}

		self::ensureEditable($field, $props);

		$path = $this->pathOf($key);
		$slot = match ($node['kind']) {
			'block' => ['content', $field],
			'layout' => ['attrs', $field],
			default => [$field],
		};

		$this->values = self::setAt($this->values, [...$path, ...$slot], $op['value']);
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
			&& (($this->nodes[$name] ?? null) !== null || array_key_exists($name, $this->content->fields))
		) {
			throw new ToolError("the name `{$name}` is already used");
		}

		$target = $this->target($op);
		$content = is_array($op['content'] ?? null) ? $op['content'] : [];

		[$node, $meta] = match ($target['kind']) {
			'blocks' => $this->newBlock($target['props'], $op, $content),
			'structure' => $this->newRow($target['props'], $content),
			'layout' => $this->newLayoutRow($target['props'], $op, $content),
			default => throw new ToolError("items cannot be inserted into a {$target['kind']} field"),
		};

		$key = $name ?? '_' . (count($this->nodes) + 1);
		$node[self::NEW] = $key;
		$this->nodes[$key] = $meta;

		$this->values = self::insertAt($this->values, $target['path'], $target['index'], $node);
		$this->touch($target['path']);
	}

	private function move(array $op): void
	{
		$key = $this->key($op['ref'] ?? null);
		$from = $this->pathOf($key);
		$node = self::getAt($this->values, $from);
		$meta = $this->nodes[$key];

		$this->values = self::removeAt($this->values, $from);
		$this->touch($from);

		$target = $this->target($op);
		$kind = self::containerKind($meta['kind']);

		if ($target['kind'] !== $kind) {
			throw new ToolError("a {$meta['kind']} cannot be moved into a {$target['kind']} field");
		}

		if ($kind === 'blocks' && !in_array($meta['type'], FieldProps::blockTypes($target['props']), true)) {
			throw new ToolError(
				"block type `{$meta['type']}` is not allowed there. Allowed: "
					. implode(', ', FieldProps::blockTypes($target['props'])),
			);
		}

		if ($kind === 'structure' && ($target['props']['name'] ?? null) !== ($meta['props']['name'] ?? null)) {
			throw new ToolError('structure rows can only move within the same structure field');
		}

		$this->values = self::insertAt($this->values, $target['path'], $target['index'], $node);
		$this->touch($target['path']);
	}

	private function remove(array $op): void
	{
		$key = $this->key($op['ref'] ?? null);
		$path = $this->pathOf($key);

		$this->values = self::removeAt($this->values, $path);
		$this->touch($path);
	}

	/**
	 * Where an insert or move goes: `after` or `before` a node, or `into` a field or node
	 *
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

		$key = $this->key($op[$mode]);
		$path = $this->pathOf($key);
		$index = (int) array_pop($path);
		$meta = $this->nodes[$key];

		if ($meta['kind'] === 'column') {
			throw new ToolError('columns belong to their layout row. Insert blocks `into` the column instead');
		}

		return [
			'path' => $path,
			'index' => $mode === 'after' ? $index + 1 : $index,
			'props' => $meta['props'],
			'kind' => self::containerKind($meta['kind']),
		];
	}

	/**
	 * `into` a top-level field name, or into a node: a nested field (`slot`),
	 * a layout column (`column`), or a column ref
	 *
	 * @return array{path: list<string|int>, index: int|null, props: array<array-key, mixed>, kind: string}
	 */
	private function into(mixed $into, array $op): array
	{
		if (is_string($into) && is_array($this->content->fields[$into] ?? null)) {
			$props = $this->content->fields[$into];

			return ['path' => [$into], 'index' => null, 'props' => $props, 'kind' => self::type($props)];
		}

		$key = $this->key($into);
		$meta = $this->nodes[$key];
		$path = $this->pathOf($key);

		if ($meta['kind'] === 'column') {
			return ['path' => [...$path, 'blocks'], 'index' => null, 'props' => $meta['props'], 'kind' => 'blocks'];
		}

		if ($meta['kind'] === 'layout') {
			$column = $op['column'] ?? null;
			$columns = self::getAt($this->values, [...$path, 'columns']);
			$count = is_array($columns) ? count($columns) : 0;

			if (!is_int($column) || $column < 1 || $column > $count) {
				throw new ToolError("into a layout row, send `column` as a number from 1 to {$count}");
			}

			return [
				'path' => [...$path, 'columns', $column - 1, 'blocks'],
				'index' => null,
				'props' => $meta['props'],
				'kind' => 'blocks',
			];
		}

		$slot = $op['slot'] ?? null;
		$props = is_string($slot) ? $meta['fields'][$slot] ?? null : null;

		if (
			!is_string($slot)
			|| !is_array($props)
			|| !in_array(self::type($props), ['blocks', 'structure', 'layout'], true)
		) {
			$slots = array_keys(array_filter(
				$meta['fields'],
				static fn(mixed $props): bool => (
					is_array($props) && in_array(self::type($props), ['blocks', 'structure', 'layout'], true)
				),
			));

			throw new ToolError(
				'into a '
				. $meta['kind']
				. ', send `slot` with one of: '
				. ($slots === [] ? 'none' : implode(', ', $slots)),
			);
		}

		$base = $meta['kind'] === 'block' ? [...$path, 'content', $slot] : [...$path, $slot];

		return ['path' => $base, 'index' => null, 'props' => $props, 'kind' => self::type($props)];
	}

	/**
	 * @return array{0: array<string, mixed>, 1: array{kind: string, type: string, fields: array<array-key, mixed>, props: array<array-key, mixed>}}
	 */
	private function newBlock(array $props, array $op, array $content): array
	{
		$type = $op['type'] ?? null;
		$types = FieldProps::blockTypes($props);

		if (!is_string($type) || !in_array($type, $types, true)) {
			throw new ToolError('`type` must be one of: ' . implode(', ', $types));
		}

		$fields = FieldProps::fieldset($props, $type);
		self::ensureKnown($content, $fields, "block {$type}");

		return [
			['id' => Str::uuid(), 'type' => $type, 'isHidden' => false, 'content' => $content],
			['kind' => 'block', 'type' => $type, 'fields' => $fields, 'props' => $props],
		];
	}

	/**
	 * @return array{0: array<array-key, mixed>, 1: array{kind: string, type: string, fields: array<array-key, mixed>, props: array<array-key, mixed>}}
	 */
	private function newRow(array $props, array $content): array
	{
		$fields = FieldProps::fields($props);
		$name = is_string($props['name'] ?? null) ? $props['name'] : 'row';
		self::ensureKnown($content, $fields, "row {$name}");

		return [$content, ['kind' => 'row', 'type' => $name, 'fields' => $fields, 'props' => $props]];
	}

	/**
	 * @return array{0: array<string, mixed>, 1: array{kind: string, type: string, fields: array<array-key, mixed>, props: array<array-key, mixed>}}
	 */
	private function newLayoutRow(array $props, array $op, array $content): array
	{
		$layouts = [];

		foreach (is_array($props['layouts'] ?? null) ? $props['layouts'] : [] as $layout) {
			$layouts[] = array_values(array_filter(is_array($layout) ? $layout : [], is_string(...)));
		}

		$columns = $op['columns'] ?? null;

		if (!in_array($columns, $layouts, true)) {
			$options = array_map(static fn(array $layout): string => (string) json_encode($layout), $layouts);

			throw new ToolError('`columns` must be one of: ' . implode(', ', $options));
		}

		$fields = FieldProps::settings($props);
		self::ensureKnown($content, $fields, 'layout row settings');

		return [
			[
				'id' => Str::uuid(),
				'attrs' => $content,
				'columns' => array_map(static fn(mixed $width): array => [
					'id' => Str::uuid(),
					'width' => $width,
					'blocks' => [],
				], $columns),
			],
			['kind' => 'layout', 'type' => 'row', 'fields' => $fields, 'props' => $props],
		];
	}

	/**
	 * A ref number from the read, or the `as` name of a node inserted earlier in this call
	 */
	private function key(mixed $ref): int|string
	{
		if ((is_int($ref) || is_string($ref)) && ($this->nodes[$ref] ?? null) !== null) {
			return $ref;
		}

		if (is_int($ref)) {
			throw new ToolError("no node {$ref}. Read the outline again to get the current numbers");
		}

		throw new ToolError('`ref` must be a number from the outline, or the `as` name of a node inserted before');
	}

	/**
	 * Current path of a node, found by its marker
	 *
	 * @return list<string|int>
	 */
	private function pathOf(int|string $key): array
	{
		$path = self::search($this->values, is_int($key) ? self::REF : self::NEW, $key, []);

		return $path ?? throw new ToolError("node {$key} was removed earlier in this call");
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
	 *
	 * @return list<string|int>|null
	 */
	private static function search(mixed $value, string $marker, int|string $key, array $path): ?array
	{
		if (!is_array($value)) {
			return null;
		}

		if (($value[$marker] ?? null) === $key) {
			return $path;
		}

		foreach ($value as $index => $child) {
			$found = self::search($child, $marker, $key, [...$path, $index]);

			if ($found !== null) {
				return $found;
			}
		}

		return null;
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

	/**
	 * Inserts into the list at the path, at the end without an index
	 *
	 * @param list<string|int> $path
	 */
	private static function insertAt(array $values, array $path, ?int $index, mixed $node): array
	{
		$list = self::getAt($values, $path);
		$list = is_array($list) ? array_values($list) : [];

		array_splice($list, $index ?? count($list), 0, [$node]);

		return self::setAt($values, $path, $list);
	}

	/**
	 * @param list<string|int> $path
	 */
	private static function removeAt(array $values, array $path): array
	{
		$index = array_pop($path);
		$list = self::getAt($values, $path);

		if ($index === null || !is_array($list)) {
			return $values;
		}

		unset($list[$index]);

		return self::setAt($values, $path, array_values($list));
	}

	private static function strip(mixed $value): mixed
	{
		if (!is_array($value)) {
			return $value;
		}

		unset($value[self::REF], $value[self::NEW]);

		return array_map(self::strip(...), $value);
	}

	private static function ensureEditable(string $field, array $props): void
	{
		if (($props['disabled'] ?? false) === true) {
			throw new ToolError("field `{$field}` is read-only");
		}
	}

	/**
	 * @param array<array-key, mixed> $fields
	 */
	private static function ensureKnown(array $content, array $fields, string $where): void
	{
		foreach (array_keys($content) as $field) {
			if (!is_array($fields[$field] ?? null)) {
				throw new ToolError(self::unknownField((string) $field, $fields, $where));
			}
		}
	}

	/**
	 * @param array<array-key, mixed> $fields
	 */
	private static function unknownField(string $field, array $fields, string $where): string
	{
		$names = array_keys($fields);

		return "{$where} has no field `{$field}`. Fields: " . ($names === [] ? 'none' : implode(', ', $names));
	}

	/**
	 * Kind of list that holds a node. Blocks in layout columns have the layout props,
	 * so the kind comes from the node, not from the props.
	 */
	private static function containerKind(string $nodeKind): string
	{
		return match ($nodeKind) {
			'block' => 'blocks',
			'row' => 'structure',
			'layout' => 'layout',
			default => $nodeKind,
		};
	}

	private static function type(array $props): string
	{
		return is_string($props['type'] ?? null) ? $props['type'] : '';
	}
}
