<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use Kirby\Blueprint\Blueprint;
use Kirby\Cms\Fieldset;
use Kirby\Cms\Fieldsets;
use Kirby\Cms\ModelWithContent;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Form\Field as FormField;
use Kirby\Form\Form;
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
		'pagepicker' => PagesField::class,
		'filepicker' => FilesField::class,
		'userpicker' => UsersField::class,
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
	public static function for(array $props, ?ModelWithContent $model = null): Field
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

		return $class === null ? new CustomField($props, $model) : new $class($props, $model);
	}

	/**
	 * The props of the fields with a value as the Panel gets them, with their defaults and `agents` hints
	 *
	 * @return array<string, array<array-key, mixed>>
	 */
	public static function props(Form $form, ModelWithContent $model): array
	{
		$definitions = $model->blueprint()->fields();
		$props = [];

		foreach ($form->fields() as $name => $field) {
			if ($field->hasValue()) {
				$field = [...$field->toArray(), 'default' => $field->default()];
				unset($field['value']);
				$props[$name] = self::withHints($field, $definitions[$name] ?? null, $model);
			}
		}

		return $props;
	}

	/**
	 * Kirby passes only the props that a field class knows to the field, so the hints come from the blueprint
	 *
	 * @param array<array-key, mixed> $props
	 *
	 * @return array<array-key, mixed>
	 */
	private static function withHints(array $props, mixed $definition, ModelWithContent $model): array
	{
		if (!is_array($definition)) {
			return $props;
		}

		if (is_array($definition['agents'] ?? null)) {
			$props['agents'] = $definition['agents'];
		}

		if (is_array($props['fields'] ?? null) && is_array($definition['fields'] ?? null)) {
			$props['fields'] = self::fieldsWithHints($props['fields'], $definition['fields'], $model);
		}

		if (is_array($props['field'] ?? null)) {
			$props['field'] = self::withHints($props['field'], $definition['field'] ?? null, $model);
		}

		if (is_array($props['fieldsets'] ?? null)) {
			$fieldsets = $definition['fieldsets'] ?? null;
			// @mago-expect analysis:less-specific-nested-argument-type (Kirby takes names of fieldsets too)
			$fieldsets = Fieldsets::factory(is_array($fieldsets) ? $fieldsets : null, ['parent' => $model]);

			foreach ($props['fieldsets'] as $type => $fieldset) {
				$tabs = $fieldsets->get((string) $type)?->tabs();

				if (is_array($fieldset) && $tabs !== null) {
					$props['fieldsets'][$type] = self::tabsWithHints($fieldset, $tabs, $model);
				}
			}
		}

		$settings = $definition['settings'] ?? null;

		if (is_array($props['settings'] ?? null) && (is_array($settings) || is_string($settings))) {
			// like Kirby's layout field
			$settings = new Fieldset([...Blueprint::extend($settings), 'type' => 'layout', 'parent' => $model]);
			$props['settings'] = self::tabsWithHints($props['settings'], $settings->tabs(), $model);
		}

		return $props;
	}

	/**
	 * @param array<array-key, mixed> $fields
	 * @param array<array-key, mixed> $definitions
	 *
	 * @return array<array-key, mixed>
	 */
	private static function fieldsWithHints(array $fields, array $definitions, ModelWithContent $model): array
	{
		foreach ($fields as $name => $props) {
			if (is_array($props)) {
				$fields[$name] = self::withHints($props, $definitions[$name] ?? null, $model);
			}
		}

		return $fields;
	}

	/**
	 * @param array<array-key, mixed> $fieldset
	 * @param array<array-key, mixed> $tabs
	 *
	 * @return array<array-key, mixed>
	 */
	private static function tabsWithHints(array $fieldset, array $tabs, ModelWithContent $model): array
	{
		if (!is_array($fieldset['tabs'] ?? null)) {
			return $fieldset;
		}

		foreach ($fieldset['tabs'] as $name => $tab) {
			$definitions = is_array($tabs[$name] ?? null) ? $tabs[$name]['fields'] ?? null : null;

			if (is_array($tab) && is_array($tab['fields'] ?? null) && is_array($definitions)) {
				$tab['fields'] = self::fieldsWithHints($tab['fields'], $definitions, $model);
				$fieldset['tabs'][$name] = $tab;
			}
		}

		return $fieldset;
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

	/**
	 * The type of the first parent class that Kirby registers as a field type
	 */
	private static function extended(string $type): ?string
	{
		$class = FormField::$types[$type] ?? null;
		$parents = is_string($class) && class_exists($class) ? class_parents($class) : false;

		foreach ($parents === false ? [] : $parents as $parent) {
			$type = array_search($parent, FormField::$types, true);

			if (is_string($type)) {
				return $type;
			}
		}

		return null;
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
	public static function input(
		array $fields,
		array $content,
		array $current,
		string $where,
		?ModelWithContent $model = null,
	): array {
		foreach ($content as $name => $value) {
			$props = $fields[$name] ?? null;

			if (!is_array($props) || self::hint($props, 'ignore') === true) {
				throw new ToolError(Field::unknownField((string) $name, self::visible($fields), $where));
			}

			$old = $current[$name] ?? null;

			// agents see read-only values, and can send them back unchanged
			if (($props['disabled'] ?? false) === true && !V::empty($value) && $value !== $old) {
				throw new ToolError("{$where}: field `{$name}` is read-only");
			}

			$content[$name] = self::for($props, $model)->input($value, $old);
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
