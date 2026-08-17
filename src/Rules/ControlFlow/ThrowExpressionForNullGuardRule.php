<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\Expression\{ArrayAccessNode, AssignmentNode, BinaryOpNode, PropertyFetchNode, StaticPropertyFetchNode, ThrowNode, VariableNode};
use PhpSyntax\Nodes\{ExpressionNode, FunctionLikeNode, PlainNodeList};
use PhpSyntax\Nodes\Scalar\NullNode;
use PhpSyntax\Nodes\Statement\{BlockNode, ExpressionStatementNode, IfNode, TryNode};
use function count;


/**
 * A value assigned and then guarded against null with a `throw` is written with the throw expression of PHP 8.0:
 * `$user = $repo->find($id); if ($user === null) { throw new NotFound; }` is
 * `$user = $repo->find($id) ?? throw new NotFound;`. Only a strict comparison with `null` is taken, a loose one
 * holding for `false`, `0` and `''` too, and only a throw that does not read the variable, which in the expression
 * would read it before it is assigned, and no guard inside a `try`, after which the variable would stay unassigned.
 * A value read out of an element or a property makes the fix risky: `??` reads a missing one silently, where the
 * assignment warned.
 */
#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.0'])]
final class ThrowExpressionForNullGuardRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('upgrading.syntax.throwExpression', Domain::adopted(), '`$a ?? throw new E()` for an `if` throwing where the assigned value is null')];
	}


	public function getVisitedNodes(): array
	{
		return [ExpressionStatementNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$assignment = $node instanceof ExpressionStatementNode ? $node->expression : null;
		$list = $node->parent;
		if (
			!$node instanceof ExpressionStatementNode
			|| !$assignment instanceof AssignmentNode
			|| !$assignment->target instanceof VariableNode
			|| ($name = $assignment->target->plainName) === null
			|| !$list instanceof PlainNodeList
			|| !($guard = $node->getNextSibling()) instanceof IfNode
			|| !self::testsNull($guard, $name)
			|| ($throw = self::findSoleThrow($guard)) === null
			|| $throw->find(VariableNode::class, fn(VariableNode $variable) => $variable->plainName === $name) !== []
			|| self::isInsideTry($node)
			|| $node->getFirstToken()->hasCommentUpTo($guard->getLastToken())
		) {
			return;
		}

		$value = $assignment->expression;
		$silenced = self::readsElement($value);
		if (!$context->report(
			$guard,
			'The null check of the assigned value must be written with `?? throw`.',
			risk: $silenced ? Risk::BehaviorChanges : null,
			because: $silenced ? 'a missing element or property is read without the warning' : null,
		)) {
			return;
		}

		$guard->remove();
		$node->replaceWith((new Builder)->statement('$t = $v ?? $e;', t: $assignment->target, v: $value, e: $throw));
	}


	/** Whether the condition of the `if`, which has no other branch, is a strict comparison of the variable with null. */
	private static function testsNull(IfNode $if, string $name): bool
	{
		$condition = $if->condition;
		if (
			$if->elseifs->getItems() !== []
			|| $if->else !== null
			|| !$condition instanceof BinaryOpNode
			|| !$condition->operator->is('===')
		) {
			return false;
		}

		[$variable, $null] = $condition->right instanceof NullNode ? [$condition->left, $condition->right] : [$condition->right, $condition->left];
		return $null instanceof NullNode && $variable instanceof VariableNode && $variable->plainName === $name;
	}


	/** The throw that is the only statement of the body of the `if`. */
	private static function findSoleThrow(IfNode $if): ?ThrowNode
	{
		$body = $if->body;
		$statements = $body instanceof BlockNode ? $body->statements->getItems() : [$body];
		$statement = count($statements) === 1 ? $statements[0] : null;
		return $statement instanceof ExpressionStatementNode && $statement->expression instanceof ThrowNode
			? $statement->expression
			: null;
	}


	/** Whether a `try` of the same function encloses the statement, so that its `catch` or `finally` may read the variable after the throw. */
	private static function isInsideTry(Node $node): bool
	{
		$try = $node->findAncestor(TryNode::class);
		return $try !== null && $try->findAncestor(FunctionLikeNode::class) === $node->findAncestor(FunctionLikeNode::class);
	}


	/** Whether the value reads an element or a property, which `??` would read without a warning. */
	private static function readsElement(ExpressionNode $value): bool
	{
		return $value instanceof ArrayAccessNode || $value instanceof PropertyFetchNode || $value instanceof StaticPropertyFetchNode;
	}
}
