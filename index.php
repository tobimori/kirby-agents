<?php

declare(strict_types=1);

use Kirby\Cms\App;
use Kirby\Data\Json;
use Kirby\Filesystem\F;

// Own classes only. The vendor folder holds dev tools and a Kirby copy for analysis,
// and its autoloader would load that Kirby copy before the site's Kirby.
spl_autoload_register(static function (string $class): void {
	$prefix = 'tobimori\\Agents\\';

	if (str_starts_with($class, $prefix)) {
		$file = __DIR__ . '/classes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

		if (is_file($file)) {
			require_once $file;
		}
	}
});

if (version_compare(App::version() ?? '0.0.0', '5.0.0', '<') === true) {
	throw new Exception('Kirby Agents requires Kirby 5 or later');
}

// translation files have keys without the `agents.` prefix
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
	'areas' => require __DIR__ . '/config/areas.php',
	'hooks' => require __DIR__ . '/config/hooks.php',
	'permissions' => [
		'connect' => true,
		'publish' => true,
		'delete' => true,
	],
	'translations' => $translations,
]);
