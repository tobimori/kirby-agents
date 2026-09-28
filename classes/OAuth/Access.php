<?php

declare(strict_types=1);

namespace tobimori\Agents\OAuth;

use Kirby\Cms\App;
use Kirby\Cms\User;
use SensitiveParameter;
use tobimori\Agents\Agents;

final class Access
{
	/**
	 * @param list<string> $scopes
	 */
	public function __construct(
		public readonly User $user,
		public readonly string $grant,
		public readonly array $scopes,
		public readonly ?string $client = null,
	) {}

	public static function fromToken(#[SensitiveParameter] string $token): ?self
	{
		$data = Token::parseAccess($token);

		if ($data === null || $data['audience'] !== Agents::resource()) {
			return null;
		}

		$user = App::instance()->users()->find($data['user']);

		$grant = $user instanceof User ? (new GrantStore($user))->find($data['grant']) : null;

		if (!$user instanceof User || $grant === null) {
			return null;
		}

		return new self($user, $data['grant'], Scope::allowedFor($user, $grant->scopes), $grant->client);
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
