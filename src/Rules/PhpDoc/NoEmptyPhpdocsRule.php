<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};


/**
 * No doc comment with nothing in it but asterisks.
 */
#[RuleInfo(Stage::Finishing, modifiesComments: true)]
final class NoEmptyPhpdocsRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('phpdoc.empty', Domain::state('forbidden'), 'An empty doc comment, which is removed')];
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

		foreach ($node->getDocComments() as $trivia) {
			if (
				$trivia->getCommentText() === ''
				&& $context->report($node, 'Empty doc comment.', trivia: $trivia)
			) {
				$node->removeTrivia($trivia);
			}
		}
	}
}
