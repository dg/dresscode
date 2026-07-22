<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\CombinedAssignmentNode;
use PhpSyntax\Nodes\Expression\PostfixOpNode;
use PhpSyntax\Nodes\Scalar\IntegerNode;
use PhpSyntax\Nodes\Statement\ExpressionStatementNode;
use PhpSyntax\Parser;
use PhpSyntax\Token;


/**
 * `$a++` and `$a--` instead of `$a += 1` and `$a -= 1`, only as a whole statement, where the value
 * of the expression cannot be observed.
 */
#[RuleInfo(
	'dresscode/increment-operator',
	Stage::Structure,
	description: 'Uses ++ and -- instead of += 1 and -= 1',
)]
final class IncrementOperatorRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [CombinedAssignmentNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof CombinedAssignmentNode
			|| !$node->parent instanceof ExpressionStatementNode
			|| !$node->operator->is('+=', '-=')
			|| !$node->expression instanceof IntegerNode
			|| $node->expression->token->text !== '1'
		) {
			return;
		}

		$operator = $node->operator->is('+=') ? '++' : '--';
		$last = $node->target->getLastToken();
		if (
			$last === null
			|| $last->hasCommentUpTo($node->expression->token)
			|| !$context->report($node, "The '{$node->operator->text} 1' assignment must be written '$operator'")
		) {
			return;
		}

		$postfix = (new Parser)->parseExpression('$x' . $operator);
		assert($postfix instanceof PostfixOpNode);
		$var = clone $node->target;
		$var->setEdgeTrivia(leading: []);
		$var->getLastToken()?->removeTrailingWhitespace();
		$postfix->target = $var;
		$node->replaceWith($postfix);
	}
}
