<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Group;
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
 * is the condition. Fixed when what replaces it is a boolean by its form (a comparison, a logical operation,
 * a negation...), otherwise only reported: `!$x` negated is `$x`, which need not be a boolean.
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
