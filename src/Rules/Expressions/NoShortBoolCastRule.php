<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Parser, Token};
use PhpSyntax\Nodes\Expression\{CastNode, UnaryOpNode};


/**
 * A `(bool)` cast instead of the double negation `!!`; a comment between the operators keeps them.
 */
#[RuleInfo(
	'dresscode/no-short-bool-cast',
	Stage::Structure,
	description: 'Replaces !! with a (bool) cast',
)]
final class NoShortBoolCastRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [UnaryOpNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof UnaryOpNode
			|| !$node->operator->is('!')
			|| !($inner = $node->expression) instanceof UnaryOpNode
			|| !$inner->operator->is('!')
			|| ($inner->expression->getFirstToken() !== null && $node->operator->hasCommentUpTo($inner->expression->getFirstToken()))
			|| !$context->report($node, "Double negation must be written as a '(bool)' cast")
		) {
			return;
		}

		$cast = (new Parser)->parseExpression('(bool) 0');
		assert($cast instanceof CastNode);
		$operand = clone $inner->expression;
		$operand->setEdgeTrivia(leading: []);
		// `!` binds looser than a cast, so an operand like `$a instanceof B` would lose itself to the cast
		$cast->expression->replaceWithExpression($operand);
		$node->replaceWith($cast);
	}
}
