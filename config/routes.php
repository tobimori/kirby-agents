<?php

declare(strict_types=1);

use Kirby\Http\Route;
use tobimori\Agents\Agents;
use tobimori\Agents\OAuth\Metadata;

// a closure, so the `path` option is known
return function (): array {
	$routes = [
		[
			'pattern' => '.well-known/oauth-protected-resource/(:all?)',
			'action' => function (string $path = ''): mixed {
				if ($path !== '' && $path !== Metadata::path(Agents::resource())) {
					Route::next();
				}

				return Metadata::response(Metadata::protectedResource());
			},
		],
		[
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
