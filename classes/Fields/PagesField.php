<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use Kirby\Cms\App;
use Kirby\Cms\ModelWithContent;

class PagesField extends RelationField
{
	protected function noun(): string
	{
		return 'page';
	}

	protected function find(string $id, ModelWithContent $model): ?ModelWithContent
	{
		return App::instance()->page($id, drafts: true);
	}
}
