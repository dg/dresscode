<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Rules\NodeHelpers;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\TernaryNode;
use PhpSyntax\Nodes\Scalar\BooleanNode;
use PhpSyntax\Token;


/**
 * `$cond ? true : false` is the condition itself and `$cond ? false : true` its negation; `$cond ?: false`
 * is the condition. Fixed when the condition is a boolean by its form (a comparison, a logical operation...),
 * otherwise only reported.
 */
#[RuleInfo(
	'dresscode/useless-ternary-operator',
	Stage::Structure,
	description: 'Replaces a ternary operator choosing between true and false with the condition',
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

		if (!NodeHelpers::isBoolean($node->condition)) {
			if ($node->then !== null) {
				$context->report($node->question, 'Useless ternary operator');
			}

			return;
		}

		if (!$context->report($node->question, 'Useless ternary operator') || $node->hasComment()) {
			return;
		}

		if ($ifValue === false) {
			$replacement = NodeHelpers::negate($node->condition);
		} else {
			$replacement = clone $node->condition;
			$replacement->setEdgeTrivia([], []);
		}

		$node->replaceWith($replacement);
	}
}
