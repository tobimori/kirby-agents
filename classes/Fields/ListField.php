<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use tobimori\Agents\Schema\Compiler;

class ListField extends Field
{
	public function describe(Compiler $schema): string
	{
		return 'html list, <ul> or <ol> with <li>';
	}

	public function prominent(): bool
	{
		return true;
	}
}
