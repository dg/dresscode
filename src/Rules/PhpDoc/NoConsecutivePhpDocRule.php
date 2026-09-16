<?php declare(strict_types=1);

namespace DressCode\Rules\PhpDoc;

use DressCode\Group;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Token;
use PhpSyntax\TriviaKind;


/**
 * No doc comment followed by another one with nothing but whitespace between them: PHP gives the code only
 * the last one, and the first one documents nothing. Which of the two is meant, or how they merge, is for
 * the author to say, so the rule only reports.
 */
#[RuleInfo(
	'dresscode/no-consecutive-phpdoc',
	Stage::Cleanup,
	description: 'Reports a doc comment followed by another one',
	group: Group::Correctness,
)]
final class NoConsecutivePhpDocRule extends NodeRule
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

		$own = [...$node->leadingTrivia, ...$node->trailingTrivia];
		$previous = null;
		foreach ([...$node->getPrevious()->trailingTrivia ?? [], ...$own] as $trivia) {
			if ($trivia->kind === TriviaKind::DocComment && !$trivia->inInterpolation) {
				if ($previous && in_array($trivia, $own, true)) {
					$context->report($node, 'Two doc comments in a row', trivia: $previous, fixable: false);
				}
				$previous = $trivia;
			} elseif ($trivia->kind !== TriviaKind::Whitespace && $trivia->kind !== TriviaKind::EndOfLine) {
				$previous = null;
			}
		}
	}
}
