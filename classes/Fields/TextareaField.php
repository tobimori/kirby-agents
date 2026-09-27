<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use tobimori\Agents\Schema\Compiler;

class TextareaField extends Field
{
	public function describe(Compiler $schema): string
	{
		$format = $this->type() === 'markdown' ? 'markdown' : 'textarea, KirbyText with Markdown';

		return $format . $this->length();
	}

	public function prominent(): bool
	{
		return true;
	}
}
