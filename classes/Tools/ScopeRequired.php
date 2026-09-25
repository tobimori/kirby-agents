<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use RuntimeException;
use tobimori\Agents\OAuth\Scope;

/**
 * The call needs a scope that the token does not have. The server answers with
 * 403 `insufficient_scope`, so the client can ask the user for more access.
 */
final class ScopeRequired extends RuntimeException
{
	public function __construct(
		public readonly Scope $scope,
	) {
		parent::__construct("This call needs the scope `{$scope->value}`");
	}
}
