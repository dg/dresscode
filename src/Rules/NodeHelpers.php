<?php declare(strict_types=1);

namespace DressCode\Rules;

use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\OperatorNode;
use PhpSyntax\Nodes\Scalar;
use PhpSyntax\Parser;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use function ord;


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

	/** the precedence of a cast and of unary minus, the tightest of the prefixes the rules write; `!` binds looser */
	private const PrefixPrecedence = 240;


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
	 * The negation of the expression as a new detached node with empty trivia on its edges: a comparison flips
	 * its operator, `!` is dropped, true and false swap, what binds tightly enough gets `!`, anything else `!(...)`.
	 */
	public static function negate(ExpressionNode $expression): ExpressionNode
	{
		$copy = self::detach($expression);
		if ($copy instanceof Expression\BinaryOpNode && ($operator = self::negateComparison($copy->operator))) {
			$copy->operator = $operator;
			return $copy;
		} elseif ($copy instanceof Expression\UnaryOpNode && $copy->operator->is('!')) {
			$inner = $copy->expression instanceof Expression\ParenthesizedNode ? $copy->expression->expression : $copy->expression;
			return self::detach($inner);
		} elseif ($copy instanceof Scalar\BooleanNode) {
			return (new Parser)->parseExpression($copy->value ? 'false' : 'true');
		}

		$negation = (new Parser)->parseExpression(self::bindsTighterThanPrefix($copy) ? '!0' : '!(0)');
		assert($negation instanceof Expression\UnaryOpNode);
		($negation->expression instanceof Expression\ParenthesizedNode ? $negation->expression->expression : $negation->expression)->replaceWith($copy);
		return $negation;
	}


	/** The operator of the opposite comparison with the trivia of the given one, null for other operators. */
	private static function negateComparison(Token $operator): ?Token
	{
		[$kind, $text] = match (true) {
			$operator->is(TokenKind::IsEqual) => [TokenKind::IsNotEqual, '!='],
			$operator->is(TokenKind::IsNotEqual) => [TokenKind::IsEqual, '=='],
			$operator->is(TokenKind::IsIdentical) => [TokenKind::IsNotIdentical, '!=='],
			$operator->is(TokenKind::IsNotIdentical) => [TokenKind::IsIdentical, '==='],
			$operator->is('<') => [TokenKind::IsGreaterOrEqual, '>='],
			$operator->is('>') => [TokenKind::IsSmallerOrEqual, '<='],
			$operator->is(TokenKind::IsSmallerOrEqual) => [ord('>'), '>'],
			$operator->is(TokenKind::IsGreaterOrEqual) => [ord('<'), '<'],
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
	 * Whether a prefix operator or a cast written in front of the expression applies to the whole of it,
	 * so that it needs no parentheses: the expression is written with no operator of its own, or with one
	 * that binds at least as tightly as the prefix.
	 */
	public static function bindsTighterThanPrefix(ExpressionNode $expression): bool
	{
		return !$expression instanceof OperatorNode || $expression->getPrecedence()[0] >= self::PrefixPrecedence;
	}


	/** A copy without a parent and without trivia on its edges. */
	private static function detach(ExpressionNode $expression): ExpressionNode
	{
		$copy = clone $expression;
		$copy->setEdgeTrivia([], []);
		return $copy;
	}
}
