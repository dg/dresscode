<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\TernaryNode;
use PhpSyntax\Token;


/**
 * The elvis form `$a ?: $b` for a one-line ternary that repeats its condition; only for expressions
 * free of side effects, which a second evaluation cannot change.
 */
#[RuleInfo(
	'dresscode/short-ternary-operator',
	Stage::Structure,
	description: 'Uses ?: where the ternary repeats its condition',
)]
final class ShortTernaryOperatorRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [TernaryNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof TernaryNode
			|| $node->then === null
			|| !$node->condition->isRepeatableRead()
			|| !$node->condition->matches($node->then)
			|| $node->question->getLine() !== $node->colon->getLine()
			|| $node->question->hasCommentUpTo($node->colon)
			|| !$context->report($node, "A ternary repeating its condition must be written '?:'")
		) {
			return;
		}

		$node->then = null;
		$node->question->setTrailingTrivia([]);
	}
}
