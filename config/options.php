<?php

declare(strict_types=1);

return [
	// extra origins that may call the MCP endpoint from a browser
	'origins' => [],
	// key to sign tokens, generated into `site/accounts/.agents-secret` if not set
	'secret' => null,
];
