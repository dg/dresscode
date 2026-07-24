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
 * No blank lines after the `/**` of a doc comment or before its `*` + `/`, and at most one in a row
 * inside it.
 */
#[RuleInfo(
	'dresscode/phpdoc-trim',
	Stage::Cleanup,
	description: 'Removes extra blank lines in a doc comment',
	modifiesComments: true,
)]
final class PhpDocTrimRule extends Rule
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
				if ($item->kind !== TriviaKind::DocComment || $item->inInterpolation) {
					continue;
				}

				$trimmed = self::trim($item->text);
				if (
					$trimmed === $item->text
					|| !$context->report($node, 'Blank line at the edge of a doc comment', trivia: $item)
				) {
					continue;
				}

				$trivia[$i] = new Trivia($item->kind, $trimmed);
				$changed = true;
			}

			if ($changed) {
				$side === 'leadingTrivia' ? $node->setLeadingTrivia($trivia) : $node->setTrailingTrivia($trivia);
			}
		}
	}


	private static function trim(string $text): string
	{
		$eol = str_contains($text, "\r\n") ? "\r\n" : "\n";
		$lines = explode("\n", str_replace("\r\n", "\n", $text));
		if (count($lines) < 3) {
			return $text;
		}

		$first = array_shift($lines);
		$last = array_pop($lines);
		$start = 0;
		while ($start < count($lines) && self::isBlank($lines[$start])) {
			$start++;
		}

		$end = count($lines);
		while ($end > $start && self::isBlank($lines[$end - 1])) {
			$end--;
		}

		$middle = [];
		$blank = false;
		foreach (array_slice($lines, $start, $end - $start) as $line) {
			$isBlank = self::isBlank($line);
			if (!$isBlank || !$blank) {
				$middle[] = $line;
			}

			$blank = $isBlank;
		}

		return implode($eol, [$first, ...$middle, $last]);
	}


	private static function isBlank(string $line): bool
	{
		return preg_match('~^[ \t]*\*?[ \t]*$~D', $line) === 1;
	}
}
