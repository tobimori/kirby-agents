<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\Scope;

interface Tool
{
	public function name(): string;

	public function definition(): array;

	public function scope(): Scope;

	public function call(Arguments $arguments, Access $access): array|string;
}
