<?php

declare(strict_types=1);

namespace tobimori\Agents\Content;

use Kirby\Cms\Page;
use tobimori\Agents\Schema\FieldProps;
use tobimori\Agents\Tools\ToolError;

/**
 * Shows content to agents: a short outline with ref numbers, or full values
 * where nested nodes carry their ref number instead of their UUID
 */
final class Presenter
{
	private const PREVIEW = 60;

	/**
	 * @var array<string, int> node path => ref
	 */
	private array $refs = [];

	private function __construct(
		private readonly Reader $content,
		private readonly bool $ids,
	) {
		foreach ($content->nodes as $node) {
			$this->refs[$node->key()] = $node->ref;
		}
	}

	public static function outline(Reader $content): string
	{
		$presenter = new self($content, ids: false);
		$lines = [$presenter->header()];
		$width = max(array_map(static fn(int|string $name): int => strlen((string) $name), [
			...array_keys($content->fields),
			'title',
		]));

		foreach ($content->fields as $name => $props) {
			$value = $content->values[$name] ?? null;
			$lines[] =
				str_pad((string) $name, $width) . '  ' . $presenter->summary(is_array($props) ? $props : [], $value);

			foreach ($presenter->nodeLines((string) $name) as $line) {
				$lines[] = $line;
			}
		}

		return implode("\n", $lines);
	}

	/**
	 * Full values of some fields
	 *
	 * @param list<string> $names
	 */
	public static function fields(Reader $content, array $names, bool $ids): array
	{
		$presenter = new self($content, $ids);
		$values = [];

		foreach ($names as $name) {
			$props = $content->fields[$name] ?? null;

			if (!is_array($props)) {
				throw new ToolError("No field `{$name}`. Fields: " . implode(', ', array_keys($content->fields)));
			}

			$values[$name] = $presenter->value($props, $content->values[$name] ?? null, [$name]);
		}

		return [...$presenter->meta(), 'fields' => $values];
	}

	/**
	 * Full values of one node
	 */
	public static function node(Reader $content, int $ref, bool $ids): array
	{
		$presenter = new self($content, $ids);
		$node = $content->node($ref);
		$value = $presenter->at($node->path);

		$data = match ($node->kind) {
			'block' => $presenter->block($node->path, $node->fields, $value),
			'layout' => $presenter->layoutRow($node->path, $node->props, $value),
			'column' => $presenter->column($node->path, $node->props, $value),
			'row' => $presenter->row($node->path, $node->fields, $value),
			default => ['ref' => $ref, 'value' => $value],
		};

		return [...$presenter->meta(), 'node' => ['kind' => $node->kind, ...$data]];
	}

	private function header(): string
	{
		$model = $this->content->model;
		$name = $model instanceof Page ? 'page ' . $model->id() . ', title "' . (string) $model->title() . '"' : 'site';

		return (
			$name
			. ', version '
			. $this->content->version
			. ', language '
			. $this->content->language
			. ', etag '
			. $this->content->etag
		);
	}

	private function meta(): array
	{
		return [
			'etag' => $this->content->etag,
			'version' => $this->content->version,
			'language' => $this->content->language,
		];
	}

	/**
	 * One line per field in the outline
	 */
	private function summary(array $props, mixed $value): string
	{
		$type = is_string($props['type'] ?? null) ? $props['type'] : '';

		return match ($type) {
			'blocks', 'layout', 'structure', 'entries' => $type
				. ', '
				. self::items(is_array($value) ? count($value) : 0),
			'date' => self::short($this->value($props, $value, [])),
			'object' => 'object ' . $this->preview(FieldProps::fields($props), is_array($value) ? $value : []),
			'pages', 'files', 'users' => $this->relations($value),
			default => self::short($value),
		};
	}

