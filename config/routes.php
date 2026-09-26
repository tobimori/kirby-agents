<?php

declare(strict_types=1);

use Kirby\Http\Route;
use tobimori\Agents\Agents;
use tobimori\Agents\OAuth\Metadata;

// a closure, so the `path` option of the site config is known
return function (): array {
	$routes = [
		[
			// with the resource path (`panel/mcp`) or without a path
			'pattern' => '.well-known/oauth-protected-resource/(:all?)',
			'action' => function (string $path = ''): mixed {
				if ($path !== '' && $path !== Metadata::path(Agents::resource())) {
					Route::next();
				}

				return Metadata::response(Metadata::protectedResource());
			},
		],
		[
			// with the issuer path (`panel`), or without a path if the endpoints are at the site root
			'pattern' => '.well-known/oauth-authorization-server/(:all?)',
			'action' => function (string $path = ''): mixed {
				if ($path !== Metadata::path(Agents::issuer())) {
					Route::next();
				}

				return Metadata::response(Metadata::authorizationServer());
			},
		],
	];

	$path = Agents::path();

	// with the `path` option, the endpoints are routes of the site instead of Panel routes
	if ($path !== null) {
		/** @var array<string, Closure> $endpoints */
		$endpoints = require __DIR__ . '/endpoints.php';

		foreach ($endpoints as $pattern => $action) {
			$routes[] = [
				'pattern' => ltrim($path . '/' . $pattern, '/'),
				'method' => 'ALL',
				'action' => $action,
			];
		}
	}

	return $routes;
};
