<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use tobimori\Agents\Schema\Compiler;

class TextField extends Field
{
	public function describe(Compiler $schema): string
	{
		return $this->type() . $this->length();
	}

	public function prominent(): bool
	{
		return true;
	}
}
