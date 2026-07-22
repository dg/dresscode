<?php declare(strict_types=1);

namespace DressCode\Rules;

use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\OperatorNode;
use PhpSyntax\Parser;
use function assert;


/**
 * Queries and constructions over the tree that several rules share.
 * @internal
 */
final class NodeHelpers
{
	/** the precedence of a cast and of unary minus, the tightest of the prefixes the rules write; `!` binds looser */
	private const PrefixPrecedence = 240;


	/**
	 * Whether a prefix operator or a cast written in front of the expression applies to the whole of it,
	 * so that it needs no parentheses: the expression is written with no operator of its own, or with one
	 * that binds at least as tightly as the prefix.
	 */
	public static function bindsTighterThanPrefix(ExpressionNode $expression): bool
	{
		return !$expression instanceof OperatorNode
			|| $expression->getPrecedence()[0] >= self::PrefixPrecedence
			// an operator written in front of its operand has no left side the prefix could take away
			|| $expression instanceof Expression\UnaryOpNode
			|| $expression instanceof Expression\CastNode
			|| $expression instanceof Expression\PrefixOpNode
			|| $expression instanceof Expression\PrintNode
			|| $expression instanceof Expression\YieldNode
			|| $expression instanceof Expression\YieldFromNode
			|| $expression instanceof Expression\IncludeNode
			|| $expression instanceof Expression\ThrowNode;
	}


	/** The detached expression in parentheses, the trivia on its edges going outside them. */
	public static function parenthesize(ExpressionNode $expression): Expression\ParenthesizedNode
	{
		$leading = $expression->getFirstToken()->leadingTrivia ?? [];
		$trailing = $expression->getLastToken()->trailingTrivia ?? [];
		$expression->setEdgeTrivia([], []);
		$parenthesized = (new Parser)->parseExpression('(0)');
		assert($parenthesized instanceof Expression\ParenthesizedNode);
		$parenthesized->expression->replaceWith($expression);
		$parenthesized->setEdgeTrivia($leading, $trailing);
		return $parenthesized;
	}
}
