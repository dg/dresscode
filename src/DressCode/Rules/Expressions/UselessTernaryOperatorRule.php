<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\NodeHelpers;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ConstantFetchNode;
use PhpSyntax\Nodes\Expression\TernaryNode;
use PhpSyntax\Nodes\ExpressionNode;
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
final class UselessTernaryOperatorRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [TernaryNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof TernaryNode
			|| !NodeHelpers::isBooleanLiteral($node->else)
			|| ($node->if !== null && !NodeHelpers::isBooleanLiteral($node->if))
		) {
			return;
		}

		$elseValue = self::valueOf($node->else);
		$ifValue = $node->if === null ? null : self::valueOf($node->if);
		$useless = $node->if === null
			? $elseValue === false // $cond ?: false
			: $ifValue !== $elseValue;
		if (!$useless) {
			return;
		}

		if (!NodeHelpers::isBoolean($node->cond)) {
			if ($node->if !== null) {
				$context->report($node->question, 'Useless ternary operator');
			}

			return;
		}

		if (!$context->report($node->question, 'Useless ternary operator') || $node->hasComment()) {
			return;
		}

		if ($ifValue === false) {
			$replacement = NodeHelpers::negate($node->cond);
		} else {
			$replacement = clone $node->cond;
			$replacement->getFirstToken()?->setLeadingTrivia([]);
			$replacement->getLastToken()?->setTrailingTrivia([]);
		}

		$node->replaceWith($replacement);
	}


	private static function valueOf(ExpressionNode $literal): bool
	{
		assert($literal instanceof ConstantFetchNode);
		return strtolower($literal->name->getName()) === 'true';
	}
}
