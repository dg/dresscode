<?php declare(strict_types=1);

namespace DressCode\Rules\Arrays;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ArrayNode;
use PhpSyntax\Token;


/**
 * No whitespace just inside the brackets of an array on a line: `[1, 2]`, not `[ 1, 2 ]`.
 */
#[RuleInfo(
	'dresscode/array-spacing',
	Stage::Formatting,
	description: 'Removes whitespace inside the brackets of an array',
)]
final class ArraySpacingRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [ArrayNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ArrayNode) {
			return;
		}

		foreach ([$node->openDelimiter, $node->closeDelimiter->getPrevious()] as $token) {
			$space = $token?->getTrailingSpace();
			if (
				$token !== null
				&& $space !== null
				&& $space !== ''
				&& $token !== $node->closeDelimiter
				&& $context->report($token, 'No whitespace inside the brackets of an array')
			) {
				$token->setTrailingSpace('');
			}
		}
	}
}
