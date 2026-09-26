<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use tobimori\Agents\Schema\Compiler;

class ColorField extends Field
{
	public function describe(Compiler $schema): string
	{
		return 'color, ' . (is_string($this->props['format'] ?? null) ? $this->props['format'] : 'hex');
	}
}
