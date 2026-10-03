<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\Analyses\Types;
use DressCode\{NodeRule, Risk, RuleContext, RuleInfo, Stage, Tristate};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\Expression\CombinedAssignmentNode;
use PhpSyntax\Nodes\Scalar\IntegerNode;
use PhpSyntax\Nodes\Statement\ExpressionStatementNode;


/**
 * `$a++` and `$a--` instead of `$a += 1` and `$a -= 1`, only as a whole statement, where the value
 * of the expression cannot be observed. Risky but for a number: null and a string that is no number count
 * differently, `null--` staying null and `'a'++` being `'b'`. Without the types, a number is not told from them.
 */
#[RuleInfo(
	'dresscode/incrementForAddOne',
	Stage::Structure,
	description: 'Uses `++` and `--` instead of `+= 1` and `-= 1`',
)]
final class IncrementForAddOneRule extends NodeRule
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
			|| !$node->operator->is(['+=', '-='])
			|| !$node->expression instanceof IntegerNode
			|| $node->expression->token->text !== '1'
		) {
			return;
		}

		$operator = $node->operator->is('+=') ? '++' : '--';
		$last = $node->target->getLastToken();
		if (
			$last->hasCommentUpTo($node->expression->token)
			|| !$context->report(
				$node,
				"The `{$node->operator->text} 1` assignment must be written `$operator`",
				risk: $context->findAnalysis(Types::class)?->isOfType($node->target, 'int|float') === Tristate::Yes ? null : Risk::TypeUnknown,
			)
		) {
			return;
		}

		$node->replaceWith((new Builder)->expression('$target' . $operator, target: $node->target));
	}
}
