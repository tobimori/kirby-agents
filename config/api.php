<?php

declare(strict_types=1);

use Kirby\Cms\App;
use tobimori\Agents\Panel\WebMcp;

// the WebMCP bridge of the Panel. Kirby checks the session and the CSRF token
return [
	'routes' => [
		[
			'pattern' => 'agents/tools',
			'method' => 'GET',
			'action' => fn() => WebMcp::tools(),
		],
		[
			'pattern' => 'agents/tools/(:any)',
			'method' => 'POST',
			'action' => fn(string $name) => WebMcp::call($name, App::instance()->request()->body()->toArray()),
		],
	],
];
