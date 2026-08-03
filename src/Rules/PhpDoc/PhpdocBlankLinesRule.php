<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Domains\Words;
use PhpSyntax\{Node, Token};
use function count;


/**
 * No blank lines after the `/**` of a doc comment or before its `*` + `/`, and at most one in a row
 * inside it.
 */
#[RuleInfo(Stage::Finishing, modifiesComments: true)]
final class PhpdocBlankLinesRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('phpdoc.blankLines', new Words(['trimmed' => 'none at the start or the end, at most one in a row inside']), 'The blank lines inside a doc comment')];
	}


	public function getVisitedNodes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token || !$node->hasComment()) {
			return;
		}

		foreach ($node->getDocComments() as $trivia) {
			[$trimmed, $atEdges] = self::trim($trivia->text);
			if (
				$trimmed !== $trivia->text
				&& $context->report(
					$node,
					$atEdges
						? 'Expected no blank line at the start or the end of the doc comment.'
						: 'Expected at most one blank line in a row in the doc comment.',
					trivia: $trivia,
				)
			) {
				$node->replaceTrivia($trivia, $trivia->withText($trimmed));
			}
		}
	}


	/** @return array{string, bool}  the text, and whether a blank line stood at its start or its end */
	private static function trim(string $text): array
	{
		$eol = str_contains($text, "\r\n") ? "\r\n" : "\n";
		$lines = explode("\n", str_replace("\r\n", "\n", $text));
		if (count($lines) < 3) {
			return [$text, false];
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

		return [implode($eol, [$first, ...$middle, $last]), $start > 0 || $end < count($lines)];
	}


	private static function isBlank(string $line): bool
	{
		return preg_match('~^[ \t]*\*?[ \t]*$~D', $line) === 1;
	}
}
