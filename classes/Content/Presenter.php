<?php

declare(strict_types=1);

namespace tobimori\Agents\Content;

use Kirby\Cms\File;
use Kirby\Cms\Page;
use tobimori\Agents\Fields\Fields;
use tobimori\Agents\Tools\ToolError;

/**
 * Shows content to agents: a short outline with ref numbers, or full values
 * where nested nodes carry their ref number instead of their UUID.
 * The field classes show the values of their type.
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

		if ($content->untranslated !== []) {
			$lines[] =
				'Not translated yet, the values are from the default language: '
				. implode(', ', $content->untranslated);
		}

		$width = max(array_map(static fn(int|string $name): int => strlen((string) $name), [
			...array_keys($content->fields),
			'title',
		]));

		foreach ($content->fields as $name => $props) {
			$field = Fields::for(is_array($props) ? $props : []);
			$lines[] =
				str_pad((string) $name, $width) . '  ' . $field->summary($content->values[$name] ?? null, $presenter);

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

			$values[$name] = Fields::for($props)->present($content->values[$name] ?? null, [$name], $presenter);
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
		$data = Fields::for($node->props)->presentNode($node, $presenter->at($node->path), $presenter);

		return [...$presenter->meta(), 'node' => ['kind' => $node->kind, ...$data]];
	}

	/**
	 * Full values of the fields in a node or object
	 *
	 * @param array<array-key, mixed> $fields
	 * @param list<string|int> $path
	 */
	public function values(array $fields, array $values, array $path): array
	{
		$result = [];

		foreach ($values as $name => $value) {
			$props = $fields[$name] ?? null;
			$result[$name] = is_array($props) ? Fields::for($props)->present($value, [...$path, $name], $this) : $value;
		}

		return $result;
	}

	/**
	 * Short values of simple fields: `label "Docs", url "https://…"`
	 *
	 * @param array<array-key, mixed> $fields
	 */
	public function preview(array $fields, array $values, int $limit = 3): string
	{
		$parts = [];
		$prominent = array_filter(
			$fields,
			static fn(mixed $props): bool => is_array($props) && Fields::for($props)->prominent(),
		);

		foreach ($prominent + $fields as $name => $props) {
			$value = $values[$name] ?? null;

			if (in_array($value, [null, '', [], false], true)) {
				continue;
			}

			$preview = Fields::for(is_array($props) ? $props : [])->preview($value, $this);

			if ($preview === null) {
				continue;
			}

			$parts[] = $name . ' ' . $preview;

			if (count($parts) === $limit) {
				break;
			}
		}

		return implode(', ', $parts);
	}

	/**
	 * `ref` number of the node at the path, and the UUID only on request
	 *
	 * @param list<string|int> $path
	 */
	public function ref(array $path, ?array $value): array
	{
		$ref = ['ref' => $this->refs[implode('/', $path)] ?? null];

		if ($this->ids && is_string($value['id'] ?? null)) {
			$ref['id'] = $value['id'];
		}

		return $ref;
	}

	public static function short(mixed $value): string
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

	private function header(): string
	{
		$model = $this->content->model;
		$name = match (true) {
			$model instanceof Page => 'page ' . $model->id() . ', title "' . $this->content->title . '"',
			$model instanceof File => 'file ' . $model->id() . ', template ' . ($model->template() ?? 'default'),
			default => 'site',
		};

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
		$meta = [
			'etag' => $this->content->etag,
			'version' => $this->content->version,
			'language' => $this->content->language,
		];

		return $this->content->untranslated !== [] ? [...$meta, 'untranslated' => $this->content->untranslated] : $meta;
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
			// and indent all its items under the name. Layout columns and their blocks need no name
			if ($node->parent !== null && !in_array($node->field, ['columns', 'blocks'], true)) {
				$slot = $node->parent . '/' . $node->field;

				if (($slots[$slot] ?? null) === null) {
					$lines[] = str_repeat('  ', $depth) . $node->field . ':';
					$slots[$slot] = $depth + 1;
				}

				$depth = $slots[$slot];
				$depths[$node->ref] = $depth;
			}

			$summary = Fields::for($node->props)->nodeSummary($node, $this->at($node->path), $this);
			$lines[] = str_repeat('  ', $depth) . $node->ref . ' ' . $summary;
		}

		return $lines;
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
}
