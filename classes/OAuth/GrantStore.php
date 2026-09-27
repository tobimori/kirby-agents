<?php

declare(strict_types=1);

namespace tobimori\Agents\OAuth;

use Closure;
use Kirby\Cms\User;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use RuntimeException;

/**
 * A dot folder, so Kirby ignores it in the user folder and deletes it with the user
 */
final class GrantStore
{
	public function __construct(
		private readonly User $user,
	) {}

	public function file(): string
	{
		return $this->user->root() . '/.agents/grants.json';
	}

	public function find(string $id): ?Grant
	{
		return $this->read()[$id] ?? null;
	}

	/**
	 * @return array<string, Grant>
	 */
	public function connected(): array
	{
		return array_filter($this->read(), static fn(Grant $grant): bool => $grant->refresh !== null);
	}

	/**
	 * @param list<string> $scopes
	 */
	public function changeScopes(string $id, array $scopes): bool
	{
		return $this->change(static function (array &$grants) use ($id, $scopes): bool {
			$grant = $grants[$id] ?? null;

			if ($grant === null) {
				return false;
			}

			$grant->scopes = $scopes;

			return true;
		});
	}

	public function revoke(string $id): bool
	{
		return $this->change(static function (array &$grants) use ($id): bool {
			$exists = array_key_exists($id, $grants);
			unset($grants[$id]);

			return $exists;
		});
	}

	/**
	 * @return array<string, Grant>
	 */
	private function read(): array
	{
		$file = $this->file();

		if (is_file($file) === false) {
			return [];
		}

		$handle = fopen($file, 'r');

		if ($handle === false || flock($handle, LOCK_SH) === false) {
			throw new RuntimeException('Cannot read the grants file');
		}

		try {
			$grants = $this->decode((string) stream_get_contents($handle));
		} finally {
			flock($handle, LOCK_UN);
			fclose($handle);
		}

		return array_filter($grants, static fn(Grant $grant): bool => $grant->expires >= time());
	}

	/**
	 * @template T
	 *
	 * @param Closure(array<string, Grant>): T $change
	 *
	 * @return T
	 */
	public function change(Closure $change): mixed
	{
		$file = $this->file();
		Dir::make(dirname($file));

		$handle = fopen($file, 'c+');

		if ($handle === false || flock($handle, LOCK_EX) === false) {
			throw new RuntimeException('Cannot write the grants file');
		}

		try {
			$grants = array_filter(
				$this->decode((string) stream_get_contents($handle)),
				static fn(Grant $grant): bool => $grant->expires >= time(),
			);

			$result = $change($grants);

			$data = array_map(static fn(Grant $grant): array => $grant->toArray(), $grants);

			ftruncate($handle, 0);
			rewind($handle);
			fwrite($handle, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			fflush($handle);

			return $result;
		} finally {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}

	public function revokeAll(): void
	{
		F::remove($this->file());
	}

	/**
	 * @return array<string, Grant>
	 */
	private function decode(string $json): array
	{
		$data = json_decode($json, true);
		$grants = [];

		foreach (is_array($data) ? $data : [] as $id => $item) {
			$grant = Grant::fromArray((string) $id, $item);

			if ($grant !== null) {
				$grants[$grant->id] = $grant;
			}
		}

		return $grants;
	}
}
