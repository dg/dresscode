<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\AssignNode;
use PhpSyntax\Nodes\Expression\PostfixNode;
use PhpSyntax\Nodes\Scalar\IntegerNode;
use PhpSyntax\Nodes\Statement\ExpressionStatementNode;
use PhpSyntax\Parser\Parser;
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
final class IncrementOperatorRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [AssignNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof AssignNode
			|| !$node->parent instanceof ExpressionStatementNode
			|| !$node->operator->is('+=', '-=')
			|| !$node->expr instanceof IntegerNode
			|| $node->expr->token->text !== '1'
		) {
			return;
		}

		$operator = $node->operator->is('+=') ? '++' : '--';
		$last = $node->var->getLastToken();
		if (
			$last === null
			|| $last->hasCommentUpTo($node->expr->token)
			|| !$context->report($node, "The '{$node->operator->text} 1' assignment must be written '$operator'")
		) {
			return;
		}

		$postfix = (new Parser)->parseExpression('$x' . $operator);
		assert($postfix instanceof PostfixNode);
		$var = clone $node->var;
		$var->getFirstToken()?->setLeadingTrivia([]);
		$var->getLastToken()?->removeTrailingWhitespace();
		$postfix->setExpr($var);
		$node->replaceWith($postfix);
	}
}
