<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use Kirby\Cms\App;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Form\Field as FormField;
use Kirby\Form\FieldClass;
use Kirby\Plugin\Plugin;
use tobimori\Agents\Agents;

/**
 * Finds the class for a field type: from the option `fields`, from other plugins,
 * from the core types, or CustomField for types that no class knows.
 *
 * Plugins declare classes for their field types in their plugin definition. Kirby ignores the key
 * without Kirby Agents, and the class is only loaded when Kirby Agents uses it:
 * `'tobimori.agents.fields' => ['alt-text' => AltTextAgentField::class]`
 */
final class Fields
{
	/**
	 * Key in the plugin definition of other plugins
	 */
	public const EXTENSION = 'tobimori.agents.fields';

	/**
	 * @var array<array-key, mixed>|null classes by type, from the site option and all plugins
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
	 * The class for a field. The blueprint hint `agents.as` uses the class of another type,
	 * for example `as: number` for a custom rating field.
	 *
	 * @param array<array-key, mixed> $props field props from the Kirby form
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

	/**
	 * Props of a top-level form field, with the blueprint hints
	 */
	public static function props(FormField|FieldClass $field): array
	{
		$props = $field->toArray();

		// class-based fields (blocks, layout, entries) leave unknown blueprint keys out of `toArray()`.
		// Nested in other fields, Kirby only keeps `toArray()`, so hints on them get lost there
		// @mago-expect analysis:non-documented-method (Kirby reads unknown keys with __call)
		$agents = $field instanceof FieldClass ? $field->agents() : null;

		if (is_array($agents)) {
			$props['agents'] = $agents;
		}

		return $props;
	}

	/**
	 * Fields without the hint `agents.ignore: true`
	 *
	 * @template K of array-key
	 *
	 * @param array<K, mixed> $fields props by field name
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
	 * Field classes from other plugins and from the site option. The site option wins,
	 * so a site can replace the class of a plugin
	 *
	 * @return array<array-key, mixed> classes by type
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
	 * The class for a type: registered by a plugin or the site, from the core types,
	 * or the class of the type it extends in Kirby, like `writer` for a `seo-writer` field
	 *
	 * @return class-string<Field>|null
	 */
	private static function find(string $type): ?string
	{
		$registered = self::registered();

		// a limit, in case field definitions extend each other in a loop
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

	/**
	 * The type that a field type of a plugin extends: `extends` in an array definition,
	 * or the parent class of a class-based field, like Kirby's BlocksField
	 */
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

		// definitions in a file are loaded on first use
		if (is_string($definition) || is_array($definition)) {
			$definition = FormField::load($type);
		}

		return is_array($definition) && is_string($definition['extends'] ?? null) ? $definition['extends'] : null;
	}

	/**
	 * A blueprint hint: a key under `agents` in the field definition
	 *
	 * @param array<array-key, mixed> $props
	 */
	public static function hint(array $props, string $key): mixed
	{
		return is_array($props['agents'] ?? null) ? $props['agents'][$key] ?? null : null;
	}
}
