<?php declare(strict_types=1);

namespace DressCode\Rules\Comments;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Token;
use PhpSyntax\TriviaKind;


/**
 * No comment with nothing in it; one alone on its line takes the line with it.
 * An empty doc comment is not a comment problem but a phpDoc one.
 */
#[RuleInfo(
	'dresscode/no-empty-comment',
	Stage::Cleanup,
	description: 'Removes empty comments',
	modifiesComments: true,
)]
final class NoEmptyCommentRule extends Rule
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
				$trivia->kind === TriviaKind::Comment
				&& !$trivia->inInterpolation
				&& self::isEmptyComment($trivia->text)
				&& $context->report($node, 'Empty comment', trivia: $trivia)
			) {
				$node->removeTrivia($trivia);
			}
		}
	}


	private static function isEmptyComment(string $text): bool
	{
		$content = str_starts_with($text, '/*')
			? substr($text, 2, -2)
			: preg_replace('~^(//|#)~', '', $text);
		return trim((string) $content) === '';
	}
}
