<?php

declare(strict_types=1);

namespace tobimori\Agents\Content;

use Kirby\Cms\App;
use Kirby\Cms\Language;
use Kirby\Content\LockedContentException;
use Kirby\Form\Fields;
use tobimori\Agents\Tools\ToolError;

final class Writer
{
	/**
	 * @param array<array-key, mixed> $values
	 * @param list<string> $changed
	 *
	 * @return array{errors: list<string>, warnings: list<string>, values: array<array-key, mixed>}
	 */
	public static function write(Reader $base, array $values, array $changed, bool $publish, bool $dryRun): array
	{
		$model = $base->model;

		if ($model->permissions()->can('update') === false) {
			throw new ToolError('your role may not change this content');
		}

		$language = Language::ensure($base->language);
		$changes = $model->version('changes');
		$latest = $model->version('latest');
		$lock = $changes->lock($language);

		if ($lock->isLocked()) {
			throw self::locked($lock->toArray());
		}

		$fields = Fields::for($model, $language);
		$source = $changes->exists($language) ? $changes : $latest;

		$fields->fill(input: $source->content($language)->toArray());
		$fields->submit(input: array_intersect_key($values, array_flip($changed)));

		$result = array_intersect_key($fields->toFormValues(), $base->fields);

		$errors = InputCheck::errors(
			$model,
			array_intersect_key($base->fields, array_flip($changed)),
			$values,
			$base->values,
		);
		$warnings = [];

		if ($language->isDefault() === false) {
			$default = App::instance()->defaultLanguage()?->code() ?? 'default';

			foreach ($changed as $name) {
				if (($base->fields[$name]['translate'] ?? true) === false) {
					$errors[] = "{$name}: this field is the same in all languages. Change it with `language: {$default}`";
				}
			}
		}

		foreach (self::errors($fields) as $name => $line) {
			if (in_array($name, $changed, true)) {
				$errors[] = $line;
			} else {
				$warnings[] = $line;
			}
		}

		if ($errors !== [] || $dryRun) {
			return ['errors' => $errors, 'warnings' => $warnings, 'values' => $result];
		}

		try {
			$changes->save(fields: $fields->toStoredValues(), language: $language);

			if ($changes->isIdentical(version: $latest, language: $language)) {
				$changes->delete($language);
			} elseif ($publish) {
				$changes->publish($language);
			}
		} catch (LockedContentException $exception) {
			throw self::locked($exception->getDetails());
		}

		return ['errors' => [], 'warnings' => $warnings, 'values' => $result];
	}

	/**
	 * @return array<string, string>
	 */
	public static function errors(Fields $fields): array
	{
		$errors = [];

		foreach ($fields->errors() as $name => $error) {
			$messages = is_array($error['message'] ?? null) ? $error['message'] : [];
			$errors[(string) $name] = $name . ': ' . implode(' ', array_filter($messages, is_string(...)));
		}

		return $errors;
	}

	/**
	 * @param array<array-key, mixed> $lock
	 */
	public static function locked(array $lock): ToolError
	{
		$user = is_array($lock['user'] ?? null) ? $lock['user']['email'] ?? null : null;
		$user = is_string($user) ? $user : 'Another user';

		return new ToolError("{$user} is editing this content in the Panel right now. Try again later.");
	}
}
