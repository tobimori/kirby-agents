<?php

declare(strict_types=1);

namespace tobimori\Agents\Plugins\Retour;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Http\Header;
use Kirby\Http\Route;
use Kirby\Retour\Log;
use Kirby\Retour\Redirect;
use Kirby\Retour\Retour;
use tobimori\Agents\Tools\Arguments;
use tobimori\Agents\Tools\ToolError;

/**
 * Reads and checks redirects of Retour for the tools
 */
final class RedirectInfo
{
	public const PATTERN = 'The old path without domain and without leading slash, like `old-page`, or a pattern like `blog/(:any)`';

	public const TARGET = 'Page id like `notes/my-post`, a URL, or a path with the matches of the pattern like `notes/$1`';

	public const PATTERNS = <<<'TEXT'
		`from` can be a pattern with Kirby placeholders: `(:any)` matches one part of the path between slashes, `(:all)` the rest of the path with its slashes, `(:num)` a number, `(:alpha)` letters, `(:alphanum)` letters and digits. Add `?` after a placeholder at the end to make it optional, like `blog/(:any?)`. No `*` and no regular expressions.
		`to` uses the matches as `$1`, `$2` and so on, in their order. For example, `from: blog/(:num)/(:any)` with `to: notes/$2` sends `blog/2024/my-post` to `notes/my-post`. One pattern can fix many 404 errors that follow the same scheme.
		TEXT;

	private const PLACEHOLDER = '/\(:(?:num|alpha|alphanum|any|all)\??\)/';

	public const STATUS = 'HTTP status code: 301 for pages that moved for good, 302 or 307 for a time, 410 for pages that are gone, or `disabled` to keep the redirect without using it';

	public static function retour(): Retour
	{
		return Retour::instance(App::instance());
	}

	/**
	 * The 404 log, or `null` if the site turned it off
	 */
	public static function log(): ?Log
	{
		$log = self::retour()->log();

		return $log instanceof Log ? $log : null;
	}

	/**
	 * The `from` without slashes. The Panel stores what editors type
	 */
	public static function path(Redirect $redirect): string
	{
		return trim((string) $redirect->toArray()['from'], '/');
	}

	public static function get(string $from): ?Redirect
	{
		$path = trim($from, " \n\r\t\v\0/");

		foreach (self::retour()->redirects() as $redirect) {
			if ($redirect instanceof Redirect && self::path($redirect) === $path) {
				return $redirect;
			}
		}

		return null;
	}

	public static function find(string $from): Redirect
	{
		return (
			self::get($from) ?? throw new ToolError(
				"No redirect from `{$from}`. retour_redirects_find lists the redirects.",
			)
		);
	}

	/**
	 * @param array{hits: int, last: ?string}|null $hits
	 *
	 * @return array<string, mixed>
	 */
	public static function summary(Redirect $redirect, ?array $hits = null): array
	{
		$data = $redirect->toArray();
		$to = is_string($data['to']) && $data['to'] !== '' ? $data['to'] : null;
		$page = $to !== null && !str_contains($to, '$') ? App::instance()->page($to) : null;

		return array_filter(
			[
				'from' => $data['from'],
				'to' => $to,
				// the Panel stores pages as UUIDs
				'page' => $page instanceof Page && $page->id() !== $to ? $page->id() : null,
				'status' => $data['status'] ?? 'disabled',
				'priority' => $data['priority'] === true ? true : null,
				'comment' => is_string($data['comment']) && $data['comment'] !== '' ? $data['comment'] : null,
				'creator' => $data['creator'],
				'modifier' => $data['modifier'],
				'hits' => $hits['hits'] ?? null,
				'lastHit' => $hits['last'] ?? null,
			],
			static fn(mixed $value): bool => $value !== null,
		);
	}

	/**
	 * The uses of the redirect since the date
	 *
	 * @return array{hits: int, last: ?string}|null
	 */
	public static function hits(Redirect $redirect, string $since): ?array
	{
		$hits = self::log()?->redirect((string) $redirect->toArray()['from'], $since, date('Y-m-d'));

		if ($hits === null) {
			return null;
		}

		return ['hits' => (int) $hits['hits'], 'last' => is_string($hits['last']) ? $hits['last'] : null];
	}

	/**
	 * The logged 404 errors since the date, one entry for each path and referrer
	 *
	 * @return list<array{path: string, referrer: ?string, last: string, hits: int}>
	 */
	public static function failures(Log $log, string $since): array
	{
		$failures = [];

		foreach ($log->fails($since, date('Y-m-d')) as $fail) {
			if (!is_array($fail)) {
				continue;
			}

			$failures[] = [
				'path' => (string) $fail['path'],
				'referrer' => is_string($fail['referrer']) && $fail['referrer'] !== '' ? $fail['referrer'] : null,
				'last' => (string) $fail['last'],
				'hits' => (int) $fail['hits'],
			];
		}

		return $failures;
	}

	public static function since(int $days): string
	{
		$time = strtotime("-{$days} days");

		return date('Y-m-d', $time === false ? time() : $time);
	}

