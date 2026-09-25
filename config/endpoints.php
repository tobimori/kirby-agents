<?php

declare(strict_types=1);

use tobimori\Agents\Http\McpEndpoint;
use tobimori\Agents\OAuth\Authorization;
use tobimori\Agents\OAuth\Registration;

// Panel routes that must work without a session, used in areas.php and hooks.php
return [
	McpEndpoint::PATH => fn() => McpEndpoint::handle(),
	'oauth/authorize' => fn() => Authorization::start(),
	'oauth/register' => fn() => Registration::handle(),
];
