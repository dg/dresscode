<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Comments;

use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Domains\Shapes;
use PhpSyntax\{Node, Token, Trivia};


/**
 * The `//` form for a single-line comment, not the `#` one.
 */
#[RuleInfo(Stage::Finishing, modifiesComments: true)]
final class NoHashCommentsRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('comments.singleline', new Shapes(['slashes' => ['//', '`//`, never `#`']]), 'The marker of a single-line comment')];
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

		foreach ([...$node->leadingTrivia, ...$node->trailingTrivia] as $item) {
			if (
				$item->is(Trivia::Comment)
				&& !$item->inInterpolation
				&& str_starts_with($item->text, '#')
				&& $context->report($node, 'A single-line comment must start with `//`, not `#`.', trivia: $item)
			) {
				$node->replaceTrivia($item, $item->withText('//' . substr($item->text, 1)));
			}
		}
	}
}
