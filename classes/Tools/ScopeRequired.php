<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use RuntimeException;

final class ScopeRequired extends RuntimeException
{
	public function __construct(
		public readonly string $scope,
	) {
		parent::__construct("This call needs the scope `{$scope}`");
	}
}
