<?php

declare(strict_types=1);

namespace tobimori\Agents\Lifecycle;

use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Http\Request;
use Kirby\Panel\Controller\Dialog\PageCreateDialogController;

/**
 * The create dialog of the Panel, which reads the input of the agent instead of the request
 */
final class CreateDialog extends PageCreateDialogController
{
	/**
	 * @param array<array-key, mixed> $input
	 */
	public function __construct(Site|Page $parent, string $template, array $input)
	{
		parent::__construct(parent: $parent, blueprints: [$template]);
		$this->input($input);
	}

	/**
	 * @param array<array-key, mixed> $input
	 */
	public function input(array $input): void
	{
		$this->request = new Request(['method' => 'POST', 'query' => [], 'body' => $input]);
	}
}
