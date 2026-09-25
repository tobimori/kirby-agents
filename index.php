<?php

declare(strict_types=1);

use Kirby\Cms\App;

if (is_file(__DIR__ . '/vendor/autoload.php')) {
	require_once __DIR__ . '/vendor/autoload.php';
}

if (version_compare(App::version() ?? '0.0.0', '5.0.0', '<') === true) {
	throw new Exception('Kirby Agents requires Kirby 5 or later');
}

App::plugin('tobimori/agents', extends: [
	'options' => require __DIR__ . '/config/options.php',
	'routes' => require __DIR__ . '/config/routes.php',
	'areas' => require __DIR__ . '/config/areas.php',
	'hooks' => require __DIR__ . '/config/hooks.php',
]);
