<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Files;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\Nodes\Statement\{EmptyStatementNode, InlineHtmlNode};
use PhpSyntax\{Token, Trivia};


/**
 * A file of PHP only does not end with `?>`; the tag and any whitespace after it go away, a statement it terminated
 * gets its semicolon. A template, a file with markup outside its tags, keeps it. The rule visits no node: the tag is the
 * last token of the file, asked about once the pass is over, when the whole file is known to hold no markup.
 */
#[RuleInfo(Stage::Structure)]
final class NoClosingTagRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('file.closingTagAtEnd', Domain::state('forbidden'), 'A file of PHP only does not end with `?>`; a `?>` before HTML is content')];
	}


	public function getVisitedNodes(): array
	{
		return [];
	}


	public function afterPass(RuleContext $context): void
	{
		$last = $context->file->endOfFile->getPrevious();
		$html = null;
		if ($last?->is(Token::InlineHtml) && trim($last->text) === '') {
			$html = $last;
			$last = $last->getPrevious();
		}

		if (
			$last === null
			|| !$last->is(Token::CloseTag)
			|| $context->file->findFirst(InlineHtmlNode::class, fn(InlineHtmlNode $node) => $node->html !== $html && !$node->isPreamble()) !== null // a template
			|| !$context->report($last, 'The closing tag `?>` at the end of the file is forbidden.')
		) {
			return;
		}

		$html?->parent?->remove();
		$statement = $last->parent;
		if ($statement instanceof EmptyStatementNode) {
			$statement->remove();
		} else {
			$last->getPrevious()?->removeTrailingWhitespace();
			$semicolon = Token::fromText(';')
				->setLeadingTrivia($last->leadingTrivia)
				->setTrailingTrivia([Trivia::fromText($context->style->lineEnding)]);
			$statement?->replaceChild($last, $semicolon);
		}
	}
}
