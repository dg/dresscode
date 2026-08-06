<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\AssignmentNode;
use PhpSyntax\Nodes\Expression\BinaryOpNode;
use PhpSyntax\Nodes\Expression\CombinedAssignmentNode;
use PhpSyntax\Nodes\Expression\ParenthesizedNode;
use PhpSyntax\Nodes\Expression\TernaryNode;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\Statement\BlockNode;
use PhpSyntax\Nodes\Statement\ExpressionStatementNode;
use PhpSyntax\Nodes\Statement\IfNode;
use PhpSyntax\Nodes\Statement\ReturnNode;
use PhpSyntax\Parser;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use function count;


/**
 * An `if` and an `else` that each only return a value, or each only assign the same variable, are one
 * ternary: `return $c ? $a : $b;`, `$x = $c ? $a : $b;`. A comment inside, or a condition joined by logical
 * operators, keeps the statement and is only reported.
 */
#[RuleInfo(
	'dresscode/ternary-for-simple-branch',
	Stage::Structure,
	description: 'Uses the ternary operator where an if-else only picks one of two values',
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
			if ($then->expression === null || $otherwise->expression === null) {
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
		$ternary->condition->replaceWith(self::operand($node->condition, $node->condition instanceof AssignmentNode || $node->condition instanceof CombinedAssignmentNode || $node->condition instanceof TernaryNode));
		$ternary->then->replaceWith(self::operand($a, $a instanceof TernaryNode));
		$ternary->else->replaceWith(self::operand($b, $b instanceof TernaryNode));

		$statement = (new Parser)->parseStatement($target === null ? 'return 0;' : '$x = 0;');
		if ($statement instanceof ReturnNode && $statement->expression !== null) {
			$statement->expression->replaceWith($ternary);
		} elseif (
			$statement instanceof ExpressionStatementNode
			&& $statement->expression instanceof AssignmentNode
			&& $target !== null
		) {
			$statement->expression->target->replaceWith(self::operand($target, false));
			$statement->expression->expression->replaceWith($ternary);
		}

		$node->replaceWith($statement);
	}


	private static function findSingleStatement(BlockNode $block): ?Node
	{
		$stmts = $block->statements->getItems();
		return count($stmts) === 1 ? $stmts[0] : null;
	}


	/** A detached copy of the expression, in parentheses when it would bind differently inside the ternary. */
	private static function operand(ExpressionNode $expr, bool $parenthesize): ExpressionNode
	{
		$copy = clone $expr;
		$copy->setEdgeTrivia([], []);
		if (!$parenthesize) {
			return $copy;
		}

		$parenthesized = (new Parser)->parseExpression('(0)');
		assert($parenthesized instanceof ParenthesizedNode);
		$parenthesized->expression->replaceWith($copy);
		return $parenthesized;
	}
}
