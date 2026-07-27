<?php declare(strict_types=1);

namespace DressCode\Rules;

use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\Scalar;
use PhpSyntax\Nodes\Statement;
use PhpSyntax\Parser;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use function assert, count;


/**
 * Queries and constructions over the tree that several rules share.
 * @internal
 */
final class NodeHelpers
{
	private const BooleanOperators = [
		TokenKind::IsEqual, TokenKind::IsNotEqual, TokenKind::IsIdentical, TokenKind::IsNotIdentical,
		'<', '>', TokenKind::IsSmallerOrEqual, TokenKind::IsGreaterOrEqual,
		TokenKind::BooleanAnd, TokenKind::BooleanOr, TokenKind::LogicalAnd, TokenKind::LogicalOr, TokenKind::LogicalXor,
	];


	/**
	 * Whether the expression yields a boolean whatever its operands: a comparison, a logical operation,
	 * a negation, instanceof, isset(), empty(), a bool cast or a boolean literal.
	 */
	public static function isBoolean(ExpressionNode $expression): bool
	{
		return match (true) {
			$expression instanceof Expression\BinaryOpNode => $expression->operator->is(...self::BooleanOperators),
			$expression instanceof Expression\UnaryOpNode => $expression->operator->is('!'),
			$expression instanceof Expression\CastNode => $expression->cast->kind === TokenKind::BoolCast,
			$expression instanceof Expression\ParenthesizedNode => self::isBoolean($expression->expression),
			$expression instanceof Scalar\BooleanNode => true,
			default => $expression instanceof Expression\InstanceofNode || $expression instanceof Expression\IssetNode || $expression instanceof Expression\EmptyNode,
		};
	}


	/**
	 * The negation of the expression as a new detached node with empty trivia on its edges: an equality flips
	 * its operator, `!` is dropped, true and false swap, what binds tightly enough gets `!`, anything else `!(...)`.
	 * An ordering is not flipped, because against NAN both `<` and `>=` are false.
	 */
	public static function negate(ExpressionNode $expression): ExpressionNode
	{
		$copy = $expression->withoutEdgeTrivia();
		if ($copy instanceof Expression\BinaryOpNode && ($operator = self::negateComparison($copy->operator))) {
			$copy->operator = $operator;
			return $copy;
		} elseif ($copy instanceof Expression\UnaryOpNode && $copy->operator->is('!')) {
			$inner = $copy->expression instanceof Expression\ParenthesizedNode ? $copy->expression->expression : $copy->expression;
			return $inner->withoutEdgeTrivia();
		} elseif ($copy instanceof Scalar\BooleanNode) {
			return (new Parser)->parseExpression($copy->value ? 'false' : 'true');
		}

		$negation = (new Parser)->parseExpression('!0');
		assert($negation instanceof Expression\UnaryOpNode);
		$negation->expression->replaceWithExpression($copy);
		return $negation;
	}


	/** The operator of the opposite equality with the trivia of the given one, null for other operators. */
	private static function negateComparison(Token $operator): ?Token
	{
		[$kind, $text] = match (true) {
			$operator->is(TokenKind::IsEqual) => [TokenKind::IsNotEqual, '!='],
			$operator->is(TokenKind::IsNotEqual) => [TokenKind::IsEqual, '=='],
			$operator->is(TokenKind::IsIdentical) => [TokenKind::IsNotIdentical, '!=='],
			$operator->is(TokenKind::IsNotIdentical) => [TokenKind::IsIdentical, '==='],
			default => [null, null],
		};
		if ($kind === null || $text === null) {
			return null;
		}

		$new = new Token($kind, $text);
		$new->setLeadingTrivia($operator->leadingTrivia);
		$new->setTrailingTrivia($operator->trailingTrivia);
		return $new;
	}


	/**
	 * Whether the block ends with a statement after which the code does not go on: return, break, continue,
	 * goto, throw or exit.
	 */
	public static function endsWithExit(Statement\BlockNode $block): bool
	{
		$stmts = $block->statements->getItems();
		$last = $stmts === [] ? null : $stmts[count($stmts) - 1];
		return match (true) {
			$last instanceof Statement\ReturnNode,
			$last instanceof Statement\BreakNode,
			$last instanceof Statement\ContinueNode,
			$last instanceof Statement\GotoNode => true,
			$last instanceof Statement\ExpressionStatementNode => $last->expression instanceof Expression\ThrowNode || $last->expression instanceof Expression\ExitNode,
			default => false,
		};
	}
}
