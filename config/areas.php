<?php

declare(strict_types=1);

use Kirby\Cms\App;
use Kirby\Panel\Field;
use Kirby\Toolkit\HtmlString;
use Kirby\Toolkit\I18n;
use tobimori\Agents\Agents;
use tobimori\Agents\License;
use tobimori\Agents\OAuth\Authorization;
use tobimori\Agents\Panel\Grants;

/**
 * @return array<string, array<string, mixed>>
 */
$public = static function (): array {
	if (Agents::path() !== null) {
		return [];
	}

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

	return $views;
};

return [
	// Kirby only loads the login area for requests without a session
	'login' => fn() => ['views' => $public()],
	'agents' => fn() => [
		'label' => I18n::translate('agents.title'),
		'icon' => 'ai',
		'menu' => true,
		'link' => 'agents',
		'views' => [
			...$public(),
			'agents.grants' => [
				'pattern' => 'agents',
				'action' => fn() => Grants::view(),
			],
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
		'dialogs' => [
			'agents.activate' => [
				'pattern' => 'agents/activate',
				'load' => function () {
					$isLocal = App::instance()->system()->isLocal();

					return [
						'component' => 'k-form-dialog',
						'props' => [
							'fields' => [
								'domain' => [
									'label' => I18n::translate('agents.license.activate.label'),
									'type' => 'info',
									'theme' => $isLocal ? 'warning' : 'info',
									'text' => HtmlString::translate(
										'agents.license.activate.' . ($isLocal ? 'local' : 'domain'),
										['domain' => License::normalizeUrl(App::instance()->system()->indexUrl())],
									),
								],
								'email' => Field::email(['required' => true]),
								'license' => [
									'label' => I18n::translate('agents.license.key.label'),
									'type' => 'text',
									'required' => true,
									'counter' => false,
									'help' => HtmlString::translate('agents.license.key.help'),
								],
							],
							'submitButton' => [
								'icon' => 'key',
								'text' => I18n::translate('agents.license.activate'),
								'theme' => 'love',
							],
						],
					];
				},
				'submit' => function () {
					$body = App::instance()->request()->body();

					License::activate(
						email: (string) $body->get('email'),
						license: trim((string) $body->get('license', '')),
					);

					return ['message' => I18n::translate('agents.license.activated')];
				},
			],
			'agents.grants.scopes' => [
				'pattern' => 'agents/grants/(:any)/(:any)/scopes',
				'load' => fn(string $user, string $grant) => Grants::scopesDialog($user, $grant),
				'submit' => fn(string $user, string $grant) => Grants::changeScopes($user, $grant),
			],
			'agents.grants.revoke' => [
				'pattern' => 'agents/grants/(:any)/(:any)/revoke',
				'load' => fn(string $user, string $grant) => Grants::confirm($user, $grant),
				'submit' => fn(string $user, string $grant) => Grants::revoke($user, $grant),
			],
		],
	],
];
