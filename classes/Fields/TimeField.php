<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use tobimori\Agents\Schema\Compiler;

class TimeField extends Field
{
	public function describe(Compiler $schema): string
	{
		return 'time "HH:MM:SS"';
	}
}
