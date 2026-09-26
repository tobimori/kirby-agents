<?php

declare(strict_types=1);

namespace tobimori\Agents\Panel;

use Kirby\Cms\App;
use Kirby\Cms\User;
use Kirby\Exception\NotFoundException;
use Kirby\Exception\PermissionException;
use Kirby\Toolkit\Escape;
use Kirby\Toolkit\I18n;
use tobimori\Agents\Agents;
use tobimori\Agents\OAuth\Grant;
use tobimori\Agents\OAuth\GrantStore;

/**
 * Panel view of connected agents. Users see their own grants, admins see all.
 */
final class Grants
{
	/**
	 * `GET /panel/agents`
	 */
	public static function view(): array
	{
		$current = self::current();
		$users = $current->isAdmin() ? App::instance()->users() : [$current];
		$grants = [];

		foreach ($users as $user) {
			foreach ((new GrantStore($user))->connected() as $grant) {
				$grants[] = [
					'active' => $grant->used ?? $grant->created,
					'item' => self::item($user, $grant),
				];
			}
		}

		// the most recently active first
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

	/**
	 * Dialog to confirm a revoke
	 */
	public static function confirm(string $userId, string $grantId): array
	{
		[$user, $grant] = self::find($userId, $grantId);

		return [
			'component' => 'k-remove-dialog',
			'props' => [
				// the client chose its name, and the dialog shows the text as HTML
				'text' => I18n::template('agents.grants.revoke.confirm', null, [
					'name' => Escape::html($grant->name),
					'user' => Escape::html($user->email() ?? $user->id()),
				]),
				'submitButton' => I18n::translate('agents.grants.revoke'),
			],
		];
	}

	/**
	 * Revokes the grant: its access token stops working at once, and the refresh token too
	 */
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
			throw new PermissionException(message: 'You may only revoke your own agents');
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
		// clients with a metadata document are identified by their URL, the others registered themselves
		$host = str_starts_with($grant->client, 'https://') ? parse_url($grant->client, PHP_URL_HOST) : null;

		return [
			'id' => $grant->id,
			'name' => $grant->name,
			'host' => is_string($host) ? $host : null,
			'user' => $user->email() ?? $user->id(),
			'scopes' => array_map(static function (string $scope): string {
				$label = I18n::translate('agents.scope.' . $scope);

				return is_string($label) ? $label : $scope;
			}, $grant->scopes),
			'created' => date('c', $grant->created),
			'used' => $grant->used !== null ? date('c', $grant->used) : null,
			'dialog' => 'agents/grants/' . $user->id() . '/' . $grant->id . '/revoke',
		];
	}
}
