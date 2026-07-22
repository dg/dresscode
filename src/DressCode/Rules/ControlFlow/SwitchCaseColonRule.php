<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\CaseNode;
use PhpSyntax\Token;
use function ord;


/**
 * A colon after `case` and `default`, not the semicolon PHP also accepts.
 */
#[RuleInfo(
	'dresscode/switch-case-colon',
	Stage::Structure,
	description: 'Ends case and default with a colon',
)]
final class SwitchCaseColonRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [CaseNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof CaseNode
			|| !$node->separator->is(';')
			|| !$context->report($node->separator, 'A case must end with a colon, not a semicolon')
		) {
			return;
		}

		$colon = new Token(ord(':'), ':');
		$colon->setLeadingTrivia($node->separator->leadingTrivia);
		$colon->setTrailingTrivia($node->separator->trailingTrivia);
		$node->setSeparator($colon);
	}
}
