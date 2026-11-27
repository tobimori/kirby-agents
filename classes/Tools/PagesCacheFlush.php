<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use Kirby\Cms\App;
use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\Scope;

final class PagesCacheFlush implements Tool
{
	private const STILL_OLD = 'If the site still shows old content, look for unsaved changes (changes_publish), a draft (page_update `status`), or a CDN or browser cache.';

	public function name(): string
	{
		return 'pages_cache_flush';
	}

	public function definition(): array
	{
		return [
			'title' => 'Flush the pages cache',
			'description' => 'Empties the pages cache, so each page renders again with the current content, templates and config. Call it when the user says the site shows old content or does not show a change, also when you are not sure that the cache is the cause. Safe without asking the user: it changes no content. Do not call it otherwise.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => (object) [],
				'additionalProperties' => false,
			],
			'annotations' => [
				'readOnlyHint' => false,
				'destructiveHint' => false,
				'idempotentHint' => true,
				'openWorldHint' => false,
			],
		];
	}

	public function scope(): string
	{
		return Scope::CONTENT_WRITE;
	}

	public function call(Arguments $arguments, Access $access): string
	{
		$cache = App::instance()->cache('pages');

		if ($cache->enabled() === false) {
			return 'The pages cache is off on this site, so Kirby renders each page at each visit. ' . self::STILL_OLD;
		}

		if ($cache->flush() === false) {
			throw new ToolError(
				'Kirby could not flush the pages cache. Tell the user: a developer must check the cache on the server.',
			);
		}

		return 'Flushed the pages cache. Each page renders again at its next visit. ' . self::STILL_OLD;
	}
}
