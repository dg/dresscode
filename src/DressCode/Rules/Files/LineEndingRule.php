<?php declare(strict_types=1);

namespace DressCode\Rules\Files;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;


/**
 * Every line ends the way the style says: the line endings of the file when the style follows the file,
 * otherwise the configured one. Line breaks inside strings, heredocs and inline HTML are content and stay.
 */
#[RuleInfo(
	'dresscode/line-ending',
	Stage::Cleanup,
	description: 'Unifies line endings',
)]
final class LineEndingRule extends Rule
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

		$eol = $context->getStyle()->eol;
		$leading = $this->unify($node, $node->leadingTrivia, $eol, $context);
		if ($leading !== null) {
			$node->setLeadingTrivia($leading);
		}

		$trailing = $this->unify($node, $node->trailingTrivia, $eol, $context);
		if ($trailing !== null) {
			$node->setTrailingTrivia($trailing);
		}
	}


	/**
	 * @param  list<Trivia>  $trivia
	 * @return ?list<Trivia>
	 */
	private function unify(Token $token, array $trivia, string $eol, RuleContext $context): ?array
	{
		$changed = false;
		$result = [];
		foreach ($trivia as $item) {
			$text = match ($item->kind) {
				TriviaKind::EndOfLine => $eol,
				TriviaKind::Comment, TriviaKind::DocComment, TriviaKind::OpenTag => (string) preg_replace('~\r\n|\r|\n~', $eol, $item->text),
				default => $item->text,
			};
			if (
			    $text !== $item->text
			    && !$item->inInterpolation
			    && $context->report($token, 'Wrong line ending', trivia: $item)
			) {
				$item = new Trivia($item->kind, $text, $item->inInterpolation);
				$changed = true;
			}

			$result[] = $item;
		}

		return $changed ? $result : null;
	}
}
