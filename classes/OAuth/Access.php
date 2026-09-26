<?php

declare(strict_types=1);

namespace tobimori\Agents\OAuth;

use Kirby\Cms\App;
use Kirby\Cms\User;
use SensitiveParameter;
use tobimori\Agents\Agents;

/**
 * A verified access token: who the agent acts as, and with which scopes
 */
final class Access
{
	/**
	 * @param list<string> $scopes
	 */
	public function __construct(
		public readonly User $user,
		public readonly string $grant,
		public readonly array $scopes,
	) {}

	/**
	 * Valid signature and expiry, issued for this server,
	 * and the user and grant still exist. Scopes the role may no longer grant are removed
	 */
	public static function fromToken(#[SensitiveParameter] string $token): ?self
	{
		$data = Token::parseAccess($token);

		if ($data === null || $data['audience'] !== Agents::resource()) {
			return null;
		}

		$user = App::instance()->users()->find($data['user']);

		if (!$user instanceof User || (new GrantStore($user))->find($data['grant']) === null) {
			return null;
		}

		// role permissions can change after the grant, so they apply to each request
		return new self($user, $data['grant'], Scope::allowedFor($user, $data['scopes']));
	}

	public function allows(Scope $scope): bool
	{
		foreach ($this->scopes as $granted) {
			if (Scope::tryFrom($granted)?->includes($scope) === true) {
				return true;
			}
		}

		return false;
	}
}
