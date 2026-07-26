<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use function count;


/**
 * A comparison with null instead of `is_null()`: `$a === null`, `!is_null($a)` becomes `$a !== null`.
 * Parentheses are added where the operand or the surrounding expression would bind differently.
 */
#[RuleInfo(
	'dresscode/no-is-null',
	Stage::Structure,
	description: 'Replaces is_null() with a comparison with null',
)]
final class NoIsNullRule extends Rule
{
	/** binary operators of the precedence of === or lower: an operand made of one needs parentheses */
	private const LooserOperators = [
		TokenKind::IsEqual, TokenKind::IsNotEqual, TokenKind::IsIdentical, TokenKind::IsNotIdentical, TokenKind::Spaceship,
		'<', '>', TokenKind::IsSmallerOrEqual, TokenKind::IsGreaterOrEqual,
		'&', '^', '|', TokenKind::BooleanAnd, TokenKind::BooleanOr, TokenKind::Coalesce,
		TokenKind::LogicalAnd, TokenKind::LogicalOr, TokenKind::LogicalXor, TokenKind::Pipe,
	];

	/** binary operators of a lower precedence than ===: a comparison as their operand needs no parentheses */
	private const LowerOperators = [
		'&', '^', '|', TokenKind::BooleanAnd, TokenKind::BooleanOr, TokenKind::Coalesce,
		TokenKind::LogicalAnd, TokenKind::LogicalOr, TokenKind::LogicalXor,
	];


	public function getVisitedTypes(): array
	{
		return [Expression\FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Expression\FunctionCallNode) {
			return;
		}

		$args = $node->args->args->getItems();
		$arg = $args[0] ?? null;
		$negated = $node->parent instanceof Expression\UnaryNode && $node->parent->operator->is('!');
		$target = $negated ? $node->parent : $node;
		if (
			count($args) !== 1
			|| !$arg instanceof ArgumentNode
			|| $arg->name || $arg->byRef || $arg->ellipsis
			|| !$context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($node, 'is_null')
			|| $target->hasComment()
			|| !$context->report($node, 'The is_null() call must be written as a comparison with null')
		) {
			return;
		}

		$operand = clone $arg->expr;
		$operand->getFirstToken()?->setLeadingTrivia([]);
		$operand->getLastToken()?->removeTrailingWhitespace();
		$comparison = (new Parser)->parseExpression(
			(self::bindsLooserThanComparison($arg->expr) ? '(0)' : '0') . ($negated ? ' !== null' : ' === null'),
		);
		assert($comparison instanceof Expression\BinaryNode);
		($comparison->left instanceof Expression\ParenthesizedNode ? $comparison->left->expr : $comparison->left)->replaceWith($operand);

		$replacement = $comparison;
		if ($target->parent instanceof Node && self::needsParentheses($target->parent, $target)) {
			$replacement = (new Parser)->parseExpression('(0)');
			assert($replacement instanceof Expression\ParenthesizedNode);
			$replacement->setExpr($comparison);
		}

		$target->replaceWith($replacement);
	}


	private static function bindsLooserThanComparison(ExpressionNode $operand): bool
	{
		return $operand instanceof Expression\AssignNode
			|| $operand instanceof Expression\AssignRefNode
			|| $operand instanceof Expression\TernaryNode
			|| $operand instanceof Expression\YieldNode
			|| $operand instanceof Expression\YieldFromNode
			|| $operand instanceof Expression\PrintNode
			|| $operand instanceof Expression\IncludeNode
			|| $operand instanceof Expression\ThrowNode
			|| $operand instanceof Expression\ArrowFunctionNode
			|| ($operand instanceof Expression\BinaryNode && $operand->operator->is(...self::LooserOperators));
	}


	/** Whether a comparison in place of the child would bind to a part of the parent. */
	private static function needsParentheses(Node $parent, Node $child): bool
	{
		return match (true) {
			$parent instanceof Expression\BinaryNode => !$parent->operator->is(...self::LowerOperators),
			$parent instanceof Expression\UnaryNode,
			$parent instanceof Expression\PostfixNode,
			$parent instanceof Expression\CastNode,
			$parent instanceof Expression\CloneNode,
			$parent instanceof Expression\InstanceofNode => true,
			$parent instanceof Expression\ArrayDimFetchNode => $parent->var === $child,
			$parent instanceof Expression\PropertyFetchNode, $parent instanceof Expression\MethodCallNode => $parent->object === $child,
			$parent instanceof Expression\StaticCallNode,
			$parent instanceof Expression\StaticPropertyFetchNode,
			$parent instanceof Expression\ClassConstantFetchNode => $parent->class === $child,
			default => false,
		};
	}
}
