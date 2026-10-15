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
use function is_string;


/**
 * A ternary split over lines takes three: the condition, then `?` with its branch, then `:` with its
 * branch; one split at either operator brings the other along, and so does the condition or the first branch spanning
 * lines, where the operators would hide at the ends of lines, except a condition of `?:` closed by a bracket opening
 * the line of the operator, `) ?: throw …`. A ternary on a line wider than the line length of the style is split so,
 * unless an outer construct on the line is spread first or `file.lineLengthExcept` names `ternary`, and the `?:` of
 * a short ternary stays together. Where the lines stand is the matter of `IndentationRule`.
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

			// an operand spanning lines before the last operator spans the ternary too, the operators then hiding at the
			// ends of its lines; the last operand may span lines after them, which stay in sight, and so does `?:` after
			// a condition closed by a bracket opening its line
			$last = $ternary->then === null ? $ternary->question : $ternary->colon;
			$closing = $ternary->condition->getLastToken();
			$first = $ternary->then === null && $closing->is([')', ']']) && $closing->startsLine()
				? $closing
				: $ternary->getFirstToken();
			$because = $gap->once(
				$ternary,
				fn() => $first->getCurrentLine() === $last->getCurrentLine()
					? NodeHelpers::findWidthReason($ternary, $gap)
					: $break,
			);
			return is_string($because) ? new Claim(line: Line::Next, because: $because) : $because;
		};
		return [TernaryNode::class => ['question' => [$operator, null], 'colon' => [$operator, null]]];
	}
}
