<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ClassConstantFetchNode;
use PhpSyntax\Nodes\Expression\StaticCallNode;
use PhpSyntax\Nodes\Expression\StaticPropertyFetchNode;
use PhpSyntax\Token;


/**
 * No whitespace around `::`.
 */
#[RuleInfo(
	'dresscode/double-colon-spacing',
	Stage::Formatting,
	description: 'Removes whitespace around the double colon',
)]
final class DoubleColonSpacingRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [StaticCallNode::class, ClassConstantFetchNode::class, StaticPropertyFetchNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
		    !$node instanceof StaticCallNode
		    && !$node instanceof ClassConstantFetchNode
		    && !$node instanceof StaticPropertyFetchNode
		) {
			return;
		}

		$operator = $node->doubleColon;
		foreach ([$operator->getPrevious(), $operator] as $token) {
			$space = $token?->getTrailingSpace();
			if (
				$token !== null
				&& $space !== null
				&& $space !== ''
				&& $context->report($operator, 'No whitespace around ::')
			) {
				$token->setTrailingSpace('');
			}
		}
	}
}
