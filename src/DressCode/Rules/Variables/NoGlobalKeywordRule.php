<?php declare(strict_types=1);

namespace DressCode\Rules\Variables;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Statement\GlobalNode;
use PhpSyntax\Token;


/**
 * The global statement is reported; there is no safe automatic fix.
 */
#[RuleInfo(
	'dresscode/no-global-keyword',
	Stage::Structure,
	description: 'Forbids the global statement',
)]
final class NoGlobalKeywordRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [GlobalNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$context->report($node, 'The global statement is forbidden');
	}
}
