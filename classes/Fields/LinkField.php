<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use tobimori\Agents\Schema\Compiler;

class LinkField extends Field
{
	public function describe(Compiler $schema): string
	{
		return 'link: URL, page://uuid, file://uuid, mailto:, tel:, or #anchor';
	}
}
