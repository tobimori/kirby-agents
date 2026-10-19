<?php

declare(strict_types=1);

// @mago-expect lint:no-error-control-operator
@include_once __DIR__ . '/vendor/autoload.php';

use Kirby\Cms\App;
use Kirby\Data\Json;
use Kirby\Filesystem\F;

if (version_compare(App::version() ?? '0.0.0', '5.0.0', '<') === true) {
	throw new Exception('Kirby Agents requires Kirby 5 or later');
}

$translations = [];
$files = glob(__DIR__ . '/translations/*.json');

foreach ($files === false ? [] : $files as $file) {
	foreach (Json::read($file) as $key => $value) {
		$translations[F::name($file)]["agents.{$key}"] = $value;
	}
}

App::plugin('tobimori/agents', extends: [
	'options' => require __DIR__ . '/config/options.php',
	'routes' => require __DIR__ . '/config/routes.php',
	'api' => require __DIR__ . '/config/api.php',
	'areas' => require __DIR__ . '/config/areas.php',
	'hooks' => require __DIR__ . '/config/hooks.php',
	'permissions' => [
		'connect' => true,
		'publish' => true,
		'delete' => true,
	],
	'translations' => $translations,
]);
