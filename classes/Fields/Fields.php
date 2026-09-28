<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use Kirby\Exception\InvalidArgumentException;
use Kirby\Form\Field as FormField;
use Kirby\Form\FieldClass;
use Kirby\Toolkit\V;
use tobimori\Agents\Agents;
use tobimori\Agents\Tools\ToolError;

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
	 * @template V
	 *
	 * @param array<K, V> $fields
	 *
	 * @return array<K, V>
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

		foreach (Agents::extensions(self::EXTENSION) as $declared) {
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
			// the first parent that Kirby registers as a field type
			$parents = class_parents($definition);

			foreach ($parents === false ? [] : $parents as $parent) {
				$type = array_search($parent, FormField::$types, true);

				if (is_string($type)) {
					return $type;
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
	 * Agents cannot change these fields: hidden with `agents.ignore`, or read-only
	 *
	 * @param array<array-key, mixed> $props
	 */
	public static function isLocked(array $props): bool
	{
		return self::hint($props, 'ignore') === true || ($props['disabled'] ?? false) === true;
	}

	/**
	 * Converts the input of an agent for a set of fields, like the content of a new block.
	 * Hidden fields count as unknown, also when the caller did not filter them
	 *
	 * @param array<array-key, mixed> $fields
	 * @param array<array-key, mixed> $content
	 * @param array<array-key, mixed> $current
	 *
	 * @return array<array-key, mixed>
	 */
	public static function input(array $fields, array $content, array $current, string $where): array
	{
		foreach ($content as $name => $value) {
			$props = $fields[$name] ?? null;

			if (!is_array($props) || self::hint($props, 'ignore') === true) {
				throw new ToolError(Field::unknownField((string) $name, self::visible($fields), $where));
			}

			$old = $current[$name] ?? null;

			// agents see read-only values, and can send them back unchanged
			// @mago-expect analysis:non-documented-method (Kirby validators are called with __callStatic)
			if (($props['disabled'] ?? false) === true && !V::empty($value) && $value !== $old) {
				throw new ToolError("{$where}: field `{$name}` is read-only");
			}

			$content[$name] = self::for($props)->input($value, $old);
		}

		return $content;
	}

	/**
	 * @param array<array-key, mixed> $props
	 */
	public static function hint(array $props, string $key): mixed
	{
		return is_array($props['agents'] ?? null) ? $props['agents'][$key] ?? null : null;
	}
}
