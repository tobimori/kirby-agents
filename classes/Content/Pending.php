<?php

declare(strict_types=1);

namespace tobimori\Agents\Content;

use Kirby\Cms\Language;
use Kirby\Cms\ModelWithContent;
use Kirby\Content\LockedContentException;
use Kirby\Form\Fields;
use tobimori\Agents\Tools\ToolError;

final class Pending
{
	/**
	 * @param list<string> $changed
	 */
	private function __construct(
		public readonly ModelWithContent $model,
		public readonly Language $language,
		public readonly array $changed,
		public readonly ?string $editor,
	) {}

	public static function publish(ModelWithContent $model, string $etag, ?string $language): self
	{
		return Writer::exclusive($model, static function () use ($model, $etag, $language): self {
			$pending = self::read($model, $etag, $language);
			$version = $model->version('changes');
			$fields = Fields::for($model, $pending->language);
			$fields->fill(input: $version->content($pending->language)->toArray());
			$errors = Writer::errors($fields);

			if ($errors !== []) {
				throw new ToolError(
					"Nothing was published. Fix these fields with content_update first:\n- " . implode("\n- ", $errors),
				);
			}

			try {
				$version->publish($pending->language);
			} catch (LockedContentException $exception) {
				throw Writer::locked($exception->getDetails());
			}

			return $pending;
		});
	}

	public static function discard(ModelWithContent $model, string $etag, ?string $language): self
	{
		return Writer::exclusive($model, static function () use ($model, $etag, $language): self {
			$pending = self::read($model, $etag, $language);

			try {
				$model->version('changes')->delete($pending->language);
			} catch (LockedContentException $exception) {
				throw Writer::locked($exception->getDetails());
			}

			return $pending;
		});
	}

	private static function read(ModelWithContent $model, string $etag, ?string $language): self
	{
		$read = Writer::read($model, $language, $etag);

		if ($read->version !== 'changes') {
			throw new ToolError('This content has no unsaved changes');
		}

		return new self($model, Language::ensure($read->language), self::changedFields($read), $read->editor);
	}

	/**
	 * The fields in which a read of the unsaved changes differs from the saved content
	 *
	 * @return list<string>
	 */
	public static function changedFields(Reader $read): array
	{
		$language = Language::ensure($read->language);
		$latest = $read->model->version('latest')->content($language)->toArray();

		// form values, because the stored text of the same value can differ
		$fields = Fields::for($read->model, $language);
		$latest = array_intersect_key($fields->reset()->fill(input: $latest)->toFormValues(), $read->fields);
		$changed = [];

		foreach ($read->fields as $key => $props) {
			if (!$language->isDefault() && is_array($props) && ($props['translate'] ?? true) === false) {
				continue;
			}

			if (($read->values[$key] ?? null) !== ($latest[$key] ?? null)) {
				$changed[] = (string) $key;
			}
		}

		return $changed;
	}
}
