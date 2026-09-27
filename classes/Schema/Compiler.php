<?php

declare(strict_types=1);

namespace tobimori\Agents\Schema;

use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Form\Form;
use tobimori\Agents\Fields\Field;
use tobimori\Agents\Fields\Fields;

final class Compiler
{
	/**
	 * @var array<string, array<string, string>>
	 */
	private array $types = [];

	public static function compile(ModelWithContent $model): Schema
	{
		$compiler = new self();
		$fields = [];

		$props = [];

		foreach (Form::for($model)->fields() as $name => $field) {
			if ($field->hasValue()) {
				$props[(string) $name] = Fields::props($field);
			}
		}

		foreach (Fields::visible($props) as $name => $field) {
			$fields[(string) $name] = $compiler->describe(is_array($field) ? $field : []);
		}

		$blueprint = basename($model->blueprint()->name());
		$title = match (true) {
			$model instanceof File && $model->exists() => 'file ' . $model->id() . ' (blueprint: ' . $blueprint . ')',
			$model instanceof File => 'file blueprint ' . $blueprint,
			!$model instanceof Page => 'site',
			$model->exists() => 'page ' . $model->id() . ' (blueprint: ' . $blueprint . ')',
			default => 'blueprint ' . $blueprint,
		};

		return new Schema($title, $fields, $compiler->types);
	}

	public static function line(array $props): string
	{
		return (new self())->describe($props);
	}

	public function expression(array $props): string
	{
		return Fields::for($props)->describe($this);
	}

	/**
	 * @param array<array-key, mixed> $fields
	 */
	public function type(string $kind, string $name, array $fields): string
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
				$conditions[] = $field . ' = ' . Field::quote($value);
			}

			$parts[] = 'only if ' . implode(' and ', $conditions);
		}

		if ($label !== '' && strtolower($label) !== strtolower(str_replace(['_', '-'], ' ', $name))) {
			$parts[] = 'label "' . $label . '"';
		}

		if (is_string($props['help'] ?? null) && $props['help'] !== '') {
			$parts[] = 'help: ' . self::shorten(strip_tags($props['help']), 100);
		}

		$description = Fields::hint($props, 'description');

		if (is_string($description) && $description !== '') {
			$parts[] = 'note: ' . self::shorten($description, 500);
		}

		$example = Fields::hint($props, 'example');

		if ($example !== null) {
			$parts[] = 'example ' . Field::quote($example);
		}

		return implode(', ', $parts);
	}

	private static function shorten(string $text, int $length): string
	{
		$text = trim((string) preg_replace('/\s+/', ' ', $text));

		return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
	}
}