	/**
	 * Outline lines for all nodes of one top-level field, indented by nesting
	 *
	 * @return list<string>
	 */
	private function nodeLines(string $field): array
	{
		$lines = [];
		$depths = [];
		$slots = [];

		foreach ($this->content->nodes as $node) {
			if ($node->path[0] !== $field) {
				continue;
			}

			$depth = $node->parent !== null ? ($depths[$node->parent] ?? 0) + 1 : 1;
			$depths[$node->ref] = $depth;

			// name the nested field once, for example `left` in a columns block,
			// and indent all its items under the name
			if ($node->parent !== null && !in_array($node->field, ['columns', 'blocks'], true)) {
				$slot = $node->parent . '/' . $node->field;

				if (($slots[$slot] ?? null) === null) {
					$lines[] = str_repeat('  ', $depth) . $node->field . ':';
					$slots[$slot] = $depth + 1;
				}

				$depth = $slots[$slot];
				$depths[$node->ref] = $depth;
			}

			$lines[] = str_repeat('  ', $depth) . $node->ref . ' ' . $this->nodeSummary($node);
		}

		return $lines;
	}

	private function nodeSummary(Node $node): string
	{
		$value = $this->at($node->path);
		$value = is_array($value) ? $value : [];

		return match ($node->kind) {
			'block' => trim(
				$node->type
				. ' '
				. $this->preview($node->fields, is_array($value['content'] ?? null) ? $value['content'] : [], 2)
				. (($value['isHidden'] ?? false) === true ? ' (hidden)' : ''),
			),
			'layout' => trim(
				'row ' . $this->preview($node->fields, is_array($value['attrs'] ?? null) ? $value['attrs'] : []),
			),
			'column' => 'column ' . $node->type,
			'row' => $this->preview($node->fields, $value),
			default => self::short($this->at($node->path)),
		};
	}

	/**
	 * Short values of simple fields: `label "Docs", url "https://…"`
	 *
	 * @param array<array-key, mixed> $fields
	 */
	private function preview(array $fields, array $values, int $limit = 3): string
	{
		$parts = [];

		// text fields say more about an item than options or flags
		$texts = array_filter(
			$fields,
			static fn(mixed $props): bool => (
				is_array($props)
				&& in_array($props['type'] ?? null, ['text', 'writer', 'textarea', 'markdown', 'list'], true)
			),
		);

		foreach ($texts + $fields as $name => $props) {
			$value = $values[$name] ?? null;
			$type = is_array($props) ? $props['type'] ?? null : null;

			if (
				in_array($type, ['blocks', 'layout', 'structure', 'object', 'entries'], true)
				|| in_array($value, [null, '', [], false], true)
			) {
				continue;
			}

			$parts[] = $name . ' ' . self::short($value);

			if (count($parts) === $limit) {
				break;
			}
		}

		return implode(', ', $parts);
	}

	/**
	 * Full value with nested nodes as `ref` numbers
	 *
	 * @param list<string|int> $path
	 */
	private function value(array $props, mixed $value, array $path): mixed
	{
		if (!is_array($value)) {
			return self::scalar($props, $value);
		}

		return match ($props['type'] ?? null) {
			'blocks' => array_map(fn(int|string $i): array => $this->block(
				[...$path, $i],
				FieldProps::fieldset($props, self::type($value[$i])),
				$value[$i],
			), array_keys($value)),
			'layout' => array_map(fn(int|string $i): array => $this->layoutRow(
				[...$path, $i],
				$props,
				$value[$i],
			), array_keys($value)),
			'structure' => array_map(fn(int|string $i): array => $this->row(
				[...$path, $i],
				FieldProps::fields($props),
				$value[$i],
			), array_keys($value)),
			'object' => $this->values(FieldProps::fields($props), $value, $path),
			'pages', 'files', 'users' => array_map(self::relation(...), $value),
			default => $value,
		};
	}

	/**
	 * Values that are not arrays. A date without time shows only the date.
	 */
	private static function scalar(array $props, mixed $value): mixed
	{
		return match (true) {
			($props['type'] ?? null) === 'date' && ($props['time'] ?? false) === false && is_string($value) => substr(
				$value,
				0,
				10,
			),
			default => $value,
		};
	}

	/**
	 * @param array<array-key, mixed> $fields
	 * @param list<string|int> $path
	 */
	private function values(array $fields, array $values, array $path): array
	{
		$result = [];

		foreach ($values as $name => $value) {
			$props = $fields[$name] ?? null;
			$result[$name] = is_array($props) ? $this->value($props, $value, [...$path, $name]) : $value;
		}

		return $result;
	}

