<?php

declare(strict_types=1);

namespace tobimori\Agents\Plugins\Retour\Tools;

use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\Scope;
use tobimori\Agents\Plugins\Retour\RedirectInfo;
use tobimori\Agents\Tools\Arguments;
use tobimori\Agents\Tools\Tool;
use tobimori\Agents\Tools\ToolError;

final class FailuresFind implements Tool
{
	public function name(): string
	{
		return 'retour_failures_find';
	}

	public function definition(): array
	{
		return [
			'title' => 'Find 404 errors',
			'description' => implode("\n", [
				'Lists the paths that visitors requested in the last `days` and got a "not found" error for, with the most hits first. Returns `total`, `failures`, and `nextCursor` (null on the last page of results).',
				'Each failure has `path`, `hits`, `lastHit`, and the pages that linked to it (`referrers`). To fix one, find the right page with pages_find and create a redirect with retour_redirect_create. Paths that bots probe, like `wp-login.php`, need no redirect.',
			]),
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'query' => ['type' => 'string', 'description' => 'Text to find in the path'],
					'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3650, 'default' => 30],
					'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50],
					'cursor' => ['type' => 'string', 'description' => '`nextCursor` from the previous result'],
				],
				'additionalProperties' => false,
			],
			'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
		];
	}

	public function scope(): string
	{
		return Scope::RETOUR_REDIRECTS;
	}

	public function call(Arguments $arguments, Access $access): array
	{
		$log = RedirectInfo::log();

		if ($log === null) {
			throw new ToolError('Retour does not log 404 errors on this site (option `distantnative.retour.logs`).');
		}

		$query = mb_strtolower(trim((string) $arguments->string('query')));
		$since = RedirectInfo::since($arguments->int('days', 30, 1, 3650));
		$failures = [];

		// Retour has one entry for each path and referrer, the agent needs one for each path
		foreach (RedirectInfo::failures($log, $since) as $fail) {
			$path = $fail['path'];

			if ($query !== '' && !str_contains(mb_strtolower($path), $query)) {
				continue;
			}

			$failure = $failures[$path] ?? ['path' => $path, 'hits' => 0, 'lastHit' => '', 'referrers' => []];
			$failure['hits'] += $fail['hits'];
			$failure['lastHit'] = max($failure['lastHit'], $fail['last']);

			if ($fail['referrer'] !== null) {
				$failure['referrers'][$fail['referrer']] =
					($failure['referrers'][$fail['referrer']] ?? 0) + $fail['hits'];
			}

			$failures[$path] = $failure;
		}

		usort($failures, static function (array $a, array $b): int {
			$order = $b['hits'] <=> $a['hits'];

			return $order !== 0 ? $order : strcmp($b['lastHit'], $a['lastHit']);
		});

		$limit = $arguments->int('limit', 50, 1, 100);
		$offset = $arguments->offset();

		return [
			'total' => count($failures),
			'failures' => array_map(
				static function (array $failure): array {
					arsort($failure['referrers']);
					$failure['referrers'] = array_slice(array_keys($failure['referrers']), 0, 5);

					return $failure;
				},
				array_slice($failures, $offset, $limit),
			),
			'nextCursor' => Arguments::nextCursor($offset, $limit, count($failures)),
		];
	}
}
