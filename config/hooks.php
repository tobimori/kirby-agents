<?php

declare(strict_types=1);

use Kirby\Cms\User;
use Kirby\Http\Route;
use tobimori\Agents\Http\McpEndpoint;
use tobimori\Agents\OAuth\GrantStore;

return [
	// without a session, GET requests match the login fallback route first
	'panel.route:before' => function (Route $route, ?string $path, string $method): Route {
		if ($path !== McpEndpoint::PATH) {
			return $route;
		}

		return new Route($path, $method, fn() => McpEndpoint::handle());
	},
	// grants were approved with the permissions of the old role
	'user.changeRole:after' => function (User $newUser): void {
		(new GrantStore($newUser))->revokeAll();
	},
];
