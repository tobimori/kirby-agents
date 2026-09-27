<?php

declare(strict_types=1);

use tobimori\Agents\Http\McpEndpoint;
use tobimori\Agents\Http\UploadEndpoint;
use tobimori\Agents\OAuth\Authorization;
use tobimori\Agents\OAuth\Registration;
use tobimori\Agents\OAuth\TokenEndpoint;

return [
	McpEndpoint::PATH => fn() => McpEndpoint::handle(),
	UploadEndpoint::PATH => fn(#[SensitiveParameter] string $token) => UploadEndpoint::handle($token),
	'oauth/authorize' => fn() => Authorization::start(),
	'oauth/register' => fn() => Registration::handle(),
	'oauth/token' => fn() => TokenEndpoint::token(),
	'oauth/revoke' => fn() => TokenEndpoint::revoke(),
];
