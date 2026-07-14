<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\MethodCallNode;
use PhpSyntax\Nodes\Expression\PropertyFetchNode;
use PhpSyntax\Token;


/**
 * No whitespace around `->` and `?->` on a line; an operator starting a line of a chain stays where it is.
 */
#[RuleInfo(
	'dresscode/object-operator-spacing',
	Stage::Formatting,
	description: 'Removes whitespace around the object operator',
)]
final class ObjectOperatorSpacingRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [MethodCallNode::class, PropertyFetchNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof MethodCallNode && !$node instanceof PropertyFetchNode) {
			return;
		}

		$operator = $node->operator;
		foreach ([$operator->getPrevious(), $operator] as $token) {
			$space = $token?->getTrailingSpace();
			if (
			    $token !== null
			    && $space !== null
			    && $space !== ''
			    && $context->report($operator, "No whitespace around the {$operator->text} operator")
			) {
				$token->setTrailingSpace('');
			}
		}
	}
}
