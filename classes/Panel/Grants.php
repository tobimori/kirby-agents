<?php

declare(strict_types=1);

namespace tobimori\Agents\Panel;

use Kirby\Cms\App;
use Kirby\Cms\User;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Exception\NotFoundException;
use Kirby\Exception\PermissionException;
use Kirby\Toolkit\Escape;
use Kirby\Toolkit\I18n;
use tobimori\Agents\Agents;
use tobimori\Agents\OAuth\Client;
use tobimori\Agents\OAuth\Grant;
use tobimori\Agents\OAuth\GrantStore;
use tobimori\Agents\OAuth\Scope;

final class Grants
{
	public static function view(): array
	{
		$current = self::current();
		$users = $current->isAdmin() ? App::instance()->users() : [$current];
		$grants = [];

		foreach ($users as $user) {
			foreach ((new GrantStore($user))->connected() as $grant) {
				$grants[] = [
					'active' => $grant->active(),
					'item' => self::item($user, $grant),
				];
			}
		}

		usort($grants, static fn(array $a, array $b): int => $b['active'] <=> $a['active']);

		return [
			'component' => 'k-agents-grants-view',
			'title' => I18n::translate('agents.title'),
			'props' => [
				'grants' => array_column($grants, 'item'),
				'all' => $current->isAdmin(),
				'url' => Agents::resource(),
			],
		];
	}

	public static function confirm(string $userId, string $grantId): array
	{
		[$user, $grant] = self::find($userId, $grantId);

		return [
			'component' => 'k-remove-dialog',
			'props' => [
				// the client chose its name, and the dialog renders HTML
				'text' => I18n::template('agents.grants.revoke.confirm', null, [
					'name' => Escape::html($grant->name),
					'user' => Escape::html($user->email() ?? $user->id()),
				]),
				'submitButton' => I18n::translate('agents.grants.revoke'),
			],
		];
	}

	public static function scopesDialog(string $userId, string $grantId): array
	{
		[$user, $grant] = self::find($userId, $grantId);
		$allowed = Scope::allowedFor($user, Scope::all());

		return [
			'component' => 'k-form-dialog',
			'props' => [
				'fields' => [
					'scopes' => [
						'type' => 'checkboxes',
						'label' => I18n::translate('agents.grants.scopes'),
						'help' => I18n::translate('agents.grants.scopes.help'),
						'required' => true,
						'min' => 1,
						'options' => array_map(static fn(string $scope): array => [
							'value' => $scope,
							'text' => Scope::find($scope)?->label() ?? $scope,
						], $allowed),
					],
				],
				'value' => ['scopes' => array_values(array_intersect($grant->scopes, $allowed))],
				'submitButton' => I18n::translate('save'),
			],
		];
	}

	public static function changeScopes(string $userId, string $grantId): array
	{
		[$user] = self::find($userId, $grantId);
		$scopes = App::instance()->request()->get('scopes');
		$scopes = is_array($scopes) ? array_values(array_filter($scopes, is_string(...))) : [];

		if ($scopes === []) {
			throw new InvalidArgumentException(message: I18n::template('agents.grants.scopes.empty'));
		}

		if (Scope::allowedFor($user, $scopes) !== $scopes) {
			throw new PermissionException(message: 'The role of the user does not allow these permissions');
		}

		(new GrantStore($user))->changeScopes($grantId, $scopes);

		return ['event' => 'agents.grant.scopes'];
	}

	public static function revoke(string $userId, string $grantId): array
	{
		[$user] = self::find($userId, $grantId);
		(new GrantStore($user))->revoke($grantId);

		return ['event' => 'agents.grant.revoke'];
	}

	/**
	 * @return array{0: User, 1: Grant}
	 */
	private static function find(string $userId, string $grantId): array
	{
		$current = self::current();
		$user = App::instance()->user($userId);

		if (!$user instanceof User || !$current->is($user) && !$current->isAdmin()) {
			throw new PermissionException(message: 'You may only change your own agents');
		}

		$grant = (new GrantStore($user))->find($grantId);

		if ($grant === null) {
			throw new NotFoundException(message: 'The agent is not connected anymore');
		}

		return [$user, $grant];
	}

	private static function current(): User
	{
		return App::instance()->user() ?? throw new PermissionException(message: 'Log in first');
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function item(User $user, Grant $grant): array
	{
		$dialogs = 'agents/grants/' . $user->id() . '/' . $grant->id;

		return [
			'id' => $grant->id,
			'client' => ['name' => $grant->name, 'host' => Client::hostOf($grant->client)],
			'user' => [
				'text' => $user->username() ?? $user->id(),
				'link' => $user->panel()->url(true),
				'image' => $user->panel()->image(),
			],
			'scopes' => array_map(
				static fn(string $scope): array => [
					'value' => $scope,
					'short' => Scope::find($scope)?->shortLabel() ?? $scope,
					'text' => Scope::find($scope)?->label() ?? $scope,
				],
				Scope::allowedFor($user, $grant->scopes),
			),
			'active' => date('c', $grant->active()),
			'dialogs' => ['scopes' => $dialogs . '/scopes', 'revoke' => $dialogs . '/revoke'],
		];
	}
}
