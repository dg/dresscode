<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\CastNode;
use PhpSyntax\Nodes\Expression\UnaryOpNode;
use PhpSyntax\Parser;
use PhpSyntax\Token;


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
		$cast->expression = $operand;
		$node->replaceWith($cast);
	}
}
