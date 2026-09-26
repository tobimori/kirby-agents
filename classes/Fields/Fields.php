<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use Kirby\Exception\InvalidArgumentException;
use Kirby\Form\Field as FormField;
use Kirby\Form\FieldClass;
use tobimori\Agents\Agents;

/**
 * Finds the class for a field type: from the option `fields`, from the core types,
 * or CustomField for types that no class knows
 */
final class Fields
{
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
		$type = is_string($type) ? $type : '';
		$custom = Agents::option('fields', []);
		$class = (is_array($custom) ? $custom[$type] ?? null : null) ?? self::CORE[$type] ?? null;

		if ($class === null && is_string($as)) {
			$name = is_string($props['name'] ?? null) ? $props['name'] : '';

			throw new InvalidArgumentException(
				message: "`agents.as` of the field `{$name}`: `{$as}` is not a core field type or a type in the option tobimori.agents.fields",
			);
		}

		$class ??= CustomField::class;

		if (!is_string($class) || !is_subclass_of($class, Field::class)) {
			throw new InvalidArgumentException(
				message: "The option tobimori.agents.fields.{$type} must be the name of a class that extends "
				. Field::class,
			);
		}

		return new $class($props);
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
	 * A blueprint hint: a key under `agents` in the field definition
	 *
	 * @param array<array-key, mixed> $props
	 */
	public static function hint(array $props, string $key): mixed
	{
		return is_array($props['agents'] ?? null) ? $props['agents'][$key] ?? null : null;
	}
}
