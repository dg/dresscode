<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ArrayDimFetchNode;
use PhpSyntax\Token;


/**
 * No whitespace around the brackets of an offset access: `$a[0]`, not `$a [ 0 ]`.
 */
#[RuleInfo(
	'dresscode/offset-bracket-spacing',
	Stage::Formatting,
	description: 'Removes whitespace around the brackets of an offset access',
)]
final class OffsetBracketSpacingRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [ArrayDimFetchNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ArrayDimFetchNode) {
			return;
		}

		$tokens = [$node->openBracket->getPrevious(), $node->openBracket];
		if ($node->dim !== null) {
			$tokens[] = $node->closeBracket->getPrevious();
		}

		foreach ($tokens as $token) {
			$space = $token?->getTrailingSpace();
			if (
			    $token !== null
			    && $space !== null
			    && $space !== ''
			    && $context->report($node->openBracket, 'No whitespace around the brackets of an offset')
			) {
				$token->setTrailingSpace('');
			}
		}
	}
}
