<?php

declare(strict_types=1);

namespace tobimori\Agents\Content;

use Kirby\Cms\App;
use Kirby\Cms\ModelWithContent;
use tobimori\Agents\Schema\FieldProps;

/**
 * Checks input that Kirby changes without an error, because the Panel never sends it:
 * - option fields drop values that are not an option
 * - pages, files, and users fields drop references they cannot find
 */
final class InputCheck
{
	/**
	 * @var list<string>
	 */
	private array $errors = [];

	private function __construct(
		private readonly ModelWithContent $model,
	) {}

	/**
	 * @param array<array-key, mixed> $fields props by field name
	 *
	 * @return list<string>
	 */
	public static function errors(ModelWithContent $model, array $fields, array $values): array
	{
		$check = new self($model);
		$check->fields($fields, $values, '');

		return $check->errors;
	}

	/**
	 * @param array<array-key, mixed> $fields
	 */
	private function fields(array $fields, array $values, string $where): void
	{
		foreach ($fields as $name => $props) {
			if (is_array($props)) {
				$this->field($where . $name, $props, $values[$name] ?? null);
			}
		}
	}

	private function field(string $where, array $props, mixed $value): void
	{
		match ($props['type'] ?? null) {
			'select', 'radio', 'toggles' => $this->options($where, $props, [$value]),
			'checkboxes', 'multiselect' => $this->options($where, $props, is_array($value) ? $value : [$value]),
			'pages', 'files', 'users' => $this->relations(
				$where,
				(string) $props['type'],
				is_array($value) ? $value : [],
			),
			'blocks' => $this->blocks($where, $props, is_array($value) ? $value : []),
			'layout' => $this->layout($where, $props, is_array($value) ? $value : []),
			'structure' => $this->rows($where, $props, is_array($value) ? $value : []),
			'object' => $this->fields(FieldProps::fields($props), is_array($value) ? $value : [], $where . ' > '),
			default => null,
		};
	}

	private function blocks(string $where, array $props, array $blocks): void
	{
		foreach (array_values($blocks) as $index => $block) {
			$type = is_array($block) && is_string($block['type'] ?? null) ? $block['type'] : '';
			$content = is_array($block) && is_array($block['content'] ?? null) ? $block['content'] : [];
			$number = $index + 1;

			$this->fields(FieldProps::fieldset($props, $type), $content, "{$where} > block {$number} ({$type}) > ");
		}
	}

	private function layout(string $where, array $props, array $rows): void
	{
		foreach (array_values($rows) as $r => $row) {
			$row = is_array($row) ? $row : [];
			$number = $r + 1;
			$attrs = is_array($row['attrs'] ?? null) ? $row['attrs'] : [];

			$this->fields(FieldProps::settings($props), $attrs, "{$where} > row {$number} settings > ");

			foreach (array_values(is_array($row['columns'] ?? null) ? $row['columns'] : []) as $c => $column) {
				$blocks = is_array($column) && is_array($column['blocks'] ?? null) ? $column['blocks'] : [];
				$this->blocks("{$where} > row {$number} > column " . ($c + 1), $props, $blocks);
			}
		}
	}

	private function rows(string $where, array $props, array $rows): void
	{
		foreach (array_values($rows) as $index => $row) {
			$this->fields(
				FieldProps::fields($props),
				is_array($row) ? $row : [],
				"{$where} > row " . ($index + 1) . ' > ',
			);
		}
	}

	/**
	 * @param array<array-key, mixed> $given
	 */
	private function options(string $where, array $props, array $given): void
	{
		$options = is_array($props['options'] ?? null) ? $props['options'] : [];
		$allowed = array_column(array_filter($options, is_array(...)), 'value');

		// no options means options from an API or a query the form could not resolve
		if ($allowed === []) {
			return;
		}

		foreach ($given as $item) {
			if ($item !== null && $item !== '' && !in_array($item, $allowed, true)) {
				$this->errors[] =
					"{$where}: "
					. self::json($item)
					. ' is not an option. Options: '
					. implode(', ', array_map(self::json(...), $allowed));
			}
		}
	}

	/**
	 * Items can be ids, UUIDs, or objects with `uuid` or `id`, like content_get returns them
	 *
	 * @param array<array-key, mixed> $items
	 */
	private function relations(string $where, string $type, array $items): void
	{
		$kirby = App::instance();

		foreach ($items as $item) {
			$id = is_array($item) ? $item['uuid'] ?? $item['id'] ?? null : $item;

			if (!is_string($id) || $id === '') {
				$this->errors[] = "{$where}: send UUIDs or ids as strings";

				continue;
			}

			$found = match ($type) {
				'pages' => $kirby->page($id, drafts: true),
				'files' => $kirby->file($id, $this->model),
				default => $kirby->user($id),
			};

			if ($found === null) {
				$this->errors[] = "{$where}: `{$id}` is not a " . rtrim($type, 's') . ' on this site';
			}
		}
	}

	private static function json(mixed $value): string
	{
		return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}
}
