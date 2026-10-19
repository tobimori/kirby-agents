<?php

declare(strict_types=1);

namespace tobimori\Agents\Plugins\Retour\Tools;

use Kirby\Retour\Redirect;
use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\Scope;
use tobimori\Agents\Plugins\Retour\RedirectInfo;
use tobimori\Agents\Tools\Arguments;
use tobimori\Agents\Tools\Tool;

final class RedirectsFind implements Tool
{
	public function name(): string
	{
		return 'retour_redirects_find';
	}

	public function definition(): array
	{
		return [
			'title' => 'Find redirects',
			'description' => implode("\n", [
				'Lists the redirects of the Retour plugin, newest first. Returns `total`, `redirects`, and `nextCursor` (null on the last page of results).',
				'Each redirect has `from`, `to`, and `status`. `to` is a page UUID like `page://abc`, a URL, or a path; `page` is the page id when `to` is a page. `hits` and `lastHit` count the uses in the last `days`, if Retour logs them.',
			]),
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'query' => [
						'type' => 'string',
						'description' => 'Text to find in `from`, `to`, the page id, or the comment',
					],
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
		$query = mb_strtolower(trim((string) $arguments->string('query')));
		$since = RedirectInfo::since($arguments->int('days', 30, 1, 3650));
		$redirects = [];

		foreach (RedirectInfo::retour()->redirects() as $redirect) {
			if (!$redirect instanceof Redirect) {
				continue;
			}

			$summary = RedirectInfo::summary($redirect);
			$text = mb_strtolower(implode(' ', array_filter(
				[$summary['from'], $summary['to'] ?? null, $summary['page'] ?? null, $summary['comment'] ?? null],
				is_string(...),
			)));

			if ($query === '' || str_contains($text, $query)) {
				$redirects[] = $redirect;
			}
		}

		$limit = $arguments->int('limit', 50, 1, 100);
		$offset = $arguments->offset();

		return [
			'total' => count($redirects),
			'redirects' => array_map(
				static fn(Redirect $redirect): array => RedirectInfo::summary($redirect, RedirectInfo::hits(
					$redirect,
					$since,
				)),
				array_slice($redirects, $offset, $limit),
			),
			'nextCursor' => Arguments::nextCursor($offset, $limit, count($redirects)),
		];
	}
}
