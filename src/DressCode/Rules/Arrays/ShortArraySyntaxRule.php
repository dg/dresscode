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
 * The short array syntax: `[1, 2]`, not `array(1, 2)`.
 */
#[RuleInfo(
	'dresscode/short-array-syntax',
	Stage::Structure,
	description: 'Writes arrays with the short syntax',
)]
final class ShortArraySyntaxRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [ArrayNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$keyword = $node instanceof ArrayNode ? $node->arrayKeyword : null;
		if (
			!$node instanceof ArrayNode
			|| $keyword === null
			|| !$context->report($keyword, 'An array must be written with the short syntax')
		) {
			return;
		}

		$open = new Token(ord('['), '[');
		$open->setLeadingTrivia($keyword->leadingTrivia);
		$open->setTrailingTrivia($node->openDelimiter->trailingTrivia);
		$close = new Token(ord(']'), ']');
		$close->setLeadingTrivia($node->closeDelimiter->leadingTrivia);
		$close->setTrailingTrivia($node->closeDelimiter->trailingTrivia);
		$node->setArrayKeyword(null);
		$node->setOpenDelimiter($open);
		$node->setCloseDelimiter($close);
	}
}
