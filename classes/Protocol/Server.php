<?php

declare(strict_types=1);

namespace tobimori\Agents\Protocol;

use Kirby\Cms\App;
use Kirby\Exception\Exception as KirbyException;
use Kirby\Http\Request;
use Kirby\Http\Response;
use Throwable;
use tobimori\Agents\Agents;
use tobimori\Agents\Http\McpEndpoint;
use tobimori\Agents\OAuth\Access;
use tobimori\Agents\Tools\Arguments;
use tobimori\Agents\Tools\Guide;
use tobimori\Agents\Tools\ScopeRequired;
use tobimori\Agents\Tools\Tool;
use tobimori\Agents\Tools\ToolError;
use tobimori\Agents\Tools\Tools;

/**
 * Stateless MCP over HTTP, for both protocol eras:
 * - modern (2026-07-28): each request carries its version and capabilities in `_meta`
 * - legacy (2025-11-25 and earlier): `initialize` is answered, but no session is kept
 */
final class Server
{
	private const MODERN = ['2026-07-28'];

	private const LEGACY = ['2025-11-25', '2025-06-18', '2025-03-26'];

	private const PARSE_ERROR = -32700;

	private const INVALID_REQUEST = -32600;

	private const METHOD_NOT_FOUND = -32601;

	private const INVALID_PARAMS = -32602;

	private const INTERNAL_ERROR = -32603;

	private const HEADER_MISMATCH = -32020;

	private const UNSUPPORTED_VERSION = -32022;

	public static function handle(Request $request, Access $access): Response
	{
		// Kirby returns the parsed form data if the client sent no JSON content type
		$body = $request->body()->contents();
		$body = is_string($body) ? $body : (string) file_get_contents('php://input');
		$message = json_decode($body, true);

		if (!is_array($message) || array_is_list($message)) {
			return self::error(null, self::PARSE_ERROR, 'Send one JSON-RPC message as a JSON object', 400);
		}

		$method = $message['method'] ?? null;

		// notifications, and responses from legacy clients, need no answer
		if (!array_key_exists('id', $message) || !is_string($method)) {
			return new Response('', null, 202);
		}

		$id = $message['id'];

		if (!is_string($id) && !is_int($id)) {
			return self::error(null, self::INVALID_REQUEST, 'The id must be a string or an integer', 400);
		}

		$params = is_array($message['params'] ?? null) ? $message['params'] : [];
		$meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
		$version = $meta['io.modelcontextprotocol/protocolVersion'] ?? null;
		$modern = is_string($version);

		if ($modern) {
			$invalid = self::validate($request, $id, $method, $params, $version, $meta);

			if ($invalid !== null) {
				return $invalid;
			}
		}

		try {
			return match ($method) {
				'initialize' => self::result($id, self::initialize($params)),
				'server/discover' => self::result($id, self::discover()),
				'ping' => self::result($id, []),
				'tools/list' => self::result($id, self::listTools($access)),
				'tools/call' => self::callTool($id, $params, $access),
				default => self::error($id, self::METHOD_NOT_FOUND, "Method not found: {$method}", $modern ? 404 : 200),
			};
		} catch (Throwable $error) {
			return self::error(
				$id,
				self::INTERNAL_ERROR,
				App::instance()->option('debug') ? $error->getMessage() : 'Internal error',
				500,
			);
		}
	}

	/**
	 * Headers must mirror the body, and the version must be supported
	 */
	private static function validate(
		Request $request,
		string|int $id,
		string $method,
		array $params,
		string $version,
		array $meta,
	): ?Response {
		if (!in_array($version, self::MODERN, true)) {
			return self::error($id, self::UNSUPPORTED_VERSION, 'Unsupported protocol version', 400, [
				'supported' => self::versions(),
				'requested' => $version,
			]);
		}

		$name = $method === 'tools/call' ? $params['name'] ?? null : null;

		if (
			$request->header('MCP-Protocol-Version') !== $version
			|| $request->header('Mcp-Method') !== $method
			|| $name !== null && self::decodeHeader($request->header('Mcp-Name')) !== $name
		) {
			return self::error($id, self::HEADER_MISMATCH, 'Headers are missing or do not match the body', 400);
		}

		if (!is_array($meta['io.modelcontextprotocol/clientCapabilities'] ?? null)) {
			return self::error(
				$id,
				self::INVALID_PARAMS,
				'Missing _meta io.modelcontextprotocol/clientCapabilities',
				400,
			);
		}

		return null;
	}

	/**
	 * Header values outside plain ASCII come as `=?base64?...?=`
	 */
	private static function decodeHeader(mixed $value): ?string
	{
		if (!is_string($value)) {
			return null;
		}

		if (str_starts_with($value, '=?base64?') && str_ends_with($value, '?=')) {
			$decoded = base64_decode(substr($value, 9, -2), true);

			return $decoded === false ? null : $decoded;
		}

		return $value;
	}

