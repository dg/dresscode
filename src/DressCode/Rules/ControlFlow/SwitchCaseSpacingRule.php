<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\CaseNode;
use PhpSyntax\Token;


/**
 * No whitespace before the colon of a case or default.
 */
#[RuleInfo(
	'dresscode/switch-case-spacing',
	Stage::Formatting,
	description: 'Removes whitespace before the colon of a case',
)]
final class SwitchCaseSpacingRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [CaseNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$previous = $node instanceof CaseNode ? $node->separator->getPrevious() : null;
		$space = $previous?->getTrailingSpace();
		if (
		    $node instanceof CaseNode
		    && $previous !== null
		    && $space !== null
		    && $space !== ''
		    && $context->report($node->separator, 'No whitespace before the colon of a case')
		) {
			$previous->setTrailingSpace('');
		}
	}
}
