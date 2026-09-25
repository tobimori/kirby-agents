<?php

declare(strict_types=1);

use Kirby\Http\Route;
use tobimori\Agents\Http\McpEndpoint;

return [
	// without a session, GET requests match the login fallback route first
	'panel.route:before' => function (Route $route, ?string $path, string $method): Route {
		if ($path !== McpEndpoint::PATH) {
			return $route;
		}

		return new Route($path, $method, fn() => McpEndpoint::handle());
	},
];
