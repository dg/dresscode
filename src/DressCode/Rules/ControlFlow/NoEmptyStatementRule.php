<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Statement\EmptyStatementNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;


/**
 * No lone semicolon standing for an empty statement; the one a close tag forms stays.
 */
#[RuleInfo(
	'dresscode/no-empty-statement',
	Stage::Structure,
	description: 'Removes empty statements',
)]
final class NoEmptyStatementRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [EmptyStatementNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			$node instanceof EmptyStatementNode
			&& $node->parent instanceof NodeList
			&& !$node->semicolon->is(TokenKind::CloseTag)
			&& $context->report($node, 'Empty statement')
		) {
			$node->remove();
		}
	}
}
