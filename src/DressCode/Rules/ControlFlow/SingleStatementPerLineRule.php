<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Statement\EmptyStatementNode;
use PhpSyntax\Nodes\StatementNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;


/**
 * A statement starts on its own line when another statement of the same list precedes it on that line.
 */
#[RuleInfo(
	'dresscode/single-statement-per-line',
	Stage::Formatting,
	description: 'Puts every statement on its own line',
)]
final class SingleStatementPerLineRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [StatementNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$list = $node->parent;
		$first = $node instanceof Node ? $node->getFirstToken() : null;
		if (
			!$list instanceof NodeList
			|| $first === null
			|| $first->startsLine()
			|| $first->is(TokenKind::CloseTag, TokenKind::InlineHtml)
			// what precedes is content, not a statement, and a line break would end up in the output
			|| $first->getPrevious()?->is(TokenKind::CloseTag, TokenKind::InlineHtml)
			|| $node instanceof EmptyStatementNode
		) {
			return;
		}

		$index = $list->indexOf($node);
		$previous = $list->getItems()[$index - 1] ?? null;
		if ($previous === null || !$context->report($first, 'Only one statement per line')) {
			return;
		}

		$first->ensureLeadingNewline($context->getStyle()->eol);
		$first->setIndentation($previous->getFirstToken()?->getIndentation() ?? '');
	}
}
