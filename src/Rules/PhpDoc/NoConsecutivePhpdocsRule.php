<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\{NodeRule, RuleContext, RuleGroup, RuleInfo, Stage};
use PhpSyntax\{Node, Token, Trivia};


/**
 * No doc comment followed by another one with nothing but whitespace between them: PHP gives the code only
 * the last one, and the first one documents nothing. Which of the two is meant, or how they merge, is for
 * the author to say, so the rule only reports.
 */
#[RuleInfo(
	'dresscode/noConsecutivePhpdocs',
	Stage::Finishing,
	description: 'Reports a doc comment followed by another one',
	group: RuleGroup::Correctness,
)]
final class NoConsecutivePhpdocsRule extends NodeRule
{
	public function getVisitedTypes(): array
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
					$context->report($node, 'Two doc comments in a row', trivia: $previous, fixable: false);
				}
				$previous = $trivia;
			} elseif (!$trivia->is(Trivia::Whitespace) && !$trivia->is(Trivia::LineEnding)) {
				$previous = null;
			}
		}
	}
}
