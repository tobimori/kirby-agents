<?php

declare(strict_types=1);

namespace tobimori\Agents\OAuth;

use Kirby\Cms\User;

enum Scope: string
{
	case ContentRead = 'content:read';
	case ContentWrite = 'content:write';
	case ContentPublish = 'content:publish';
	case PagesManage = 'pages:manage';
	case PagesDelete = 'pages:delete';

	/**
	 * @return list<string>
	 */
	public static function all(): array
	{
		return array_map(static fn(self $scope) => $scope->value, self::cases());
	}

	/**
	 * Scopes needed for basic use
	 *
	 * @return list<string>
	 */
	public static function minimal(): array
	{
		return [self::ContentRead->value, self::ContentWrite->value];
	}

	/**
	 * Scopes the user may grant, based on the `tobimori.agents` role permissions
	 *
	 * @param list<string> $scopes
	 *
	 * @return list<string>
	 */
	public static function allowedFor(User $user, array $scopes): array
	{
		$permissions = $user->role()->permissions();

		if ($permissions->for('tobimori.agents', 'connect') === false) {
			return [];
		}

		$allowed = [];

		foreach ($scopes as $value) {
			$scope = self::tryFrom($value);
			$permission = $scope?->permission();

			if ($scope !== null && ($permission === null || $permissions->for('tobimori.agents', $permission))) {
				$allowed[] = $value;
			}
		}

		return $allowed;
	}

	/**
	 * A broader scope includes the narrower ones
	 */
	public function includes(self $scope): bool
	{
		return match ($this) {
			self::ContentWrite => in_array($scope, [self::ContentWrite, self::ContentRead], true),
			self::ContentPublish, self::PagesManage => in_array(
				$scope,
				[$this, self::ContentWrite, self::ContentRead],
				true,
			),
			default => $this === $scope,
		};
	}

	/**
	 * Extra role permission needed to grant this scope
	 */
	public function permission(): ?string
	{
		return match ($this) {
			self::ContentPublish => 'publish',
			self::PagesDelete => 'delete',
			default => null,
		};
	}
}
