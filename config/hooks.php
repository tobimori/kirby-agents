<?php

declare(strict_types=1);

use Kirby\Cms\User;
use Kirby\Http\Route;
use tobimori\Agents\Agents;
use tobimori\Agents\OAuth\GrantStore;

/** @var array<string, Closure> $endpoints */
$endpoints = require __DIR__ . '/endpoints.php';

return [
	// without a session, GET requests match the login fallback route first
	'panel.route:before' => function (Route $route, ?string $path, string $method) use ($endpoints): Route {
		$action = Agents::path() === null ? $endpoints[$path ?? ''] ?? null : null;

		return $action === null ? $route : new Route((string) $path, $method, $action);
	},
	'user.changeRole:after' => function (User $newUser): void {
		(new GrantStore($newUser))->revokeAll();
	},
];
