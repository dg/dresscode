<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ArrayAccessNode;
use PhpSyntax\Nodes\Expression\AssignmentNode;
use PhpSyntax\Nodes\Expression\BinaryOpNode;
use PhpSyntax\Nodes\Expression\CombinedAssignmentNode;
use PhpSyntax\Nodes\Expression\IssetNode;
use PhpSyntax\Nodes\Expression\ParenthesizedNode;
use PhpSyntax\Nodes\Expression\PropertyFetchNode;
use PhpSyntax\Nodes\Expression\TernaryNode;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\Scalar\NullNode;
use PhpSyntax\Parser;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use function count;


/**
 * `isset($a) ? $a : $b` and `$a !== null ? $a : $b` are `$a ?? $b`. The repeated expression must be one
 * that can be read again without side effects. `??` asks `__isset` or `offsetExists` and then reads through
 * `__get` or `offsetGet`, and without `__isset` it calls `__get` straight away. `isset()` of a property never
 * calls its `__get`, so after it a property is a risky subject; `!== null` never asks, so after it a property
 * or an offset anywhere in the expression is.
 */
#[RuleInfo(
	'dresscode/null-coalescing-operator',
	Stage::Structure,
	description: 'Replaces a ternary testing for null with the null coalescing operator',
)]
final class NullCoalescingOperatorRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [TernaryNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof TernaryNode || $node->then === null) {
			return;
		}

		$cond = $node->condition;
		if ($cond instanceof IssetNode && count($cond->variables) === 1) {
			[$subject, $value, $default] = [$cond->variables->getItems()[0], $node->then, $node->else];
		} elseif (
			$cond instanceof BinaryOpNode
			&& $cond->operator->is(TokenKind::IsIdentical, TokenKind::IsNotIdentical)
		) {
			$subject = match (true) {
				$cond->right instanceof NullNode => $cond->left,
				$cond->left instanceof NullNode => $cond->right,
				default => null,
			};
			[$value, $default] = $cond->operator->is(TokenKind::IsNotIdentical) ? [$node->then, $node->else] : [$node->else, $node->then];
		} else {
			return;
		}

		if (
			$subject === null
			|| !$subject->isRepeatableRead()
			|| !$subject->matches($value)
			|| $node->hasComment()
		) {
			return;
		}

		$risky = $cond instanceof IssetNode
			? $subject instanceof PropertyFetchNode
			: self::canAskObject($subject);
		if (!$context->report($node->question, "A ternary testing for null must be written with '??'", risky: $risky)) {
			return;
		}

		$coalesce = (new Parser)->parseExpression('0 ?? 0');
		assert($coalesce instanceof BinaryOpNode);
		$coalesce->left->replaceWith(self::copy($subject));
		if (
			$default instanceof TernaryNode
			|| $default instanceof AssignmentNode
			|| $default instanceof CombinedAssignmentNode
		) {
			$parenthesized = (new Parser)->parseExpression('(0)');
			assert($parenthesized instanceof ParenthesizedNode);
			$parenthesized->expression->replaceWith(self::copy($default));
			$coalesce->right->replaceWith($parenthesized);
		} else {
			$coalesce->right->replaceWith(self::copy($default));
		}

		$node->replaceWith($coalesce);
	}


	/** Whether the expression reads a property or an offset, which an object may answer through its methods. */
	private static function canAskObject(ExpressionNode $expr): bool
	{
		return array_any([PropertyFetchNode::class, ArrayAccessNode::class], fn(string $class) => $expr instanceof $class || $expr->find($class) !== []);
	}


	private static function copy(ExpressionNode $expr): ExpressionNode
	{
		$copy = clone $expr;
		$copy->setEdgeTrivia([], []);
		return $copy;
	}
}
