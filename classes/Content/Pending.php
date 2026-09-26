<?php

declare(strict_types=1);

namespace tobimori\Agents\Content;

use Kirby\Cms\Language;
use Kirby\Cms\ModelWithContent;
use Kirby\Content\LockedContentException;
use Kirby\Form\Fields;
use tobimori\Agents\Tools\ToolError;

/**
 * Unsaved changes of a page or the site in one language, checked against the etag of a read
 */
final class Pending
{
	/**
	 * @param list<string> $changed fields that differ from the published version
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
		$changes = $model->version('changes')->read($language) ?? [];
		$latest = $model->version('latest')->exists($language) ? $model->version('latest')->read($language) ?? [] : [];
		$changed = [];

		// Kirby ignores these in the comparison too
		unset($changes['lock'], $changes['uuid'], $latest['lock'], $latest['uuid']);

		foreach (array_unique([...array_keys($changes), ...array_keys($latest)]) as $key) {
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

		// invalid content must not go live, also when an editor saved it in the Panel
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
