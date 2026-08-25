<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token, TriviaKind};


/**
 * No doc comment followed by another one with nothing but whitespace between them: PHP gives the code only
 * the last one, and the first one documents nothing. Which of the two is meant, or how they merge, is for
 * the author to say, so the rule only reports.
 */
#[RuleInfo(
	'dresscode/no-consecutive-phpdoc',
	Stage::Cleanup,
	description: 'Reports a doc comment followed by another one',
	group: Group::Correctness,
)]
final class NoConsecutivePhpDocRule extends NodeRule
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

		$own = [...$node->leadingTrivia, ...$node->trailingTrivia];
		$previous = null;
		foreach ([...$node->getPrevious()->trailingTrivia ?? [], ...$own] as $trivia) {
			if ($trivia->kind === TriviaKind::DocComment && !$trivia->inInterpolation) {
				if ($previous && in_array($trivia, $own, true)) {
					$context->report($node, 'Two doc comments in a row', trivia: $previous, fixable: false);
				}
				$previous = $trivia;
			} elseif ($trivia->kind !== TriviaKind::Whitespace && $trivia->kind !== TriviaKind::EndOfLine) {
				$previous = null;
			}
		}
	}
}
