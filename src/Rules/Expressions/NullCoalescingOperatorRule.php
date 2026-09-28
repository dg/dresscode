<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\Analyses\Types;
use DressCode\{Group, NodeRule, Risk, RuleContext, RuleInfo, Stage, Tristate};
use PhpSyntax\{Node, Token, TokenKind};
use PhpSyntax\Nodes\Expression\{ArrayAccessNode, BinaryOpNode, IssetNode, PropertyFetchNode, TernaryNode};
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\Scalar\NullNode;
use function count;


/**
 * `isset($a) ? $a : $b` and `$a !== null ? $a : $b` are `$a ?? $b`. The repeated expression must be one
 * that can be read again without side effects. `??` asks `__isset` or `offsetExists` and then reads through
 * `__get` or `offsetGet`, and without `__isset` it calls `__get` straight away. `isset()` of a property never
 * calls its `__get`, so after it a property is a risky subject; `!== null` never asks, so after it a property
 * or an offset anywhere in the expression is. Without the types, a plain property or array is not told from
 * an object answering through its methods.
 */
#[RuleInfo(
	'dresscode/null-coalescing-operator',
	Stage::Structure,
	description: 'Replaces a ternary testing for null with the null coalescing operator',
	group: Group::Modernization,
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

		$asked = $cond instanceof IssetNode
			? ($subject instanceof PropertyFetchNode ? [$subject] : [])
			: self::findAskable($subject);
		$types = $context->findAnalysis(Types::class);
		if (!$context->report(
			$node->question,
			'A ternary testing for null must be written with `??`',
			risky: array_all($asked, fn(ExpressionNode $read) => self::isPlainRead($read, $types)) ? null : Risk::TypeUnknown,
			because: '`??` may ask an object through its magic methods, which the test did not',
		)) {
			return;
		}

		$node->replaceWith(BinaryOpNode::of($subject->withoutEdgeTrivia(), '??', $default->withoutEdgeTrivia()));
	}


	/**
	 * The reads of a property or an offset in the expression, which an object may answer through its methods.
	 * @return list<ExpressionNode>
	 */
	private static function findAskable(ExpressionNode $expr): array
	{
		return array_values(array_filter(
			[$expr, ...$expr->find(ExpressionNode::class)],
			fn(ExpressionNode $node) => $node instanceof PropertyFetchNode || $node instanceof ArrayAccessNode,
		));
	}


	/** Whether the types tell that the read goes straight to a value: a plain property, or an offset of an array. */
	private static function isPlainRead(ExpressionNode $read, ?Types $types): bool
	{
		return match (true) {
			$read instanceof PropertyFetchNode => $types?->isPlainProperty($read) === Tristate::Yes,
			$read instanceof ArrayAccessNode => $types?->isOfType($read->expression, 'array') === Tristate::Yes,
			default => false,
		};
	}
}
