<?php declare(strict_types=1);

namespace DressCode\Rules\Files;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Nodes\Statement\EmptyStatementNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;


/**
 * A file of PHP only does not end with `?>`; the tag and any whitespace after it go away, a statement it
 * terminated gets its semicolon.
 */
#[RuleInfo(
	'dresscode/no-closing-tag',
	Stage::Structure,
	description: 'Removes the closing tag at the end of the file',
)]
final class NoClosingTagRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [];
	}


	public function afterFile(RuleContext $context): void
	{
		$last = $context->getFile()->eof->getPrevious();
		$html = null;
		if ($last?->is(TokenKind::InlineHtml) && trim($last->text) === '') {
			$html = $last;
			$last = $last->getPrevious();
		}

		if (
			$last === null
			|| !$last->is(TokenKind::CloseTag)
			|| !$context->report($last, 'The closing tag at the end of the file is forbidden')
		) {
			return;
		}

		$html?->parent?->remove();
		$statement = $last->parent;
		if ($statement instanceof EmptyStatementNode) {
			$statement->remove();
		} else {
			$last->getPrevious()?->removeTrailingWhitespace();
			$semicolon = new Token(ord(';'), ';');
			$semicolon->setLeadingTrivia($last->leadingTrivia);
			$semicolon->setTrailingTrivia([new Trivia(TriviaKind::EndOfLine, $context->getStyle()->eol)]);
			$statement?->replaceChild($last, $semicolon);
		}
	}
}
