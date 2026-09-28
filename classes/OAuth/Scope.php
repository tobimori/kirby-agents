<?php

declare(strict_types=1);

namespace tobimori\Agents\OAuth;

use Kirby\Cms\Permissions;
use Kirby\Cms\User;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Toolkit\I18n;
use tobimori\Agents\Agents;

/**
 * A permission that a user gives an agent. Plugins add their own with the key `tobimori.agents.scopes`
 */
final class Scope
{
	public const EXTENSION = 'tobimori.agents.scopes';

	public const CONTENT_READ = 'content:read';
	public const CONTENT_WRITE = 'content:write';
	public const CONTENT_PUBLISH = 'content:publish';
	public const PAGES_MANAGE = 'pages:manage';
	public const PAGES_DELETE = 'pages:delete';
	public const FILES_MANAGE = 'files:manage';
	public const FILES_DELETE = 'files:delete';

	/**
	 * @var array<string, self>|null
	 */
	private static ?array $registered = null;

	/**
	 * @param string|array<array-key, mixed>|null $label a translation key, a text or translations, `null` for the core translations
	 * @param string|array<array-key, mixed>|null $short
	 * @param list<string> $includes scopes that this scope allows too
	 * @param list<string> $permissions Kirby permissions, the role needs all of them
	 * @param list<string> $anyOf Kirby permissions, the role needs one of them
	 */
	private function __construct(
		public readonly string $name,
		private readonly string|array|null $label = null,
		private readonly string|array|null $short = null,
		private readonly array $includes = [],
		private readonly array $permissions = [],
		private readonly array $anyOf = [],
	) {}

	/**
	 * @return list<string>
	 */
	public static function all(): array
	{
		return array_keys(self::registered());
	}

	/**
	 * @return list<string>
	 */
	public static function minimal(): array
	{
		return [self::CONTENT_READ, self::CONTENT_WRITE];
	}

	public static function find(string $name): ?self
	{
		return self::registered()[$name] ?? null;
	}

	/**
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

		return array_values(array_filter(
			$scopes,
			static fn(string $name): bool => self::find($name)?->allows($permissions) === true,
		));
	}

	public function includes(string $scope): bool
	{
		return $scope === $this->name || in_array($scope, $this->includes, true);
	}

	public function label(): string
	{
		return $this->text($this->label, 'agents.scope.');
	}

	public function shortLabel(): string
	{
		return $this->text($this->short ?? $this->label, 'agents.scope.short.');
	}

	/**
	 * @param string|array<array-key, mixed>|null $label
	 */
	private function text(string|array|null $label, string $prefix): string
	{
		$text = match (true) {
			$label === null => I18n::translate($prefix . $this->name),
			is_array($label) => I18n::translate($label),
			// a translation key, or the text itself
			default => I18n::translate($label, $label),
		};

		return is_string($text) ? $text : $this->name;
	}

	private function allows(Permissions $permissions): bool
	{
		$has = static function (string $permission) use ($permissions): bool {
			// plugin permissions have the name of the plugin as category, like `acme.newsletter.send`
			$dot = (int) strrpos($permission, '.');

			return $permissions->for(substr($permission, 0, $dot), substr($permission, $dot + 1));
		};

		return (
			array_filter($this->permissions, static fn(string $permission): bool => !$has($permission)) === []
			&& ($this->anyOf === [] || array_filter($this->anyOf, $has) !== [])
		);
	}

	/**
	 * @return array<string, self>
	 */
	private static function registered(): array
	{
		if (self::$registered !== null) {
			return self::$registered;
		}

		$content = ['pages.update', 'site.update', 'files.update'];
		$write = [self::CONTENT_WRITE, self::CONTENT_READ];
		$scopes = [];

		foreach ([
			new self(self::CONTENT_READ),
			new self(self::CONTENT_WRITE, includes: [self::CONTENT_READ], anyOf: $content),
			new self(
				self::CONTENT_PUBLISH,
				includes: $write,
				permissions: ['tobimori.agents.publish'],
				anyOf: [...$content, 'pages.changeStatus'],
			),
			new self(self::PAGES_MANAGE, includes: $write, anyOf: [
				'pages.create',
				'pages.changeTitle',
				'pages.changeSlug',
				'pages.changeTemplate',
				'pages.move',
				'pages.sort',
			]),
			new self(self::PAGES_DELETE, permissions: ['tobimori.agents.delete'], anyOf: ['pages.delete']),
			new self(self::FILES_MANAGE, includes: $write, anyOf: ['files.create']),
			new self(self::FILES_DELETE, permissions: ['tobimori.agents.delete'], anyOf: ['files.delete']),
		] as $scope) {
			$scopes[$scope->name] = $scope;
		}

		foreach (Agents::extensions(self::EXTENSION) as $plugin => $declared) {
			foreach (is_array($declared) ? $declared : [] as $name => $definition) {
				$scope = self::custom((string) $name, $definition, $plugin);

				if (array_key_exists($scope->name, $scopes)) {
					throw new InvalidArgumentException(
						message: "The scope `{$scope->name}` of the plugin {$plugin} exists already",
					);
				}

				$scopes[$scope->name] = $scope;
			}
		}

		return self::$registered = $scopes;
	}

	private static function custom(string $name, mixed $definition, string $plugin): self
	{
		$error = static fn(string $problem): InvalidArgumentException => new InvalidArgumentException(
			message: "The scope `{$name}` of the plugin {$plugin}: {$problem}",
		);

		// the name goes into the `scope` parameter of OAuth and into the `WWW-Authenticate` header
		if (preg_match('/^[a-z0-9_-]+:[a-z0-9_-]+$/', $name) !== 1) {
			throw $error('the name must look like `newsletter:send`');
		}

		$definition = is_array($definition) ? $definition : [];
		$label = $definition['label'] ?? null;
		$short = $definition['short'] ?? null;
		$declared = $definition['permissions'] ?? [];
		$permissions = [];

		if (!is_string($label) && !is_array($label)) {
			throw $error('`label` must be a translation key, a text or an array of translations');
		}

		foreach (is_array($declared) ? $declared : [null] as $permission) {
			if (!is_string($permission) || !str_contains($permission, '.')) {
				throw $error('`permissions` must be a list of Kirby permissions, like `acme.newsletter.send`');
			}

			$permissions[] = $permission;
		}

		return new self($name, $label, is_string($short) || is_array($short) ? $short : null, permissions: $permissions);
	}
}
