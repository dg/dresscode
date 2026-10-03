<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token, Trivia};
use function count;


/**
 * The stars of a doc comment line up one space right of its opening `/**`, each followed by a space when
 * text follows. A doc comment sharing its first line with code is left alone.
 */
#[RuleInfo(
	'dresscode/phpdocAlignment',
	Stage::Formatting,
	description: 'Aligns the stars of a doc comment with its opening',
	modifiesComments: true,
)]
final class PhpdocAlignmentRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token || !$node->hasComment()) {
			return;
		}

		$trivias = $node->leadingTrivia;
		foreach ($trivias as $i => $trivia) {
			$before = $trivias[$i - 1] ?? null;
			if (
				!$trivia->is(Trivia::DocComment)
				|| $trivia->inInterpolation
				|| !str_contains($trivia->text, "\n")
				|| ($before !== null && !$before->isLineEnding() && !$before->is(Trivia::Whitespace))
				|| ($before?->is(Trivia::Whitespace) && isset($trivias[$i - 2]) && !$trivias[$i - 2]->isLineEnding())
			) {
				continue;
			}

			$indentation = $before?->is(Trivia::Whitespace) ? $before->text : '';
			$aligned = self::align($trivia->text, $indentation);
			if ($aligned !== $trivia->text && $context->report($node, 'Misaligned doc comment', trivia: $trivia)) {
				$node->replaceTrivia($trivia, new Trivia(Trivia::DocComment, $aligned));
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
