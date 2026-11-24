<?php

declare(strict_types=1);

namespace tobimori\Agents\Lifecycle;

use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Content\MemoryStorage;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Form\Field\FileListField;
use Kirby\Form\Fields as FormFields;
use Kirby\Form\Form;
use Throwable;
use tobimori\Agents\Content\InputCheck;
use tobimori\Agents\Content\Models;
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

		foreach (FormFields::for($parent) as $name => $field) {
			if (!$field instanceof FileListField) {
				continue;
			}

			try {
				$listed = $field->parentModel();
			} catch (InvalidArgumentException) {
				continue;
			}

			if (Models::is($listed, $parent) && $field->upload() !== false) {
				$templates[$field->blueprint() ?? self::DEFAULT][] = 'list ' . $name;
			}
		}

		foreach (self::fields($parent, Reader::read($parent)->fields, '') as $path => $template) {
			$templates[$template ?? self::DEFAULT][] = 'field ' . $path;
		}

		$result = [];

		foreach ($templates as $template => $from) {
			$file = self::draft($parent, $template);

			if ($file->guards()->isAvailable('create')) {
				$result[$template] = [
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
		$props = Fields::visible(Fields::props($form, $draft));
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
				$stored[$name] = $value;
			}
		}

		return $stored;
	}

	/**
	 * A placeholder file that has no content, also when a real file or its metadata has the same name.
	 * It keeps the name: blueprint queries can depend on it
	 */
	public static function draft(Site|Page $parent, string $template, string $filename = 'upload.tmp'): File
	{
		$file = new File([
			'filename' => $filename,
			'parent' => $parent,
			'template' => $template === self::DEFAULT ? null : $template,
		]);

		// Kirby copies the content from the disk into memory, so remove it there. Nothing is written
		$file->changeStorage(MemoryStorage::class, copy: true);
		$storage = $file->storage();

		foreach ($storage->all() as $version => $language) {
			$storage->delete($version, $language);
		}

		return $file;
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
			$uploads = $props['uploads'] ?? null;

			if (
				$field instanceof FilesField
				&& is_array($uploads)
				&& self::uploadsTo($parent, $uploads['parent'] ?? null)
			) {
				$template = $uploads['blueprint'] ?? null;
				$found[$path] = is_string($template) ? $template : null;
			}

			foreach ($field->fieldSets() as $set => $nested) {
				$prefix = $set === '' ? "{$path} > " : "{$path} > {$set} > ";
				$found = [...$found, ...self::fields($parent, $nested, $prefix)];
			}
		}

		return $found;
	}

	/**
	 * Like Kirby, which uploads to the files of the parent query, or to the parent of a file
	 */
	private static function uploadsTo(Site|Page $parent, mixed $query): bool
	{
		try {
			$target = is_string($query) && $query !== '' ? $parent->query($query) : $parent;
		} catch (Throwable) {
			return false;
		}

		return Models::is($target instanceof File ? $target->parent() : $target, $parent);
	}
}
