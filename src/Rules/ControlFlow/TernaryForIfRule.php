<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\Expression\{AssignmentNode, BinaryOpNode};
use PhpSyntax\Nodes\{ExpressionNode, FunctionLikeNode};
use PhpSyntax\Nodes\Statement\{BlockNode, ExpressionStatementNode, IfNode, ReturnNode};
use function count;


/**
 * An `if` and an `else` that each only return a value, or each only assign the same variable, are one
 * ternary: `return $c ? $a : $b;`, `$x = $c ? $a : $b;`. A comment inside, or a condition joined by logical
 * operators, keeps the statement and is only reported. A function returning by reference keeps its returns,
 * because a ternary is no variable to return a reference to. The fix is risky where the condition runs code and
 * the variable reads before it an offset that is more than a variable, a literal or a constant, or a class given by
 * an expression.
 */
#[RuleInfo(Stage::Structure)]
final class TernaryForIfRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('controlFlow.ifReturningOneOfTwoValues', Domain::state('forbidden'), '`if ($c) { return 1; } else { return 2; }` is `return $c ? 1 : 2;`')];
	}


	public function getVisitedNodes(): array
	{
		return [IfNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof IfNode
			|| !$node->elseifs->isEmpty()
			|| !$node->body instanceof BlockNode
			|| !($else = $node->else) || !$else->body instanceof BlockNode
			|| ($then = self::findSingleStatement($node->body)) === null
			|| ($otherwise = self::findSingleStatement($else->body)) === null
		) {
			return;
		}

		if ($then instanceof ReturnNode && $otherwise instanceof ReturnNode) {
			if ($then->expression === null || $otherwise->expression === null || self::returnsReference($node)) {
				return;
			}

			[$target, $a, $b] = [null, $then->expression, $otherwise->expression];

		} elseif (
			$then instanceof ExpressionStatementNode && ($x = $then->expression) instanceof AssignmentNode
			&& $otherwise instanceof ExpressionStatementNode && ($y = $otherwise->expression) instanceof AssignmentNode
			&& ($assigned = $x->target) instanceof ExpressionNode
			&& $assigned->isRepeatableRead() && $assigned->matches($y->target)
		) {
			[$target, $a, $b] = [$assigned, $x->expression, $y->expression];

		} else {
			return;
		}

		$fixable = !$node->hasInnerComment()
			&& !($node->condition instanceof BinaryOpNode && $node->condition->isLogical());
		$reordered = $target !== null && $target->hasEarlyReads() && $node->condition->hasEffect();
		if (!$context->report(
			$node->ifKeyword,
			'The if-else picking one of two values must be written as a ternary.',
			fixable: $fixable,
			risk: $reordered ? Risk::BehaviorChanges : null,
			because: $reordered ? 'the ternary evaluates the target before the condition, which may change it' : null,
		)) {
			return;
		}

		$builder = new Builder;
		$ternary = $builder->ternary($node->condition, $a, $b);
		$node->replaceWith($target === null
			? $builder->statement('return $value;', value: $ternary)
			: $builder->statement('$target = $value;', target: $target, value: $ternary));
	}


	private static function findSingleStatement(BlockNode $block): ?Node
	{
		$stmts = $block->statements->getItems();
		return count($stmts) === 1 ? $stmts[0] : null;
	}


	/** Whether the returns leave a function that returns by reference. */
	private static function returnsReference(Node $node): bool
	{
		return $node->findAncestor(FunctionLikeNode::class)?->ampersand !== null;
	}
}
