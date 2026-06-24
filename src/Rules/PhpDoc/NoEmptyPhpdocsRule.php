<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\{NodeRule, RuleContext, RuleGroup, RuleInfo, Stage};
use PhpSyntax\{Node, Token, Trivia};


/**
 * No doc comment with nothing in it but asterisks.
 */
#[RuleInfo(
	'dresscode/noEmptyPhpdocs',
	Stage::Finishing,
	description: 'Removes empty doc comments',
	group: RuleGroup::Cleanup,
	modifiesComments: true,
)]
final class NoEmptyPhpdocsRule extends NodeRule
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

		foreach ([...$node->leadingTrivia, ...$node->trailingTrivia] as $trivia) {
			if (
				$trivia->id === Trivia::DocComment
				&& !$trivia->inInterpolation
				&& trim(substr($trivia->text, 3, -2), " \t\r\n*") === ''
				&& $context->report($node, 'Empty doc comment', trivia: $trivia)
			) {
				$node->removeTrivia($trivia);
			}
		}
	}
}
