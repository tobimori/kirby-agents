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
	) {}

	public static function for(ModelWithContent $model, string $etag, ?string $language): self
	{
		if ($model->permissions()->can('update') === false) {
			throw new ToolError('your role may not change this content');
		}

		$read = Reader::read($model, null, $language);

		if ($read->version !== 'changes') {
			throw new ToolError('This content has no unsaved changes');
		}

		if ($etag !== $read->etag) {
			throw new ToolError(
				"The changes are different from your read. The current etag is {$read->etag}. Read them again with content_get.",
			);
		}

		$language = Language::ensure($read->language);
		$latest = $model->version('latest')->content($language)->toArray();

		// form values, because the stored text of the same value can differ
		$fields = Fields::for($model, $language);
		$changes = $read->values;
		$latest = array_intersect_key($fields->reset()->fill(input: $latest)->toFormValues(), $read->fields);
		$changed = [];

		foreach ($read->fields as $key => $props) {
			if (!$language->isDefault() && is_array($props) && ($props['translate'] ?? true) === false) {
				continue;
			}

			if (($changes[$key] ?? null) !== ($latest[$key] ?? null)) {
				$changed[] = (string) $key;
			}
		}

		return new self($model, $language, $changed);
	}

	public function publish(): void
	{
		$version = $this->model->version('changes');
		$fields = Fields::for($this->model, $this->language);
		$fields->fill(input: $version->content($this->language)->toArray());
		$errors = Writer::errors($fields);

		if ($errors !== []) {
			throw new ToolError(
				"Nothing was published. Fix these fields with content_update first:\n- " . implode("\n- ", $errors),
			);
		}

		try {
			$version->publish($this->language);
		} catch (LockedContentException $exception) {
			throw Writer::locked($exception->getDetails());
		}
	}

	public function discard(): void
	{
		try {
			$this->model->version('changes')->delete($this->language);
		} catch (LockedContentException $exception) {
			throw Writer::locked($exception->getDetails());
		}
	}
}
