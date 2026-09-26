<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use Kirby\Cms\ModelWithContent;
use tobimori\Agents\Content\InputCheck;
use tobimori\Agents\Content\Presenter;
use tobimori\Agents\Schema\Compiler;

/**
 * A list of pages, files, or users
 */
abstract class RelationField extends Field
{
	/**
	 * `page`, `file`, or `user`
	 */
	abstract protected function noun(): string;

	/**
	 * The model for an id or UUID, or null
	 */
	abstract protected function find(string $id, ModelWithContent $model): ?ModelWithContent;

	public function describe(Compiler $schema): string
	{
		$query = is_string($this->props['query'] ?? null) ? ', from ' . $this->props['query'] : '';

		return $this->noun() . 's, list of UUIDs' . $this->count() . $query;
	}

	public function input(mixed $value, mixed $current): mixed
	{
		return self::json($value);
	}

	/**
	 * Kirby drops references it cannot find. Items can be ids, UUIDs,
	 * or objects with `uuid` or `id`, like content_get returns them
	 */
	public function check(mixed $value, InputCheck $check, string $where): void
	{
		foreach (is_array($value) ? $value : [] as $item) {
			$id = is_array($item) ? $item['uuid'] ?? $item['id'] ?? null : $item;

			if (!is_string($id) || $id === '') {
				$check->error("{$where}: send UUIDs or ids as strings");

				continue;
			}

			if ($check->isNew($id) && $this->find($id, $check->model) === null) {
				$check->error("{$where}: `{$id}` is not a {$this->noun()} on this site");
			}
		}
	}

	public function present(mixed $value, array $path, Presenter $presenter): mixed
	{
		return is_array($value) ? array_map(self::relation(...), $value) : $value;
	}

	public function summary(mixed $value, Presenter $presenter): string
	{
		$items = is_array($value) ? array_map(self::relation(...), $value) : [];

		return $items === []
			? '(empty)'
			: implode(', ', array_map(
				// files without a title show their filename, which is in the id already
				static fn(array $item): string => (
					(
						$item['title'] !== '' && $item['title'] !== basename((string) $item['id'])
							? '"' . $item['title'] . '" '
							: ''
					) . implode(' ', array_filter([$item['id'], $item['uuid']]))
				),
				$items,
			));
	}

	/**
	 * @return array{uuid: string|null, id: string|null, title: string}
	 */
	private static function relation(mixed $item): array
	{
		$item = is_array($item) ? $item : [];

		return [
			'uuid' => is_string($item['uuid'] ?? null) ? $item['uuid'] : null,
			'id' => is_string($item['id'] ?? null) ? $item['id'] : null,
			'title' => is_string($item['text'] ?? null) ? $item['text'] : '',
		];
	}
}
