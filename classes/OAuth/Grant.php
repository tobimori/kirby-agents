<?php

declare(strict_types=1);

namespace tobimori\Agents\OAuth;

final class Grant
{
	/**
	 * @param list<string> $scopes
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $client,
		public readonly string $name,
		public array $scopes,
		public readonly string $redirect,
		public readonly string $resource,
		public readonly int $created,
		public int $expires,
		public ?string $code = null,
		public ?string $challenge = null,
		public ?string $refresh = null,
		public ?string $previous = null,
		public ?int $used = null,
	) {}

	public static function create(
		string $client,
		string $name,
		array $scopes,
		string $redirect,
		string $resource,
		string $code,
		string $challenge,
	): self {
		$now = time();

		return new self(
			id: bin2hex(random_bytes(8)),
			client: $client,
			name: $name,
			scopes: array_values(array_filter($scopes, is_string(...))),
			redirect: $redirect,
			resource: $resource,
			created: $now,
			expires: $now + Token::CODE_TTL,
			code: Token::hash($code),
			challenge: $challenge,
		);
	}

	public static function fromArray(string $id, mixed $data): ?self
	{
		if (
			!is_array($data)
			|| !is_string($data['client'] ?? null)
			|| !is_string($data['name'] ?? null)
			|| !is_array($data['scopes'] ?? null)
			|| !is_string($data['redirect'] ?? null)
			|| !is_string($data['resource'] ?? null)
			|| !is_int($data['created'] ?? null)
			|| !is_int($data['expires'] ?? null)
		) {
			return null;
		}

		$string = static fn(mixed $value): ?string => is_string($value) ? $value : null;

		return new self(
			id: $id,
			client: $data['client'],
			name: $data['name'],
			scopes: array_values(array_filter($data['scopes'], is_string(...))),
			redirect: $data['redirect'],
			resource: $data['resource'],
			created: $data['created'],
			expires: $data['expires'],
			code: $string($data['code'] ?? null),
			challenge: $string($data['challenge'] ?? null),
			refresh: $string($data['refresh'] ?? null),
			previous: $string($data['previous'] ?? null),
			used: is_int($data['used'] ?? null) ? $data['used'] : null,
		);
	}

	public function toArray(): array
	{
		return [
			'client' => $this->client,
			'name' => $this->name,
			'scopes' => $this->scopes,
			'redirect' => $this->redirect,
			'resource' => $this->resource,
			'created' => $this->created,
			'expires' => $this->expires,
			'code' => $this->code,
			'challenge' => $this->challenge,
			'refresh' => $this->refresh,
			'previous' => $this->previous,
			'used' => $this->used,
		];
	}

	public function active(): int
	{
		return $this->used ?? $this->created;
	}

	public function rotate(string $refresh): void
	{
		$this->previous = $this->refresh;
		$this->refresh = Token::hash($refresh);
		$this->expires = time() + Token::REFRESH_TTL;
		$this->used = time();
	}
}
