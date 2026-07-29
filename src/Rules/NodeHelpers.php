<?php declare(strict_types=1);

namespace DressCode\Rules;

use DressCode\RuleContext;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\OperatorNode;
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
	 * The negation of the expression as a new detached node with empty trivia on its edges: an equality flips
	 * its operator, `!` is dropped, true and false swap, what binds tightly enough gets `!`, anything else `!(...)`.
	 * An ordering is not flipped, because against NAN both `<` and `>=` are false.
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


	/** A copy without a parent and without trivia on its edges. */
	private static function detach(ExpressionNode $expression): ExpressionNode
	{
		$copy = clone $expression;
		$copy->setEdgeTrivia([], []);
		return $copy;
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


	/**
	 * The constructs inside the node that may reach a variable by a name they do not spell out: variable
	 * variables, calls of compact(), extract() and get_defined_vars(), and eval and include, whose code runs in
	 * the scope they are written in.
	 * @return list<Node>
	 */
	public static function findDynamicVariableAccesses(Node $node, RuleContext $context): array
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		return $node->find(Node::class, fn(Node $inner) => $inner instanceof Expression\IncludeNode
			|| $inner instanceof Expression\EvalNode
			|| ($inner instanceof Expression\VariableNode && ($inner->dollar !== null || !$inner->name instanceof Token))
			|| (
				$inner instanceof Expression\FunctionCallNode
				&& array_any(['compact', 'extract', 'get_defined_vars'], fn(string $function) => $resolver->isGlobalFunctionCall($inner, $function))
			));
	}
}
