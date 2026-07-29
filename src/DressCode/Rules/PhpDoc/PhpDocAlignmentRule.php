<?php declare(strict_types=1);

namespace DressCode\Rules\PhpDoc;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function count;


/**
 * The stars of a doc comment line up one space right of its opening `/**`, each followed by a space when
 * text follows. A doc comment sharing its first line with code is left alone.
 */
#[RuleInfo(
	'dresscode/phpdoc-alignment',
	Stage::Formatting,
	description: 'Aligns the stars of a doc comment with its opening',
	modifiesComments: true,
)]
final class PhpDocAlignmentRule extends Rule
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

		$trivias = $node->leadingTrivia;
		foreach ($trivias as $i => $trivia) {
			$before = $trivias[$i - 1] ?? null;
			if (
				$trivia->kind !== TriviaKind::DocComment
				|| $trivia->inInterpolation
				|| !str_contains($trivia->text, "\n")
				|| ($before !== null && !$before->isEndOfLine() && $before->kind !== TriviaKind::Whitespace)
				|| ($before?->kind === TriviaKind::Whitespace && isset($trivias[$i - 2]) && !$trivias[$i - 2]->isEndOfLine())
			) {
				continue;
			}

			$indentation = $before?->kind === TriviaKind::Whitespace ? $before->text : '';
			$aligned = self::align($trivia->text, $indentation);
			if ($aligned !== $trivia->text && $context->report($node, 'Misaligned doc comment', trivia: $trivia)) {
				$node->replaceTrivia($trivia, new Trivia(TriviaKind::DocComment, $aligned));
			}
		}
	}


	private static function align(string $text, string $indentation): string
	{
		$parts = preg_split('~(\r\n|\n|\r)~', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
		for ($i = 2; $i < count($parts); $i += 2) {
			$parts[$i] = (string) preg_replace('~^[ \t]*\*(?=[^ \t\r\n/])~', "$indentation * ", $parts[$i], 1, $count);
			if ($count === 0) {
				$parts[$i] = (string) preg_replace('~^[ \t]*\*~', "$indentation *", $parts[$i]);
			}
		}

		return implode('', $parts);
	}
}
