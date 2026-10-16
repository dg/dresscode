<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\Analyses\Types;
use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage, Tristate, Values};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\Expression\CombinedAssignmentNode;
use PhpSyntax\Nodes\Scalar\IntegerNode;
use PhpSyntax\Nodes\Statement\ExpressionStatementNode;


/**
 * `$a++` and `$a--` instead of `$a += 1` and `$a -= 1`, or `++$a` and `--$a` where `expressions.assignment.increment`
 * says `prefix`, only as a whole statement, where the value of the expression cannot be observed. Risky but for a
 * number: null and a string that is no number count differently, `null--` staying null and `'a'++` being `'b'`, so a
 * target the types tell is no number stays as it is. Without the types, a number is not told from them.
 */
#[RuleInfo(Stage::Structure, reads: ['expressions.assignment.increment'], analyses: [Types::class])]
final class IncrementForAddOneRule extends NodeRule
{
	private bool $prefix = false;


	public static function getDecisions(): array
	{
		return [new Decision(
			'expressions.assignment.addingOne',
			Domain::state('forbidden'),
			'`$i += 1`, which is `$i++`, or `++$i` by `expressions.assignment.increment`',
		)];
	}


	public function configure(Values $values): void
	{
		$this->prefix = $values->find('expressions.assignment.increment')?->getWord() === 'prefix';
	}


	public function getVisitedNodes(): array
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
		$written = $this->prefix ? $operator . $node->target->text : $node->target->text . $operator;
		$last = $node->target->getLastToken();
		$type = $context->findAnalysis(Types::class)?->isOfType($node->target, 'int|float');
		$number = $type === Tristate::Yes;
		if (
			$type === Tristate::No
			|| $last->hasCommentUpTo($node->expression->token)
			|| !$context->report(
				$node,
				"The `{$node->operator->text} 1` assignment must be written `$written`.",
				risk: $number ? null : Risk::TypeUnknown,
				because: $number ? null : "the target may be a string, which `$operator` changes as text",
			)
		) {
			return;
		}

		$node->replaceWith((new Builder)->expression($this->prefix ? $operator . '$target' : '$target' . $operator, target: $node->target));
	}
}
