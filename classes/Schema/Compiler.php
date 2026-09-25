<?php

declare(strict_types=1);

namespace tobimori\Agents\Schema;

use Closure;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Form\Form;
use tobimori\Agents\Agents;

/**
 * Turns the Kirby form of a page or the site into a Schema.
 * The form layer resolves `extends`, tabs, sections, custom fields, and option queries.
 */
final class Compiler
{
	private const WRITER_MARKS = [
		'bold' => 'strong',
		'italic' => 'em',
		'underline' => 'u',
		'strike' => 's',
		'code' => 'code',
		'link' => 'a',
		'email' => 'a href="mailto:…"',
		'sup' => 'sup',
		'sub' => 'sub',
	];

	private const WRITER_NODES = [
		'paragraph' => 'p',
		'heading' => 'h1-h6',
		'bulletList' => 'ul',
		'orderedList' => 'ol',
		'quote' => 'blockquote',
	];

	private const MAX_OPTIONS = 30;

	/**
	 * @var array<string, array<string, string>>
	 */
	private array $types = [];

	public static function compile(ModelWithContent $model): Schema
	{
		$compiler = new self();
		$fields = [];

		foreach (Form::for($model)->fields() as $name => $field) {
			if ($field->hasValue()) {
				$fields[(string) $name] = $compiler->describe($field->toArray());
			}
		}

		$blueprint = basename($model->blueprint()->name());
		$title = match (true) {
			!$model instanceof Page => 'site',
			$model->exists() => 'page ' . $model->id() . ' (blueprint: ' . $blueprint . ')',
			default => 'blueprint ' . $blueprint,
		};

		return new Schema($title, $fields, $compiler->types);
	}

	/**
	 * One line for one field: type expression, then constraints
	 */
	private function describe(array $props): string
	{
		$parts = [$this->expression($props)];
		$name = is_string($props['name'] ?? null) ? $props['name'] : '';
		$label = is_string($props['label'] ?? null) ? $props['label'] : '';

		if (($props['required'] ?? false) === true) {
			$parts[] = 'required';
		}

		if (($props['disabled'] ?? false) === true) {
			$parts[] = 'read-only';
		}

		if (($props['translate'] ?? true) === false) {
			$parts[] = 'same in all languages';
		}

		if (is_array($props['when'] ?? null) && $props['when'] !== []) {
			$conditions = [];

			foreach ($props['when'] as $field => $value) {
				$conditions[] = $field . ' = ' . self::value($value);
			}

			$parts[] = 'only if ' . implode(' and ', $conditions);
		}

		if ($label !== '' && strtolower($label) !== strtolower(str_replace(['_', '-'], ' ', $name))) {
			$parts[] = 'label "' . $label . '"';
		}

		if (is_string($props['help'] ?? null) && $props['help'] !== '') {
			$parts[] = 'help: ' . self::shorten(strip_tags($props['help']), 100);
		}

		return implode(', ', $parts);
	}

	private function expression(array $props): string
	{
		$type = is_string($props['type'] ?? null) ? $props['type'] : 'unknown';
		$adapters = Agents::option('fields', []);
		$adapter = is_array($adapters) ? $adapters[$type] ?? null : null;

		if ($adapter instanceof Closure) {
			return (string) $adapter($props);
		}

		return match ($type) {
			'text', 'slug', 'email', 'url', 'tel', 'password' => $type . self::length($props),
			'textarea' => 'textarea, KirbyText with Markdown' . self::length($props),
			'markdown' => 'markdown' . self::length($props),
			'writer' => self::writer($props),
			'list' => 'html list, <ul> or <ol> with <li>',
			'number', 'range' => 'number' . self::range($props),
			'toggle' => 'boolean',
			'select', 'radio', 'toggles' => 'one of ' . self::options($props),
			'checkboxes', 'multiselect', 'tags' => 'list of ' . self::options($props) . self::count($props),
			'date' => ($props['time'] ?? false) !== false ? 'date "YYYY-MM-DD HH:MM:SS"' : 'date "YYYY-MM-DD"',
			'time' => 'time "HH:MM:SS"',
			'color' => 'color, ' . (is_string($props['format'] ?? null) ? $props['format'] : 'hex'),
			'link' => 'link: URL, page://uuid, file://uuid, mailto:, tel:, or #anchor',
			'files', 'pages', 'users' => $type . ', list of UUIDs' . self::count($props) . self::query($props),
			'structure' => 'structure<' . $this->register('row', $props) . '>' . self::count($props),
			'object' => 'object<' . $this->register('object', $props) . '>',
			'entries' => 'entries<'
				. $this->expression(is_array($props['field'] ?? null) ? $props['field'] : [])
				. '>'
				. self::count($props),
			'blocks' => 'blocks<' . $this->fieldsets($props) . '>' . self::count($props),
			'layout' => $this->layout($props),
			'hidden' => 'hidden, keep the value',
			default => self::custom($type, $props),
		};
	}

