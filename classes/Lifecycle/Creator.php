<?php

declare(strict_types=1);

namespace tobimori\Agents\Lifecycle;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Form\Form;
use Kirby\Toolkit\Str;
use tobimori\Agents\Content\InputCheck;
use tobimori\Agents\Content\Writer;
use tobimori\Agents\Fields\Fields;
use tobimori\Agents\Schema\Compiler;
use tobimori\Agents\Tools\ToolError;

final class Creator
{
	private readonly CreateDialog $dialog;

	public function __construct(
		public readonly Site|Page $parent,
		public readonly string $template,
		private readonly ?string $title = null,
		private readonly ?string $slug = null,
	) {
		$this->dialog = new CreateDialog(
			$parent,
			$template,
			array_filter(['title' => $title, 'slug' => $slug], static fn(?string $value): bool => $value !== null),
		);
	}

	public function status(): string
	{
		$status = $this->option('status');

		return is_string($status) ? $status : 'draft';
	}

	/**
	 * @return array{status: string, title: string, slug: string, sort: string, fields?: array<string, string>}
	 */
	public function describe(): array
	{
		$title = $this->option('title');
		$slug = $this->option('slug');
		$fields = array_map(Compiler::line(...), $this->fields());
		$options = [
			'status' => $this->status(),
			'title' => is_string($title) ? "set from the template `{$title}`" : 'you send it',
			'slug' => is_string($slug) ? "set from the template `{$slug}`" : 'from the title, unless you send one',
			'sort' => Placement::sorting($this->dialog->model()),
		];

		return $fields === [] ? $options : [...$options, 'fields' => $fields];
	}

	/**
	 * @param array<array-key, mixed> $content
	 */
	public function create(array $content, bool $save): ?Page
	{
		$fields = $this->fields();
		$unknown = array_diff(array_keys($content), array_keys($fields));

		if ($unknown !== []) {
			throw new ToolError(
				'Only the fields of the create dialog can be set here'
				. ($fields !== [] ? ' (' . implode(', ', array_keys($fields)) . ')' : '')
				. '. Set `'
				. implode('`, `', $unknown)
				. '` with content_update after the page exists.',
			);
		}

		$content = Fields::input($fields, $content, [], 'the create dialog');

		$input = [...$content, 'title' => $this->title ?? '', 'slug' => $this->slug ?? ''];
		$resolved = $this->dialog->resolveFieldTemplates($input, ['title', 'slug']);

		if (!is_string($resolved['slug'] ?? null) || $resolved['slug'] === '') {
			$input['slug'] = Str::slug(is_string($resolved['title'] ?? null) ? $resolved['title'] : '');
		}

		$data = $this->dialog->sanitize($input);
		$this->check($data, $fields, $content);

		if ($save === false) {
			return null;
		}

		$this->dialog->input($input);
		$this->dialog->submit();
		$slug = is_string($data['slug']) ? $data['slug'] : '';
		$page = App::instance()->page(
			$this->parent instanceof Page ? $this->parent->id() . '/' . $slug : $slug,
			drafts: true,
		);

		if (!$page instanceof Page) {
			throw new ToolError('The page was created, but it cannot be found. Look for it with pages_find.');
		}

		return $page;
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @param array<string, array<array-key, mixed>> $fields
	 * @param array<array-key, mixed> $content
	 */
	private function check(array $data, array $fields, array $content): void
	{
		$values = is_array($data['content'] ?? null) ? $data['content'] : [];
		$form = Form::for($this->dialog->model())->fill(input: $values);
		$errors = [
			...InputCheck::errors($this->dialog->model(), $fields, $content),
			...array_values(array_intersect_key(Writer::errors($form->fields()), $fields)),
		];

		if ($errors !== []) {
			throw new ToolError("Nothing was created. Invalid values:\n- " . implode("\n- ", $errors));
		}

		$this->dialog->validate($data, $this->status());

		$slug = is_string($data['slug'] ?? null) ? $data['slug'] : '';
		Placement::draft($this->parent, $this->template, $slug)->guards()->ensureExecutable('create');
	}

	/**
	 * @return array<string, array<array-key, mixed>>
	 */
	private function fields(): array
	{
		// Kirby checks the fields of the dialog, but its props have no `agents` hints
		$names = array_keys($this->dialog->customFields());
		$model = $this->dialog->model();
		$form = new Form(fields: array_intersect_key($model->blueprint()->fields(), array_flip($names)), model: $model);

		return Fields::visible(Fields::props($form, $model));
	}

	private function option(string $key): mixed
	{
		$create = $this->dialog->blueprint()->toArray()['create'] ?? null;

		return is_array($create) ? $create[$key] ?? null : null;
	}
}
