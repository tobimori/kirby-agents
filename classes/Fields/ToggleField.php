<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use tobimori\Agents\Schema\Compiler;

class ToggleField extends Field
{
	public function describe(Compiler $schema): string
	{
		return 'boolean';
	}
}
