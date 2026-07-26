<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Parser;
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
final class NoIsNullRule extends NodeRule
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
		return [Expression\FunctionCallNode::class, Expression\UnaryOpNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		// a negated call is rewritten where the negation stands, so that the rule replaces the node it
		// was given and the walk does not carry on inside what it took out of the tree
		$negated = self::isNegation($node);
		$call = $negated ? $node->expression : $node;
		if (!$call instanceof Expression\FunctionCallNode || (!$negated && self::isNegation($call->parent))) {
			return;
		}

		$target = $node instanceof Expression\UnaryOpNode ? $node : $call;
		$args = $call->arguments->items->getItems();
		$arg = $args[0] ?? null;
		if (
			count($args) !== 1
			|| !$arg instanceof ArgumentNode
			|| $arg->name || $arg->ampersand || $arg->ellipsis
			|| !$context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($call, 'is_null')
			|| $target->hasComment()
			|| !$context->report($call, 'The is_null() call must be written as a comparison with null')
		) {
			return;
		}

		$operand = clone $arg->value;
		$operand->setEdgeTrivia(leading: []);
		$operand->getLastToken()?->removeTrailingWhitespace();
		$comparison = (new Parser)->parseExpression(
			(self::bindsLooserThanComparison($arg->value) ? '(0)' : '0') . ($negated ? ' !== null' : ' === null'),
		);
		assert($comparison instanceof Expression\BinaryOpNode);
		($comparison->left instanceof Expression\ParenthesizedNode ? $comparison->left->expression : $comparison->left)->replaceWith($operand);

		$replacement = $comparison;
		if (self::needsParentheses($target)) {
			$replacement = (new Parser)->parseExpression('(0)');
			assert($replacement instanceof Expression\ParenthesizedNode);
			$replacement->expression = $comparison;
		}

		$target->replaceWith($replacement);
	}


	/** @phpstan-assert-if-true Expression\UnaryOpNode $node */
	private static function isNegation(Node|Token|null $node): bool
	{
		return $node instanceof Expression\UnaryOpNode && $node->operator->is('!');
	}


	private static function bindsLooserThanComparison(ExpressionNode $operand): bool
	{
		return $operand instanceof Expression\AssignmentNode
			|| $operand instanceof Expression\CombinedAssignmentNode
			|| $operand instanceof Expression\AssignmentByReferenceNode
			|| $operand instanceof Expression\TernaryNode
			|| $operand instanceof Expression\YieldNode
			|| $operand instanceof Expression\YieldFromNode
			|| $operand instanceof Expression\PrintNode
			|| $operand instanceof Expression\IncludeNode
			|| $operand instanceof Expression\ThrowNode
			|| $operand instanceof Expression\ArrowFunctionNode
			|| ($operand instanceof Expression\BinaryOpNode && $operand->operator->is(...self::LooserOperators));
	}


	/** Whether a comparison in place of the expression would bind to a part of what stands around it. */
	private static function needsParentheses(ExpressionNode $expr): bool
	{
		$parent = $expr->parent;
		return $expr->isDereferenced()
			|| match (true) {
				$parent instanceof Expression\BinaryOpNode => !$parent->operator->is(...self::LowerOperators),
				$parent instanceof Expression\UnaryOpNode,
				$parent instanceof Expression\PrefixOpNode,
				$parent instanceof Expression\PostfixOpNode,
				$parent instanceof Expression\CastNode,
				$parent instanceof Expression\CloneNode,
				$parent instanceof Expression\InstanceofNode => true,
				default => false,
			};
	}
}
