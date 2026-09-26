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

/**
 * File templates that can be uploaded to a page, like the upload buttons of the Panel:
 * files sections that list its files, and files fields that upload to it
 */
final class Uploads
{
	/**
	 * Name for files without a template
	 */
	public const DEFAULT = 'default';

	/**
	 * @return array<string, array{title: string, from: list<string>, accept: array<array-key, mixed>}> by template
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

			// the role may not upload some templates (`options.create` in the file blueprint)
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
	 * Checks the values for the fields of a new file, required fields included,
	 * and returns them as Kirby stores them
	 *
	 * @param array<string, mixed> $content form values
	 *
	 * @return array<string, mixed>
	 */
	public static function content(File $draft, array $content): array
	{
		$form = Form::for($draft);
		$props = $form->fields()->toProps();
		$unknown = array_diff(array_keys($content), array_keys($props));

		if ($unknown !== []) {
			throw new ToolError(
				'The file template has no field `' . implode('`, `', $unknown) . '`. Fields: '
					. implode(', ', array_keys($props)),
			);
		}

		$form->fill(input: $content);

		// the Panel lets the upload through and shows the errors later, but the file is public at once
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

	/**
	 * Unsaved file with the template, to read its blueprint and permissions
	 */
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
		$files = $parent->files();

		return $template === null ? $files->count() : $files->filter('template', $template)->count();
	}

	/**
	 * Files fields that upload to the parent, also inside blocks, layouts, structures, and objects
	 *
	 * @param array<array-key, mixed> $fields
	 *
	 * @return array<string, string|null> upload template by field path
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
				// `uploads.parent` is a query like the `parent` of a section
				&& Placement::lists($parent, $props['uploads'], $parent)
			) {
				$template = $props['uploads']['template'] ?? null;
				$found[$path] = is_string($template) ? $template : null;
			}

			// block types and layout settings are part of the path, the fields of structures and objects not
			foreach ($field->fieldSets() as $set => $nested) {
				$prefix = $set === '' ? "{$path} > " : "{$path} > {$set} > ";
				$found = [...$found, ...self::fields($parent, $nested, $prefix)];
			}
		}

		return $found;
	}
}
