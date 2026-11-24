<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Cms\User;
use tobimori\Agents\Content\InputCheck;
use tobimori\Agents\Content\Presenter;
use tobimori\Agents\Schema\Compiler;

abstract class RelationField extends Field
{
	abstract protected function noun(): string;

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
		return is_array($value) ? $this->relations($value, $presenter->content->model) : $value;
	}

	public function summary(mixed $value, Presenter $presenter): string
	{
		$items = is_array($value) ? $this->relations($value, $presenter->content->model) : [];

		return $items === []
			? '(empty)'
			: implode(', ', array_map(
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
	 * Kirby's form values are the stored UUIDs or ids
	 *
	 * @param array<array-key, mixed> $value
	 *
	 * @return list<array{uuid: string|null, id: string|null, title: string}>
	 */
	private function relations(array $value, ModelWithContent $model): array
	{
		$items = [];

		foreach ($value as $id) {
			$found = is_string($id) ? $this->find($id, $model) : null;

			$items[] = [
				'uuid' => $found?->uuid()?->toString(),
				'id' => $found?->id() ?? (is_string($id) ? $id : null),
				'title' => match (true) {
					$found instanceof Page => (string) $found->title()->value(),
					$found instanceof File => $found->filename(),
					$found instanceof User => (string) $found->username(),
					default => '',
				},
			];
		}

		return $items;
	}
}
