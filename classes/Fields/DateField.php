<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use tobimori\Agents\Content\Presenter;
use tobimori\Agents\Schema\Compiler;

class DateField extends Field
{
	public function describe(Compiler $schema): string
	{
		return $this->hasTime() ? 'date "YYYY-MM-DD HH:MM:SS"' : 'date "YYYY-MM-DD"';
	}

	// Kirby stores a time also for dates without time
	public function present(mixed $value, array $path, Presenter $presenter): mixed
	{
		return !$this->hasTime() && is_string($value) ? substr($value, 0, 10) : $value;
	}

	private function hasTime(): bool
	{
		return ($this->props['time'] ?? false) !== false;
	}
}
