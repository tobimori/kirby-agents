<?php

declare(strict_types=1);

/** @var array<string, Closure> $endpoints */
$endpoints = require __DIR__ . '/endpoints.php';
$views = [];

foreach ($endpoints as $pattern => $action) {
	$views["agents.{$pattern}"] = [
		'pattern' => $pattern,
		'method' => 'ALL',
		'auth' => false,
		'action' => $action,
	];
}

return [
	// Kirby only loads the login area for requests without a session
	'login' => fn() => ['views' => $views],
	'agents' => fn() => ['views' => $views],
];