	/**
	 * Registers each fieldset as `block <type>` and returns `a | b | c`
	 */
	private function fieldsets(array $props): string
	{
		$names = [];

		foreach (FieldProps::blockTypes($props) as $type) {
			$names[] = $this->addType('block', $type, FieldProps::fieldset($props, $type));
		}

		return implode(' | ', $names);
	}

	private function layout(array $props): string
	{
		$layouts = [];

		foreach (is_array($props['layouts'] ?? null) ? $props['layouts'] : [] as $columns) {
			$layouts[] = implode(' ', array_filter(is_array($columns) ? $columns : [], is_string(...)));
		}

		$expression =
			'layout, rows with columns ' . implode(' | ', $layouts) . ', blocks<' . $this->fieldsets($props) . '>';
		if (is_array($props['settings'] ?? null)) {
			$settings = $this->addType('settings', self::name($props), FieldProps::settings($props));
			$expression .= ', row settings<' . $settings . '>';
		}

		return $expression;
	}

	/**
	 * Registers the sub-fields of a structure or object under the field name
	 */
	private function register(string $kind, array $props): string
	{
		return $this->addType($kind, self::name($props), FieldProps::fields($props));
	}

	/**
	 * Adds a named type once. The same name with other fields gets a number: `links2`.
	 */
	private function addType(string $kind, string $name, array $fields): string
	{
		$lines = [];

		foreach ($fields as $field => $props) {
			if (is_array($props) && ($props['saveable'] ?? true) !== false) {
				$lines[(string) $field] = $this->describe($props);
			}
		}

		$unique = $name;
		$number = 2;

		while (($this->types["{$kind} {$unique}"] ?? $lines) !== $lines) {
			$unique = $name . $number++;
		}

		$this->types["{$kind} {$unique}"] = $lines;

		return $unique;
	}

	private static function writer(array $props): string
	{
		$marks = self::enabled(
			$props['marks'] ?? null,
			['bold', 'italic', 'underline', 'strike', 'link', 'email'],
			self::WRITER_MARKS,
		);
		$tags = array_map(static fn(string $mark): string => self::WRITER_MARKS[$mark] ?? $mark, $marks);

		if (($props['inline'] ?? false) === true) {
			return 'inline html without <p>, tags: ' . self::tags($tags) . self::length($props);
		}

		// outside inline mode, the writer always wraps text in paragraphs
		$nodes = self::enabled(
			$props['nodes'] ?? null,
			['paragraph', 'heading', 'bulletList', 'orderedList'],
			self::WRITER_NODES,
		);
		$nodes = $nodes === [] ? ['paragraph'] : $nodes;
		$blocks = array_map(static fn(string $node): string => self::WRITER_NODES[$node] ?? $node, $nodes);

		return 'html, blocks: ' . self::tags($blocks) . ', inline: ' . self::tags($tags) . self::length($props);
	}

