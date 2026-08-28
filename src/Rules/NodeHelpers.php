<?php declare(strict_types=1);

namespace DressCode\Rules;

use DressCode\Analyses;
use DressCode\RuleContext;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\AttributeGroupNode;
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\OperatorNode;
use PhpSyntax\Nodes\Scalar;
use PhpSyntax\Nodes\SeparatedNodeList;
use PhpSyntax\Nodes\Statement;
use PhpSyntax\Parser;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function array_slice, count, ord;


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
	 * Whether the declaration says of itself that it is deprecated, by the `@deprecated` annotation or by the
	 * `#[\Deprecated]` attribute of PHP 8.4. What is deprecated cannot be renamed any more, so a rule about
	 * names leaves it alone.
	 */
	public static function isDeprecated(Node $node, RuleContext $context): bool
	{
		$docComment = $node->getDocComment();
		if ($docComment !== null && !$docComment->inInterpolation) {
			foreach ($context->getAnalysis(Analyses\PhpDoc::class)->parse($docComment)->children as $child) {
				if ($child instanceof PhpDocTagNode && strcasecmp($child->name, '@deprecated') === 0) {
					return true;
				}
			}
		}

		$attributes = property_exists($node, 'attributes') ? $node->attributes : null;
		$resolver = $context->getAnalysis(NameResolver::class);
		foreach ($attributes instanceof NodeList ? $attributes->getItems() : [] as $group) {
			foreach ($group instanceof AttributeGroupNode ? $group->attributes->getItems() : [] as $attribute) {
				if (strcasecmp($resolver->resolveClass($attribute->name, $node), 'Deprecated') === 0) {
					return true;
				}
			}
		}

		return false;
	}


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


	/**
	 * Whether the block ends with a statement after which the code does not go on: return, break, continue,
	 * goto, throw or exit.
	 */
	public static function leaves(Statement\BlockNode $block): bool
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
	 * Splits a declaration listing several items (`const A = 1, B = 2;`, `public $a, $b;`, `use A, B;`) into
	 * one declaration per item: every item after the first gets a copy of the declaration of its own, the copies
	 * follow the original in its list and the original keeps the first item. The slot names the list of items
	 * of the declaration (`'items'`, `'traits'`).
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

			$copy->setEdgeTrivia([new Trivia(TriviaKind::Whitespace, $indentation)], $i === count($members) - 2 ? $trailing : [$end]);
			$list->insert($index + $i + 1, $copy);
		}

		foreach (array_slice($members, 1) as $member) {
			$items->removeItem($member);
		}

		$node->setEdgeTrivia(trailing: [$end]);
	}
}
