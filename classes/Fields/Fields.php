<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use Kirby\Exception\InvalidArgumentException;
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
	 * @param array<array-key, mixed> $props field props from the Kirby form
	 */
	public static function for(array $props): Field
	{
		$type = is_string($props['type'] ?? null) ? $props['type'] : '';
		$custom = Agents::option('fields', []);
		$class = (is_array($custom) ? $custom[$type] ?? null : null) ?? self::CORE[$type] ?? CustomField::class;

		if (!is_string($class) || !is_subclass_of($class, Field::class)) {
			throw new InvalidArgumentException(
				message: "The option tobimori.agents.fields.{$type} must be the name of a class that extends "
				. Field::class,
			);
		}

		return new $class($props);
	}
}
