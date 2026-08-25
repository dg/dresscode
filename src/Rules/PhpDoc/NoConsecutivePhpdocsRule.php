<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token, Trivia};


/**
 * No doc comment followed by another one with nothing but whitespace between them: PHP gives the code only
 * the last one, and the first one documents nothing. Which of the two is meant, or how they merge, is for
 * the author to say, so the rule only reports.
 */
#[RuleInfo(Stage::Finishing)]
final class NoConsecutivePhpdocsRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('correctness.consecutivePhpdocs', Domain::state('forbidden'), 'A doc comment followed by another one, the first lost to reflection')];
	}


	public function getVisitedNodes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token || !$node->hasComment()) {
			return;
		}

		$own = [...$node->leadingTrivia, ...$node->trailingTrivia];
		$previous = null;
		foreach ([...$node->getPrevious()->trailingTrivia ?? [], ...$own] as $trivia) {
			if ($trivia->is(Trivia::DocComment) && !$trivia->inInterpolation) {
				if ($previous && in_array($trivia, $own, true)) {
					$context->report($node, 'Two doc comments in a row, of which PHP keeps only the second.', trivia: $previous, fixable: false);
				}
				$previous = $trivia;
			} elseif (!$trivia->is(Trivia::Whitespace) && !$trivia->is(Trivia::LineEnding)) {
				$previous = null;
			}
		}
	}
}
