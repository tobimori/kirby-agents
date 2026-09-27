<?php

declare(strict_types=1);

namespace tobimori\Agents\Fields;

use DOMElement;
use DOMNode;
use DOMText;
use Kirby\Toolkit\A;
use Kirby\Toolkit\Dom;
use tobimori\Agents\Content\InputCheck;
use tobimori\Agents\Schema\Compiler;

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
		'bulletList' => 'ul',
		'orderedList' => 'ol',
		'quote' => 'blockquote',
	];

	// headings come from the `headings` option
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
		'bulletList' => ['ul', 'li'],
		'orderedList' => ['ol', 'li'],
		'quote' => ['blockquote'],
	];

	// HTML elements that must not go into a paragraph
	private const BLOCKS = [
		'address',
		'article',
		'aside',
		'blockquote',
		'details',
		'div',
		'dl',
		'fieldset',
		'figure',
		'footer',
		'form',
		'h1',
		'h2',
		'h3',
		'h4',
		'h5',
		'h6',
		'header',
		'hr',
		'main',
		'nav',
		'ol',
		'p',
		'pre',
		'section',
		'table',
		'ul',
	];

	public function describe(Compiler $schema): string
	{
		$tags = array_map(static fn(string $mark): string => self::MARKS[$mark] ?? $mark, $this->marks());

		if ($this->isInline()) {
			return 'inline html without <p>, tags: ' . self::tags($tags) . $this->length();
		}

		$blocks = [];

		foreach ($this->blockNodes() as $node) {
			if ($node === 'heading') {
				$blocks = [...$blocks, ...$this->headings()];

				continue;
			}

			$blocks[] = self::NODES[$node] ?? $node;
		}

		return 'html, blocks: ' . self::tags($blocks) . ', inline: ' . self::tags($tags) . $this->length();
	}

	public function input(mixed $value, mixed $current): mixed
	{
		if (!is_string($value) || $this->isInline()) {
			return $value;
		}

		return self::paragraphs($value);
	}

	public function check(mixed $value, InputCheck $check, string $where): void
	{
		if (
			!is_string($value)
			|| !$check->isNew($value)
			|| preg_match_all('/<([a-z][a-z0-9]*)\b/i', $value, $matches) === 0
		) {
			return;
		}

		$allowed = $this->allowedTags();
		$used = array_unique(array_map(strtolower(...), $matches[1]));
		$wrong = array_diff($used, $allowed);

		if ($wrong !== []) {
			$check->error(
				"{$where}: " . self::tags(array_values($wrong)) . ' not allowed. Allowed: ' . self::tags($allowed),
			);
		}
	}

	public function prominent(): bool
	{
		return true;
	}

	/**
	 * @return list<string>
	 */
	private function allowedTags(): array
	{
		// hard breaks are always on
		$tags = ['br'];

		if ($this->isInline() === false) {
			// list items contain paragraphs
			$tags[] = 'p';
		}

		foreach ([...$this->marks(), ...$this->blockNodes()] as $name) {
			if ($name === 'heading') {
				$tags = [...$tags, ...$this->headings()];

				continue;
			}

			$tags = [...$tags, ...(self::TAGS[$name] ?? [])];
		}

		return array_values(array_unique($tags));
	}

	/**
	 * @return list<string>
	 */
	private function headings(): array
	{
		$levels = A::wrap($this->props['headings'] ?? range(1, 6));

		return array_values(array_map(static fn(mixed $level): string => 'h' . (int) $level, $levels));
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
			array_keys(self::MARKS),
		);
	}

	/**
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
			['heading', ...array_keys(self::NODES)],
		);

		return $nodes === [] ? ['paragraph'] : $nodes;
	}

	/**
	 * Puts text outside of blocks into paragraphs, as the Panel does
	 */
	private static function paragraphs(string $html): string
	{
		$dom = new Dom($html);
		$body = $dom->body();

		if ($body === null) {
			return $html;
		}

		$paragraph = null;
		$changed = false;

		foreach (iterator_to_array($body->childNodes) as $node) {
			if (!$node instanceof DOMNode) {
				continue;
			}

			$isBlock = $node instanceof DOMElement && in_array(strtolower($node->tagName), self::BLOCKS, true);
			$isSpace = $node instanceof DOMText && trim($node->textContent) === '';

			if ($isBlock || $isSpace && $paragraph === null) {
				$paragraph = null;

				continue;
			}

			if ($paragraph === null) {
				$paragraph = new DOMElement('p');
				$body->insertBefore($paragraph, $node);
				$changed = true;
			}

			$paragraph->appendChild($node);
		}

		if ($changed === false) {
			return $html;
		}

		return $dom->innerMarkup($body);
	}

	/**
	 * @param list<string> $defaults
	 * @param list<string> $all
	 *
	 * @return list<string>
	 */
	private static function enabled(mixed $value, array $defaults, array $all): array
	{
		return match (true) {
			$value === true => $all,
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
