<?php

declare(strict_types=1);

namespace tobimori\Agents\Content;

use Kirby\Cms\Language;
use Kirby\Content\LockedContentException;
use Kirby\Form\Fields;
use tobimori\Agents\Tools\ToolError;

/**
 * Saves form values like the Panel does: fill the fields from the changes
 * (or latest) version, submit the new values, save to the changes version.
 * Unlike the Panel, invalid values in the changed fields are not saved.
 */
final class Writer
{
	/**
	 * @param array<array-key, mixed> $values form values
	 * @param list<string> $changed top-level fields to submit
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
			$user = $lock->user()?->email() ?? 'another user';

			throw new ToolError("{$user} is editing this content in the Panel right now. Try again later.");
		}

		$fields = Fields::for($model, $language);
		$source = $changes->exists($language) ? $changes : $latest;

		$fields->fill(input: $source->content($language)->toArray());
		$fields->submit(input: array_intersect_key($values, array_flip($changed)));

		// the values as Kirby will store them, with defaults and normalized relations
		$result = array_intersect_key($fields->toFormValues(), $base->fields);

		// Kirby drops unknown options and references without an error, so check the input
		$errors = InputCheck::errors($model, array_intersect_key($base->fields, array_flip($changed)), $values);
		$warnings = [];

		foreach ($fields->errors() as $name => $error) {
			$messages = is_array($error['message'] ?? null) ? $error['message'] : [];
			$line = $name . ': ' . implode(' ', array_filter($messages, is_string(...)));

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

			// the Panel does the same: no changes version without changes
			if ($changes->isIdentical(version: $latest, language: $language)) {
				$changes->delete($language);
			} elseif ($publish) {
				$changes->publish($language);
			}
		} catch (LockedContentException $exception) {
			throw new ToolError($exception->getMessage());
		}

		return ['errors' => [], 'warnings' => $warnings, 'values' => $result];
	}
}
