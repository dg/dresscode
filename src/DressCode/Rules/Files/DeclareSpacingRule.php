<?php declare(strict_types=1);

namespace DressCode\Rules\Files;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Statement\DeclareNode;
use PhpSyntax\Token;


/**
 * A declare statement without whitespace inside: `declare(strict_types=1)`.
 */
#[RuleInfo(
	'dresscode/declare-spacing',
	Stage::Formatting,
	description: 'Removes whitespace inside a declare statement',
)]
final class DeclareSpacingRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [DeclareNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof DeclareNode) {
			return;
		}

		$tokens = [$node->declareKeyword, $node->openParen];
		foreach ($node->items->getItems() as $item) {
			$tokens[] = $item->name->token;
			$tokens[] = $item->equals;
			$tokens[] = $item->value->getLastToken();
		}

		foreach ($tokens as $token) {
			$space = $token?->getTrailingSpace();
			if (
			    $token !== null
			    && $space !== null
			    && $space !== ''
			    && $context->report($node->declareKeyword, 'No whitespace inside a declare statement')
			) {
				$token->setTrailingSpace('');
			}
		}
	}
}
