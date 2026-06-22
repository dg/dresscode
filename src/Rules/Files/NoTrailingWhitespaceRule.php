<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Files;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token, Trivia};


/**
 * No whitespace at the end of a line, single-line comments included; the content of strings is left alone.
 */
#[RuleInfo(
	'dresscode/noTrailingWhitespace',
	Stage::Finishing,
	description: 'Removes whitespace at the end of lines',
)]
final class NoTrailingWhitespaceRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token || ($node->leadingTrivia === [] && $node->trailingTrivia === [])) {
			return;
		}

		$leading = $this->clean($node, $node->leadingTrivia, $node->id === Token::EndOfFile, $context);
		if ($leading !== null) {
			$node->setLeadingTrivia($leading);
		}

		$trailing = $this->clean($node, $node->trailingTrivia, false, $context);
		if ($trailing !== null) {
			$node->setTrailingTrivia($trailing);
		}
	}


	/**
	 * The trivia without whitespace before a line ending (or at the end of the file) and without whitespace
	 * ending a single-line comment, each removal reported; null when nothing changes.
	 * @param  list<Trivia>  $trivia
	 * @return ?list<Trivia>
	 */
	private function clean(Token $token, array $trivia, bool $atEnd, RuleContext $context): ?array
	{
		$result = [];
		$changed = false;
		foreach ($trivia as $i => $item) {
			$next = $trivia[$i + 1] ?? null;
			$replacement = match (true) {
				$item->inInterpolation => $item,
				$item->id === Trivia::Whitespace && ($next === null ? $atEnd : $next->id === Trivia::LineEnding) => null,
				($item->id === Trivia::OpenTag && $next?->id === Trivia::LineEnding)
				|| ($item->id === Trivia::Comment && !str_starts_with($item->text, '/*')) => self::trim($item),
				default => $item,
			};

			if ($replacement !== $item && !$context->report($token, 'Trailing whitespace', trivia: $item)) {
				$replacement = $item;
			}

			$changed = $changed || $replacement !== $item;
			if ($replacement !== null) {
				$result[] = $replacement;
			}
		}

		return $changed ? $result : null;
	}


	private static function trim(Trivia $trivia): Trivia
	{
		$trimmed = rtrim($trivia->text, " \t");
		return $trimmed === $trivia->text ? $trivia : $trivia->withText($trimmed);
	}
}
