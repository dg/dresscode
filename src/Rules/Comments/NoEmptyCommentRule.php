<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Comments;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token, Trivia, TriviaKind};
use function count;


/**
 * No comment with nothing in it; one alone on its line takes the line with it. An empty line of a block of line
 * comments is a paragraph break, not an empty comment. An empty doc comment is not a comment problem but a phpDoc one.
 */
#[RuleInfo(
	'dresscode/no-empty-comment',
	Stage::Cleanup,
	description: 'Removes empty comments',
	group: Group::Cleanup,
	modifiesComments: true,
)]
final class NoEmptyCommentRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token) {
			return;
		}

		$blocks = [
			...self::groupComments($node->leadingTrivia, atLineStart: true),
			...self::groupComments($node->trailingTrivia, atLineStart: false),
		];
		foreach ($blocks as $block) {
			if (!array_all($block, fn(Trivia $comment) => $comment->getCommentText() === '')) {
				continue;
			}

			foreach ($block as $comment) {
				if ($context->report($node, 'Empty comment', trivia: $comment)) {
					$node->removeTrivia($comment);
				}
			}
		}
	}


	/**
	 * The comments among the trivia, in blocks: line comments alone on their lines, one under another and written
	 * with the same marker, are one block; a blank line ends it. Every other comment is a block of its own.
	 * @param  list<Trivia>  $trivia
	 * @return list<list<Trivia>>
	 */
	private static function groupComments(array $trivia, bool $atLineStart): array
	{
		$lines = [[]];
		foreach ($trivia as $item) {
			if (!$item->isWhitespace()) {
				$lines[array_key_last($lines)][] = $item;
			}

			if ($item->isEndOfLine()) {
				$lines[] = [];
			}
		}

		$blocks = [];
		$block = []; // the one still open, of line comments alone on their lines
		foreach ($lines as $i => $line) {
			$lone = ($atLineStart || $i > 0) && count($line) === 1 && $line[0]->isLineComment() && !$line[0]->inInterpolation
				? $line[0]
				: null;
			if ($block && $lone?->text[0] !== $block[0]->text[0]) {
				$blocks[] = $block;
				$block = [];
			}

			if ($lone) {
				$block[] = $lone;
				continue;
			}

			foreach ($line as $comment) {
				if ($comment->kind === TriviaKind::Comment && !$comment->inInterpolation) {
					$blocks[] = [$comment];
				}
			}
		}

		return $block ? [...$blocks, $block] : $blocks;
	}
}
