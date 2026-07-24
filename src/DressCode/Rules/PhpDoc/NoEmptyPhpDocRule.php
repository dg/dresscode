<?php declare(strict_types=1);

namespace DressCode\Rules\PhpDoc;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Token;
use PhpSyntax\TriviaKind;


/**
 * No doc comment with nothing in it but asterisks.
 */
#[RuleInfo(
	'dresscode/no-empty-phpdoc',
	Stage::Cleanup,
	description: 'Removes empty doc comments',
	modifiesComments: true,
)]
final class NoEmptyPhpDocRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token) {
			return;
		}

		foreach ([...$node->leadingTrivia, ...$node->trailingTrivia] as $trivia) {
			if (
				$trivia->kind === TriviaKind::DocComment
				&& !$trivia->inInterpolation
				&& trim(substr($trivia->text, 3, -2), " \t\r\n*") === ''
				&& $context->report($node, 'Empty doc comment', trivia: $trivia)
			) {
				$node->removeTrivia($trivia);
			}
		}
	}
}
