<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Comments;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token, Trivia};


/**
 * The `//` form for a single-line comment, not the `#` one.
 */
#[RuleInfo(
	'dresscode/noHashComments',
	Stage::Finishing,
	description: 'Writes single-line comments with `//`, not `#`',
	modifiesComments: true,
)]
final class NoHashCommentsRule extends NodeRule
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

		foreach (['leadingTrivia', 'trailingTrivia'] as $side) {
			$trivia = $node->$side;
			$changed = false;
			foreach ($trivia as $i => $item) {
				if (
					$item->is(Trivia::Comment)
					&& !$item->inInterpolation
					&& str_starts_with($item->text, '#')
					&& $context->report($node, 'A single-line comment must start with `//`, not `#`', trivia: $item)
				) {
					$trivia[$i] = $item->withText('//' . substr($item->text, 1));
					$changed = true;
				}
			}

			if ($changed) {
				$side === 'leadingTrivia' ? $node->setLeadingTrivia($trivia) : $node->setTrailingTrivia($trivia);
			}
		}
	}
}
