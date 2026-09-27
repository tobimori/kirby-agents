<?php

declare(strict_types=1);

namespace tobimori\Agents\Http;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Cms\User;
use Kirby\Exception\Exception as KirbyException;
use Kirby\Http\Response;
use SensitiveParameter;
use tobimori\Agents\Agents;
use tobimori\Agents\Content\FileInfo;
use tobimori\Agents\Content\Models;
use tobimori\Agents\Lifecycle\Uploads;
use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\GrantStore;
use tobimori\Agents\OAuth\Scope;
use tobimori\Agents\OAuth\Secret;
use tobimori\Agents\OAuth\Token;
use tobimori\Agents\Tools\ToolError;

final class UploadEndpoint
{
	public const PATH = 'mcp/upload/(:any)';

	private const PREFIX = 'kau';

	private const TTL = 600;

	public const MAX_CONTENT = 4000;

	/**
	 * @param array<string, mixed> $content
	 *
	 * @return array{url: string, expires: int}
	 */
	public static function link(
		Access $access,
		Site|Page $parent,
		string $template,
		string $filename,
		array $content,
	): array {
		$expires = time() + self::TTL;
		$payload = Token::encode((string) json_encode([
			'u' => $access->user->id(),
			'g' => $access->grant,
			'p' => $parent instanceof Page ? $parent->id() : 'site',
			't' => $template,
			'f' => $filename,
			'c' => $content,
			'e' => $expires,
		]));
		$token = self::PREFIX . '.' . $payload . '.' . Secret::sign(self::PREFIX . '.' . $payload);

		return ['url' => Agents::resource() . '/upload/' . $token, 'expires' => $expires];
	}

	public static function handle(#[SensitiveParameter] string $token): Response
	{
		$kirby = App::instance();
		$request = $kirby->request();
		$error = Guard::https($request);

		if ($error !== null) {
			return $error;
		}

		if ($request->method() !== 'POST') {
			return new Response('', null, 405, ['Allow' => 'POST']);
		}

		$data = self::parse($token);

		if ($data === null) {
			return self::error(401, 'The upload link is not valid or expired. Call file_upload again.');
		}

		$limited = RateLimit::hit('upload', 'grant ' . $data['grant']);

		if ($limited !== null) {
			return $limited;
		}

		$kirby->auth()->setUser($data['user']);
		$upload = $request->files()->get('file');

		if (
			!is_array($upload)
			|| ($upload['error'] ?? null) !== UPLOAD_ERR_OK
			|| !is_string($upload['tmp_name'] ?? null)
		) {
			$code = is_array($upload) ? $upload['error'] ?? null : null;

			return self::error(400, match ($code) {
				UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than the server allows',
				null
					=> 'Send the file as multipart form data in the field `file`, for example `curl -F file=@photo.jpg <url>`',
				default => 'The upload failed',
			});
		}

		try {
			$parent = Models::find($data['page']);
			$file = $parent->createFile([
				'source' => $upload['tmp_name'],
				'filename' => $data['filename'],
				'template' => $data['template'] === Uploads::DEFAULT ? null : $data['template'],
				'content' => [...$data['content'], 'sort' => $parent->files()->count() + 1],
			], move: true);
		} catch (KirbyException|ToolError $exception) {
			return self::error(400, $exception->getMessage());
		}

		return Response::json([
			'file' => FileInfo::summary($file),
			'next' => 'The file is public now. Change its fields with content_update and the file id. Use its `uuid` in files fields.',
		], 201);
	}

	/**
	 * @return array{user: User, grant: string, page: string, template: string, filename: string, content: array<array-key, mixed>}|null
	 */
	private static function parse(#[SensitiveParameter] string $token): ?array
	{
		$parts = explode('.', $token);

		if (
			count($parts) !== 3
			|| $parts[0] !== self::PREFIX
			|| !hash_equals(Secret::sign(self::PREFIX . '.' . $parts[1]), $parts[2])
		) {
			return null;
		}

		$data = json_decode(Token::decode($parts[1]), true);

		if (
			!is_array($data)
			|| !is_int($data['e'] ?? null)
			|| $data['e'] < time()
			|| !is_string($data['u'] ?? null)
			|| !is_string($data['g'] ?? null)
			|| !is_string($data['p'] ?? null)
			|| !is_string($data['t'] ?? null)
			|| !is_string($data['f'] ?? null)
			|| !is_array($data['c'] ?? null)
		) {
			return null;
		}

		$user = App::instance()->users()->find($data['u']);
		$grant = $user instanceof User ? (new GrantStore($user))->find($data['g']) : null;

		if (!$user instanceof User || $grant === null) {
			return null;
		}

		$access = new Access($user, $grant->id, Scope::allowedFor($user, $grant->scopes));

		if ($access->allows(Scope::FilesManage) === false) {
			return null;
		}

		return [
			'user' => $user,
			'grant' => $grant->id,
			'page' => $data['p'],
			'template' => $data['t'],
			'filename' => $data['f'],
			'content' => $data['c'],
		];
	}

	private static function error(int $status, string $message): Response
	{
		return Response::json(['error' => $message], $status);
	}
}
