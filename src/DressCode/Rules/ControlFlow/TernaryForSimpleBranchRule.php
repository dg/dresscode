<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\AssignNode;
use PhpSyntax\Nodes\Expression\BinaryNode;
use PhpSyntax\Nodes\Expression\ParenthesizedNode;
use PhpSyntax\Nodes\Expression\TernaryNode;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\Statement\BlockNode;
use PhpSyntax\Nodes\Statement\ExpressionStatementNode;
use PhpSyntax\Nodes\Statement\IfNode;
use PhpSyntax\Nodes\Statement\ReturnNode;
use PhpSyntax\Parser\Parser;
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
final class TernaryForSimpleBranchRule extends Rule
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
			if ($then->expr === null || $otherwise->expr === null) {
				return;
			}

			[$target, $a, $b] = [null, $then->expr, $otherwise->expr];
		} elseif (
			$then instanceof ExpressionStatementNode && ($x = $then->expr) instanceof AssignNode && $x->operator->is('=')
			&& $otherwise instanceof ExpressionStatementNode && ($y = $otherwise->expr) instanceof AssignNode && $y->operator->is('=')
			&& $x->var->isRepeatableRead() && $x->var->matches($y->var)
		) {
			[$target, $a, $b] = [$x->var, $x->expr, $y->expr];
		} else {
			return;
		}

		$fixable = !$node->hasComment()
			&& !($node->cond instanceof BinaryNode && $node->cond->operator->is(TokenKind::BooleanAnd, TokenKind::BooleanOr, TokenKind::LogicalAnd, TokenKind::LogicalOr, TokenKind::LogicalXor));
		if (
			!$context->report($node->ifKeyword, 'An if-else picking one of two values must be a ternary')
			|| !$fixable
		) {
			return;
		}

		$ternary = (new Parser)->parseExpression('0 ? 0 : 0');
		assert($ternary instanceof TernaryNode && $ternary->if !== null);
		$ternary->cond->replaceWith(self::operand($node->cond, $node->cond instanceof AssignNode || $node->cond instanceof TernaryNode));
		$ternary->if->replaceWith(self::operand($a, $a instanceof TernaryNode));
		$ternary->else->replaceWith(self::operand($b, $b instanceof TernaryNode));

		$statement = (new Parser)->parseStatement($target === null ? 'return 0;' : '$x = 0;');
		if ($statement instanceof ReturnNode && $statement->expr !== null) {
			$statement->expr->replaceWith($ternary);
		} elseif (
			$statement instanceof ExpressionStatementNode
			&& $statement->expr instanceof AssignNode
			&& $target !== null
		) {
			$statement->expr->var->replaceWith(self::operand($target, false));
			$statement->expr->expr->replaceWith($ternary);
		}

		$node->replaceWith($statement);
	}


	private static function findSingleStatement(BlockNode $block): ?Node
	{
		$stmts = $block->stmts->getItems();
		return count($stmts) === 1 ? $stmts[0] : null;
	}


	/** A detached copy of the expression, in parentheses when it would bind differently inside the ternary. */
	private static function operand(ExpressionNode $expr, bool $parenthesize): ExpressionNode
	{
		$copy = clone $expr;
		$copy->getFirstToken()?->setLeadingTrivia([]);
		$copy->getLastToken()?->setTrailingTrivia([]);
		if (!$parenthesize) {
			return $copy;
		}

		$parenthesized = (new Parser)->parseExpression('(0)');
		assert($parenthesized instanceof ParenthesizedNode);
		$parenthesized->expr->replaceWith($copy);
		return $parenthesized;
	}
}
