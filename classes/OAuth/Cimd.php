<?php

declare(strict_types=1);

namespace tobimori\Agents\OAuth;

use Kirby\Cms\App;
use Throwable;
use tobimori\Agents\Agents;

final class Cimd
{
	private const MAX_BYTES = 10 * 1024;

	private const TIMEOUT = 5;

	public static function client(string $url): ?Client
	{
		if (self::isValidUrl($url) === false) {
			return null;
		}

		$cache = App::instance()->cache('tobimori.agents');
		$key = 'cimd.' . hash('sha256', $url);
		$data = $cache->get($key);

		if (!is_array($data)) {
			$fetched = self::fetch($url);

			if ($fetched === null) {
				return null;
			}

			[$data, $minutes] = $fetched;
			$cache->set($key, $data, $minutes);
		}

		return self::toClient($url, $data);
	}

	private static function isValidUrl(string $url): bool
	{
		$parts = parse_url($url);

		return (
			is_array($parts)
			&& ($parts['scheme'] ?? '') === 'https'
			&& ($parts['host'] ?? '') !== ''
			&& trim($parts['path'] ?? '', '/') !== ''
			&& ($parts['fragment'] ?? null) === null
			&& ($parts['user'] ?? null) === null
		);
	}

	private static function toClient(string $url, array $data): ?Client
	{
		if (($data['client_id'] ?? null) !== $url) {
			return null;
		}

		$registered = $data['redirect_uris'] ?? null;
		$uris = [];

		foreach (is_array($registered) ? $registered : [] as $uri) {
			if (is_string($uri) && RedirectUri::isValid($uri)) {
				$uris[] = $uri;
			}
		}

		if ($uris === []) {
			return null;
		}

		$name = is_string($data['client_name'] ?? null) ? $data['client_name'] : (string) parse_url($url, PHP_URL_HOST);

		return new Client(id: $url, name: $name, redirectUris: $uris, authMethod: 'none');
	}

	/**
	 * @return array{0: array, 1: int}|null
	 */
	private static function fetch(string $url): ?array
	{
		$host = (string) parse_url($url, PHP_URL_HOST);
		$port = (int) (parse_url($url, PHP_URL_PORT) ?? 443);
		$ip = self::publicIp($host);

		if ($ip === null) {
			return null;
		}

		$body = '';
		/** @var array<string, string> $headers */
		$headers = [];
		$curl = curl_init($url);

		curl_setopt_array($curl, [
			CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? "[{$ip}]" : $ip)],
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
			CURLOPT_TIMEOUT => self::TIMEOUT,
			CURLOPT_CAINFO => (string) App::instance()->root('kirby') . '/cacert.pem',
			CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: ' . Agents::userAgent()],
			CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$headers): int {
				$parts = explode(':', $header, 2);

				if (count($parts) === 2) {
					$headers[strtolower(trim($parts[0]))] = trim($parts[1]);
				}

				return strlen($header);
			},
			// returning less than the chunk length stops the transfer
			CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
				$body .= $chunk;

				return strlen($body) > self::MAX_BYTES ? 0 : strlen($chunk);
			},
		]);

		$ok = curl_exec($curl);
		$status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

		if ($ok === false || $status !== 200) {
			return null;
		}

		$data = json_decode($body, true);

		if (!is_array($data)) {
			return null;
		}

		return [$data, self::cacheMinutes($headers['cache-control'] ?? '')];
	}

	private static function publicIp(string $host): ?string
	{
		try {
			$ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : self::resolve($host);
		} catch (Throwable) {
			return null;
		}

		foreach ($ips as $ip) {
			if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
				return null;
			}
		}

		return $ips[0] ?? null;
	}

	/**
	 * @return list<string>
	 */
	private static function resolve(string $host): array
	{
		$records = dns_get_record($host, DNS_A | DNS_AAAA);
		$ips = [];

		foreach (is_array($records) ? $records : [] as $record) {
			$ip = $record['ip'] ?? $record['ipv6'] ?? null;

			if (is_string($ip)) {
				$ips[] = $ip;
			}
		}

		return $ips;
	}

	private static function cacheMinutes(string $cacheControl): int
	{
		$match = [];

		if (preg_match('/max-age=(\d+)/', $cacheControl, $match) !== 1) {
			return 60;
		}

		return max(5, min(1440, intdiv((int) $match[1], 60)));
	}
}
