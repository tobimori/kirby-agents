<?php

declare(strict_types=1);

return [
	// client metadata documents
	'cache' => true,
	// extra origins that may call the MCP endpoint from a browser
	'origins' => [],
	// scopes to ask for on each authorization, in addition to the ones the client requests
	'scopes' => [],
	// key to sign tokens, generated into `site/accounts/.agents-secret` if not set
	'secret' => null,
];
