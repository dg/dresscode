<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\Analyses\IndentationSnapshot;
use DressCode\{Claim, Decision, Gap, GapRule, Line, RuleInfo, Stage};
use DressCode\Domains\Words;
use DressCode\Rules\NodeHelpers;
use PhpSyntax\Nodes\Expression\TernaryNode;


/**
 * A ternary split over lines takes three: the condition, then `?` with its branch, then `:` with its
 * branch; one split at either operator brings the other along. A ternary on a line wider than the line length of
 * the style is split so, unless an outer construct on the line is spread first or `file.lineLengthExcept` names
 * `ternary`, and the `?:` of a short ternary stays together. Where the lines stand is the matter of `IndentationRule`.
 */
#[RuleInfo(Stage::Formatting, reads: ['file.lineLengthExcept'], analyses: [IndentationSnapshot::class])]
final class MultilineTernaryRule extends GapRule
{
	public static function getDecisions(): array
	{
		return [new Decision(
			'multiline.ternary',
			new Words(['perLine' => '`?` and `:` each opening a line of its own']),
			'A ternary spread over lines by its author, or for the width of its line',
		)];
	}


	public function getClaims(): array
	{
		$break = new Claim(line: Line::Next, because: 'the ternary spans several lines');
		$operator = function (Gap $gap) use ($break): ?Claim {
			$ternary = $gap->token->parent;
			if (!$ternary instanceof TernaryNode || ($gap->token->is(':') && $ternary->then === null)) {
				return null;
			}

			$because = $gap->once($ternary, fn() => self::findWidthReason($ternary, $gap));
			return match (true) {
				$because !== null => new Claim(line: Line::Next, because: $because),
				$ternary->question->startsLine() || $ternary->colon->startsLine() => $break,
				default => null,
			};
		};
		return [TernaryNode::class => ['question' => [$operator, null], 'colon' => [$operator, null]]];
	}


	/**
	 * Why a ternary on one line is spread: its line is wider than the line length of the style and no outer construct
	 * on the line is spread first; measured once the line is indented.
	 */
	private static function findWidthReason(TernaryNode $ternary, Gap $gap): ?string
	{
		$frame = NodeHelpers::findSplitFrame($ternary);
		if ($frame === null || $frame[3] || !$gap->style->lineSplits->has($frame[0])) {
			return null;
		}

		// the width first: asking whether the line is in place costs a plan of the whole file
		$width = NodeHelpers::measureOverflow($ternary, $frame[1], $gap);
		return $width !== null && NodeHelpers::isLineInPlace($gap, $frame[1])
			? "the line is $width characters long, more than {$gap->style->maxLineLength}"
			: null;
	}
}
