<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\CastNode;
use PhpSyntax\Nodes\Expression\UnaryNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;


/**
 * A `(bool)` cast instead of the double negation `!!`; a comment between the operators keeps them.
 */
#[RuleInfo(
	'dresscode/no-short-bool-cast',
	Stage::Structure,
	description: 'Replaces !! with a (bool) cast',
)]
final class NoShortBoolCastRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [UnaryNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof UnaryNode
			|| !$node->operator->is('!')
			|| !($inner = $node->expr) instanceof UnaryNode
			|| !$inner->operator->is('!')
			|| ($inner->expr->getFirstToken() !== null && $node->operator->hasCommentUpTo($inner->expr->getFirstToken()))
			|| !$context->report($node, "Double negation must be written as a '(bool)' cast")
		) {
			return;
		}

		$cast = (new Parser)->parseExpression('(bool) 0');
		assert($cast instanceof CastNode);
		$operand = clone $inner->expr;
		$operand->getFirstToken()?->setLeadingTrivia([]);
		$cast->setExpr($operand);
		$node->replaceWith($cast);
	}
}
