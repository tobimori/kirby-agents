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

	public const TARGET = 'Page id like `notes/my-post`, `/` for the home page, a URL, or a path with the matches of the pattern like `notes/$1`. Add the extension for a content representation, like `notes.rss`';

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
		[$page, $extension] = $to !== null && !str_contains($to, '$') ? self::page($to) : [null, ''];

		return array_filter(
			[
				'from' => $data['from'],
				'to' => $to,
				// the Panel stores pages as UUIDs
				'page' => $page !== null && $page->id() . $extension !== $to ? $page->id() . $extension : null,
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

		// redirects from the Panel can have patterns that agents may not send, so only new values are checked
		if ($arguments->has('from')) {
			self::checkPattern((string) $values['from']);
		}

		if ($arguments->has('from') || $arguments->has('to')) {
			self::checkMatches((string) $values['from'], is_string($values['to'] ?? null) ? $values['to'] : '');
		}

		$status = $values['status'] ?? null;

		if (is_int($status) && $status >= 300 && $status < 400 && ($values['to'] ?? null) === null) {
			throw new ToolError("A redirect with the status {$status} needs `to`");
		}

		return $values;
	}

	/**
	 * Agents guess `*` or regular expressions, which Kirby takes as a part of the regular expression,
	 * and URLs, which never match the path of a request
	 */
	private static function checkPattern(string $from): void
	{
		if (preg_match('!^[a-z][a-z0-9+.-]*://!i', $from) === 1) {
			throw new ToolError('`from` must be a path without the domain, like `old-page`');
		}

		if (self::isRegex($from)) {
			throw new ToolError(
				'`from` must be a path, or a pattern with the placeholders `(:any)`, `(:all)`, `(:num)`, `(:alpha)` or `(:alphanum)`, like `blog/(:any)`. No `*` and no regular expressions.',
			);
		}
	}

	private static function isRegex(string $from): bool
	{
		return preg_match('/[*^$\\\\[\]{}|()?+]/', (string) preg_replace(self::PLACEHOLDER, '', $from)) === 1;
	}

	/**
	 * The matches `$1`, `$2` and so on in `to` need a placeholder in `from`
	 */
	private static function checkMatches(string $from, string $to): void
	{
		// editors can use regular expressions in the Panel, with groups that are not counted here
		if (self::isRegex($from)) {
			return;
		}

		$placeholders = (int) preg_match_all(self::PLACEHOLDER, $from);
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

		// the home page for Retour, URLs, and paths with placeholders like `blog/$1`
		if (
			$to === '/'
			|| str_contains($to, '$')
			|| preg_match('!^[a-z][a-z0-9+.-]*:!i', $to) === 1 && !str_starts_with($to, 'page://')
		) {
			return $to;
		}

		[$page, $extension] = self::page($to);

		if ($page === null) {
			throw new ToolError(
				"No published page `{$to}`. Send a page id like `blog/my-post`, `/` for the home page, a URL like `https://example.com`, or a path with placeholders like `blog/$1`.",
			);
		}

		// without UUIDs, `uuid()` is null
		return ($page->uuid()?->toString() ?? $page->id()) . $extension;
	}

	/**
	 * The published page of a target, and the extension of a content representation like `.rss`.
	 * Retour finds the page the same way, and adds the extension to its URL
	 *
	 * @return array{0: ?Page, 1: string}
	 */
	private static function page(string $to): array
	{
		$extension = pathinfo($to, PATHINFO_EXTENSION);
		$path = $extension === '' ? $to : substr($to, 0, -strlen($extension) - 1);
		$page = App::instance()->page(trim($path, '/'));

		return [
			$page instanceof Page && !$page->isDraft() ? $page : null,
			$extension === '' ? '' : ".{$extension}",
		];
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
