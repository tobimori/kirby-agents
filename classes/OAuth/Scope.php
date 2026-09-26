<?php

declare(strict_types=1);

namespace tobimori\Agents\OAuth;

use Kirby\Cms\User;

/**
 * Scopes follow the permission groups of Kirby (`pages.*`, `files.*`). Kirby still checks
 * each action with the role of the user, the scope only limits what the agent may try.
 */
enum Scope: string
{
	case ContentRead = 'content:read';
	case ContentWrite = 'content:write';
	case ContentPublish = 'content:publish';
	case PagesManage = 'pages:manage';
	case PagesDelete = 'pages:delete';
	case FilesManage = 'files:manage';
	case FilesDelete = 'files:delete';

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
	 * Scopes the user may grant: the plugin permissions (`tobimori.agents.*`) allow it,
	 * and the role has at least one of the Kirby permissions behind the scope
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

			if ($scope === null) {
				continue;
			}

			$plugin = $scope->permission();
			$kirby = $scope->kirbyPermissions();
			$any =
				$kirby === []
				|| array_filter($kirby, static fn(string $permission): bool => $permissions->for(...explode(
					'.',
					$permission,
					2,
				))) !== [];

			if ($any && ($plugin === null || $permissions->for('tobimori.agents', $plugin))) {
				$allowed[] = $value;
			}
		}

		return $allowed;
	}

	/**
	 * A broader scope includes the narrower ones. Creating pages or files includes writing their content.
	 */
	public function includes(self $scope): bool
	{
		return match ($this) {
			self::ContentWrite => in_array($scope, [self::ContentWrite, self::ContentRead], true),
			self::ContentPublish, self::PagesManage, self::FilesManage => in_array(
				$scope,
				[$this, self::ContentWrite, self::ContentRead],
				true,
			),
			default => $this === $scope,
		};
	}

	/**
	 * Extra plugin permission needed to grant this scope
	 */
	public function permission(): ?string
	{
		return match ($this) {
			self::ContentPublish => 'publish',
			self::PagesDelete, self::FilesDelete => 'delete',
			default => null,
		};
	}

	/**
	 * Kirby role permissions behind the scope. The role needs at least one of them.
	 *
	 * @return list<string>
	 */
	public function kirbyPermissions(): array
	{
		return match ($this) {
			self::ContentRead => [],
			self::ContentWrite => ['pages.update', 'site.update', 'files.update'],
			self::ContentPublish => ['pages.update', 'site.update', 'files.update', 'pages.changeStatus'],
			self::PagesManage => [
				'pages.create',
				'pages.changeTitle',
				'pages.changeSlug',
				'pages.changeTemplate',
				'pages.move',
				'pages.sort',
			],
			self::PagesDelete => ['pages.delete'],
			self::FilesManage => ['files.create'],
			self::FilesDelete => ['files.delete'],
		};
	}
}
