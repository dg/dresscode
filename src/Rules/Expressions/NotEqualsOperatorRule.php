<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\BinaryOpNode;
use PhpSyntax\Token;


/**
 * The `!=` operator instead of its `<>` synonym.
 */
#[RuleInfo(
	'dresscode/not-equals-operator',
	Stage::Structure,
	description: 'Writes != instead of <>',
)]
final class NotEqualsOperatorRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [BinaryOpNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			$node instanceof BinaryOpNode
			&& $node->operator->text === '<>'
			&& $context->report($node->operator, "The <> operator must be written '!='")
		) {
			$node->operator->setText('!=');
		}
	}
}
