<?php declare(strict_types=1);

namespace DressCode;

use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\SeparatedNodeList;
use PhpSyntax\Nodes\Statement;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function count, in_array, ord;


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
	public static function isBoolean(ExpressionNode $expr): bool
	{
		return match (true) {
			$expr instanceof Expression\BinaryNode => $expr->operator->is(...self::BooleanOperators),
			$expr instanceof Expression\UnaryNode => $expr->operator->is('!'),
			$expr instanceof Expression\CastNode => $expr->cast->kind === TokenKind::BoolCast,
			$expr instanceof Expression\ParenthesizedNode => self::isBoolean($expr->expr),
			$expr instanceof Expression\ConstantFetchNode => self::isBooleanLiteral($expr),
			default => $expr instanceof Expression\InstanceofNode || $expr instanceof Expression\IssetNode || $expr instanceof Expression\EmptyNode,
		};
	}


	/** Whether the expression is the literal true or false. */
	public static function isBooleanLiteral(ExpressionNode $expr): bool
	{
		return $expr instanceof Expression\ConstantFetchNode
			&& in_array(strtolower($expr->name->getName()), ['true', 'false'], strict: true);
	}


	/**
	 * The negation of the expression as a new detached node with empty trivia on its edges: a comparison flips
	 * its operator, `!` is dropped, true and false swap, a primary expression gets `!`, anything else `!(...)`.
	 */
	public static function negate(ExpressionNode $expr): ExpressionNode
	{
		$copy = self::detach($expr);
		if ($copy instanceof Expression\BinaryNode && ($operator = self::negateComparison($copy->operator))) {
			$copy->setOperator($operator);
			return $copy;
		} elseif ($copy instanceof Expression\UnaryNode && $copy->operator->is('!')) {
			$inner = $copy->expr instanceof Expression\ParenthesizedNode ? $copy->expr->expr : $copy->expr;
			return self::detach($inner);
		} elseif (self::isBooleanLiteral($copy)) {
			assert($copy instanceof Expression\ConstantFetchNode);
			return (new Parser)->parseExpression(strtolower($copy->name->getName()) === 'true' ? 'false' : 'true');
		}

		$negation = (new Parser)->parseExpression(self::isPrimary($copy) ? '!0' : '!(0)');
		assert($negation instanceof Expression\UnaryNode);
		($negation->expr instanceof Expression\ParenthesizedNode ? $negation->expr->expr : $negation->expr)->replaceWith($copy);
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
	 * Whether the expression binds tighter than any operator, so that a prefix operator or a cast applies
	 * to it without parentheses: a variable, a fetch, a call, or a parenthesized expression.
	 */
	public static function isPrimary(ExpressionNode $expr): bool
	{
		return $expr instanceof Expression\VariableNode
			|| $expr instanceof Expression\ArrayDimFetchNode
			|| $expr instanceof Expression\PropertyFetchNode
			|| $expr instanceof Expression\StaticPropertyFetchNode
			|| $expr instanceof Expression\ClassConstantFetchNode
			|| $expr instanceof Expression\ConstantFetchNode
			|| $expr instanceof Expression\FunctionCallNode
			|| $expr instanceof Expression\MethodCallNode
			|| $expr instanceof Expression\StaticCallNode
			|| $expr instanceof Expression\ParenthesizedNode
			|| $expr instanceof Expression\IssetNode
			|| $expr instanceof Expression\EmptyNode;
	}


	/** A copy without a parent and without trivia on its edges. */
	private static function detach(ExpressionNode $expr): ExpressionNode
	{
		$copy = clone $expr;
		$copy->getFirstToken()?->setLeadingTrivia([]);
		$copy->getLastToken()?->setTrailingTrivia([]);
		return $copy;
	}


	/**
	 * Whether the block ends with a statement after which the code does not go on: return, break, continue,
	 * goto, throw or exit.
	 */
	public static function leaves(Statement\BlockNode $block): bool
	{
		$stmts = $block->stmts->getItems();
		$last = $stmts === [] ? null : $stmts[count($stmts) - 1];
		return match (true) {
			$last instanceof Statement\ReturnNode,
			$last instanceof Statement\BreakNode,
			$last instanceof Statement\ContinueNode,
			$last instanceof Statement\GotoNode => true,
			$last instanceof Statement\ExpressionStatementNode => $last->expr instanceof Expression\ThrowNode || $last->expr instanceof Expression\ExitNode,
			default => false,
		};
	}


	/**
	 * Splits a declaration listing several items (`const A = 1, B = 2;`, `public $a, $b;`, `use A, B;`) into
	 * one declaration per item: every item after the first gets a copy of the declaration of its own, the copies
	 * follow the original in its list and the original keeps the first item. The slot names the list of items
	 * of the declaration (`items`, `traits`).
	 * @template T of Node
	 * @param  T  $node
	 * @param  NodeList<T>  $list
	 */
	public static function splitItems(Node $node, NodeList $list, string $slot, string $eol): void
	{
		$items = $node->$slot;
		assert($items instanceof SeparatedNodeList);
		$members = $items->getItems();
		$index = $list->indexOf($node);
		$indentation = $node->getFirstToken()?->getIndentation() ?? '';
		$trailing = $node->getLastToken()->trailingTrivia ?? [];
		$end = new Trivia(TriviaKind::EndOfLine, $eol);
		foreach (array_slice($members, 1) as $i => $member) {
			$copy = clone $node;
			$copied = $copy->$slot;
			assert($copied instanceof SeparatedNodeList);
			foreach ($copied->getItems() as $j => $item) {
				if ($j !== $i + 1) {
					$copied->removeItem($item);
				}
			}

			$copy->getFirstToken()?->setLeadingTrivia([new Trivia(TriviaKind::Whitespace, $indentation)]);
			$copy->getLastToken()?->setTrailingTrivia($i === count($members) - 2 ? $trailing : [$end]);
			$list->insert($index + $i + 1, $copy);
		}

		foreach (array_slice($members, 1) as $member) {
			$items->removeItem($member);
		}

		$node->getLastToken()?->setTrailingTrivia([$end]);
	}
}
