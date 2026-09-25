<?php

declare(strict_types=1);

use tobimori\Agents\Http\McpEndpoint;

$endpoint = [
	'agents.mcp' => [
		'pattern' => McpEndpoint::PATH,
		'method' => 'ALL',
		'auth' => false,
		'action' => fn() => McpEndpoint::handle(),
	],
];

return [
	// Kirby only loads the login area for requests without a session
	'login' => fn() => ['views' => $endpoint],
	'agents' => fn() => ['views' => $endpoint],
];
