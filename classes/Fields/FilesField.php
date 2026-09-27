<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use Kirby\Cms\App;
use Kirby\Cms\ModelWithContent;

class FilesField extends RelationField
{
	protected function noun(): string
	{
		return 'file';
	}

	protected function find(string $id, ModelWithContent $model): ?ModelWithContent
	{
		return App::instance()->file($id, $model);
	}
}