	private static function initialize(array $params): array
	{
		$requested = $params['protocolVersion'] ?? null;

		return [
			'protocolVersion' => in_array($requested, self::LEGACY, true) ? $requested : self::LEGACY[0],
			'capabilities' => self::capabilities(),
			'serverInfo' => self::serverInfo(),
			'instructions' => self::instructions(),
		];
	}

	private static function discover(): array
	{
		return [
			'supportedVersions' => self::versions(),
			'capabilities' => self::capabilities(),
			'instructions' => self::instructions(),
			'ttlMs' => 5 * 60 * 1000,
			'cacheScope' => 'private',
		];
	}

	/**
	 * Tool definitions for the role, with the parameter guide in the description.
	 * Also used by the WebMCP bridge.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function definitions(Access $access): array
	{
		return array_map(static function (Tool $tool) use ($access): array {
			$definition = $tool->definition();
			$schema = is_array($definition['inputSchema'] ?? null) ? $definition['inputSchema'] : [];
			$summary = is_string($definition['description'] ?? null) ? $definition['description'] : '';
			$definition['description'] = $summary . "\n\n" . Guide::parameters($schema);

			if ($access->allows($tool->scope()) === false) {
				$definition['description'] =
					"Needs the `{$tool->scope()->value}` scope, which this connection does not have yet. A call asks the user to allow it in the browser, so ask the user first.\n\n"
					. $definition['description'];
			}

			return ['name' => $tool->name(), ...$definition];
		}, Tools::for($access));
	}

	/**
	 * Runs a tool and returns an MCP tool result. Rule errors are results with `isError`.
	 * Also used by the WebMCP bridge.
	 *
	 * @throws ScopeRequired if the access needs another scope
	 *
	 * @return array<string, mixed>
	 */
	public static function run(Tool $tool, array $arguments, Access $access): array
	{
		if ($access->allows($tool->scope()) === false) {
			throw new ScopeRequired($tool->scope());
		}

		try {
			$data = $tool->call(new Arguments($arguments), $access);
		} catch (ToolError|KirbyException $error) {
			// Kirby exceptions are rule violations with messages for users, like a duplicate slug
			return ['content' => [['type' => 'text', 'text' => $error->getMessage()]], 'isError' => true];
		}

		if (is_string($data)) {
			return ['content' => [['type' => 'text', 'text' => $data]]];
		}

		return ['content' => [['type' => 'text', 'text' => self::json($data)]], 'structuredContent' => $data];
	}

	private static function listTools(Access $access): array
	{
		return [
			'tools' => self::definitions($access),
			// the list depends on the role and the token scopes
			'ttlMs' => 5 * 60 * 1000,
			'cacheScope' => 'private',
		];
	}

	private static function callTool(string|int $id, array $params, Access $access): Response
	{
		$name = $params['name'] ?? null;
		$tool = is_string($name) ? Tools::find($name) : null;

		if ($tool === null) {
			return self::error($id, self::INVALID_PARAMS, 'Unknown tool: ' . (is_string($name) ? $name : ''), 400);
		}

		try {
			$result = self::run($tool, is_array($params['arguments'] ?? null) ? $params['arguments'] : [], $access);
		} catch (ScopeRequired $error) {
			return McpEndpoint::insufficientScope($error->scope, $access);
		}

		return self::result($id, $result);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function capabilities(): array
	{
		return ['tools' => ['listChanged' => false]];
	}

	private static function serverInfo(): array
	{
		return [
			'name' => 'kirby-agents',
			'title' => 'Kirby: ' . Agents::siteTitle(),
			'version' => App::plugin('tobimori/agents')?->version() ?? 'dev',
		];
	}

	private static function instructions(): string
	{
		return (
			'Kirby CMS site "'
			. Agents::siteTitle()
			. '". '
			. 'All tools act as the connected Kirby user, with the permissions of their role. '
			. 'Start with site_overview to see the page blueprints and the page tree, then use pages_find. '
			. 'Pages are identified by their id, which is their path, for example `blog/my-post`.'
		);
	}

	/**
	 * @return list<string>
	 */
	private static function versions(): array
	{
		return [...self::MODERN, ...self::LEGACY];
	}

	private static function result(string|int $id, array $result): Response
	{
		$meta = is_array($result['_meta'] ?? null) ? $result['_meta'] : [];
		$meta['io.modelcontextprotocol/serverInfo'] = self::serverInfo();
		$result['_meta'] = $meta;
		$result['resultType'] ??= 'complete';

		return Response::json(self::json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]));
	}

	private static function error(
		string|int|null $id,
		int $code,
		string $message,
		int $status,
		?array $data = null,
	): Response {
		$error = ['code' => $code, 'message' => $message];

		if ($data !== null) {
			$error['data'] = $data;
		}

		return Response::json(self::json(['jsonrpc' => '2.0', 'id' => $id, 'error' => $error]), $status);
	}

	private static function json(mixed $data): string
	{
		return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}
}
