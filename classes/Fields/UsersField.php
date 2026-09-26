<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use Kirby\Cms\App;
use Kirby\Cms\ModelWithContent;

class UsersField extends RelationField
{
	protected function noun(): string
	{
		return 'user';
	}

	protected function find(string $id, ModelWithContent $model): ?ModelWithContent
	{
		return App::instance()->user($id);
	}
}