	/**
	 * @param list<string|int> $path
	 * @param array<array-key, mixed> $fields
	 */
	private function block(array $path, array $fields, mixed $block): array
	{
		$block = is_array($block) ? $block : [];
		$content = is_array($block['content'] ?? null) ? $block['content'] : [];

		return array_filter(
			[
				...$this->ref($path, $block),
				'type' => self::type($block),
				'hidden' => ($block['isHidden'] ?? false) === true ? true : null,
				'content' => $this->values($fields, $content, [...$path, 'content']),
			],
			static fn(mixed $value): bool => $value !== null,
		);
	}

	/**
	 * @param list<string|int> $path
	 */
	private function layoutRow(array $path, array $props, mixed $row): array
	{
		$row = is_array($row) ? $row : [];
		$columns = is_array($row['columns'] ?? null) ? $row['columns'] : [];

		return [
			...$this->ref($path, $row),
			'settings' => $this->values(
				FieldProps::settings($props),
				is_array($row['attrs'] ?? null) ? $row['attrs'] : [],
				[...$path, 'attrs'],
			),
			'columns' => array_map(fn(int|string $c): array => $this->column(
				[...$path, 'columns', $c],
				$props,
				$columns[$c],
			), array_keys($columns)),
		];
	}

	/**
	 * @param list<string|int> $path
	 */
	private function column(array $path, array $props, mixed $column): array
	{
		$column = is_array($column) ? $column : [];
		$blocks = is_array($column['blocks'] ?? null) ? $column['blocks'] : [];

		return [
			...$this->ref($path, $column),
			'width' => $column['width'] ?? '1/1',
			'blocks' => array_map(fn(int|string $b): array => $this->block(
				[...$path, 'blocks', $b],
				FieldProps::fieldset($props, self::type($blocks[$b])),
				$blocks[$b],
			), array_keys($blocks)),
		];
	}

	/**
	 * @param list<string|int> $path
	 * @param array<array-key, mixed> $fields
	 */
	private function row(array $path, array $fields, mixed $row): array
	{
		return [...$this->ref($path, null), ...$this->values($fields, is_array($row) ? $row : [], $path)];
	}

	/**
	 * `ref` number, and the UUID only on request
	 *
	 * @param list<string|int> $path
	 */
	private function ref(array $path, ?array $value): array
	{
		$ref = ['ref' => $this->refs[implode('/', $path)] ?? null];

		if ($this->ids && is_string($value['id'] ?? null)) {
			$ref['id'] = $value['id'];
		}

		return $ref;
	}

	/**
	 * @param list<string|int> $path
	 */
	private function at(array $path): mixed
	{
		$value = $this->content->values;

		foreach ($path as $key) {
			$value = is_array($value) ? $value[$key] ?? null : null;
		}

		return $value;
	}

	private function relations(mixed $value): string
	{
		$items = is_array($value) ? array_map(self::relation(...), $value) : [];

		return $items === []
			? '(empty)'
			: implode(', ', array_map(
				static fn(array $item): string => '"'
				. $item['title']
				. '" '
				. implode(' ', array_filter([$item['id'], $item['uuid']])),
				$items,
			));
	}

	/**
	 * @return array{uuid: string|null, id: string|null, title: string}
	 */
	private static function relation(mixed $item): array
	{
		$item = is_array($item) ? $item : [];

		return [
			'uuid' => is_string($item['uuid'] ?? null) ? $item['uuid'] : null,
			'id' => is_string($item['id'] ?? null) ? $item['id'] : null,
			'title' => is_string($item['text'] ?? null) ? $item['text'] : '',
		];
	}

	private static function items(int $count): string
	{
		return $count === 1 ? '1 item' : $count . ' items';
	}

	private static function type(mixed $block): string
	{
		return is_array($block) && is_string($block['type'] ?? null) ? $block['type'] : 'unknown';
	}

	private static function short(mixed $value): string
	{
		if ($value === null || $value === '' || $value === []) {
			return '(empty)';
		}

		if (!is_string($value)) {
			return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}

		$text = trim((string) preg_replace('/\s+/', ' ', strip_tags($value)));

		return '"' . (mb_strlen($text) > self::PREVIEW ? mb_substr($text, 0, self::PREVIEW - 1) . '…' : $text) . '"';
	}
}
