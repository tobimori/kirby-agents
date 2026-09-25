<?php

declare(strict_types=1);

use Kirby\Http\Route;
use tobimori\Agents\Agents;
use tobimori\Agents\OAuth\Metadata;

return [
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
		// with the issuer path (`panel`)
		'pattern' => '.well-known/oauth-authorization-server/(:all)',
		'action' => function (string $path): mixed {
			if ($path !== Metadata::path(Agents::issuer())) {
				Route::next();
			}

			return Metadata::response(Metadata::authorizationServer());
		},
	],
];
