<?php

declare(strict_types=1);

namespace tobimori\Agents\Content;

use Kirby\Cms\File;
use Kirby\Image\Image;
use Kirby\Uuid\FileUuid;

final class FileInfo
{
	public const FIELDS = 'Each file has: `id` (use it with the content tools, for example to change its `alt` text), `uuid` (null until the file has a stored UUID), `filename`, `template`, `type` (image, document, video, …), `mime`, `size` in bytes, `width` and `height` for images, `url`, `changes` (true when the file has unsaved changes).';

	/**
	 * @return array{id: string, uuid: string|null, filename: string, template: string|null, type: string|null, mime: string|null, size: int, width?: int, height?: int, url: string, changes: bool}
	 */
	public static function summary(File $file): array
	{
		// `uuid()` would generate and write a missing UUID, which a read must not do
		$uuid = FileUuid::retrieveId($file);
		$asset = $file->asset();
		$summary = [
			'id' => $file->id(),
			'uuid' => $uuid !== null ? 'file://' . $uuid : null,
			'filename' => $file->filename(),
			'template' => $file->template(),
			'type' => $file->type(),
			'mime' => $asset->mime(),
			'size' => $asset->size(),
			'url' => $file->url(),
			'changes' => $file->version('changes')->exists('*'),
		];

		if ($asset instanceof Image) {
			$summary['width'] = $asset->width();
			$summary['height'] = $asset->height();
		}

		return $summary;
	}
}
