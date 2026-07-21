<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\BinaryNode;
use PhpSyntax\Token;


/**
 * The `!=` operator instead of its `<>` synonym.
 */
#[RuleInfo(
	'dresscode/not-equals-operator',
	Stage::Structure,
	description: 'Writes != instead of <>',
)]
final class NotEqualsOperatorRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [BinaryNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
		    $node instanceof BinaryNode
		    && $node->operator->text === '<>'
		    && $context->report($node->operator, "The <> operator must be written '!='")
		) {
			$node->operator->setText('!=');
		}
	}
}
