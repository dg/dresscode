<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Files;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token, Trivia};


/**
 * No whitespace at the end of a line, the lines of comments included; the content of strings is left alone.
 */
#[RuleInfo(Stage::Finishing)]
final class NoTrailingWhitespaceRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('file.trailingWhitespace', Domain::state('forbidden'), 'No space or tab at the end of a line')];
	}


	public function getVisitedNodes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token || ($node->leadingTrivia === [] && $node->trailingTrivia === [])) {
			return;
		}

		$leading = self::clean($node, $node->leadingTrivia, true, $context);
		if ($leading !== null) {
			$node->setLeadingTrivia($leading);
		}

		$trailing = self::clean($node, $node->trailingTrivia, false, $context);
		if ($trailing !== null) {
			$node->setTrailingTrivia($trailing);
		}
	}


	/**
	 * The trivia without whitespace before a line ending (or at the end of the file) and without whitespace
	 * ending a line of a comment, each trivia changed reported once; null when nothing changes. A comment that
	 * a close tag ends keeps its whitespace, which stands before the `?>`, not at the end of a line.
	 * @param  list<Trivia>  $trivia
	 * @return ?list<Trivia>
	 */
	private static function clean(Token $token, array $trivia, bool $leading, RuleContext $context): ?array
	{
		$result = [];
		$changed = false;
		foreach ($trivia as $i => $item) {
			$next = $trivia[$i + 1] ?? null;
			$replacement = match (true) {
				$item->inInterpolation => $item,
				$item->is(Trivia::Whitespace) && ($next === null ? $leading && $token->is(Token::EndOfFile) : $next->is(Trivia::LineEnding)) => null,
				$item->is(Trivia::OpenTag) && $next?->is(Trivia::LineEnding) => self::trim($item),
				$item->isComment() => match (true) {
					str_starts_with($item->text, '/*') => self::trimLines($item),
					$next === null && ($leading ? $token : $token->getNext())?->is(Token::CloseTag) => $item,
					default => self::trim($item),
				},
				default => $item,
			};

			if ($replacement !== $item && !$context->report($token, 'Expected no whitespace at the end of the line.', trivia: $item)) {
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


	/** The comment spanning lines without whitespace at the end of its lines. */
	private static function trimLines(Trivia $trivia): Trivia
	{
		$trimmed = preg_replace('~[ \t]+(?=\r?\n)~', '', $trivia->text);
		return $trimmed === $trivia->text ? $trivia : $trivia->withText($trimmed);
	}
}
