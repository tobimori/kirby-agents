<?php

declare(strict_types=1);

namespace tobimori\Agents\Content;

use tobimori\Agents\Fields\Field;
use tobimori\Agents\Fields\Fields;
use tobimori\Agents\Tools\ToolError;

final class Editor
{
	private const REF = '__ref';

	private const NEW = '__new';

	// wraps nodes that are not arrays, like entries, so that they can carry a marker
	private const VALUE = '__value';

	/**
	 * @var array<array-key, mixed>
	 */
	private array $values;

	/**
	 * @var array<int|string, array{kind: string, type: string, fields: array<array-key, mixed>, props: array<array-key, mixed>}>
	 */
	private array $nodes = [];

	/**
	 * @var array<string, true>
	 */
	private array $changed = [];

	/**
	 * Nodes in read-only fields, also through a parent
	 *
	 * @var array<int, true>
	 */
	private array $locked = [];

	public function __construct(
		private readonly Reader $content,
	) {
		$this->values = $content->values;

		foreach ($content->nodes as $node) {
			$value = self::getAt($this->values, $node->path);

			if (!is_array($value)) {
				$value = [self::VALUE => $value];
			}

			$value[self::REF] = $node->ref;

			$this->values = self::setAt($this->values, $node->path, $value);

			$inLocked = $node->parent !== null && ($this->locked[$node->parent] ?? false);

			if ($inLocked || ($node->props['disabled'] ?? false) === true) {
				$this->locked[$node->ref] = true;
			}
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

		foreach (array_keys($this->nodes) as $key) {
			$path = is_string($key) ? self::search($this->values, self::NEW, $key, []) : null;

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
		$this->ensureOpen($key);
		$node = $this->nodes[$key];
		$props = $node['fields'][$field] ?? null;

		if (!is_array($props)) {
			throw new ToolError(Field::unknownField($field, $node['fields'], "{$node['kind']} {$ref}"));
		}

		self::ensureEditable($field, $props);

		return [$props, [...$this->pathOf($key), ...Fields::for($node['props'])->contentPath($node['kind']), $field]];
	}

	/**
	 * @param array<array-key, mixed> $props
	 * @param list<string|int> $path
	 */
	private function write(array $props, array $path, mixed $value): void
	{
		$current = self::getAt($this->values, $path);
		$this->values = self::setAt($this->values, $path, Fields::for($props)->input($value, $current));
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
		$content = Field::json($op['content'] ?? null);
		$content = is_array($content) ? $content : [];

		[$node, $meta] = Fields::for($target['props'])->newItem($target['kind'], $op, $content);

		$key = $name ?? '_' . (count($this->nodes) + 1);
		$node[self::NEW] = $key;
		$this->nodes[$key] = $meta;

		$this->values = self::insertAt($this->values, $target['path'], $target['index'], $node);
		$this->touch($target['path']);
	}

	private function move(array $op): void
	{
		$key = $this->key($op['ref'] ?? null);
		$this->ensureOpen($key);
		$from = $this->pathOf($key);
		$node = self::getAt($this->values, $from);
		$meta = $this->nodes[$key];

		$this->values = self::removeAt($this->values, $from);
		$this->touch($from);

		$target = $this->target($op);
		Fields::for($target['props'])->accept($target['kind'], $meta);

		$this->values = self::insertAt($this->values, $target['path'], $target['index'], $node);
		$this->touch($target['path']);
	}

	private function remove(array $op): void
	{
		$key = $this->key($op['ref'] ?? null);
		$this->ensureOpen($key);
		$path = $this->pathOf($key);

		$this->values = self::removeAt($this->values, $path);
		$this->touch($path);
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

		$key = $this->key($op[$mode]);
		$this->ensureOpen($key);
		$path = $this->pathOf($key);
		$index = (int) array_pop($path);
		$meta = $this->nodes[$key];

		return [
			'path' => $path,
			'index' => $mode === 'after' ? $index + 1 : $index,
			'props' => $meta['props'],
			'kind' => $meta['kind'],
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

		$key = $this->key($into);
		$this->ensureOpen($key);
		$meta = $this->nodes[$key];
		$path = $this->pathOf($key);
		$owner = Fields::for($meta['props']);
		$inner = $owner->into($meta['kind'], self::getAt($this->values, $path), $op);

		if ($inner !== null) {
			return [
				'path' => [...$path, ...$inner['path']],
				'index' => null,
				'props' => $meta['props'],
				'kind' => $inner['kind'],
			];
		}

		$slot = $op['slot'] ?? null;
		$props = is_string($slot) ? $meta['fields'][$slot] ?? null : null;
		$kind = is_array($props) ? Fields::for($props)->itemKind() : null;

		if (!is_string($slot) || !is_array($props) || $kind === null) {
			$slots = array_keys(array_filter(
				$meta['fields'],
				static fn(mixed $props): bool => is_array($props) && Fields::for($props)->itemKind() !== null,
			));

			throw new ToolError(
				'into a '
				. $meta['kind']
				. ', send `slot` with one of: '
				. ($slots === [] ? 'none' : implode(', ', $slots)),
			);
		}

		self::ensureEditable($slot, $props);

		return [
			'path' => [...$path, ...$owner->contentPath($meta['kind']), $slot],
			'index' => null,
			'props' => $props,
			'kind' => $kind,
		];
	}

	private function key(mixed $ref): int|string
	{
		if (is_string($ref) && ctype_digit($ref)) {
			$ref = (int) $ref;
		}

		if ((is_int($ref) || is_string($ref)) && ($this->nodes[$ref] ?? null) !== null) {
			return $ref;
		}

		if (is_int($ref)) {
			throw new ToolError("no node {$ref}. Read the outline again to get the current numbers");
		}

		throw new ToolError('`ref` must be a number from the outline, or the `as` name of a node inserted before');
	}

	/**
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

		if (array_key_exists(self::VALUE, $value)) {
			return $value[self::VALUE];
		}

		unset($value[self::REF], $value[self::NEW]);

		return array_map(self::strip(...), $value);
	}

	private function ensureOpen(int|string $key): void
	{
		if ($this->locked[$key] ?? false) {
			throw new ToolError("node {$key} is in a read-only field");
		}
	}

	private static function ensureEditable(string $field, array $props): void
	{
		if (($props['disabled'] ?? false) === true) {
			throw new ToolError("field `{$field}` is read-only");
		}
	}
}
