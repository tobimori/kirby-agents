<?php

declare(strict_types=1);

namespace tobimori\Agents\Lifecycle;

use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Form\Form;
use tobimori\Agents\Content\InputCheck;
use tobimori\Agents\Content\Reader;
use tobimori\Agents\Content\Writer;
use tobimori\Agents\Fields\Fields;
use tobimori\Agents\Fields\FilesField;
use tobimori\Agents\Tools\ToolError;

final class Uploads
{
	public const DEFAULT = 'default';

	/**
	 * @return array<string, array{title: string, from: list<string>, accept: array<array-key, mixed>}>
	 */
	public static function templates(Site|Page $parent): array
	{
		$templates = [];

		foreach (Placement::sections($parent, $parent, 'files') as $section) {
			$template = is_string($section['template'] ?? null) ? $section['template'] : null;
			$max = $section['max'] ?? null;

			if (($section['create'] ?? true) === false || is_int($max) && self::count($parent, $template) >= $max) {
				continue;
			}

			$templates[$template ?? self::DEFAULT][] = 'section ' . (string) ($section['name'] ?? '');
		}

		foreach (self::fields($parent, Reader::read($parent)->fields, '') as $path => $template) {
			$templates[$template ?? self::DEFAULT][] = 'field ' . $path;
		}

		$result = [];

		foreach ($templates as $template => $from) {
			$file = self::draft($parent, (string) $template);

			if ($file->permissions()->can('create')) {
				$result[(string) $template] = [
					'title' => $file->blueprint()->title(),
					'from' => array_values(array_unique($from)),
					'accept' => array_filter(
						$file->blueprint()->accept(),
						static fn(mixed $value): bool => $value !== null,
					),
				];
			}
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $content
	 *
	 * @return array<string, mixed>
	 */
	public static function content(File $draft, array $content): array
	{
		$form = Form::for($draft);
		$props = [];

		foreach ($form->fields() as $name => $field) {
			$props[(string) $name] = Fields::props($field);
		}

		$props = Fields::visible($props);
		$unknown = array_diff(array_keys($content), array_keys($props));

		if ($unknown !== []) {
			throw new ToolError(
				'The file template has no field `' . implode('`, `', $unknown) . '`. Fields: '
					. implode(', ', array_keys($props)),
			);
		}

		$content = Fields::input($props, $content, [], 'the file');
		$form->fill(input: $content);

		$errors = [
			...InputCheck::errors($draft, array_intersect_key($props, $content), $content),
			...array_values(Writer::errors($form->fields())),
		];

		if ($errors !== []) {
			throw new ToolError(
				"No upload link. Invalid or missing values in `content`:\n- " . implode("\n- ", $errors),
			);
		}

		$stored = [];

		foreach ($form->toStoredValues() as $name => $value) {
			if ($value !== null && $value !== '') {
				$stored[(string) $name] = $value;
			}
		}

		return $stored;
	}

	public static function draft(Site|Page $parent, string $template, string $filename = 'upload.tmp'): File
	{
		return new File([
			'filename' => $filename,
			'parent' => $parent,
			'template' => $template === self::DEFAULT ? null : $template,
		]);
	}

	private static function count(Site|Page $parent, ?string $template): int
	{
		return $parent->files()->template($template)->count();
	}

	/**
	 * @param array<array-key, mixed> $fields
	 *
	 * @return array<string, string|null>
	 */
	private static function fields(Site|Page $parent, array $fields, string $where): array
	{
		$found = [];

		foreach ($fields as $name => $props) {
			if (!is_array($props)) {
				continue;
			}

			$path = $where . $name;
			$field = Fields::for($props);

			if (
				$field instanceof FilesField
				&& is_array($props['uploads'] ?? null)
				&& Placement::lists($parent, $props['uploads'], $parent)
			) {
				$template = $props['uploads']['template'] ?? null;
				$found[$path] = is_string($template) ? $template : null;
			}

			foreach ($field->fieldSets() as $set => $nested) {
				$prefix = $set === '' ? "{$path} > " : "{$path} > {$set} > ";
				$found = [...$found, ...self::fields($parent, $nested, $prefix)];
			}
		}

		return $found;
	}
}