	/**
	 * The input of the agent over the values of the redirect
	 *
	 * @param array<array-key, mixed> $current
	 *
	 * @return array<array-key, mixed>
	 */
	public static function values(Arguments $arguments, array $current): array
	{
		$values = $current;

		if ($arguments->has('from')) {
			$values['from'] = trim((string) $arguments->string('from'), " \n\r\t\v\0/");
		}

		if ($arguments->has('to')) {
			$values['to'] = self::target(trim((string) $arguments->string('to')));
		}

		if ($arguments->has('status')) {
			$values['status'] = self::status($arguments);
		}

		if ($arguments->has('priority')) {
			$values['priority'] = $arguments->bool('priority', false);
		}

		if ($arguments->has('comment')) {
			$values['comment'] = trim((string) $arguments->string('comment'));
		}

		if (($values['from'] ?? '') === '') {
			throw new ToolError('`from` must be a path, for example `old-page`');
		}

		self::checkPattern((string) $values['from'], is_string($values['to'] ?? null) ? $values['to'] : '');

		$status = $values['status'] ?? null;

		if (is_int($status) && $status >= 300 && $status < 400 && ($values['to'] ?? null) === null) {
			throw new ToolError("A redirect with the status {$status} needs `to`");
		}

		return $values;
	}

	/**
	 * Agents guess `*` or regular expressions, which Kirby takes as a part of the regular expression
	 */
	private static function checkPattern(string $from, string $to): void
	{
		$placeholders = (int) preg_match_all(self::PLACEHOLDER, $from);

		if (preg_match('/[*^$\\\\[\]{}|()?+]/', (string) preg_replace(self::PLACEHOLDER, '', $from)) === 1) {
			throw new ToolError(
				'`from` must be a path, or a pattern with the placeholders `(:any)`, `(:all)`, `(:num)`, `(:alpha)` or `(:alphanum)`, like `blog/(:any)`. No `*` and no regular expressions.',
			);
		}

		preg_match_all('/\$(\d+)/', $to, $matches);
		$used = $matches[1] === [] ? 0 : max(array_map(intval(...), $matches[1]));

		if ($used > $placeholders) {
			throw new ToolError(
				"`to` uses `\${$used}`, but `from` has {$placeholders} placeholders. `\$1` is the match of the first placeholder.",
			);
		}
	}

	/**
	 * Pages as UUIDs like in the Panel, so the redirect survives when the page moves
	 */
	private static function target(string $to): ?string
	{
		if ($to === '') {
			return null;
		}

		// URLs, and paths with placeholders like `blog/$1`
		if (
			str_contains($to, '$')
			|| preg_match('!^[a-z][a-z0-9+.-]*:!i', $to) === 1 && !str_starts_with($to, 'page://')
		) {
			return $to;
		}

		$page = App::instance()->page(trim($to, '/'));

		if (!$page instanceof Page) {
			throw new ToolError(
				"No published page `{$to}`. Send a page id like `blog/my-post`, a URL like `https://example.com`, or a path with placeholders like `blog/$1`.",
			);
		}

		// without UUIDs, `uuid()` is null
		return $page->uuid()?->toString() ?? $page->id();
	}

	private static function status(Arguments $arguments): ?int
	{
		// a number, or the text `disabled`
		try {
			$status = (string) $arguments->int('status', 0, 100, 599);
		} catch (ToolError) {
			$status = (string) $arguments->string('status');
		}

		if ($status === 'disabled') {
			return null;
		}

		if (!ctype_digit($status) || !array_key_exists("_{$status}", Header::$codes)) {
			throw new ToolError('`status` must be an HTTP status code like `301`, or `disabled`');
		}

		return (int) $status;
	}

	/**
	 * Marks the logged 404 errors as resolved that the redirect handles now, like the Panel does
	 *
	 * @return list<string>
	 */
	public static function resolve(Redirect $redirect): array
	{
		$log = self::log();

		if ($log === null || !$redirect->isActive()) {
			return [];
		}

		$pattern = self::path($redirect);
		$route = new Route($pattern, 'GET', static fn(): null => null);
		$resolved = [];

		foreach (self::failures($log, '2000-01-01') as $failure) {
			$path = trim($failure['path'], '/');

			if (!in_array($path, $resolved, true) && $route->parse($pattern, $path) !== false) {
				$log->resolve($failure['path']);
				$resolved[] = $path;
			}
		}

		return $resolved;
	}

	/**
	 * Hints about redirects that Kirby will not use
	 *
	 * @return list<string>
	 */
	public static function notes(Redirect $redirect): array
	{
		$from = self::path($redirect);

		if (!$redirect->isActive()) {
			return ['The redirect is disabled, so it does nothing until it gets a status code.'];
		}

		if (!$redirect->priority() && App::instance()->page($from) instanceof Page) {
			return [
				"The page `{$from}` exists, and pages win over redirects. Set `priority: true` if the redirect should win.",
			];
		}

		return [];
	}
}
