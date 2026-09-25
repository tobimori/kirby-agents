<?php

declare(strict_types=1);

use Kirby\Toolkit\I18n;
use tobimori\Agents\OAuth\Authorization;

/** @var array<string, Closure> $endpoints */
$endpoints = require __DIR__ . '/endpoints.php';
$public = [];

foreach ($endpoints as $pattern => $action) {
	$public["agents.{$pattern}"] = [
		'pattern' => $pattern,
		'method' => 'ALL',
		'auth' => false,
		'action' => $action,
	];
}

return [
	// Kirby only loads the login area for requests without a session
	'login' => fn() => ['views' => $public],
	'agents' => fn() => [
		'label' => I18n::translate('agents.title'),
		'icon' => 'ai',
		'views' => [
			...$public,
			'agents.authorize' => [
				'pattern' => 'agents/authorize/(:any)',
				'action' => fn(string $id) => Authorization::view($id),
			],
			'agents.authorize.decide' => [
				'pattern' => 'agents/authorize/(:any)',
				'method' => 'POST',
				'action' => fn(string $id) => Authorization::decide($id),
			],
		],
	],
];
