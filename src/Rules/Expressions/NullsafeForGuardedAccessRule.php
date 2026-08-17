<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{Expression, ExpressionNode};
use PhpSyntax\Nodes\Scalar\NullNode;


/**
 * A ternary that guards a member access against null is the nullsafe operator of PHP 8.0:
 * `$a === null ? null : $a->b()` and `$a !== null ? $a->b() : null` are both `$a?->b()`.
 *
 * The branch that is not null must be a chain of member accesses, offsets after the first of them included, and
 * nothing else, and it must reach for the subject with `->`: `?->` stops the whole chain after it, which is what
 * the ternary does, but it stops nothing around it, so `$a === null ? null : $a->b() + 1` says something else. The
 * subject must be the same expression on both sides and free of side effects, because the chain reads it once.
 */
#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.0'])]
final class NullsafeForGuardedAccessRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('upgrading.syntax.nullsafe', Domain::adopted(), '`$a?->b` for `$a ? $a->b : null`')];
	}


	public function getVisitedNodes(): array
	{
		return [Expression\TernaryNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof Expression\TernaryNode
			|| $node->then === null
			|| $node->hasInnerComment()
			|| !($condition = $node->condition) instanceof Expression\BinaryOpNode
			|| !$condition->operator->is([Token::IsIdentical, Token::IsNotIdentical])
		) {
			return;
		}

		$subject = match (true) {
			$condition->right instanceof NullNode => $condition->left,
			$condition->left instanceof NullNode => $condition->right,
			default => null,
		};
		[$chain, $null] = $condition->operator->is(Token::IsNotIdentical)
			? [$node->then, $node->else]
			: [$node->else, $node->then];
		if (
			$subject === null
			|| !$null instanceof NullNode
			|| !$subject->isRepeatableRead()
			|| self::findFirstLink($chain, $subject) === null
			|| !$context->report($node->question, 'The ternary testing for null must be written with `?->`.')
		) {
			return;
		}

		$replacement = $chain->withoutEdgeTrivia();
		$link = self::findFirstLink($replacement, $subject);
		assert($link !== null);
		$link->operator->replaceWith(Token::fromText('?->'));
		$node->replaceWith($replacement);
	}


	/**
	 * The access with which the chain first reaches for the subject, null where the expression is no chain
	 * of member accesses, where it does not stand on the subject, or where it reaches for it nullsafe already.
	 */
	private static function findFirstLink(
		ExpressionNode $chain,
		ExpressionNode $subject,
	): Expression\PropertyFetchNode|Expression\MethodCallNode|null
	{
		$link = null;
		$offset = false; // an offset between the subject and the link would read null before ?-> could stop
		while (!$chain->matches($subject)) {
			if ($chain instanceof Expression\MethodCallNode && $chain->arguments->isPartialApplication()) {
				return null; // PHP refuses a first-class callable made in a nullsafe chain
			} elseif ($chain instanceof Expression\PropertyFetchNode || $chain instanceof Expression\MethodCallNode) {
				[$link, $offset] = [$chain, false];
				$chain = $chain->object;
			} elseif ($chain instanceof Expression\ArrayAccessNode) {
				$offset = true;
				$chain = $chain->expression;
			} else {
				return null;
			}
		}

		return $link !== null && !$offset && !$link->nullsafe ? $link : null;
	}
}
