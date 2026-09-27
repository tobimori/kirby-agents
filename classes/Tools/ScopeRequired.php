<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use RuntimeException;
use tobimori\Agents\OAuth\Scope;

final class ScopeRequired extends RuntimeException
{
	public function __construct(
		public readonly Scope $scope,
	) {
		parent::__construct("This call needs the scope `{$scope->value}`");
	}
}
