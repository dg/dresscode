<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Parser, Token, TokenKind};
use PhpSyntax\Nodes\Expression\{AssignmentNode, BinaryOpNode, ClosureNode, TernaryNode};
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\{BlockNode, ExpressionStatementNode, FunctionNode, IfNode, ReturnNode};
use function count;


/**
 * An `if` and an `else` that each only return a value, or each only assign the same variable, are one
 * ternary: `return $c ? $a : $b;`, `$x = $c ? $a : $b;`. A comment inside, or a condition joined by logical
 * operators, keeps the statement and is only reported. A function returning by reference keeps its returns,
 * because a ternary is no variable to return a reference to.
 */
#[RuleInfo(
	'dresscode/ternary-for-simple-branch',
	Stage::Structure,
	description: 'Uses the ternary operator where an if-else only picks one of two values',
	group: Group::Modernization,
)]
final class TernaryForSimpleBranchRule extends NodeRule
{
	public function getVisitedTypes(): array
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

		$fixable = !$node->hasComment()
			&& !($node->condition instanceof BinaryOpNode && $node->condition->operator->is(TokenKind::BooleanAnd, TokenKind::BooleanOr, TokenKind::LogicalAnd, TokenKind::LogicalOr, TokenKind::LogicalXor));
		if (
			!$context->report($node->ifKeyword, 'An if-else picking one of two values must be a ternary')
			|| !$fixable
		) {
			return;
		}

		$ternary = (new Parser)->parseExpression('0 ? 0 : 0');
		assert($ternary instanceof TernaryNode && $ternary->then !== null);
		$ternary->condition->replaceWithExpression($node->condition->withoutEdgeTrivia());
		$ternary->then->replaceWithExpression($a->withoutEdgeTrivia());
		$ternary->else->replaceWithExpression($b->withoutEdgeTrivia());

		$statement = (new Parser)->parseStatement($target === null ? 'return 0;' : '$x = 0;');
		if ($statement instanceof ReturnNode && $statement->expression !== null) {
			$statement->expression->replaceWith($ternary);
		} elseif (
			$statement instanceof ExpressionStatementNode
			&& $statement->expression instanceof AssignmentNode
			&& $target !== null
		) {
			$copy = $target->withoutEdgeTrivia();
			$statement->expression->target->replaceWith($copy);
			$statement->expression->expression->replaceWith($ternary);
		}

		$node->replaceWith($statement);
	}


	private static function findSingleStatement(BlockNode $block): ?Node
	{
		$stmts = $block->statements->getItems();
		return count($stmts) === 1 ? $stmts[0] : null;
	}


	/** Whether the returns leave a function that returns by reference. */
	private static function returnsReference(Node $node): bool
	{
		for ($ancestor = $node->parent; $ancestor !== null; $ancestor = $ancestor->parent) {
			if (
				$ancestor instanceof FunctionNode
				|| $ancestor instanceof MethodNode
				|| $ancestor instanceof ClosureNode
			) {
				return $ancestor->ampersand !== null;
			}
		}

		return false;
	}
}
