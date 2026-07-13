<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\TernaryNode;
use PhpSyntax\Nodes\Scalar\BooleanNode;


/**
 * `$cond ? true : false` is the condition itself and `$cond ? false : true` its negation, fixed when that is
 * a boolean by its form (a comparison, a logical operation, a negation...) and otherwise only reported, since
 * `!$x` negated is `$x`, which need not be a boolean; `$cond ?: false` is the condition only when it is a boolean
 * by its form, and nothing is said otherwise. Without the types, a variable or a call holding a boolean is not
 * told from one holding anything else.
 */
#[RuleInfo(
	'dresscode/useless-ternary-operator',
	Stage::Structure,
	description: 'Replaces a ternary operator choosing between true and false with the condition',
	group: Group::Cleanup,
)]
final class UselessTernaryOperatorRule extends NodeRule
{
	public function getVisitedTypes(): array
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

		$elseValue = $node->else->value;
		$ifValue = $node->then instanceof BooleanNode ? $node->then->value : null;
		$useless = $node->then === null
			? $elseValue === false // $cond ?: false
			: $ifValue !== $elseValue;
		if (!$useless) {
			return;
		}

		if ($ifValue === false) {
			$replacement = NodeHelpers::negate($node->condition);
		} else {
			$replacement = $node->condition->withoutEdgeTrivia();
		}

		if (!NodeHelpers::isBoolean($replacement)) {
			if ($ifValue !== null) { // `$x ?: false` is not $x unless $x is a boolean, so it is no violation then
				$context->report($node->question, 'Useless ternary operator');
			}

			return;
		}

		if (!$context->report($node->question, 'Useless ternary operator') || $node->hasComment()) {
			return;
		}

		$node->replaceWith($replacement);
	}
}
