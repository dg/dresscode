<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Parser, Token};
use PhpSyntax\Nodes\Expression\{CombinedAssignmentNode, PostfixOpNode};
use PhpSyntax\Nodes\Scalar\IntegerNode;
use PhpSyntax\Nodes\Statement\ExpressionStatementNode;


/**
 * `$a++` and `$a--` instead of `$a += 1` and `$a -= 1`, only as a whole statement, where the value
 * of the expression cannot be observed. Risky: null and a string that is no number count differently,
 * `null--` staying null and `'a'++` being `'b'`; without the types, an int is not told from them.
 */
#[RuleInfo(
	'dresscode/increment-operator',
	Stage::Structure,
	description: 'Uses ++ and -- instead of += 1 and -= 1',
	risky: true,
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
