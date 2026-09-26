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
		// also fields of plugins that extend the textarea
		$format = $this->type() === 'markdown' ? 'markdown' : 'textarea, KirbyText with Markdown';

		return $format . $this->length();
	}

	public function prominent(): bool
	{
		return true;
	}
}
