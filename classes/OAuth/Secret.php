<?php

declare(strict_types=1);

namespace tobimori\Agents\OAuth;

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use RuntimeException;
use tobimori\Agents\Agents;

final class Secret
{
	private static ?string $key = null;

	/**
	 * HMAC key from the `secret` option, or from a file that is generated on first use
	 */
	public static function key(): string
	{
		if (self::$key !== null) {
			return self::$key;
		}

		$option = Agents::option('secret');

		if (is_string($option) && $option !== '') {
			return self::$key = $option;
		}

		return self::$key = self::fromFile((string) App::instance()->root('accounts') . '/.agents-secret');
	}

	public static function sign(string $data): string
	{
		return Token::encode(hash_hmac('sha256', $data, self::key(), true));
	}

	/**
	 * Reads the key, or writes a new one if the file is empty.
	 * The lock makes sure that parallel requests use the same key.
	 */
	private static function fromFile(string $file): string
	{
		Dir::make(dirname($file));

		$handle = fopen($file, 'c+');

		if ($handle === false || flock($handle, LOCK_EX) === false) {
			throw new RuntimeException('Cannot open the secret file');
		}

		try {
			$key = trim((string) stream_get_contents($handle));

			if ($key === '') {
				$key = bin2hex(random_bytes(32));
				fwrite($handle, $key);
				fflush($handle);
				chmod($file, 0o600);
			}

			return $key;
		} finally {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}
}
