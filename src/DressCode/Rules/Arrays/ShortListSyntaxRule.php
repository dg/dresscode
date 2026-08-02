<?php declare(strict_types=1);

namespace DressCode\Rules\Arrays;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ArrayNode;
use PhpSyntax\Nodes\Expression\ListNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;


/**
 * `[$a, $b] = ...` instead of `list($a, $b) = ...`; nested lists are converted from the inside out.
 */
#[RuleInfo(
	'dresscode/short-list-syntax',
	Stage::Structure,
	description: 'Uses the short syntax for destructuring instead of list()',
)]
final class ShortListSyntaxRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [ListNode::class];
	}


	public function leave(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof ListNode
			|| $node->listKeyword->hasComment()
			|| !$context->report($node->listKeyword, 'Destructuring must be written with the short syntax')
		) {
			return;
		}

		$array = (new Parser)->parseExpression('[0]');
		assert($array instanceof ArrayNode);
		$array->setItems(clone $node->items);
		$array->openDelimiter->setTrailingTrivia($node->openParen->trailingTrivia);
		$array->closeDelimiter->setLeadingTrivia($node->closeParen->leadingTrivia);
		$node->replaceWith($array);
	}
}
