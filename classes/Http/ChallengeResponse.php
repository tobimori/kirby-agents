<?php

declare(strict_types=1);

namespace tobimori\Agents\Http;

use Kirby\Http\Response;

/**
 * Response with a `WWW-Authenticate` header. PHP changes the status to 401 when this
 * header is sent, and Kirby sends the status before the headers. This keeps a 403.
 */
final class ChallengeResponse extends Response
{
	public function send(): string
	{
		$body = parent::send();
		http_response_code($this->code());

		return $body;
	}
}