	/**
	 * `null` means the defaults, `true` all, `false` none
	 *
	 * @param list<string> $defaults
	 * @param array<string, string> $all
	 *
	 * @return list<string>
	 */
	private static function enabled(mixed $value, array $defaults, array $all): array
	{
		return match (true) {
			$value === true => array_keys($all),
			$value === false => [],
			is_array($value) => array_values(array_filter($value, is_string(...))),
			default => $defaults,
		};
	}

	/**
	 * @param list<string> $tags
	 */
	private static function tags(array $tags): string
	{
		return $tags === [] ? 'none' : implode(' ', array_map(static fn(string $tag): string => "<{$tag}>", $tags));
	}

	private static function options(array $props): string
	{
		$options = is_array($props['options'] ?? null) ? $props['options'] : [];

		if ($options === []) {
			return 'strings';
		}

		$values = [];

		foreach (array_slice($options, 0, self::MAX_OPTIONS) as $option) {
			$value = is_array($option) ? $option['value'] ?? null : $option;
			$text = is_array($option) && is_string($option['text'] ?? null) ? $option['text'] : null;
			$differs = $text !== null && is_string($value) && strtolower($text) !== strtolower($value);
			$values[] = self::value($value) . ($differs ? ' (' . $text . ')' : '');
		}

		$more = count($options) - self::MAX_OPTIONS;

		return implode(' | ', $values) . ($more > 0 ? " | … {$more} more" : '');
	}

	private static function custom(string $type, array $props): string
	{
		$parts = ["custom field \"{$type}\""];

		if (is_array($props['options'] ?? null) && $props['options'] !== []) {
			$parts[] = 'options ' . self::options($props);
		}

		foreach (['min', 'max', 'step', 'minlength', 'maxlength'] as $key) {
			if (is_int($props[$key] ?? null) || is_float($props[$key] ?? null)) {
				$parts[] = $key . ' ' . $props[$key];
			}
		}

		if (array_key_exists('default', $props) && $props['default'] !== null && $props['default'] !== '') {
			$parts[] = 'default ' . self::value($props['default']);
		}

		// top-level fields carry their current value, which shows the value type
		if (array_key_exists('value', $props) && $props['value'] !== null) {
			$parts[] = 'value ' . get_debug_type($props['value']);
		}

		return implode(', ', $parts);
	}

	private static function length(array $props): string
	{
		$min = is_int($props['minlength'] ?? null) ? $props['minlength'] : null;
		$max = is_int($props['maxlength'] ?? null) ? $props['maxlength'] : null;

		return match (true) {
			$min !== null && $max !== null => ", {$min} to {$max} characters",
			$max !== null => ", max {$max} characters",
			$min !== null => ", min {$min} characters",
			default => '',
		};
	}

	private static function range(array $props): string
	{
		$parts = [];

		foreach (['min', 'max', 'step'] as $key) {
			if (is_int($props[$key] ?? null) || is_float($props[$key] ?? null)) {
				$parts[] = $key . ' ' . $props[$key];
			}
		}

		return $parts === [] ? '' : ', ' . implode(', ', $parts);
	}

	/**
	 * Number of items for list-like fields
	 */
	private static function count(array $props): string
	{
		$min = is_int($props['min'] ?? null) ? $props['min'] : null;
		$max = is_int($props['max'] ?? null) ? $props['max'] : null;

		return match (true) {
			$min !== null && $max !== null => ", {$min} to {$max} items",
			$max !== null => ", max {$max}",
			$min !== null => ", min {$min}",
			default => '',
		};
	}

	private static function query(array $props): string
	{
		return is_string($props['query'] ?? null) ? ', from ' . $props['query'] : '';
	}

	private static function name(array $props): string
	{
		return is_string($props['name'] ?? null) ? $props['name'] : 'item';
	}

	private static function value(mixed $value): string
	{
		return is_string($value) ? '"' . $value . '"' : (string) json_encode($value);
	}

	private static function shorten(string $text, int $length): string
	{
		$text = trim((string) preg_replace('/\s+/', ' ', $text));

		return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
	}
}
