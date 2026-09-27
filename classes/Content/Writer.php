<?php

declare(strict_types=1);

namespace tobimori\Agents\Content;

use Closure;
use Kirby\Cms\App;
use Kirby\Cms\Language;
use Kirby\Cms\ModelWithContent;
use Kirby\Content\LockedContentException;
use Kirby\Content\VersionCache;
use Kirby\Filesystem\Dir;
use Kirby\Form\Fields;
use RuntimeException;
use tobimori\Agents\Tools\ToolError;

final class Writer
{
	/**
	 * Locks that this request holds, so that a nested call does not wait for itself
	 *
	 * @var array<string, true>
	 */
	private static array $held = [];

	/**
	 * Changes content in one step: reads it, checks the etag, applies the operations, checks the result, saves it,
	 * and reads it again. Only one agent request at a time writes the same content
	 *
	 * @param list<mixed> $ops
	 *
	 * @return array{base: Reader, edit: array{values: array<array-key, mixed>, created: list<array{name: string|null, path: list<string|int>}>, changed: list<string>}, others: array{editor: string, fields: list<string>}|null, after: Reader|null, errors: list<string>, warnings: list<string>, values: array<array-key, mixed>, model: ModelWithContent}
	 */
	public static function write(
		ModelWithContent $model,
		?string $language,
		string $etag,
		array $ops,
		bool $publish,
		bool $dryRun,
	): array {
		return self::exclusive($model, static function () use (
			$model,
			$language,
			$etag,
			$ops,
			$publish,
			$dryRun,
		): array {
			$base = self::read($model, $language, $etag);
			$editor = new Editor($base);
			$editor->apply($ops);
			$edit = $editor->result();

			// before the save, which can publish
			$others = self::others($base, $edit['changed']);
			$saved = self::save($base, $edit['values'], $edit['changed'], $publish, $dryRun);

			$after = match (true) {
				$saved['errors'] !== [] => null,
				$dryRun => $base->withValues($saved['values']),
				default => self::fresh($saved['model'], $language),
			};

			return ['base' => $base, 'edit' => $edit, 'others' => $others, 'after' => $after, ...$saved];
		});
	}

	/**
	 * Reads content that the user may change, as the agent read it before
	 */
	public static function read(ModelWithContent $model, ?string $language, string $etag): Reader
	{
		if ($model->permissions()->can('update') === false) {
			throw new ToolError('your role may not change this content');
		}

		$base = self::fresh($model, $language);

		if ($etag !== $base->etag) {
			throw new ToolError(
				"The content changed since your read. The current etag is {$base->etag}. Read it again with content_get, because the ref numbers may have changed too.",
			);
		}

		return $base;
	}

	/**
	 * Fields with unsaved changes of another user, which the agent did not change
	 *
	 * @param list<string> $changed
	 *
	 * @return array{editor: string, fields: list<string>}|null
	 */
	private static function others(Reader $base, array $changed): ?array
	{
		$editor = $base->editor;

		if ($editor === null) {
			return null;
		}

		$fields = array_values(array_diff(Pending::changedFields($base), $changed));

		if ($fields === []) {
			return null;
		}

		return ['editor' => $editor, 'fields' => $fields];
	}

	/**
	 * Kirby keeps content that it read once. Another request can have changed it since
	 */
	private static function fresh(ModelWithContent $model, ?string $language): Reader
	{
		VersionCache::reset();

		return Reader::read($model, null, $language);
	}

	/**
	 * Kirby's lock only stops other users, not two requests of the same user
	 *
	 * @template T
	 *
	 * @param Closure(): T $run
	 *
	 * @return T
	 */
	public static function exclusive(ModelWithContent $model, Closure $run): mixed
	{
		// the site has no id, but there is only one
		$name = hash('sha256', $model::class . ' ' . (string) $model->id());

		if (self::$held[$name] ?? false) {
			return $run();
		}

		$cache = App::instance()->root('cache') ?? throw new RuntimeException('Kirby has no cache folder');
		$dir = $cache . '/tobimori.agents/locks';
		Dir::make($dir);

		$handle = fopen($dir . '/' . $name . '.lock', 'c');

		if ($handle === false || flock($handle, LOCK_EX) === false) {
			throw new RuntimeException('Cannot lock the content');
		}

		self::$held[$name] = true;

		try {
			return $run();
		} finally {
			unset(self::$held[$name]);
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}

	/**
	 * @param array<array-key, mixed> $values
	 * @param list<string> $changed
	 *
	 * @return array{errors: list<string>, warnings: list<string>, values: array<array-key, mixed>, model: ModelWithContent}
	 */
	private static function save(Reader $base, array $values, array $changed, bool $publish, bool $dryRun): array
	{
		$model = $base->model;
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

		// Kirby validates all fields when it publishes: reject before the save, or the changes stay saved
		if ($publish && $warnings !== []) {
			foreach ($warnings as $line) {
				$errors[] = "{$line} (you did not change this field, but to publish, all fields must be valid)";
			}

			$warnings = [];
		}

		if ($errors !== [] || $dryRun) {
			return ['errors' => $errors, 'warnings' => $warnings, 'values' => $result, 'model' => $model];
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

		// a publish replaces the model: the old one keeps the old content in memory
		return ['errors' => [], 'warnings' => $warnings, 'values' => $result, 'model' => $changes->model()];
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
