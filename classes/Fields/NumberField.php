<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use tobimori\Agents\Schema\Compiler;

/**
 * Number or range
 */
class NumberField extends Field
{
	public function describe(Compiler $schema): string
	{
		$limits = $this->limits();

		return 'number' . ($limits === [] ? '' : ', ' . implode(', ', $limits));
	}
}
