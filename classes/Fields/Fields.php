<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use Kirby\Cms\App;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Form\Field as FormField;
use Kirby\Form\FieldClass;
use Kirby\Plugin\Plugin;
use tobimori\Agents\Agents;

final class Fields
{
	public const EXTENSION = 'tobimori.agents.fields';

	/**
	 * @var array<array-key, mixed>|null
	 */
	private static ?array $registered = null;

	/**
	 * @var array<string, class-string<Field>>
	 */
	private const CORE = [
		'text' => TextField::class,
		'slug' => TextField::class,
		'email' => TextField::class,
		'url' => TextField::class,
		'tel' => TextField::class,
		'password' => TextField::class,
		'textarea' => TextareaField::class,
		'markdown' => TextareaField::class,
		'writer' => WriterField::class,
		'list' => ListField::class,
		'number' => NumberField::class,
		'range' => NumberField::class,
		'toggle' => ToggleField::class,
		'select' => OptionField::class,
		'radio' => OptionField::class,
		'toggles' => OptionField::class,
		'checkboxes' => OptionsField::class,
		'multiselect' => OptionsField::class,
		'tags' => OptionsField::class,
		'date' => DateField::class,
		'time' => TimeField::class,
		'color' => ColorField::class,
		'link' => LinkField::class,
		'hidden' => HiddenField::class,
		'pages' => PagesField::class,
		'files' => FilesField::class,
		'users' => UsersField::class,
		'structure' => StructureField::class,
		'object' => ObjectField::class,
		'entries' => EntriesField::class,
		'blocks' => BlocksField::class,
		'layout' => LayoutField::class,
	];

	/**
	 * @param array<array-key, mixed> $props
	 */
	public static function for(array $props): Field
	{
		$as = self::hint($props, 'as');
		$type = is_string($as) ? $as : $props['type'] ?? '';
		$class = self::find(is_string($type) ? $type : '');

		if ($class === null && is_string($as)) {
			$name = is_string($props['name'] ?? null) ? $props['name'] : '';

			throw new InvalidArgumentException(
				message: "`agents.as` of the field `{$name}`: `{$as}` is not a field type with a class",
			);
		}

		return $class === null ? new CustomField($props) : new $class($props);
	}

	public static function props(FormField|FieldClass $field): array
	{
		$props = $field->toArray();

		// @mago-expect analysis:non-documented-method (Kirby reads unknown keys with __call)
		$agents = $field instanceof FieldClass ? $field->agents() : null;

		if (is_array($agents)) {
			$props['agents'] = $agents;
		}

		return $props;
	}

	/**
	 * @template K of array-key
	 *
	 * @param array<K, mixed> $fields
	 *
	 * @return array<K, mixed>
	 */
	public static function visible(array $fields): array
	{
		return array_filter(
			$fields,
			static fn(mixed $props): bool => !is_array($props) || self::hint($props, 'ignore') !== true,
		);
	}

	/**
	 * @return array<array-key, mixed>
	 */
	private static function registered(): array
	{
		if (self::$registered !== null) {
			return self::$registered;
		}

		$classes = [];

		foreach (App::instance()->plugins() as $plugin) {
			$declared = $plugin instanceof Plugin ? $plugin->extends()[self::EXTENSION] ?? null : null;
			$classes = [...$classes, ...(is_array($declared) ? $declared : [])];
		}

		$option = Agents::option('fields', []);

		return self::$registered = [...$classes, ...(is_array($option) ? $option : [])];
	}

	/**
	 * @return class-string<Field>|null
	 */
	private static function find(string $type): ?string
	{
		$registered = self::registered();

		for ($depth = 0; $depth < 10 && $type !== null; $depth++) {
			$class = $registered[$type] ?? self::CORE[$type] ?? null;

			if ($class === null) {
				$type = self::extended($type);

				continue;
			}

			if (!is_string($class) || !is_subclass_of($class, Field::class)) {
				throw new InvalidArgumentException(
					message: 'The class for the field type `'
					. $type
					. '` (option or plugin key '
					. self::EXTENSION
					. ') must exist and extend '
					. Field::class,
				);
			}

			return $class;
		}

		return null;
	}

	private static function extended(string $type): ?string
	{
		$definition = FormField::$types[$type] ?? null;

		if (is_string($definition) && class_exists($definition)) {
			$parents = class_parents($definition);

			foreach ($parents === false ? [] : $parents as $parent) {
				if (str_starts_with($parent, 'Kirby\\Form\\Field\\')) {
					return lcfirst(substr(basename(str_replace('\\', '/', $parent)), 0, -5));
				}
			}

			return null;
		}

		if (is_string($definition) || is_array($definition)) {
			$definition = FormField::load($type);
		}

		return is_array($definition) && is_string($definition['extends'] ?? null) ? $definition['extends'] : null;
	}

	/**
	 * @param array<array-key, mixed> $props
	 */
	public static function hint(array $props, string $key): mixed
	{
		return is_array($props['agents'] ?? null) ? $props['agents'][$key] ?? null : null;
	}
}
