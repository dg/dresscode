<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Claim, Decision, Gap, GapRule, Line, RuleInfo, Stage};
use DressCode\Domains\Words;
use PhpSyntax\Nodes\Expression\TernaryNode;


/**
 * A ternary split over lines takes three: the condition, then `?` with its branch, then `:` with its
 * branch; one split at either operator brings the other along. A ternary kept on one line is left alone,
 * and the `?:` of a short ternary stays together. Where the lines stand is the matter of `IndentationRule`.
 */
#[RuleInfo(Stage::Formatting)]
final class MultilineTernaryRule extends GapRule
{
	public static function getDecisions(): array
	{
		return [new Decision('multiline.ternary', new Words(['perLine' => '`?` and `:` each opening a line of its own']), 'A ternary spread over lines')];
	}


	public function getClaims(): array
	{
		$break = new Claim(line: Line::Next, because: 'the ternary spans several lines');
		$operator = fn(Gap $gap) => ($ternary = $gap->token->parent) instanceof TernaryNode
			&& ($ternary->question->startsLine() || $ternary->colon->startsLine())
			&& !($gap->token->is(':') && $ternary->then === null)
				? $break
				: null;
		return [TernaryNode::class => ['question' => [$operator, null], 'colon' => [$operator, null]]];
	}
}
