<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use tobimori\Agents\Schema\Compiler;

/**
 * Textarea with KirbyText, or a markdown field of a plugin
 */
class TextareaField extends Field
{
	public function describe(Compiler $schema): string
	{
		$format = $this->type() === 'textarea' ? 'textarea, KirbyText with Markdown' : $this->type();

		return $format . $this->length();
	}

	public function prominent(): bool
	{
		return true;
	}
}
