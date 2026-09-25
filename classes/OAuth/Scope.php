<?php

declare(strict_types=1);

namespace tobimori\Agents\OAuth;

enum Scope: string
{
	case ContentRead = 'content:read';
	case ContentWrite = 'content:write';
	case ContentPublish = 'content:publish';
	case PagesManage = 'pages:manage';
	case PagesDelete = 'pages:delete';

	/**
	 * @return list<string>
	 */
	public static function all(): array
	{
		return array_map(static fn(self $scope) => $scope->value, self::cases());
	}

	/**
	 * Scopes needed for basic use
	 *
	 * @return list<string>
	 */
	public static function minimal(): array
	{
		return [self::ContentRead->value, self::ContentWrite->value];
	}
}
