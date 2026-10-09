<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\TernaryNode;
use PhpSyntax\Nodes\Scalar\BooleanNode;


/**
 * `$cond ? true : false` is the condition itself and `$cond ? false : true` its negation, fixed when that is
 * a boolean by its form (a comparison, a logical operation, a negation...) and otherwise only reported, since
 * `!$x` negated is `$x`, which need not be a boolean; `$cond ?: false` is the condition only when it is a boolean
 * by its form, and nothing is said otherwise, so a variable or a call holding a boolean is not told from one
 * holding anything else.
 */
#[RuleInfo(Stage::Structure)]
final class UselessTernaryOperatorRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('expressions.ternary.ofTrueAndFalse', Domain::state('forbidden'), '`$a > 1 ? true : false` is the condition itself, where it is a boolean')];
	}


	public function getVisitedNodes(): array
	{
		return [TernaryNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof TernaryNode
			|| !$node->else instanceof BooleanNode
			|| ($node->then !== null && !$node->then instanceof BooleanNode)
		) {
			return;
		}

		$elseValue = $node->else->toValue();
		$ifValue = $node->then instanceof BooleanNode ? $node->then->toValue() : null;
		$useless = $node->then === null
			? $elseValue === false // $cond ?: false
			: $ifValue !== $elseValue;
		if (!$useless) {
			return;
		}

		$replacement = $ifValue === false
			? NodeHelpers::negate($node->condition)
			: $node->condition->withoutEdgeTrivia();

		if (!$replacement->evaluatesToBoolean()) {
			if ($ifValue !== null) { // `$x ?: false` is not $x unless $x is a boolean, so it is no violation then
				$context->report($node->question, 'Useless ternary operator, but the condition may not be a boolean.', fixable: false);
			}

			return;
		}

		if (!$context->report($node->question, 'Useless ternary operator, because the condition itself is the result.', fixable: !$node->hasInnerComment())) {
			return;
		}

		$node->replaceWith($replacement);
	}
}
