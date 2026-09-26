<?php

declare(strict_types=1);

namespace tobimori\Agents\Lifecycle;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\PageRules;
use Kirby\Cms\Site;
use Kirby\Form\Form;
use Kirby\Panel\PageCreateDialog;
use Kirby\Toolkit\Str;
use tobimori\Agents\Content\InputCheck;
use tobimori\Agents\Content\Writer;
use tobimori\Agents\Schema\Compiler;
use tobimori\Agents\Tools\ToolError;

/**
 * Creates pages with the logic of the Panel's create dialog: the `create` options of the
 * blueprint set the title and slug templates, the fields to fill, and the status
 */
final class Creator
{
	private readonly PageCreateDialog $dialog;

	public function __construct(
		public readonly Site|Page $parent,
		public readonly string $template,
		private readonly ?string $title = null,
		private readonly ?string $slug = null,
	) {
		$this->dialog = new PageCreateDialog(
			parentId: $parent instanceof Page ? 'pages/' . str_replace('/', '+', $parent->id()) : 'site',
			sectionId: null,
			template: $template,
			viewId: null,
			slug: $slug,
			title: $title,
		);
	}

	/**
	 * Status of new pages: `draft` unless the blueprint sets `create.status`
	 */
	public function status(): string
	{
		$status = $this->option('status');

		return is_string($status) ? $status : 'draft';
	}

	/**
	 * The create options for page_rules
	 *
	 * @return array{status: string, title: string, slug: string, fields?: array<string, string>}
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
		];

		return $fields === [] ? $options : [...$options, 'fields' => $fields];
	}

	/**
	 * Checks the input and creates the page. Without `$save`, only checks.
	 *
	 * @param array<array-key, mixed> $content values of the create fields
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

		$input = [...$content, 'title' => $this->title ?? '', 'slug' => $this->slug ?? ''];
		$resolved = $this->dialog->resolveFieldTemplates($input);

		// the slug field of the Panel dialog follows the title
		if (!is_string($resolved['slug'] ?? null) || $resolved['slug'] === '') {
			$input['slug'] = Str::slug(is_string($resolved['title'] ?? null) ? $resolved['title'] : '');
		}

		$data = $this->dialog->sanitize($input);
		$this->check($data, $fields, $content);

		if ($save === false) {
			return null;
		}

		$this->dialog->submit($input);
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
	 * The dialog in the Panel checks the create fields in the browser, so check them here.
	 * Then the checks of the dialog and of `PageRules::create`, which runs only when the page is saved.
	 *
	 * @param array<array-key, mixed> $data sanitized input
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
		PageRules::create(Placement::draft($this->parent, $this->template, $slug));
	}

	/**
	 * Props of the create fields, with resolved options
	 *
	 * @return array<string, array<array-key, mixed>>
	 */
	private function fields(): array
	{
		$fields = [];

		foreach ($this->dialog->customFields() as $name => $props) {
			if (is_array($props)) {
				$fields[(string) $name] = $props;
			}
		}

		return $fields;
	}

	private function option(string $key): mixed
	{
		$create = $this->dialog->blueprint()->toArray()['create'] ?? null;

		return is_array($create) ? $create[$key] ?? null : null;
	}
}
