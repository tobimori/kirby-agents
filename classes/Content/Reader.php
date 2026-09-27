<?php

declare(strict_types=1);

namespace tobimori\Agents\Content;

use Kirby\Cms\App;
use Kirby\Cms\Language;
use Kirby\Cms\ModelWithContent;
use Kirby\Content\VersionId;
use Kirby\Form\Form;
use tobimori\Agents\Fields\Fields;
use tobimori\Agents\Tools\ToolError;

final class Reader
{
	/**
	 * @param array<array-key, mixed> $fields
	 * @param array<array-key, mixed> $values
	 * @param array<int, Node> $nodes
	 * @param list<string> $untranslated
	 */
	private function __construct(
		public readonly ModelWithContent $model,
		public readonly string $version,
		public readonly string $language,
		public readonly string $etag,
		public readonly array $fields,
		public readonly array $values,
		public readonly array $nodes,
		public readonly string $title,
		public readonly array $untranslated,
		public readonly ?string $editor,
	) {}

	public static function read(ModelWithContent $model, ?string $version = null, ?string $language = null): self
	{
		$language = self::language($language);

		$version ??= $model->version(VersionId::changes())->exists($language) ? 'changes' : 'latest';

		if (!in_array($version, ['latest', 'changes'], true)) {
			throw new ToolError('`version` must be `latest` or `changes`');
		}

		$content = $model->version($version);

		$missing = $content->exists($language) === false;

		if ($missing && ($language->isDefault() || $content->exists('default') === false)) {
			throw new ToolError("The page has no `{$version}` version in this language");
		}

		$form = new Form(fields: $model->blueprint()->fields(), model: $model, language: $language);
		$form->fill(input: $content->content($language)->toArray());

		$fields = [];

		foreach ($form->fields() as $name => $field) {
			if ($field->hasValue()) {
				$props = Fields::props($field);
				unset($props['value']);
				$fields[(string) $name] = $props;
			}
		}

		$fields = Fields::visible($fields);

		$values = array_intersect_key($form->toFormValues(), $fields);
		$raw = $content->read($language) ?? [];
		$title = $content->content($language)->toArray()['title'] ?? '';

		$untranslated = [];

		if ($language->isDefault() === false) {
			foreach ($fields as $name => $props) {
				if (($props['translate'] ?? true) !== false && !array_key_exists(strtolower($name), $raw)) {
					$untranslated[] = $name;
				}
			}
		}

		$fallback = $language->isDefault() ? null : $content->read('default');
		$editor = null;

		// another user made the unsaved changes, maybe in the Panel
		if ($version === 'changes') {
			$user = $content->lock($language)->user();

			if ($user !== null && $user->is(App::instance()->user()) === false) {
				$editor = $user->email() ?? $user->id();
			}
		}
		$nodes = Nodes::index($fields, $values);

		// refs also depend on the blueprint, for example on the order of its fields
		$refs = array_map(static fn(Node $node): array => [$node->kind, $node->type, $node->key()], $nodes);
		$stored = json_encode([$version, $language->code(), $raw, $fallback, $refs]);

		return new self(
			model: $model,
			version: $version,
			language: $language->code(),
			etag: substr(hash('sha256', (string) $stored), 0, 12),
			fields: $fields,
			values: $values,
			nodes: $nodes,
			title: is_string($title) ? $title : '',
			untranslated: $untranslated,
			editor: $editor,
		);
	}

	/**
	 * @param array<array-key, mixed> $values
	 */
	public function withValues(array $values): self
	{
		return new self(
			model: $this->model,
			version: $this->version,
			language: $this->language,
			etag: 'none, dry run',
			fields: $this->fields,
			values: $values,
			nodes: Nodes::index($this->fields, $values),
			title: $this->title,
			untranslated: $this->untranslated,
			editor: $this->editor,
		);
	}

	public function node(int $ref): Node
	{
		return (
			$this->nodes[$ref] ?? throw new ToolError(
				"No node {$ref}. Read the outline again to get the current numbers.",
			)
		);
	}

	private static function language(?string $code): Language
	{
		$kirby = App::instance();

		if ($code === null || $kirby->multilang() === false) {
			return Language::ensure('default');
		}

		$language = $kirby->language($code);

		if ($language === null) {
			$codes = array_filter($kirby->languages()->codes(), is_string(...));

			throw new ToolError("No language `{$code}`. Languages: " . implode(', ', $codes));
		}

		return $language;
	}
}
