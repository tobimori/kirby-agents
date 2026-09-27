<?php

declare(strict_types=1);

namespace tobimori\Agents\OAuth;

use SensitiveParameter;

final class Client
{
	/**
	 * @param list<string> $redirectUris
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly array $redirectUris,
		public readonly string $authMethod,
		public readonly bool $metadataDocument,
	) {}

	public static function find(string $id): ?self
	{
		if (str_starts_with($id, 'https://')) {
			return Cimd::client($id);
		}

		return Registration::client($id);
	}

	public function allowsRedirect(string $uri): bool
	{
		foreach ($this->redirectUris as $registered) {
			if (RedirectUri::matches($registered, $uri)) {
				return true;
			}
		}

		return false;
	}

	public function isPublic(): bool
	{
		return $this->authMethod === 'none';
	}

	public function hasSecret(#[SensitiveParameter] string $secret): bool
	{
		return $this->isPublic() === false && hash_equals(Registration::secret($this->id), $secret);
	}

	public function host(): ?string
	{
		$host = $this->metadataDocument ? parse_url($this->id, PHP_URL_HOST) : null;

		return is_string($host) ? $host : null;
	}
}
