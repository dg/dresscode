<?php declare(strict_types=1);

namespace DressCode\Rules\Comments;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;


/**
 * The `//` form for a single-line comment, not the `#` one.
 */
#[RuleInfo(
	'dresscode/no-hash-comment',
	Stage::Cleanup,
	description: 'Writes single-line comments with //, not #',
	modifiesComments: true,
)]
final class NoHashCommentRule extends Rule
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

		foreach (['leadingTrivia', 'trailingTrivia'] as $side) {
			$trivia = $node->$side;
			$changed = false;
			foreach ($trivia as $i => $item) {
				if (
					$item->kind === TriviaKind::Comment
					&& !$item->inInterpolation
					&& str_starts_with($item->text, '#')
					&& $context->report($node, "A single-line comment must start with '//', not '#'", trivia: $item)
				) {
					$trivia[$i] = new Trivia($item->kind, '//' . substr($item->text, 1));
					$changed = true;
				}
			}

			if ($changed) {
				$side === 'leadingTrivia' ? $node->setLeadingTrivia($trivia) : $node->setTrailingTrivia($trivia);
			}
		}
	}
}
