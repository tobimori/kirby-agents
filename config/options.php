<?php

declare(strict_types=1);

return [
	// client metadata documents
	'cache' => true,
	// rate limit counters
	'cache.limits' => true,
	// requests per window in seconds, for example `'mcp' => [300, 60]`. `false` turns a limit off, or all limits
	// defaults: register [20, 3600], authorize [30, 60], token [60, 60] per IP; mcp [120, 60], upload [30, 60] per agent
	'limits' => [],
	// extra origins that may call the MCP endpoint from a browser
	'origins' => [],
	// scopes to ask for on each authorization, in addition to the ones the client requests
	'scopes' => [],
	// key to sign tokens, generated into `site/accounts/.agents-secret` if not set
	'secret' => null,
];
