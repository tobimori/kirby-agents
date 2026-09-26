<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use tobimori\Agents\Content\InputCheck;
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

	/**
	 * HTML tags of the marks and nodes, to find tags the field does not allow
	 */
	private const TAGS = [
		'bold' => ['strong', 'b'],
		'italic' => ['em', 'i'],
		'underline' => ['u'],
		'strike' => ['s', 'del'],
		'code' => ['code'],
		'link' => ['a'],
		'email' => ['a'],
		'sup' => ['sup'],
		'sub' => ['sub'],
		'paragraph' => ['p'],
		'heading' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
		'bulletList' => ['ul', 'li'],
		'orderedList' => ['ol', 'li'],
		'quote' => ['blockquote'],
	];

	public function describe(Compiler $schema): string
	{
		$tags = array_map(static fn(string $mark): string => self::MARKS[$mark] ?? $mark, $this->marks());

		if ($this->isInline()) {
			return 'inline html without <p>, tags: ' . self::tags($tags) . $this->length();
		}

		$blocks = array_map(static fn(string $node): string => self::NODES[$node] ?? $node, $this->blockNodes());

		return 'html, blocks: ' . self::tags($blocks) . ', inline: ' . self::tags($tags) . $this->length();
	}

	/**
	 * Kirby stores any HTML, because the Panel only offers the enabled marks and nodes.
	 * Tags of other plugins' nodes are unknown here, so they pass.
	 */
	public function check(mixed $value, InputCheck $check, string $where): void
	{
		if (
			!is_string($value)
			|| !$check->isNew($value)
			|| preg_match_all('/<([a-z][a-z0-9]*)\b/i', $value, $matches) === 0
		) {
			return;
		}

		$enabled = [...$this->marks(), ...$this->blockNodes(), ...($this->isInline() ? [] : ['paragraph'])];
		$allowed = [];
		$known = [];

		foreach (self::TAGS as $name => $tags) {
			$known = [...$known, ...$tags];

			if (in_array($name, $enabled, true)) {
				$allowed = [...$allowed, ...$tags];
			}
		}

		$used = array_unique(array_map(strtolower(...), $matches[1]));
		$wrong = array_diff(array_intersect($used, $known), $allowed);

		if ($wrong !== []) {
			$check->error(
				"{$where}: " . self::tags(array_values($wrong)) . ' not allowed. Allowed: '
					. self::tags(array_values(array_unique($allowed))),
			);
		}
	}

	public function prominent(): bool
	{
		return true;
	}

	private function isInline(): bool
	{
		return ($this->props['inline'] ?? false) === true;
	}

	/**
	 * @return list<string>
	 */
	private function marks(): array
	{
		return self::enabled(
			$this->props['marks'] ?? null,
			['bold', 'italic', 'underline', 'strike', 'link', 'email'],
			self::MARKS,
		);
	}

	/**
	 * Block nodes, none in inline mode. Outside inline mode, the writer always wraps text in paragraphs
	 *
	 * @return list<string>
	 */
	private function blockNodes(): array
	{
		if ($this->isInline()) {
			return [];
		}

		$nodes = self::enabled(
			$this->props['nodes'] ?? null,
			['paragraph', 'heading', 'bulletList', 'orderedList'],
			self::NODES,
		);

		return $nodes === [] ? ['paragraph'] : $nodes;
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
