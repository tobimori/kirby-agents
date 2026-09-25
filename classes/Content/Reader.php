<?php

declare(strict_types=1);

namespace tobimori\Agents\Content;

use Kirby\Cms\App;
use Kirby\Cms\Language;
use Kirby\Cms\ModelWithContent;
use Kirby\Content\VersionId;
use Kirby\Form\Form;
use tobimori\Agents\Tools\ToolError;

/**
 * Form values of one content version in one language, with numbered nodes and an etag
 */
final class Reader
{
	/**
	 * @param array<array-key, mixed> $fields props by field name, without values
	 * @param array<array-key, mixed> $values form values, as the Panel sees them
	 * @param array<int, Node> $nodes
	 */
	private function __construct(
		public readonly ModelWithContent $model,
		public readonly string $version,
		public readonly string $language,
		public readonly string $etag,
		public readonly array $fields,
		public readonly array $values,
		public readonly array $nodes,
	) {}

	/**
	 * Without a version: the changes version if it exists, like the Panel shows it
	 */
	public static function read(ModelWithContent $model, ?string $version = null, ?string $language = null): self
	{
		$language = self::language($language);

		$version ??= $model->version(VersionId::changes())->exists($language) ? 'changes' : 'latest';

		if (!in_array($version, ['latest', 'changes'], true)) {
			throw new ToolError('`version` must be `latest` or `changes`');
		}

		$content = $model->version($version);

		if ($content->exists($language) === false) {
			throw new ToolError("The page has no `{$version}` version in this language");
		}

		$form = new Form(fields: $model->blueprint()->fields(), model: $model, language: $language);
		$form->fill(input: $content->content($language)->toArray());

		$fields = [];

		foreach ($form->fields() as $name => $field) {
			if ($field->hasValue()) {
				$props = $field->toArray();
				unset($props['value']);
				$fields[(string) $name] = $props;
			}
		}

		$values = array_intersect_key($form->toFormValues(), $fields);
		$stored = json_encode([$version, $language->code(), $content->read($language)]);

		return new self(
			model: $model,
			version: $version,
			language: $language->code(),
			etag: substr(hash('sha256', (string) $stored), 0, 12),
			fields: $fields,
			values: $values,
			nodes: Nodes::index($fields, $values),
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
