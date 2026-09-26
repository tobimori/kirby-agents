<?php

declare(strict_types=1);

namespace tobimori\Agents\Lifecycle;

use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use tobimori\Agents\Content\Reader;
use tobimori\Agents\Schema\FieldProps;

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
			$type = $props['type'] ?? null;

			if (
				$type === 'files'
				&& is_array($props['uploads'] ?? null)
				// `uploads.parent` is a query like the `parent` of a section
				&& Placement::lists($parent, $props['uploads'], $parent)
			) {
				$template = $props['uploads']['template'] ?? null;
				$found[$path] = is_string($template) ? $template : null;
			}

			$nested = match ($type) {
				'blocks', 'layout' => self::blockFields($parent, $props, $path),
				'structure', 'object' => self::fields($parent, FieldProps::fields($props), $path . ' > '),
				default => [],
			};

			$found = [...$found, ...$nested];
		}

		return $found;
	}

	/**
	 * @return array<string, string|null>
	 */
	private static function blockFields(Site|Page $parent, array $props, string $path): array
	{
		$found = $props['type'] === 'layout'
			? self::fields($parent, FieldProps::settings($props), $path . ' > settings > ')
			: [];

		foreach (FieldProps::blockTypes($props) as $type) {
			$found = [...$found, ...self::fields($parent, FieldProps::fieldset($props, $type), "{$path} > {$type} > ")];
		}

		return $found;
	}
}
