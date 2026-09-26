<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use tobimori\Agents\Schema\Compiler;

/**
 * Writer: HTML with the marks and nodes the blueprint allows
 */
class WriterField extends Field
{
	private const MARKS = [
		'bold' => 'strong',
		'italic' => 'em',
		'underline' => 'u',
		'strike' => 's',
		'code' => 'code',
		'link' => 'a',
		'email' => 'a href="mailto:…"',
		'sup' => 'sup',
		'sub' => 'sub',
	];

	private const NODES = [
		'paragraph' => 'p',
		'heading' => 'h1-h6',
		'bulletList' => 'ul',
		'orderedList' => 'ol',
		'quote' => 'blockquote',
	];

	public function describe(Compiler $schema): string
	{
		$marks = self::enabled(
			$this->props['marks'] ?? null,
			['bold', 'italic', 'underline', 'strike', 'link', 'email'],
			self::MARKS,
		);
		$tags = array_map(static fn(string $mark): string => self::MARKS[$mark] ?? $mark, $marks);

		if (($this->props['inline'] ?? false) === true) {
			return 'inline html without <p>, tags: ' . self::tags($tags) . $this->length();
		}

		// outside inline mode, the writer always wraps text in paragraphs
		$nodes = self::enabled(
			$this->props['nodes'] ?? null,
			['paragraph', 'heading', 'bulletList', 'orderedList'],
			self::NODES,
		);
		$nodes = $nodes === [] ? ['paragraph'] : $nodes;
		$blocks = array_map(static fn(string $node): string => self::NODES[$node] ?? $node, $nodes);

		return 'html, blocks: ' . self::tags($blocks) . ', inline: ' . self::tags($tags) . $this->length();
	}

	public function prominent(): bool
	{
		return true;
	}

	/**
	 * `null` means the defaults, `true` all, `false` none
	 *
	 * @param list<string> $defaults
	 * @param array<string, string> $all
	 *
	 * @return list<string>
	 */
	private static function enabled(mixed $value, array $defaults, array $all): array
	{
		return match (true) {
			$value === true => array_keys($all),
			$value === false => [],
			is_array($value) => array_values(array_filter($value, is_string(...))),
			default => $defaults,
		};
	}

	/**
	 * @param list<string> $tags
	 */
	private static function tags(array $tags): string
	{
		return $tags === [] ? 'none' : implode(' ', array_map(static fn(string $tag): string => "<{$tag}>", $tags));
	}
}
