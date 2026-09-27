<?php

declare(strict_types=1);

namespace tobimori\Agents\Http;

use Kirby\Http\Response;

/**
 * PHP changes the status to 401 when `WWW-Authenticate` is sent after it, this keeps a 403
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
