<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use Kirby\Cms\ModelWithContent;
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
		return is_array($value) ? array_map(self::relation(...), $value) : $value;
	}

	public function summary(mixed $value, Presenter $presenter): string
	{
		$items = is_array($value) ? array_map(self::relation(...), $value) : [];

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
